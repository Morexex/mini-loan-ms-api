<?php

namespace Tests\Feature\Reconciliation;

use App\Domain\Disbursements\Actions\DisburseLoanAction;
use App\Domain\Loans\Actions\ApproveLoanAction;
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

class ManualReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ops_can_list_unmatched_queue(): void
    {
        $user = User::factory()->create();
        WebhookLog::query()->create([
            'provider' => 'daraja_stk',
            'idempotency_key' => 'daraja_stk:orphan:0',
            'headers' => [],
            'payload' => ['Body' => ['stkCallback' => ['ResultCode' => 0]]],
            'processing_status' => WebhookProcessingStatus::Unmatched,
            'error_message' => 'No open Payment Intent matched evidence signals.',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/reconciliation/unmatched')
            ->assertOk()
            ->assertJsonPath('data.unmatched_webhooks.0.processing_status', 'unmatched');
    }

    public function test_ops_can_manually_match_unmatched_webhook_to_expired_intent(): void
    {
        $user = User::factory()->create();
        $loan = $this->activeLoan();
        $amount = (string) $loan->installments()->orderBy('sequence')->value('amount_due');

        $intent = PaymentIntent::query()->create([
            'customer_id' => $loan->customer_id,
            'loan_id' => $loan->id,
            'amount' => $amount,
            'phone' => '254700111222',
            'status' => PaymentIntentStatus::Expired,
            'attempt_number' => 1,
            'expires_at' => now()->subMinutes(5),
            'submitted_at' => now()->subMinutes(20),
            'merchant_request_id' => 'manual-merchant',
            'checkout_request_id' => 'manual-checkout',
            'metadata' => [],
        ]);

        $log = WebhookLog::query()->create([
            'provider' => 'daraja_stk',
            'idempotency_key' => 'daraja_stk:manual-checkout:0',
            'headers' => [],
            'payload' => [
                'Body' => [
                    'stkCallback' => [
                        'MerchantRequestID' => 'manual-merchant',
                        'CheckoutRequestID' => 'other-checkout',
                        'ResultCode' => 0,
                        'ResultDesc' => 'ok',
                        'CallbackMetadata' => [
                            'Item' => [
                                ['Name' => 'Amount', 'Value' => (float) $amount],
                                ['Name' => 'MpesaReceiptNumber', 'Value' => 'MANUALRCP1'],
                                ['Name' => 'TransactionDate', 'Value' => 20260805120000],
                                ['Name' => 'PhoneNumber', 'Value' => 254700111222],
                            ],
                        ],
                    ],
                ],
            ],
            'processing_status' => WebhookProcessingStatus::Unmatched,
            'error_message' => 'No open Payment Intent matched evidence signals.',
        ]);

        $this->actingAs($user)->postJson('/api/v1/reconciliation/matches', [
            'webhook_log_id' => $log->id,
            'payment_intent_uuid' => $intent->uuid,
            'reason' => 'Customer confirmed late STK payment via call.',
        ])->assertOk()
            ->assertJsonPath('data.outcome', 'accepted');

        $this->assertSame(PaymentIntentStatus::Completed, $intent->fresh()->status);
        $this->assertSame(PaymentStatus::Posted, Payment::query()->first()->status);
        $this->assertSame(WebhookProcessingStatus::Processed, $log->fresh()->processing_status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reconciliation.manual_match',
        ]);
    }

    public function test_manual_match_requires_reason(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/reconciliation/matches', [
            'webhook_log_id' => 1,
            'payment_intent_uuid' => '00000000-0000-0000-0000-000000000001',
        ])->assertUnprocessable();
    }

    public function test_ops_can_reject_unmatched_webhook(): void
    {
        $user = User::factory()->create();
        $log = WebhookLog::query()->create([
            'provider' => 'sms_forwarder',
            'idempotency_key' => 'sms:junk',
            'headers' => [],
            'payload' => ['text' => 'spam'],
            'processing_status' => WebhookProcessingStatus::Unmatched,
        ]);

        $this->actingAs($user)->postJson('/api/v1/reconciliation/rejects', [
            'webhook_log_id' => $log->id,
            'reason' => 'Unrelated SMS noise; not a loan repayment.',
        ])->assertOk();

        $this->assertSame(WebhookProcessingStatus::Failed, $log->fresh()->processing_status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reconciliation.manual_reject',
        ]);
    }

    private function activeLoan(): Loan
    {
        $customer = Customer::factory()->create(['phone' => '254700111222']);
        WalletAccount::query()->create([
            'customer_id' => $customer->id,
            'balance' => 0,
            'currency' => 'KES',
        ]);
        $product = LoanProduct::factory()->create([
            'term_length' => 2,
            'interest_rate' => 10,
            'fee_amount' => 0,
        ]);
        $loan = Loan::factory()->create([
            'customer_id' => $customer->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 2000,
            'status' => LoanStatus::Pending,
        ]);

        $loan = app(ApproveLoanAction::class)->handle($loan);

        return app(DisburseLoanAction::class)->handle($loan);
    }
}
