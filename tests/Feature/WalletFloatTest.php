<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use App\Services\Payments\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

/**
 * What we are holding on customers' behalf.
 *
 * Wallet balances are not revenue — they are money handed over and not yet
 * spent, owed back as food, and held in a separate bank account for that
 * reason. The account only means anything if somebody can say what should be
 * in it, which is what this reports.
 *
 * Opening plus movement must equal closing. A report that does not balance is
 * worse than none: it gets reconciled against a bank statement, and the
 * difference gets explained away.
 */
class WalletFloatTest extends CheckoutTest
{
    private function fund(User $user, float $amount): Wallet
    {
        static $n = 0;
        $n++;

        $topup = WalletTopup::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'status' => WalletTopup::CREATED,
            'gateway_order_id' => 'order_float_'.$n,
        ]);

        return app(WalletService::class)->creditTopup($topup, 'pay_float_'.$n);
    }

    public function test_it_reports_what_is_owed_to_customers(): void
    {
        $this->fund($this->customer(), 500);
        $this->fund($this->customer(), 300);

        $this->artisan('nexmile:wallet-float')
            ->expectsOutputToContain('800.00')
            ->assertSuccessful();
    }

    public function test_an_empty_platform_owes_nothing(): void
    {
        $this->artisan('nexmile:wallet-float')
            ->expectsOutputToContain('0.00')
            ->assertSuccessful();
    }

    public function test_opening_plus_movement_equals_closing(): void
    {
        /*
         * The arithmetic the whole report rests on. Money put in before the
         * window is opening; money put in during it is movement; the two must
         * add up to what is held at the end.
         */
        $customer = $this->customer();

        Carbon::setTestNow(now()->subMonths(2));
        $this->fund($customer, 1000);

        Carbon::setTestNow();
        $this->fund($customer, 250);

        $output = $this->runFloat();

        // Opening 1,000 + 250 topped up = 1,250 held.
        $this->assertStringContainsString('1,000.00', $output);
        $this->assertStringContainsString('250.00', $output);
        $this->assertStringContainsString('1,250.00', $output);
    }

    public function test_spending_reduces_what_is_held(): void
    {
        // The money becomes revenue when it buys food. Until then it is not
        // ours, and after it is, it is no longer a liability.
        $shop = $this->restaurant();
        $customer = $this->customer();
        $this->fund($customer, 2000);

        $this->orderPaidFromWallet($shop, $customer);

        $held = (float) Wallet::sum('balance');

        $this->assertLessThan(2000, $held, 'the order did not reduce the float');

        $this->artisan('nexmile:wallet-float')
            ->expectsOutputToContain(number_format($held, 2))
            ->assertSuccessful();
    }

    public function test_it_fails_when_the_ledger_and_the_balances_disagree(): void
    {
        /*
         * The check that stops a wrong number reaching a bank reconciliation.
         * A balance written behind the ledger's back is exactly the drift this
         * has to refuse to report past.
         */
        $customer = $this->customer();
        $this->fund($customer, 400);

        Wallet::where('user_id', $customer->id)->first()->forceFill(['balance' => 950])->save();

        $this->artisan('nexmile:wallet-float')->assertFailed();
    }

    public function test_a_historic_period_is_not_checked_against_todays_balances(): void
    {
        /*
         * Asked about last month, the live balances have moved on since.
         * Comparing them would fail every time and prove nothing.
         */
        $customer = $this->customer();

        Carbon::setTestNow(now()->subMonths(3));
        $this->fund($customer, 600);

        Carbon::setTestNow();
        $this->fund($customer, 400);

        $this->artisan('nexmile:wallet-float', [
            '--from' => now()->subMonths(4)->toDateString(),
            '--to' => now()->subMonths(2)->toDateString(),
        ])->assertSuccessful();
    }

    public function test_a_backwards_period_is_refused(): void
    {
        $this->artisan('nexmile:wallet-float', [
            '--from' => now()->toDateString(),
            '--to' => now()->subMonth()->toDateString(),
        ])->assertFailed();
    }

    public function test_a_refund_puts_money_back_into_the_float(): void
    {
        // Cancelled food is money we are holding again, not money we kept.
        $shop = $this->restaurant();
        $customer = $this->customer();
        $this->fund($customer, 2000);

        $order = $this->orderPaidFromWallet($shop, $customer);

        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();

        $this->assertSame(
            1,
            WalletTransaction::where('type', WalletTransaction::REFUND)->count(),
        );

        $this->artisan('nexmile:wallet-float')
            ->expectsOutputToContain('2,000.00')
            ->assertSuccessful();
    }

    /**
     * Run it and read what it printed.
     *
     * Artisan::call() rather than $this->artisan(), because output() only
     * captures the former — the latter returns a PendingCommand whose
     * output never reaches the facade, and the assertions then run against
     * an empty string and pass for the wrong reason.
     */
    private function runFloat(): string
    {
        $this->assertSame(0, Artisan::call('nexmile:wallet-float'));

        return Artisan::output();
    }

    private function orderPaidFromWallet($shop, User $customer)
    {
        Sanctum::actingAs($customer);

        $address = $customer->addresses()->create([
            'label' => 'home', 'contact_name' => 'Meena', 'contact_phone' => '9876543210',
            'line1' => '4 Gandhi Nagar', 'city' => 'Madurai', 'pincode' => '625020',
            'latitude' => 9.9200, 'longitude' => 78.1195,
        ]);

        $dish = $this->dish($shop);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id, 'quantity' => 1,
        ])->assertCreated();

        $placed = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery',
            'payment_method' => 'wallet',
            'address_id' => $address->id,
            'use_wallet' => true,
        ])->assertCreated()->json('data');

        return Order::findOrFail($placed['id']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
