<?php

namespace Tests\Unit\Domain\Reports;

use App\Domain\Disbursements\Actions\DisburseLoanAction;
use App\Domain\Loans\Actions\ApproveLoanAction;
use App\Domain\Reports\ReportService;
use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Models\WalletAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_aging_buckets_split_current_and_past_due(): void
    {
        $loan = $this->activeLoan();
        $asOf = CarbonImmutable::parse('2026-08-06')->startOfDay();

        $first = $loan->installments()->orderBy('sequence')->first();
        $first->due_date = $asOf->subDays(45)->toDateString();
        $first->status = InstallmentStatus::Overdue;
        $first->save();

        $second = $loan->installments()->orderBy('sequence')->skip(1)->first();
        $second->due_date = $asOf->addDays(5)->toDateString();
        $second->status = InstallmentStatus::Scheduled;
        $second->save();

        $aging = app(ReportService::class)->aging($asOf);
        $byKey = collect($aging['buckets'])->keyBy('key');

        $this->assertSame(1, $byKey['31_60']['count']);
        $this->assertSame(1, $byKey['current']['count']);
        $this->assertSame(0, $byKey['1_30']['count']);
        $this->assertSame(0, $byKey['61_plus']['count']);
        $this->assertTrue(bccomp($aging['total_outstanding'], '0', 2) === 1);
    }

    private function activeLoan(): Loan
    {
        User::factory()->create();
        $customer = Customer::factory()->create(['phone' => '254700111222']);
        WalletAccount::query()->create([
            'customer_id' => $customer->id,
            'balance' => 0,
            'currency' => 'KES',
        ]);
        $product = LoanProduct::factory()->create([
            'term_length' => 2,
            'interest_rate' => 10,
            'fee_amount' => 0,
        ]);
        $loan = Loan::factory()->create([
            'customer_id' => $customer->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 2000,
            'status' => LoanStatus::Pending,
        ]);

        $loan = app(ApproveLoanAction::class)->handle($loan);

        return app(DisburseLoanAction::class)->handle($loan)->load('installments');
    }
}
