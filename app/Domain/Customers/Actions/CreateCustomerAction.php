<?php

namespace App\Domain\Customers\Actions;

use App\Domain\Audit\RecordAuditLog;
use App\Domain\Customers\Support\PhoneNormalizer;
use App\Models\Customer;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Support\Facades\DB;

class CreateCustomerAction
{
    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer,
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    /**
     * @param  array{name: string, phone: string, id_number: string, email?: string|null}  $data
     */
    public function handle(array $data, ?User $actor = null, ?string $ip = null): Customer
    {
        $normalizedPhone = $this->phoneNormalizer->normalize($data['phone']);

        return DB::transaction(function () use ($data, $normalizedPhone, $actor, $ip): Customer {
            $customer = Customer::query()->create([
                'name' => $data['name'],
                'phone' => $normalizedPhone,
                'id_number' => $data['id_number'],
                'email' => $data['email'] ?? null,
            ]);

            WalletAccount::query()->create([
                'customer_id' => $customer->id,
                'balance' => 0,
                'currency' => 'KES',
            ]);

            $this->recordAuditLog->handle(
                auditable: $customer,
                action: 'customer.created',
                actor: $actor,
                after: $customer->only(['name', 'phone', 'id_number', 'email']),
                ip: $ip,
            );

            return $customer->load('walletAccount');
        });
    }
}
