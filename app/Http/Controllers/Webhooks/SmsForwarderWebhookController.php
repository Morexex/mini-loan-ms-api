<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Reconciliation\Support\SmsForwarderPayloadMapper;
use App\Enums\WebhookProcessingStatus;
use App\Jobs\ReconcilePaymentEvidenceJob;
use App\Models\WebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Throwable;

class SmsForwarderWebhookController extends Controller
{
    public function __invoke(Request $request, SmsForwarderPayloadMapper $mapper): JsonResponse
    {
        $payload = $request->all();

        $log = WebhookLog::query()->create([
            'provider' => 'sms_forwarder',
            'idempotency_key' => null,
            'headers' => collect($request->headers->all())
                ->map(fn ($values) => is_array($values) ? implode(', ', $values) : (string) $values)
                ->except(['authorization', 'cookie', 'x-sms-forwarder-secret'])
                ->all(),
            'payload' => $payload,
            'processing_status' => WebhookProcessingStatus::Received,
        ]);

        try {
            $evidence = $mapper->map($payload, $log->id);
            $log->idempotency_key = $evidence->idempotencyKey;
            $log->save();

            ReconcilePaymentEvidenceJob::dispatch($evidence);
        } catch (InvalidArgumentException $e) {
            $log->processing_status = WebhookProcessingStatus::Failed;
            $log->error_message = $e->getMessage();
            $log->save();

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            $log->processing_status = WebhookProcessingStatus::Failed;
            $log->error_message = $e->getMessage();
            $log->save();

            return response()->json([
                'message' => 'Unable to ingest SMS evidence.',
            ], 500);
        }

        return response()->json([
            'message' => 'Accepted',
            'webhook_log_id' => $log->id,
        ], 202);
    }
}
