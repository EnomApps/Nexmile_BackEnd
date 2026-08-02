<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer address book (EP2, feeding EP4's 1 km discovery).
 *
 * Every action is scoped to the signed-in user's own addresses, so one
 * customer can never read or modify another's.
 */
class AddressController extends Controller
{
    /**
     * List your addresses
     *
     * Default address first, then most recently added.
     */
    public function index(Request $request): JsonResponse
    {
        $addresses = $request->user()->addresses()
            ->orderByDesc('is_default')
            ->latest('id')
            ->get();

        return response()->json(['data' => AddressResource::collection($addresses)]);
    }

    /**
     * Add an address
     *
     * The first address a customer saves becomes their default automatically.
     */
    public function store(AddressRequest $request): JsonResponse
    {
        $user = $request->user();
        $isFirst = $user->addresses()->count() === 0;

        $address = DB::transaction(function () use ($request, $user, $isFirst) {
            $address = $user->addresses()->create($request->validated() + [
                'label' => $request->input('label', 'home'),
            ]);

            if ($isFirst || $request->boolean('is_default')) {
                $address->makeDefault();
            }

            return $address;
        });

        return response()->json([
            'message' => 'Address saved.',
            'data' => new AddressResource($address->fresh()),
        ], 201);
    }

    /**
     * Show one address
     */
    public function show(Request $request, int $address): JsonResponse
    {
        return response()->json([
            'data' => new AddressResource($this->findOwned($request, $address)),
        ]);
    }

    /**
     * Update an address
     */
    public function update(AddressRequest $request, int $address): JsonResponse
    {
        $model = $this->findOwned($request, $address);

        DB::transaction(function () use ($request, $model) {
            $model->update($request->validated());

            if ($request->boolean('is_default')) {
                $model->makeDefault();
            }
        });

        return response()->json([
            'message' => 'Address updated.',
            'data' => new AddressResource($model->fresh()),
        ]);
    }

    /**
     * Make an address the default
     */
    public function makeDefault(Request $request, int $address): JsonResponse
    {
        $model = $this->findOwned($request, $address);
        $model->makeDefault();

        return response()->json([
            'message' => 'Default address updated.',
            'data' => new AddressResource($model->fresh()),
        ]);
    }

    /**
     * Delete an address
     *
     * Soft delete: past orders snapshot the address, so removing it here never
     * changes order history. If the default is removed, the next most recent
     * address is promoted so the customer is never left without one.
     */
    public function destroy(Request $request, int $address): JsonResponse
    {
        $model = $this->findOwned($request, $address);

        DB::transaction(function () use ($model, $request) {
            $wasDefault = $model->is_default;
            $model->delete();

            if ($wasDefault) {
                $request->user()->addresses()->latest('id')->first()?->makeDefault();
            }
        });

        return response()->json(['message' => 'Address removed.']);
    }

    /**
     * Scoping the lookup to the owner means another user's id returns 404
     * rather than 403 — which would confirm the address exists.
     */
    private function findOwned(Request $request, int $id): Address
    {
        return $request->user()->addresses()->findOrFail($id);
    }
}
