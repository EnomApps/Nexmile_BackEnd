<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MenuItem;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MerchantMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config(['media.disk' => 's3']);
    }

    private function merchantUser(array $attributes = []): User
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Owner '.$n,
            'phone' => '98770000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "menu{$n}@example.in",
            'password' => 'secret',
            'role' => UserRole::Merchant,
            'status' => UserStatus::Active,
        ]);

        Merchant::create(array_merge([
            'user_id' => $user->id,
            'business_name' => 'Anjappar '.$n,
            'owner_name' => 'Owner',
            'address_line1' => '5 Bypass Road',
            'city' => 'Madurai',
            'pincode' => '625001',
            'kyc_status' => KycStatus::Verified,
        ], $attributes));

        return $user->fresh();
    }

    /**
     * A declared-MIME fake rather than fake()->image(), which needs the GD
     * extension. The mimes rule reads the reported type, so this exercises the
     * same path.
     */
    private function photo(string $name = 'biryani.jpg'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 40, 'image/jpeg');
    }

    private function itemPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Mutton Biryani',
            'price' => 260,
            'prep_time_minutes' => 25,
        ], $overrides);
    }

    public function test_a_merchant_can_create_and_list_categories(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());

        $this->postJson('/api/v1/merchant/categories', ['name' => 'Biryani'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Biryani');

        $this->getJson('/api/v1/merchant/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.items_count', 0);
    }

    public function test_deleting_a_category_keeps_its_items(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $merchant = $user->merchant;

        $category = $merchant->categories()->create(['name' => 'Starters']);
        $item = $merchant->menuItems()->create($this->itemPayload(['category_id' => $category->id]));

        $this->deleteJson("/api/v1/merchant/categories/{$category->id}")->assertOk();

        // The dish survives; it just loses its grouping.
        $this->assertDatabaseHas('menu_items', ['id' => $item->id, 'category_id' => null, 'deleted_at' => null]);
    }

    public function test_a_merchant_can_create_an_item_with_a_photo(): void
    {
        Sanctum::actingAs($this->merchantUser());

        $response = $this->post('/api/v1/merchant/menu-items', $this->itemPayload([
            'image' => $this->photo(),
        ]), ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Mutton Biryani');

        /*
         * Money is a JSON number, and a whole-rupee price encodes as `260`,
         * not `260.0` — JSON has no way to keep the zero fraction. Clients
         * must read money as `num`, never as `double`, or a ₹260 dish throws
         * where a ₹259.50 one parses. Asserted loosely for the same reason.
         */
        $this->assertEqualsWithDelta(260, $response->json('data.price'), 0.001);

        $this->assertNotNull($response->json('data.image_url'));

        // Stored under the merchant's own prefix, with a generated name.
        $stored = MenuItem::sole()->image_path;
        $this->assertStringStartsWith('menu/', $stored);
        $this->assertStringNotContainsString('biryani', $stored);
        Storage::disk('s3')->assertExists($stored);
    }

    public function test_replacing_a_photo_removes_the_old_object(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());

        $item = $user->merchant->menuItems()->create($this->itemPayload());

        $this->post("/api/v1/merchant/menu-items/{$item->id}", [
            'image' => $this->photo('first.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $first = $item->fresh()->image_path;

        $this->post("/api/v1/merchant/menu-items/{$item->id}", [
            'image' => $this->photo('second.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $second = $item->fresh()->image_path;

        $this->assertNotSame($first, $second);
        Storage::disk('s3')->assertMissing($first);
        Storage::disk('s3')->assertExists($second);
    }

    public function test_a_merchant_cannot_file_an_item_under_another_merchants_category(): void
    {
        $other = $this->merchantUser();
        $stolen = $other->merchant->categories()->create(['name' => 'Theirs']);

        Sanctum::actingAs($this->merchantUser());

        $this->postJson('/api/v1/merchant/menu-items', $this->itemPayload(['category_id' => $stolen->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_a_merchant_cannot_read_or_edit_another_merchants_item(): void
    {
        $victim = $this->merchantUser();
        $item = $victim->merchant->menuItems()->create($this->itemPayload());

        Sanctum::actingAs($this->merchantUser());

        $this->getJson("/api/v1/merchant/menu-items/{$item->id}")->assertNotFound();
        $this->postJson("/api/v1/merchant/menu-items/{$item->id}", ['price' => 1])->assertNotFound();
        $this->deleteJson("/api/v1/merchant/menu-items/{$item->id}")->assertNotFound();

        $this->assertSame('260.00', $item->fresh()->price);
    }

    public function test_availability_can_be_toggled_on_its_own(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $item = $user->merchant->menuItems()->create($this->itemPayload());

        $this->postJson("/api/v1/merchant/menu-items/{$item->id}/availability", ['is_available' => false])
            ->assertOk()
            ->assertJsonPath('data.is_available', false);

        $this->assertFalse($item->fresh()->is_available);
    }

    public function test_a_discount_price_must_be_higher_than_the_price(): void
    {
        Sanctum::actingAs($this->merchantUser());

        $this->postJson('/api/v1/merchant/menu-items', $this->itemPayload([
            'price' => 200, 'compare_at_price' => 150,
        ]))->assertStatus(422)->assertJsonValidationErrors('compare_at_price');
    }

    public function test_gst_rate_is_limited_to_the_statutory_rates(): void
    {
        Sanctum::actingAs($this->merchantUser());

        $this->postJson('/api/v1/merchant/menu-items', $this->itemPayload(['gst_rate' => 7]))
            ->assertStatus(422)->assertJsonValidationErrors('gst_rate');
    }

    public function test_reordering_ignores_ids_belonging_to_another_merchant(): void
    {
        $victim = $this->merchantUser();
        $theirs = $victim->merchant->menuItems()->create($this->itemPayload(['sort_order' => 7]));

        Sanctum::actingAs($user = $this->merchantUser());
        $mine = $user->merchant->menuItems()->create($this->itemPayload(['sort_order' => 3]));

        $this->postJson('/api/v1/merchant/menu-items/reorder', ['ids' => [$theirs->id, $mine->id]])
            ->assertOk();

        $this->assertSame(7, $theirs->fresh()->sort_order);
        $this->assertSame(1, $mine->fresh()->sort_order);
    }

    public function test_deleting_an_item_is_a_soft_delete(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $item = $user->merchant->menuItems()->create($this->itemPayload());

        $this->deleteJson("/api/v1/merchant/menu-items/{$item->id}")->assertOk();

        // Past order lines still resolve to it.
        $this->assertSoftDeleted('menu_items', ['id' => $item->id]);
    }

    public function test_the_portal_menu_page_lists_dishes_and_toggles_them(): void
    {
        $user = $this->merchantUser();
        $item = $user->merchant->menuItems()->create($this->itemPayload());

        $this->actingAs($user)->get('/merchants/menu')
            ->assertOk()
            ->assertSee('Mutton Biryani');

        $this->actingAs($user)->post("/merchants/menu/items/{$item->id}/toggle")
            ->assertRedirect();

        $this->assertFalse($item->fresh()->is_available);
    }

    public function test_the_portal_renders_the_create_and_edit_forms(): void
    {
        $user = $this->merchantUser();
        $user->merchant->categories()->create(['name' => 'Starters']);
        $item = $user->merchant->menuItems()->create($this->itemPayload());

        $this->actingAs($user)->get('/merchants/menu/items/create')
            ->assertOk()
            ->assertSee('Starters');

        $this->actingAs($user)->get("/merchants/menu/items/{$item->id}/edit")
            ->assertOk()
            ->assertSee('Mutton Biryani');
    }

    public function test_the_portal_can_create_and_update_a_dish(): void
    {
        $user = $this->merchantUser();

        $this->actingAs($user)->post('/merchants/menu/items', $this->itemPayload([
            'image' => $this->photo(),
        ]))->assertRedirect(route('merchants.menu.index'));

        $item = MenuItem::sole();
        $this->assertSame('Mutton Biryani', $item->name);

        // PATCH over multipart works because Laravel spoofs the method on a POST.
        $this->actingAs($user)->patch("/merchants/menu/items/{$item->id}", [
            'name' => 'Mutton Biryani (Family)',
            'price' => 480,
        ])->assertRedirect(route('merchants.menu.index'));

        $this->assertSame('Mutton Biryani (Family)', $item->fresh()->name);
    }

    public function test_the_portal_rejects_another_merchants_item(): void
    {
        $victim = $this->merchantUser();
        $item = $victim->merchant->menuItems()->create($this->itemPayload());

        $this->actingAs($this->merchantUser())
            ->get("/merchants/menu/items/{$item->id}/edit")
            ->assertNotFound();
    }

    public function test_menu_endpoints_reject_anonymous_callers(): void
    {
        $this->getJson('/api/v1/merchant/menu-items')->assertUnauthorized();
        $this->getJson('/api/v1/merchant/categories')->assertUnauthorized();
    }
}
