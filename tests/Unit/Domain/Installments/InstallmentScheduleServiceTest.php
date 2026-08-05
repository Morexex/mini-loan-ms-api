<?php

namespace Tests\Unit\Domain\Installments;

use App\Domain\Installments\InstallmentScheduleService;
use App\Enums\InterestModel;
use App\Enums\LoanStatus;
use App\Enums\TermUnit;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class InstallmentScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_flat_schedule_splits_principal_interest_and_fee(): void
    {
        $loan = $this->makeLoan(
            principal: '1000.00',
            rate: '10',
            term: 2,
            unit: TermUnit::Months,
            fee: '100.00',
        );

        $rows = (new InstallmentScheduleService)->generate(
            $loan,
            $loan->loanProduct,
            CarbonImmutable::parse('2026-01-01'),
        );

        $totals = (new InstallmentScheduleService)->totals($rows);

        $this->assertSame(2, $totals['count']);
        $this->assertSame('1000.00', $totals['principal']);
        $this->assertSame('100.00', $totals['interest']);
        $this->assertSame('100.00', $totals['fee']);
        $this->assertSame('2026-02-01', $rows[0]['due_date']);
        $this->assertSame('2026-03-01', $rows[1]['due_date']);
    }

    public function test_weekly_due_dates_and_remainder_cents_land_on_last_row(): void
    {
        $loan = $this->makeLoan(
            principal: '100.00',
            rate: '10',
            term: 3,
            unit: TermUnit::Weeks,
            fee: '0.00',
        );

        $rows = (new InstallmentScheduleService)->generate(
            $loan,
            $loan->loanProduct,
            CarbonImmutable::parse('2026-01-01'),
        );

        $this->assertCount(3, $rows);
        $this->assertSame('2026-01-08', $rows[0]['due_date']);
        $this->assertSame('2026-01-15', $rows[1]['due_date']);
        $this->assertSame('2026-01-22', $rows[2]['due_date']);

        // 100 / 3 = 33.33, 33.33, 33.34
        $this->assertSame('33.33', $rows[0]['principal_due']);
        $this->assertSame('33.33', $rows[1]['principal_due']);
        $this->assertSame('33.34', $rows[2]['principal_due']);

        $totals = (new InstallmentScheduleService)->totals($rows);
        $this->assertSame('100.00', $totals['principal']);
        $this->assertSame('10.00', $totals['interest']);
    }

    public function test_rejects_zero_principal(): void
    {
        $loan = $this->makeLoan(
            principal: '0.00',
            rate: '10',
            term: 2,
            unit: TermUnit::Months,
            fee: '0.00',
        );

        $this->expectException(InvalidArgumentException::class);
        (new InstallmentScheduleService)->generate($loan, $loan->loanProduct);
    }

    private function makeLoan(
        string $principal,
        string $rate,
        int $term,
        TermUnit $unit,
        string $fee,
    ): Loan {
        $customer = Customer::factory()->create();
        $product = LoanProduct::factory()->create([
            'interest_model' => InterestModel::Flat,
            'interest_rate' => $rate,
            'term_unit' => $unit,
            'term_length' => $term,
            'fee_amount' => $fee,
        ]);

        return Loan::factory()->create([
            'customer_id' => $customer->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'status' => LoanStatus::Pending,
        ])->load('loanProduct');
    }
}
