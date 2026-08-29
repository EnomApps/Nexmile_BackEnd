<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use App\Services\Reviews\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ratings left by a customer against a delivered order (EP12).
 */
class ReviewController extends Controller
{
    public function __construct(protected ReviewService $reviews) {}

    /**
     * The review left on an order
     *
     * `data` is null when the customer has not reviewed it yet, so the app can
     * tell "not rated" from "rated 0".
     */
    public function show(Request $request, int $order): JsonResponse
    {
        $model = $this->order($request, $order);

        return response()->json([
            'data' => $model->review === null ? null : $this->payload($model->review),
        ]);
    }

    /**
     * Leave a review
     *
     * Once per order, and only after it was delivered. `rider_rating` is
     * ignored on an order nobody delivered.
     */
    public function store(Request $request, int $order): JsonResponse
    {
        $model = $this->order($request, $order);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'rider_rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],

            /*
             * Optional per-dish scores, keyed by menu_item_id:
             *   {"dishes": {"41": 5, "44": 3}}
             *
             * Anything not on the order is ignored rather than refused — a
             * stale menu id from a slow screen should not lose the whole
             * review, and rating dishes you did not buy is the cheapest way
             * there is to bury a competitor.
             */
            'dishes' => ['sometimes', 'array', 'max:30'],
            'dishes.*' => ['integer', 'between:1,5'],
        ]);

        $review = $this->reviews->leave(
            $model,
            $request->user(),
            $data['rating'],
            $data['rider_rating'] ?? null,
            $data['comment'] ?? null,
            $data['dishes'] ?? [],
        );

        return response()->json([
            'message' => 'Thanks for the feedback.',
            'data' => $this->payload($review),
        ], 201);
    }

    private function order(Request $request, int $order): Order
    {
        // Scoped to the caller: an order id is not a licence to read someone
        // else's dinner.
        return $request->user()->orders()->with('review')->findOrFail($order);
    }

    /** @return array<string, mixed> */
    private function payload(Review $review): array
    {
        return [
            'id' => $review->id,
            'order_id' => $review->order_id,
            'rating' => $review->rating,
            'rider_rating' => $review->rider_rating,
            'comment' => $review->comment,
            // Only what was kept: a dish that was not on the order is silently
            // dropped, and the app should show what actually saved.
            'dishes' => $review->items()->pluck('rating', 'menu_item_id'),
            'created_at' => $review->created_at,
        ];
    }
}
