<?php

namespace App\Console\Commands;

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Places a test order against a merchant so the portal order screens can be
 * exercised before customer checkout exists (EP5).
 *
 * Blocked outside local and staging: this writes an order nobody paid for, and
 * on production it would land in a real merchant's kitchen queue and pollute
 * their payout reporting.
 */
class MakeDemoOrderCommand extends Command
{
    protected $signature = 'nexmile:demo-order
                            {--merchant= : Merchant id, or the account email}
                            {--status=placed : Status to create the order in}';

    protected $description = 'Create a demo order for testing the merchant order screens';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run on production — this creates an unpaid order in a live kitchen queue.');

            return self::FAILURE;
        }

        $merchant = $this->resolveMerchant();

        if ($merchant === null) {
            $this->error('No merchant found. Register one at /merchants/register first.');

            return self::FAILURE;
        }

        $status = OrderStatus::tryFrom($this->option('status'));

        if ($status === null) {
            $this->error('Unknown status. One of: '.implode(', ', OrderStatus::values()));

            return self::FAILURE;
        }

        $order = $this->createOrder($merchant, $status);

        $this->info("Created order #{$order->order_number} for {$merchant->business_name}.");
        $this->line('  Status:  '.$status->value);
        $this->line('  Total:   ₹'.number_format((float) $order->grand_total, 2));
        $this->line('  Portal:  '.route('merchants.orders.show', $order->id));

        return self::SUCCESS;
    }

    protected function resolveMerchant(): ?Merchant
    {
        $option = $this->option('merchant');

        if ($option === null) {
            return Merchant::oldest('id')->first();
        }

        if (is_numeric($option)) {
            return Merchant::find((int) $option);
        }

        return Merchant::whereRelation('user', 'email', $option)->first();
    }

    protected function createOrder(Merchant $merchant, OrderStatus $status): Order
    {
        $customer = $this->demoCustomer();

        /*
         * Priced off the merchant's real menu when they have one, so the
         * totals on screen match dishes they recognise. Falls back to a
         * placeholder line for a merchant who has not built a menu yet.
         */
        $menuItems = $merchant->menuItems()->available()->inRandomOrder()->limit(2)->get();

        $lines = $menuItems->isNotEmpty()
            ? $menuItems->map(fn ($item) => [
                'menu_item_id' => $item->id,
                'name' => $item->name,
                'unit_price' => $item->price,
                'quantity' => random_int(1, 2),
                'gst_rate' => $item->gst_rate,
                'is_veg' => $item->is_veg,
            ])
            : collect([[
                'menu_item_id' => null,
                'name' => 'Demo dish',
                'unit_price' => 180.00,
                'quantity' => 2,
                'gst_rate' => 5.00,
                'is_veg' => true,
            ]]);

        $itemsTotal = $lines->sum(fn ($line) => (float) $line['unit_price'] * $line['quantity']);
        $tax = round($lines->sum(fn ($line) => (float) $line['unit_price'] * $line['quantity'] * ((float) $line['gst_rate'] / 100)), 2);
        $delivery = 25.00;
        $packaging = (float) ($merchant->packaging_fee ?? 0);
        $grand = round($itemsTotal + $tax + $delivery + $packaging, 2);
        $commission = round($itemsTotal * ((float) ($merchant->commission_rate ?? 0) / 100), 2);

        $order = $merchant->orders()->create([
            'order_number' => 'NXD'.strtoupper(Str::random(7)),
            'user_id' => $customer->id,
            'status' => $status,
            'fulfilment_type' => FulfilmentType::Delivery,

            'delivery_contact_name' => $customer->name,
            'delivery_contact_phone' => $customer->phone,
            'delivery_line1' => '14 Bypass Road',
            'delivery_landmark' => 'Near the bus stand',
            'delivery_city' => $merchant->city ?? 'Madurai',
            'delivery_pincode' => $merchant->pincode ?? '625001',
            'distance_metres' => random_int(300, 950),

            'items_total' => $itemsTotal,
            'packaging_fee' => $packaging,
            'delivery_fee' => $delivery,
            'tax_total' => $tax,
            'grand_total' => $grand,
            'commission_amount' => $commission,
            'merchant_payout' => round($itemsTotal + $packaging - $commission, 2),

            'customer_note' => 'Please make it less spicy.',
            'pickup_code' => (string) random_int(1000, 9999),
            'placed_at' => now(),
        ]);

        foreach ($lines as $line) {
            $lineTotal = (float) $line['unit_price'] * $line['quantity'];

            $order->items()->create([
                ...$line,
                'line_total' => $lineTotal,
                'tax_amount' => round($lineTotal * ((float) $line['gst_rate'] / 100), 2),
            ]);
        }

        return $order->fresh();
    }

    protected function demoCustomer(): User
    {
        return User::firstOrCreate(
            ['email' => 'demo.customer@nexmile.in'],
            [
                'name' => 'Demo Customer',
                'phone' => '9000000001',
                'password' => Str::random(32),
                'role' => UserRole::Customer,
                'status' => UserStatus::Active,
            ],
        );
    }
}
