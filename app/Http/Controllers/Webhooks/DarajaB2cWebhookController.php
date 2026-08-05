<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\WebhookProcessingStatus;
use App\Models\WebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Optional B2C result/timeout sink — logs raw payload for audit.
 * Disbursement completion today is driven by the synchronous B2C response path (M9).
 */
class DarajaB2cWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();
        $conversationId = (string) data_get($payload, 'Result.ConversationID', data_get($payload, 'ConversationID', ''));

        WebhookLog::query()->create([
            'provider' => 'daraja_b2c',
            'idempotency_key' => $conversationId !== ''
                ? 'daraja_b2c:'.$conversationId
                : 'daraja_b2c:'.sha1(json_encode($payload) ?: uniqid('b2c_', true)),
            'headers' => collect($request->headers->all())
                ->map(fn ($values) => is_array($values) ? implode(', ', $values) : (string) $values)
                ->except(['authorization', 'cookie'])
                ->all(),
            'payload' => $payload,
            'processing_status' => WebhookProcessingStatus::Processed,
            'error_message' => 'B2C callback logged; async result handling deferred.',
        ]);

        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
        ]);
    }
}
