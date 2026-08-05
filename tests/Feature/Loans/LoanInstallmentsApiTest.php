<?php

namespace Tests\Feature\Loans;

use App\Domain\Loans\Actions\ApproveLoanAction;
use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanInstallmentsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ops_can_list_installments_for_approved_loan(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $product = LoanProduct::factory()->create([
            'term_length' => 4,
            'term_unit' => 'weeks',
            'interest_rate' => 5,
            'fee_amount' => 10,
        ]);
        $loan = Loan::factory()->create([
            'customer_id' => $customer->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 2000,
            'status' => LoanStatus::Pending,
        ]);

        app(ApproveLoanAction::class)->handle($loan);

        $this->actingAs($user)
            ->getJson("/api/v1/loans/{$loan->id}/installments")
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.sequence', 1)
            ->assertJsonPath('data.3.sequence', 4);
    }

    public function test_pending_loan_has_empty_installments_collection(): void
    {
        $user = User::factory()->create();
        $loan = Loan::factory()->create(['status' => LoanStatus::Pending]);

        $this->actingAs($user)
            ->getJson("/api/v1/loans/{$loan->id}/installments")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
