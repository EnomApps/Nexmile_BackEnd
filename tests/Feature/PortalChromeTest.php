<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a signed-in merchant sees above their order queue.
 *
 * Not the marketing site's menu. Eight links to Investors and Technology over
 * a live queue are eight invitations to leave the portal mid-shift, and the
 * way back is the browser's Back button onto a page whose CSRF token has since
 * expired — which is a 419 on the next thing they press.
 */
class PortalChromeTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'User '.$n,
            'phone' => '93220000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "chrome{$n}@example.in",
            'password' => 'secret',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function merchantUser(): User
    {
        $user = $this->user(UserRole::Merchant);

        Merchant::create([
            'user_id' => $user->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
        ]);

        return $user->fresh();
    }

    private function header(string $html): string
    {
        $from = strpos($html, '<header');
        $to = strpos($html, '</header>');

        $this->assertNotFalse($from, 'No header in the page.');

        return substr($html, $from, $to - $from);
    }

    public function test_the_marketing_menu_is_gone_once_a_merchant_signs_in(): void
    {
        $header = $this->header(
            $this->actingAs($this->merchantUser())->get('/merchants/orders')->assertOk()->getContent()
        );

        foreach ([__('site.nav.investors'), __('site.nav.technology'), __('site.nav.about')] as $label) {
            $this->assertStringNotContainsString($label, $header);
        }
    }

    public function test_the_public_site_still_has_its_menu(): void
    {
        // The change is about who is signed in, not about switching the
        // marketing site off.
        $this->get('/')
            ->assertOk()
            ->assertSee(__('site.nav.investors'), false)
            ->assertSee(__('site.nav.contact'), false);
    }

    public function test_a_signed_out_merchant_gets_the_menu_back(): void
    {
        $user = $this->merchantUser();

        $this->actingAs($user)->post('/merchants/logout');

        $this->get('/')->assertOk()->assertSee(__('site.nav.about'), false);
    }

    public function test_a_customer_browsing_the_site_is_unaffected(): void
    {
        // Only merchants work inside the portal. A customer with a login is
        // reading the marketing site like anyone else.
        $this->actingAs($this->user(UserRole::Customer))
            ->get('/')
            ->assertOk()
            ->assertSee(__('site.nav.about'), false);
    }

    public function test_the_wordmark_goes_to_the_queue_not_the_home_page(): void
    {
        // Where they were trying to get when they pressed it.
        $this->actingAs($this->merchantUser())
            ->get('/merchants/orders')
            ->assertOk()
            ->assertSee(route('merchants.dashboard'), false);
    }

    public function test_there_is_still_a_way_out(): void
    {
        /*
         * Without a visible sign-out the only exit is closing the tab, which
         * leaves the session alive on a shop computer several people use.
         */
        $this->actingAs($this->merchantUser())
            ->get('/merchants/orders')
            ->assertOk()
            ->assertSee(route('merchants.logout'), false);
    }

    public function test_language_can_still_be_changed_from_a_phone(): void
    {
        /*
         * The mobile language switcher lived inside the marketing menu, which
         * a signed-in merchant no longer has. A shopkeeper who reads Tamil
         * needs it more behind the counter than at a desk, so the remaining
         * copy has to be visible at every width rather than from `sm` up.
         */
        $html = $this->actingAs($this->merchantUser())
            ->get('/merchants/orders')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('language.switch', 'ta'), $html);
        $this->assertStringNotContainsString('hidden sm:flex items-center rounded-lg', $html);
    }

    public function test_the_marketing_footer_goes_as_well(): void
    {
        $html = $this->actingAs($this->merchantUser())
            ->get('/merchants/orders')
            ->assertOk()
            ->getContent();

        // Four columns of marketing under a live order queue is the same
        // invitation to wander off that the top bar was.
        foreach ([__('site.footer.about'), __('site.footer.investors'), __('site.footer.careers')] as $label) {
            $this->assertStringNotContainsString($label, $html);
        }
    }

    public function test_the_legal_links_stay_wherever_you_are(): void
    {
        /*
         * Not a preference. A customer is entitled to read these before
         * ordering, a merchant is trading under them, and a payment provider
         * will not activate live payments until it can find them on every
         * page — which means every page.
         */
        $html = $this->actingAs($this->merchantUser())
            ->get('/merchants/orders')
            ->assertOk()
            ->getContent();

        foreach (['terms', 'privacy', 'refunds'] as $document) {
            $this->assertStringContainsString(route($document), $html);
        }
    }

    public function test_support_is_still_reachable_from_the_portal(): void
    {
        // The one thing a working merchant might genuinely want from down
        // there is somebody to email.
        $this->actingAs($this->merchantUser())
            ->get('/merchants/orders')
            ->assertOk()
            ->assertSee(config('site.email')[0] ?? '', false);
    }

    public function test_the_public_footer_is_untouched(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('site.footer.investors'), false)
            ->assertSee(__('site.footer.careers'), false);
    }

    public function test_the_menu_script_does_not_run_without_a_menu(): void
    {
        /*
         * getElementById returns null for a button that is no longer rendered,
         * and calling addEventListener on it throws — taking every later
         * script on the page down with it, including the one that rings for a
         * new order.
         */
        $html = $this->actingAs($this->merchantUser())
            ->get('/merchants/orders')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            "document.getElementById('navToggle').addEventListener",
            $html,
        );
    }
}
