<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\KycDocument;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Merchant onboarding on nexmile.in — the session-based portal, not the API.
 */
class MerchantPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config(['kyc.disk' => 's3']);
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'owner_name' => 'Karthik Raja',
            'phone' => '9876543210',
            'email' => 'karthik@saravana.in',
            'password' => 'Nexmile2026',
            'password_confirmation' => 'Nexmile2026',
            'business_name' => 'Saravana Bhavan',
            'address_line1' => '12 West Masi Street',
            'city' => 'Madurai',
            'state' => 'Tamil Nadu',
            'pincode' => '625001',
        ], $overrides);
    }

    private function merchantUser(array $merchantAttributes = []): User
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => 'Owner', 'phone' => '90000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "portal{$n}@example.in", 'password' => 'Nexmile2026',
            'role' => UserRole::Merchant, 'status' => UserStatus::Pending,
        ]);

        Merchant::create(array_merge([
            'user_id' => $user->id, 'business_name' => 'Saravana Bhavan', 'owner_name' => 'Owner',
            'address_line1' => '12 West Masi Street', 'city' => 'Madurai', 'pincode' => '625001',
        ], $merchantAttributes));

        return $user->fresh();
    }

    private function pdf(string $name = 'doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'application/pdf');
    }

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    */

    public function test_the_registration_and_login_pages_render(): void
    {
        $this->get('/merchants/register')->assertOk()->assertSee('Register your restaurant');
        $this->get('/merchants/login')->assertOk();
    }

    public function test_the_merchants_page_links_to_the_form_not_an_email(): void
    {
        // The call to action now points at the form. The footer still lists
        // business@nexmile.in as a contact, which is fine.
        $this->get('/merchants')
            ->assertOk()
            ->assertSee(route('merchants.register'), false)
            ->assertSee(route('merchants.login'), false);
    }

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    */

    public function test_registering_creates_the_account_and_signs_the_merchant_in(): void
    {
        $this->post('/merchants/register', $this->registrationPayload())
            ->assertRedirect(route('merchants.dashboard'));

        $user = User::where('email', 'karthik@saravana.in')->firstOrFail();
        $this->assertSame(UserRole::Merchant, $user->role);
        // Pending until an admin verifies the documents.
        $this->assertSame(UserStatus::Pending, $user->status);
        $this->assertSame('Saravana Bhavan', $user->merchant->business_name);
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_validates_indian_formats(): void
    {
        $this->post('/merchants/register', $this->registrationPayload([
            'phone' => '12345',
            'pincode' => '99',
            'password_confirmation' => 'different',
        ]))->assertSessionHasErrors(['phone', 'pincode', 'password']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        $this->post('/merchants/register', $this->registrationPayload())->assertRedirect();

        // Registration signs you in, and the guest middleware would bounce a
        // second attempt to the dashboard before validation ever ran.
        $this->post('/merchants/logout');

        $this->post('/merchants/register', $this->registrationPayload(['phone' => '9000011122']))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::count());
    }

    public function test_an_already_signed_in_merchant_is_sent_to_the_dashboard(): void
    {
        $this->actingAs($this->merchantUser())
            ->get('/merchants/register')
            ->assertRedirect(route('merchants.dashboard'));
    }

    /*
    |--------------------------------------------------------------------------
    | Login
    |--------------------------------------------------------------------------
    */

    public function test_a_merchant_can_sign_in_with_email_or_phone(): void
    {
        $user = $this->merchantUser();

        $this->post('/merchants/login', ['identifier' => $user->email, 'password' => 'Nexmile2026'])
            ->assertRedirect(route('merchants.dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->post('/merchants/logout');

        $this->post('/merchants/login', ['identifier' => $user->phone, 'password' => 'Nexmile2026'])
            ->assertRedirect(route('merchants.dashboard'));
    }

    public function test_wrong_credentials_are_rejected(): void
    {
        $user = $this->merchantUser();

        $this->post('/merchants/login', ['identifier' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_a_suspended_merchant_cannot_sign_in(): void
    {
        $user = $this->merchantUser();
        $user->update(['status' => UserStatus::Suspended]);

        $this->post('/merchants/login', ['identifier' => $user->email, 'password' => 'Nexmile2026'])
            ->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_a_customer_cannot_sign_in_to_the_merchant_portal(): void
    {
        $customer = User::create([
            'name' => 'Customer', 'phone' => '9111100000', 'email' => 'cust@example.in',
            'password' => 'Nexmile2026', 'role' => UserRole::Customer, 'status' => UserStatus::Active,
        ]);

        $this->post('/merchants/login', ['identifier' => $customer->email, 'password' => 'Nexmile2026'])
            ->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | Dashboard and documents
    |--------------------------------------------------------------------------
    */

    public function test_the_dashboard_requires_signing_in(): void
    {
        $this->get('/merchants/dashboard')->assertRedirect(route('merchants.login'));
    }

    public function test_the_dashboard_lists_required_documents_and_kyc_state(): void
    {
        $user = $this->merchantUser();

        $this->actingAs($user)->get('/merchants/dashboard')
            ->assertOk()
            ->assertSee('Saravana Bhavan')
            ->assertSee(DocumentType::FssaiCertificate->label())
            ->assertSee(DocumentType::PanCard->label())
            ->assertSee('Not yet submitted');
    }

    public function test_a_document_can_be_uploaded_from_the_dashboard(): void
    {
        $user = $this->merchantUser();

        $this->actingAs($user)->post('/merchants/dashboard/documents', [
            'type' => DocumentType::PanCard->value,
            'file' => $this->pdf('pan.pdf'),
        ])->assertRedirect();

        $document = KycDocument::firstOrFail();
        $this->assertSame($user->merchant->id, $document->documentable_id);
        Storage::disk('s3')->assertExists($document->path);
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        $user = $this->merchantUser();

        $this->actingAs($user)->post('/merchants/dashboard/documents', [
            'type' => DocumentType::PanCard->value,
            'file' => UploadedFile::fake()->create('huge.pdf', 6000, 'application/pdf'),
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('kyc_documents', 0);
    }

    public function test_submitting_is_blocked_until_every_required_document_is_uploaded(): void
    {
        $user = $this->merchantUser();

        $this->actingAs($user)->post('/merchants/dashboard/submit')
            ->assertSessionHasErrors('documents');

        $this->assertSame(KycStatus::Pending, $user->merchant->fresh()->kyc_status);
    }

    public function test_a_complete_merchant_can_submit_for_verification(): void
    {
        $user = $this->merchantUser();

        foreach (config('kyc.required.merchant') as $type) {
            $this->actingAs($user)->post('/merchants/dashboard/documents', [
                'type' => $type, 'file' => $this->pdf("{$type}.pdf"),
            ])->assertRedirect();
        }

        $this->actingAs($user)->post('/merchants/dashboard/submit')->assertRedirect();

        $this->assertSame(KycStatus::Submitted, $user->merchant->fresh()->kyc_status);
    }

    public function test_a_merchant_cannot_delete_another_merchants_document(): void
    {
        $victim = $this->merchantUser();
        $this->actingAs($victim)->post('/merchants/dashboard/documents', [
            'type' => DocumentType::PanCard->value, 'file' => $this->pdf(),
        ]);
        $theirs = KycDocument::firstOrFail();

        $this->actingAs($this->merchantUser())
            ->delete("/merchants/dashboard/documents/{$theirs->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('kyc_documents', ['id' => $theirs->id, 'deleted_at' => null]);
    }

    public function test_signing_out_returns_to_the_site(): void
    {
        $this->actingAs($this->merchantUser())
            ->post('/merchants/logout')
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | Localisation
    |--------------------------------------------------------------------------
    */

    public function test_the_portal_is_translated(): void
    {
        // The rest of the site switches language; the portal must follow, or
        // a Tamil visitor hits an English form and it looks broken.
        $this->get('/language/ta');
        $this->get('/merchants/register')
            ->assertOk()
            ->assertSee('உங்கள் உணவகத்தைப் பதிவு செய்யுங்கள்');

        $this->get('/language/hi');
        $this->get('/merchants/login')
            ->assertOk()
            ->assertSee('मर्चेंट साइन इन');
    }
}
