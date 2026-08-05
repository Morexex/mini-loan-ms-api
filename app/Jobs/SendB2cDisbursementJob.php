<?php

namespace App\Jobs;

use App\Domain\Disbursements\Actions\CompleteB2cDisbursementAction;
use App\Models\Disbursement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendB2cDisbursementJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $disbursementId,
    ) {}

    public function handle(CompleteB2cDisbursementAction $action): void
    {
        $disbursement = Disbursement::query()->findOrFail($this->disbursementId);
        $action->handle($disbursement);
    }
}
