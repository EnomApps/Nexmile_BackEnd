<?php

namespace Tests\Feature;

use App\Contracts\RoadDistanceProvider;
use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Discovery\RoadDistance\GoogleDistanceMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * How far it is to ride, as opposed to how far it looks.
 *
 * Madurai has a river and several level crossings. A restaurant nine hundred
 * metres away in a straight line can be a 1.6 km ride, and a customer told
 * "900 m" is being quoted a time on a number nothing can keep.
 *
 * The provider bills per pair, so most of what is tested here is about not
 * asking: only the page shown, cached hard, batched into one call, and never
 * allowed to make discovery worse when it fails.
 */
class RoadDistanceTest extends TestCase
{
    use RefreshDatabase;

    /** Madurai Periyar bus stand, near enough. */
    private const LAT = 9.9195;

    private const LNG = 78.1193;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'discovery.road_distance.enabled' => true,
            'discovery.road_distance.coordinate_precision' => 3,
        ]);
    }

    private function restaurant(int $metresNorth = 0): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => 'Owner '.$n,
            'phone' => '97660000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "road{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        return Merchant::create([
            'user_id' => $owner->id,
            'business_name' => 'Restaurant '.$n,
            'owner_name' => 'Owner',
            'address_line1' => '1 Main Road',
            'city' => 'Madurai',
            'pincode' => '625001',
            'latitude' => self::LAT + rad2deg($metresNorth / 6371000),
            'longitude' => self::LNG,
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
            'fssai_license_no' => '12345678901234',
            'fssai_expiry_date' => now()->addYear(),
        ]);
    }

    private function customer(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Customer '.$n,
            'phone' => '97670000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "roadcust{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);
    }

    /** A provider that answers with fixed metres and counts how often it was asked. */
    private function provider(array $metres): object
    {
        $fake = new class($metres) implements RoadDistanceProvider
        {
            public int $calls = 0;

            public int $pairs = 0;

            public function __construct(private array $metres) {}

            public function measure(float $fromLat, float $fromLng, array $destinations): array
            {
                $this->calls++;
                $this->pairs += count($destinations);

                return array_map(
                    fn (int $i) => $this->metres[$i] ?? null,
                    array_keys($destinations),
                );
            }
        };

        $this->app->instance(RoadDistanceProvider::class, $fake);

        return $fake;
    }

    private function search(): TestResponse
    {
        return $this->getJson('/api/v1/restaurants?latitude='.self::LAT.'&longitude='.self::LNG);
    }

    public function test_a_restaurant_across_the_river_reports_the_real_ride(): void
    {
        // Nine hundred metres in a straight line, a 1.6 km ride around the
        // level crossing. The second number is the one the promise rests on.
        $this->restaurant(900);
        $this->provider([1600]);

        Sanctum::actingAs($this->customer());

        $row = $this->search()->assertOk()->json('data.0');

        $this->assertSame(900, $row['distance_metres']);
        $this->assertSame(1600, $row['road_distance_metres']);
    }

    public function test_the_longer_ride_never_hides_the_restaurant(): void
    {
        /*
         * Road distance is always at least the straight line, so the haversine
         * filter has already been the generous one. The only error left is a
         * restaurant that looks nearer than it rides, and the honest fix is to
         * say so — in a town with thirty restaurants, dropping one costs the
         * customer more than four extra minutes does.
         */
        $this->restaurant(900);
        $this->provider([4000]);

        Sanctum::actingAs($this->customer());

        $this->search()->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_one_request_covers_the_whole_page(): void
    {
        foreach (range(1, 8) as $n) {
            $this->restaurant(100 * $n);
        }

        $fake = $this->provider(array_fill(0, 8, 500));

        Sanctum::actingAs($this->customer());
        $this->search()->assertOk();

        // Billed per pair, so eight restaurants must be eight destinations in
        // one request, not eight requests.
        $this->assertSame(1, $fake->calls);
        $this->assertSame(8, $fake->pairs);
    }

    public function test_the_second_search_asks_nobody(): void
    {
        $this->restaurant(400);
        $fake = $this->provider([700]);

        Sanctum::actingAs($this->customer());

        $this->search()->assertOk();
        $this->assertSame(1, $fake->calls);

        // A road network does not change between lunch and dinner.
        $this->search()->assertOk()->assertJsonPath('data.0.road_distance_metres', 700);
        $this->assertSame(1, $fake->calls);
    }

    public function test_a_customer_along_the_same_street_reuses_the_answer(): void
    {
        $this->restaurant(400);
        $fake = $this->provider([700]);

        Sanctum::actingAs($this->customer());
        $this->search()->assertOk();

        /*
         * Roughly thirty metres away — inside the hundred-metre grid the cache
         * key rounds to. Exact coordinates would mean a fresh paid lookup
         * every time a phone drifts, and a cache that never hits only costs
         * money.
         */
        $this->getJson('/api/v1/restaurants'
            .'?latitude='.(self::LAT + rad2deg(30 / 6371000))
            .'&longitude='.self::LNG)->assertOk();

        $this->assertSame(1, $fake->calls);
    }

    public function test_a_provider_that_cannot_answer_leaves_the_list_intact(): void
    {
        $this->restaurant(400);
        $this->provider([null]);

        Sanctum::actingAs($this->customer());

        // A maps outage must never empty the busiest screen in the product.
        $row = $this->search()->assertOk()->assertJsonCount(1, 'data')->json('data.0');

        /*
         * Absent rather than null, the same as when the feature is off. The
         * app gets one rule for every case it cannot distinguish anyway: use
         * road_distance_metres when it is there, fall back when it is not.
         */
        $this->assertArrayNotHasKey('road_distance_metres', $row);
        $this->assertSame(400, $row['distance_metres']);
    }

    public function test_a_failure_is_not_cached_for_a_month(): void
    {
        $this->restaurant(400);
        $fake = $this->provider([null]);

        Sanctum::actingAs($this->customer());
        $this->search()->assertOk();

        // One bad afternoon must not be remembered over a road network that
        // has not changed since the bridge was built.
        $this->travel(6)->minutes();

        $this->search()->assertOk();

        $this->assertSame(2, $fake->calls);
    }

    public function test_road_distance_is_absent_when_the_feature_is_off(): void
    {
        config(['discovery.road_distance.enabled' => false]);

        $this->restaurant(400);
        $fake = $this->provider([700]);

        Sanctum::actingAs($this->customer());

        $row = $this->search()->assertOk()->json('data.0');

        // Discovery works exactly as it did before this existed.
        $this->assertSame(0, $fake->calls);
        $this->assertArrayNotHasKey('road_distance_metres', $row);
        $this->assertSame(400, $row['distance_metres']);
    }

    // --------------------------------------------------------- the provider

    public function test_the_google_driver_reads_metres_out_of_a_matrix(): void
    {
        config(['services.google_maps.key' => 'test-key']);

        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'rows' => [['elements' => [
                    ['status' => 'OK', 'distance' => ['value' => 1600]],
                    ['status' => 'ZERO_RESULTS'],
                ]]],
            ]),
        ]);

        $metres = (new GoogleDistanceMatrix)->measure(self::LAT, self::LNG, [
            ['lat' => 9.92, 'lng' => 78.12],
            ['lat' => 9.93, 'lng' => 78.13],
        ]);

        // ZERO_RESULTS is a real answer — nowhere to drive — and is left null
        // so the straight line is used rather than a number being invented.
        $this->assertSame([1600, null], $metres);
    }

    public function test_an_exhausted_quota_is_not_read_as_a_measurement(): void
    {
        config(['services.google_maps.key' => 'test-key']);

        /*
         * Google answers 200 with a status of its own. An expired key arrives
         * looking like success, and treating it as one would mean silently
         * measuring nothing until somebody noticed.
         */
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OVER_QUERY_LIMIT',
                'error_message' => 'quota exceeded',
            ]),
        ]);

        $metres = (new GoogleDistanceMatrix)->measure(self::LAT, self::LNG, [
            ['lat' => 9.92, 'lng' => 78.12],
        ]);

        $this->assertSame([null], $metres);
    }

    public function test_no_key_means_no_request(): void
    {
        config(['services.google_maps.key' => null]);

        Http::fake();

        $metres = (new GoogleDistanceMatrix)->measure(self::LAT, self::LNG, [
            ['lat' => 9.92, 'lng' => 78.12],
        ]);

        $this->assertSame([null], $metres);
        Http::assertNothingSent();
    }

    public function test_an_unreachable_provider_is_survived(): void
    {
        config(['services.google_maps.key' => 'test-key']);

        Http::fake(fn () => throw new \RuntimeException('connection reset'));

        // A dropped connection in a shop is normal, and must not be an error
        // the customer sees.
        $this->assertSame(
            [null],
            (new GoogleDistanceMatrix)->measure(self::LAT, self::LNG, [['lat' => 9.92, 'lng' => 78.12]]),
        );
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }
}
