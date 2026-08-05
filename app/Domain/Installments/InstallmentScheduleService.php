<?php

namespace App\Domain\Installments;

use App\Enums\InstallmentStatus;
use App\Enums\TermUnit;
use App\Models\Loan;
use App\Models\LoanProduct;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class InstallmentScheduleService
{
    /**
     * Flat interest (ADR 0001):
     * - total_interest = principal * (rate / 100) for the full product term (flat charge)
     * - product fee_amount is spread evenly across installments
     * - principal + interest + fee split across term_length periods
     * - remainder cents applied to the final installment
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function generate(Loan $loan, LoanProduct $product, ?CarbonImmutable $approvedAt = null): Collection
    {
        $approvedAt ??= CarbonImmutable::now();
        $periods = (int) $product->term_length;

        if ($periods < 1) {
            throw new \InvalidArgumentException('Loan product term_length must be at least 1.');
        }

        $principal = (string) $loan->principal_amount;
        $rate = (string) $product->interest_rate;
        $feeTotal = (string) $product->fee_amount;

        $totalInterest = bcmul($principal, bcdiv($rate, '100', 8), 2);
        $totalPrincipal = $principal;
        $totalFee = bcadd($feeTotal, '0', 2);

        $basePrincipal = bcdiv($totalPrincipal, (string) $periods, 2);
        $baseInterest = bcdiv($totalInterest, (string) $periods, 2);
        $baseFee = bcdiv($totalFee, (string) $periods, 2);

        $allocatedPrincipal = '0.00';
        $allocatedInterest = '0.00';
        $allocatedFee = '0.00';

        $rows = collect();

        for ($sequence = 1; $sequence <= $periods; $sequence++) {
            $isLast = $sequence === $periods;

            $principalDue = $isLast
                ? bcsub($totalPrincipal, $allocatedPrincipal, 2)
                : $basePrincipal;
            $interestDue = $isLast
                ? bcsub($totalInterest, $allocatedInterest, 2)
                : $baseInterest;
            $feeDue = $isLast
                ? bcsub($totalFee, $allocatedFee, 2)
                : $baseFee;

            $allocatedPrincipal = bcadd($allocatedPrincipal, $principalDue, 2);
            $allocatedInterest = bcadd($allocatedInterest, $interestDue, 2);
            $allocatedFee = bcadd($allocatedFee, $feeDue, 2);

            $amountDue = bcadd(bcadd($principalDue, $interestDue, 2), $feeDue, 2);
            $dueDate = $this->dueDateFor($approvedAt, $product->term_unit, $sequence);

            $rows->push([
                'loan_id' => $loan->id,
                'sequence' => $sequence,
                'due_date' => $dueDate->toDateString(),
                'principal_due' => $principalDue,
                'interest_due' => $interestDue,
                'fee_due' => $feeDue,
                'amount_due' => $amountDue,
                'amount_paid' => '0.00',
                'status' => InstallmentStatus::Scheduled->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $rows;
    }

    private function dueDateFor(CarbonImmutable $approvedAt, TermUnit $unit, int $sequence): CarbonImmutable
    {
        return match ($unit) {
            TermUnit::Months => $approvedAt->addMonthsNoOverflow($sequence),
            TermUnit::Weeks => $approvedAt->addWeeks($sequence),
        };
    }
}
