<?php

namespace App\Services\Admin;

use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\RiderStatus;
use App\Http\Controllers\Web\Admin\AdminOrderController;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Rider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The numbers needed to answer "how is today going" without opening a SQL
 * client.
 *
 * Everything is scoped to a day rather than a rolling window: a merchant, a
 * rider and an accountant all think in days, and "last 24 hours" straddles two
 * services in a way nobody can reconcile against a till.
 */
class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function forDay(?Carbon $day = null): array
    {
        $day ??= now();
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        $placed = Order::whereBetween('placed_at', [$from, $to]);

        /*
         * Revenue counts delivered orders only. An order in a kitchen right
         * now is not money, and counting it would make every afternoon look
         * better than the evening it becomes.
         */
        $delivered = Order::whereBetween('delivered_at', [$from, $to]);

        return [
            'day' => $from->toDateString(),
            'is_today' => $from->isToday(),

            'orders' => [
                'placed' => (clone $placed)->count(),
                'delivered' => (clone $delivered)->count(),
                'cancelled' => (clone $placed)->whereIn('status', [
                    OrderStatus::Cancelled->value,
                    OrderStatus::Rejected->value,
                ])->count(),
                'in_flight' => Order::active()->where('status', '!=', OrderStatus::PendingPayment->value)->count(),
            ],

            'money' => [
                'gross' => round((float) (clone $delivered)->sum('grand_total'), 2),
                'commission' => round((float) (clone $delivered)->sum('commission_amount'), 2),
                'merchant_payout' => round((float) (clone $delivered)->sum('merchant_payout'), 2),
                'delivery_fees' => round((float) (clone $delivered)->sum('delivery_fee'), 2),
                'average_order' => $this->average((clone $delivered)),
            ],

            'network' => [
                'merchants_verified' => Merchant::where('kyc_status', KycStatus::Verified->value)->count(),
                'merchants_open' => $this->openMerchants(),
                'riders_verified' => Rider::where('kyc_status', KycStatus::Verified->value)->count(),
                'riders_on_duty' => Rider::whereIn('duty_status', [
                    RiderStatus::Available->value,
                    RiderStatus::OnOrder->value,
                ])->count(),
            ],

            /*
             * Two things that mean somebody has to do something now, rather
             * than describing what already happened.
             */
            'attention' => [
                'kyc_waiting' => Merchant::where('kyc_status', KycStatus::Submitted->value)->count()
                    + Rider::where('kyc_status', KycStatus::Submitted->value)->count(),
                'orders_stale' => Order::where('status', OrderStatus::ReadyForPickup->value)
                    ->whereNull('rider_id')
                    ->where('ready_at', '<=', now()->subMinutes(AdminOrderController::STALE_MINUTES))
                    ->count(),
            ],
        ];
    }

    /**
     * Merchants that could take an order this minute — switched on, verified,
     * licence current, and inside their opening hours.
     *
     * Opening hours cannot be asked in SQL without duplicating the midnight
     * logic, so the candidate set is narrowed first and the rest is answered
     * by the model that already knows how.
     */
    protected function openMerchants(): int
    {
        return Merchant::query()
            ->where('kyc_status', KycStatus::Verified->value)
            ->where('is_accepting_orders', true)
            ->with('operatingHours')
            ->get()
            ->filter(fn (Merchant $merchant) => $merchant->isOpenNow())
            ->count();
    }

    /**
     * @param  Builder<Order>  $delivered
     */
    protected function average($delivered): float
    {
        $count = (clone $delivered)->count();

        return $count === 0 ? 0.0 : round((float) $delivered->sum('grand_total') / $count, 2);
    }
}
