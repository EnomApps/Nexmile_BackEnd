<?php

namespace Tests\Feature;

use App\Contracts\RouteProvider;
use App\Models\Order;
use App\Services\LiveState\Routing\GoogleDirections;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeRouteProvider;

/**
 * Turn-by-turn directions for the rider app (EP9).
 *
 * The rider navigates inside our app now, which means we buy the turns rather
 * than Google giving them away inside their own app. Every request here is a
 * billed Directions call set against a delivery fee of about ₹29, so most of
 * what is asserted below is not the happy path — it is the cooldown and the
 * ceiling that stop one lost rider spending the margin on a delivery.
 *
 * The other half is that none of those limits may ever leave a rider
 * mid-delivery with no directions. Each one degrades to a slightly older
 * route, never to nothing.
 */
class RiderNavigationTest extends DispatchTest
{
    private FakeRouteProvider $routes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->routes = new FakeRouteProvider;

        $this->app->instance(RouteProvider::class, $this->routes);
    }

    /** An order accepted by a rider, ready to be navigated. */
    private function carrying(): array
    {
        $order = $this->readyOrder();
        $rider = $this->rider();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/accept")->assertOk();

        return [$order->fresh(), $rider];
    }

    private function ask(Order $order, string $target = 'dropoff', array $extra = [])
    {
        return $this->getJson("/api/v1/rider/orders/{$order->id}/route?".http_build_query([
            'latitude' => 9.9195,
            'longitude' => 78.1193,
            'target' => $target,
            ...$extra,
        ]));
    }

    public function test_it_gives_the_rider_the_turns_to_the_customer(): void
    {
        [$order] = $this->carrying();

        $this->ask($order)
            ->assertOk()
            ->assertJsonPath('data.target', 'dropoff')
            ->assertJsonPath('data.distance_metres', 2300)
            ->assertJsonPath('data.duration_seconds', 540)
            ->assertJsonPath('data.steps.1.instruction', 'Turn right onto 3rd Street')
            ->assertJsonPath('data.steps.1.maneuver', 'turn-right')
            ->assertJsonPath('data.steps.1.end_latitude', 9.93)
            ->assertJsonStructure([
                'data' => ['target', 'polyline', 'distance_metres', 'duration_seconds', 'steps'],
            ]);
    }

    public function test_the_first_step_has_no_turn_to_draw(): void
    {
        /*
         * "Head north on Bypass Road" is not a manoeuvre, and Google sends no
         * maneuver field for it. Passed through as null rather than guessed
         * at, because an app that assumes an icon for every step draws a
         * wrong arrow on the rider's first instruction.
         */
        [$order] = $this->carrying();

        $this->ask($order)->assertOk()->assertJsonPath('data.steps.0.maneuver', null);
    }

    public function test_pickup_routes_to_the_restaurant_not_the_customer(): void
    {
        [$order] = $this->carrying();

        $this->ask($order, 'pickup')->assertOk();

        $this->assertSame(
            [(float) $order->merchant->latitude, (float) $order->merchant->longitude],
            $this->routes->last()['to'],
            'the rider was sent to the wrong stop',
        );
    }

    public function test_dropoff_routes_to_the_delivery_address(): void
    {
        [$order] = $this->carrying();

        $this->ask($order)->assertOk();

        $this->assertSame(
            [(float) $order->delivery_latitude, (float) $order->delivery_longitude],
            $this->routes->last()['to'],
        );
    }

    public function test_the_rider_position_is_the_origin(): void
    {
        [$order] = $this->carrying();

        $this->ask($order, 'dropoff', ['latitude' => 9.9111, 'longitude' => 78.1000])->assertOk();

        $this->assertSame([9.9111, 78.1], $this->routes->last()['from']);
    }

    public function test_the_turns_come_in_the_riders_own_language(): void
    {
        /*
         * A rider who picked Tamil should not be read street names in
         * English. Taken off Accept-Language because an API request carries
         * no session, so the app is the only thing that knows.
         */
        [$order] = $this->carrying();

        $this->withHeader('Accept-Language', 'ta-IN,ta;q=0.9')->ask($order)->assertOk();

        $this->assertSame('ta', $this->routes->last()['language']);
    }

    public function test_an_unsupported_language_falls_back_rather_than_being_passed_on(): void
    {
        // Whatever the client sends, we ask Google only for a language we
        // actually ship. Null leaves it to pick, which is English here.
        [$order] = $this->carrying();

        $this->withHeader('Accept-Language', 'de-DE')->ask($order)->assertOk();

        $this->assertNull($this->routes->last()['language']);
    }

    public function test_only_the_assigned_rider_can_navigate_an_order(): void
    {
        /*
         * 404 rather than 403: another rider's order is not something to
         * confirm the existence of, and the drop address is where a customer
         * lives.
         */
        [$order] = $this->carrying();

        $other = $this->rider();
        Sanctum::actingAs($other->user);

        $this->ask($order)->assertNotFound();

        $this->assertSame(0, $this->routes->calls(), 'we paid Google for a request we refused');
    }

    public function test_a_delivered_order_is_no_longer_navigable(): void
    {
        /*
         * A rider app left open on a finished delivery would otherwise keep
         * buying directions to a doorstep it has already left — and a
         * delivered order's address stops being that rider's business.
         */
        [$order, $rider] = $this->carrying();

        Sanctum::actingAs($rider->user);
        $this->postJson("/api/v1/rider/orders/{$order->id}/pickup", [
            'pickup_code' => $order->pickup_code,
        ])->assertOk();
        $this->postJson("/api/v1/rider/orders/{$order->id}/deliver")->assertOk();

        $this->ask($order->fresh())->assertForbidden();

        $this->assertSame(0, $this->routes->calls());
    }

    public function test_a_customer_cannot_navigate_an_order(): void
    {
        [$order] = $this->carrying();

        Sanctum::actingAs($this->customer());

        $this->ask($order)->assertForbidden();
    }

    public function test_navigation_needs_a_signed_in_rider(): void
    {
        [$order] = $this->carrying();

        app('auth')->forgetGuards();

        $this->getJson("/api/v1/rider/orders/{$order->id}/route?latitude=9.9&longitude=78.1&target=dropoff")
            ->assertUnauthorized();
    }

    public function test_a_position_is_required(): void
    {
        [$order] = $this->carrying();

        $this->getJson("/api/v1/rider/orders/{$order->id}/route?target=dropoff")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_the_target_must_be_one_of_the_two_stops(): void
    {
        [$order] = $this->carrying();

        $this->ask($order, 'somewhere-else')->assertStatus(422)->assertJsonValidationErrors(['target']);
    }

    public function test_no_maps_key_is_a_503_not_a_failure(): void
    {
        /*
         * Told apart from "no route" on purpose: this one is permanent until
         * somebody configures a key, and the app shows a dotted straight line
         * rather than an error either way.
         */
        [$order] = $this->carrying();

        $this->routes->configured = false;

        $this->ask($order)->assertStatus(503);

        $this->assertSame(0, $this->routes->calls(), 'we called Google with no key configured');
    }

    public function test_nowhere_to_ride_is_a_422(): void
    {
        [$order] = $this->carrying();

        $this->routes->answer = null;

        $this->ask($order)->assertStatus(422);
    }

    public function test_an_address_with_no_coordinates_is_not_sent_to_google(): void
    {
        /*
         * The rider app hands these to the phone's own maps app, which can
         * search text. Asking Directions to route to nothing is a request
         * paid for to get an error back.
         */
        [$order] = $this->carrying();

        $order->forceFill(['delivery_latitude' => null, 'delivery_longitude' => null])->save();

        $this->ask($order->fresh())->assertStatus(422);

        $this->assertSame(0, $this->routes->calls());
    }

    public function test_asking_twice_in_a_row_is_bought_once(): void
    {
        /*
         * The cooldown. The app asks at most once every 15 seconds and this
         * window is 10, so a rider genuinely off route is never held up — it
         * catches a retry loop or two screens open on one order.
         *
         * The second answer is the same route, not an error: a held-back
         * request must not look to the rider like a failure.
         */
        [$order] = $this->carrying();

        $first = $this->ask($order)->assertOk()->json('data');
        $second = $this->ask($order)->assertOk()->json('data');

        $this->assertSame(1, $this->routes->calls(), 'the cooldown did not hold');
        $this->assertSame($first, $second);
    }

    public function test_each_stop_has_its_own_cooldown(): void
    {
        // Arriving at the restaurant and immediately navigating onward is one
        // rider asking about two different places, not a retry.
        [$order] = $this->carrying();

        $this->ask($order, 'pickup')->assertOk();
        $this->ask($order, 'dropoff')->assertOk();

        $this->assertSame(2, $this->routes->calls());
    }

    public function test_the_ceiling_stops_buying_directions_for_one_order(): void
    {
        /*
         * A rider parked just off the route, or an app retrying, can ask every
         * few seconds for a quarter of an hour. Left alone that spends more on
         * directions than the delivery earns.
         *
         * Past the ceiling the last good route keeps being served — the rider
         * still has directions, they are just not refreshed.
         */
        [$order] = $this->carrying();

        $max = (int) config('tracking.navigation.max_per_order');

        for ($i = 0; $i < $max + 4; $i++) {
            // Standing in for 10 seconds passing between asks, so this tests
            // the ceiling rather than re-testing the cooldown.
            Cache::forget("nav:wait:{$order->id}:dropoff");

            $this->ask($order)->assertOk();
        }

        $this->assertSame($max, $this->routes->calls(), 'the per-order ceiling did not hold');
    }

    public function test_a_failed_call_still_costs_the_cooldown(): void
    {
        /*
         * Otherwise a maps outage is one billed request per rider per poll:
         * the failure returns instantly, the app retries, and nothing is
         * holding the door.
         */
        [$order] = $this->carrying();

        $this->routes->answer = null;

        $this->ask($order)->assertStatus(422);
        $this->ask($order)->assertStatus(422);

        $this->assertSame(1, $this->routes->calls());
    }

    public function test_a_failure_after_a_good_route_keeps_serving_the_good_one(): void
    {
        /*
         * One refused call must not drop the rider onto a straight line in
         * the middle of a junction. The route we already bought is still a
         * better answer than none.
         */
        [$order] = $this->carrying();

        $good = $this->ask($order)->assertOk()->json('data');

        $this->routes->answer = null;
        Cache::forget("nav:wait:{$order->id}:dropoff");

        $this->assertSame($good, $this->ask($order)->assertOk()->json('data'));
    }

    public function test_the_driver_strips_googles_html_and_reads_the_steps(): void
    {
        /*
         * The real driver against a canned Directions answer. Google wraps
         * street names in markup and separates the last sentence with a div
         * and no space, so stripping tags to nothing runs words together.
         *
         * Done here rather than in the app: an app that renders our HTML is
         * an app we break by changing providers.
         */
        config(['services.google_maps.key' => 'test-key']);

        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'routes' => [[
                    'overview_polyline' => ['points' => 'ynb}Amqs}M'],
                    'legs' => [[
                        'distance' => ['value' => 2300],
                        'duration' => ['value' => 600],
                        'duration_in_traffic' => ['value' => 540],
                        'steps' => [
                            [
                                'html_instructions' => 'Head <b>north</b> on Bypass Rd',
                                'distance' => ['value' => 400],
                                'duration' => ['value' => 90],
                                'end_location' => ['lat' => 9.9255, 'lng' => 78.1198],
                            ],
                            [
                                'html_instructions' => 'Turn right onto <b>Gandhi &amp; Co St</b><div style="x">Destination on the left</div>',
                                'maneuver' => 'turn-right',
                                'distance' => ['value' => 200],
                                'duration' => ['value' => 40],
                                'end_location' => ['lat' => 9.93, 'lng' => 78.12],
                            ],
                            // No end_location: unnavigable, and passing it on
                            // with zeroes would point the camera at the sea.
                            [
                                'html_instructions' => 'Arrive',
                                'distance' => ['value' => 0],
                                'duration' => ['value' => 0],
                            ],
                        ],
                    ]],
                ]],
            ]),
        ]);

        $found = (new GoogleDirections)->navigation(9.9195, 78.1193, 9.93, 78.12, 'ta');

        $this->assertNotNull($found);
        $this->assertCount(2, $found['steps']);
        $this->assertSame('Head north on Bypass Rd', $found['steps'][0]['instruction']);
        $this->assertSame('Turn right onto Gandhi & Co St Destination on the left', $found['steps'][1]['instruction']);
        $this->assertNull($found['steps'][0]['maneuver']);
        $this->assertSame('turn-right', $found['steps'][1]['maneuver']);
        $this->assertSame(9.93, $found['steps'][1]['end_latitude']);

        // The number the rider is actually waiting out, not the free-flowing
        // road: duration_in_traffic wins where Google sends it.
        $this->assertSame(540, $found['duration_seconds']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'language=ta'));
    }

    public function test_the_driver_says_plainly_when_there_is_no_key(): void
    {
        config(['services.google_maps.key' => null]);

        $driver = new GoogleDirections;

        $this->assertFalse($driver->configured());
        $this->assertNull($driver->navigation(9.9, 78.1, 9.93, 78.12));
    }

    public function test_the_driver_treats_a_refused_key_as_no_route(): void
    {
        /*
         * Google answers 200 with a status of its own. An expired key, a
         * wrong IP restriction and an exhausted quota all arrive looking like
         * successes.
         */
        config(['services.google_maps.key' => 'test-key']);

        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'REQUEST_DENIED',
                'error_message' => 'This IP is not authorized.',
            ]),
        ]);

        $this->assertNull((new GoogleDirections)->navigation(9.9, 78.1, 9.93, 78.12));
    }
}
