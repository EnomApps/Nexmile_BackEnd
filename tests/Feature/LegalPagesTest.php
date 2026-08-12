<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Terms, privacy and refunds.
 *
 * Razorpay will not activate live payments until all three are publicly
 * reachable and linked, and a customer is entitled to read them before they
 * order. A missing one blocks trading, not just compliance.
 */
class LegalPagesTest extends TestCase
{
    public function test_all_three_documents_are_public(): void
    {
        foreach (['terms', 'privacy', 'refunds'] as $document) {
            // No sign-in: a payment provider's reviewer is not a customer.
            $this->get("/{$document}")
                ->assertOk()
                ->assertSee(config("legal.documents.{$document}.title"));
        }
    }

    public function test_every_page_links_to_them(): void
    {
        // Footer links, so a reviewer finds them from wherever they land.
        foreach (['/', '/contact', '/merchants'] as $page) {
            $html = $this->get($page)->assertOk()->getContent();

            foreach (['/terms', '/privacy', '/refunds'] as $path) {
                $this->assertStringContainsString('href="'.url($path).'"', $html, "{$page} does not link {$path}");
            }
        }
    }

    public function test_each_document_has_real_content(): void
    {
        foreach (config('legal.documents') as $slug => $doc) {
            $this->assertNotEmpty($doc['sections'], "{$slug} has no sections");

            foreach ($doc['sections'] as $section) {
                $this->assertNotEmpty($section['heading']);
                $this->assertNotEmpty($section['body']);
            }
        }
    }

    public function test_the_grievance_officer_is_published(): void
    {
        // Required by the IT Rules 2021: a named officer, contactable, on the
        // platform itself.
        $this->get('/privacy')
            ->assertOk()
            ->assertSee(config('site.email.support'))
            ->assertSee('Grievance Officer');
    }

    public function test_the_officer_block_survives_missing_details(): void
    {
        /*
         * The officer's name and registered address are not settled yet. Until
         * they are, the page must omit those rows rather than print a label
         * with nothing after it — and the contact email must still be there,
         * so a complaint always has somewhere to go.
         */
        config(['legal.grievance.name' => '', 'legal.address' => '']);

        $html = $this->get('/privacy')->assertOk()->getContent();

        $this->assertStringContainsString('Grievance Officer', $html);
        $this->assertStringContainsString(config('site.email.support'), $html);

        foreach ([__('site.legal.officer'), __('site.legal.address')] as $label) {
            $this->assertStringNotContainsString($label.'</dt>', $html, "empty row rendered for {$label}");
        }
    }

    public function test_the_refund_policy_says_what_the_system_actually_does(): void
    {
        $html = $this->get('/refunds')->assertOk()->getContent();

        /*
         * These are not decoration — each mirrors a rule enforced in code, and
         * a policy that promises something the software does not do is worse
         * than no policy.
         */
        $this->assertStringContainsString('until the restaurant accepts', $html);
        $this->assertStringContainsString('refunded in full', $html);
        $this->assertStringContainsString('original payment method', $html);
    }

    public function test_the_documents_cross_link(): void
    {
        // Someone reading the terms should reach the refund policy without
        // going hunting.
        $html = $this->get('/terms')->assertOk()->getContent();

        $this->assertStringContainsString(url('/privacy'), $html);
        $this->assertStringContainsString(url('/refunds'), $html);
    }
}
