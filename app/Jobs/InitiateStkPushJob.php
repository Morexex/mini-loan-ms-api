<?php

namespace App\Jobs;

use App\Domain\Payments\Actions\SubmitStkPushAction;
use App\Models\PaymentIntent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class InitiateStkPushJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $paymentIntentId,
    ) {}

    public function handle(SubmitStkPushAction $action): void
    {
        $intent = PaymentIntent::query()->findOrFail($this->paymentIntentId);
        $action->handle($intent);
    }
}
