<?php

namespace Tests\Feature;

use App\Enums\FulfilmentType;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\RiderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\RefreshToken;
use App\Models\Rider;
use App\Models\RiderLocationPing;
use App\Models\RiderPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin rider section, and the trail it reads.
 *
 * Two things are being protected here, and only one of them is the page.
 *
 * The page itself is mostly a reader, so what the tests pin is that it reads
 * from whatever already owns each figure: earnings from the orders and the
 * statements, sign-ins from the refresh tokens, position from Redis with the
 * row as a fallback. A number invented on this screen that disagreed with the
 * rider's own app would be worse than showing nothing, because an admin would
 * quote it to a rider.
 *
 * The other thing is the write rate. Storing a trail was avoided for a long
 * time for a good reason — the app pings every ten seconds and no relational
 * table should absorb that — so the gate that drops pings which did not move
 * is load-bearing, not an optimisation. If it ever stops working, nothing
 * breaks visibly; the table just quietly becomes the biggest one in the
 * database. So it is tested from both sides.
 */
class AdminRiderManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role, string $prefix): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => ucfirst($prefix).' '.$n,
            'phone' => '94000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "adminrider.{$prefix}{$n}@example.in",
            'password' => 'secret',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function rider(array $attributes = []): Rider
    {
        return $this->user(UserRole::Rider, 'rider')->rider()->create(array_merge([
            'full_name' => 'Selvam K',
            'vehicle_type' => 'motorcycle',
            'vehicle_number' => 'TN38AB1234',
            'kyc_status' => KycStatus::Verified,
            'duty_status' => RiderStatus::Available,
            'driving_licence_expiry' => now()->addYear(),
            'insurance_expiry' => now()->addYear(),
        ], $attributes));
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

    private function order(Rider $rider, OrderStatus $status, array $attributes = []): Order
    {
        static $n = 0;
        $n++;

        return $this->restaurant()->orders()->create(array_merge([
            'order_number' => 'NXR'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'user_id' => $this->user(UserRole::Customer, 'cust')->id,
            'rider_id' => $rider->id,
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
        ], $attributes))->fresh();
    }

    private function admin(): User
    {
        return $this->user(UserRole::Admin, 'admin');
    }

    // ------------------------------------------------------------ the roster

    public function test_the_roster_is_admin_only(): void
    {
        $this->get('/admin/riders')->assertRedirect('/admin/login');

        // A signed-in rider is still not an admin, which is the case worth
        // asserting: they hold a valid session for the same application.
        $this->actingAs($this->rider()->user)
            ->get('/admin/riders')
            ->assertForbidden();
    }

    public function test_the_roster_finds_a_rider_by_name_plate_or_phone(): void
    {
        $wanted = $this->rider(['full_name' => 'Murugan P', 'vehicle_number' => 'TN38ZZ9999']);
        $other = $this->rider(['full_name' => 'Anbu R', 'vehicle_number' => 'TN38AA1111']);

        $admin = $this->admin();

        foreach (['Murugan', 'TN38ZZ9999', $wanted->user->phone] as $term) {
            $response = $this->actingAs($admin)
                ->get('/admin/riders?q='.urlencode($term))
                ->assertOk();

            $response->assertSee('Murugan P');
            $response->assertDontSee('Anbu R');
        }

        /*
         * The id, asserted without exclusivity on purpose. A numeric term is
         * matched against the id exactly *and* as a fragment of plates,
         * phones and emails — which is the behaviour an admin wants, because
         * "38" should find every TN38 plate and a half-remembered number
         * plate is the commonest thing anybody has to search on. So a short
         * id legitimately returns other riders too, and pinning that it does
         * not would be pinning the wrong thing.
         */
        $this->actingAs($admin)
            ->get('/admin/riders?q='.$wanted->id)
            ->assertOk()
            ->assertSee('Murugan P');

        // Guard against a search that matches everything, which would pass
        // every assertion above while being useless.
        $this->actingAs($admin)
            ->get('/admin/riders?q=Anbu')
            ->assertOk()
            ->assertSee('Anbu R')
            ->assertDontSee('Murugan P');

        $this->assertNotNull($other->id);
    }

    public function test_the_roster_filters_by_duty_status(): void
    {
        $this->rider(['full_name' => 'On Duty Rider', 'duty_status' => RiderStatus::Available]);
        $this->rider(['full_name' => 'Clocked Off Rider', 'duty_status' => RiderStatus::Offline]);

        $this->actingAs($this->admin())
            ->get('/admin/riders?duty=available')
            ->assertOk()
            ->assertSee('On Duty Rider')
            ->assertDontSee('Clocked Off Rider');
    }

    public function test_a_rider_never_offered_an_order_is_not_shown_as_refusing_everything(): void
    {
        $rider = $this->rider();

        // The alternative reading of 0 offers is 0% acceptance, which would
        // put the newest rider on the roster at the top of a worst-first
        // list and send an admin after entirely the wrong person.
        $this->assertNull($rider->acceptanceRate());

        $this->actingAs($this->admin())
            ->get('/admin/riders?sort=acceptance&dir=asc')
            ->assertOk()
            ->assertSee('never offered');
    }

    public function test_the_roster_rejects_a_sort_column_it_does_not_know(): void
    {
        // Sorting is interpolated into SQL after being whitelisted, so the
        // whitelist is the only thing standing between a query string and
        // the database.
        $this->actingAs($this->admin())
            ->get('/admin/riders?sort=full_name);--')
            ->assertSessionHasErrors('sort');
    }

    // ------------------------------------------------------------- the rider

    public function test_the_rider_page_shows_the_whole_file(): void
    {
        $rider = $this->rider();

        $this->order($rider, OrderStatus::Delivered, [
            'assigned_at' => now()->subMinutes(40),
            'arrived_at' => now()->subMinutes(34),
            'picked_up_at' => now()->subMinutes(30),
            'delivered_at' => now()->subMinutes(18),
            'rider_payout' => 45.50,
        ]);

        $this->order($rider, OrderStatus::Cancelled);
        $this->order($rider, OrderStatus::PickedUp);

        $response = $this->actingAs($this->admin())
            ->get("/admin/riders/{$rider->id}")
            ->assertOk();

        $response->assertSee('Selvam K');
        $response->assertSee('TN38AB1234');

        // Every section the page exists for, named as the page names them.
        $response->assertSee('Where they are now');
        $response->assertSee('Performance');
        $response->assertSee('Earnings');
        $response->assertSee('Orders');
        $response->assertSee('Where they went');
        $response->assertSee('Sign-ins and activity');
        $response->assertSee('Registration');
    }

    public function test_the_rider_page_counts_ongoing_separately_from_finished(): void
    {
        $rider = $this->rider();

        $this->order($rider, OrderStatus::Delivered, ['delivered_at' => now()]);
        $this->order($rider, OrderStatus::Delivered, ['delivered_at' => now()]);
        $this->order($rider, OrderStatus::Cancelled);
        $this->order($rider, OrderStatus::PickedUp);
        $this->order($rider, OrderStatus::RiderAssigned);

        $response = $this->actingAs($this->admin())
            ->get("/admin/riders/{$rider->id}")
            ->assertOk();

        $counts = $response->viewData('counts');

        $this->assertSame(5, $counts['total']);
        $this->assertSame(2, $counts['delivered']);
        $this->assertSame(1, $counts['cancelled']);
        // Still in hand, however far along. An order sitting in `picked_up`
        // is the one an admin is being phoned about.
        $this->assertSame(2, $counts['ongoing']);
    }

    public function test_earned_and_paid_are_two_different_numbers(): void
    {
        $rider = $this->rider();

        $this->order($rider, OrderStatus::Delivered, [
            'delivered_at' => now()->subDays(9),
            'rider_payout' => 100,
        ]);

        $this->order($rider, OrderStatus::Delivered, [
            'delivered_at' => now()->subDay(),
            'rider_payout' => 60,
        ]);

        // A statement covering only the older delivery.
        RiderPayout::create([
            'rider_id' => $rider->id,
            'period_start' => now()->subDays(14)->toDateString(),
            'period_end' => now()->subDays(8)->toDateString(),
            'delivery_earnings' => 100,
            'gross' => 100,
            'net' => 100,
            'status' => RiderPayout::PAID,
            'paid_at' => now()->subDays(7),
        ]);

        $earnings = $this->actingAs($this->admin())
            ->get("/admin/riders/{$rider->id}")
            ->assertOk()
            ->viewData('earnings');

        $this->assertSame(160.0, $earnings['delivered_total']);
        $this->assertSame(100.0, $earnings['paid']);

        /*
         * The number a rider asking "where is my money" is actually asking
         * about: earned, and on no statement yet. Calling this "pending"
         * elsewhere would imply a transfer exists, and none does.
         */
        $this->assertSame(60.0, $earnings['since_statement']);
    }

    public function test_a_renewed_session_is_not_counted_as_a_fresh_sign_in(): void
    {
        $rider = $this->rider();

        $first = RefreshToken::create([
            'user_id' => $rider->user_id,
            'token_hash' => str_repeat('a', 64),
            'device_name' => 'Redmi 12',
            'ip_address' => '49.207.0.1',
            'expires_at' => now()->addDays(30),
            'last_used_at' => now()->subHours(2),
        ]);

        $second = RefreshToken::create([
            'user_id' => $rider->user_id,
            'token_hash' => str_repeat('b', 64),
            'device_name' => 'Redmi 12',
            'ip_address' => '49.207.0.1',
            'expires_at' => now()->addDays(30),
            'last_used_at' => now(),
        ]);

        // The app exchanged the first token for the second.
        $first->update(['replaced_by_id' => $second->id, 'revoked_at' => now()->subHours(2)]);

        $sessions = $this->actingAs($this->admin())
            ->get("/admin/riders/{$rider->id}")
            ->assertOk()
            ->viewData('sessions')
            ->keyBy('id');

        /*
         * Without this distinction an app quietly renewing its token every
         * hour reads as a rider signing in twenty times a day, and the one
         * question this section answers — when did they actually start work
         * — becomes unanswerable.
         */
        $this->assertTrue($sessions[$first->id]['is_sign_in']);
        $this->assertFalse($sessions[$second->id]['is_sign_in']);
    }

    public function test_the_page_says_so_rather_than_drawing_a_broken_map(): void
    {
        config(['services.google_maps.browser_key' => null]);

        $rider = $this->rider();

        $this->actingAs($this->admin())
            ->get("/admin/riders/{$rider->id}")
            ->assertOk()
            // The server key cannot stand in: it is IP-restricted, and a key
            // in a page is public.
            ->assertSee('GOOGLE_MAPS_BROWSER_KEY');
    }

    public function test_the_server_maps_key_is_never_rendered_into_the_page(): void
    {
        config([
            'services.google_maps.key' => 'server-only-key-must-not-leak',
            'services.google_maps.browser_key' => null,
        ]);

        $rider = $this->rider();

        $this->actingAs($this->admin())
            ->get("/admin/riders/{$rider->id}")
            ->assertOk()
            ->assertDontSee('server-only-key-must-not-leak');
    }

    public function test_the_position_endpoint_reports_staleness(): void
    {
        $rider = $this->rider([
            'last_latitude' => 9.9252,
            'last_longitude' => 78.1197,
            'last_location_at' => now()->subMinutes(10),
        ]);

        $data = $this->actingAs($this->admin())
            ->getJson("/admin/riders/{$rider->id}/position")
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['stale']);
        $this->assertSame(9.9252, $data['latitude']);

        /*
         * Which source answered. An admin looking at a dot needs to know
         * whether it is live or the last thing recorded before Redis
         * restarted, because those mean very different things about where
         * the rider is standing right now.
         */
        $this->assertSame('last recorded', $data['source']);
    }

    // -------------------------------------------------------------- the trail

    public function test_a_ping_that_moved_the_rider_is_kept(): void
    {
        $rider = $this->rider([
            'last_latitude' => 9.9252,
            'last_longitude' => 78.1197,
            'last_location_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($rider->user);

        // Roughly 300 m north.
        $this->postJson('/api/v1/rider/location', [
            'latitude' => 9.9279,
            'longitude' => 78.1197,
            'accuracy_metres' => 12,
            'speed' => 6.5,
        ])->assertOk();

        $this->assertDatabaseCount('rider_location_pings', 1);

        $ping = RiderLocationPing::first();

        $this->assertSame($rider->id, $ping->rider_id);
        $this->assertSame(12, $ping->accuracy_metres);
    }

    public function test_a_rider_standing_still_does_not_fill_the_table(): void
    {
        $rider = $this->rider([
            'last_latitude' => 9.9252,
            'last_longitude' => 78.1197,
            'last_location_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($rider->user);

        /*
         * Ten pings from a parked bike. This is the whole reason no trail was
         * stored for so long: at a ping every ten seconds, a rider waiting a
         * quarter of an hour at a counter is ninety rows that all say the
         * same thing. The gate is load-bearing — if it regresses, nothing
         * breaks visibly and the table just becomes the largest in the
         * database.
         */
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/rider/location', [
                'latitude' => 9.9252 + ($i * 0.00001),
                'longitude' => 78.1197,
                'recorded_at' => now()->addSeconds($i * 10)->toIso8601String(),
            ])->assertOk();
        }

        $this->assertDatabaseCount('rider_location_pings', 0);
    }

    public function test_the_first_fix_of_a_shift_is_always_kept(): void
    {
        // Nothing to compare against, so there is no way to know whether the
        // rider moved — and a trail that starts at the second fix starts
        // wherever they happened to be when they moved, not where they began.
        $rider = $this->rider(['last_latitude' => null, 'last_longitude' => null]);

        Sanctum::actingAs($rider->user);

        $this->postJson('/api/v1/rider/location', [
            'latitude' => 9.9252,
            'longitude' => 78.1197,
        ])->assertOk();

        $this->assertDatabaseCount('rider_location_pings', 1);
    }

    public function test_a_kept_ping_records_the_order_being_carried(): void
    {
        $rider = $this->rider([
            'last_latitude' => 9.9252,
            'last_longitude' => 78.1197,
            'last_location_at' => now()->subMinute(),
        ]);

        $order = $this->order($rider, OrderStatus::PickedUp, ['picked_up_at' => now()->subMinutes(2)]);

        Sanctum::actingAs($rider->user);

        $this->postJson('/api/v1/rider/location', [
            'latitude' => 9.9279,
            'longitude' => 78.1197,
        ])->assertOk();

        /*
         * Resolved at write time rather than inferred from timestamps later.
         * A fix taken seconds either side of a handover is otherwise
         * guesswork, and those are exactly the ones somebody comes back to
         * ask about.
         */
        $this->assertSame($order->id, RiderLocationPing::first()->order_id);
    }

    public function test_a_mock_gps_ping_leaves_no_trail(): void
    {
        $rider = $this->rider([
            'last_latitude' => 9.9252,
            'last_longitude' => 78.1197,
            'last_location_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($rider->user);

        $this->postJson('/api/v1/rider/location', [
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'is_mock' => true,
        ])->assertStatus(422);

        // A faked position must not become part of the record an admin later
        // reads as evidence of where somebody was.
        $this->assertDatabaseCount('rider_location_pings', 0);
    }

    public function test_recording_can_be_switched_off(): void
    {
        config(['tracking.history.enabled' => false]);

        $rider = $this->rider(['last_latitude' => null, 'last_longitude' => null]);

        Sanctum::actingAs($rider->user);

        $this->postJson('/api/v1/rider/location', [
            'latitude' => 9.9252,
            'longitude' => 78.1197,
        ])->assertOk();

        $this->assertDatabaseCount('rider_location_pings', 0);
    }

    public function test_the_trail_shows_one_delivery_rather_than_a_whole_day(): void
    {
        $rider = $this->rider();
        $order = $this->order($rider, OrderStatus::Delivered, ['delivered_at' => now()->subHour()]);

        // On the delivery.
        RiderLocationPing::create([
            'rider_id' => $rider->id,
            'order_id' => $order->id,
            'latitude' => 9.9252,
            'longitude' => 78.1197,
            'recorded_at' => now()->subHours(2),
        ]);

        /*
         * Idle, between orders. This is the point the narrow view exists to
         * leave out: where a rider waits is where they eat, and plausibly
         * where they live, and none of it is needed to answer a question
         * about a delivery.
         */
        RiderLocationPing::create([
            'rider_id' => $rider->id,
            'order_id' => null,
            'latitude' => 9.9400,
            'longitude' => 78.1400,
            'recorded_at' => now()->subMinutes(30),
        ]);

        $response = $this->actingAs($this->admin())
            ->get("/admin/riders/{$rider->id}")
            ->assertOk();

        $this->assertSame('order', $response->viewData('trailScope'));

        $points = $response->viewData('trail')['points'];

        $this->assertCount(1, $points);
        $this->assertSame($order->id, $points->first()->order_id);
    }

    public function test_a_whole_day_is_shown_only_when_it_is_asked_for(): void
    {
        $rider = $this->rider();
        $order = $this->order($rider, OrderStatus::Delivered, ['delivered_at' => now()->subHour()]);

        RiderLocationPing::create([
            'rider_id' => $rider->id,
            'order_id' => $order->id,
            'latitude' => 9.9252,
            'longitude' => 78.1197,
            'recorded_at' => now()->subHours(2),
        ]);

        RiderLocationPing::create([
            'rider_id' => $rider->id,
            'order_id' => null,
            'latitude' => 9.9400,
            'longitude' => 78.1400,
            'recorded_at' => now()->subMinutes(30),
        ]);

        // Kept, not removed: "where was this rider all afternoon" is fair
        // when somebody is being paid for a shift that produced nothing. It
        // is just a decision somebody makes rather than the default screen.
        $response = $this->actingAs($this->admin())
            ->get("/admin/riders/{$rider->id}?scope=day&date=".now()->toDateString())
            ->assertOk();

        $this->assertSame('day', $response->viewData('trailScope'));
        $this->assertCount(2, $response->viewData('trail')['points']);
    }

    public function test_the_day_asked_for_is_the_day_returned(): void
    {
        $rider = $this->rider();

        RiderLocationPing::create([
            'rider_id' => $rider->id,
            'latitude' => 9.9252,
            'longitude' => 78.1197,
            'recorded_at' => now()->subDays(2)->setTime(10, 15),
        ]);

        RiderLocationPing::create([
            'rider_id' => $rider->id,
            'latitude' => 9.9279,
            'longitude' => 78.1210,
            'recorded_at' => now()->setTime(9, 30),
        ]);

        $earlier = now()->subDays(2)->toDateString();

        $trail = $this->actingAs($this->admin())
            ->get("/admin/riders/{$rider->id}?scope=day&date={$earlier}")
            ->assertOk()
            ->viewData('trail');

        $this->assertCount(1, $trail['points']);
        $this->assertSame('10:15', $trail['points']->first()->recorded_at->format('H:i'));
    }

    public function test_one_riders_trail_cannot_be_read_through_another_riders_page(): void
    {
        $watched = $this->rider();
        $other = $this->rider(['full_name' => 'Anbu R']);

        $othersOrder = $this->order($other, OrderStatus::Delivered, ['delivered_at' => now()]);

        RiderLocationPing::create([
            'rider_id' => $other->id,
            'order_id' => $othersOrder->id,
            'latitude' => 13.0827,
            'longitude' => 80.2707,
            'recorded_at' => now()->subMinutes(20),
        ]);

        /*
         * The order id is guessable and pasteable, so the scoping has to be
         * through the rider's own pings rather than a query by order alone.
         */
        $trail = $this->actingAs($this->admin())
            ->get("/admin/riders/{$watched->id}?order={$othersOrder->id}")
            ->assertOk()
            ->viewData('trail');

        $this->assertCount(0, $trail['points']);
    }

    public function test_the_delivery_picker_lists_only_orders_with_something_recorded(): void
    {
        $rider = $this->rider();

        $withTrail = $this->order($rider, OrderStatus::Delivered, ['delivered_at' => now()]);
        $withoutTrail = $this->order($rider, OrderStatus::Delivered, ['delivered_at' => now()]);

        RiderLocationPing::create([
            'rider_id' => $rider->id,
            'order_id' => $withTrail->id,
            'latitude' => 9.9252,
            'longitude' => 78.1197,
            'recorded_at' => now()->subHour(),
        ]);

        $orders = $this->actingAs($this->admin())
            ->get("/admin/riders/{$rider->id}")
            ->assertOk()
            ->viewData('trailOrders');

        // Listing an order with no trail only invites a click that shows
        // nothing, and makes the page look broken rather than empty.
        $this->assertSame([$withTrail->id], $orders->pluck('order_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotContains($withoutTrail->id, $orders->pluck('order_id')->all());
    }

    // ------------------------------------------------------------- retention

    public function test_the_pruner_deletes_only_what_is_past_retention(): void
    {
        $rider = $this->rider();

        $old = RiderLocationPing::create([
            'rider_id' => $rider->id,
            'latitude' => 9.9252,
            'longitude' => 78.1197,
            'recorded_at' => now()->subDays(45),
        ]);

        $recent = RiderLocationPing::create([
            'rider_id' => $rider->id,
            'latitude' => 9.9252,
            'longitude' => 78.1197,
            'recorded_at' => now()->subDays(3),
        ]);

        $this->artisan('nexmile:prune-location-history', ['--days' => 30])
            ->assertSuccessful();

        $this->assertDatabaseMissing('rider_location_pings', ['id' => $old->id]);
        $this->assertDatabaseHas('rider_location_pings', ['id' => $recent->id]);
    }

    public function test_the_pruner_refuses_a_retention_of_zero(): void
    {
        $rider = $this->rider();

        $ping = RiderLocationPing::create([
            'rider_id' => $rider->id,
            'latitude' => 9.9252,
            'longitude' => 78.1197,
            'recorded_at' => now()->subYear(),
        ]);

        /*
         * A typo in a cron entry must not be the thing that wipes every
         * trail on the platform. There is no reading of "keep zero days"
         * that this command is the right place to act on.
         */
        $this->artisan('nexmile:prune-location-history', ['--days' => 0])
            ->assertFailed();

        $this->assertDatabaseHas('rider_location_pings', ['id' => $ping->id]);
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $rider = $this->rider();

        RiderLocationPing::create([
            'rider_id' => $rider->id,
            'latitude' => 9.9252,
            'longitude' => 78.1197,
            'recorded_at' => now()->subDays(90),
        ]);

        $this->artisan('nexmile:prune-location-history', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('rider_location_pings', 1);
    }
}
