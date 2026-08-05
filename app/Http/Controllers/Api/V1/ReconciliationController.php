<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reconciliation\Actions\ManualMatchAction;
use App\Domain\Reconciliation\Actions\ManualRejectAction;
use App\Enums\PaymentIntentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ManualMatchRequest;
use App\Http\Requests\Api\V1\ManualRejectRequest;
use App\Http\Resources\Api\V1\PaymentIntentResource;
use App\Http\Resources\Api\V1\WebhookLogResource;
use App\Models\PaymentIntent;
use App\Models\WebhookLog;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ReconciliationController extends Controller
{
    public function unmatched(Request $request): JsonResponse
    {
        $this->authorize('operate', WebhookLog::class);

        $webhooks = WebhookLog::query()
            ->where('processing_status', WebhookProcessingStatus::Unmatched)
            ->latest('id')
            ->paginate(perPage: min((int) $request->integer('per_page', 25), 100));

        $expiredIntents = PaymentIntent::query()
            ->where('status', PaymentIntentStatus::Expired)
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => [
                'unmatched_webhooks' => WebhookLogResource::collection($webhooks)->resolve(),
                'expired_intents' => PaymentIntentResource::collection($expiredIntents)->resolve(),
            ],
            'meta' => [
                'unmatched_webhooks' => [
                    'current_page' => $webhooks->currentPage(),
                    'last_page' => $webhooks->lastPage(),
                    'per_page' => $webhooks->perPage(),
                    'total' => $webhooks->total(),
                ],
            ],
        ]);
    }

    public function candidateIntents(Request $request): AnonymousResourceCollection
    {
        $this->authorize('operate', PaymentIntent::class);

        $request->validate([
            'phone' => ['nullable', 'string', 'max:32'],
            'loan_id' => ['nullable', 'integer', 'exists:loans,id'],
        ]);

        $query = PaymentIntent::query()
            ->whereIn('status', [
                PaymentIntentStatus::Pending->value,
                PaymentIntentStatus::Submitted->value,
                PaymentIntentStatus::AwaitingCallback->value,
                PaymentIntentStatus::Matched->value,
                PaymentIntentStatus::Expired->value,
            ])
            ->latest('id');

        if ($request->filled('phone')) {
            $query->where('phone', $request->string('phone')->toString());
        }

        if ($request->filled('loan_id')) {
            $query->where('loan_id', $request->integer('loan_id'));
        }

        return PaymentIntentResource::collection(
            $query->limit(50)->get()
        );
    }

    public function match(
        ManualMatchRequest $request,
        ManualMatchAction $action,
    ): JsonResponse {
        $this->authorize('operate', WebhookLog::class);

        $log = WebhookLog::query()->findOrFail($request->integer('webhook_log_id'));
        $intent = PaymentIntent::query()
            ->where('uuid', $request->string('payment_intent_uuid')->toString())
            ->firstOrFail();

        try {
            $result = $action->handle(
                log: $log,
                intent: $intent,
                reason: $request->string('reason')->toString(),
                actor: $request->user(),
                ip: $request->ip(),
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => $result->message,
            'data' => [
                'outcome' => $result->outcome,
                'payment_intent_uuid' => $result->paymentIntentUuid,
            ],
        ]);
    }

    public function reject(
        ManualRejectRequest $request,
        ManualRejectAction $action,
    ): JsonResponse {
        $this->authorize('operate', WebhookLog::class);

        $log = WebhookLog::query()->findOrFail($request->integer('webhook_log_id'));

        try {
            $rejected = $action->handle(
                log: $log,
                reason: $request->string('reason')->toString(),
                actor: $request->user(),
                ip: $request->ip(),
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Evidence rejected.',
            'data' => (new WebhookLogResource($rejected))->resolve(),
        ]);
    }
}
