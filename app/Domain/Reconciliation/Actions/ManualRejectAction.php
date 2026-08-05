<?php

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Audit\RecordAuditLog;
use App\Enums\WebhookProcessingStatus;
use App\Models\User;
use App\Models\WebhookLog;
use DomainException;
use Illuminate\Support\Facades\DB;

class ManualRejectAction
{
    public function __construct(
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    public function handle(
        WebhookLog $log,
        string $reason,
        User $actor,
        ?string $ip = null,
    ): WebhookLog {
        return DB::transaction(function () use ($log, $reason, $actor, $ip): WebhookLog {
            $locked = WebhookLog::query()->lockForUpdate()->findOrFail($log->id);

            if (! in_array($locked->processing_status, [
                WebhookProcessingStatus::Unmatched,
                WebhookProcessingStatus::Received,
                WebhookProcessingStatus::Failed,
            ], true)) {
                throw new DomainException('Webhook log is not eligible for rejection.');
            }

            $before = ['processing_status' => $locked->processing_status->value];
            $locked->processing_status = WebhookProcessingStatus::Failed;
            $locked->error_message = 'Rejected by ops: '.$reason;
            $locked->save();

            $this->recordAuditLog->handle(
                auditable: $locked,
                action: 'reconciliation.manual_reject',
                actor: $actor,
                before: $before,
                after: [
                    'processing_status' => $locked->processing_status->value,
                    'webhook_log_id' => $locked->id,
                ],
                reason: $reason,
                ip: $ip,
            );

            return $locked;
        });
    }
}
