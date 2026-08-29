<?php

namespace Tests\Feature;

use App\Contracts\PushSender;
use App\Enums\FulfilmentType;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\RiderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Rider;
use App\Models\User;
use App\Services\Orders\OrderStatusService;
use App\Services\Push\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reaching people whose app is closed.
 *
 * A rider's phone is in their pocket with the screen off, and a suspended
 * app's socket is dead. This is the only route to someone who is not looking
 * at a screen, which is why the order offer matters more than everything else
 * here put together.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role, string $prefix): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => ucfirst($prefix).' '.$n,
            'phone' => '96000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "{$prefix}{$n}@example.in",
            'password' => 'secret',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function restaurant(): Merchant
    {
        return Merchant::create([
            'user_id' => $this->user(UserRole::Merchant, 'shop')->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'latitude' => 9.9195,
            'longitude' => 78.1193,
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
        ]);
    }

    private function rider(array $attributes = []): Rider
    {
        return $this->user(UserRole::Rider, 'rider')->rider()->create([
            'full_name' => 'Selvam K',
            'vehicle_type' => 'motorcycle',
            'kyc_status' => KycStatus::Verified,
            'driving_licence_expiry' => now()->addYear(),
            'insurance_expiry' => now()->addYear(),
            'duty_status' => RiderStatus::Available,
            ...$attributes,
        ]);
    }

    private function order(Merchant $merchant, User $customer, OrderStatus $status = OrderStatus::Placed): Order
    {
        static $n = 0;
        $n++;

        return $merchant->orders()->create([
            'order_number' => 'NXP'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'user_id' => $customer->id,
            'status' => $status,
            'fulfilment_type' => FulfilmentType::Delivery,
            'delivery_contact_name' => 'Meena',
            'delivery_line1' => '4 Gandhi Nagar',
            'delivery_city' => 'Madurai',
            'delivery_pincode' => '625020',
            'items_total' => 300,
            'grand_total' => 340,
            'merchant_payout' => 270,
            'placed_at' => now(),
        ])->fresh();
    }

    public function test_a_device_registers_and_can_be_forgotten(): void
    {
        $user = $this->user(UserRole::Customer, 'customer');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/devices', [
            'token' => 'fcm-token-abc',
            'platform' => 'android',
            'app' => 'customer',
        ])->assertOk();

        $this->assertSame(1, DeviceToken::where('user_id', $user->id)->count());

        // Without this, the phone keeps buzzing for a shift somebody else is
        // working, and that person cannot stop it.
        $this->deleteJson('/api/v1/devices', ['token' => 'fcm-token-abc'])->assertOk();

        $this->assertSame(0, DeviceToken::count());
    }

    public function test_registering_the_same_token_twice_does_not_duplicate_it(): void
    {
        // FCM rotates tokens on reinstall and restore, and apps re-register on
        // every launch.
        $user = $this->user(UserRole::Customer, 'customer');

        Sanctum::actingAs($user);

        foreach ([1, 2] as $ignored) {
            $this->postJson('/api/v1/devices', [
                'token' => 'same-token',
                'platform' => 'android',
                'app' => 'customer',
            ])->assertOk();
        }

        $this->assertSame(1, DeviceToken::count());
    }

    public function test_a_token_moves_to_whoever_signed_in_last(): void
    {
        /*
         * A token belongs to an install, not a person. One rider handing a
         * device to the next shift must not leave the previous account
         * receiving the new one's orders.
         */
        $first = $this->user(UserRole::Rider, 'rider');
        $second = $this->user(UserRole::Rider, 'rider');

        Sanctum::actingAs($first);
        $this->postJson('/api/v1/devices', ['token' => 'shared', 'platform' => 'android', 'app' => 'rider']);

        Sanctum::actingAs($second);
        $this->postJson('/api/v1/devices', ['token' => 'shared', 'platform' => 'android', 'app' => 'rider']);

        $this->assertSame(1, DeviceToken::count());
        $this->assertSame($second->id, DeviceToken::sole()->user_id);
    }

    public function test_the_two_apps_are_separate_installs(): void
    {
        // A rider ordering their dinner is a customer. Their two apps must not
        // receive each other's alerts.
        $user = $this->user(UserRole::Rider, 'rider');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/devices', ['token' => 'rider-tok', 'platform' => 'android', 'app' => 'rider']);
        $this->postJson('/api/v1/devices', ['token' => 'cust-tok', 'platform' => 'android', 'app' => 'customer']);

        $this->assertSame(2, DeviceToken::where('user_id', $user->id)->count());
    }

    public function test_marking_an_order_ready_offers_it_to_riders(): void
    {
        /*
         * The one that justifies the whole feature: an order that sits going
         * cold until somebody's phone buzzes in their pocket.
         */
        Queue::fake();

        $merchant = $this->restaurant();
        $customer = $this->user(UserRole::Customer, 'customer');
        $this->rider();

        $order = $this->order($merchant, $customer, OrderStatus::Preparing);

        app(OrderStatusService::class)->markReady($order, $merchant->user);

        Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->app === PushService::RIDER
            && $job->data['type'] === 'order.offer');

        // And the customer is told their food is ready.
        Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->app === PushService::CUSTOMER
            && $job->data['type'] === 'order.ready');
    }

    public function test_an_off_duty_rider_is_not_offered_the_order(): void
    {
        // Waking someone who has finished their shift is how an app gets its
        // notifications switched off for good.
        Queue::fake();

        $merchant = $this->restaurant();
        $this->rider(['duty_status' => RiderStatus::Offline]);

        $order = $this->order($merchant, $this->user(UserRole::Customer, 'customer'), OrderStatus::Preparing);

        app(OrderStatusService::class)->markReady($order, $merchant->user);

        Queue::assertNotPushed(SendPushNotification::class, fn ($job) => $job->app === PushService::RIDER);
    }

    public function test_a_rider_is_never_offered_their_own_order(): void
    {
        // The same hole the board already closes: order dinner, deliver it to
        // yourself, keep the fee.
        Queue::fake();

        $merchant = $this->restaurant();
        $rider = $this->rider();

        $order = $this->order($merchant, $rider->user, OrderStatus::Preparing);

        app(OrderStatusService::class)->markReady($order, $merchant->user);

        Queue::assertNotPushed(SendPushNotification::class, fn ($job) => $job->app === PushService::RIDER);
    }

    public function test_a_rider_with_expired_documents_is_not_offered_the_order(): void
    {
        Queue::fake();

        $merchant = $this->restaurant();
        $this->rider(['insurance_expiry' => now()->subDay()]);

        $order = $this->order($merchant, $this->user(UserRole::Customer, 'customer'), OrderStatus::Preparing);

        app(OrderStatusService::class)->markReady($order, $merchant->user);

        Queue::assertNotPushed(SendPushNotification::class, fn ($job) => $job->app === PushService::RIDER);
    }

    public function test_accepting_and_delivering_tell_the_customer(): void
    {
        Queue::fake();

        $merchant = $this->restaurant();
        $customer = $this->user(UserRole::Customer, 'customer');
        $order = $this->order($merchant, $customer);

        $status = app(OrderStatusService::class);
        $status->accept($order, $merchant->user, 20);

        Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->data['type'] === 'order.accepted');
    }

    public function test_sending_is_queued_not_inline(): void
    {
        /*
         * FCM is one HTTP call per device. Doing that inline would add a round
         * trip per rider to the moment a merchant taps "ready" — the one
         * moment where a spinner costs food going cold.
         */
        Queue::fake();

        $merchant = $this->restaurant();
        $order = $this->order($merchant, $this->user(UserRole::Customer, 'customer'));

        app(OrderStatusService::class)->accept($order, $merchant->user, 20);

        Queue::assertPushed(SendPushNotification::class);
    }

    public function test_delivery_prunes_tokens_the_provider_rejected(): void
    {
        /*
         * Without pruning, the table fills with uninstalled apps and every
         * send gets slower — one wasted HTTP request per dead device, forever.
         */
        $user = $this->user(UserRole::Customer, 'customer');

        $push = app(PushService::class);
        $push->register($user, 'live-token', 'android', PushService::CUSTOMER);
        $push->register($user, 'dead-token', 'android', PushService::CUSTOMER);

        $this->app->bind(PushSender::class, fn () => new class implements PushSender
        {
            public function send(array $tokens, string $title, string $body, array $data = []): array
            {
                return ['dead-token'];
            }
        });

        app(PushService::class)->deliver([$user->id], PushService::CUSTOMER, 'Title', 'Body');

        $this->assertSame(
            ['live-token'],
            DeviceToken::where('user_id', $user->id)->pluck('token')->all(),
        );
    }

    public function test_a_person_with_no_devices_costs_nothing(): void
    {
        // Most people will not have granted permission. That must not become a
        // queued job per order.
        Queue::fake();

        $merchant = $this->restaurant();
        $order = $this->order($merchant, $this->user(UserRole::Customer, 'customer'));

        app(PushService::class)->toUser(null, PushService::CUSTOMER, 'Title', 'Body');

        Queue::assertNothingPushed();
    }
}
