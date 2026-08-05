<?php

namespace App\Domain\Reconciliation;

use App\Domain\Audit\RecordAuditLog;
use App\Domain\Payments\AllocationService;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\Payment;
use App\Models\PaymentIntent;
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
            $log = $evidence->webhookLogId
                ? WebhookLog::query()->lockForUpdate()->find($evidence->webhookLogId)
                : null;

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

            $intent = $this->findCandidateIntent($evidence);

            if (! $intent) {
                $this->updateLog($log, WebhookProcessingStatus::Unmatched, 'No open Payment Intent matched evidence signals.');

                return ReconciliationResult::unmatched('No open Payment Intent matched evidence signals.');
            }

            $intent = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->id);

            if (in_array($intent->status, [
                PaymentIntentStatus::Allocated,
                PaymentIntentStatus::Completed,
                PaymentIntentStatus::Expired,
                PaymentIntentStatus::Cancelled,
                PaymentIntentStatus::Failed,
            ], true)) {
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
                ],
            );

            $this->updateLog(
                $log,
                WebhookProcessingStatus::Processed,
                'Allocated Payment Intent '.$intent->uuid.'; payment '.$payment->uuid.'.',
            );

            return ReconciliationResult::accepted($intent->uuid, 'Payment posted and allocated.');
        });
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

        // Checkout ID is metadata confirmation only — never the ledger identity.
        if ($evidence->checkoutRequestId) {
            $byCheckout = (clone $query)->where('checkout_request_id', $evidence->checkoutRequestId)->first();
            if ($byCheckout) {
                return $byCheckout;
            }
        }

        // Without checkout hint: exact phone + amount (Q4 exact match).
        if ($evidence->phone && $evidence->amount) {
            return $query
                ->where('phone', $evidence->phone)
                ->where('amount', $evidence->amount)
                ->orderByDesc('id')
                ->first();
        }

        return null;
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
