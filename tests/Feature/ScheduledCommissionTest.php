<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Merchant\EarningsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A commission rate that changes on a date the restaurant was told about.
 *
 * The launch offer is a lower rate for the first few months. Ending it by hand
 * means either it never ends, or it ends for whoever an admin happened to open
 * that week — and a rate that rises with no warning is what makes a restaurant
 * leave, correctly.
 */
class ScheduledCommissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-01-15 11:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function user(UserRole $role): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'User '.$n,
            'phone' => '96000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "comm{$n}@example.in",
            'password' => 'secret',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function restaurant(array $terms = []): Merchant
    {
        $merchant = Merchant::create([
            'user_id' => $this->user(UserRole::Merchant)->id,
            'business_name' => 'Ponnusamy Hotel',
            'owner_name' => 'Owner',
            'address_line1' => '9 Anna Salai',
            'city' => 'Madurai',
            'pincode' => '625001',
            'kyc_status' => KycStatus::Verified,
            'is_accepting_orders' => true,
        ]);

        // forceFill: commission is not mass-assignable anywhere, by design.
        $merchant->forceFill(['commission_rate' => 10, ...$terms])->save();

        return $merchant->fresh();
    }

    public function test_the_launch_rate_holds_until_the_day_it_ends(): void
    {
        $merchant = $this->restaurant([
            'scheduled_commission_rate' => 12,
            'commission_changes_on' => '2026-04-14',
        ]);

        $this->assertEqualsWithDelta(10.0, $merchant->effectiveCommissionRate(), 0.001);
        $this->assertTrue($merchant->hasUpcomingCommissionChange());

        // The day before is still the launch rate. An offer that ends early is
        // the same broken promise as one that never ends.
        Carbon::setTestNow(Carbon::parse('2026-04-13 23:59:00'));
        $this->assertEqualsWithDelta(10.0, $merchant->fresh()->effectiveCommissionRate(), 0.001);
    }

    public function test_the_new_rate_starts_at_midnight_on_the_date(): void
    {
        $merchant = $this->restaurant([
            'scheduled_commission_rate' => 12,
            'commission_changes_on' => '2026-04-14',
        ]);

        /*
         * From midnight, not from whenever a job happened to run. "You move to
         * 12% on 14 March" is what the restaurant was told, and a rate that
         * turned at 14:32 would be indefensible against the day's payouts.
         */
        Carbon::setTestNow(Carbon::parse('2026-04-14 00:01:00'));

        $merchant = $merchant->fresh();

        $this->assertEqualsWithDelta(12.0, $merchant->effectiveCommissionRate(), 0.001);
        $this->assertFalse($merchant->hasUpcomingCommissionChange());
    }

    public function test_the_rate_turns_without_anything_having_run(): void
    {
        /*
         * No command, no scheduler, no cron. The scheduler needs a crontab
         * line nobody has confirmed exists, and a launch offer still charging
         * 10% in July because of it is a revenue hole nobody would notice.
         */
        $merchant = $this->restaurant([
            'scheduled_commission_rate' => 12,
            'commission_changes_on' => '2026-04-14',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-01 09:00:00'));

        $this->assertEqualsWithDelta(12.0, $merchant->fresh()->effectiveCommissionRate(), 0.001);
    }

    public function test_a_merchant_with_no_schedule_is_untouched(): void
    {
        $merchant = $this->restaurant();

        $this->assertEqualsWithDelta(10.0, $merchant->effectiveCommissionRate(), 0.001);
        $this->assertFalse($merchant->hasUpcomingCommissionChange());
    }

    public function test_an_order_is_priced_at_the_rate_in_force_that_day(): void
    {
        $merchant = $this->restaurant([
            'scheduled_commission_rate' => 12,
            'commission_changes_on' => '2026-04-14',
        ]);

        // ₹300 of food. The client's own worked example.
        $before = $merchant->fresh()->effectiveCommissionRate();

        Carbon::setTestNow(Carbon::parse('2026-04-14 12:00:00'));
        $after = $merchant->fresh()->effectiveCommissionRate();

        $this->assertEqualsWithDelta(30.0, 300 * $before / 100, 0.01);
        $this->assertEqualsWithDelta(36.0, 300 * $after / 100, 0.01);
    }

    // ------------------------------------------------------------ the admin

    private function admin(): User
    {
        return $this->user(UserRole::Admin);
    }

    public function test_an_admin_schedules_the_end_of_the_launch_offer(): void
    {
        $merchant = $this->restaurant();

        $this->actingAs($this->admin())
            ->post(route('admin.merchants.terms', $merchant->id), [
                'commission_rate' => 10,
                'scheduled_commission_rate' => 12,
                'commission_changes_on' => '2026-04-14',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $merchant->refresh();

        $this->assertEqualsWithDelta(12.0, (float) $merchant->scheduled_commission_rate, 0.001);
        $this->assertSame('2026-04-14', $merchant->commission_changes_on->toDateString());
    }

    public function test_a_change_cannot_be_backdated(): void
    {
        $merchant = $this->restaurant();

        // Backdating would charge a restaurant more for orders it has already
        // cooked, on terms it was never shown.
        $this->actingAs($this->admin())
            ->post(route('admin.merchants.terms', $merchant->id), [
                'commission_rate' => 10,
                'scheduled_commission_rate' => 12,
                'commission_changes_on' => '2026-01-01',
            ])
            ->assertSessionHasErrors('commission_changes_on');

        $this->assertNull($merchant->fresh()->commission_changes_on);
    }

    public function test_half_a_schedule_is_refused(): void
    {
        $merchant = $this->restaurant();

        // A date with no rate does nothing when it arrives; a rate with no
        // date never applies. Either half alone is an offer that never ends.
        $this->actingAs($this->admin())
            ->post(route('admin.merchants.terms', $merchant->id), [
                'commission_rate' => 10,
                'commission_changes_on' => '2026-04-14',
            ])
            ->assertSessionHasErrors('scheduled_commission_rate');

        $this->actingAs($this->admin())
            ->post(route('admin.merchants.terms', $merchant->id), [
                'commission_rate' => 10,
                'scheduled_commission_rate' => 12,
            ])
            ->assertSessionHasErrors('commission_changes_on');
    }

    public function test_a_schedule_can_be_cleared(): void
    {
        $merchant = $this->restaurant([
            'scheduled_commission_rate' => 12,
            'commission_changes_on' => '2026-04-14',
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.merchants.terms', $merchant->id), ['commission_rate' => 10])
            ->assertRedirect();

        $merchant->refresh();

        $this->assertNull($merchant->scheduled_commission_rate);
        $this->assertNull($merchant->commission_changes_on);
    }

    public function test_the_scheduled_rate_obeys_the_same_ceiling(): void
    {
        $merchant = $this->restaurant();

        // A fat-fingered 150 in the second box would take more than the order
        // is worth, three months after anybody looked at it.
        $this->actingAs($this->admin())
            ->post(route('admin.merchants.terms', $merchant->id), [
                'commission_rate' => 10,
                'scheduled_commission_rate' => 150,
                'commission_changes_on' => '2026-04-14',
            ])
            ->assertSessionHasErrors('scheduled_commission_rate');
    }

    // --------------------------------------------------------- the merchant

    public function test_the_restaurant_is_told_before_the_rate_rises(): void
    {
        $merchant = $this->restaurant([
            'scheduled_commission_rate' => 12,
            'commission_changes_on' => '2026-04-14',
        ]);

        $summary = app(EarningsService::class)->summary(
            $merchant->fresh(),
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
        );

        $this->assertEqualsWithDelta(10.0, $summary['commission_rate'], 0.001);
        $this->assertEqualsWithDelta(12.0, $summary['upcoming_commission_rate'], 0.001);
        $this->assertSame('2026-04-14', $summary['commission_changes_on']);

        // On the page, in the merchant's own language, next to the number it
        // changes — not in an email nobody opened three months ago.
        $this->actingAs($merchant->user)
            ->get('/merchants/earnings')
            ->assertOk()
            ->assertSee('12')
            ->assertSee('14 April 2026');
    }

    public function test_the_warning_goes_once_the_change_has_happened(): void
    {
        $merchant = $this->restaurant([
            'scheduled_commission_rate' => 12,
            'commission_changes_on' => '2026-04-14',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-20 11:00:00'));

        $summary = app(EarningsService::class)->summary(
            $merchant->fresh(),
            Carbon::parse('2026-04-01'),
            Carbon::parse('2026-04-30'),
        );

        // The rate it warned about is now simply the rate.
        $this->assertEqualsWithDelta(12.0, $summary['commission_rate'], 0.001);
        $this->assertNull($summary['upcoming_commission_rate']);
    }

    // ---------------------------------------------------------- the tidy-up

    public function test_the_command_folds_a_due_change_into_the_rate(): void
    {
        $merchant = $this->restaurant([
            'scheduled_commission_rate' => 12,
            'commission_changes_on' => '2026-04-14',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-15 00:20:00'));

        $this->artisan('nexmile:apply-commission-changes')->assertSuccessful();

        $merchant->refresh();

        /*
         * The rate was already 12% before this ran. What the command removes
         * is the admin page still advertising a change that happened — which
         * is how somebody ends up setting 12% by hand on top of it.
         */
        $this->assertEqualsWithDelta(12.0, (float) $merchant->commission_rate, 0.001);
        $this->assertNull($merchant->scheduled_commission_rate);
        $this->assertNull($merchant->commission_changes_on);
    }

    public function test_the_command_leaves_a_future_change_alone(): void
    {
        $merchant = $this->restaurant([
            'scheduled_commission_rate' => 12,
            'commission_changes_on' => '2026-04-14',
        ]);

        $this->artisan('nexmile:apply-commission-changes')->assertSuccessful();

        $merchant->refresh();

        $this->assertEqualsWithDelta(10.0, (float) $merchant->commission_rate, 0.001);
        $this->assertNotNull($merchant->commission_changes_on);
    }
}
