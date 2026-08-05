<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Reconciliation\Support\DarajaStkCallbackMapper;
use App\Enums\WebhookProcessingStatus;
use App\Jobs\ReconcilePaymentEvidenceJob;
use App\Models\WebhookLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Throwable;

class DarajaStkWebhookController extends Controller
{
    public function __invoke(Request $request, DarajaStkCallbackMapper $mapper): JsonResponse
    {
        $payload = $request->all();

        $log = WebhookLog::query()->create([
            'provider' => 'daraja_stk',
            'idempotency_key' => null,
            'headers' => $this->safeHeaders($request),
            'payload' => $payload,
            'processing_status' => WebhookProcessingStatus::Received,
        ]);

        try {
            $evidence = $mapper->map($payload, $log->id);

            try {
                $log->idempotency_key = $evidence->idempotencyKey;
                $log->save();
            } catch (UniqueConstraintViolationException) {
                // Keep raw delivery for audit; suffix key so UNIQUE holds.
                $log->idempotency_key = $evidence->idempotencyKey.':dup:'.$log->id;
                $log->processing_status = WebhookProcessingStatus::IgnoredDuplicate;
                $log->error_message = 'Duplicate STK callback idempotency key.';
                $log->save();

                return $this->ack();
            }

            ReconcilePaymentEvidenceJob::dispatch($evidence);
        } catch (InvalidArgumentException $e) {
            $log->processing_status = WebhookProcessingStatus::Failed;
            $log->error_message = $e->getMessage();
            $log->save();
        } catch (Throwable $e) {
            $log->processing_status = WebhookProcessingStatus::Failed;
            $log->error_message = $e->getMessage();
            $log->save();
        }

        return $this->ack();
    }

    private function ack(): JsonResponse
    {
        // Daraja expects a fast ACK regardless of business outcome.
        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function safeHeaders(Request $request): array
    {
        return collect($request->headers->all())
            ->map(fn ($values) => is_array($values) ? implode(', ', $values) : (string) $values)
            ->except(['authorization', 'cookie', 'x-sms-forwarder-secret'])
            ->all();
    }
}
