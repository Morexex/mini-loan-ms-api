<?php

namespace Tests\Feature\Webhooks;

use App\Domain\Disbursements\Actions\DisburseLoanAction;
use App\Domain\Loans\Actions\ApproveLoanAction;
use App\Enums\LoanStatus;
use App\Enums\PaymentIntentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsForwarderWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_forwarder_requires_secret(): void
    {
        config(['daraja.sms_forwarder_secret' => 'test-secret']);

        $this->postJson('/webhooks/sms-forwarder', [
            'text' => 'Confirmed. Ksh500.00 received.',
            'phone' => '254700111222',
            'amount' => 500,
        ])->assertUnauthorized();
    }

    public function test_sms_forwarder_allocates_open_intent_by_phone_and_amount(): void
    {
        config(['daraja.sms_forwarder_secret' => 'test-secret']);

        $loan = $this->activeLoan();
        $intent = PaymentIntent::query()->create([
            'customer_id' => $loan->customer_id,
            'loan_id' => $loan->id,
            'amount' => '500.00',
            'phone' => '254700111222',
            'status' => PaymentIntentStatus::AwaitingCallback,
            'attempt_number' => 1,
            'expires_at' => now()->addMinutes(15),
            'submitted_at' => now(),
            'metadata' => [],
        ]);

        $this->postJson('/webhooks/sms-forwarder', [
            'text' => 'QWERTY1234 Confirmed. Ksh500.00 received from 254700111222.',
            'phone' => '254700111222',
            'amount' => 500,
            'receipt' => 'QWERTY1234',
            'received_at' => now()->toIso8601String(),
        ], [
            'X-Sms-Forwarder-Secret' => 'test-secret',
        ])->assertAccepted();

        $intent->refresh();
        $this->assertSame(PaymentIntentStatus::Completed, $intent->status);
        $this->assertSame(1, Payment::query()->count());
        $this->assertDatabaseHas('webhook_logs', [
            'provider' => 'sms_forwarder',
            'processing_status' => WebhookProcessingStatus::Processed->value,
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

        return app(DisburseLoanAction::class)->handle($loan);
    }
}
