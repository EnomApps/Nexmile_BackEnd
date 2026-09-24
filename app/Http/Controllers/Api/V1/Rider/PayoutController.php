<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Http\Controllers\Controller;
use App\Models\Rider;
use App\Models\RiderPayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a rider was paid, week by week.
 *
 * Earnings have been visible per order for a while; this is the part that says
 * what actually reached the bank and what was taken out on the way.
 */
class PayoutController extends Controller
{
    /**
     * Weekly payouts
     *
     * Newest first. Each row is one week, with the parts that make it up —
     * "why is this less than I counted" has one answer and it belongs on the
     * same screen as the number that prompts the question.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1, 50'],
        ]);

        $payouts = RiderPayout::query()
            ->where('rider_id', $this->rider($request)->id)
            // Carried weeks are an accounting step, not something that
            // happened to the rider — the money appears in the week it is
            // actually paid, which is the week they will look for it in.
            ->where('status', '!=', RiderPayout::CARRIED)
            ->latest('period_start')
            ->paginate($data['per_page'] ?? 12);

        return response()->json([
            'data' => $payouts->getCollection()->map(fn (RiderPayout $p) => $this->present($p))->all(),
            'meta' => [
                'current_page' => $payouts->currentPage(),
                'last_page' => $payouts->lastPage(),
                'total' => $payouts->total(),
            ],
        ]);
    }

    /**
     * One week
     *
     * Including the deductions breakdown behind the total.
     */
    public function show(Request $request, int $payout): JsonResponse
    {
        $model = RiderPayout::where('rider_id', $this->rider($request)->id)->findOrFail($payout);

        return response()->json(['data' => $this->present($model)]);
    }

    /** @return array<string, mixed> */
    private function present(RiderPayout $payout): array
    {
        return [
            'id' => $payout->id,
            'period_start' => $payout->period_start->toDateString(),
            'period_end' => $payout->period_end->toDateString(),

            'delivery_earnings' => (float) $payout->delivery_earnings,
            'referral_earnings' => (float) $payout->referral_earnings,
            /*
             * Weeks too small to transfer, folded into this one. Shown rather
             * than silently absorbed: a rider who remembers earning ₹40 last
             * week and saw no payment is owed an explanation of where it went.
             */
            'carried_in' => (float) $payout->carried_in,
            'gross' => (float) $payout->gross,

            'deductions_total' => round((float) $payout->tds, 2),
            'deductions' => $payout->deductions(),

            // What actually reached the bank, and the only figure a rider will
            // check against their statement.
            'net' => (float) $payout->net,

            'status' => $payout->status,
            'paid_at' => $payout->paid_at,
            'reference' => $payout->reference,
        ];
    }

    private function rider(Request $request): Rider
    {
        $rider = $request->user()->rider;

        abort_if($rider === null, 404);

        return $rider;
    }
}
