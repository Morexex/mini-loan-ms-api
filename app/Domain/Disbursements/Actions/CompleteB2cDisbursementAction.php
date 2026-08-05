<?php

namespace App\Domain\Disbursements\Actions;

use App\Domain\Audit\RecordAuditLog;
use App\Domain\Loans\Support\LoanStatusGuard;
use App\Enums\DisbursementStatus;
use App\Enums\LoanStatus;
use App\Infrastructure\Daraja\DarajaGateway;
use App\Models\Disbursement;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompleteB2cDisbursementAction
{
    public function __construct(
        private readonly DarajaGateway $darajaGateway,
        private readonly LoanStatusGuard $statusGuard,
        private readonly RecordAuditLog $recordAuditLog,
    ) {}

    public function handle(Disbursement $disbursement): Disbursement
    {
        $disbursement->loadMissing('loan.customer');
        $loan = $disbursement->loan;

        $payload = [
            'amount' => (int) round((float) $disbursement->amount),
            'phone' => $disbursement->phone,
            'remarks' => 'Loan #'.$loan->id.' disbursement',
            'occasion' => 'LoanDisbursement',
        ];

        $disbursement->status = DisbursementStatus::Submitted;
        $disbursement->request_payload = $payload;
        $disbursement->save();

        try {
            $result = $this->darajaGateway->b2c($payload);
        } catch (Throwable $exception) {
            return $this->markFailed($disbursement, $exception->getMessage(), [
                'exception' => $exception->getMessage(),
            ]);
        }

        $disbursement->request_payload = $result['request'] ?? $payload;
        $disbursement->response_payload = $result['response'] ?? $result;
        $disbursement->daraja_conversation_id = $result['conversation_id'] ?? null;
        $disbursement->daraja_originator_conversation_id = $result['originator_conversation_id'] ?? null;

        if (! ($result['successful'] ?? false)) {
            return $this->markFailed(
                $disbursement,
                (string) ($result['response_description'] ?? 'Daraja B2C was not accepted.'),
                $result['response'] ?? $result,
            );
        }

        return DB::transaction(function () use ($disbursement, $loan): Disbursement {
            $disbursement->status = DisbursementStatus::Successful;
            $disbursement->completed_at = now();
            $disbursement->error_message = null;
            $disbursement->save();

            $this->statusGuard->assertCanTransition($loan->status, LoanStatus::Disbursed);
            $loan->status = LoanStatus::Disbursed;
            $loan->disbursed_at = now();
            $loan->save();

            $this->statusGuard->assertCanTransition($loan->status, LoanStatus::Active);
            $loan->status = LoanStatus::Active;
            $loan->activated_at = now();
            $loan->save();

            $this->recordAuditLog->handle(
                auditable: $loan,
                action: 'loan.disbursed',
                actor: null,
                after: [
                    'status' => $loan->status->value,
                    'disbursement_uuid' => $disbursement->uuid,
                    'daraja_conversation_id' => $disbursement->daraja_conversation_id,
                ],
            );

            return $disbursement->fresh('loan');
        });
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function markFailed(Disbursement $disbursement, string $message, array $response): Disbursement
    {
        $disbursement->status = DisbursementStatus::Failed;
        $disbursement->completed_at = now();
        $disbursement->error_message = $message;
        $disbursement->response_payload = $response;
        $disbursement->save();

        $this->recordAuditLog->handle(
            auditable: $disbursement->loan,
            action: 'loan.disbursement_failed',
            actor: null,
            after: [
                'status' => $disbursement->loan->status->value,
                'disbursement_uuid' => $disbursement->uuid,
                'error' => $message,
            ],
        );

        return $disbursement->fresh('loan');
    }
}
