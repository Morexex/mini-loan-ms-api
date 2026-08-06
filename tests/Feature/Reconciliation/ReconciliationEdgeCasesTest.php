<?php

namespace Tests\Feature\Reconciliation;

use App\Domain\Disbursements\Actions\DisburseLoanAction;
use App\Domain\Loans\Actions\ApproveLoanAction;
use App\Domain\Reconciliation\PaymentEvidence;
use App\Domain\Reconciliation\ReconciliationService;
use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Enums\PaymentEvidenceSource;
use App\Enums\PaymentIntentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Milestone 15: failure-mode / edge-case coverage for the reconciliation engine.
 */
class ReconciliationEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_collision_matches_exact_amount_only(): void
    {
        $loan = $this->activeLoan(principal: 2000, termLength: 2);
        $firstDue = (string) $loan->installments()->orderBy('sequence')->value('amount_due');
        $otherAmount = bcadd($firstDue, '25.00', 2);

        $exactIntent = $this->openIntent($loan, $firstDue, 'checkout-a', phone: '254700111222');
        $this->openIntent($loan, $otherAmount, 'checkout-b', phone: '254700111222');

        $evidence = $this->evidenceByPhoneAmount(
            phone: '254700111222',
            amount: $firstDue,
            receipt: 'RCPTCOLLIDE',
            idempotencyKey: 'sms:collision:exact',
        );

        $result = app(ReconciliationService::class)->reconcile($evidence);

        $this->assertSame('accepted', $result->outcome);
        $this->assertSame($exactIntent->uuid, $result->paymentIntentUuid);
        $this->assertSame(PaymentIntentStatus::Completed, $exactIntent->fresh()->status);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_ambiguous_same_phone_and_amount_prefers_newest_open_intent(): void
    {
        $loan = $this->activeLoan(principal: 2000, termLength: 2);
        $amount = (string) $loan->installments()->orderBy('sequence')->value('amount_due');

        $older = $this->openIntent($loan, $amount, 'checkout-old', phone: '254700111222');
        $newer = $this->openIntent($loan, $amount, 'checkout-new', phone: '254700111222');

        $result = app(ReconciliationService::class)->reconcile(
            $this->evidenceByPhoneAmount(
                phone: '254700111222',
                amount: $amount,
                receipt: 'RCPTNEWEST',
                idempotencyKey: 'sms:newest',
            )
        );

        $this->assertSame('accepted', $result->outcome);
        $this->assertSame($newer->uuid, $result->paymentIntentUuid);
        $this->assertSame(PaymentIntentStatus::Completed, $newer->fresh()->status);
        $this->assertSame(PaymentIntentStatus::AwaitingCallback, $older->fresh()->status);
    }

    public function test_large_payment_settles_multiple_installments_and_completes_loan(): void
    {
        $loan = $this->activeLoan(principal: 1000, termLength: 2);
        $totalDue = $loan->installments->reduce(
            fn (string $carry, $row) => bcadd($carry, (string) $row->amount_due, 2),
            '0.00',
        );

        $intent = $this->openIntent($loan, $totalDue, 'checkout-full');

        app(ReconciliationService::class)->reconcile(
            $this->evidence($intent, $totalDue, 'RCPTFULLPAY')
        );

        $this->assertSame(2, PaymentAllocation::query()->count());
        $this->assertSame(0, $loan->installments()->where('status', '!=', InstallmentStatus::Paid->value)->count());
        $this->assertSame(LoanStatus::Completed, $loan->fresh()->status);
        $this->assertSame(PaymentIntentStatus::Completed, $intent->fresh()->status);
    }

    public function test_second_evidence_against_completed_intent_is_ignored(): void
    {
        $loan = $this->activeLoan(principal: 2000, termLength: 2);
        $amount = (string) $loan->installments()->orderBy('sequence')->value('amount_due');
        $intent = $this->openIntent($loan, $amount, 'checkout-once');

        app(ReconciliationService::class)->reconcile(
            $this->evidence($intent, $amount, 'RCPTONCE1')
        );

        $second = $this->evidence($intent, $amount, 'RCPTONCE2');
        // Force a new idempotency key but same checkout-linked intent (now completed).
        $secondLog = WebhookLog::query()->create([
            'provider' => 'daraja_stk',
            'idempotency_key' => 'daraja_stk:checkout-once:0:second',
            'headers' => [],
            'payload' => [],
            'processing_status' => WebhookProcessingStatus::Received,
        ]);

        $secondEvidence = new PaymentEvidence(
            source: PaymentEvidenceSource::DarajaStk,
            idempotencyKey: (string) $secondLog->idempotency_key,
            phone: $intent->phone,
            amount: $amount,
            occurredAt: now()->toImmutable(),
            receiptNumber: 'RCPTONCE2',
            checkoutRequestId: $intent->checkout_request_id,
            merchantRequestId: $intent->merchant_request_id,
            webhookLogId: $secondLog->id,
            raw: [
                'Body' => [
                    'stkCallback' => [
                        'ResultCode' => 0,
                        'CheckoutRequestID' => $intent->checkout_request_id,
                    ],
                ],
            ],
        );

        $result = app(ReconciliationService::class)->reconcile($secondEvidence);

        $this->assertSame('ignored_duplicate', $result->outcome);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, PaymentAllocation::query()->count());
    }

    public function test_sms_evidence_without_open_intent_stays_unmatched(): void
    {
        $result = app(ReconciliationService::class)->reconcile(
            $this->evidenceByPhoneAmount(
                phone: '254700999888',
                amount: '100.00',
                receipt: 'RCPTNONE',
                idempotencyKey: 'sms:orphan',
            )
        );

        $this->assertSame('unmatched', $result->outcome);
        $this->assertSame(0, Payment::query()->count());
        $this->assertDatabaseHas('webhook_logs', [
            'idempotency_key' => 'sms:orphan',
            'processing_status' => WebhookProcessingStatus::Unmatched->value,
        ]);
    }

    public function test_zero_amount_evidence_fails_without_posting_payment(): void
    {
        $loan = $this->activeLoan(principal: 2000, termLength: 2);
        $intent = $this->openIntent($loan, '500.00', 'checkout-zero');

        $log = WebhookLog::query()->create([
            'provider' => 'daraja_stk',
            'idempotency_key' => 'daraja_stk:checkout-zero:0',
            'headers' => [],
            'payload' => [],
            'processing_status' => WebhookProcessingStatus::Received,
        ]);

        $result = app(ReconciliationService::class)->reconcile(new PaymentEvidence(
            source: PaymentEvidenceSource::DarajaStk,
            idempotencyKey: (string) $log->idempotency_key,
            phone: $intent->phone,
            amount: '0.00',
            occurredAt: now()->toImmutable(),
            receiptNumber: 'RCPTZERO',
            checkoutRequestId: $intent->checkout_request_id,
            merchantRequestId: null,
            webhookLogId: $log->id,
            raw: ['Body' => ['stkCallback' => ['ResultCode' => 0]]],
        ));

        $this->assertSame('failed', $result->outcome);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(PaymentIntentStatus::AwaitingCallback, $intent->fresh()->status);
    }

    private function evidence(PaymentIntent $intent, string $amount, string $receipt): PaymentEvidence
    {
        $log = WebhookLog::query()->create([
            'provider' => 'daraja_stk',
            'idempotency_key' => 'daraja_stk:'.$intent->checkout_request_id.':0:'.$receipt,
            'headers' => [],
            'payload' => [],
            'processing_status' => WebhookProcessingStatus::Received,
        ]);

        return new PaymentEvidence(
            source: PaymentEvidenceSource::DarajaStk,
            idempotencyKey: (string) $log->idempotency_key,
            phone: $intent->phone,
            amount: $amount,
            occurredAt: now()->toImmutable(),
            receiptNumber: $receipt,
            checkoutRequestId: $intent->checkout_request_id,
            merchantRequestId: $intent->merchant_request_id,
            webhookLogId: $log->id,
            raw: [
                'Body' => [
                    'stkCallback' => [
                        'ResultCode' => 0,
                        'CheckoutRequestID' => $intent->checkout_request_id,
                    ],
                ],
            ],
        );
    }

    private function evidenceByPhoneAmount(
        string $phone,
        string $amount,
        string $receipt,
        string $idempotencyKey,
    ): PaymentEvidence {
        $log = WebhookLog::query()->create([
            'provider' => 'sms_forwarder',
            'idempotency_key' => $idempotencyKey,
            'headers' => [],
            'payload' => ['phone' => $phone, 'amount' => $amount, 'receipt' => $receipt],
            'processing_status' => WebhookProcessingStatus::Received,
        ]);

        return new PaymentEvidence(
            source: PaymentEvidenceSource::SmsForwarder,
            idempotencyKey: $idempotencyKey,
            phone: $phone,
            amount: $amount,
            occurredAt: now()->toImmutable(),
            receiptNumber: $receipt,
            checkoutRequestId: null,
            merchantRequestId: null,
            webhookLogId: $log->id,
            raw: ['phone' => $phone, 'amount' => $amount],
        );
    }

    private function openIntent(
        Loan $loan,
        string $amount,
        string $checkoutRequestId,
        string $phone = '254700111222',
    ): PaymentIntent {
        return PaymentIntent::query()->create([
            'customer_id' => $loan->customer_id,
            'loan_id' => $loan->id,
            'amount' => $amount,
            'phone' => $phone,
            'status' => PaymentIntentStatus::AwaitingCallback,
            'attempt_number' => 1,
            'expires_at' => now()->addMinutes(15),
            'submitted_at' => now(),
            'merchant_request_id' => 'fake-merchant-'.$checkoutRequestId,
            'checkout_request_id' => $checkoutRequestId,
            'metadata' => [],
        ]);
    }

    private function activeLoan(int $principal, int $termLength): Loan
    {
        User::factory()->create();
        $customer = Customer::factory()->create(['phone' => '254700111222']);
        WalletAccount::query()->create([
            'customer_id' => $customer->id,
            'balance' => 0,
            'currency' => 'KES',
        ]);
        $product = LoanProduct::factory()->create([
            'term_length' => $termLength,
            'interest_rate' => 10,
            'fee_amount' => 0,
        ]);
        $loan = Loan::factory()->create([
            'customer_id' => $customer->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'status' => LoanStatus::Pending,
        ]);

        $loan = app(ApproveLoanAction::class)->handle($loan);
        $loan = app(DisburseLoanAction::class)->handle($loan);
        $loan->load(['customer.walletAccount', 'installments']);

        return $loan;
    }
}
