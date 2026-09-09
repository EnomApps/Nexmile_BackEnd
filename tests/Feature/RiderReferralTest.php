<?php

namespace Tests\Feature;

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Rider;
use App\Models\RiderReferral;
use App\Models\RiderReferralBonus;
use App\Models\User;
use App\Services\Riders\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Riders inviting riders, and what that earns them.
 *
 * A referral bonus is the most abused feature on every gig platform there has
 * ever been, because it is free money to anyone with a spare SIM card. Most of
 * what follows is about that: nothing is paid for an account existing, only
 * for deliveries that were actually made.
 */
class RiderReferralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['referrals.enabled' => true]);
        config(['referrals.milestones' => [
            ['deliveries' => 10, 'amount' => 500.00],
            ['deliveries' => 50, 'amount' => 2000.00],
        ]]);
    }

    private function user(UserRole $role, ?string $phone = null): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'User '.$n,
            'phone' => $phone ?? '95000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'email' => "ref{$n}@example.in",
            'password' => 'secret',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function rider(?string $phone = null, array $attributes = []): Rider
    {
        return $this->user(UserRole::Rider, $phone)->rider()->create([
            'full_name' => 'Selvam K',
            'vehicle_type' => 'motorcycle',
            'kyc_status' => KycStatus::Verified,
            'kyc_verified_at' => now(),
            'driving_licence_expiry' => now()->addYear(),
            'insurance_expiry' => now()->addYear(),
            ...$attributes,
        ]);
    }

    private function service(): ReferralService
    {
        return app(ReferralService::class);
    }

    // ------------------------------------------------------------- inviting

    public function test_a_rider_invites_someone_by_phone_number(): void
    {
        $referrer = $this->rider();

        Sanctum::actingAs($referrer->user);

        $this->postJson('/api/v1/rider/referrals', [
            'phone' => '+91 98765 43210',
            'name' => 'Murugan',
            'city' => 'Madurai',
        ])->assertCreated();

        $referral = RiderReferral::sole();

        // Stored as ten digits however it was typed, or a friend signing up as
        // 9876543210 would never match the invitation that named them.
        $this->assertSame('9876543210', $referral->referred_phone);
        $this->assertSame($referrer->id, $referral->referrer_rider_id);
        $this->assertSame(RiderReferral::INVITED, $referral->state());
    }

    public function test_a_rider_cannot_refer_their_own_number(): void
    {
        // The one that would otherwise pay for a second SIM in the same pocket.
        $referrer = $this->rider('9876500001');

        Sanctum::actingAs($referrer->user);

        $this->postJson('/api/v1/rider/referrals', ['phone' => '9876500001'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        // Written differently, still the same person.
        $this->postJson('/api/v1/rider/referrals', ['phone' => '+91 98765 00001'])
            ->assertStatus(422);

        $this->assertSame(0, RiderReferral::count());
    }

    public function test_somebody_who_already_has_an_account_is_not_a_recruit(): void
    {
        $referrer = $this->rider();
        $colleague = $this->rider('9876500002');

        /*
         * Otherwise a rider invites the colleague sitting next to them who
         * joined last year and collects for deliveries that were always going
         * to happen.
         */
        Sanctum::actingAs($referrer->user);

        $this->postJson('/api/v1/rider/referrals', ['phone' => $colleague->user->phone])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_the_same_number_cannot_be_invited_twice_by_one_rider(): void
    {
        $referrer = $this->rider();

        Sanctum::actingAs($referrer->user);

        $this->postJson('/api/v1/rider/referrals', ['phone' => '9876500003'])->assertCreated();
        $this->postJson('/api/v1/rider/referrals', ['phone' => '9876500003'])->assertStatus(422);

        $this->assertSame(1, RiderReferral::count());
    }

    public function test_an_open_invitation_holds_the_number_against_other_riders(): void
    {
        $first = $this->rider();
        $second = $this->rider();

        Sanctum::actingAs($first->user);
        $this->postJson('/api/v1/rider/referrals', ['phone' => '9876500004'])->assertCreated();

        Sanctum::actingAs($second->user);
        $response = $this->postJson('/api/v1/rider/referrals', ['phone' => '9876500004'])
            ->assertStatus(422);

        // Says the number is taken, never who took it — that would tell one
        // rider which contacts another is working through.
        $this->assertStringNotContainsString(
            $first->full_name,
            json_encode($response->json()),
        );
    }

    public function test_a_lapsed_invitation_frees_the_number(): void
    {
        $first = $this->rider();
        $second = $this->rider();

        RiderReferral::create([
            'referrer_rider_id' => $first->id,
            'referred_phone' => '9876500005',
            'invited_at' => now()->subDays(90),
            'expires_at' => now()->subDays(30),
        ]);

        /*
         * Otherwise January's invitation sits on the number for ever, and the
         * friend who finally joins in September earns nobody anything.
         */
        Sanctum::actingAs($second->user);

        $this->postJson('/api/v1/rider/referrals', ['phone' => '9876500005'])->assertCreated();
    }

    public function test_a_rider_cannot_hold_more_than_the_cap(): void
    {
        config(['referrals.max_open_invites' => 3]);

        $referrer = $this->rider();
        Sanctum::actingAs($referrer->user);

        foreach (range(1, 3) as $n) {
            $this->postJson('/api/v1/rider/referrals', ['phone' => '987650100'.$n])->assertCreated();
        }

        // Someone working through a contact list is not referring friends.
        $this->postJson('/api/v1/rider/referrals', ['phone' => '9876501009'])->assertStatus(422);
    }

    public function test_a_number_that_is_not_ten_digits_is_refused(): void
    {
        Sanctum::actingAs($this->rider()->user);

        $this->postJson('/api/v1/rider/referrals', ['phone' => '98765'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    // -------------------------------------------------------------- linking

    public function test_signing_up_on_an_invited_number_links_the_referral(): void
    {
        $referrer = $this->rider();

        $this->service()->invite($referrer, '9876500006', 'Murugan', 'Madurai');

        // The friend joins. Nothing calls the referral system explicitly.
        $friend = $this->rider('9876500006', ['kyc_status' => KycStatus::Pending, 'kyc_verified_at' => null]);

        $referral = RiderReferral::sole();

        $this->assertSame($friend->id, $referral->referred_rider_id);
        $this->assertSame(RiderReferral::JOINED, $referral->fresh()->state());
    }

    public function test_a_recruit_belongs_to_the_first_person_who_asked(): void
    {
        $first = $this->rider();
        $second = $this->rider();

        Carbon::setTestNow(now()->subDays(5));
        $earlier = $this->service()->invite($first, '9876500007', null, null);

        Carbon::setTestNow(now()->addDays(3));
        // Force a second open invitation past the usual guard, to prove the
        // tie is broken here rather than by whichever row comes back first.
        $later = RiderReferral::create([
            'referrer_rider_id' => $second->id,
            'referred_phone' => '9876500007',
            'invited_at' => now(),
            'expires_at' => now()->addDays(60),
        ]);

        Carbon::setTestNow();

        $friend = $this->rider('9876500007');

        $this->assertSame($friend->id, $earlier->fresh()->referred_rider_id);
        $this->assertNull($later->fresh()->referred_rider_id);
    }

    public function test_kyc_and_first_delivery_move_the_state_without_being_written_down(): void
    {
        $referrer = $this->rider();
        $this->service()->invite($referrer, '9876500008', null, null);

        $friend = $this->rider('9876500008', ['kyc_status' => KycStatus::Pending, 'kyc_verified_at' => null]);
        $referral = RiderReferral::sole();

        $this->assertSame(RiderReferral::JOINED, $referral->state());

        // Read off the friend's own record, which is the only copy that
        // cannot drift out of step with the truth.
        $friend->forceFill(['kyc_status' => KycStatus::Verified, 'kyc_verified_at' => now()])->save();
        $this->assertSame(RiderReferral::ONBOARDED, $referral->fresh()->load('referred')->state());

        $friend->forceFill(['completed_deliveries' => 1])->save();
        $this->assertSame(RiderReferral::WORKING, $referral->fresh()->load('referred')->state());
    }

    // --------------------------------------------------------------- paying

    /** Put the friend on a delivery count and run the credit. */
    private function deliver(Rider $friend, int $count): void
    {
        $friend->forceFill(['completed_deliveries' => $count])->save();

        $this->service()->creditDeliveries($friend->fresh());
    }

    public function test_nothing_is_paid_for_signing_up(): void
    {
        $referrer = $this->rider();
        $this->service()->invite($referrer, '9876500009', null, null);

        $friend = $this->rider('9876500009');

        /*
         * The whole design. A bonus for an account existing can be earned by
         * anyone with a spare SIM and an afternoon; a bonus for ten real
         * deliveries cannot be earned by anything except ten real deliveries.
         */
        $this->service()->creditDeliveries($friend);

        $this->assertSame(0, RiderReferralBonus::count());
    }

    public function test_the_first_milestone_pays_at_ten_deliveries(): void
    {
        $referrer = $this->rider();
        $this->service()->invite($referrer, '9876500010', null, null);
        $friend = $this->rider('9876500010');

        $this->deliver($friend, 9);
        $this->assertSame(0, RiderReferralBonus::count());

        $this->deliver($friend, 10);

        $bonus = RiderReferralBonus::sole();
        $this->assertEqualsWithDelta(500.0, (float) $bonus->amount, 0.01);
        $this->assertSame($referrer->id, $bonus->rider_id);
    }

    public function test_a_milestone_is_never_paid_twice(): void
    {
        $referrer = $this->rider();
        $this->service()->invite($referrer, '9876500011', null, null);
        $friend = $this->rider('9876500011');

        // Every delivery after the tenth runs the credit again.
        foreach (range(10, 20) as $count) {
            $this->deliver($friend, $count);
        }

        $this->assertSame(1, RiderReferralBonus::count());
        $this->assertEqualsWithDelta(500.0, (float) RiderReferralBonus::sum('amount'), 0.01);
    }

    public function test_both_milestones_pay_as_the_friend_keeps_working(): void
    {
        $referrer = $this->rider();
        $this->service()->invite($referrer, '9876500012', null, null);
        $friend = $this->rider('9876500012');

        $this->deliver($friend, 50);

        // Fifty deliveries clears both thresholds, including the one passed
        // on the way — a rider who was not checked at ten is still owed it.
        $this->assertSame(2, RiderReferralBonus::count());
        $this->assertEqualsWithDelta(2500.0, (float) RiderReferralBonus::sum('amount'), 0.01);
    }

    public function test_a_suspended_referrer_stops_earning(): void
    {
        $referrer = $this->rider();
        $this->service()->invite($referrer, '9876500013', null, null);
        $friend = $this->rider('9876500013');

        $this->deliver($friend, 10);
        $this->assertSame(1, RiderReferralBonus::count());

        // Someone removed for running fake accounts must not keep collecting
        // from the ones that got through.
        $referrer->user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->deliver($friend, 50);

        $this->assertSame(1, RiderReferralBonus::count());
    }

    public function test_the_stored_amount_survives_the_scheme_changing(): void
    {
        $referrer = $this->rider();
        $this->service()->invite($referrer, '9876500014', null, null);
        $friend = $this->rider('9876500014');

        $this->deliver($friend, 10);

        // Next quarter the scheme is cheaper. What was already earned is not
        // restated — a rider who cannot check last month's bonus against last
        // month's rules has no reason to believe this month's.
        config(['referrals.milestones' => [['deliveries' => 10, 'amount' => 100.00]]]);

        $this->deliver($friend, 12);

        $this->assertEqualsWithDelta(500.0, (float) RiderReferralBonus::sum('amount'), 0.01);
    }

    public function test_a_rider_nobody_referred_pays_nobody(): void
    {
        $stranger = $this->rider();

        $this->deliver($stranger, 50);

        $this->assertSame(0, RiderReferralBonus::count());
    }

    // -------------------------------------------------------------- reading

    public function test_the_referral_screen_shows_earnings_and_progress(): void
    {
        $referrer = $this->rider();
        $this->service()->invite($referrer, '9876500015', 'Murugan', 'Madurai');
        $friend = $this->rider('9876500015');
        $this->deliver($friend, 12);

        Sanctum::actingAs($referrer->user);

        $response = $this->getJson('/api/v1/rider/referrals')->assertOk();

        $this->assertEqualsWithDelta(500.0, (float) $response->json('meta.earned'), 0.01);
        $this->assertSame(1, $response->json('meta.joined'));

        // The "earn up to" figure comes from the scheme, not from a number
        // typed into the app that nobody remembers to change.
        $this->assertEqualsWithDelta(2500.0, (float) $response->json('meta.max_per_referral'), 0.01);

        $row = $response->json('data.0');
        $this->assertSame('Murugan', $row['name']);
        $this->assertSame(RiderReferral::WORKING, $row['state']);
        $this->assertSame(12, $row['deliveries']);

        $this->assertTrue($row['steps'][0]['done']);
        $this->assertFalse($row['steps'][1]['done']);

        // Something to act on. "38 more deliveries" is a reason to ring your
        // friend; "not yet" is not.
        $this->assertSame(38, $row['steps'][1]['remaining']);
    }

    public function test_the_friends_number_is_not_printed_in_full(): void
    {
        $referrer = $this->rider();
        $this->service()->invite($referrer, '9876500016', 'Murugan', null);

        Sanctum::actingAs($referrer->user);

        $response = $this->getJson('/api/v1/rider/referrals')->assertOk();

        // A shared or shoulder-surfed phone makes somebody else's number
        // everyone's.
        $this->assertStringNotContainsString('9876500016', $response->getContent());
        $this->assertSame('98••••••16', $response->json('data.0.phone'));
    }

    public function test_counting_friends_means_people_who_joined(): void
    {
        $referrer = $this->rider();

        $this->service()->invite($referrer, '9876500017', null, null);
        $this->service()->invite($referrer, '9876500018', null, null);
        $this->rider('9876500017');

        Sanctum::actingAs($referrer->user);

        $response = $this->getJson('/api/v1/rider/referrals')->assertOk();

        // "6 friends referred" meaning six numbers typed into a form is a
        // number the rider knows is not true.
        $this->assertSame(1, $response->json('meta.joined'));
        $this->assertSame(2, $response->json('meta.invited'));
    }

    public function test_a_rider_cannot_read_another_riders_referral(): void
    {
        $referrer = $this->rider();
        $referral = $this->service()->invite($referrer, '9876500019', null, null);

        Sanctum::actingAs($this->rider()->user);

        $this->getJson("/api/v1/rider/referrals/{$referral->id}")->assertNotFound();
    }

    public function test_referrals_are_closed_when_the_scheme_is_off(): void
    {
        config(['referrals.enabled' => false]);

        Sanctum::actingAs($this->rider()->user);

        $this->postJson('/api/v1/rider/referrals', ['phone' => '9876500020'])->assertStatus(422);
    }

    public function test_a_customer_cannot_reach_the_rider_referral_screen(): void
    {
        Sanctum::actingAs($this->user(UserRole::Customer));

        $this->getJson('/api/v1/rider/referrals')->assertForbidden();
    }
}
