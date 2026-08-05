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
use App\Enums\WalletTransactionReason;
use App\Jobs\ExpireStalePaymentIntentsJob;
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

class ReconciliationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_match_allocates_oldest_due_first(): void
    {
        $loan = $this->activeLoan(principal: 2000, termLength: 2);
        $firstDue = (string) $loan->installments()->orderBy('sequence')->first()->amount_due;

        $intent = $this->openIntent($loan, $firstDue, 'checkout-exact');

        $result = app(ReconciliationService::class)->reconcile(
            $this->evidence($intent, $firstDue, 'RCPTEXACT1')
        );

        $this->assertSame('accepted', $result->outcome);

        $first = $loan->installments()->orderBy('sequence')->first();
        $second = $loan->installments()->orderBy('sequence')->skip(1)->first();

        $this->assertSame(InstallmentStatus::Paid, $first->fresh()->status);
        $this->assertSame(InstallmentStatus::Scheduled, $second->fresh()->status);
        $this->assertSame(1, PaymentAllocation::query()->count());
        $this->assertSame(PaymentIntentStatus::Completed, $intent->fresh()->status);
    }

    public function test_partial_payment_marks_installment_partially_paid(): void
    {
        $loan = $this->activeLoan(principal: 2000, termLength: 2);
        $firstDue = (string) $loan->installments()->orderBy('sequence')->first()->amount_due;
        $partial = bcdiv($firstDue, '2', 2);

        $intent = $this->openIntent($loan, $partial, 'checkout-partial');

        app(ReconciliationService::class)->reconcile(
            $this->evidence($intent, $partial, 'RCPTPARTIAL')
        );

        $first = $loan->installments()->orderBy('sequence')->first()->fresh();
        $this->assertSame(InstallmentStatus::PartiallyPaid, $first->status);
        $this->assertSame($partial, (string) $first->amount_paid);
    }

    public function test_overpayment_credits_customer_wallet(): void
    {
        $loan = $this->activeLoan(principal: 1000, termLength: 1);
        $due = (string) $loan->installments()->first()->amount_due;
        $overpay = bcadd($due, '50.00', 2);

        $intent = $this->openIntent($loan, $overpay, 'checkout-over');

        app(ReconciliationService::class)->reconcile(
            $this->evidence($intent, $overpay, 'RCPTOVERPAY')
        );

        $wallet = $loan->customer->walletAccount()->first();
        $this->assertSame('50.00', (string) $wallet->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_account_id' => $wallet->id,
            'reason' => WalletTransactionReason::Overpayment->value,
            'amount' => '50.00',
        ]);
        $this->assertSame(LoanStatus::Completed, $loan->fresh()->status);
        $this->assertSame(InstallmentStatus::Paid, $loan->installments()->first()->fresh()->status);
    }

    public function test_duplicate_evidence_does_not_double_allocate(): void
    {
        $loan = $this->activeLoan(principal: 2000, termLength: 2);
        $amount = (string) $loan->installments()->orderBy('sequence')->first()->amount_due;
        $intent = $this->openIntent($loan, $amount, 'checkout-dupe');

        $evidence = $this->evidence($intent, $amount, 'RCPTDUPE001');
        $first = app(ReconciliationService::class)->reconcile($evidence);
        $second = app(ReconciliationService::class)->reconcile($evidence);

        $this->assertSame('accepted', $first->outcome);
        $this->assertSame('ignored_duplicate', $second->outcome);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, PaymentAllocation::query()->count());
    }

    public function test_expired_intent_is_not_allocated(): void
    {
        $loan = $this->activeLoan(principal: 2000, termLength: 2);
        $amount = '500.00';
        $intent = $this->openIntent($loan, $amount, 'checkout-expired');
        $intent->expires_at = now()->subMinute();
        $intent->save();

        $result = app(ReconciliationService::class)->reconcile(
            $this->evidence($intent, $amount, 'RCPTEXPIRED')
        );

        $this->assertSame('unmatched', $result->outcome);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_scheduler_expires_stale_intents(): void
    {
        $loan = $this->activeLoan(principal: 2000, termLength: 2);
        $intent = $this->openIntent($loan, '500.00', 'checkout-ttl');
        $intent->expires_at = now()->subMinutes(1);
        $intent->save();

        (new ExpireStalePaymentIntentsJob)->handle(app(\App\Domain\Audit\RecordAuditLog::class));

        $this->assertSame(PaymentIntentStatus::Expired, $intent->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_intent.expired',
        ]);
    }

    public function test_checkout_linked_amount_variance_posts_evidence_amount(): void
    {
        $loan = $this->activeLoan(principal: 2000, termLength: 2);
        $intentAmount = (string) $loan->installments()->orderBy('sequence')->first()->amount_due;
        $evidenceAmount = bcadd($intentAmount, '10.00', 2);

        $intent = $this->openIntent($loan, $intentAmount, 'checkout-variance');

        app(ReconciliationService::class)->reconcile(
            $this->evidence($intent, $evidenceAmount, 'RCPTVAR10')
        );

        $this->assertDatabaseHas('payments', [
            'payment_intent_id' => $intent->id,
            'amount' => $evidenceAmount,
        ]);
        $this->assertTrue((bool) ($intent->fresh()->metadata['reconciliation']['amount_variance'] ?? false));
    }

    private function evidence(PaymentIntent $intent, string $amount, string $receipt): PaymentEvidence
    {
        $log = WebhookLog::query()->create([
            'provider' => 'daraja_stk',
            'idempotency_key' => 'daraja_stk:'.$intent->checkout_request_id.':0:'.$receipt,
            'headers' => [],
            'payload' => [],
            'processing_status' => \App\Enums\WebhookProcessingStatus::Received,
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

    private function openIntent(Loan $loan, string $amount, string $checkoutRequestId): PaymentIntent
    {
        return PaymentIntent::query()->create([
            'customer_id' => $loan->customer_id,
            'loan_id' => $loan->id,
            'amount' => $amount,
            'phone' => $loan->customer->phone,
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
