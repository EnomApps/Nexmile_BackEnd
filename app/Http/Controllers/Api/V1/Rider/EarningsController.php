<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Rider;
use App\Services\Riders\RiderPayoutService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What a rider has earned (EP13).
 *
 * The first question every delivery partner asks at the end of a shift, and
 * the one the product could not answer at all until now. A rider who cannot
 * see what they made today has no reason to believe what they are told they
 * made this week.
 */
class EarningsController extends Controller
{
    /**
     * Earnings summary
     *
     * Today, this week, and the orders behind them. Only delivered orders
     * count — food in a bag is not money yet.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $rider = $this->rider($request);

        // India has no DST, so a local day is a fixed window and this needs no
        // timezone gymnastics — but it must be the rider's day, not UTC's, or
        // a late shift lands on tomorrow's total.
        $today = Carbon::now()->startOfDay();
        $weekStart = Carbon::now()->startOfWeek();

        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $weekStart;
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : Carbon::now()->endOfDay();

        $orders = $this->delivered($rider)
            ->whereBetween('delivered_at', [$from, $to])
            ->latest('delivered_at')
            ->paginate($data['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'data' => $orders->getCollection()->map(fn (Order $order) => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'delivered_at' => $order->delivered_at,
                'restaurant' => $order->merchant?->business_name,
                'payout' => (float) $order->rider_payout,
                /*
                 * The parts, not just the total. A rider disputing a figure
                 * should be shown the arithmetic rather than told the answer
                 * again — and it is what makes the rate card believable.
                 */
                'breakdown' => $order->rider_payout_breakdown,
            ])->all(),

            'meta' => [
                'today' => $this->sum($rider, $today, Carbon::now()),
                'this_week' => $this->sum($rider, $weekStart, Carbon::now()),
                'range' => $this->sum($rider, $from, $to),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * What one delivery would pay, before accepting it.
     *
     * Quoted with the same code that settles it, so a rider is never shown a
     * number that later turns out to be a different number.
     */
    public function quote(Request $request, int $order): JsonResponse
    {
        $rider = $this->rider($request);

        $model = Order::query()
            ->with('merchant:id,latitude,longitude,business_name')
            ->findOrFail($order);

        return response()->json([
            'data' => app(RiderPayoutService::class)->calculate($model),
        ]);
    }

    /** @return array<string, mixed> */
    private function sum(Rider $rider, Carbon $from, Carbon $to): array
    {
        $row = $this->delivered($rider)
            ->whereBetween('delivered_at', [$from, $to])
            ->selectRaw('COUNT(*) as deliveries, COALESCE(SUM(rider_payout), 0) as earned')
            ->first();

        return [
            'deliveries' => (int) ($row->deliveries ?? 0),
            'earned' => round((float) ($row->earned ?? 0), 2),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    /** @return Builder<Order> */
    private function delivered(Rider $rider)
    {
        // Delivered only. An order in a bag is work done but not money owed,
        // and showing it as earned makes every total a promise we then have to
        // take back if it is cancelled.
        return Order::query()
            ->with('merchant:id,business_name')
            ->where('rider_id', $rider->id)
            ->where('status', OrderStatus::Delivered->value);
    }

    private function rider(Request $request): Rider
    {
        $rider = $request->user()->rider;

        abort_if($rider === null, 404, 'No rider profile found for this account.');

        return $rider;
    }
}
