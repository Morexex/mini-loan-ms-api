<?php

namespace Tests\Feature\Payments;

use App\Domain\Disbursements\Actions\DisburseLoanAction;
use App\Domain\Loans\Actions\ApproveLoanAction;
use App\Enums\LoanStatus;
use App\Enums\PaymentIntentStatus;
use App\Infrastructure\Daraja\FakeDarajaGateway;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentIntentStkTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        FakeDarajaGateway::$shouldSucceed = true;
        parent::tearDown();
    }

    public function test_ops_can_create_payment_intent_and_submit_stk(): void
    {
        $user = User::factory()->create();
        $loan = $this->activeLoan();

        $response = $this->actingAs($user)->postJson("/api/v1/loans/{$loan->id}/payment-intents", [
            'amount' => 500,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_callback')
            ->assertJsonPath('data.amount', '500.00');

        $checkoutId = $response->json('data.checkout_request_id');
        $this->assertIsString($checkoutId);
        $this->assertStringStartsWith('fake-checkout-', $checkoutId);
        $this->assertStringStartsWith('fake-merchant-', (string) $response->json('data.merchant_request_id'));

        $this->assertDatabaseHas('payment_intents', [
            'loan_id' => $loan->id,
            'status' => PaymentIntentStatus::AwaitingCallback->value,
            'checkout_request_id' => $checkoutId,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_intent.created',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_intent.stk_submitted',
        ]);
    }

    public function test_stk_failure_marks_intent_failed(): void
    {
        $user = User::factory()->create();
        $loan = $this->activeLoan();

        FakeDarajaGateway::$shouldSucceed = false;

        $this->actingAs($user)->postJson("/api/v1/loans/{$loan->id}/payment-intents", [
            'amount' => 500,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'failed');
    }

    public function test_cannot_collect_on_non_active_loan(): void
    {
        $user = User::factory()->create();
        $loan = Loan::factory()->create(['status' => LoanStatus::Approved]);

        $this->actingAs($user)->postJson("/api/v1/loans/{$loan->id}/payment-intents", [
            'amount' => 500,
        ])->assertUnprocessable();
    }

    public function test_ops_can_list_and_show_payment_intents(): void
    {
        $user = User::factory()->create();
        $loan = $this->activeLoan();

        $created = $this->actingAs($user)->postJson("/api/v1/loans/{$loan->id}/payment-intents", [
            'amount' => 250,
        ])->assertCreated();

        $uuid = $created->json('data.uuid');

        $this->actingAs($user)
            ->getJson("/api/v1/loans/{$loan->id}/payment-intents")
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $uuid);

        $this->actingAs($user)
            ->getJson("/api/v1/payment-intents/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $uuid);
    }

    private function activeLoan(): Loan
    {
        $customer = Customer::factory()->create(['phone' => '254700111222']);
        $product = LoanProduct::factory()->create(['term_length' => 2]);
        $loan = Loan::factory()->create([
            'customer_id' => $customer->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 2000,
            'status' => LoanStatus::Pending,
        ]);

        $loan = app(ApproveLoanAction::class)->handle($loan);
        $loan = app(DisburseLoanAction::class)->handle($loan);

        $this->assertSame(LoanStatus::Active, $loan->status);

        return $loan;
    }
}
