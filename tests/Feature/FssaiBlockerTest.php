<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Merchant;

/**
 * Uploading the certificate and having the licence recorded are two different
 * things. A merchant looking at an approved document and being told to "update
 * your licence" has nowhere to go, and telling someone to fix something they
 * cannot reach is worse than saying nothing.
 */
class FssaiBlockerTest extends CheckoutTest
{
    private function withoutLicence(): Merchant
    {
        $shop = $this->restaurant();
        $shop->forceFill(['fssai_license_no' => null, 'fssai_expiry_date' => null])->save();
        $shop->update(['is_accepting_orders' => false]);

        return $shop->fresh();
    }

    /** @param array<string, mixed> $attributes */
    private function certificate(Merchant $shop, DocumentStatus $status): void
    {
        $shop->kycDocuments()->create([
            'type' => DocumentType::FssaiCertificate,
            'status' => $status,
            'disk' => 'local',
            'path' => 'kyc/merchant/fssai.pdf',
            'original_name' => 'Food Safety Certificate.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);
    }

    public function test_no_certificate_asks_them_to_upload_one(): void
    {
        $shop = $this->withoutLicence();

        $this->assertSame('no_document', $shop->fssaiBlocker());

        $this->actingAs($shop->user)->get('/merchants/dashboard')
            ->assertOk()
            ->assertSee('Upload your FSSAI licence certificate below');
    }

    public function test_a_certificate_under_review_says_so(): void
    {
        $shop = $this->withoutLicence();
        $this->certificate($shop, DocumentStatus::Pending);

        $this->assertSame('awaiting_review', $shop->fresh()->fssaiBlocker());
    }

    public function test_an_approved_certificate_tells_them_it_is_with_us(): void
    {
        $shop = $this->withoutLicence();
        $this->certificate($shop, DocumentStatus::Approved);

        // This is the state that produced "update your licence" — advice the
        // merchant could not act on, because only an admin can record it.
        $this->assertSame('awaiting_details', $shop->fresh()->fssaiBlocker());

        $this->actingAs($shop->user)
            ->post('/merchants/accepting-orders', ['is_accepting_orders' => 1])
            ->assertSessionHasErrors('is_accepting_orders');

        $this->actingAs($shop->user)->get('/merchants/dashboard')
            ->assertOk()
            ->assertSee('our team is recording the licence details')
            ->assertDontSee('Update it before taking orders');
    }

    public function test_a_rejected_certificate_points_at_the_note(): void
    {
        $shop = $this->withoutLicence();
        $this->certificate($shop, DocumentStatus::Rejected);

        $this->assertSame('rejected', $shop->fresh()->fssaiBlocker());
    }

    public function test_a_lapsed_licence_is_the_merchants_to_renew(): void
    {
        $shop = $this->restaurant();
        $shop->forceFill([
            'fssai_license_no' => '12345678901234',
            'fssai_expiry_date' => now()->subMonth(),
        ])->save();

        // Recorded but lapsed is the one case they can actually act on.
        $this->assertSame('expired', $shop->fresh()->fssaiBlocker());

        $this->actingAs($shop->user)->get('/merchants/dashboard')
            ->assertOk()
            ->assertSee('Renew it and upload the new certificate');
    }

    public function test_a_current_licence_blocks_nothing(): void
    {
        $shop = $this->restaurant();

        $this->assertNull($shop->fssaiBlocker());
    }

    public function test_the_warning_appears_before_they_press_the_button(): void
    {
        $shop = $this->withoutLicence();
        $this->certificate($shop, DocumentStatus::Approved);

        // Discovering you cannot open by pressing a button that refuses is a
        // poor way to find out, especially when the fix belongs to someone
        // else.
        $this->actingAs($shop->user)->get('/merchants/dashboard')
            ->assertOk()
            ->assertSee('our team is recording the licence details');
    }
}
