<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Push\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Where to reach this person when their app is closed.
 *
 * Shared by the customer and rider apps — `app` says which install this token
 * belongs to. One person may hold both: a rider ordering their dinner is a
 * customer, and their two apps must not receive each other's alerts.
 */
class DeviceTokenController extends Controller
{
    public function __construct(protected PushService $push) {}

    /**
     * Register this device
     *
     * Call after sign-in, and again whenever FCM rotates the token — which it
     * does on reinstall, on restore to a new phone, and occasionally for no
     * reason at all. Registering the same token twice is safe.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
            'app' => ['required', Rule::in([
                PushService::CUSTOMER, PushService::RIDER, PushService::MERCHANT,
            ])],
        ]);

        $this->push->register($request->user(), $data['token'], $data['platform'], $data['app']);

        return response()->json(['message' => 'Device registered.']);
    }

    /**
     * Forget this device
     *
     * Call on sign-out. Without it the phone keeps buzzing for a shift
     * somebody else is working, and there is no way for that person to stop
     * it.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $this->push->forget($data['token']);

        return response()->json(['message' => 'Device forgotten.']);
    }
}
