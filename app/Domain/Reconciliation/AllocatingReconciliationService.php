<?php

namespace App\Domain\Reconciliation;

use App\Domain\Audit\RecordAuditLog;
use App\Domain\Payments\AllocationService;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Full reconciliation: match evidence → post Payment → allocate installments → wallet overpay.
 */
class AllocatingReconciliationService implements ReconciliationService
{
    public function __construct(
        private readonly AllocationService $allocationService,
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    public function reconcile(PaymentEvidence $evidence): ReconciliationResult
    {
        return DB::transaction(function () use ($evidence): ReconciliationResult {
            $guard = $this->guardEvidence($evidence);
            if ($guard !== null) {
                return $guard;
            }

            $intent = $this->findCandidateIntent($evidence);

            if (! $intent) {
                if ($this->matchesTerminalIntent($evidence)) {
                    $this->updateLog(
                        $this->lockLog($evidence),
                        WebhookProcessingStatus::IgnoredDuplicate,
                        'Evidence references an already terminal Payment Intent.',
                    );

                    return ReconciliationResult::ignoredDuplicate();
                }

                $this->updateLog(
                    $this->lockLog($evidence),
                    WebhookProcessingStatus::Unmatched,
                    'No open Payment Intent matched evidence signals.',
                );

                return ReconciliationResult::unmatched('No open Payment Intent matched evidence signals.');
            }

            return $this->applyToIntent($evidence, $intent);
        });
    }

    public function reconcileToIntent(
        PaymentEvidence $evidence,
        PaymentIntent $intent,
        ?User $actor = null,
        ?string $reason = null,
    ): ReconciliationResult {
        return DB::transaction(function () use ($evidence, $intent, $actor, $reason): ReconciliationResult {
            $guard = $this->guardEvidence($evidence);
            if ($guard !== null) {
                return $guard;
            }

            $intent = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->id);

            if (! $this->isManuallyMatchable($intent)) {
                $this->updateLog(
                    $this->lockLog($evidence),
                    WebhookProcessingStatus::Failed,
                    'Payment Intent is not eligible for manual match.',
                );

                return ReconciliationResult::failed('Payment Intent is not eligible for manual match.');
            }

            $result = $this->applyToIntent($evidence, $intent, manual: true);

            if ($result->outcome === 'accepted' && $reason) {
                $this->recordAuditLog->handle(
                    auditable: $intent->fresh(),
                    action: 'payment_intent.manual_match',
                    actor: $actor,
                    after: [
                        'payment_intent_uuid' => $intent->uuid,
                        'webhook_log_id' => $evidence->webhookLogId,
                        'reason' => $reason,
                    ],
                    reason: $reason,
                );
            }

            return $result;
        });
    }

    private function guardEvidence(PaymentEvidence $evidence): ?ReconciliationResult
    {
        $log = $this->lockLog($evidence);

        if ($log && in_array($log->processing_status, [
            WebhookProcessingStatus::Processed,
            WebhookProcessingStatus::IgnoredDuplicate,
        ], true)) {
            return ReconciliationResult::ignoredDuplicate();
        }

        if (Payment::query()->where('idempotency_key', $evidence->idempotencyKey)->exists()) {
            $this->updateLog($log, WebhookProcessingStatus::IgnoredDuplicate, 'Payment already posted for idempotency key.');

            return ReconciliationResult::ignoredDuplicate();
        }

        $duplicateWebhook = WebhookLog::query()
            ->where('idempotency_key', $evidence->idempotencyKey)
            ->where('id', '!=', $evidence->webhookLogId)
            ->whereIn('processing_status', [
                WebhookProcessingStatus::Processed->value,
                WebhookProcessingStatus::IgnoredDuplicate->value,
            ])
            ->exists();

        if ($duplicateWebhook) {
            $this->updateLog($log, WebhookProcessingStatus::IgnoredDuplicate, 'Duplicate idempotency key.');

            return ReconciliationResult::ignoredDuplicate();
        }

        $resultCode = data_get($evidence->raw, 'Body.stkCallback.ResultCode');
        if ($resultCode !== null && (int) $resultCode !== 0) {
            $this->updateLog($log, WebhookProcessingStatus::Processed, 'Non-success STK result recorded; no allocation.');

            return ReconciliationResult::failed('STK result was not successful.');
        }

        if ($evidence->amount === null || bccomp($evidence->amount, '0', 2) !== 1) {
            $this->updateLog($log, WebhookProcessingStatus::Failed, 'Evidence amount missing or invalid.');

            return ReconciliationResult::failed('Evidence amount missing or invalid.');
        }

        return null;
    }

    private function applyToIntent(
        PaymentEvidence $evidence,
        PaymentIntent $intent,
        bool $manual = false,
    ): ReconciliationResult {
        $log = $this->lockLog($evidence);
        $intent = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->id);

        $terminal = [
            PaymentIntentStatus::Allocated,
            PaymentIntentStatus::Completed,
            PaymentIntentStatus::Cancelled,
            PaymentIntentStatus::Failed,
        ];

        if (! $manual) {
            $terminal[] = PaymentIntentStatus::Expired;
        }

        if (in_array($intent->status, $terminal, true)) {
            $this->updateLog($log, WebhookProcessingStatus::IgnoredDuplicate, 'Payment Intent already terminal.');

            return ReconciliationResult::ignoredDuplicate();
        }

        $intent->status = PaymentIntentStatus::Matched;
        $intent->save();

        try {
            $payment = Payment::query()->create([
                'payment_intent_id' => $intent->id,
                'customer_id' => $intent->customer_id,
                'loan_id' => $intent->loan_id,
                'amount' => $evidence->amount,
                'phone' => $evidence->phone ?? $intent->phone,
                'status' => PaymentStatus::Posted,
                'evidence_source' => $evidence->source,
                'evidenced_at' => $evidence->occurredAt,
                'idempotency_key' => $evidence->idempotencyKey,
                'receipt_number' => $evidence->receiptNumber,
                'webhook_log_id' => $evidence->webhookLogId,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->updateLog($log, WebhookProcessingStatus::IgnoredDuplicate, 'Payment idempotency conflict.');

            return ReconciliationResult::ignoredDuplicate();
        }

        $loan = $intent->loan()->lockForUpdate()->firstOrFail();
        $loan->load('customer');

        $result = $this->allocationService->allocate($payment, $loan);

        $intent->status = PaymentIntentStatus::Completed;
        $metadata = $intent->metadata ?? [];
        $metadata['reconciliation'] = [
            'payment_uuid' => $payment->uuid,
            'allocated' => $result['allocated'],
            'wallet_credit' => $result['wallet_credit'],
            'evidence_amount' => $evidence->amount,
            'intent_amount' => (string) $intent->amount,
            'amount_variance' => bccomp($evidence->amount, (string) $intent->amount, 2) !== 0,
            'manual' => $manual,
        ];
        unset($metadata['pending_evidence']);
        $intent->metadata = $metadata;
        $intent->save();

        $this->recordAuditLog->handle(
            auditable: $intent,
            action: 'payment_intent.reconciled',
            after: [
                'status' => $intent->status->value,
                'payment_uuid' => $payment->uuid,
                'allocated' => $result['allocated'],
                'wallet_credit' => $result['wallet_credit'],
                'manual' => $manual,
            ],
        );

        $this->updateLog(
            $log,
            WebhookProcessingStatus::Processed,
            'Allocated Payment Intent '.$intent->uuid.'; payment '.$payment->uuid.'.',
        );

        return ReconciliationResult::accepted($intent->uuid, 'Payment posted and allocated.');
    }

    private function isManuallyMatchable(PaymentIntent $intent): bool
    {
        return in_array($intent->status, [
            PaymentIntentStatus::Pending,
            PaymentIntentStatus::Submitted,
            PaymentIntentStatus::AwaitingCallback,
            PaymentIntentStatus::Matched,
            PaymentIntentStatus::Expired,
        ], true);
    }

    private function matchesTerminalIntent(PaymentEvidence $evidence): bool
    {
        if (! $evidence->checkoutRequestId) {
            return false;
        }

        return PaymentIntent::query()
            ->where('checkout_request_id', $evidence->checkoutRequestId)
            ->whereIn('status', [
                PaymentIntentStatus::Allocated->value,
                PaymentIntentStatus::Completed->value,
                PaymentIntentStatus::Cancelled->value,
                PaymentIntentStatus::Failed->value,
            ])
            ->exists();
    }

    private function findCandidateIntent(PaymentEvidence $evidence): ?PaymentIntent
    {
        $query = PaymentIntent::query()
            ->whereIn('status', [
                PaymentIntentStatus::AwaitingCallback->value,
                PaymentIntentStatus::Submitted->value,
                PaymentIntentStatus::Pending->value,
                PaymentIntentStatus::Matched->value,
            ])
            ->where('expires_at', '>', now());

        if ($evidence->checkoutRequestId) {
            $byCheckout = (clone $query)->where('checkout_request_id', $evidence->checkoutRequestId)->first();
            if ($byCheckout) {
                return $byCheckout;
            }
        }

        if ($evidence->phone && $evidence->amount) {
            return $query
                ->where('phone', $evidence->phone)
                ->where('amount', $evidence->amount)
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    private function lockLog(PaymentEvidence $evidence): ?WebhookLog
    {
        return $evidence->webhookLogId
            ? WebhookLog::query()->lockForUpdate()->find($evidence->webhookLogId)
            : null;
    }

    private function updateLog(?WebhookLog $log, WebhookProcessingStatus $status, string $message): void
    {
        if (! $log) {
            return;
        }

        $log->processing_status = $status;
        $log->error_message = $message;
        $log->save();
    }
}
