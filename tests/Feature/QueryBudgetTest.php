<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Banner;
use App\Models\Cuisine;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * How many queries the screens people wait on actually run.
 *
 * An endpoint that costs one query per restaurant looks fine with three test
 * restaurants and falls over at forty. These budgets are deliberately close to
 * the real numbers, so adding a per-row query fails here rather than on a
 * customer's phone.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 9.9195;

    private const LNG = 78.1193;

    private function customer(): User
    {
        return User::create([
            'name' => 'Customer',
            'phone' => '9300000001',
            'email' => 'budget.customer@example.in',
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);
    }

    /** A neighbourhood, not a fixture — the cost only shows at volume. */
    private function restaurants(int $count): void
    {
        for ($n = 1; $n <= $count; $n++) {
            $owner = User::create([
                'name' => 'Owner '.$n,
                'phone' => '93100000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                'email' => "budget.owner{$n}@example.in",
                'password' => 'secret',
                'role' => UserRole::Merchant,
                'status' => UserStatus::Active,
            ]);

            $merchant = Merchant::create([
                'user_id' => $owner->id,
                'business_name' => 'Hotel '.$n,
                'owner_name' => 'Owner',
                'address_line1' => '9 Anna Salai',
                'city' => 'Madurai',
                'pincode' => '625001',
                'latitude' => self::LAT,
                'longitude' => self::LNG,
                'kyc_status' => KycStatus::Verified,
                'is_accepting_orders' => true,
                'cuisines' => ['biryani'],
                'cost_for_two' => 250,
            ]);

            $merchant->menuItems()->create([
                'name' => 'Dish '.$n, 'price' => 100, 'is_available' => true,
            ]);

            foreach (range(0, 6) as $day) {
                $merchant->operatingHours()->create([
                    'day_of_week' => $day,
                    'is_closed' => false,
                    'opens_at' => '00:00',
                    'closes_at' => '23:59',
                ]);
            }
        }
    }

    /** @return array{count: int, queries: array<int, string>} */
    private function measure(callable $call): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $call();

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return ['count' => count($log), 'queries' => array_column($log, 'query')];
    }

    public function test_the_nearby_list_does_not_cost_a_query_per_restaurant(): void
    {
        /*
         * The busiest screen in the product. A per-card query is invisible in
         * development and is the whole difference between a list that appears
         * and a list that arrives.
         */
        $this->restaurants(20);

        Sanctum::actingAs($this->customer());

        $result = $this->measure(function () {
            $this->getJson('/api/v1/restaurants?latitude='.self::LAT.'&longitude='.self::LNG)
                ->assertOk();
        });

        $this->assertLessThan(
            10,
            $result['count'],
            "The nearby list ran {$result['count']} queries for 15 cards — something is running per row.",
        );
    }

    public function test_the_home_screen_stays_within_budget(): void
    {
        // Several sections over the same restaurants. Each section rendering
        // its own copy of the per-card work is the failure mode here.
        $this->restaurants(20);

        Banner::create(['image_path' => 'banners/a.png', 'alt_text' => 'Offer']);
        Cuisine::create(['slug' => 'biryani', 'name' => 'Biryani']);

        Sanctum::actingAs($this->customer());

        $result = $this->measure(function () {
            $this->getJson('/api/v1/home?latitude='.self::LAT.'&longitude='.self::LNG)->assertOk();
        });

        $this->assertLessThan(
            35,
            $result['count'],
            "The home screen ran {$result['count']} queries — a section is repeating per-card work.",
        );
    }

    public function test_a_restaurant_menu_does_not_cost_a_query_per_dish(): void
    {
        $this->restaurants(1);

        $merchant = Merchant::sole();
        $category = $merchant->categories()->create(['name' => 'Tiffin']);

        for ($n = 0; $n < 30; $n++) {
            $merchant->menuItems()->create([
                'category_id' => $category->id,
                'name' => 'Dish '.$n,
                'price' => 60,
                'is_available' => true,
            ]);
        }

        Sanctum::actingAs($this->customer());

        $result = $this->measure(function () use ($merchant) {
            $this->getJson("/api/v1/restaurants/{$merchant->id}/menu")->assertOk();
        });

        $this->assertLessThan(
            15,
            $result['count'],
            "The menu ran {$result['count']} queries for 30 dishes.",
        );
    }
}
