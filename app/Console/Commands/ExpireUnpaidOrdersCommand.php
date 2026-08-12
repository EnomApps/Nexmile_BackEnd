<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\LiveState\OrderStateService;
use App\Services\Menu\SurplusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Close out orders nobody ever paid for.
 *
 * A customer who shuts the app on the bank's page leaves an order in
 * `pending_payment` for ever. It never reaches a kitchen, so it is invisible —
 * but if it took the last Food Rescue portion, nobody else can buy that
 * portion either, and the merchant sees stock they cannot sell.
 */
class ExpireUnpaidOrdersCommand extends Command
{
    protected $signature = 'nexmile:expire-unpaid {--minutes= : Override the configured window}';

    protected $description = 'Cancel unpaid orders and release the stock they were holding';

    public function handle(SurplusService $surplus, OrderStateService $liveState): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('payments.abandon_after_minutes'));

        $orders = Order::query()
            ->where('status', OrderStatus::PendingPayment->value)
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->with('items.menuItem')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('Nothing to expire.');

            return self::SUCCESS;
        }

        foreach ($orders as $order) {
            DB::transaction(function () use ($order, $surplus) {
                $order->update([
                    'status' => OrderStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by' => 'system',
                    'cancellation_reason' => 'Payment was not completed.',
                    'cancellation_fee' => 0,
                ]);

                $order->statusHistory()->create([
                    'from_status' => OrderStatus::PendingPayment,
                    'to_status' => OrderStatus::Cancelled,
                    'note' => 'Expired after '.config('payments.abandon_after_minutes').' minutes unpaid.',
                    'created_at' => now(),
                ]);

                $order->payments()
                    ->where('status', PaymentStatus::Pending)
                    ->update(['status' => PaymentStatus::Failed, 'failure_reason' => 'Abandoned.']);

                /*
                 * Give the portions back. This is the one cancellation where
                 * that is unambiguously right — the kitchen never saw the
                 * order, so nothing was cooked or thrown away.
                 */
                foreach ($order->items as $item) {
                    if ($item->menuItem?->is_surplus_deal) {
                        $surplus->release($item->menuItem, (int) $item->quantity);
                    }
                }
            });

            rescue(fn () => $liveState->setStatus($order->id, OrderStatus::Cancelled), report: true);

            $this->line("  cancelled {$order->order_number}");
        }

        $this->info("Expired {$orders->count()} unpaid order(s).");

        return self::SUCCESS;
    }
}
