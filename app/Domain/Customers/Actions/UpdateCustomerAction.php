<?php

namespace App\Domain\Customers\Actions;

use App\Domain\Audit\RecordAuditLog;
use App\Domain\Customers\Support\PhoneNormalizer;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateCustomerAction
{
    public function __construct(
        private readonly PhoneNormalizer $phoneNormalizer,
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    /**
     * @param  array{name?: string, phone?: string, id_number?: string, email?: string|null}  $data
     */
    public function handle(Customer $customer, array $data, ?User $actor = null, ?string $ip = null): Customer
    {
        return DB::transaction(function () use ($customer, $data, $actor, $ip): Customer {
            $before = $customer->only(['name', 'phone', 'id_number', 'email']);

            if (array_key_exists('phone', $data) && $data['phone'] !== null) {
                $data['phone'] = $this->phoneNormalizer->normalize($data['phone']);
            }

            $customer->fill($data);
            $customer->save();

            $this->recordAuditLog->handle(
                auditable: $customer,
                action: 'customer.updated',
                actor: $actor,
                before: $before,
                after: $customer->only(['name', 'phone', 'id_number', 'email']),
                ip: $ip,
            );

            return $customer->load('walletAccount');
        });
    }
}
