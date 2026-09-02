<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Banner;
use App\Models\Cuisine;
use App\Models\Merchant;
use App\Models\User;
use App\Support\HomeCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Caching the home screen's merchandising.
 *
 * Banners, cuisines and collection tiles are the same for everyone and change
 * a few times a week, yet they are fetched on every app open. Everything else
 * on that screen depends on where the customer is standing and must never be
 * shared.
 */
class HomeCacheTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 9.9195;

    private const LNG = 78.1193;

    private function customer(string $suffix = '1'): User
    {
        return User::create([
            'name' => 'Customer '.$suffix,
            'phone' => '9320000'.str_pad($suffix, 3, '0', STR_PAD_LEFT),
            'email' => "hcache{$suffix}@example.in",
            'password' => 'secret',
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
        ]);
    }

    private function restaurant(float $lat, float $lng, string $name): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => 'Owner '.$n,
            'phone' => '93300000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "hcacheowner{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        return Merchant::create([
            'user_id' => $owner->id,
            'business_name' => $name,
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'latitude' => $lat,
            'longitude' => $lng,
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
        ]);
    }

    private function home(User $as, float $lat, float $lng): array
    {
        Sanctum::actingAs($as);

        return $this->getJson("/api/v1/home?latitude={$lat}&longitude={$lng}")
            ->assertOk()
            ->json('data.sections');
    }

    public function test_merchandising_is_cached_between_requests(): void
    {
        Banner::create(['image_path' => 'banners/a.png', 'alt_text' => 'First']);

        $this->home($this->customer(), self::LAT, self::LNG);

        $this->assertTrue(Cache::has(HomeCache::KEY));
    }

    public function test_editing_a_banner_clears_the_cache_immediately(): void
    {
        /*
         * An admin who uploads a banner and then waits a minute wondering
         * whether it worked has been failed by a cache they never asked for.
         */
        Banner::create(['image_path' => 'banners/a.png', 'alt_text' => 'First']);

        $this->home($this->customer(), self::LAT, self::LNG);
        $this->assertTrue(Cache::has(HomeCache::KEY));

        Banner::create(['image_path' => 'banners/b.png', 'alt_text' => 'Second']);

        $this->assertFalse(Cache::has(HomeCache::KEY));

        $sections = $this->home($this->customer('2'), self::LAT, self::LNG);
        $banners = collect($sections)->firstWhere('type', 'banners');

        $this->assertCount(2, $banners['items']);
    }

    public function test_switching_a_cuisine_off_clears_it_too(): void
    {
        // Clearing happens on the model, so a command or a support tool gets
        // it as well as the admin screen.
        Cuisine::create(['slug' => 'biryani', 'name' => 'Biryani']);

        $this->home($this->customer(), self::LAT, self::LNG);
        $this->assertTrue(Cache::has(HomeCache::KEY));

        Cuisine::sole()->update(['is_active' => false]);

        $this->assertFalse(Cache::has(HomeCache::KEY));
    }

    public function test_restaurants_are_never_served_from_another_persons_cache(): void
    {
        /*
         * The reason only the merchandising is cached. Two customers standing
         * a long way apart must not see each other's neighbourhood, however
         * much cheaper that would be.
         */
        $near = $this->restaurant(self::LAT, self::LNG, 'Madurai Mess');
        $far = $this->restaurant(13.0299, 80.1103, 'Chennai Kitchen');

        Banner::create(['image_path' => 'banners/a.png', 'alt_text' => 'Shared']);

        $maduraiSections = $this->home($this->customer(), self::LAT, self::LNG);
        $chennaiSections = $this->home($this->customer('2'), 13.0299, 80.1103);

        $names = fn (array $sections) => collect($sections)
            ->where('type', 'restaurants')
            ->flatMap(fn ($section) => collect($section['items'])->pluck('name'))
            ->unique()
            ->values()
            ->all();

        $this->assertSame([$near->business_name], $names($maduraiSections));
        $this->assertSame([$far->business_name], $names($chennaiSections));
    }

    public function test_a_second_request_still_returns_the_same_screen(): void
    {
        // A cache that changes what people see is worse than no cache.
        Banner::create(['image_path' => 'banners/a.png', 'alt_text' => 'First']);
        Cuisine::create(['slug' => 'dosa', 'name' => 'Dosa']);
        $this->restaurant(self::LAT, self::LNG, 'Madurai Mess');

        $first = $this->home($this->customer(), self::LAT, self::LNG);
        $second = $this->home($this->customer('2'), self::LAT, self::LNG);

        $this->assertSame(
            array_column($first, 'type'),
            array_column($second, 'type'),
        );
    }
}
