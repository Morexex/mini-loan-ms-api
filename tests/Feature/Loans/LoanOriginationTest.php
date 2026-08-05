<?php

namespace Tests\Feature\Loans;

use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanOriginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ops_can_create_pending_loan(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        WalletAccount::query()->create([
            'customer_id' => $customer->id,
            'balance' => 0,
            'currency' => 'KES',
        ]);
        $product = LoanProduct::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->postJson('/api/v1/loans', [
            'customer_id' => $customer->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 5000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.principal_amount', '5000.00');

        $this->assertDatabaseHas('loans', [
            'customer_id' => $customer->id,
            'status' => LoanStatus::Pending->value,
        ]);
    }

    public function test_inactive_product_cannot_be_used(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $product = LoanProduct::factory()->create(['is_active' => false]);

        $this->actingAs($user)->postJson('/api/v1/loans', [
            'customer_id' => $customer->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 5000,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['loan_product_id']);
    }

    public function test_ops_can_approve_loan_and_generate_schedule(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $product = LoanProduct::factory()->create([
            'interest_rate' => 12,
            'term_unit' => 'months',
            'term_length' => 3,
            'fee_amount' => 0,
            'is_active' => true,
        ]);
        $loan = Loan::factory()->create([
            'customer_id' => $customer->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 3000,
            'status' => LoanStatus::Pending,
        ]);

        $response = $this->actingAs($user)->postJson("/api/v1/loans/{$loan->id}/approve");

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonCount(3, 'data.installments');

        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'status' => LoanStatus::Approved->value,
        ]);
        $this->assertDatabaseCount('installments', 3);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'loan.approved',
            'auditable_id' => $loan->id,
        ]);
    }

    public function test_cannot_approve_non_pending_loan(): void
    {
        $user = User::factory()->create();
        $loan = Loan::factory()->approved()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/loans/{$loan->id}/approve")
            ->assertUnprocessable();
    }

    public function test_ops_can_list_and_show_loans(): void
    {
        $user = User::factory()->create();
        $loan = Loan::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/loans')
            ->assertOk()
            ->assertJsonPath('data.0.id', $loan->id);

        $this->actingAs($user)
            ->getJson("/api/v1/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $loan->id);
    }
}
