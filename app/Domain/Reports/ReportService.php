<?php

namespace App\Domain\Reports;

use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\Installment;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\WalletAccount;
use App\Models\WebhookLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $startOfDay = $asOf->startOfDay();
        $startOfMonth = $asOf->startOfMonth();

        $outstanding = Installment::query()
            ->whereIn('status', [
                InstallmentStatus::Scheduled->value,
                InstallmentStatus::Due->value,
                InstallmentStatus::PartiallyPaid->value,
                InstallmentStatus::Overdue->value,
            ])
            ->whereHas('loan', fn ($q) => $q->where('status', LoanStatus::Active->value))
            ->selectRaw('COALESCE(SUM(amount_due - amount_paid), 0) as total')
            ->value('total');

        $paymentsToday = Payment::query()
            ->where('status', PaymentStatus::Posted->value)
            ->where('created_at', '>=', $startOfDay)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total')
            ->first();

        $paymentsMtd = Payment::query()
            ->where('status', PaymentStatus::Posted->value)
            ->where('created_at', '>=', $startOfMonth)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total')
            ->first();

        return [
            'as_of' => $asOf->toIso8601String(),
            'loans' => [
                'active' => Loan::query()->where('status', LoanStatus::Active)->count(),
                'completed' => Loan::query()->where('status', LoanStatus::Completed)->count(),
                'pending' => Loan::query()->where('status', LoanStatus::Pending)->count(),
            ],
            'outstanding_balance' => $this->money((string) $outstanding),
            'payments' => [
                'today' => [
                    'count' => (int) ($paymentsToday->cnt ?? 0),
                    'amount' => $this->money((string) ($paymentsToday->total ?? '0')),
                ],
                'mtd' => [
                    'count' => (int) ($paymentsMtd->cnt ?? 0),
                    'amount' => $this->money((string) ($paymentsMtd->total ?? '0')),
                ],
            ],
            'reconciliation' => [
                'unmatched_webhooks' => WebhookLog::query()
                    ->where('processing_status', WebhookProcessingStatus::Unmatched)
                    ->count(),
                'expired_intents' => PaymentIntent::query()
                    ->where('status', PaymentIntentStatus::Expired)
                    ->count(),
                'open_intents' => PaymentIntent::query()
                    ->whereIn('status', [
                        PaymentIntentStatus::Pending->value,
                        PaymentIntentStatus::Submitted->value,
                        PaymentIntentStatus::AwaitingCallback->value,
                        PaymentIntentStatus::Matched->value,
                    ])
                    ->count(),
            ],
            'wallet_liability' => $this->money((string) WalletAccount::query()->sum('balance')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function aging(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now()->startOfDay();
        $today = $asOf->toDateString();

        $rows = Installment::query()
            ->whereIn('status', [
                InstallmentStatus::Scheduled->value,
                InstallmentStatus::Due->value,
                InstallmentStatus::PartiallyPaid->value,
                InstallmentStatus::Overdue->value,
            ])
            ->whereHas('loan', fn ($q) => $q->where('status', LoanStatus::Active->value))
            ->select([
                'due_date',
                DB::raw('(amount_due - amount_paid) as outstanding'),
            ])
            ->get();

        $buckets = [
            'current' => ['label' => 'Current (not past due)', 'count' => 0, 'amount' => '0.00'],
            '1_30' => ['label' => '1–30 days past due', 'count' => 0, 'amount' => '0.00'],
            '31_60' => ['label' => '31–60 days past due', 'count' => 0, 'amount' => '0.00'],
            '61_plus' => ['label' => '61+ days past due', 'count' => 0, 'amount' => '0.00'],
        ];

        foreach ($rows as $row) {
            $outstanding = $this->money((string) $row->outstanding);
            if (bccomp($outstanding, '0', 2) !== 1) {
                continue;
            }

            $due = CarbonImmutable::parse($row->due_date)->startOfDay();
            $daysPastDue = $due->diffInDays($asOf, false);

            if ($daysPastDue <= 0) {
                $key = 'current';
            } elseif ($daysPastDue <= 30) {
                $key = '1_30';
            } elseif ($daysPastDue <= 60) {
                $key = '31_60';
            } else {
                $key = '61_plus';
            }

            $buckets[$key]['count']++;
            $buckets[$key]['amount'] = bcadd($buckets[$key]['amount'], $outstanding, 2);
        }

        return [
            'as_of' => $asOf->toIso8601String(),
            'as_of_date' => $today,
            'buckets' => array_values(array_map(
                fn (string $key, array $bucket) => ['key' => $key, ...$bucket],
                array_keys($buckets),
                $buckets,
            )),
            'total_outstanding' => $this->money(array_reduce(
                $buckets,
                fn (string $carry, array $bucket) => bcadd($carry, $bucket['amount'], 2),
                '0.00',
            )),
        ];
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }
}
