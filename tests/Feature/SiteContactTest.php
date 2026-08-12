<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Contact details are public promises. A broken one is worse than none —
 * a customer with a problem writes to it and hears nothing back.
 */
class SiteContactTest extends TestCase
{
    public function test_every_configured_address_is_a_valid_email(): void
    {
        foreach (config('site.email') as $key => $address) {
            $this->assertNotFalse(
                filter_var($address, FILTER_VALIDATE_EMAIL),
                "site.email.{$key} is not a valid email address: {$address}",
            );

            // The one that started this: an address missing its @ renders as
            // plain text and a mailto link that goes nowhere.
            $this->assertStringContainsString('@', $address);
            $this->assertStringEndsWith('@nexmile.in', $address);
        }
    }

    public function test_the_contact_page_shows_every_inbox(): void
    {
        $page = $this->get('/contact')->assertOk();

        foreach (['support', 'info', 'business', 'investors', 'ceo', 'cfo'] as $key) {
            $page->assertSee(config("site.email.{$key}"));
        }
    }

    public function test_each_address_is_a_working_mailto_link(): void
    {
        $html = $this->get('/contact')->assertOk()->getContent();

        foreach (config('site.email') as $key => $address) {
            $this->assertStringContainsString(
                'mailto:'.$address,
                $html,
                "site.email.{$key} is shown but not linked",
            );
        }
    }

    public function test_the_pages_that_point_at_an_inbox_still_do(): void
    {
        // Each of these sends people to a specific mailbox; a rename that
        // missed one would leave a dead link on a page nobody checks often.
        $this->get('/delivery-partners')->assertOk()->assertSee(config('site.email.info'));
        $this->get('/investors')->assertOk()->assertSee(config('site.email.investors'));
    }
}
