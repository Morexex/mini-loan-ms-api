<?php

namespace Tests\Feature\Loans;

use App\Domain\Loans\Actions\ApproveLoanAction;
use App\Enums\DisbursementStatus;
use App\Enums\LoanStatus;
use App\Infrastructure\Daraja\FakeDarajaGateway;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanDisbursementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        FakeDarajaGateway::$shouldSucceed = true;
        parent::tearDown();
    }

    public function test_ops_can_disburse_approved_loan_via_fake_daraja(): void
    {
        $user = User::factory()->create();
        $loan = $this->approvedLoan();

        $response = $this->actingAs($user)->postJson("/api/v1/loans/{$loan->id}/disburse");

        $response->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.disbursements.0.status', 'successful');

        $this->assertDatabaseHas('disbursements', [
            'loan_id' => $loan->id,
            'status' => DisbursementStatus::Successful->value,
            'daraja_conversation_id' => 'fake-conversation-id',
        ]);

        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'status' => LoanStatus::Active->value,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'loan.disbursed',
            'auditable_id' => $loan->id,
        ]);
    }

    public function test_failed_daraja_keeps_loan_in_disbursement_requested(): void
    {
        FakeDarajaGateway::$shouldSucceed = false;
        $user = User::factory()->create();
        $loan = $this->approvedLoan();

        $response = $this->actingAs($user)->postJson("/api/v1/loans/{$loan->id}/disburse");

        $response->assertOk()
            ->assertJsonPath('data.status', 'disbursement_requested')
            ->assertJsonPath('data.disbursements.0.status', 'failed');

        $this->assertDatabaseHas('disbursements', [
            'loan_id' => $loan->id,
            'status' => DisbursementStatus::Failed->value,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'loan.disbursement_failed',
            'auditable_id' => $loan->id,
        ]);
    }

    public function test_cannot_disburse_pending_loan(): void
    {
        $user = User::factory()->create();
        $loan = Loan::factory()->create(['status' => LoanStatus::Pending]);

        $this->actingAs($user)
            ->postJson("/api/v1/loans/{$loan->id}/disburse")
            ->assertUnprocessable();
    }

    public function test_can_retry_after_failed_disbursement(): void
    {
        FakeDarajaGateway::$shouldSucceed = false;
        $user = User::factory()->create();
        $loan = $this->approvedLoan();

        $this->actingAs($user)->postJson("/api/v1/loans/{$loan->id}/disburse")->assertOk();

        FakeDarajaGateway::$shouldSucceed = true;

        $this->actingAs($user)
            ->postJson("/api/v1/loans/{$loan->id}/disburse")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseCount('disbursements', 2);
    }

    private function approvedLoan(): Loan
    {
        $customer = Customer::factory()->create(['phone' => '254712345678']);
        $product = LoanProduct::factory()->create(['term_length' => 2]);
        $loan = Loan::factory()->create([
            'customer_id' => $customer->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 2500,
            'status' => LoanStatus::Pending,
        ]);

        return app(ApproveLoanAction::class)->handle($loan);
    }
}
