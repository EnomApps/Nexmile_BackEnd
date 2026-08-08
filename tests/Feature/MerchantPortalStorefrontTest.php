<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
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
}
