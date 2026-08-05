<?php

namespace App\Jobs;

use App\Domain\Audit\RecordAuditLog;
use App\Enums\PaymentIntentStatus;
use App\Models\PaymentIntent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ExpireStalePaymentIntentsJob implements ShouldQueue
{
    use Queueable;

    public function handle(RecordAuditLog $audit): void
    {
        $stale = PaymentIntent::query()
            ->whereIn('status', [
                PaymentIntentStatus::Pending->value,
                PaymentIntentStatus::Submitted->value,
                PaymentIntentStatus::AwaitingCallback->value,
            ])
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($stale as $intent) {
            DB::transaction(function () use ($intent, $audit): void {
                $locked = PaymentIntent::query()->lockForUpdate()->find($intent->id);
                if (! $locked) {
                    return;
                }

                if (! in_array($locked->status, [
                    PaymentIntentStatus::Pending,
                    PaymentIntentStatus::Submitted,
                    PaymentIntentStatus::AwaitingCallback,
                ], true)) {
                    return;
                }

                if ($locked->expires_at->isFuture()) {
                    return;
                }

                $before = ['status' => $locked->status->value];
                $locked->status = PaymentIntentStatus::Expired;
                $locked->save();

                $audit->handle(
                    auditable: $locked,
                    action: 'payment_intent.expired',
                    before: $before,
                    after: ['status' => $locked->status->value],
                    reason: 'TTL elapsed without successful reconciliation.',
                );
            });
        }
    }
}
