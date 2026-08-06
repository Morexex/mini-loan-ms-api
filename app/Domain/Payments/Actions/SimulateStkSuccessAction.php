<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Audit\RecordAuditLog;
use App\Domain\Reconciliation\ReconciliationService;
use App\Domain\Reconciliation\Support\DarajaStkCallbackMapper;
use App\Enums\PaymentIntentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\WebhookLog;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class SimulateStkSuccessAction
{
    public function __construct(
        private readonly DarajaStkCallbackMapper $stkMapper,
        private readonly ReconciliationService $reconciliation,
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    public function handle(PaymentIntent $intent, ?User $actor = null, ?string $ip = null): PaymentIntent
    {
        if (! $this->simulationAllowed()) {
            throw new DomainException(
                'STK payment simulation is disabled. Enable DARAJA_ALLOW_STK_SIMULATION for sandbox demos.',
            );
        }

        $intent->loadMissing('loan.customer');

        if ($intent->status !== PaymentIntentStatus::AwaitingCallback) {
            throw new DomainException(
                'Only payment intents awaiting callback can be simulated (current: '.$intent->status->value.').',
            );
        }

        $checkoutRequestId = $intent->checkout_request_id;
        if (! is_string($checkoutRequestId) || $checkoutRequestId === '') {
            throw new DomainException('Payment intent has no CheckoutRequestID to simulate against.');
        }

        $payload = $this->successPayload($intent, $checkoutRequestId);

        $log = WebhookLog::query()->create([
            'provider' => 'daraja_stk',
            'idempotency_key' => null,
            'headers' => [
                'x-mini-loan-simulation' => 'stk_success',
                'x-forwarded-for' => $ip ?? 'ops-simulate',
            ],
            'payload' => $payload,
            'processing_status' => WebhookProcessingStatus::Received,
        ]);

        try {
            $evidence = $this->stkMapper->map($payload, $log->id);
            $log->idempotency_key = $evidence->idempotencyKey;
            $log->save();
        } catch (UniqueConstraintViolationException) {
            $log->idempotency_key = 'daraja_stk:'.$checkoutRequestId.':0:sim:'.$log->id;
            $log->processing_status = WebhookProcessingStatus::IgnoredDuplicate;
            $log->error_message = 'Simulate STK skipped — callback already processed for this checkout id.';
            $log->save();

            throw new DomainException('This payment intent already has processed STK evidence.');
        }

        $result = $this->reconciliation->reconcile($evidence);

        if ($result->outcome !== 'accepted') {
            throw new DomainException(
                'Simulation ingested evidence but allocation did not complete: '.($result->message ?? $result->outcome),
            );
        }

        $this->recordAuditLog->handle(
            auditable: $intent->fresh(),
            action: 'payment_intent.stk_simulated',
            actor: $actor,
            after: [
                'webhook_log_id' => $log->id,
                'checkout_request_id' => $checkoutRequestId,
                'amount' => $intent->amount,
            ],
            ip: $ip,
        );

        return $intent->fresh(['loan', 'customer']);
    }

    public function simulationAllowed(): bool
    {
        $explicit = config('daraja.allow_stk_simulation');
        if ($explicit !== null && $explicit !== '') {
            return filter_var($explicit, FILTER_VALIDATE_BOOLEAN);
        }

        if ((bool) config('daraja.fake')) {
            return true;
        }

        return str_contains((string) config('daraja.base_url'), 'sandbox.safaricom.co.ke');
    }

    /**
     * @return array<string, mixed>
     */
    private function successPayload(PaymentIntent $intent, string $checkoutRequestId): array
    {
        $amount = (float) $intent->amount;
        $phone = preg_replace('/\D+/', '', (string) $intent->phone) ?: '254708374149';
        $receipt = 'SIM'.Str::upper(Str::random(8));

        return [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => $intent->merchant_request_id ?: 'sim-merchant-'.$intent->id,
                    'CheckoutRequestID' => $checkoutRequestId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => $amount],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                            ['Name' => 'TransactionDate', 'Value' => now()->format('YmdHis')],
                            ['Name' => 'PhoneNumber', 'Value' => (int) $phone],
                        ],
                    ],
                ],
            ],
        ];
    }
}
