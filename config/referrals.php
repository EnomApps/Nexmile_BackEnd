<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rider referrals
    |--------------------------------------------------------------------------
    | A rider invites someone they know, and earns when that person actually
    | starts working.
    |
    | Riders recruiting riders is the cheapest hiring a delivery platform ever
    | does, and the person being recruited arrives already knowing what the
    | work is — which is most of why they stay.
    */

    'enabled' => env('REFERRALS_ENABLED', true),

    /*
     * What the referrer earns, and what the new rider has to have done first.
     *
     * Paid on deliveries completed, never on signing up. A bonus for an
     * account existing can be earned by anyone with a spare SIM card and an
     * afternoon; a bonus for fifty real deliveries cannot be earned by
     * anything except fifty real deliveries.
     *
     * Two payments rather than one for the same reason a launch offer has an
     * end date: the second one is what keeps the referrer interested in
     * whether their friend is actually getting on.
     *
     * Amounts are a commercial decision, not a technical one. At ₹2,500 a head
     * a hundred referrals is ₹2.5 lakh, so this belongs in a budget before it
     * belongs in an app.
     */
    'milestones' => [
        ['deliveries' => 10, 'amount' => 500.00],
        ['deliveries' => 50, 'amount' => 2000.00],
    ],

    /*
     * How long an invitation stays open.
     *
     * An invite nobody took up should stop occupying the number. Otherwise a
     * rider who invited a friend in January blocks anyone else from ever
     * referring them, and the friend who joins in September earns nobody
     * anything.
     */
    'invite_expires_days' => 60,

    /*
     * Open invitations one rider may hold at once.
     *
     * Someone working through a contact list a hundred numbers at a time is
     * not referring friends, and the cap costs an honest rider nothing — they
     * free a slot every time an invite is taken up or lapses.
     */
    'max_open_invites' => 20,

];
