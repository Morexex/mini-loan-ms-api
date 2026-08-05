<?php

namespace App\Jobs;

use App\Domain\Reconciliation\PaymentEvidence;
use App\Domain\Reconciliation\ReconciliationService;
use App\Enums\WebhookProcessingStatus;
use App\Models\WebhookLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReconcilePaymentEvidenceJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PaymentEvidence $evidence,
    ) {}

    public function handle(ReconciliationService $reconciliation): void
    {
        $reconciliation->reconcile($this->evidence);
    }

    public function failed(?Throwable $exception): void
    {
        if (! $this->evidence->webhookLogId) {
            return;
        }

        WebhookLog::query()
            ->whereKey($this->evidence->webhookLogId)
            ->update([
                'processing_status' => WebhookProcessingStatus::Failed->value,
                'error_message' => $exception?->getMessage() ?? 'Reconciliation job failed.',
            ]);
    }
}
