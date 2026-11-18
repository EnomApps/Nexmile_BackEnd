<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use App\Models\Order;
use App\Services\Media\ImageService;
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
     * Add or replace the door photo
     *
     * One file in `photo`. A second upload replaces the first.
     *
     * The problem this solves is a Tier-2 address: a plot number painted on a
     * wall that is not the gate, three doors off one staircase and no flat
     * numbers anywhere. "Green door, second floor, past the water tank" is
     * something nobody types and a photo says at a glance.
     *
     * Only the assigned rider ever sees it, and only through a link that dies
     * in a few hours — see config/media.php.
     */
    public function photo(Request $request, int $address, ImageService $images): JsonResponse
    {
        $request->validate(
            ['photo' => ImageService::rules()],
            ImageService::messages('photo'),
        );

        $model = $this->findOwned($request, $address);
        $previous = $model->photo_path;

        /*
         * store() then forceFill, rather than attach(), because attach()
         * deletes the replaced object immediately. An order placed an hour ago
         * snapshotted that path and the rider is still reading it, so the old
         * photo only goes once no order needs it.
         */
        $path = $images->store('door-photos/'.$model->user_id, $request->file('photo'));

        $model->forceFill(['photo_path' => $path])->save();

        $this->forgetPhoto($previous);

        return response()->json([
            'message' => 'Door photo saved.',
            'data' => new AddressResource($model->fresh()),
        ]);
    }

    /**
     * Remove the door photo
     *
     * The address itself is untouched.
     */
    public function deletePhoto(Request $request, int $address): JsonResponse
    {
        $model = $this->findOwned($request, $address);
        $previous = $model->photo_path;

        $model->forceFill(['photo_path' => null])->save();

        $this->forgetPhoto($previous);

        return response()->json([
            'message' => 'Door photo removed.',
            'data' => new AddressResource($model->fresh()),
        ]);
    }

    /**
     * Delete a replaced photo, unless an order is still pointing at it.
     *
     * Orders snapshot the path, so a blind delete here would empty the door
     * photo on every past delivery and, worse, on one in progress. Keeping the
     * object while any order references it costs a few kilobytes; deleting it
     * costs the rider standing at the gate right now.
     *
     * Nothing prunes the kept objects yet. They are reachable only through a
     * signed link off an order nobody can see but that order's rider, and the
     * order retention policy is the right place to decide when an address a
     * customer has already deleted stops being ours to hold.
     */
    private function forgetPhoto(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        if (Order::where('delivery_photo_path', $path)->exists()) {
            return;
        }

        app(ImageService::class)->purge($path);
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
