<?php

namespace App\Domain\Reconciliation;

use App\Enums\PaymentIntentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\PaymentIntent;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\DB;

/**
 * Milestone 11 ingest handler: idempotent logging + candidate intent attachment.
 * Does not create payments or allocate installments (Milestone 12).
 */
class IngestOnlyReconciliationService implements ReconciliationService
{
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

            $existing = WebhookLog::query()
                ->where('idempotency_key', $evidence->idempotencyKey)
                ->where('id', '!=', $evidence->webhookLogId)
                ->whereIn('processing_status', [
                    WebhookProcessingStatus::Processed->value,
                    WebhookProcessingStatus::IgnoredDuplicate->value,
                ])
                ->exists();

            if ($existing) {
                $this->updateLog($log, WebhookProcessingStatus::IgnoredDuplicate, 'Duplicate idempotency key.');

                return ReconciliationResult::ignoredDuplicate();
            }

            $resultCode = data_get($evidence->raw, 'Body.stkCallback.ResultCode');
            if ($resultCode !== null && (int) $resultCode !== 0) {
                $this->updateLog($log, WebhookProcessingStatus::Processed, 'Non-success STK result recorded; no intent match required.');

                return ReconciliationResult::failed('STK result was not successful.');
            }

            $intent = $this->findCandidateIntent($evidence);

            if (! $intent) {
                $this->updateLog($log, WebhookProcessingStatus::Unmatched, 'No open Payment Intent matched evidence signals.');

                return ReconciliationResult::unmatched('No open Payment Intent matched evidence signals.');
            }

            $metadata = $intent->metadata ?? [];
            $metadata['pending_evidence'] = [
                'source' => $evidence->source->value,
                'idempotency_key' => $evidence->idempotencyKey,
                'amount' => $evidence->amount,
                'phone' => $evidence->phone,
                'receipt_number' => $evidence->receiptNumber,
                'checkout_request_id' => $evidence->checkoutRequestId,
                'merchant_request_id' => $evidence->merchantRequestId,
                'webhook_log_id' => $evidence->webhookLogId,
                'occurred_at' => $evidence->occurredAt->toIso8601String(),
            ];
            $intent->metadata = $metadata;

            if ($intent->status === PaymentIntentStatus::AwaitingCallback) {
                $intent->status = PaymentIntentStatus::Matched;
            }
            $intent->save();

            $this->updateLog($log, WebhookProcessingStatus::Processed, 'Matched Payment Intent '.$intent->uuid.'; allocation deferred to M12.');

            return ReconciliationResult::accepted($intent->uuid);
        });
    }

    private function findCandidateIntent(PaymentEvidence $evidence): ?PaymentIntent
    {
        $query = PaymentIntent::query()
            ->whereIn('status', [
                PaymentIntentStatus::AwaitingCallback->value,
                PaymentIntentStatus::Submitted->value,
                PaymentIntentStatus::Pending->value,
            ])
            ->where('expires_at', '>', now());

        // Metadata confirmation only — never the sole structural identity of money movement.
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
