<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MerchantStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        config(['media.disk' => 's3']);
    }

    /**
     * A real 1×1 PNG rather than UploadedFile::fake()->image(), which needs
     * the GD extension. This also exercises the actual mimes check, which
     * inspects file content and not the extension.
     */
    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
    }

    private function merchantUser(): User
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Owner '.$n,
            'phone' => '97690000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "store{$n}@example.in",
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
            'latitude' => 9.9195,
            'longitude' => 78.1193,
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
            'fssai_license_no' => '12345678901234',
            'fssai_expiry_date' => now()->addYear(),
        ]);

        return $user->fresh();
    }

    public function test_a_logo_can_be_uploaded_and_replaced(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());

        $this->post('/api/v1/merchant/storefront/image', [
            'type' => 'logo',
            'file' => $this->png(),
        ], ['Accept' => 'application/json'])->assertOk();

        $first = $user->merchant->fresh()->logo_path;
        $this->assertNotNull($first);
        Storage::disk('s3')->assertExists($first);

        $this->post('/api/v1/merchant/storefront/image', [
            'type' => 'logo',
            'file' => $this->png('better.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        $second = $user->merchant->fresh()->logo_path;
        $this->assertNotSame($first, $second);
        // The replaced object is cleaned up, not left paying for storage.
        Storage::disk('s3')->assertMissing($first);
    }

    public function test_logo_and_banner_do_not_overwrite_each_other(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());

        foreach (['logo', 'banner'] as $type) {
            $this->post('/api/v1/merchant/storefront/image', [
                'type' => $type,
                'file' => $this->png("{$type}.png"),
            ], ['Accept' => 'application/json'])->assertOk();
        }

        $merchant = $user->merchant->fresh();
        $this->assertNotNull($merchant->logo_path);
        $this->assertNotNull($merchant->banner_path);
        $this->assertNotSame($merchant->logo_path, $merchant->banner_path);
    }

    public function test_the_customer_sees_the_logo_it_uploaded(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());

        $this->post('/api/v1/merchant/storefront/image', [
            'type' => 'logo',
            'file' => $this->png(),
        ], ['Accept' => 'application/json'])->assertOk();

        $customer = User::create([
            'name' => 'Cust', 'phone' => '9771000001', 'email' => 'storecust@example.in',
            'password' => 'secret', 'role' => UserRole::Customer, 'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($customer);

        // Before this existed, logo_url was permanently null.
        $this->getJson("/api/v1/restaurants/{$user->merchant->id}")
            ->assertOk()
            ->assertJsonPath('data.logo_url', fn ($url) => is_string($url) && $url !== '');
    }

    public function test_a_non_image_is_refused(): void
    {
        Sanctum::actingAs($this->merchantUser());

        $this->post('/api/v1/merchant/storefront/image', [
            'type' => 'logo',
            'file' => UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_the_weekly_schedule_is_replaced_as_a_unit(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());

        $this->putJson('/api/v1/merchant/storefront/hours', [
            'hours' => [
                ['day_of_week' => 1, 'opens_at' => '11:00', 'closes_at' => '22:00'],
                ['day_of_week' => 2, 'is_closed' => true],
            ],
        ])->assertOk()->assertJsonCount(2, 'data.hours');

        // A second write replaces, never merges: a partial save could leave a
        // shop open on a day the merchant just closed.
        $this->putJson('/api/v1/merchant/storefront/hours', [
            'hours' => [['day_of_week' => 3, 'opens_at' => '09:00', 'closes_at' => '15:00']],
        ])->assertOk()->assertJsonCount(1, 'data.hours');

        $this->assertSame(1, $user->merchant->operatingHours()->count());
    }

    public function test_hours_actually_close_the_storefront_to_customers(): void
    {
        Sanctum::actingAs($user = $this->merchantUser());
        $merchant = $user->merchant;

        // Wednesday 05 Aug 2026, lunch only.
        Carbon::setTestNow(Carbon::parse('2026-08-05 20:00:00'));

        $this->putJson('/api/v1/merchant/storefront/hours', [
            'hours' => [['day_of_week' => 3, 'opens_at' => '11:00', 'closes_at' => '15:00']],
        ])->assertOk()->assertJsonPath('data.is_open_now', false);

        $customer = User::create([
            'name' => 'Cust', 'phone' => '9772000001', 'email' => 'hourscust@example.in',
            'password' => 'secret', 'role' => UserRole::Customer, 'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($customer);

        // isOpenNow() was decorative until hours could be set at all.
        $this->getJson("/api/v1/restaurants/{$merchant->id}")
            ->assertOk()
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.within_operating_hours', false)
            ->assertJsonPath('data.is_accepting_orders', true);

        Carbon::setTestNow();
    }

    public function test_a_day_cannot_be_listed_twice(): void
    {
        Sanctum::actingAs($this->merchantUser());

        $this->putJson('/api/v1/merchant/storefront/hours', [
            'hours' => [
                ['day_of_week' => 1, 'opens_at' => '09:00', 'closes_at' => '12:00'],
                ['day_of_week' => 1, 'opens_at' => '18:00', 'closes_at' => '22:00'],
            ],
        ])->assertStatus(422);
    }

    public function test_storefront_endpoints_reject_other_roles(): void
    {
        $customer = User::create([
            'name' => 'Cust', 'phone' => '9773000001', 'email' => 'nope@example.in',
            'password' => 'secret', 'role' => UserRole::Customer, 'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/merchant/storefront/hours')->assertForbidden();
        $this->putJson('/api/v1/merchant/storefront/hours', ['hours' => []])->assertForbidden();
    }
}
