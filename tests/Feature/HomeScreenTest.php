<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Banner;
use App\Models\Collection as CuratedCollection;
use App\Models\Cuisine;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The home screen, filters and collections (home screen v2).
 *
 * The point of these endpoints is that merchandising and filtering change from
 * the server. If the app has to be rebuilt to reorder a rail, they have failed
 * at the only thing they were for.
 */
class HomeScreenTest extends TestCase
{
    use RefreshDatabase;

    /** Madurai, and everything below sits within the delivery radius of it. */
    private const LAT = 9.9195;

    private const LNG = 78.1193;

    private function customer(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Customer '.$n,
            'phone' => '90100000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "home.customer{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);
    }

    private function restaurant(array $attributes = []): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => 'Owner '.$n,
            'phone' => '91100000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "home.owner{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        return Merchant::create([
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
            ...$attributes,
        ]);
    }

    private function query(array $extra = []): string
    {
        return http_build_query(['latitude' => self::LAT, 'longitude' => self::LNG, ...$extra]);
    }

    public function test_the_home_screen_returns_ordered_sections(): void
    {
        $this->restaurant();

        Banner::create([
            'image_path' => 'banners/one.jpg',
            'alt_text' => 'Items at 50% off',
            'action_type' => 'collection',
            'action_value' => 'under-250',
            'position' => 1,
        ]);

        Cuisine::create(['slug' => 'biryani', 'name' => 'Biryani', 'position' => 1]);

        Sanctum::actingAs($this->customer());

        $response = $this->getJson('/api/v1/home?'.$this->query())->assertOk();

        $types = array_column($response->json('data.sections'), 'type');

        // Banners first, then the cuisine rail: the order is the server's to
        // decide, which is the whole reason this endpoint exists.
        $this->assertSame('banners', $types[0]);
        $this->assertSame('cuisines', $types[1]);
        $this->assertContains('restaurants', $types);

        $response->assertJsonPath('data.sections.0.items.0.action.type', 'collection')
            ->assertJsonPath('data.sections.0.items.0.alt_text', 'Items at 50% off');
    }

    public function test_a_banner_outside_its_campaign_window_is_not_shown(): void
    {
        // A campaign that ends on its own needs nobody awake at midnight.
        $this->restaurant();

        Banner::create([
            'image_path' => 'banners/old.jpg',
            'alt_text' => 'Last week',
            'ends_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($this->customer());

        $types = array_column(
            $this->getJson('/api/v1/home?'.$this->query())->assertOk()->json('data.sections'),
            'type',
        );

        $this->assertNotContains('banners', $types);
    }

    public function test_the_restaurant_list_reports_a_total_and_what_it_applied(): void
    {
        /*
         * `total` drives "Show results (42)" on the filter sheet. Without it
         * the app cannot show a count until the sheet closes, which is the
         * entire point of that button.
         */
        $this->restaurant(['is_pure_veg' => true]);
        $this->restaurant(['is_pure_veg' => false]);

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/restaurants?'.$this->query(['veg_only' => 1]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.applied_filters.veg_only', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_sorting_by_rating_puts_unrated_restaurants_last(): void
    {
        // Unrated is not the same as bad, but it cannot outrank a kitchen that
        // has earned a score either.
        $unrated = $this->restaurant();
        $good = $this->restaurant();
        $good->forceFill(['rating' => 4.6, 'rating_count' => 12])->save();

        Sanctum::actingAs($this->customer());

        $ids = array_column(
            $this->getJson('/api/v1/restaurants?'.$this->query(['sort' => 'rating']))
                ->assertOk()->json('data'),
            'id',
        );

        $this->assertSame([$good->id, $unrated->id], $ids);
    }

    public function test_a_cost_bracket_from_the_filter_sheet_is_understood(): void
    {
        // The sheet sends "150-300"; the API also accepts cost_min/cost_max.
        $cheap = $this->restaurant(['cost_for_two' => 120]);
        $mid = $this->restaurant(['cost_for_two' => 250]);

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/restaurants?'.$this->query(['cost_for_two' => '150-300']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mid->id);

        $this->getJson('/api/v1/restaurants?'.$this->query(['cost_for_two' => '0-150']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $cheap->id);
    }

    public function test_searching_a_dish_finds_the_kitchen_that_cooks_it(): void
    {
        /*
         * The search bar says "Restaurant name or a dish". Matched against
         * available items only — sending someone to a shop for a dish that is
         * off the menu is worse than no result at all.
         */
        $merchant = $this->restaurant();
        $merchant->menuItems()->create([
            'name' => 'Mutton Biryani', 'price' => 220, 'is_available' => true,
        ]);

        $other = $this->restaurant();
        $other->menuItems()->create([
            'name' => 'Mutton Biryani', 'price' => 200, 'is_available' => false,
        ]);

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/restaurants?'.$this->query(['search' => 'biryani']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $merchant->id);
    }

    public function test_the_filter_sheet_is_defined_by_the_server(): void
    {
        Cuisine::create(['slug' => 'dosa', 'name' => 'Dosa', 'position' => 1]);

        Sanctum::actingAs($this->customer());

        $keys = array_column(
            $this->getJson('/api/v1/filters')->assertOk()->json('data'),
            'key',
        );

        // Every key is a real query parameter on GET /restaurants, so the app
        // can build the request straight from this response.
        $this->assertContains('sort', $keys);
        $this->assertContains('rating_min', $keys);
        $this->assertContains('cuisine', $keys);

        /*
         * Free delivery is a platform-wide threshold today, so a filter for it
         * would match every restaurant and narrow nothing. Not offered until
         * it can actually separate them.
         */
        $this->assertNotContains('free_delivery', $keys);
    }

    public function test_a_collection_still_respects_the_delivery_radius(): void
    {
        // Curated, but not exempt: a shop 4 km away is still 4 km away.
        $near = $this->restaurant();
        $far = $this->restaurant(['latitude' => 13.0299, 'longitude' => 80.1103]);

        $collection = CuratedCollection::create([
            'slug' => 'under-250',
            'title' => 'Meals under ₹250',
            'subtitle' => 'Full meals, nothing over ₹250',
        ]);
        $collection->merchants()->attach([$near->id, $far->id]);

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/collections/under-250?'.$this->query())
            ->assertOk()
            ->assertJsonPath('data.title', 'Meals under ₹250')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.restaurants.0.id', $near->id);
    }

    public function test_an_inactive_collection_is_a_404(): void
    {
        CuratedCollection::create(['slug' => 'gone', 'title' => 'Gone', 'is_active' => false]);

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/collections/gone?'.$this->query())->assertNotFound();
    }

    public function test_a_new_restaurant_shows_a_null_rating_not_a_zero(): void
    {
        // "0.0" reads as bad where a new kitchen is only new. The app hides
        // the badge on null.
        $this->restaurant();

        Sanctum::actingAs($this->customer());

        $this->getJson('/api/v1/restaurants?'.$this->query())
            ->assertOk()
            ->assertJsonPath('data.0.rating', null)
            ->assertJsonPath('data.0.rating_count', 0);
    }
}
