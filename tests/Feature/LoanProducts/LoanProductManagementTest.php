<?php

namespace Tests\Feature\LoanProducts;

use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanProductManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_loan_products(): void
    {
        $this->getJson('/api/v1/loan-products')->assertUnauthorized();
    }

    public function test_ops_can_create_flat_interest_product(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/loan-products', [
            'name' => 'Staff Salary Advance',
            'interest_model' => 'flat',
            'interest_rate' => 10,
            'term_unit' => 'months',
            'term_length' => 6,
            'fee_amount' => 100,
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.interest_model', 'flat')
            ->assertJsonPath('data.term_unit', 'months')
            ->assertJsonPath('data.term_length', 6);

        $this->assertDatabaseHas('loan_products', [
            'name' => 'Staff Salary Advance',
            'interest_model' => 'flat',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'loan_product.created',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_reducing_balance_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/loan-products', [
            'name' => 'Bad Product',
            'interest_model' => 'reducing',
            'interest_rate' => 12,
            'term_unit' => 'months',
            'term_length' => 12,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['interest_model']);
    }

    public function test_ops_can_list_filter_and_update_products(): void
    {
        $user = User::factory()->create();
        $product = LoanProduct::factory()->create([
            'name' => 'Alpha Product',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/loan-products?search=Alpha&is_active=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id);

        $this->actingAs($user)
            ->patchJson("/api/v1/loan-products/{$product->id}", [
                'name' => 'Alpha Product Updated',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Alpha Product Updated')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'loan_product.updated',
            'auditable_id' => $product->id,
        ]);
    }

    public function test_ops_can_show_product(): void
    {
        $user = User::factory()->create();
        $product = LoanProduct::factory()->create();

        $this->actingAs($user)
            ->getJson("/api/v1/loan-products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id);
    }
}
