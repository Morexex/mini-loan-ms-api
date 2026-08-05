<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Loans\Actions\ApproveLoanAction;
use App\Domain\Loans\Actions\CreateLoanApplicationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLoanRequest;
use App\Http\Resources\Api\V1\LoanResource;
use App\Models\Loan;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LoanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Loan::class);

        $loans = Loan::query()
            ->with(['customer', 'loanProduct'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->latest('id')
            ->paginate(perPage: min((int) $request->integer('per_page', 15), 100));

        return LoanResource::collection($loans);
    }

    public function store(StoreLoanRequest $request, CreateLoanApplicationAction $action): JsonResponse
    {
        $this->authorize('create', Loan::class);

        $loan = $action->handle(
            data: $request->validated(),
            actor: $request->user(),
            ip: $request->ip(),
        );

        return (new LoanResource($loan))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Loan $loan): LoanResource
    {
        $this->authorize('view', $loan);

        $loan->load(['customer', 'loanProduct', 'installments']);

        return new LoanResource($loan);
    }

    public function installments(Loan $loan): AnonymousResourceCollection
    {
        $this->authorize('view', $loan);

        $installments = $loan->installments()->orderBy('sequence')->get();

        return \App\Http\Resources\Api\V1\InstallmentResource::collection($installments);
    }

    public function approve(Request $request, Loan $loan, ApproveLoanAction $action): LoanResource|JsonResponse
    {
        $this->authorize('approve', $loan);

        try {
            $loan = $action->handle(
                loan: $loan,
                actor: $request->user(),
                ip: $request->ip(),
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new LoanResource($loan);
    }
}
