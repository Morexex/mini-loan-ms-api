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
use Tests\TestCase;

class InstallmentScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_flat_schedule_splits_principal_interest_and_fee(): void
    {
        $customer = Customer::factory()->create();
        $product = LoanProduct::factory()->create([
            'interest_model' => InterestModel::Flat,
            'interest_rate' => 10,
            'term_unit' => TermUnit::Months,
            'term_length' => 2,
            'fee_amount' => 100,
        ]);

        $loan = Loan::factory()->create([
            'customer_id' => $customer->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 1000,
            'status' => LoanStatus::Pending,
        ]);

        $rows = (new InstallmentScheduleService)->generate(
            $loan,
            $product,
            CarbonImmutable::parse('2026-01-01'),
        );

        $this->assertCount(2, $rows);

        $principalSum = '0.00';
        $interestSum = '0.00';
        $feeSum = '0.00';
        foreach ($rows as $row) {
            $principalSum = bcadd($principalSum, $row['principal_due'], 2);
            $interestSum = bcadd($interestSum, $row['interest_due'], 2);
            $feeSum = bcadd($feeSum, $row['fee_due'], 2);
        }

        $this->assertSame('1000.00', $principalSum);
        $this->assertSame('100.00', $interestSum); // 10% flat of 1000
        $this->assertSame('100.00', $feeSum);
        $this->assertSame('2026-02-01', $rows[0]['due_date']);
        $this->assertSame('2026-03-01', $rows[1]['due_date']);
    }
}
