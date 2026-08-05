<?php

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Audit\RecordAuditLog;
use App\Domain\Reconciliation\PaymentEvidence;
use App\Domain\Reconciliation\ReconciliationResult;
use App\Domain\Reconciliation\ReconciliationService;
use App\Domain\Reconciliation\Support\DarajaStkCallbackMapper;
use App\Domain\Reconciliation\Support\SmsForwarderPayloadMapper;
use App\Enums\WebhookProcessingStatus;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\WebhookLog;
use DomainException;
use InvalidArgumentException;

class ManualMatchAction
{
    public function __construct(
        private readonly ReconciliationService $reconciliation,
        private readonly DarajaStkCallbackMapper $stkMapper,
        private readonly SmsForwarderPayloadMapper $smsMapper,
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    public function handle(
        WebhookLog $log,
        PaymentIntent $intent,
        string $reason,
        User $actor,
        ?string $ip = null,
    ): ReconciliationResult {
        if (! in_array($log->processing_status, [
            WebhookProcessingStatus::Unmatched,
            WebhookProcessingStatus::Received,
            WebhookProcessingStatus::Failed,
        ], true)) {
            throw new DomainException('Webhook log is not eligible for manual match.');
        }

        $evidence = $this->mapEvidence($log);
        $result = $this->reconciliation->reconcileToIntent($evidence, $intent, $actor, $reason);

        if ($result->outcome !== 'accepted') {
            throw new DomainException($result->message ?? 'Manual match failed.');
        }

        $this->recordAuditLog->handle(
            auditable: $log->fresh(),
            action: 'reconciliation.manual_match',
            actor: $actor,
            after: [
                'webhook_log_id' => $log->id,
                'payment_intent_uuid' => $intent->uuid,
                'outcome' => $result->outcome,
            ],
            reason: $reason,
            ip: $ip,
        );

        return $result;
    }

    private function mapEvidence(WebhookLog $log): PaymentEvidence
    {
        $payload = $log->payload ?? [];

        return match ($log->provider) {
            'daraja_stk' => $this->stkMapper->map($payload, $log->id),
            'sms_forwarder' => $this->smsMapper->map($payload, $log->id),
            default => throw new InvalidArgumentException('Unsupported webhook provider for manual match: '.$log->provider),
        };
    }
}
