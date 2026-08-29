<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Seeding a restaurant a full menu.
 *
 * Forty-eight dishes through a web form is a day of typing, and a menu of
 * three dishes tests nothing — no category rail, no price filter worth
 * applying, no search that finds anything.
 */
class SeedMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('media.disk'));
    }

    private function restaurant(array $attributes = []): Merchant
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Owner '.$n,
            'phone' => '97910000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "seedmenu{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        return Merchant::create([
            'user_id' => $user->id,
            'business_name' => 'Kishore Food '.$n,
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'kyc_status' => KycStatus::Verified,
            ...$attributes,
        ]);
    }

    public function test_it_builds_a_menu_with_categories_and_photos(): void
    {
        $merchant = $this->restaurant();

        $this->artisan('nexmile:seed-menu', ['merchant' => $merchant->id, '--only' => 'Biryani'])
            ->assertSuccessful();

        $category = $merchant->categories()->where('name', 'Biryani')->sole();
        $dishes = $merchant->menuItems()->where('category_id', $category->id)->get();

        $this->assertGreaterThan(3, $dishes->count());

        $biryani = $dishes->firstWhere('name', 'Chicken Biryani');

        $this->assertNotNull($biryani);
        $this->assertFalse($biryani->is_veg);
        $this->assertTrue($biryani->is_available);
        $this->assertEqualsWithDelta(180, (float) $biryani->price, 0.01);
        // 5% is what the rest of the app assumes for prepared food.
        $this->assertEqualsWithDelta(5.00, (float) $biryani->gst_rate, 0.01);

        $this->assertNotNull($biryani->image_path);
        Storage::disk(config('media.disk'))->assertExists($biryani->image_path);
    }

    public function test_running_it_twice_does_not_duplicate_the_menu(): void
    {
        // It will be run again when categories are added, and a doubled menu
        // is worse than no menu.
        $merchant = $this->restaurant();

        $this->artisan('nexmile:seed-menu', ['merchant' => $merchant->id, '--only' => 'Beverages'])->assertSuccessful();
        $first = $merchant->menuItems()->count();

        $this->artisan('nexmile:seed-menu', ['merchant' => $merchant->id, '--only' => 'Beverages'])->assertSuccessful();

        $this->assertSame($first, $merchant->menuItems()->count());
        $this->assertSame(1, $merchant->categories()->where('name', 'Beverages')->count());
    }

    public function test_it_leaves_a_price_the_merchant_changed_alone(): void
    {
        /*
         * A merchant will re-price a seeded dish. Seeding again must not
         * quietly put our number back — only --force may.
         */
        $merchant = $this->restaurant();

        $this->artisan('nexmile:seed-menu', ['merchant' => $merchant->id, '--only' => 'Beverages'])->assertSuccessful();

        $tea = $merchant->menuItems()->where('name', 'Tea')->sole();
        $tea->forceFill(['price' => 18])->save();

        $this->artisan('nexmile:seed-menu', ['merchant' => $merchant->id, '--only' => 'Beverages'])->assertSuccessful();

        $this->assertEqualsWithDelta(18, (float) $tea->fresh()->price, 0.01);
    }

    public function test_a_pure_veg_kitchen_is_not_given_non_veg_dishes(): void
    {
        /*
         * The same rule the portal enforces. A command writing straight to the
         * model would otherwise walk around a guard the forms hold, and leave
         * a pure veg kitchen selling mutton biryani.
         */
        $merchant = $this->restaurant(['is_pure_veg' => true]);

        $this->artisan('nexmile:seed-menu', ['merchant' => $merchant->id, '--only' => 'Biryani'])
            ->assertSuccessful();

        $this->assertSame(0, $merchant->menuItems()->where('is_veg', false)->count());
        // The veg biryani still lands, or the guard would be a blocker.
        $this->assertGreaterThan(0, $merchant->menuItems()->where('is_veg', true)->count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $merchant = $this->restaurant();

        $this->artisan('nexmile:seed-menu', ['merchant' => $merchant->id, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, $merchant->menuItems()->count());
        $this->assertSame(0, $merchant->categories()->count());
    }

    public function test_a_merchant_can_be_named_rather_than_numbered(): void
    {
        // Nobody knows their merchant ids by heart.
        $merchant = $this->restaurant();

        $this->artisan('nexmile:seed-menu', ['merchant' => 'Kishore Food', '--only' => 'Breads'])
            ->assertSuccessful();

        $this->assertGreaterThan(0, $merchant->menuItems()->count());
    }

    public function test_an_unknown_merchant_fails_rather_than_seeding_the_wrong_one(): void
    {
        $this->artisan('nexmile:seed-menu', ['merchant' => 'no-such-restaurant'])
            ->assertFailed();
    }

    public function test_every_dish_in_the_manifest_has_a_photo(): void
    {
        // A missing file would create a dish with no photo, which is the state
        // the seeded menu exists to avoid.
        $dir = database_path('seeders/menu');
        $groups = json_decode(file_get_contents($dir.'/manifest.json'), true);

        $this->assertNotEmpty($groups);

        foreach ($groups as $group) {
            foreach ($group['dishes'] as $dish) {
                $this->assertFileExists($dir.'/'.$dish['slug'].'.webp', "{$dish['slug']} has no photo");
                $this->assertGreaterThan(0, $dish['price'], "{$dish['slug']} has no price");
            }
        }
    }

    public function test_the_seed_set_survives_being_used(): void
    {
        // UploadedFile moves the file it is given unless told otherwise, which
        // would empty the folder for the next merchant.
        $dir = database_path('seeders/menu');
        $before = count(glob($dir.'/*.webp'));

        $this->artisan('nexmile:seed-menu', ['merchant' => $this->restaurant()->id, '--only' => 'Desserts'])
            ->assertSuccessful();

        $this->assertSame($before, count(glob($dir.'/*.webp')));
    }
}
