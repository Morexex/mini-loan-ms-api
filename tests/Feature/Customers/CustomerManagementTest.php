<?php

namespace Tests\Feature\Customers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_customers(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
    }

    public function test_ops_can_create_customer_with_wallet_and_audit(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Jane Borrower',
            'phone' => '0712345678',
            'id_number' => '12345678',
            'email' => 'jane@example.com',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.phone', '254712345678')
            ->assertJsonPath('data.wallet.balance', '0.00')
            ->assertJsonPath('data.wallet.currency', 'KES');

        $this->assertDatabaseHas('customers', [
            'phone' => '254712345678',
            'name' => 'Jane Borrower',
        ]);

        $customer = Customer::query()->firstOrFail();
        $this->assertDatabaseHas('wallet_accounts', [
            'customer_id' => $customer->id,
            'balance' => 0,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customer.created',
            'auditable_type' => $customer->getMorphClass(),
            'auditable_id' => $customer->id,
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_duplicate_phone_is_rejected(): void
    {
        $user = User::factory()->create();
        Customer::factory()->create(['phone' => '254712345678']);

        $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Other',
            'phone' => '0712345678',
            'id_number' => '99999999',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_ops_can_list_search_and_update_customers(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create([
            'name' => 'Searchable Person',
            'phone' => '254700111222',
        ]);
        WalletAccount::query()->create([
            'customer_id' => $customer->id,
            'balance' => 0,
            'currency' => 'KES',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/customers?search=Searchable')
            ->assertOk()
            ->assertJsonPath('data.0.id', $customer->id);

        $this->actingAs($user)
            ->patchJson("/api/v1/customers/{$customer->id}", [
                'name' => 'Updated Name',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customer.updated',
            'auditable_id' => $customer->id,
        ]);
    }

    public function test_ops_can_show_customer(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        WalletAccount::query()->create([
            'customer_id' => $customer->id,
            'balance' => 0,
            'currency' => 'KES',
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id);
    }
}
