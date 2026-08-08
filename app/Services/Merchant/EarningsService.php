<?php

namespace App\Services\Merchant;

use App\Enums\OrderStatus;
use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a merchant has earned and what they are owed (EP11, EP13).
 *
 * The first question any restaurant owner asks, and until now the portal could
 * not answer it — they could see the total on one order and nothing else.
 */
class EarningsService
{
    /**
     * Delivered orders only.
     *
     * A merchant is paid for food that reached a customer. Counting an order
     * still in the kitchen would show them money that can still evaporate if
     * they cancel it, and reconciling that against a bank transfer later is
     * how trust in the number gets lost.
     *
     * @return array<string, mixed>
     */
    public function summary(Merchant $merchant, Carbon $from, Carbon $to): array
    {
        $delivered = $this->delivered($merchant, $from, $to);

        $orders = (clone $delivered)->count();
        $gross = round((float) (clone $delivered)->sum('items_total')
            + (float) (clone $delivered)->sum('packaging_fee'), 2);
        $commission = round((float) (clone $delivered)->sum('commission_amount'), 2);
        $payout = round((float) (clone $delivered)->sum('merchant_payout'), 2);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'orders' => $orders,
            'gross' => $gross,
            'commission' => $commission,
            'payout' => $payout,
            'average_order' => $orders === 0 ? 0.0 : round($gross / $orders, 2),
            /*
             * Shown separately because it is the line a merchant will query.
             * "Why is my payout less than my sales" has one answer and it
             * should be on the same screen as the number that prompts it.
             */
            'commission_rate' => (float) $merchant->commission_rate,

            'cancelled' => Order::where('merchant_id', $merchant->id)
                ->whereBetween('placed_at', [$from, $to])
                ->whereIn('status', [OrderStatus::Cancelled->value, OrderStatus::Rejected->value])
                ->count(),
        ];
    }

    /**
     * Day by day, so a merchant can see which days are worth opening for.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function daily(Merchant $merchant, Carbon $from, Carbon $to): Collection
    {
        return $this->delivered($merchant, $from, $to)
            ->get(['delivered_at', 'items_total', 'packaging_fee', 'commission_amount', 'merchant_payout'])
            // Grouped in PHP: date functions differ between MySQL and SQLite,
            // and the row count over a reporting period is small.
            ->groupBy(fn (Order $order) => $order->delivered_at->toDateString())
            ->map(fn (Collection $rows, string $date) => [
                'date' => $date,
                'orders' => $rows->count(),
                'gross' => round($rows->sum(fn (Order $o) => (float) $o->items_total + (float) $o->packaging_fee), 2),
                'commission' => round($rows->sum(fn (Order $o) => (float) $o->commission_amount), 2),
                'payout' => round($rows->sum(fn (Order $o) => (float) $o->merchant_payout), 2),
            ])
            ->sortKeysDesc()
            ->values();
    }

    /**
     * @return Builder<Order>
     */
    protected function delivered(Merchant $merchant, Carbon $from, Carbon $to)
    {
        return Order::query()
            ->where('merchant_id', $merchant->id)
            ->where('status', OrderStatus::Delivered->value)
            ->whereBetween('delivered_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }
}
