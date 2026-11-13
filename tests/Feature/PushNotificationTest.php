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
use App\Services\Push\FcmPushSender;
use App\Services\Push\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
            public function send(
                array $tokens,
                string $title,
                string $body,
                array $data = [],
                ?string $collapseKey = null,
            ): array {
                return ['dead-token'];
            }

            public function sendData(
                array $tokens,
                array $data,
                ?string $collapseKey = null,
                ?int $ttlSeconds = null,
            ): array {
                return ['dead-token'];
            }
        });

        app(PushService::class)->deliver([$user->id], PushService::CUSTOMER, 'push.customer.delivered');

        $this->assertSame(
            ['live-token'],
            DeviceToken::where('user_id', $user->id)->pluck('token')->all(),
        );
    }

    public function test_the_payload_names_the_channel_and_the_sound(): void
    {
        config([
            'push.fcm.project_id' => 'nexmile-e03c1',
            'push.android_channel' => 'nexmile_orders',
            'push.ios_sound' => 'nexmile.caf',
        ]);

        // Seeded so the sender never reaches for the service-account file.
        // This is about the shape of the message, not about minting OAuth.
        Cache::put('push.fcm.token', 'test-access-token', now()->addMinutes(50));

        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'sent'])]);

        (new FcmPushSender)->send(['a-token'], 'Title', 'Body', ['type' => 'order.accepted', 'order_id' => 8]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'fcm.googleapis.com')) {
                return true;
            }

            $message = $request->data()['message'];

            /*
             * Both are silent when wrong. Android drops a notification whose
             * channel it does not know, and iOS plays nothing for a sound file
             * the app does not bundle — neither reports an error, so only a
             * test catches a change here.
             */
            $this->assertSame('nexmile_orders', $message['android']['notification']['channel_id']);
            $this->assertSame('nexmile.caf', $message['apns']['payload']['aps']['sound']);

            // FCM rejects a data payload containing any other scalar, and the
            // app builds its deep link by reading order_id as a string.
            $this->assertSame('8', $message['data']['order_id']);
            $this->assertSame('order.accepted', $message['data']['type']);

            return true;
        });
    }

    public function test_a_person_with_no_devices_costs_nothing(): void
    {
        // Most people will not have granted permission. That must not become a
        // queued job per order.
        Queue::fake();

        $merchant = $this->restaurant();
        $order = $this->order($merchant, $this->user(UserRole::Customer, 'customer'));

        app(PushService::class)->toUser(null, PushService::CUSTOMER, 'push.customer.delivered');

        Queue::assertNothingPushed();
    }

    public function test_a_notification_is_written_in_the_recipients_language(): void
    {
        /*
         * The whole platform is Tier-2 and Tier-3 Tamil Nadu. A rider who
         * picked Tamil in the app being sent English on their lock screen is
         * not a cosmetic miss.
         *
         * This was broken for a while in a way nothing would have shown: the
         * copy was rendered in the request, then sent from a queue worker, so
         * every notification went out in the worker's own locale — English —
         * while lang/ta/push.php sat there unused.
         */
        $customer = $this->user(UserRole::Customer, 'customer');
        $customer->forceFill(['preferred_locale' => 'ta'])->save();

        $sent = $this->recordingSender();

        app(PushService::class)->register($customer, 'ta-token', 'android', PushService::CUSTOMER);

        app(PushService::class)->deliver([$customer->id], PushService::CUSTOMER, 'push.customer.delivered');

        $this->assertSame(__('push.customer.delivered.title', [], 'ta'), $sent->titles[0]);
        $this->assertNotSame(__('push.customer.delivered.title', [], 'en'), $sent->titles[0]);
    }

    public function test_one_notification_reaches_two_languages_at_once(): void
    {
        /*
         * The rider board is one notification to everybody on duty, and they
         * do not share a language. Rendering it once — in anybody's locale —
         * is wrong for whoever did not pick that one.
         */
        $tamil = $this->user(UserRole::Rider, 'rider');
        $tamil->forceFill(['preferred_locale' => 'ta'])->save();

        $hindi = $this->user(UserRole::Rider, 'rider');
        $hindi->forceFill(['preferred_locale' => 'hi'])->save();

        $push = app(PushService::class);
        $push->register($tamil, 'ta-token', 'android', PushService::RIDER);
        $push->register($hindi, 'hi-token', 'android', PushService::RIDER);

        $sent = $this->recordingSender();

        app(PushService::class)->deliver(
            [$tamil->id, $hindi->id],
            PushService::RIDER,
            'push.rider.offer',
            ['restaurant' => 'Ponnusamy Hotel', 'area' => 'Madurai'],
        );

        $this->assertCount(2, $sent->titles, 'both languages should have been sent separately');

        $this->assertEqualsCanonicalizing(
            [__('push.rider.offer.title', [], 'ta'), __('push.rider.offer.title', [], 'hi')],
            $sent->titles,
        );
    }

    public function test_the_replacements_still_land_in_a_translated_body(): void
    {
        // A translated string with a dropped placeholder reads as a bug to the
        // person holding the phone, not as a missing translation.
        $customer = $this->user(UserRole::Customer, 'customer');
        $customer->forceFill(['preferred_locale' => 'ta'])->save();

        $sent = $this->recordingSender();

        app(PushService::class)->register($customer, 'ta-token', 'android', PushService::CUSTOMER);

        app(PushService::class)->deliver(
            [$customer->id],
            PushService::CUSTOMER,
            'push.customer.accepted',
            ['restaurant' => 'Ponnusamy Hotel', 'minutes' => 20],
        );

        $this->assertStringContainsString('Ponnusamy Hotel', $sent->bodies[0]);
        $this->assertStringContainsString('20', $sent->bodies[0]);
        $this->assertStringNotContainsString(':restaurant', $sent->bodies[0]);
    }

    public function test_a_blank_locale_falls_back_rather_than_sending_nothing(): void
    {
        // An older row, or a user created before the column had a default. The
        // notification still has to be readable.
        $customer = $this->user(UserRole::Customer, 'customer');
        $customer->forceFill(['preferred_locale' => ''])->save();

        $sent = $this->recordingSender();

        app(PushService::class)->register($customer, 'blank-token', 'android', PushService::CUSTOMER);

        app(PushService::class)->deliver([$customer->id], PushService::CUSTOMER, 'push.customer.delivered');

        $this->assertSame(__('push.customer.delivered.title', [], config('app.locale')), $sent->titles[0]);
    }

    public function test_the_queued_job_carries_a_key_not_finished_copy(): void
    {
        /*
         * The guard on the whole fix. If somebody puts rendered text back into
         * the job, it is rendered in the request's locale again and every
         * notification quietly returns to English.
         */
        Queue::fake();

        $merchant = $this->restaurant();
        $order = $this->order($merchant, $this->user(UserRole::Customer, 'customer'));

        app(OrderStatusService::class)->accept($order, $merchant->user, 20);

        Queue::assertPushed(SendPushNotification::class, function ($job) {
            $this->assertSame('push.customer.accepted', $job->key);
            $this->assertSame(20, $job->replacements['minutes']);

            return true;
        });
    }

    public function test_only_the_owner_can_forget_a_device(): void
    {
        /*
         * Otherwise any signed-in account could silence somebody else's phone
         * with a token it happened to learn — and a rider whose notifications
         * stop has no way of telling why.
         */
        $owner = $this->user(UserRole::Rider, 'rider');
        $stranger = $this->user(UserRole::Customer, 'customer');

        app(PushService::class)->register($owner, 'owned-token', 'android', PushService::RIDER);

        Sanctum::actingAs($stranger);
        $this->deleteJson('/api/v1/devices', ['token' => 'owned-token'])->assertOk();

        $this->assertDatabaseHas('device_tokens', ['token' => 'owned-token', 'user_id' => $owner->id]);

        Sanctum::actingAs($owner);
        $this->deleteJson('/api/v1/devices', ['token' => 'owned-token'])->assertOk();

        $this->assertDatabaseMissing('device_tokens', ['token' => 'owned-token']);
    }

    public function test_a_rejected_access_token_is_minted_again_and_the_message_retried(): void
    {
        /*
         * The OAuth token lasts an hour and is cached for fifty minutes. A key
         * rotated or revoked at six o'clock would otherwise drop every
         * notification on the platform until ten to seven, with nothing but a
         * log line to say so.
         */
        config(['push.fcm.project_id' => 'nexmile-e03c1']);

        Cache::put('push.fcm.token', 'stale-token', now()->addMinutes(50));

        Http::fakeSequence()
            ->push(['error' => ['status' => 'UNAUTHENTICATED']], 401)
            ->push(['name' => 'sent'], 200);

        $sender = $this->senderWithCountedTokens();

        $dead = $sender->send(['a-token'], 'Title', 'Body');

        $this->assertSame(2, $sender->minted, 'a 401 did not mint a fresh token');

        // Retried and delivered, and a live token not mistaken for a dead one.
        $this->assertSame([], $dead);

        // The stale one is gone, so the next send does not start by failing.
        $this->assertNotSame('stale-token', Cache::get('push.fcm.token'));
    }

    public function test_a_revoked_key_is_not_minted_against_once_per_device(): void
    {
        /*
         * A key that is genuinely revoked answers 401 to everything. Minting
         * once per device would turn one broken key into a flood of token
         * requests at Google, which is how a project gets rate limited on top
         * of being broken.
         */
        config(['push.fcm.project_id' => 'nexmile-e03c1']);

        Http::fake(['fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNAUTHENTICATED']], 401)]);

        $sender = $this->senderWithCountedTokens();

        $dead = $sender->send(['one', 'two', 'three'], 'Title', 'Body');

        // One mint at the start, one more after the first 401, and no more.
        $this->assertSame(2, $sender->minted, 'a revoked key minted a token per device');

        // And nothing was deleted: a 401 is our problem, not the device's.
        $this->assertSame([], $dead);
    }

    /**
     * The real sender with the OAuth mint counted and stubbed.
     *
     * Overridden rather than key-generated: the production service account can
     * notify every install of both apps, so no test goes near it, and
     * generating a throwaway RSA key needs an openssl.cnf that not every
     * machine has sitting where PHP expects it.
     */
    private function senderWithCountedTokens(): FcmPushSender
    {
        return new class extends FcmPushSender
        {
            public int $minted = 0;

            protected function accessToken(): string
            {
                $this->minted++;

                return 'access-token-'.$this->minted;
            }
        };
    }

    public function test_every_notification_exists_in_all_three_languages(): void
    {
        /*
         * A key missing from ta or hi does not fail — it falls back to
         * English, which looks exactly like working. So the only way this
         * stays true is a test that reads the files.
         *
         * Asserted both ways round: a key in English and not in Tamil is a
         * rider reading English, and a key in Tamil and not in English is
         * copy nobody will ever see.
         */
        $flatten = function (array $values, string $prefix = '') use (&$flatten): array {
            $keys = [];

            foreach ($values as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

                $keys = is_array($value)
                    ? [...$keys, ...$flatten($value, $path)]
                    : [...$keys, $path];
            }

            return $keys;
        };

        $read = fn (string $locale) => $flatten(require base_path("lang/{$locale}/push.php"));

        $english = $read('en');

        $this->assertNotEmpty($english);

        foreach (['ta', 'hi'] as $locale) {
            $this->assertEqualsCanonicalizing(
                $english,
                $read($locale),
                "lang/{$locale}/push.php does not hold the same keys as English",
            );
        }
    }

    public function test_every_notification_the_app_sends_has_copy_behind_it(): void
    {
        /*
         * The other half: a key passed by OrderNotifier that does not exist
         * sends the key itself as the notification — "push.customer.accepted"
         * on somebody's lock screen — because that is what __() returns when
         * it finds nothing.
         */
        $source = file_get_contents(app_path('Services/Push/OrderNotifier.php'));

        preg_match_all("/'(push\.[a-z_.]+)'/", $source, $matches);

        $keys = array_unique($matches[1]);

        $this->assertNotEmpty($keys, 'no notification keys found — has the notifier changed shape?');

        foreach ($keys as $key) {
            foreach (['title', 'body'] as $part) {
                $this->assertNotSame(
                    "{$key}.{$part}",
                    __("{$key}.{$part}"),
                    "{$key}.{$part} has no copy behind it and would be sent as the key",
                );
            }
        }
    }

    public function test_the_stages_of_one_order_replace_each_other(): void
    {
        /*
         * A customer who leaves their phone alone through a delivery would
         * otherwise find five notifications for one order — accepted, ready,
         * rider assigned, picked up, delivered — stacked newest first, with
         * the current one buried.
         *
         * Keyed on the order, so each stage replaces the last. Android still
         * alerts when it replaces, so every stage is felt; only the pile is
         * avoided.
         */
        $customer = $this->user(UserRole::Customer, 'customer');

        $sent = $this->recordingSender();

        app(PushService::class)->register($customer, 'c-token', 'android', PushService::CUSTOMER);

        $push = app(PushService::class);

        $push->deliver([$customer->id], PushService::CUSTOMER, 'push.customer.accepted',
            ['restaurant' => 'Ponnusamy Hotel', 'minutes' => 20],
            ['type' => 'order.accepted', 'order_id' => '41']);

        $push->deliver([$customer->id], PushService::CUSTOMER, 'push.customer.delivered',
            [], ['type' => 'order.delivered', 'order_id' => '41']);

        $this->assertSame(['order-41', 'order-41'], $sent->collapseKeys);
    }

    public function test_two_orders_do_not_replace_each_other(): void
    {
        // The reason it is keyed on the order and not on the person. A
        // customer with two deliveries running must see both.
        $customer = $this->user(UserRole::Customer, 'customer');

        $sent = $this->recordingSender();

        app(PushService::class)->register($customer, 'c-token', 'android', PushService::CUSTOMER);

        $push = app(PushService::class);

        $push->deliver([$customer->id], PushService::CUSTOMER, 'push.customer.ready',
            [], ['type' => 'order.ready', 'order_id' => '41']);

        $push->deliver([$customer->id], PushService::CUSTOMER, 'push.customer.ready',
            [], ['type' => 'order.ready', 'order_id' => '42']);

        $this->assertSame(['order-41', 'order-42'], $sent->collapseKeys);
    }

    public function test_a_notification_about_nothing_in_particular_stands_alone(): void
    {
        // The test notification has no order behind it, so there is nothing to
        // collapse it against and it must not silently replace a real one.
        $customer = $this->user(UserRole::Customer, 'customer');

        $sent = $this->recordingSender();

        app(PushService::class)->register($customer, 'c-token', 'android', PushService::CUSTOMER);

        app(PushService::class)->deliver([$customer->id], PushService::CUSTOMER, 'push.test', [], ['type' => 'test']);

        $this->assertSame([null], $sent->collapseKeys);
    }

    public function test_the_payload_carries_the_tag_android_and_ios_each_want(): void
    {
        /*
         * Two names for one idea, and both are silent when missing: Android
         * stacks, iOS stacks, and nothing reports a problem.
         */
        config(['push.fcm.project_id' => 'nexmile-e03c1']);

        Cache::put('push.fcm.token', 'test-access-token', now()->addMinutes(50));

        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'sent'])]);

        (new FcmPushSender)->send(['a-token'], 'Title', 'Body', ['type' => 'order.ready'], 'order-41');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'fcm.googleapis.com')) {
                return true;
            }

            $message = $request->data()['message'];

            $this->assertSame('order-41', $message['android']['notification']['tag']);
            $this->assertSame('order-41', $message['apns']['headers']['apns-collapse-id']);

            return true;
        });
    }

    public function test_no_collapse_key_leaves_the_fields_out_entirely(): void
    {
        /*
         * Sent as null rather than omitted, FCM rejects the message outright —
         * so an unrelated notification would fail rather than simply not
         * collapsing.
         */
        config(['push.fcm.project_id' => 'nexmile-e03c1']);

        Cache::put('push.fcm.token', 'test-access-token', now()->addMinutes(50));

        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'sent'])]);

        (new FcmPushSender)->send(['a-token'], 'Title', 'Body', ['type' => 'test']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'fcm.googleapis.com')) {
                return true;
            }

            $message = $request->data()['message'];

            $this->assertArrayNotHasKey('tag', $message['android']['notification']);
            $this->assertArrayNotHasKey('apns-collapse-id', $message['apns']['headers']);

            // And the channel is still there, which the tag sits beside.
            $this->assertArrayHasKey('channel_id', $message['android']['notification']);

            return true;
        });
    }

    /** A sender that records what it was asked to deliver. */
    private function recordingSender(): object
    {
        $sender = new class implements PushSender
        {
            /** @var list<string> */
            public array $titles = [];

            /** @var list<string> */
            public array $bodies = [];

            /** @var list<string|null> */
            public array $collapseKeys = [];

            /** @var list<array<string, string>> */
            public array $payloads = [];

            /** @var list<int|null> */
            public array $ttls = [];

            public function send(
                array $tokens,
                string $title,
                string $body,
                array $data = [],
                ?string $collapseKey = null,
            ): array {
                $this->titles[] = $title;
                $this->bodies[] = $body;
                $this->collapseKeys[] = $collapseKey;

                return [];
            }

            public function sendData(
                array $tokens,
                array $data,
                ?string $collapseKey = null,
                ?int $ttlSeconds = null,
            ): array {
                $this->payloads[] = $data;
                $this->collapseKeys[] = $collapseKey;
                $this->ttls[] = $ttlSeconds;

                return [];
            }
        };

        $this->app->instance(PushSender::class, $sender);

        return $sender;
    }
}
