<?php

namespace App\Domain\Payments\Actions;

use App\Domain\Audit\RecordAuditLog;
use App\Enums\LoanStatus;
use App\Enums\PaymentIntentStatus;
use App\Jobs\InitiateStkPushJob;
use App\Models\Loan;
use App\Models\PaymentIntent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreatePaymentIntentAction
{
    public function __construct(
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    /**
     * @param  array{amount: numeric}  $data
     */
    public function handle(Loan $loan, array $data, ?User $actor = null, ?string $ip = null): PaymentIntent
    {
        $loan->loadMissing('customer');

        if ($loan->status !== LoanStatus::Active) {
            throw new DomainException('STK repayment requires an active loan.');
        }

        $ttlMinutes = (int) config('daraja.payment_intent_ttl_minutes', 15);
        $attempt = PaymentIntent::query()->where('loan_id', $loan->id)->count() + 1;

        $intent = DB::transaction(function () use ($loan, $data, $attempt, $ttlMinutes, $actor, $ip): PaymentIntent {
            $intent = PaymentIntent::query()->create([
                'customer_id' => $loan->customer_id,
                'loan_id' => $loan->id,
                'amount' => $data['amount'],
                'phone' => $loan->customer->phone,
                'status' => PaymentIntentStatus::Pending,
                'attempt_number' => $attempt,
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);

            $this->recordAuditLog->handle(
                auditable: $intent,
                action: 'payment_intent.created',
                actor: $actor,
                after: [
                    'uuid' => $intent->uuid,
                    'loan_id' => $intent->loan_id,
                    'amount' => (string) $intent->amount,
                    'phone' => $intent->phone,
                    'status' => $intent->status->value,
                ],
                ip: $ip,
            );

            return $intent;
        });

        InitiateStkPushJob::dispatchSync($intent->id);

        return $intent->fresh(['loan', 'customer']);
    }
}
