<?php

namespace App\Http\Controllers\Api\V1\Rider;

use App\Http\Controllers\Controller;
use App\Models\Rider;
use App\Models\RiderReferral;
use App\Services\Riders\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Riders inviting riders.
 *
 * The cheapest recruiting a delivery platform does, and the person who arrives
 * already knows what the work is because someone doing it told them.
 *
 * Nothing here pays for an account existing. Every rupee is attached to
 * deliveries the new rider actually completed, because a bonus for signing up
 * is a bonus anyone can earn with a spare SIM card.
 */
class ReferralController extends Controller
{
    public function __construct(protected ReferralService $referrals) {}

    /**
     * My referrals
     *
     * What this rider has earned, who they invited, and how far each one has
     * got. `max_per_referral` is the "earn up to" figure — read it rather than
     * printing an amount into the app, or the screen and the scheme drift
     * apart the first time the numbers change.
     */
    public function index(Request $request): JsonResponse
    {
        $rider = $this->rider($request);
        $summary = $this->referrals->summary($rider);

        return response()->json([
            'data' => $summary['referrals']
                ->map(fn (RiderReferral $r) => $this->referrals->timeline($r))
                ->values()
                ->all(),

            'meta' => [
                'earned' => $summary['earned'],
                'joined' => $summary['joined'],
                'invited' => $summary['invited'],
                'max_per_referral' => $summary['max_per_referral'],
                'milestones' => $summary['milestones'],
            ],
        ]);
    }

    /**
     * Invite someone
     *
     * A ten-digit mobile number, however it is typed — spaces and a +91 are
     * fine. Refused for your own number, for somebody who already has a
     * Nexmile account, and for a number another rider has an open invitation
     * on. Show `errors.phone[0]`; each refusal says which it was.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $referral = $this->referrals->invite(
            $this->rider($request),
            $data['phone'],
            $data['name'] ?? null,
            $data['city'] ?? null,
        );

        return response()->json([
            /*
             * Deliberately does not promise anything will be sent to them.
             * Nexmile does not text a number somebody else typed in — that is
             * how a referral feature becomes a way to have strangers spammed.
             * The rider invites their friend themselves.
             */
            'message' => 'Invitation saved. Ask them to sign up with that number.',
            'data' => $this->referrals->timeline($referral),
        ], 201);
    }

    /**
     * One referral
     *
     * The progress timeline behind a single invitation.
     */
    public function show(Request $request, int $referral): JsonResponse
    {
        $rider = $this->rider($request);

        $model = RiderReferral::with(['referred', 'bonuses'])
            ->where('referrer_rider_id', $rider->id)
            ->findOrFail($referral);

        return response()->json(['data' => $this->referrals->timeline($model)]);
    }

    private function rider(Request $request): Rider
    {
        $rider = $request->user()->rider;

        abort_if($rider === null, 404);

        return $rider;
    }
}
