<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customers\Actions\CreateCustomerAction;
use App\Domain\Customers\Actions\UpdateCustomerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCustomerRequest;
use App\Http\Requests\Api\V1\UpdateCustomerRequest;
use App\Http\Resources\Api\V1\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);

        $customers = Customer::query()
            ->with('walletAccount')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('id_number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate(perPage: min((int) $request->integer('per_page', 15), 100));

        return CustomerResource::collection($customers);
    }

    public function store(StoreCustomerRequest $request, CreateCustomerAction $action): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $customer = $action->handle(
            data: $request->validated(),
            actor: $request->user(),
            ip: $request->ip(),
        );

        return (new CustomerResource($customer))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Customer $customer): CustomerResource
    {
        $this->authorize('view', $customer);

        $customer->load('walletAccount');

        return new CustomerResource($customer);
    }

    public function update(
        UpdateCustomerRequest $request,
        Customer $customer,
        UpdateCustomerAction $action,
    ): CustomerResource {
        $this->authorize('update', $customer);

        $customer = $action->handle(
            customer: $customer,
            data: $request->validated(),
            actor: $request->user(),
            ip: $request->ip(),
        );

        return new CustomerResource($customer);
    }
}
