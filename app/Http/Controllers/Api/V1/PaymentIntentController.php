<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\Actions\CreatePaymentIntentAction;
use App\Domain\Payments\Actions\SimulateStkSuccessAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePaymentIntentRequest;
use App\Http\Resources\Api\V1\PaymentIntentResource;
use App\Models\Loan;
use App\Models\PaymentIntent;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PaymentIntentController extends Controller
{
    public function index(Request $request, Loan $loan): AnonymousResourceCollection
    {
        $this->authorize('view', $loan);

        $intents = PaymentIntent::query()
            ->where('loan_id', $loan->id)
            ->latest('id')
            ->paginate(perPage: min((int) $request->integer('per_page', 15), 100));

        return PaymentIntentResource::collection($intents);
    }

    public function store(
        StorePaymentIntentRequest $request,
        Loan $loan,
        CreatePaymentIntentAction $action,
    ): JsonResponse {
        $this->authorize('collect', $loan);

        try {
            $intent = $action->handle(
                loan: $loan,
                data: $request->validated(),
                actor: $request->user(),
                ip: $request->ip(),
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return (new PaymentIntentResource($intent))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(PaymentIntent $paymentIntent): PaymentIntentResource
    {
        $paymentIntent->loadMissing('loan');
        $this->authorize('view', $paymentIntent->loan);

        return new PaymentIntentResource($paymentIntent);
    }

    public function simulateStkSuccess(
        Request $request,
        Loan $loan,
        PaymentIntent $paymentIntent,
        SimulateStkSuccessAction $action,
    ): JsonResponse {
        $this->authorize('collect', $loan);

        if ((int) $paymentIntent->loan_id !== (int) $loan->id) {
            return response()->json([
                'message' => 'Payment intent does not belong to this loan.',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $intent = $action->handle(
                intent: $paymentIntent,
                actor: $request->user(),
                ip: $request->ip(),
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Simulated STK success callback ingested and allocated.',
            'data' => (new PaymentIntentResource($intent))->resolve(),
            'meta' => [
                'stk_simulation' => true,
            ],
        ]);
    }
}
