<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Resources\CategoryResource;
use App\Models\Cuisine;
use App\Models\ItemOption;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The portal is how a real merchant uses any of this — they have no app — so
 * the API alone would not have closed these gaps.
 */
class MerchantPortalStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        config(['media.disk' => 's3']);
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('logo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
    }

    private function merchantUser(): User
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Owner '.$n,
            'phone' => '97740000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "portalstore{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        Merchant::create([
            'user_id' => $user->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Owner',
            'address_line1' => '1 Main Road',
            'city' => 'Madurai',
            'pincode' => '625001',
            'kyc_status' => KycStatus::Verified,
        ]);

        return $user->fresh();
    }

    public function test_a_merchant_can_upload_a_logo_from_the_portal(): void
    {
        $user = $this->merchantUser();

        $this->actingAs($user)
            ->post('/merchants/storefront/image', ['type' => 'logo', 'file' => $this->png()])
            ->assertRedirect();

        $this->assertNotNull($user->merchant->fresh()->logo_path);
    }

    public function test_the_hours_form_saves_all_seven_days(): void
    {
        $user = $this->merchantUser();

        $days = [];
        foreach (range(0, 6) as $day) {
            $days[$day] = ['is_open' => $day === 0 ? '0' : '1', 'opens_at' => '11:00', 'closes_at' => '22:00'];
        }

        $this->actingAs($user)->post('/merchants/storefront/hours', ['days' => $days])->assertRedirect();

        $hours = $user->merchant->operatingHours()->get()->keyBy('day_of_week');
        $this->assertCount(7, $hours);
        $this->assertTrue($hours[0]->is_closed);
        $this->assertFalse($hours[1]->is_closed);
    }

    public function test_a_closed_day_keeps_its_times_for_when_it_reopens(): void
    {
        $user = $this->merchantUser();

        $this->actingAs($user)->post('/merchants/storefront/hours', [
            'days' => [3 => ['is_open' => '0', 'opens_at' => '10:30', 'closes_at' => '23:00']],
        ])->assertRedirect();

        $wednesday = $user->merchant->operatingHours()->where('day_of_week', 3)->sole();

        // Reopening the day should restore what they last used, not blanks.
        $this->assertTrue($wednesday->is_closed);
        $this->assertSame('10:30', substr((string) $wednesday->opens_at, 0, 5));
    }

    public function test_a_merchant_can_add_a_choice_group_from_the_portal(): void
    {
        $user = $this->merchantUser();
        $item = $user->merchant->menuItems()->create(['name' => 'Dosa', 'price' => 60]);

        $this->actingAs($user)->post("/merchants/menu/items/{$item->id}/options", [
            'name' => 'Spice level',
            'selection' => 'single',
            'is_required' => '1',
            'options' => [
                ['name' => 'Mild', 'price_delta' => '0'],
                ['name' => 'Extra spicy', 'price_delta' => '10'],
                // The form renders blank rows; they are not input.
                ['name' => '', 'price_delta' => ''],
                ['name' => '', 'price_delta' => ''],
            ],
        ])->assertRedirect();

        $group = $item->optionGroups()->sole();
        $this->assertSame('Spice level', $group->name);
        $this->assertSame(2, $group->options()->count());
    }

    public function test_clearing_a_name_removes_that_choice(): void
    {
        $user = $this->merchantUser();
        $item = $user->merchant->menuItems()->create(['name' => 'Dosa', 'price' => 60]);

        $this->actingAs($user)->post("/merchants/menu/items/{$item->id}/options", [
            'name' => 'Size', 'selection' => 'single',
            'options' => [['name' => 'Small', 'price_delta' => '0'], ['name' => 'Large', 'price_delta' => '30']],
        ])->assertRedirect();

        $group = $item->optionGroups()->sole();
        $keep = $group->options()->orderBy('id')->first();
        $drop = $group->options()->orderBy('id')->skip(1)->first();

        $this->actingAs($user)->patch("/merchants/menu/option-groups/{$group->id}", [
            'name' => 'Size', 'selection' => 'single',
            'options' => [
                ['id' => $keep->id, 'name' => 'Small', 'price_delta' => '0'],
                ['id' => $drop->id, 'name' => '', 'price_delta' => '30'],
            ],
        ])->assertRedirect();

        $this->assertNotNull(ItemOption::find($keep->id));
        $this->assertNull(ItemOption::find($drop->id));
    }

    public function test_the_portal_pages_load(): void
    {
        $user = $this->merchantUser();
        $item = $user->merchant->menuItems()->create(['name' => 'Dosa', 'price' => 60]);

        $this->actingAs($user)->get('/merchants/storefront')->assertOk()->assertSee('Opening hours');
        $this->actingAs($user)->get("/merchants/menu/items/{$item->id}/options")->assertOk()->assertSee('Dosa');
        $this->actingAs($user)->get('/merchants/menu')->assertOk();
    }

    public function test_another_merchants_item_is_not_reachable(): void
    {
        $victim = $this->merchantUser();
        $item = $victim->merchant->menuItems()->create(['name' => 'Secret', 'price' => 60]);

        $this->actingAs($this->merchantUser())
            ->get("/merchants/menu/items/{$item->id}/options")
            ->assertNotFound();
    }

    /*
     * Listing details. Without these three a restaurant is invisible to the
     * cuisine rail, the VEG toggle and the price filters — the filters look
     * broken when they are only unset.
     */
    public function test_a_merchant_sets_their_own_cuisine_price_and_veg_status(): void
    {
        Cuisine::create(['slug' => 'biryani', 'name' => 'Biryani']);
        Cuisine::create(['slug' => 'dosa', 'name' => 'Dosa']);

        $user = $this->merchantUser();

        $this->actingAs($user)
            ->post('/merchants/storefront/listing', [
                'cuisines' => ['biryani', 'dosa'],
                'cost_for_two' => 300,
                'is_pure_veg' => 1,
            ])
            ->assertRedirect();

        $merchant = $user->merchant->fresh();

        $this->assertSame(['biryani', 'dosa'], $merchant->cuisines);
        $this->assertSame(300, $merchant->cost_for_two);
        $this->assertTrue($merchant->is_pure_veg);
    }

    public function test_an_unknown_cuisine_slug_is_refused(): void
    {
        /*
         * A slug that matches no configured cuisine filters to nothing,
         * silently — the merchant would believe they are listed under it and
         * never appear.
         */
        $user = $this->merchantUser();

        $this->actingAs($user)
            ->post('/merchants/storefront/listing', ['cuisines' => ['not-a-cuisine']])
            ->assertSessionHasErrors('cuisines.0');

        $this->assertNull($user->merchant->fresh()->cuisines);
    }

    public function test_clearing_the_veg_flag_works(): void
    {
        // An unchecked checkbox sends nothing at all, so absence has to mean
        // false rather than "leave it as it was".
        $user = $this->merchantUser();
        $user->merchant->forceFill(['is_pure_veg' => true])->save();

        $this->actingAs($user)
            ->post('/merchants/storefront/listing', ['cost_for_two' => 200])
            ->assertRedirect();

        $this->assertFalse($user->merchant->fresh()->is_pure_veg);
    }

    public function test_a_merchant_cannot_claim_every_cuisine(): void
    {
        // Six is plenty. A restaurant listed under everything is listed under
        // nothing useful.
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $slug) {
            Cuisine::create(['slug' => $slug, 'name' => strtoupper($slug)]);
        }

        $this->actingAs($this->merchantUser())
            ->post('/merchants/storefront/listing', [
                'cuisines' => ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
            ])
            ->assertSessionHasErrors('cuisines');
    }

    public function test_a_merchant_can_photograph_a_menu_category(): void
    {
        $user = $this->merchantUser();
        $category = $user->merchant->categories()->create(['name' => 'Biryani']);

        $this->actingAs($user)
            ->post("/merchants/menu/categories/{$category->id}/image", ['image' => $this->png()])
            ->assertRedirect();

        $stored = $category->fresh()->image_path;

        $this->assertNotNull($stored);
        Storage::disk('s3')->assertExists($stored);

        // And it reaches the customer app as a URL, not a raw storage path.
        $this->assertNotNull(
            (new CategoryResource($category->fresh()))
                ->toArray(request())['image_url']
        );
    }

    public function test_removing_a_category_photo_deletes_the_file(): void
    {
        // An orphaned object is a bill nobody is watching.
        $user = $this->merchantUser();
        $category = $user->merchant->categories()->create(['name' => 'Tiffin']);

        $this->actingAs($user)
            ->post("/merchants/menu/categories/{$category->id}/image", ['image' => $this->png()]);

        $stored = $category->fresh()->image_path;

        $this->actingAs($user)
            ->delete("/merchants/menu/categories/{$category->id}/image")
            ->assertRedirect();

        $this->assertNull($category->fresh()->image_path);
        Storage::disk('s3')->assertMissing($stored);
    }

    public function test_another_merchants_category_cannot_be_photographed(): void
    {
        // The id is scoped to the signed-in merchant, so someone else's is a
        // 404 rather than an edit.
        $theirs = $this->merchantUser()->merchant->categories()->create(['name' => 'Theirs']);

        $this->actingAs($this->merchantUser())
            ->post("/merchants/menu/categories/{$theirs->id}/image", ['image' => $this->png()])
            ->assertNotFound();

        $this->assertNull($theirs->fresh()->image_path);
    }

    public function test_a_category_photo_must_be_an_image(): void
    {
        $user = $this->merchantUser();
        $category = $user->merchant->categories()->create(['name' => 'Drinks']);

        $this->actingAs($user)
            ->post("/merchants/menu/categories/{$category->id}/image", [
                'image' => UploadedFile::fake()->createWithContent('menu.pdf', '%PDF-1.4 not a photo'),
            ])
            ->assertSessionHasErrors('image');

        $this->assertNull($category->fresh()->image_path);
    }
}
