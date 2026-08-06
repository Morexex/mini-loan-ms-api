<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\WalletAccount;
use Illuminate\Database\Seeder;

class SandboxDemoCustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customer = Customer::query()->firstOrCreate(
            ['phone' => '254708374149'],
            [
                'name' => 'Sandbox Test Borrower',
                'id_number' => 'SANDBOX-001',
                'email' => 'sandbox-borrower@miniloan.test',
            ],
        );

        WalletAccount::query()->firstOrCreate(
            ['customer_id' => $customer->id],
            [
                'balance' => 0,
                'currency' => 'KES',
            ],
        );
    }
}
