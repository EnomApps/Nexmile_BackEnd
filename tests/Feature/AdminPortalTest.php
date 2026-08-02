<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\KycDocument;
use App\Models\Merchant;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config(['kyc.disk' => 's3']);
    }

    private function makeUser(UserRole $role, UserStatus $status = UserStatus::Active): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'User '.$n,
            'phone' => '97000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "admin-test{$n}@example.in",
            'password' => 'AdminPass2026!',
            'role' => $role,
            'status' => $status,
        ]);
    }

    private function merchant(array $attributes = []): Merchant
    {
        $user = $this->makeUser(UserRole::Merchant, UserStatus::Pending);

        return Merchant::create(array_merge([
            'user_id' => $user->id, 'business_name' => 'Saravana Bhavan', 'owner_name' => 'Karthik',
            'address_line1' => '12 West Masi Street', 'city' => 'Madurai', 'pincode' => '625001',
            'kyc_status' => KycStatus::Submitted,
        ], $attributes));
    }

    private function document(Merchant $merchant, array $attributes = []): KycDocument
    {
        return $merchant->kycDocuments()->create(array_merge([
            'type' => DocumentType::PanCard->value,
            'status' => DocumentStatus::Pending->value,
            'disk' => 's3', 'path' => 'kyc/merchant/1/x.pdf',
            'original_name' => 'pan.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1024,
        ], $attributes));
    }

    /*
    |--------------------------------------------------------------------------
    | Creating admins
    |--------------------------------------------------------------------------
    */

    public function test_an_admin_can_be_created_from_the_console(): void
    {
        $this->artisan('nexmile:make-admin', [
            '--email' => 'ops@nexmile.in', '--name' => 'Ops', '--phone' => '9800011122',
        ])
            ->expectsQuestion('Password (at least 12 characters)', 'SuperSecret123')
            ->expectsQuestion('Confirm password', 'SuperSecret123')
            ->assertSuccessful();

        $user = User::where('email', 'ops@nexmile.in')->firstOrFail();
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
    }

    public function test_a_short_admin_password_is_refused(): void
    {
        // This account can suspend merchants and read every KYC document.
        $this->artisan('nexmile:make-admin', [
            '--email' => 'weak@nexmile.in', '--name' => 'Weak', '--phone' => '9800011133',
        ])
            ->expectsQuestion('Password (at least 12 characters)', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'weak@nexmile.in']);
    }

    public function test_mismatched_passwords_are_refused(): void
    {
        $this->artisan('nexmile:make-admin', [
            '--email' => 'typo@nexmile.in', '--name' => 'Typo', '--phone' => '9800011144',
        ])
            ->expectsQuestion('Password (at least 12 characters)', 'CorrectHorse123')
            ->expectsQuestion('Confirm password', 'CorrectHorse124')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'typo@nexmile.in']);
    }

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    */

    public function test_the_admin_area_requires_signing_in(): void
    {
        // And guests must land on the admin form, not the merchant one.
        $this->get('/admin')->assertRedirect(route('admin.login'));
    }

    public function test_an_admin_can_sign_in(): void
    {
        $admin = $this->makeUser(UserRole::Admin);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'AdminPass2026!'])
            ->assertRedirect(route('admin.index'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_merchant_cannot_sign_in_to_the_admin_area(): void
    {
        $merchant = $this->merchant();

        $this->post('/admin/login', [
            'email' => $merchant->user->email, 'password' => 'AdminPass2026!',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_signed_in_merchant_cannot_reach_admin_pages(): void
    {
        $merchant = $this->merchant();

        $this->actingAs($merchant->user)->get('/admin')->assertForbidden();
    }

    public function test_a_suspended_admin_cannot_sign_in(): void
    {
        $admin = $this->makeUser(UserRole::Admin, UserStatus::Suspended);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'AdminPass2026!'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | Queue and detail
    |--------------------------------------------------------------------------
    */

    public function test_the_queue_lists_submitted_accounts(): void
    {
        $this->merchant();
        $this->merchant(['business_name' => 'Not Submitted', 'kyc_status' => KycStatus::Pending]);

        $this->actingAs($this->makeUser(UserRole::Admin))
            ->get('/admin')
            ->assertOk()
            ->assertSee('Saravana Bhavan')
            ->assertDontSee('Not Submitted');
    }

    public function test_the_queue_can_be_filtered(): void
    {
        $this->merchant(['business_name' => 'Waiting Kitchen', 'kyc_status' => KycStatus::Pending]);

        $this->actingAs($this->makeUser(UserRole::Admin))
            ->get('/admin?status=pending')
            ->assertOk()
            ->assertSee('Waiting Kitchen');
    }

    public function test_the_detail_page_shows_documents_and_business_data(): void
    {
        $merchant = $this->merchant(['fssai_license_no' => '12345678901234']);
        $this->document($merchant);

        $this->actingAs($this->makeUser(UserRole::Admin))
            ->get("/admin/merchants/{$merchant->id}")
            ->assertOk()
            ->assertSee('Saravana Bhavan')
            ->assertSee(DocumentType::PanCard->label())
            ->assertSee('12345678901234');
    }

    public function test_an_unknown_account_type_is_rejected(): void
    {
        $this->actingAs($this->makeUser(UserRole::Admin))
            ->get('/admin/customers/1')
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Decisions
    |--------------------------------------------------------------------------
    */

    public function test_verifying_activates_the_account_and_approves_its_documents(): void
    {
        $merchant = $this->merchant();
        $this->document($merchant);
        $admin = $this->makeUser(UserRole::Admin);

        $this->actingAs($admin)
            ->post("/admin/merchants/{$merchant->id}/verify")
            ->assertRedirect();

        $merchant->refresh();
        $this->assertSame(KycStatus::Verified, $merchant->kyc_status);
        $this->assertNotNull($merchant->kyc_verified_at);
        $this->assertSame(UserStatus::Active, $merchant->user->fresh()->status);
        $this->assertSame(DocumentStatus::Approved, $merchant->kycDocuments()->first()->status);
    }

    public function test_an_account_with_a_rejected_document_cannot_be_verified(): void
    {
        $merchant = $this->merchant();
        $this->document($merchant, ['status' => DocumentStatus::Rejected->value, 'rejection_reason' => 'Blurred']);

        $this->actingAs($this->makeUser(UserRole::Admin))
            ->post("/admin/merchants/{$merchant->id}/verify")
            ->assertSessionHasErrors('verify');

        $this->assertSame(KycStatus::Submitted, $merchant->fresh()->kyc_status);
    }

    public function test_rejecting_a_document_records_who_decided(): void
    {
        $merchant = $this->merchant();
        $doc = $this->document($merchant);
        $admin = $this->makeUser(UserRole::Admin);

        $this->actingAs($admin)
            ->post("/admin/merchants/{$merchant->id}/documents/{$doc->id}", [
                'status' => 'rejected', 'rejection_reason' => 'The PAN card image is blurred.',
            ])->assertRedirect();

        $doc->refresh();
        $this->assertSame(DocumentStatus::Rejected, $doc->status);
        $this->assertSame($admin->id, $doc->reviewed_by_user_id);
        $this->assertNotNull($doc->reviewed_at);
    }

    public function test_rejecting_a_document_without_a_reason_is_refused(): void
    {
        $merchant = $this->merchant();
        $doc = $this->document($merchant);

        $this->actingAs($this->makeUser(UserRole::Admin))
            ->post("/admin/merchants/{$merchant->id}/documents/{$doc->id}", ['status' => 'rejected'])
            ->assertSessionHasErrors('rejection_reason');
    }

    public function test_rejecting_an_account_needs_an_actionable_reason(): void
    {
        $merchant = $this->merchant();
        $admin = $this->makeUser(UserRole::Admin);

        $this->actingAs($admin)
            ->post("/admin/merchants/{$merchant->id}/reject", ['reason' => 'no'])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin)
            ->post("/admin/merchants/{$merchant->id}/reject", [
                'reason' => 'The FSSAI certificate has expired. Upload a current one.',
            ])->assertRedirect();

        $this->assertSame(KycStatus::Rejected, $merchant->fresh()->kyc_status);
    }

    public function test_suspending_a_merchant_stops_orders_and_kills_sessions(): void
    {
        $merchant = $this->merchant([
            'kyc_status' => KycStatus::Verified, 'is_accepting_orders' => true,
        ]);
        $merchant->user->update(['status' => UserStatus::Active]);
        $merchant->user->createToken('device');

        $this->actingAs($this->makeUser(UserRole::Admin))
            ->post("/admin/merchants/{$merchant->id}/status", ['status' => 'suspended'])
            ->assertRedirect();

        $merchant->refresh();
        $this->assertSame(UserStatus::Suspended, $merchant->user->fresh()->status);
        $this->assertFalse($merchant->is_accepting_orders);
        $this->assertSame(0, $merchant->user->tokens()->count());
    }

    public function test_a_rider_account_can_also_be_reviewed(): void
    {
        $user = $this->makeUser(UserRole::Rider, UserStatus::Pending);
        $rider = Rider::create([
            'user_id' => $user->id, 'full_name' => 'Meena', 'kyc_status' => KycStatus::Submitted,
        ]);

        $admin = $this->makeUser(UserRole::Admin);

        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Meena');
        $this->actingAs($admin)->post("/admin/riders/{$rider->id}/verify")->assertRedirect();

        $this->assertSame(KycStatus::Verified, $rider->fresh()->kyc_status);
    }

    public function test_the_admin_area_is_not_indexed(): void
    {
        // An internal tool should not turn up in search results.
        $this->get('/admin/login')->assertOk()->assertSee('noindex', false);
    }
}
