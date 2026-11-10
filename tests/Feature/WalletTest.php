<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\WalletService;
use Laravel\Sanctum\Sanctum;

/**
 * The customer wallet.
 *
 * This is the first thing in the product that holds a customer's money before
 * they have bought anything, which changes what a bug costs. A double credit
 * is money given away; a double debit is money taken twice from somebody who
 * will notice; a balance that drifts from its ledger is an argument nobody can
 * settle. Each of those has a test here because none of them announce
 * themselves in a log.
 *
 * Extends CheckoutTest for a real cart and a real order — a wallet spend is
 * only honest when it is tested against an order that actually got placed.
 */
class WalletTest extends CheckoutTest
{
    /** A few metres from the restaurant, as CheckoutTest places it. */
    private function deliveryAddress(User $user): Address
    {
        return $user->addresses()->create([
            'label' => 'home',
            'contact_name' => 'Meena',
            'contact_phone' => '9876543210',
            'line1' => '4 Gandhi Nagar',
            'city' => 'Madurai',
            'pincode' => '625020',
            'latitude' => 9.9200,
            'longitude' => 78.1195,
        ]);
    }

    private function wallets(): WalletService
    {
        return app(WalletService::class);
    }

    /** Put money in without going through a gateway. */
    private function fund(User $user, float $amount): Wallet
    {
        $topup = WalletTopup::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'status' => WalletTopup::CREATED,
            'gateway_order_id' => 'order_'.$user->id.'_'.$amount,
        ]);

        return $this->wallets()->creditTopup($topup, 'pay_seed_'.$topup->id);
    }

    public function test_a_new_customer_has_an_empty_wallet(): void
    {
        $customer = $this->customer();

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.balance', fn ($balance) => (float) $balance === 0.0)
            ->assertJsonPath('data.transactions', []);
    }

    public function test_a_topup_is_credited_once_however_many_times_it_is_confirmed(): void
    {
        /*
         * The failure this exists for. Razorpay redelivers webhooks, the app
         * retries on a timeout that actually succeeded, and a customer taps
         * twice. Every one of those is free money without the lock and the
         * idempotency key behind it.
         */
        $customer = $this->customer();
        $topup = WalletTopup::create([
            'user_id' => $customer->id,
            'amount' => 500,
            'status' => WalletTopup::CREATED,
            'gateway_order_id' => 'order_test_1',
        ]);

        foreach (range(1, 4) as $ignored) {
            $this->wallets()->creditTopup($topup, 'pay_test_1');
        }

        $this->assertSame('500.00', Wallet::where('user_id', $customer->id)->first()->balance);
        $this->assertSame(1, WalletTransaction::where('type', WalletTransaction::TOPUP)->count());
    }

    public function test_the_webhook_credits_when_the_app_never_came_back(): void
    {
        /*
         * A customer pays, their train enters a tunnel, and verify never
         * arrives. The webhook is the only thing that turns their money into a
         * balance — if it did not, they would have paid for nothing.
         */
        $customer = $this->customer();
        $topup = WalletTopup::create([
            'user_id' => $customer->id,
            'amount' => 250,
            'status' => WalletTopup::CREATED,
            'gateway_order_id' => 'order_hook_1',
        ]);

        $body = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_hook_1',
                'order_id' => 'order_hook_1',
                'method' => 'upi',
            ]]],
        ]);

        $this->call(
            'POST',
            '/api/webhooks/razorpay',
            [], [], [],
            ['HTTP_X-Razorpay-Signature' => app(FakeGateway::class)->sign($body), 'CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertOk();

        $this->assertSame('250.00', Wallet::where('user_id', $customer->id)->first()->balance);
        $this->assertSame(WalletTopup::PAID, $topup->fresh()->status);
    }

    public function test_a_webhook_and_a_verify_for_the_same_topup_credit_once(): void
    {
        // Both arrive, in either order. Whichever is second must find it done.
        $customer = $this->customer();
        $topup = WalletTopup::create([
            'user_id' => $customer->id,
            'amount' => 300,
            'status' => WalletTopup::CREATED,
            'gateway_order_id' => 'order_both_1',
        ]);

        $this->wallets()->creditTopup($topup, 'pay_both_1');

        $body = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_both_1', 'order_id' => 'order_both_1',
            ]]],
        ]);

        $this->call(
            'POST', '/api/webhooks/razorpay', [], [], [],
            ['HTTP_X-Razorpay-Signature' => app(FakeGateway::class)->sign($body), 'CONTENT_TYPE' => 'application/json'],
            $body,
        )->assertOk();

        $this->assertSame('300.00', Wallet::where('user_id', $customer->id)->first()->balance);
        $this->assertSame(1, WalletTransaction::count());
    }

    public function test_a_forged_signature_credits_nothing(): void
    {
        $customer = $this->customer();
        $topup = WalletTopup::create([
            'user_id' => $customer->id,
            'amount' => 999,
            'status' => WalletTopup::CREATED,
            'gateway_order_id' => 'order_forged',
        ]);

        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/wallet/topups/{$topup->id}/verify", [
            'razorpay_payment_id' => 'pay_forged',
            'razorpay_order_id' => 'order_forged',
            'razorpay_signature' => 'not-a-real-signature',
        ])->assertStatus(422);

        $this->assertSame(0, WalletTransaction::count());
        $this->assertNull($topup->fresh()->credited_at);
    }

    public function test_a_signature_from_a_different_payment_is_refused(): void
    {
        /*
         * A genuine signature, correctly computed — over somebody else's ₹10
         * payment. Without checking the order id matches the row, a customer
         * could present it against a ₹5,000 top-up and have it honoured.
         */
        $customer = $this->customer();
        $topup = WalletTopup::create([
            'user_id' => $customer->id,
            'amount' => 5000,
            'status' => WalletTopup::CREATED,
            'gateway_order_id' => 'order_the_real_one',
        ]);

        $gateway = app(FakeGateway::class);

        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/wallet/topups/{$topup->id}/verify", [
            'razorpay_payment_id' => 'pay_cheap',
            'razorpay_order_id' => 'order_somewhere_else',
            'razorpay_signature' => $gateway->sign('order_somewhere_else|pay_cheap'),
        ])->assertStatus(422);

        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_another_customers_topup_cannot_be_verified(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();

        $topup = WalletTopup::create([
            'user_id' => $theirs->id,
            'amount' => 500,
            'status' => WalletTopup::CREATED,
            'gateway_order_id' => 'order_theirs',
        ]);

        Sanctum::actingAs($mine);
        $this->postJson("/api/v1/wallet/topups/{$topup->id}/verify", [
            'razorpay_payment_id' => 'pay_x',
            'razorpay_order_id' => 'order_theirs',
            'razorpay_signature' => 'x',
        ])->assertNotFound();
    }

    public function test_topups_outside_the_limits_are_refused(): void
    {
        $customer = $this->customer();

        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/wallet/topups', ['amount' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->postJson('/api/v1/wallet/topups', ['amount' => 50000])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_a_topup_that_would_breach_the_balance_ceiling_is_refused(): void
    {
        // A balance that quietly grows past the RBI's ₹10,000 minimum-detail
        // cap invites a question we would rather not have to answer.
        config(['payments.gateway' => 'fake']);

        $customer = $this->customer();
        $this->fund($customer, 9000);

        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/wallet/topups', ['amount' => 2000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_the_statement_explains_the_balance(): void
    {
        $customer = $this->customer();
        $this->fund($customer, 500);

        Sanctum::actingAs($customer);
        $body = $this->getJson('/api/v1/wallet')->assertOk()->json('data');

        $this->assertEquals(500, $body['balance']);
        $this->assertSame('topup', $body['transactions'][0]['type']);
        $this->assertSame('credit', $body['transactions'][0]['direction']);
        $this->assertEquals(500, $body['transactions'][0]['balance_after']);
    }

    public function test_a_wallet_that_covers_the_bill_places_the_order_outright(): void
    {
        $shop = $this->restaurant();
        $customer = $this->customer();
        $this->fund($customer, 2000);

        $order = $this->orderWithWallet($shop, $customer, 'wallet');

        $this->assertSame(OrderStatus::Placed, $order->status);
        $this->assertSame('0.00', $order->cod_amount);
        $this->assertSame((string) $order->grand_total, (string) $order->wallet_amount);

        // Settled the moment it was placed — the money moved before the order
        // existed, so nothing is waiting on a provider.
        $this->assertSame(PaymentStatus::Paid, $order->payments()->first()->status);
    }

    public function test_a_partial_wallet_leaves_the_rest_for_the_rider_to_collect(): void
    {
        $shop = $this->restaurant();
        $customer = $this->customer();
        $this->fund($customer, 50);

        $order = $this->orderWithWallet($shop, $customer, 'cod');

        $this->assertSame('50.00', $order->wallet_amount);
        $this->assertSame(
            number_format((float) $order->grand_total - 50, 2, '.', ''),
            (string) $order->cod_amount,
            'the rider would ask for the wrong amount',
        );

        $this->assertSame('0.00', Wallet::where('user_id', $customer->id)->first()->balance);
    }

    public function test_claiming_the_wallet_covers_it_when_it_does_not_is_refused(): void
    {
        /*
         * The app picks the method by reading the balance first, and it can
         * change in between — another device, or a refund landing. Letting
         * this through would create an order marked fully paid that is not.
         */
        $shop = $this->restaurant();
        $customer = $this->customer();
        $this->fund($customer, 20);

        Sanctum::actingAs($customer);
        $address = $this->deliveryAddress($customer);
        $dish = $this->dish($shop);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id, 'quantity' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery',
            'payment_method' => 'wallet',
            'address_id' => $address->id,
            'use_wallet' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('use_wallet');

        // Nothing taken, and no half-built order left behind.
        $this->assertSame('20.00', Wallet::where('user_id', $customer->id)->first()->balance);
        $this->assertSame(0, Order::where('user_id', $customer->id)->count());
    }

    public function test_a_cancelled_order_puts_the_wallet_money_back(): void
    {
        $shop = $this->restaurant();
        $customer = $this->customer();
        $this->fund($customer, 2000);

        $order = $this->orderWithWallet($shop, $customer, 'wallet');
        $spent = (float) $order->wallet_amount;

        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Changed my mind'])->assertOk();

        $this->assertSame(
            number_format(2000.0, 2, '.', ''),
            Wallet::where('user_id', $customer->id)->first()->balance,
            'the refund did not restore the balance',
        );

        $refund = WalletTransaction::where('type', WalletTransaction::REFUND)->first();
        $this->assertNotNull($refund);
        $this->assertSame(number_format($spent, 2, '.', ''), $refund->amount);
    }

    public function test_cancelling_twice_refunds_once(): void
    {
        $shop = $this->restaurant();
        $customer = $this->customer();
        $this->fund($customer, 2000);

        $order = $this->orderWithWallet($shop, $customer, 'wallet');

        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        // The second is refused as already cancelled, but even if support
        // forced it through, the idempotency key holds the line.
        $this->postJson("/api/v1/orders/{$order->id}/cancel");
        app(WalletService::class)->refundOrder($order->fresh());

        $this->assertSame(1, WalletTransaction::where('type', WalletTransaction::REFUND)->count());
        $this->assertSame('2000.00', Wallet::where('user_id', $customer->id)->first()->balance);
    }

    public function test_a_wallet_can_never_go_negative(): void
    {
        /*
         * The last line of defence. Reaching here means a caller skipped the
         * lock or did its own arithmetic, and losing the transaction is far
         * better than losing the money.
         */
        $customer = $this->customer();
        $this->fund($customer, 100);

        $this->expectException(\RuntimeException::class);

        $wallet = Wallet::where('user_id', $customer->id)->first();
        $method = new \ReflectionMethod(WalletService::class, 'move');
        $method->invoke(
            $this->wallets(),
            $customer,
            500.0,
            WalletTransaction::DEBIT,
            WalletTransaction::ORDER_PAYMENT,
            ['description' => 'more than there is'],
            $wallet,
        );
    }

    public function test_the_ledger_is_checked_against_the_balance(): void
    {
        $customer = $this->customer();
        $this->fund($customer, 400);

        $this->artisan('nexmile:check-wallets')->assertSuccessful();

        // A balance written behind the ledger's back is exactly the drift this
        // exists to catch.
        Wallet::where('user_id', $customer->id)->first()->forceFill(['balance' => 999])->save();

        $this->artisan('nexmile:check-wallets')->assertFailed();
    }

    public function test_the_wallet_works_while_the_gateway_is_off(): void
    {
        /*
         * Wallet money moved when it was topped up, so spending it needs
         * nobody's permission. A customer with a balance should not be told to
         * pay cash because Razorpay is down.
         */
        config(['payments.gateway' => null]);

        $shop = $this->restaurant();
        $customer = $this->customer();
        $this->fund($customer, 2000);

        $order = $this->orderWithWallet($shop, $customer, 'wallet');

        $this->assertSame(OrderStatus::Placed, $order->status);
    }

    public function test_topping_up_is_refused_with_no_gateway(): void
    {
        // Putting money in does need one, and a screen that leads nowhere is
        // worse than one that says so.
        config(['payments.gateway' => null]);

        Sanctum::actingAs($this->customer());
        $this->postJson('/api/v1/wallet/topups', ['amount' => 500])->assertStatus(503);
    }

    public function test_the_wallet_cannot_be_combined_with_an_online_payment(): void
    {
        config(['payments.gateway' => 'fake']);

        $shop = $this->restaurant();
        $customer = $this->customer();
        $this->fund($customer, 50);

        Sanctum::actingAs($customer);
        $address = $this->deliveryAddress($customer);
        $dish = $this->dish($shop);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id, 'quantity' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery',
            'payment_method' => 'upi',
            'address_id' => $address->id,
            'use_wallet' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('use_wallet');
    }

    public function test_the_wallet_needs_a_signed_in_customer(): void
    {
        $this->getJson('/api/v1/wallet')->assertUnauthorized();
        $this->postJson('/api/v1/wallet/topups', ['amount' => 100])->assertUnauthorized();
    }

    public function test_topping_up_is_not_a_taxable_supply(): void
    {
        /*
         * Confirmed with the client's accountant: no GST arises on a top-up.
         *
         * Adding money is a deposit, not a supply — nothing has been sold yet,
         * and the tax falls due on the food when the balance eventually buys
         * some. So ₹500 paid in is ₹500 credited: nothing withheld, nothing
         * added, and no tax recorded anywhere against it.
         *
         * This is a rule somebody could plausibly "fix" by deducting GST on
         * the way in, which would both short the customer and account for tax
         * twice on the same money.
         */
        $customer = $this->customer();
        $this->fund($customer, 500);

        $this->assertSame('500.00', Wallet::where('user_id', $customer->id)->first()->balance);

        $row = WalletTransaction::where('type', WalletTransaction::TOPUP)->firstOrFail();
        $this->assertSame('500.00', $row->amount);
        $this->assertSame('500.00', $row->balance_after);
    }

    public function test_paying_from_the_wallet_does_not_change_the_tax(): void
    {
        /*
         * The other half of the same rule. GST is charged on the supply, so
         * how the customer settled it — balance, cash, card — cannot alter
         * what is owed on the food.
         */
        $shop = $this->restaurant();

        $cash = $this->customer();
        $paidInCash = $this->orderWithWallet($shop, $cash, 'cod', useWallet: false);

        $funded = $this->customer();
        $this->fund($funded, 2000);
        $paidFromWallet = $this->orderWithWallet($shop, $funded, 'wallet');

        $this->assertSame(
            (string) $paidInCash->tax_total,
            (string) $paidFromWallet->tax_total,
            'the wallet changed the GST on an identical basket',
        );

        $this->assertSame((string) $paidInCash->grand_total, (string) $paidFromWallet->grand_total);
    }

    public function test_the_invoice_shows_what_came_from_the_balance(): void
    {
        /*
         * An invoice reading "cash on delivery" to somebody who handed over
         * half the total is a document they cannot reconcile against their own
         * bank — and the GST on it is unaffected either way.
         */
        $shop = $this->restaurant();
        $customer = $this->customer();
        $this->fund($customer, 50);

        $order = $this->orderWithWallet($shop, $customer, 'cod');

        Sanctum::actingAs($customer);
        $html = $this->get("/api/v1/orders/{$order->id}/invoice")->assertOk()->getContent();

        $this->assertStringContainsString('50.00 paid from wallet balance', $html);
        $this->assertStringContainsString('collected on delivery', $html);
    }

    /** Place an order paying as much as possible from the wallet. */
    private function orderWithWallet($shop, User $customer, string $method, bool $useWallet = true): Order
    {
        Sanctum::actingAs($customer);
        $address = $this->deliveryAddress($customer);
        $dish = $this->dish($shop);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $dish->id, 'quantity' => 1,
        ])->assertCreated();

        $placed = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery',
            'payment_method' => $method,
            'address_id' => $address->id,
            'use_wallet' => $useWallet,
        ])->assertCreated()->json('data');

        return Order::findOrFail($placed['id']);
    }
}
