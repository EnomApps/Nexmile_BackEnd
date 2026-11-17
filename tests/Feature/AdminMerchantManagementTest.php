<?php

namespace Tests\Feature;

use App\Enums\FulfilmentType;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin restaurant section.
 *
 * The rider page's counterpart and the simpler of the two, so most of what
 * is pinned here is arithmetic rather than infrastructure.
 *
 * Two figures on this page exist nowhere else on the platform, and both are
 * easy to get subtly wrong in a way nobody would notice: the rejection rate
 * must be measured against what the kitchen was actually asked to make, and
 * the prep gap must compare what was promised with what happened. A rejection
 * rate quietly flattered by abandoned checkouts would make a bad restaurant
 * look fine, which is precisely the failure this page was built to prevent.
 */
class AdminMerchantManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role, string $prefix): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => ucfirst($prefix).' '.$n,
            'phone' => '95000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "adminshop.{$prefix}{$n}@example.in",
            'password' => 'secret',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function merchant(array $attributes = []): Merchant
    {
        return Merchant::create(array_merge([
            'user_id' => $this->user(UserRole::Merchant, 'shop')->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Ponnusamy R',
            'business_phone' => '9876500011',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'latitude' => 9.9195,
            'longitude' => 78.1193,
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
            'fssai_license_no' => '12345678901234',
            'fssai_expiry_date' => now()->addYear(),
        ], $attributes));
    }

    private function order(Merchant $merchant, OrderStatus $status, array $attributes = []): Order
    {
        static $n = 0;
        $n++;

        return $merchant->orders()->create(array_merge([
            'order_number' => 'NXM'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'user_id' => $this->user(UserRole::Customer, 'cust')->id,
            'status' => $status,
            'fulfilment_type' => FulfilmentType::Delivery,
            'delivery_contact_name' => 'Meena',
            'delivery_line1' => '4 Gandhi Nagar',
            'delivery_city' => 'Madurai',
            'delivery_pincode' => '625020',
            'items_total' => 300,
            'grand_total' => 340,
            'merchant_payout' => 270,
            'placed_at' => now(),
        ], $attributes))->fresh();
    }

    private function admin(): User
    {
        return $this->user(UserRole::Admin, 'admin');
    }

    // ------------------------------------------------------------ the roster

    public function test_the_roster_is_admin_only(): void
    {
        $this->get('/admin/merchants')->assertRedirect('/admin/login');

        // A signed-in restaurant owner holds a valid session for the same
        // application, which is the case worth asserting.
        $this->actingAs($this->merchant()->user)
            ->get('/admin/merchants')
            ->assertForbidden();
    }

    public function test_the_roster_finds_a_restaurant_by_name_owner_or_licence(): void
    {
        $this->merchant(['business_name' => 'Hotel Vasanth', 'fssai_license_no' => '99999999999999']);
        $this->merchant(['business_name' => 'Anjappar', 'owner_name' => 'Kumar S', 'fssai_license_no' => '11111111111111']);

        $admin = $this->admin();

        foreach (['Vasanth', '99999999999999'] as $term) {
            $this->actingAs($admin)
                ->get('/admin/merchants?q='.urlencode($term))
                ->assertOk()
                ->assertSee('Hotel Vasanth')
                ->assertDontSee('Anjappar');
        }

        $this->actingAs($admin)
            ->get('/admin/merchants?q=Kumar')
            ->assertOk()
            ->assertSee('Anjappar')
            ->assertDontSee('Hotel Vasanth');
    }

    public function test_the_roster_separates_paused_from_closed(): void
    {
        $this->merchant(['business_name' => 'Paused Kitchen', 'is_accepting_orders' => false]);
        $this->merchant(['business_name' => 'Open Kitchen', 'is_accepting_orders' => true]);

        /*
         * The whole reason this page exists. A customer sees nothing in both
         * cases, but "the owner switched it off" and "they are shut until
         * five" need completely different answers on the phone.
         */
        $this->actingAs($this->admin())
            ->get('/admin/merchants?state=paused')
            ->assertOk()
            ->assertSee('Paused Kitchen')
            ->assertDontSee('Open Kitchen');
    }

    public function test_the_roster_rejects_a_sort_column_it_does_not_know(): void
    {
        // Sorting is interpolated into SQL after whitelisting, so the
        // whitelist is all that stands between a query string and the
        // database.
        $this->actingAs($this->admin())
            ->get('/admin/merchants?sort=business_name);--')
            ->assertSessionHasErrors('sort');
    }

    public function test_a_restaurant_with_nothing_to_reject_is_not_shown_as_a_rejecter(): void
    {
        $this->merchant(['business_name' => 'Brand New Kitchen']);

        $this->actingAs($this->admin())
            ->get('/admin/merchants?sort=rejects&dir=desc')
            ->assertOk()
            ->assertSee('none yet');
    }

    // -------------------------------------------------------- the restaurant

    public function test_the_page_shows_the_whole_file(): void
    {
        $merchant = $this->merchant();

        $this->order($merchant, OrderStatus::Delivered, [
            'accepted_at' => now()->subMinutes(30),
            'ready_at' => now()->subMinutes(14),
            'delivered_at' => now()->subMinutes(5),
            'estimated_prep_minutes' => 15,
        ]);

        $response = $this->actingAs($this->admin())
            ->get("/admin/merchants/{$merchant->id}")
            ->assertOk();

        $response->assertSee('Ponnusamy Hotel');
        $response->assertSee('Can it take an order right now?');
        $response->assertSee('Performance');
        $response->assertSee('Earnings');
        $response->assertSee('Orders');
        $response->assertSee('Sign-ins and activity');
        $response->assertSee('Registration');

        // The two that moved here from the old verification screen. Taking
        // that URL would not have been acceptable without them.
        $response->assertSee('Food licence');
        $response->assertSee('Commercial terms');
    }

    public function test_the_old_verification_page_assertions_still_hold(): void
    {
        // AdminPortalTest reaches this same URL. The page behind it changed;
        // what an admin can see and do on it must not have.
        $waiting = $this->merchant([
            'business_name' => 'Waiting Kitchen',
            'kyc_status' => KycStatus::Submitted,
            'fssai_license_no' => '12345678901234',
        ]);

        $this->actingAs($this->admin())
            ->get("/admin/merchants/{$waiting->id}")
            ->assertOk()
            ->assertSee('Waiting Kitchen')
            ->assertSee('12345678901234')
            ->assertSee('Verify restaurant');

        /*
         * And gone once there is nothing to verify. Offering "Verify" on an
         * already-verified account invites a click that re-approves every
         * document and re-stamps kyc_verified_at for no reason.
         */
        $done = $this->merchant(['business_name' => 'Done Kitchen']);

        $this->actingAs($this->admin())
            ->get("/admin/merchants/{$done->id}")
            ->assertOk()
            ->assertDontSee('Verify restaurant');
    }

    public function test_the_rejection_rate_ignores_checkouts_the_kitchen_never_saw(): void
    {
        $merchant = $this->merchant();

        $this->order($merchant, OrderStatus::Delivered, ['delivered_at' => now()]);
        $this->order($merchant, OrderStatus::Delivered, ['delivered_at' => now()]);
        $this->order($merchant, OrderStatus::Delivered, ['delivered_at' => now()]);
        $this->order($merchant, OrderStatus::Rejected);

        /*
         * An abandoned checkout never reached the kitchen. Counting it in
         * the denominator would quietly improve the rate of every restaurant
         * on the platform — most of all the ones customers give up on.
         */
        $this->order($merchant, OrderStatus::PendingPayment);
        $this->order($merchant, OrderStatus::PendingPayment);

        $performance = $this->actingAs($this->admin())
            ->get("/admin/merchants/{$merchant->id}")
            ->assertOk()
            ->viewData('performance');

        $this->assertSame(4, $performance['orders_asked']);
        $this->assertSame(1, $performance['rejected']);
        $this->assertSame(25.0, $performance['rejection_rate']);
    }

    public function test_the_prep_gap_compares_what_was_promised_with_what_happened(): void
    {
        $merchant = $this->merchant();

        // Promised 10 minutes, took 20, three times over.
        foreach (range(1, 3) as $i) {
            $this->order($merchant, OrderStatus::Delivered, [
                'accepted_at' => now()->subMinutes(60 * $i),
                'ready_at' => now()->subMinutes(60 * $i - 20),
                'delivered_at' => now()->subMinutes(60 * $i - 30),
                'estimated_prep_minutes' => 10,
            ]);
        }

        $performance = $this->actingAs($this->admin())
            ->get("/admin/merchants/{$merchant->id}")
            ->assertOk()
            ->viewData('performance');

        $this->assertSame(10.0, $performance['minutes_prep_promised']);
        $this->assertSame(20.0, $performance['minutes_prep_actual']);

        /*
         * Ten minutes out, in the same direction, on every order. That is
         * every arrival time this restaurant's customers are quoted being
         * wrong all day — and it is invisible on every other screen.
         */
        $this->assertSame(10.0, $performance['prep_gap']);
    }

    public function test_a_restaurant_with_no_deliveries_shows_dashes_not_zeroes(): void
    {
        $merchant = $this->merchant();

        $performance = $this->actingAs($this->admin())
            ->get("/admin/merchants/{$merchant->id}")
            ->assertOk()
            ->viewData('performance');

        // Zero would read as "instant" rather than "never measured", and the
        // two get acted on very differently.
        $this->assertNull($performance['minutes_prep_actual']);
        $this->assertNull($performance['prep_gap']);
        $this->assertNull($performance['rejection_rate']);
    }

    public function test_everything_green_and_still_nothing_orderable_is_called_out(): void
    {
        $merchant = $this->merchant();

        $menu = $this->actingAs($this->admin())
            ->get("/admin/merchants/{$merchant->id}")
            ->assertOk()
            ->viewData('menu');

        /*
         * Verified, accepting, licence in date, and no menu. Healthy on
         * every other screen in the portal and completely unorderable.
         */
        $this->assertTrue($menu['empty']);

        $this->actingAs($this->admin())
            ->get("/admin/merchants/{$merchant->id}")
            ->assertSee('No menu items at all');
    }

    public function test_earnings_come_from_the_service_the_merchant_portal_uses(): void
    {
        $merchant = $this->merchant();

        $this->order($merchant, OrderStatus::Delivered, [
            'delivered_at' => now()->subDay(),
            'items_total' => 500,
            'packaging_fee' => 20,
            'commission_amount' => 78,
            'merchant_payout' => 442,
        ]);

        $earnings = $this->actingAs($this->admin())
            ->get("/admin/merchants/{$merchant->id}")
            ->assertOk()
            ->viewData('earnings');

        /*
         * Read through EarningsService rather than recomputed here. Two
         * screens with their own arithmetic eventually disagree about the
         * same week, and the restaurant is the one who would be right.
         */
        $this->assertSame(520.0, $earnings['gross']);
        $this->assertSame(78.0, $earnings['commission']);
        $this->assertSame(442.0, $earnings['payout']);
    }

    public function test_a_renewed_session_is_not_counted_as_a_fresh_sign_in(): void
    {
        $merchant = $this->merchant();

        $first = RefreshToken::create([
            'user_id' => $merchant->user_id,
            'token_hash' => str_repeat('c', 64),
            'device_name' => 'Kitchen tablet',
            'ip_address' => '49.207.0.9',
            'expires_at' => now()->addDays(30),
            'last_used_at' => now()->subHours(3),
        ]);

        $second = RefreshToken::create([
            'user_id' => $merchant->user_id,
            'token_hash' => str_repeat('d', 64),
            'device_name' => 'Kitchen tablet',
            'ip_address' => '49.207.0.9',
            'expires_at' => now()->addDays(30),
            'last_used_at' => now(),
        ]);

        $first->update(['replaced_by_id' => $second->id, 'revoked_at' => now()->subHours(3)]);

        $sessions = $this->actingAs($this->admin())
            ->get("/admin/merchants/{$merchant->id}")
            ->assertOk()
            ->viewData('sessions')
            ->keyBy('id');

        $this->assertTrue($sessions[$first->id]['is_sign_in']);
        $this->assertFalse($sessions[$second->id]['is_sign_in']);
    }

    public function test_the_page_says_which_of_the_four_conditions_failed(): void
    {
        $merchant = $this->merchant([
            'is_accepting_orders' => false,
            'fssai_expiry_date' => now()->subMonth(),
        ]);

        $state = $this->actingAs($this->admin())
            ->get("/admin/merchants/{$merchant->id}")
            ->assertOk()
            ->viewData('state');

        $this->assertFalse($state['accepting']);
        $this->assertNotNull($state['fssai_blocker']);
        $this->assertFalse($state['can_receive']);

        // Verified is still true — an expired licence is a separate problem
        // from an unverified account, and conflating them sends an admin to
        // re-approve documents that were never the issue.
        $this->assertTrue($state['verified']);
    }

    public function test_the_full_bank_account_number_is_not_shown(): void
    {
        $merchant = $this->merchant(['bank_account_number' => '11112222333344']);

        $this->actingAs($this->admin())
            ->get("/admin/merchants/{$merchant->id}")
            ->assertOk()
            ->assertDontSee('11112222333344');
    }
}
