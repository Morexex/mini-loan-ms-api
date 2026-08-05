<?php

namespace Tests\Feature\Webhooks;

use App\Domain\Disbursements\Actions\DisburseLoanAction;
use App\Domain\Loans\Actions\ApproveLoanAction;
use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DarajaStkWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_stk_success_callback_allocates_payment_intent(): void
    {
        $loan = $this->activeLoan();
        $intent = $this->openIntent($loan, '500.00', 'fake-checkout-id');

        $this->postJson('/webhooks/daraja/stk', $this->stkSuccessPayload(
            checkoutRequestId: 'fake-checkout-id',
            amount: 500,
            phone: '254700111222',
            receipt: 'QWERTY1234',
        ))->assertOk()
            ->assertJsonPath('ResultCode', 0);

        $intent->refresh();
        $this->assertSame(PaymentIntentStatus::Completed, $intent->status);

        $this->assertDatabaseHas('payments', [
            'payment_intent_id' => $intent->id,
            'status' => PaymentStatus::Posted->value,
            'receipt_number' => 'QWERTY1234',
            'amount' => '500.00',
        ]);

        $this->assertDatabaseHas('webhook_logs', [
            'provider' => 'daraja_stk',
            'processing_status' => WebhookProcessingStatus::Processed->value,
        ]);

        $this->assertTrue(
            $loan->installments()->where('status', InstallmentStatus::Paid)->exists()
            || $loan->installments()->where('status', InstallmentStatus::PartiallyPaid)->exists()
        );
    }

    public function test_duplicate_stk_callback_is_idempotent(): void
    {
        $loan = $this->activeLoan();
        $this->openIntent($loan, '500.00', 'fake-checkout-id');

        $payload = $this->stkSuccessPayload(
            checkoutRequestId: 'fake-checkout-id',
            amount: 500,
            phone: '254700111222',
            receipt: 'QWERTY1234',
        );

        $this->postJson('/webhooks/daraja/stk', $payload)->assertOk();
        $this->postJson('/webhooks/daraja/stk', $payload)->assertOk();

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, WebhookLog::query()->where('processing_status', WebhookProcessingStatus::Processed)->count());
        $this->assertSame(1, WebhookLog::query()->where('processing_status', WebhookProcessingStatus::IgnoredDuplicate)->count());
        $this->assertSame(1, PaymentIntent::query()->where('status', PaymentIntentStatus::Completed)->count());
    }

    public function test_stk_callback_without_matching_intent_is_unmatched(): void
    {
        $this->postJson('/webhooks/daraja/stk', $this->stkSuccessPayload(
            checkoutRequestId: 'unknown-checkout',
            amount: 999,
            phone: '254700111222',
            receipt: 'ABCDEF9999',
        ))->assertOk();

        $this->assertDatabaseHas('webhook_logs', [
            'provider' => 'daraja_stk',
            'processing_status' => WebhookProcessingStatus::Unmatched->value,
        ]);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_failed_stk_result_is_logged_without_allocation(): void
    {
        $loan = $this->activeLoan();
        $intent = $this->openIntent($loan, '500.00', 'fake-checkout-id');

        $this->postJson('/webhooks/daraja/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'fake-merchant-id',
                    'CheckoutRequestID' => 'fake-checkout-id',
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user',
                ],
            ],
        ])->assertOk();

        $intent->refresh();
        $this->assertSame(PaymentIntentStatus::AwaitingCallback, $intent->status);
        $this->assertSame(0, Payment::query()->count());
        $this->assertDatabaseHas('webhook_logs', [
            'processing_status' => WebhookProcessingStatus::Processed->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stkSuccessPayload(
        string $checkoutRequestId,
        float $amount,
        string $phone,
        string $receipt,
    ): array {
        return [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'fake-merchant-id',
                    'CheckoutRequestID' => $checkoutRequestId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => $amount],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                            ['Name' => 'TransactionDate', 'Value' => 20260805120000],
                            ['Name' => 'PhoneNumber', 'Value' => (int) $phone],
                        ],
                    ],
                ],
            ],
        ];
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
            'merchant_request_id' => 'fake-merchant-id',
            'checkout_request_id' => $checkoutRequestId,
            'metadata' => [],
        ]);
    }

    private function activeLoan(): Loan
    {
        User::factory()->create();
        $customer = Customer::factory()->create(['phone' => '254700111222']);
        WalletAccount::query()->create([
            'customer_id' => $customer->id,
            'balance' => 0,
            'currency' => 'KES',
        ]);
        $product = LoanProduct::factory()->create(['term_length' => 2]);
        $loan = Loan::factory()->create([
            'customer_id' => $customer->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 2000,
            'status' => LoanStatus::Pending,
        ]);

        $loan = app(ApproveLoanAction::class)->handle($loan);
        $loan = app(DisburseLoanAction::class)->handle($loan);
        $loan->load('customer');

        return $loan;
    }
}
