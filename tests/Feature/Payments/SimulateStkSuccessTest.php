<?php

namespace Tests\Feature\Payments;

use App\Domain\Disbursements\Actions\DisburseLoanAction;
use App\Domain\Loans\Actions\ApproveLoanAction;
use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentStatus;
use App\Infrastructure\Daraja\FakeDarajaGateway;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimulateStkSuccessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        FakeDarajaGateway::$shouldSucceed = true;
        parent::tearDown();
    }

    public function test_ops_can_simulate_stk_success_and_allocate(): void
    {
        $user = User::factory()->create();
        $loan = $this->activeLoan();

        $created = $this->actingAs($user)->postJson("/api/v1/loans/{$loan->id}/payment-intents", [
            'amount' => 500,
        ])->assertCreated();

        $uuid = $created->json('data.uuid');

        $this->actingAs($user)
            ->postJson("/api/v1/loans/{$loan->id}/payment-intents/{$uuid}/simulate-stk-success")
            ->assertOk()
            ->assertJsonPath('data.status', PaymentIntentStatus::Completed->value)
            ->assertJsonPath('meta.stk_simulation', true);

        $this->assertDatabaseHas('payments', [
            'loan_id' => $loan->id,
            'status' => PaymentStatus::Posted->value,
            'amount' => '500.00',
        ]);

        $this->assertTrue(
            $loan->installments()->whereIn('status', [
                InstallmentStatus::Paid->value,
                InstallmentStatus::PartiallyPaid->value,
            ])->exists()
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment_intent.stk_simulated',
        ]);
    }

    public function test_simulate_rejects_when_disabled(): void
    {
        config([
            'daraja.allow_stk_simulation' => false,
            'daraja.fake' => true,
            'daraja.base_url' => 'https://api.safaricom.co.ke',
        ]);

        $user = User::factory()->create();
        $loan = $this->activeLoan();

        $uuid = $this->actingAs($user)->postJson("/api/v1/loans/{$loan->id}/payment-intents", [
            'amount' => 500,
        ])->json('data.uuid');

        $this->actingAs($user)
            ->postJson("/api/v1/loans/{$loan->id}/payment-intents/{$uuid}/simulate-stk-success")
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($message) => str_contains((string) $message, 'disabled'));
    }

    private function activeLoan(): Loan
    {
        $customer = Customer::factory()->create(['phone' => '254708374149']);
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
