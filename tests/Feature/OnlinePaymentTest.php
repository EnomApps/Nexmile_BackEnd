<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\FakeGateway;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Online payment (EP6).
 *
 * Two rules run through all of it: an unpaid order never reaches a kitchen,
 * and the client is never the authority on whether money moved.
 */
class OnlinePaymentTest extends CheckoutTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // The fake gateway signs with a real HMAC, so the verification path is
        // genuinely exercised rather than stubbed to "yes".
        config(['payments.gateway' => 'fake']);
    }

    private function gateway(): FakeGateway
    {
        return app(PaymentGateway::class);
    }

    /** @return array{Order, User, Merchant} */
    private function unpaidOrder(): array
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $address = $customer->addresses()->create([
            'label' => 'home', 'line1' => '4 Gandhi Nagar', 'city' => 'Madurai',
            'pincode' => '625020', 'latitude' => 9.9200, 'longitude' => 78.1195,
        ]);

        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", [
            'menu_item_id' => $this->dish($shop)->id, 'quantity' => 2,
        ])->assertCreated();

        $body = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery', 'payment_method' => 'upi', 'address_id' => $address->id,
        ])->assertCreated()->json('data');

        return [Order::find($body['id']), $customer, $shop];
    }

    public function test_an_unpaid_order_never_reaches_the_kitchen(): void
    {
        [$order, , $shop] = $this->unpaidOrder();

        $this->assertSame(OrderStatus::PendingPayment, $order->status);
        $this->assertNull($order->placed_at);

        // The merchant queue filters pending_payment out, so a kitchen never
        // starts on money that has not moved.
        Sanctum::actingAs($shop->user);
        $this->getJson('/api/v1/merchant/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_starting_a_payment_returns_what_the_sdk_needs(): void
    {
        [$order, $customer] = $this->unpaidOrder();

        Sanctum::actingAs($customer);

        $data = $this->postJson("/api/v1/orders/{$order->id}/payment")
            ->assertOk()->json('data');

        $this->assertStringStartsWith('order_', $data['gateway_order_id']);
        // Paise, not rupees — ₹420.00 becomes 42000.
        $this->assertSame(42000, $data['amount_paise']);
        $this->assertSame('INR', $data['currency']);
        $this->assertSame($order->order_number, $data['order_number']);

        $this->assertSame($data['gateway_order_id'], $order->payments()->latest('id')->first()->gateway_order_id);
    }

    public function test_a_confirmed_payment_puts_the_order_in_the_kitchen(): void
    {
        [$order, $customer, $shop] = $this->unpaidOrder();

        Sanctum::actingAs($customer);
        $session = $this->postJson("/api/v1/orders/{$order->id}/payment")->assertOk()->json('data');

        $paymentId = 'pay_OK123456789';
        $signature = $this->gateway()->sign($session['gateway_order_id'].'|'.$paymentId);

        $this->postJson("/api/v1/orders/{$order->id}/payment/confirm", [
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
        ])->assertOk()->assertJsonPath('data.status', 'placed');

        $order->refresh();
        $this->assertSame(OrderStatus::Placed, $order->status);
        $this->assertNotNull($order->placed_at);
        $this->assertSame(PaymentStatus::Paid, $order->payments()->latest('id')->first()->status);

        Sanctum::actingAs($shop->user);
        $this->getJson('/api/v1/merchant/orders')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_forged_signature_is_refused_and_nothing_is_placed(): void
    {
        [$order, $customer] = $this->unpaidOrder();

        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/orders/{$order->id}/payment")->assertOk();

        // An app saying "it worked" is not evidence.
        $this->postJson("/api/v1/orders/{$order->id}/payment/confirm", [
            'razorpay_payment_id' => 'pay_FORGED',
            'razorpay_signature' => str_repeat('a', 64),
        ])->assertStatus(422)->assertJsonValidationErrors('signature');

        $order->refresh();
        $this->assertSame(OrderStatus::PendingPayment, $order->status);
        $this->assertSame(PaymentStatus::Failed, $order->payments()->latest('id')->first()->status);
    }

    public function test_a_valid_signature_over_a_payment_that_failed_is_still_refused(): void
    {
        [$order, $customer] = $this->unpaidOrder();

        Sanctum::actingAs($customer);
        $session = $this->postJson("/api/v1/orders/{$order->id}/payment")->assertOk()->json('data');

        // The fake reports anything prefixed pay_fail_ as failed. A signature
        // proves the message is authentic, not that money moved.
        $paymentId = 'pay_fail_9999';
        $signature = $this->gateway()->sign($session['gateway_order_id'].'|'.$paymentId);

        $this->postJson("/api/v1/orders/{$order->id}/payment/confirm", [
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
        ])->assertStatus(422)->assertJsonValidationErrors('payment');

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_the_webhook_completes_an_order_the_app_never_confirmed(): void
    {
        [$order, $customer] = $this->unpaidOrder();

        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/orders/{$order->id}/payment")->assertOk();

        // The customer force-closed the app on the bank's page. Razorpay still
        // tells us, which is the whole reason the webhook is the authority.
        $this->postWebhook([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_WEBHOOK01', 'method' => 'upi',
                'notes' => ['order_id' => (string) $order->id],
            ]]],
        ])->assertOk();

        $this->assertSame(OrderStatus::Placed, $order->fresh()->status);
    }

    public function test_a_webhook_without_a_valid_signature_is_rejected(): void
    {
        [$order] = $this->unpaidOrder();

        $payload = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'id' => 'pay_FAKE', 'notes' => ['order_id' => (string) $order->id],
        ]]]]);

        // The only thing between this URL and a stranger pushing unpaid orders
        // into kitchens.
        $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'HTTP_X-Razorpay-Signature' => 'nonsense',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(401);

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_a_repeated_webhook_changes_nothing(): void
    {
        [$order, $customer] = $this->unpaidOrder();

        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/orders/{$order->id}/payment")->assertOk();

        $body = [
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_TWICE01', 'method' => 'upi',
                'notes' => ['order_id' => (string) $order->id],
            ]]],
        ];

        // Razorpay retries until we answer, so every write has to be safe to
        // repeat.
        $this->postWebhook($body)->assertOk();
        $placedAt = $order->fresh()->placed_at;

        $this->postWebhook($body)->assertOk();

        $this->assertEquals($placedAt, $order->fresh()->placed_at);
        $this->assertSame(1, $order->statusHistory()->where('to_status', 'placed')->count());
    }

    public function test_cancelling_a_paid_order_refunds_it(): void
    {
        [$order, $customer, $shop] = $this->unpaidOrder();

        Sanctum::actingAs($customer);
        $session = $this->postJson("/api/v1/orders/{$order->id}/payment")->assertOk()->json('data');
        $paymentId = 'pay_REFUND01';

        $this->postJson("/api/v1/orders/{$order->id}/payment/confirm", [
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $this->gateway()->sign($session['gateway_order_id'].'|'.$paymentId),
        ])->assertOk();

        Sanctum::actingAs($shop->user);
        $this->postJson("/api/v1/merchant/orders/{$order->id}/reject", [
            'reason' => 'Kitchen closed early tonight, sorry.',
        ])->assertOk();

        $refund = $order->refunds()->sole();
        $this->assertSame('completed', $refund->status);
        $this->assertMoney(420.0, $refund->amount);
        $this->assertSame(PaymentStatus::Refunded, $order->payments()->latest('id')->first()->status);
    }

    public function test_a_cod_order_has_nothing_to_refund(): void
    {
        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $address = $customer->addresses()->create([
            'label' => 'home', 'line1' => '4 Gandhi Nagar', 'city' => 'Madurai',
            'pincode' => '625020', 'latitude' => 9.9200, 'longitude' => 78.1195,
        ]);
        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $this->dish($shop)->id])
            ->assertCreated();
        $order = $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery', 'payment_method' => 'cod', 'address_id' => $address->id,
        ])->assertCreated()->json('data');

        Sanctum::actingAs($shop->user);
        $this->postJson("/api/v1/merchant/orders/{$order['id']}/reject", [
            'reason' => 'Gas cylinder ran out, sorry.',
        ])->assertOk();

        $this->assertSame(0, Order::find($order['id'])->refunds()->count());
    }

    public function test_an_abandoned_payment_is_expired_and_its_stock_released(): void
    {
        [$order] = $this->unpaidOrder();

        $order->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('nexmile:expire-unpaid')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('system', $order->cancelled_by);
        $this->assertSame(PaymentStatus::Failed, $order->payments()->latest('id')->first()->status);
    }

    public function test_a_fresh_unpaid_order_is_left_alone(): void
    {
        [$order] = $this->unpaidOrder();

        // Long enough to survive a slow bank page.
        $this->artisan('nexmile:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_online_payment_is_refused_when_no_gateway_is_configured(): void
    {
        config(['payments.gateway' => null]);

        Sanctum::actingAs($customer = $this->customer());
        $shop = $this->restaurant();
        $address = $customer->addresses()->create([
            'label' => 'home', 'line1' => '4 Gandhi Nagar', 'city' => 'Madurai',
            'pincode' => '625020', 'latitude' => 9.9200, 'longitude' => 78.1195,
        ]);
        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/items", ['menu_item_id' => $this->dish($shop)->id])
            ->assertCreated();

        // Offering a method that cannot complete loses the basket at the final
        // step, which is worse than not offering it.
        $this->postJson("/api/v1/restaurants/{$shop->id}/cart/checkout", [
            'fulfilment_type' => 'delivery', 'payment_method' => 'upi', 'address_id' => $address->id,
        ])->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    public function test_another_customers_payment_cannot_be_started(): void
    {
        [$order] = $this->unpaidOrder();

        Sanctum::actingAs($this->customer());

        $this->postJson("/api/v1/orders/{$order->id}/payment")->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->id}/payment/confirm", [
            'razorpay_payment_id' => 'pay_X', 'razorpay_signature' => 'x',
        ])->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function postWebhook(array $body): TestResponse
    {
        $raw = json_encode($body);

        return $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'HTTP_X-Razorpay-Signature' => $this->gateway()->sign($raw),
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }
}
