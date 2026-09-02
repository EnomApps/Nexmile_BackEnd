<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Riders\ConductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reporting the rider who brought an order.
 *
 * A customer had no way to say a rider was rude, asked for extra money, or
 * turned up with the food spilled. A star rating does not carry that — nobody
 * reads three stars as "he shouted at me".
 */
class RiderReportController extends Controller
{
    public function __construct(protected ConductService $conduct) {}

    /**
     * What can be reported
     *
     * Served from the server so the list can change without an app release,
     * and so the app never sends a category the backend does not know.
     */
    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => collect(config('conduct.report_categories'))
                ->map(fn (array $c, string $key) => ['value' => $key, 'label' => $c['label']])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Report a delivery
     *
     * Once the order is finished, once per order. Goes to a person to read —
     * nothing happens to the rider automatically.
     */
    public function store(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:40'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $model = $request->user()->orders()->findOrFail($order);

        $this->conduct->report($model, $request->user(), $data['category'], $data['description'] ?? null);

        return response()->json([
            /*
             * Deliberately does not promise an outcome. A customer told
             * "action will be taken" expects to hear that it was, and most
             * reports end in a conversation nobody outside sees.
             */
            'message' => 'Thank you. Our team will look into this.',
        ], 201);
    }
}
