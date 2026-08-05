<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\LoanProducts\Actions\CreateLoanProductAction;
use App\Domain\LoanProducts\Actions\UpdateLoanProductAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLoanProductRequest;
use App\Http\Requests\Api\V1\UpdateLoanProductRequest;
use App\Http\Resources\Api\V1\LoanProductResource;
use App\Models\LoanProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class LoanProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', LoanProduct::class);

        $products = LoanProduct::query()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where('name', 'like', "%{$search}%");
            })
            ->when($request->has('is_active'), function ($query) use ($request): void {
                $query->where('is_active', $request->boolean('is_active'));
            })
            ->latest('id')
            ->paginate(perPage: min((int) $request->integer('per_page', 15), 100));

        return LoanProductResource::collection($products);
    }

    public function store(StoreLoanProductRequest $request, CreateLoanProductAction $action): JsonResponse
    {
        $this->authorize('create', LoanProduct::class);

        $product = $action->handle(
            data: $request->validated(),
            actor: $request->user(),
            ip: $request->ip(),
        );

        return (new LoanProductResource($product))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(LoanProduct $loanProduct): LoanProductResource
    {
        $this->authorize('view', $loanProduct);

        return new LoanProductResource($loanProduct);
    }

    public function update(
        UpdateLoanProductRequest $request,
        LoanProduct $loanProduct,
        UpdateLoanProductAction $action,
    ): LoanProductResource {
        $this->authorize('update', $loanProduct);

        $product = $action->handle(
            product: $loanProduct,
            data: $request->validated(),
            actor: $request->user(),
            ip: $request->ip(),
        );

        return new LoanProductResource($product);
    }
}
