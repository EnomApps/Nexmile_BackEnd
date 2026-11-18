<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Models\OrderItem;
use Illuminate\Console\Command;

/**
 * Work out which dishes each restaurant is known for.
 *
 * Runs overnight. The answer changes once a day at most, and computing it per
 * request would mean a GROUP BY over order_items every time somebody opens a
 * menu.
 */
class MarkBestsellersCommand extends Command
{
    protected $signature = 'nexmile:mark-bestsellers
                            {--merchant= : Only this restaurant}
                            {--dry-run : Show what would change and write nothing}';

    protected $description = 'Flag each restaurant\'s best-selling dishes from recent delivered orders';

    public function handle(): int
    {
        $window = (int) config('menu.bestsellers.window_days');
        $top = (int) config('menu.bestsellers.per_merchant');
        $minimum = (int) config('menu.bestsellers.min_orders');

        if ($window < 1 || $top < 1) {
            $this->components->error('menu.bestsellers needs a window of at least one day and room for at least one dish.');

            return self::FAILURE;
        }

        $since = now()->subDays($window);
        $dry = (bool) $this->option('dry-run');

        $merchants = Merchant::query()
            ->when($this->option('merchant'), fn ($q, $id) => $q->whereKey($id))
            ->pluck('id');

        if ($merchants->isEmpty()) {
            $this->components->warn('No restaurants to look at.');

            return self::SUCCESS;
        }

        $changed = 0;
        $badged = 0;

        foreach ($merchants as $merchantId) {
            /*
             * Quantity, not order count. Two plates of idli on one bill is
             * two plates sold, and a dish that gets ordered in pairs is
             * exactly the kind of thing the badge should be finding.
             *
             * Delivered only. An order the kitchen rejected, or one abandoned
             * at payment, says nothing about whether the food is good — and
             * counting rejections would hand the badge to whatever the
             * restaurant keeps running out of.
             */
            $sold = OrderItem::query()
                ->selectRaw('menu_item_id, SUM(quantity) as sold')
                ->whereNotNull('menu_item_id')
                ->whereHas('order', function ($q) use ($merchantId, $since) {
                    $q->where('merchant_id', $merchantId)
                        ->where('status', OrderStatus::Delivered->value)
                        ->where('created_at', '>=', $since);
                })
                ->groupBy('menu_item_id')
                ->orderByDesc('sold')
                ->get();

            /*
             * The threshold applies per dish, not to the restaurant's total.
             * A new place that has genuinely sold forty biryanis has a
             * bestseller; one that has sold four of everything does not, and
             * saying so is more useful than badging its whole menu.
             */
            $winners = $sold
                ->filter(fn ($row) => (int) $row->sold >= $minimum)
                ->take($top)
                ->pluck('menu_item_id')
                ->all();

            $current = MenuItem::where('merchant_id', $merchantId)
                ->where('is_bestseller', true)
                ->pluck('id')
                ->all();

            sort($winners);
            sort($current);

            if ($winners === $current) {
                continue;
            }

            $changed++;
            $badged += count($winners);

            $this->line(sprintf(
                '  restaurant %-5s %d dish(es) → %s',
                $merchantId,
                count($winners),
                $winners === [] ? 'none' : implode(', ', $winners),
            ));

            if ($dry) {
                continue;
            }

            /*
             * Clear then set, so a dish that has stopped selling loses the
             * badge. Without the clear the flag only ever accumulates and a
             * menu ends up entirely bestsellers.
             */
            MenuItem::where('merchant_id', $merchantId)
                ->where('is_bestseller', true)
                ->update(['is_bestseller' => false]);

            if ($winners !== []) {
                MenuItem::where('merchant_id', $merchantId)
                    ->whereIn('id', $winners)
                    ->update(['is_bestseller' => true]);
            }
        }

        $this->components->info(sprintf(
            '%s%d restaurant(s) changed, %d dish(es) badged, from %d day(s) of delivered orders.',
            $dry ? 'Dry run: ' : '',
            $changed,
            $badged,
            $window,
        ));

        return self::SUCCESS;
    }
}
