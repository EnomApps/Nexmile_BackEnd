<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Banner;
use App\Models\Collection;
use App\Models\Cuisine;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Editing the customer home screen from the admin portal.
 *
 * The tables behind it exist so merchandising changes without an app release.
 * Without these screens that only moved the release from the Play Store to a
 * database client — still an engineer, at night, with production credentials.
 */
class AdminMerchandisingTest extends TestCase
{
    use RefreshDatabase;

    /** One per test — the helper is called twice in places, and phone is unique. */
    private ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
    }

    private function admin(): User
    {
        return $this->admin ??= User::create([
            'name' => 'Admin',
            'phone' => '9000000111',
            'email' => 'admin@nexmile.in',
            'password' => 'secret',
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }

    private function image(string $name = 'banner.png'): UploadedFile
    {
        // A real PNG rather than a fake: the content-based mime check is part
        // of what is being exercised.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );

        return UploadedFile::fake()->createWithContent($name, $png);
    }

    public function test_an_admin_can_add_a_banner_and_it_reaches_the_customer_app(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/home-screen/banners', [
                'image' => $this->image(),
                'alt_text' => 'Items at 50% off',
                'action_type' => 'collection',
                'action_value' => 'under-250',
                'position' => 1,
            ])
            ->assertRedirect();

        $banner = Banner::sole();

        $this->assertSame('Items at 50% off', $banner->alt_text);
        $this->assertNotSame('', $banner->image_path);
        $this->assertTrue($banner->is_active);
    }

    public function test_a_banner_that_goes_somewhere_must_say_where(): void
    {
        // An action with no target is a tap that does nothing, which looks
        // like a broken app rather than a configuration mistake.
        $this->actingAs($this->admin())
            ->post('/admin/home-screen/banners', [
                'image' => $this->image(),
                'alt_text' => 'Goes nowhere',
                'action_type' => 'collection',
            ])
            ->assertSessionHasErrors('action_value');

        $this->assertSame(0, Banner::count());
    }

    public function test_alt_text_is_required(): void
    {
        // A screen reader has nothing else to announce.
        $this->actingAs($this->admin())
            ->post('/admin/home-screen/banners', [
                'image' => $this->image(),
                'action_type' => 'none',
            ])
            ->assertSessionHasErrors('alt_text');
    }

    public function test_a_campaign_cannot_end_before_it_starts(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/home-screen/banners', [
                'image' => $this->image(),
                'alt_text' => 'Backwards',
                'action_type' => 'none',
                'starts_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
                'ends_at' => now()->addDay()->format('Y-m-d\TH:i'),
            ])
            ->assertSessionHasErrors('ends_at');
    }

    public function test_switching_a_banner_off_hides_it_without_deleting_it(): void
    {
        // A seasonal banner comes back. Deleting it means uploading it again.
        $banner = Banner::create(['image_path' => 'banners/a.png', 'alt_text' => 'Diwali']);

        $this->actingAs($this->admin())
            ->post("/admin/home-screen/banners/{$banner->id}/toggle")
            ->assertRedirect();

        $this->assertFalse($banner->fresh()->is_active);
        $this->assertNotNull(Banner::find($banner->id));
    }

    public function test_a_cuisine_slug_is_forced_into_the_shape_the_app_sends_back(): void
    {
        /*
         * The slug is matched against a restaurant's cuisines list and sent
         * back by the app as a filter value. "South Indian" typed with a
         * capital and a space matches nothing, silently.
         */
        $this->actingAs($this->admin())
            ->post('/admin/home-screen/cuisines', ['name' => 'South Indian', 'slug' => 'South Indian'])
            ->assertSessionHasErrors('slug');

        $this->actingAs($this->admin())
            ->post('/admin/home-screen/cuisines', ['name' => 'South Indian', 'slug' => 'south-indian'])
            ->assertRedirect();

        $this->assertSame('south-indian', Cuisine::sole()->slug);
    }

    public function test_a_collection_keeps_the_order_its_restaurants_were_picked_in(): void
    {
        $collection = Collection::create(['slug' => 'under-250', 'title' => 'Meals under 250']);

        $first = $this->restaurant('Amma Mess');
        $second = $this->restaurant('Konar Kadai');

        $this->actingAs($this->admin())
            ->post("/admin/home-screen/collections/{$collection->id}/merchants", [
                'merchant_ids' => [$second->id, $first->id],
            ])
            ->assertRedirect();

        $this->assertSame(
            [$second->id, $first->id],
            $collection->fresh()->merchants->pluck('id')->all(),
        );
    }

    public function test_saving_an_empty_selection_empties_the_collection(): void
    {
        // The obvious way to clear one, and it must not silently keep the old
        // list because the field was absent.
        $collection = Collection::create(['slug' => 'x', 'title' => 'X']);
        $collection->merchants()->attach($this->restaurant('Amma Mess')->id);

        $this->actingAs($this->admin())
            ->post("/admin/home-screen/collections/{$collection->id}/merchants", [])
            ->assertRedirect();

        $this->assertCount(0, $collection->fresh()->merchants);
    }

    public function test_the_page_is_admin_only(): void
    {
        $merchant = User::create([
            'name' => 'Merchant',
            'phone' => '9000000222',
            'email' => 'merchant@nexmile.in',
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        // Guest first: actingAs() persists for the rest of the test, so a
        // "signed out" request made after it is still signed in.
        $this->get('/admin/home-screen')->assertRedirect();
        $this->actingAs($merchant)->get('/admin/home-screen')->assertForbidden();
    }

    private function restaurant(string $name): Merchant
    {
        static $n = 0;
        $n++;

        $owner = User::create([
            'name' => $name,
            'phone' => '9200000'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'email' => "shop{$n}@example.in",
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
            'kyc_status' => KycStatus::Verified,
        ]);
    }
}
