<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class OpsUserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'ops@miniloan.test'],
            [
                'name' => 'Ops Officer',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
    }
}
