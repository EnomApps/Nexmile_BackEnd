<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Contact details are public promises. A broken one is worse than none —
 * a customer with a problem writes to it and hears nothing back.
 */
class SiteContactTest extends TestCase
{
    /**
     * The mailboxes that exist on the mail server, checked against the admin
     * console. Anything published that is not on this list bounces, and this
     * list is also the guard against someone "tidying" info.info@ back to
     * info@ because it reads like a mistake.
     */
    private const MAILBOXES = [
        'info.info@nexmile.in',
        'investor.investor@nexmile.in',
        'support.nexmile@nexmile.in',
        'ceo@nexmile.in',
        'cfo@nexmile.in',
    ];

    public function test_only_mailboxes_that_exist_are_published(): void
    {
        foreach (config('site.email') as $key => $address) {
            $this->assertContains(
                $address,
                self::MAILBOXES,
                "site.email.{$key} is {$address}, which is not a mailbox on the server — mail to it will bounce",
            );
        }
    }

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

        foreach (['support', 'info', 'investors', 'ceo', 'cfo'] as $key) {
            $page->assertSee(config("site.email.{$key}"));
        }

        // business@ was never created on the mail server, so it is not offered.
        $page->assertDontSee('business@nexmile.in');
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
