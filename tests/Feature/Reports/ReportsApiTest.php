<?php

namespace Tests\Feature\Reports;

use App\Domain\Disbursements\Actions\DisburseLoanAction;
use App\Domain\Loans\Actions\ApproveLoanAction;
use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Enums\PaymentEvidenceSource;
use App\Enums\PaymentIntentStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_reports(): void
    {
        $this->getJson('/api/v1/reports/overview')->assertUnauthorized();
        $this->getJson('/api/v1/reports/aging')->assertUnauthorized();
    }

    public function test_ops_can_view_overview_and_aging(): void
    {
        $user = User::factory()->create();
        $loan = $this->activeLoan();

        $first = $loan->installments()->orderBy('sequence')->first();
        $first->due_date = now()->subDays(10)->toDateString();
        $first->status = InstallmentStatus::Overdue;
        $first->save();

        $second = $loan->installments()->orderBy('sequence')->skip(1)->first();
        $second->due_date = now()->addDays(20)->toDateString();
        $second->status = InstallmentStatus::Scheduled;
        $second->save();

        Payment::query()->create([
            'payment_intent_id' => null,
            'customer_id' => $loan->customer_id,
            'loan_id' => $loan->id,
            'amount' => '100.00',
            'phone' => '254700111222',
            'status' => PaymentStatus::Posted,
            'evidence_source' => PaymentEvidenceSource::Manual,
            'evidenced_at' => now(),
            'idempotency_key' => 'report-test-payment-1',
            'receipt_number' => 'RPTTEST001',
        ]);

        WebhookLog::query()->create([
            'provider' => 'daraja_stk',
            'idempotency_key' => 'unmatched-report',
            'headers' => [],
            'payload' => [],
            'processing_status' => WebhookProcessingStatus::Unmatched,
        ]);

        PaymentIntent::query()->create([
            'customer_id' => $loan->customer_id,
            'loan_id' => $loan->id,
            'amount' => '50.00',
            'phone' => '254700111222',
            'status' => PaymentIntentStatus::Expired,
            'attempt_number' => 1,
            'expires_at' => now()->subHour(),
            'metadata' => [],
        ]);

        $overview = $this->actingAs($user)
            ->getJson('/api/v1/reports/overview')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $overview['loans']['active']);
        $this->assertSame(1, $overview['payments']['today']['count']);
        $this->assertSame('100.00', $overview['payments']['today']['amount']);
        $this->assertSame(1, $overview['reconciliation']['unmatched_webhooks']);
        $this->assertSame(1, $overview['reconciliation']['expired_intents']);
        $this->assertTrue(bccomp($overview['outstanding_balance'], '0', 2) === 1);

        $aging = $this->actingAs($user)
            ->getJson('/api/v1/reports/aging')
            ->assertOk()
            ->json('data');

        $byKey = collect($aging['buckets'])->keyBy('key');
        $this->assertSame(1, $byKey['current']['count']);
        $this->assertSame(1, $byKey['1_30']['count']);
        $this->assertTrue(bccomp($aging['total_outstanding'], '0', 2) === 1);
    }

    private function activeLoan(): Loan
    {
        $customer = Customer::factory()->create(['phone' => '254700111222']);
        WalletAccount::query()->create([
            'customer_id' => $customer->id,
            'balance' => '25.00',
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

        return app(DisburseLoanAction::class)->handle($loan);
    }
}
