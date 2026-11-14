<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Http\Controllers\Controller;
use App\Http\Resources\RiderOfferResource;
use App\Http\Resources\RiderOrderResource;
use App\Models\Rider;
use App\Models\RiderOffer;
use App\Services\Orders\OfferDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The ringing order card (EP7).
 *
 * A push message starts the ring; these endpoints are the truth about it.
 * The app calls `current` when it starts cold or comes back from the
 * background, because a data message can be dropped by the system, arrive
 * twice, or arrive on a phone that was switched off — and a rider staring at
 * a card for an order somebody else collected ten minutes ago is worse than
 * a rider who saw no card at all.
 */
class OfferController extends Controller
{
    public function __construct(protected OfferDispatchService $offers) {}

    /**
     * The offer this rider should have on screen
     *
     * `data` is null when there is nothing ringing, which is the normal
     * answer. Not a 404: "you have no offer" is a successful question.
     */
    public function current(Request $request): JsonResponse
    {
        $offer = $this->offers->current($this->rider($request));

        return response()->json([
            'data' => $offer === null ? null : new RiderOfferResource($offer),
        ]);
    }

    /**
     * Accept an offer
     *
     * Four answers the app has to tell apart, which is why they are four
     * status codes rather than one:
     *
     * - **200** it is yours. The response carries the order, drop address and
     *   customer phone included — this is the moment the rider is trusted
     *   with them.
     * - **409** somebody else was faster, or the order was cancelled while
     *   the phone was ringing. Close the card and say so.
     * - **410** the window closed. Close the card silently: the countdown
     *   already ran out on screen, and a rider does not need telling twice.
     * - **422** the rider cannot take it — off duty, documents lapsed, or
     *   already carrying one. The message is the thing to show.
     */
    public function accept(Request $request, RiderOffer $offer): JsonResponse
    {
        $rider = $this->rider($request);

        $order = $this->offers->accept($rider, $offer->load('order.merchant', 'order.items'));

        return response()->json([
            'message' => 'Order accepted. Head to the restaurant.',
            // Loaded to match GET /rider/orders/{order} exactly, options
            // included: the app puts this response straight onto the delivery
            // screen rather than fetching the order again.
            'data' => new RiderOrderResource($order->load('merchant', 'items.options', 'customer')),
        ]);
    }

    /**
     * Decline an offer
     *
     * Always 200, even for an offer that has already ended. The card is gone
     * from the rider's screen either way, and refusing a decline would leave
     * the app with an error to show about something that does not matter.
     *
     * Declining does not count against the rider. Only letting it ring out
     * does — see config/dispatch.php.
     */
    public function reject(Request $request, RiderOffer $offer): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $this->offers->reject($this->rider($request), $offer, $data['reason'] ?? null);

        return response()->json(['message' => 'Offer declined.']);
    }

    protected function rider(Request $request): Rider
    {
        $rider = $request->user()->rider;

        abort_if($rider === null, 404, 'No rider profile found for this account.');

        return $rider;
    }
}
