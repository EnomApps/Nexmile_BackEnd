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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KycTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No real bucket is touched; assertions run against the fake disk.
        Storage::fake('s3');
        config(['kyc.disk' => 's3']);
    }

    private function user(UserRole $role, array $attributes = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'name' => 'Test User',
            'phone' => '98760000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "kyc{$n}@example.in",
            'password' => 'secret',
            'role' => $role,
            'status' => UserStatus::Pending,
        ], $attributes));
    }

    private function merchantUser(array $attributes = []): User
    {
        $user = $this->user(UserRole::Merchant);

        Merchant::create(array_merge([
            'user_id' => $user->id,
            'business_name' => 'Saravana Bhavan',
            'owner_name' => 'Karthik',
            'address_line1' => '12 West Masi Street',
            'city' => 'Madurai',
            'pincode' => '625001',
        ], $attributes));

        return $user->fresh();
    }

    private function riderUser(array $attributes = []): User
    {
        $user = $this->user(UserRole::Rider);
        Rider::create(array_merge(['user_id' => $user->id, 'full_name' => 'Rider'], $attributes));

        return $user->fresh();
    }

    private function adminUser(): User
    {
        return $this->user(UserRole::Admin, ['status' => UserStatus::Active]);
    }

    private function pdf(string $name = 'fssai.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 200, 'application/pdf');
    }

    private function uploadAll(string $prefix, array $types): void
    {
        foreach ($types as $type) {
            $this->postJson("/api/v1/{$prefix}/kyc/documents", [
                'type' => $type,
                'file' => $this->pdf("{$type}.pdf"),
            ])->assertCreated();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Upload
    |--------------------------------------------------------------------------
    */

    public function test_a_merchant_can_upload_a_document_and_it_lands_on_the_private_disk(): void
    {
        $user = $this->merchantUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/merchant/kyc/documents', [
            'type' => DocumentType::FssaiCertificate->value,
            'file' => $this->pdf(),
        ])->assertCreated()
            ->assertJsonPath('data.type', 'fssai_certificate')
            ->assertJsonPath('data.status', 'pending');

        $document = KycDocument::findOrFail($response->json('data.id'));
        Storage::disk('s3')->assertExists($document->path);
    }

    public function test_the_stored_path_is_never_returned(): void
    {
        Sanctum::actingAs($this->merchantUser());

        $body = $this->postJson('/api/v1/merchant/kyc/documents', [
            'type' => DocumentType::PanCard->value,
            'file' => $this->pdf('pan.pdf'),
        ])->assertCreated()->getContent();

        // With the path, anyone knowing the bucket could build a direct object
        // URL and bypass the signed-link expiry.
        $this->assertStringNotContainsString('"path"', $body);
        $this->assertStringNotContainsString('kyc/merchant/', $body);
    }

    public function test_the_original_filename_is_not_used_as_the_object_key(): void
    {
        Sanctum::actingAs($this->merchantUser());

        $id = $this->postJson('/api/v1/merchant/kyc/documents', [
            'type' => DocumentType::PanCard->value,
            'file' => UploadedFile::fake()->create('../../etc/passwd.pdf', 10, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        // A user-supplied name can carry path separators and leaks personal
        // information into the object key.
        $this->assertStringNotContainsString('passwd', KycDocument::find($id)->path);
        $this->assertStringNotContainsString('..', KycDocument::find($id)->path);
    }

    public function test_oversized_and_wrong_type_files_are_rejected(): void
    {
        Sanctum::actingAs($this->merchantUser());

        $this->postJson('/api/v1/merchant/kyc/documents', [
            'type' => DocumentType::PanCard->value,
            'file' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->postJson('/api/v1/merchant/kyc/documents', [
            'type' => DocumentType::PanCard->value,
            'file' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_a_merchant_cannot_upload_a_rider_document_type(): void
    {
        Sanctum::actingAs($this->merchantUser());

        // The reviewer's checklist depends on types matching the role.
        $this->postJson('/api/v1/merchant/kyc/documents', [
            'type' => DocumentType::DrivingLicence->value,
            'file' => $this->pdf('dl.pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_reuploading_a_type_replaces_the_previous_file(): void
    {
        Sanctum::actingAs($this->merchantUser());

        $first = $this->postJson('/api/v1/merchant/kyc/documents', [
            'type' => DocumentType::PanCard->value, 'file' => $this->pdf('pan1.pdf'),
        ])->json('data.id');

        $second = $this->postJson('/api/v1/merchant/kyc/documents', [
            'type' => DocumentType::PanCard->value, 'file' => $this->pdf('pan2.pdf'),
        ])->assertCreated()->json('data.id');

        // Three live PAN cards would leave a reviewer guessing which is current.
        $this->assertSoftDeleted('kyc_documents', ['id' => $first]);
        $this->assertNotSame($first, $second);
    }

    /*
    |--------------------------------------------------------------------------
    | Submission
    |--------------------------------------------------------------------------
    */

    public function test_submission_is_refused_while_documents_are_missing(): void
    {
        Sanctum::actingAs($this->merchantUser());

        $this->postJson('/api/v1/merchant/kyc/submit')
            ->assertStatus(422)
            ->assertJsonValidationErrors('documents');
    }

    public function test_status_lists_exactly_what_is_still_missing(): void
    {
        Sanctum::actingAs($this->merchantUser());

        $this->postJson('/api/v1/merchant/kyc/documents', [
            'type' => DocumentType::FssaiCertificate->value, 'file' => $this->pdf(),
        ])->assertCreated();

        $data = $this->getJson('/api/v1/merchant/kyc')->assertOk()->json('data');

        $this->assertFalse($data['can_submit']);
        $this->assertEqualsCanonicalizing(['pan_card', 'bank_proof'], $data['missing_documents']);
    }

    public function test_a_complete_merchant_can_submit(): void
    {
        $user = $this->merchantUser();
        Sanctum::actingAs($user);

        $this->uploadAll('merchant', config('kyc.required.merchant'));

        $this->postJson('/api/v1/merchant/kyc/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->assertSame(KycStatus::Submitted, $user->merchant->fresh()->kyc_status);
    }

    public function test_a_complete_rider_can_submit(): void
    {
        $user = $this->riderUser();
        Sanctum::actingAs($user);

        $this->uploadAll('rider', config('kyc.required.rider'));

        $this->postJson('/api/v1/rider/kyc/submit')->assertOk();
        $this->assertSame(KycStatus::Submitted, $user->rider->fresh()->kyc_status);
    }

    public function test_an_already_verified_account_cannot_resubmit(): void
    {
        $user = $this->merchantUser(['kyc_status' => KycStatus::Verified]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/merchant/kyc/submit')->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Ownership
    |--------------------------------------------------------------------------
    */

    public function test_a_merchant_cannot_delete_another_merchants_document(): void
    {
        $victim = $this->merchantUser();
        Sanctum::actingAs($victim);
        $theirs = $this->postJson('/api/v1/merchant/kyc/documents', [
            'type' => DocumentType::PanCard->value, 'file' => $this->pdf(),
        ])->json('data.id');

        Sanctum::actingAs($this->merchantUser());

        $this->deleteJson("/api/v1/merchant/kyc/documents/{$theirs}")->assertStatus(404);
        $this->assertDatabaseHas('kyc_documents', ['id' => $theirs, 'deleted_at' => null]);
    }

    public function test_an_approved_document_cannot_be_deleted_or_replaced(): void
    {
        $user = $this->merchantUser();
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/v1/merchant/kyc/documents', [
            'type' => DocumentType::PanCard->value, 'file' => $this->pdf(),
        ])->json('data.id');

        KycDocument::find($id)->update(['status' => DocumentStatus::Approved]);

        // Approved documents are the evidence behind a verification decision.
        $this->deleteJson("/api/v1/merchant/kyc/documents/{$id}")->assertStatus(422);
        $this->postJson('/api/v1/merchant/kyc/documents', [
            'type' => DocumentType::PanCard->value, 'file' => $this->pdf('new.pdf'),
        ])->assertStatus(422);
    }

    public function test_a_rider_cannot_reach_merchant_kyc_and_vice_versa(): void
    {
        Sanctum::actingAs($this->riderUser());
        $this->getJson('/api/v1/merchant/kyc')->assertStatus(403);

        Sanctum::actingAs($this->merchantUser());
        $this->getJson('/api/v1/rider/kyc')->assertStatus(403);
    }

    public function test_a_verified_rider_cannot_edit_document_numbers(): void
    {
        $user = $this->riderUser(['kyc_status' => KycStatus::Verified]);
        Sanctum::actingAs($user);

        // Swapping in a different licence after approval would defeat the check.
        $this->patchJson('/api/v1/rider/kyc/details', ['pan' => 'ABCDE1234F'])
            ->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Admin review
    |--------------------------------------------------------------------------
    */

    public function test_the_admin_queue_lists_submitted_accounts(): void
    {
        $this->merchantUser(['kyc_status' => KycStatus::Submitted]);
        $this->riderUser(['kyc_status' => KycStatus::Submitted]);
        $this->merchantUser(['kyc_status' => KycStatus::Pending]);

        Sanctum::actingAs($this->adminUser());

        $data = $this->getJson('/api/v1/admin/kyc/queue')->assertOk()->json('data');

        $this->assertSame(1, $data['counts']['merchants']);
        $this->assertSame(1, $data['counts']['riders']);
    }

    public function test_verifying_an_account_approves_its_documents_and_activates_the_user(): void
    {
        $user = $this->merchantUser();
        Sanctum::actingAs($user);
        $this->uploadAll('merchant', config('kyc.required.merchant'));
        $this->postJson('/api/v1/merchant/kyc/submit')->assertOk();
        $merchantId = $user->merchant->id;

        $admin = $this->adminUser();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/kyc/merchants/{$merchantId}/verify")->assertOk();

        $merchant = Merchant::find($merchantId);
        $this->assertSame(KycStatus::Verified, $merchant->kyc_status);
        $this->assertNotNull($merchant->kyc_verified_at);
        $this->assertSame(UserStatus::Active, $merchant->user->status);
        $this->assertSame(0, $merchant->kycDocuments()->where('status', 'pending')->count());
    }

    public function test_an_account_with_a_rejected_document_cannot_be_verified(): void
    {
        $user = $this->merchantUser();
        Sanctum::actingAs($user);
        $this->uploadAll('merchant', config('kyc.required.merchant'));
        $merchantId = $user->merchant->id;

        $document = KycDocument::first();
        $document->update(['status' => DocumentStatus::Rejected, 'rejection_reason' => 'Blurred']);

        Sanctum::actingAs($this->adminUser());

        // Approving the account while a document is rejected leaves the record
        // self-contradictory.
        $this->postJson("/api/v1/admin/kyc/merchants/{$merchantId}/verify")->assertStatus(422);
    }

    public function test_rejecting_a_document_requires_a_reason(): void
    {
        $user = $this->merchantUser();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/merchant/kyc/documents', [
            'type' => DocumentType::PanCard->value, 'file' => $this->pdf(),
        ])->json('data.id');

        $admin = $this->adminUser();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/kyc/documents/{$id}/review", ['status' => 'rejected'])
            ->assertStatus(422)->assertJsonValidationErrors('rejection_reason');

        $this->postJson("/api/v1/admin/kyc/documents/{$id}/review", [
            'status' => 'rejected', 'rejection_reason' => 'The PAN card image is blurred.',
        ])->assertOk();

        $document = KycDocument::find($id);
        $this->assertSame(DocumentStatus::Rejected, $document->status);
        // A verification is a compliance record; who decided must be answerable.
        $this->assertSame($admin->id, $document->reviewed_by_user_id);
        $this->assertNotNull($document->reviewed_at);
    }

    public function test_rejecting_an_account_requires_an_actionable_reason(): void
    {
        $user = $this->merchantUser(['kyc_status' => KycStatus::Submitted]);
        $merchantId = $user->merchant->id;
        Sanctum::actingAs($this->adminUser());

        $this->postJson("/api/v1/admin/kyc/merchants/{$merchantId}/reject", ['reason' => 'no'])
            ->assertStatus(422);

        $this->postJson("/api/v1/admin/kyc/merchants/{$merchantId}/reject", [
            'reason' => 'The FSSAI certificate has expired. Upload a current one.',
        ])->assertOk();

        $this->assertSame(KycStatus::Rejected, Merchant::find($merchantId)->kyc_status);
    }

    public function test_suspending_a_merchant_stops_orders_and_kills_sessions(): void
    {
        $user = $this->merchantUser([
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
        ]);
        $user->update(['status' => UserStatus::Active]);
        $merchantId = $user->merchant->id;
        $user->createToken('device');

        Sanctum::actingAs($this->adminUser());

        $this->postJson("/api/v1/admin/kyc/merchants/{$merchantId}/status", ['status' => 'suspended'])
            ->assertOk();

        $merchant = Merchant::find($merchantId);
        $this->assertSame(UserStatus::Suspended, $merchant->user->status);
        // A suspended merchant must stop receiving orders immediately.
        $this->assertFalse($merchant->is_accepting_orders);
        $this->assertSame(0, $merchant->user->tokens()->count());
    }

    public function test_non_admins_cannot_reach_the_review_endpoints(): void
    {
        $merchant = $this->merchantUser();
        $merchantId = $merchant->merchant->id;

        Sanctum::actingAs($merchant);
        $this->getJson('/api/v1/admin/kyc/queue')->assertStatus(403);
        $this->postJson("/api/v1/admin/kyc/merchants/{$merchantId}/verify")->assertStatus(403);

        Sanctum::actingAs($this->riderUser());
        $this->getJson('/api/v1/admin/kyc/queue')->assertStatus(403);
    }

    public function test_kyc_endpoints_reject_anonymous_callers(): void
    {
        $this->getJson('/api/v1/merchant/kyc')->assertStatus(401);
        $this->getJson('/api/v1/rider/kyc')->assertStatus(401);
        $this->getJson('/api/v1/admin/kyc/queue')->assertStatus(401);
    }
}
