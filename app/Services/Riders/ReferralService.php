<?php

namespace App\Services\Riders;

use App\Enums\UserStatus;
use App\Models\Rider;
use App\Models\RiderReferral;
use App\Models\RiderReferralBonus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Riders inviting riders, and what that earns them.
 *
 * The cheapest hiring a delivery platform ever does, and the recruit arrives
 * already knowing what the work is — which is most of why they stay.
 *
 * It is also the single most abused feature on every gig platform there has
 * ever been, because a referral bonus is free money to anyone with a spare SIM
 * card. Almost everything below is about that: nothing is paid for an account
 * existing, only for deliveries that were actually made, and a recruit belongs
 * to exactly one referrer for ever.
 */
class ReferralService
{
    /**
     * Invite someone by phone number.
     *
     * @throws ValidationException
     */
    public function invite(Rider $referrer, string $phone, ?string $name, ?string $city): RiderReferral
    {
        $this->guardEnabled();

        $phone = $this->normalise($phone);

        if (strlen($phone) !== 10) {
            throw ValidationException::withMessages([
                'phone' => 'That does not look like a ten-digit mobile number.',
            ]);
        }

        // The obvious one, and the one that would otherwise pay for a second
        // SIM card in the same pocket.
        if ($this->normalise((string) $referrer->user?->phone) === $phone) {
            throw ValidationException::withMessages([
                'phone' => 'That is your own number.',
            ]);
        }

        /*
         * Somebody who already has an account is not a recruit. Without this
         * a rider could invite the colleague sitting next to them who joined
         * last year, and collect for deliveries that were always going to
         * happen.
         */
        if (User::where('phone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'Someone is already using Nexmile on that number.',
            ]);
        }

        $open = RiderReferral::where('referrer_rider_id', $referrer->id)->open()->count();

        if ($open >= (int) config('referrals.max_open_invites')) {
            throw ValidationException::withMessages([
                'phone' => 'You have too many invitations still open. Wait for some to be taken up.',
            ]);
        }

        /*
         * Somebody else got there first and their invitation is still live.
         * Deliberately does not say who — that would tell one rider which of
         * their contacts another rider is working through.
         */
        $taken = RiderReferral::where('referred_phone', $phone)->open()
            ->where('referrer_rider_id', '!=', $referrer->id)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'phone' => 'That number has already been invited.',
            ]);
        }

        try {
            return RiderReferral::create([
                'referrer_rider_id' => $referrer->id,
                'referred_phone' => $phone,
                'referred_name' => $name,
                'referred_city' => $city,
                'invited_at' => now(),
                'expires_at' => now()->addDays((int) config('referrals.invite_expires_days')),
            ]);
        } catch (QueryException) {
            // The unique index catching a re-invite, including two taps of the
            // same button arriving together.
            throw ValidationException::withMessages([
                'phone' => 'You have already invited that number.',
            ]);
        }
    }

    /**
     * Attach a new rider to whoever invited them, if anyone did.
     *
     * Fired when the rider record is created rather than called from the two
     * controllers that create one, so a third way in cannot forget it.
     */
    public function linkOnSignup(Rider $rider): void
    {
        if (! config('referrals.enabled')) {
            return;
        }

        $phone = $this->normalise((string) $rider->user?->phone);

        if ($phone === '') {
            return;
        }

        /*
         * Oldest first: if two people invited the same number before either
         * was matched, the one who asked first gets the recruit. Any rule
         * would do — what matters is that it is decided here rather than by
         * whichever row the database happened to return.
         */
        $referral = RiderReferral::where('referred_phone', $phone)
            ->open()
            ->oldest('invited_at')
            ->first();

        // Nobody can be their own recruit, however the accounts were made.
        if ($referral === null || $referral->referrer_rider_id === $rider->id) {
            return;
        }

        $referral->forceFill(['referred_rider_id' => $rider->id])->save();
    }

    /**
     * Pay whatever this rider's deliveries have just earned their referrer.
     *
     * Called after each delivery. Idempotent by unique index rather than by
     * checking first — two deliveries finishing at the same moment would both
     * pass a check and only one can survive the insert.
     */
    public function creditDeliveries(Rider $rider): void
    {
        if (! config('referrals.enabled')) {
            return;
        }

        $referral = RiderReferral::where('referred_rider_id', $rider->id)
            ->with('referrer.user')
            ->first();

        if ($referral === null) {
            return;
        }

        /*
         * A suspended referrer stops earning. Someone removed for running
         * fake accounts should not keep collecting from the ones that got
         * through, and this is the only place that can stop it.
         */
        if ($referral->referrer?->user?->status !== UserStatus::Active) {
            return;
        }

        $done = $this->deliveries($rider);

        foreach ((array) config('referrals.milestones') as $milestone) {
            if ($done < (int) $milestone['deliveries']) {
                continue;
            }

            $this->pay($referral, (int) $milestone['deliveries'], (float) $milestone['amount']);
        }
    }

    /**
     * The referrer's own screen: what they have earned and who from.
     *
     * @return array<string, mixed>
     */
    public function summary(Rider $referrer): array
    {
        $referrals = RiderReferral::where('referrer_rider_id', $referrer->id)
            ->with(['referred', 'bonuses'])
            ->latest('invited_at')
            ->get();

        return [
            'earned' => round((float) RiderReferralBonus::where('rider_id', $referrer->id)->sum('amount'), 2),

            /*
             * Counted on people who actually joined, not on invitations sent.
             * "6 friends referred" meaning six numbers typed into a form would
             * be a number the rider knows is not true.
             */
            'joined' => $referrals->whereNotNull('referred_rider_id')->count(),
            'invited' => $referrals->count(),

            // What one referral is worth in full, from config rather than a
            // number written into the app that nobody remembers to change.
            'max_per_referral' => round(array_sum(
                array_column((array) config('referrals.milestones'), 'amount')
            ), 2),

            'milestones' => array_map(fn (array $m) => [
                'deliveries' => (int) $m['deliveries'],
                'amount' => round((float) $m['amount'], 2),
            ], (array) config('referrals.milestones')),

            'referrals' => $referrals,
        ];
    }

    /**
     * The progress timeline for one invitation.
     *
     * Deliberately does not claim to know about installing the app. The
     * observable event is somebody signing up, and a tick saying "App
     * Installed" that really means "signed up" is a small lie on a screen
     * about money.
     *
     * @return array<string, mixed>
     */
    public function timeline(RiderReferral $referral): array
    {
        $rider = $referral->referred;
        $done = $rider === null ? 0 : $this->deliveries($rider);

        return [
            'id' => $referral->id,
            'name' => $referral->referred_name,
            'phone' => $referral->maskedPhone(),
            'city' => $referral->referred_city,
            'state' => $referral->state(),
            'invited_at' => $referral->invited_at,
            'expires_at' => $referral->expires_at,
            'joined_at' => $rider?->created_at,
            'onboarded_at' => $rider?->kyc_verified_at,
            'deliveries' => $done,

            'steps' => array_map(fn (array $m) => [
                'deliveries' => (int) $m['deliveries'],
                'amount' => round((float) $m['amount'], 2),
                'done' => $done >= (int) $m['deliveries'],
                /*
                 * How many more, so the referrer has something to act on. "3
                 * more deliveries" is a reason to ring their friend; "not yet"
                 * is not.
                 */
                'remaining' => max(0, (int) $m['deliveries'] - $done),
                'earned_at' => $referral->bonuses
                    ->firstWhere('deliveries_required', (int) $m['deliveries'])?->earned_at,
            ], (array) config('referrals.milestones')),

            'earned' => round((float) $referral->bonuses->sum('amount'), 2),
        ];
    }

    /** Deliveries the referred rider has actually completed. */
    protected function deliveries(Rider $rider): int
    {
        return (int) $rider->completed_deliveries;
    }

    /** Insert the bonus, letting the unique index refuse a second one. */
    protected function pay(RiderReferral $referral, int $deliveries, float $amount): void
    {
        try {
            DB::transaction(fn () => RiderReferralBonus::create([
                'rider_referral_id' => $referral->id,
                'rider_id' => $referral->referrer_rider_id,
                'deliveries_required' => $deliveries,
                'amount' => $amount,
                'earned_at' => now(),
            ]));
        } catch (QueryException) {
            // Already paid. The whole point of the index.
        }
    }

    /** Ten digits, however it was typed or stored. */
    protected function normalise(string $phone): string
    {
        return substr(preg_replace('/\D/', '', $phone) ?? '', -10);
    }

    /** @throws ValidationException */
    protected function guardEnabled(): void
    {
        if (! config('referrals.enabled')) {
            throw ValidationException::withMessages([
                'phone' => 'Referrals are not open at the moment.',
            ]);
        }
    }
}
