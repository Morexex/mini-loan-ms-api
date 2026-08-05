<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Audit\RecordAuditLog;
use App\Enums\PaymentIntentStatus;
use App\Infrastructure\Daraja\DarajaGateway;
use App\Models\PaymentIntent;
use Illuminate\Support\Facades\DB;
use Throwable;

class SubmitStkPushAction
{
    public function __construct(
        private readonly DarajaGateway $darajaGateway,
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    public function handle(PaymentIntent $intent): PaymentIntent
    {
        $intent->loadMissing('loan');

        $payload = [
            'amount' => (int) round((float) $intent->amount),
            'phone' => $intent->phone,
            'account_reference' => 'LOAN-'.$intent->loan_id,
            'transaction_desc' => 'Loan repayment '.$intent->uuid,
            'payment_intent_uuid' => $intent->uuid,
        ];

        $intent->status = PaymentIntentStatus::Submitted;
        $intent->submitted_at = now();
        $intent->metadata = array_merge($intent->metadata ?? [], ['stk_request' => $payload]);
        $intent->save();

        try {
            $result = $this->darajaGateway->stkPush($payload);
        } catch (Throwable $exception) {
            return $this->markFailed($intent, $exception->getMessage(), [
                'exception' => $exception->getMessage(),
            ]);
        }

        if (! ($result['successful'] ?? false)) {
            return $this->markFailed(
                $intent,
                (string) ($result['response_description'] ?? 'STK Push was not accepted.'),
                $result['response'] ?? $result,
            );
        }

        return DB::transaction(function () use ($intent, $result): PaymentIntent {
            $intent->status = PaymentIntentStatus::AwaitingCallback;
            $intent->merchant_request_id = $result['merchant_request_id'] ?? null;
            $intent->checkout_request_id = $result['checkout_request_id'] ?? null;
            $intent->metadata = array_merge($intent->metadata ?? [], [
                'stk_response' => $result['response'] ?? $result,
            ]);
            $intent->save();

            $this->recordAuditLog->handle(
                auditable: $intent,
                action: 'payment_intent.stk_submitted',
                actor: null,
                after: [
                    'status' => $intent->status->value,
                    'merchant_request_id' => $intent->merchant_request_id,
                    'checkout_request_id' => $intent->checkout_request_id,
                ],
            );

            return $intent->fresh(['loan', 'customer']);
        });
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function markFailed(PaymentIntent $intent, string $message, array $response): PaymentIntent
    {
        $intent->status = PaymentIntentStatus::Failed;
        $intent->metadata = array_merge($intent->metadata ?? [], [
            'error' => $message,
            'stk_response' => $response,
        ]);
        $intent->save();

        $this->recordAuditLog->handle(
            auditable: $intent,
            action: 'payment_intent.stk_failed',
            actor: null,
            after: [
                'status' => $intent->status->value,
                'error' => $message,
            ],
        );

        return $intent->fresh(['loan', 'customer']);
    }
}
