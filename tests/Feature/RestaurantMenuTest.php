<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RestaurantMenuTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Customer '.$n,
            'phone' => '97660000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "menu{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);
    }

    private function restaurant(): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => 'Owner '.$n,
            'phone' => '97670000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "menuowner{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        return Merchant::create([
            'user_id' => $owner->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Owner',
            'address_line1' => '1 Main Road',
            'city' => 'Madurai',
            'pincode' => '625001',
            'latitude' => 9.9195,
            'longitude' => 78.1193,
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
            'fssai_license_no' => '12345678901234',
            'fssai_expiry_date' => now()->addYear(),
        ]);
    }

    public function test_the_menu_returns_active_categories_with_their_items(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();

        $mains = $shop->categories()->create(['name' => 'Mains', 'sort_order' => 0]);
        $retired = $shop->categories()->create(['name' => 'Old menu', 'is_active' => false]);

        $shop->menuItems()->create([
            'category_id' => $mains->id, 'name' => 'Chicken 65', 'price' => 180, 'is_veg' => false,
        ]);
        $shop->menuItems()->create([
            'category_id' => $retired->id, 'name' => 'Discontinued', 'price' => 100,
        ]);

        $body = $this->getJson("/api/v1/restaurants/{$shop->id}/menu")->assertOk()->json();

        // A retired section is the merchant deliberately removing it.
        $this->assertSame(['Mains'], array_column($body['data']['menu'], 'name'));
        $this->assertSame('Chicken 65', $body['data']['menu'][0]['items'][0]['name']);
    }

    public function test_out_of_stock_items_are_returned_not_hidden(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();

        $category = $shop->categories()->create(['name' => 'Mains']);
        $shop->menuItems()->create([
            'category_id' => $category->id, 'name' => 'Mutton Biryani',
            'price' => 260, 'is_available' => false,
        ]);

        $item = $this->getJson("/api/v1/restaurants/{$shop->id}/menu")
            ->assertOk()
            ->json('data.menu.0.items.0');

        // Merchants toggle this mid-service; a dish vanishing confuses the
        // customer who came for it. The app shows it struck through.
        $this->assertSame('Mutton Biryani', $item['name']);
        $this->assertFalse($item['is_available']);
    }

    public function test_items_with_no_category_are_still_orderable(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();

        $shop->menuItems()->create(['name' => 'Filter Coffee', 'price' => 20]);

        $this->getJson("/api/v1/restaurants/{$shop->id}/menu")
            ->assertOk()
            ->assertJsonPath('data.menu.0.name', 'Uncategorised')
            ->assertJsonPath('data.menu.0.items.0.name', 'Filter Coffee');
    }

    public function test_a_shop_with_no_categories_still_shows_its_dishes(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();

        $shop->menuItems()->create(['name' => 'Dosa', 'price' => 50]);

        /*
         * This is the shape that lost a real merchant their whole menu: loose
         * items used to sit in a sibling key, so an app looping `menu` found
         * an empty list and showed "no dishes available" for a shop with a
         * dish on it. Everything orderable now lives under `menu`.
         */
        $body = $this->getJson("/api/v1/restaurants/{$shop->id}/menu")->assertOk()->json();

        $names = collect($body['data']['menu'])->flatMap(fn ($c) => array_column($c['items'], 'name'));

        $this->assertSame(['Dosa'], $names->all());
        $this->assertArrayNotHasKey('uncategorised', $body);
    }

    public function test_categorised_and_loose_dishes_arrive_in_one_list(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();

        $mains = $shop->categories()->create(['name' => 'Mains']);
        $shop->menuItems()->create(['category_id' => $mains->id, 'name' => 'Biryani', 'price' => 200]);
        $shop->menuItems()->create(['name' => 'Filter Coffee', 'price' => 20]);

        $body = $this->getJson("/api/v1/restaurants/{$shop->id}/menu")->assertOk()->json();

        // Real categories first, loose items in a group of their own at the end.
        $this->assertSame(['Mains', 'Uncategorised'], array_column($body['data']['menu'], 'name'));
        $this->assertNull($body['data']['menu'][1]['id']);

        $names = collect($body['data']['menu'])->flatMap(fn ($c) => array_column($c['items'], 'name'));
        $this->assertEqualsCanonicalizing(['Biryani', 'Filter Coffee'], $names->all());
    }

    public function test_a_shop_with_no_hours_configured_is_treated_as_open(): void
    {
        $shop = $this->restaurant();

        // Defaulting to closed would hide every merchant who has not filled in
        // a schedule — indistinguishable from the platform being broken.
        $this->assertTrue($shop->isWithinOperatingHours());
        $this->assertTrue($shop->isOpenNow());
    }

    public function test_operating_hours_close_the_shop_outside_the_window(): void
    {
        $shop = $this->restaurant();
        $at = Carbon::parse('2026-08-05 15:00:00'); // a Wednesday

        $shop->operatingHours()->create([
            'day_of_week' => $at->dayOfWeek, 'opens_at' => '11:00', 'closes_at' => '14:30',
        ]);

        $this->assertFalse($shop->isWithinOperatingHours($at));
        $this->assertTrue($shop->isWithinOperatingHours($at->copy()->setTime(12, 0)));
    }

    public function test_a_kitchen_open_past_midnight_is_open_all_evening(): void
    {
        $shop = $this->restaurant();
        $evening = Carbon::parse('2026-08-05 22:00:00'); // Wednesday

        $shop->operatingHours()->create([
            'day_of_week' => $evening->dayOfWeek, 'opens_at' => '18:00', 'closes_at' => '01:00',
        ]);

        // Comparing naively would call this shut during its busiest hours.
        $this->assertTrue($shop->isWithinOperatingHours($evening));
        $this->assertTrue($shop->isWithinOperatingHours($evening->copy()->setTime(0, 30)));
        $this->assertFalse($shop->isWithinOperatingHours($evening->copy()->setTime(15, 0)));
    }

    public function test_a_closed_day_shuts_the_shop(): void
    {
        $shop = $this->restaurant();
        $at = Carbon::parse('2026-08-05 12:00:00');

        $shop->operatingHours()->create([
            'day_of_week' => $at->dayOfWeek, 'opens_at' => '11:00',
            'closes_at' => '22:00', 'is_closed' => true,
        ]);

        $this->assertFalse($shop->isWithinOperatingHours($at));
    }

    public function test_the_response_separates_closed_for_the_night_from_not_taking_orders(): void
    {
        Sanctum::actingAs($this->customer());
        $shop = $this->restaurant();
        $shop->update(['is_accepting_orders' => false]);

        $body = $this->getJson("/api/v1/restaurants/{$shop->id}")->assertOk()->json('data');

        // Only one of those two is worth waiting for.
        $this->assertFalse($body['is_open']);
        $this->assertFalse($body['is_accepting_orders']);
        $this->assertTrue($body['within_operating_hours']);
    }
}
