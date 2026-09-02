<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rider conduct
    |--------------------------------------------------------------------------
    | What a customer can report, and what Nexmile does about it.
    |
    | Nothing here acts on its own. A report is what a customer said; a warning
    | is what Nexmile decided after reading it. Automatic penalties would put a
    | rider's livelihood in the hands of whoever complains loudest, and the most
    | motivated complainant is usually someone angling for a refund.
    */

    /*
     * What a customer picks from. Kept short and concrete: a long list of
     * overlapping options produces reports nobody can act on, and "other" with
     * a description is more useful than a category nobody read carefully.
     *
     * The three marked serious are the ones an admin should see first — they
     * are safety and money, not service quality.
     */
    'report_categories' => [
        'rude' => ['label' => 'Rude or aggressive', 'serious' => true],
        'extra_money' => ['label' => 'Asked for extra money', 'serious' => true],
        'unsafe' => ['label' => 'Unsafe or dangerous riding', 'serious' => true],
        'food_damaged' => ['label' => 'Food spilled or tampered with', 'serious' => false],
        'never_arrived' => ['label' => 'Marked delivered but never arrived', 'serious' => true],
        'very_late' => ['label' => 'Very late with no explanation', 'serious' => false],
        'other' => ['label' => 'Something else', 'serious' => false],
    ],

    /*
     * How long a warning counts against a rider.
     *
     * Warnings that never expire mean one bad month follows someone forever,
     * and a rider with nothing to gain from improving is a rider who leaves.
     */
    'warning_window_days' => 90,

    /*
     * Warnings within that window before a rider is flagged for review.
     *
     * A flag, not a termination. Nobody loses their income to a counter — an
     * admin opens the file, reads what actually happened, and decides. The
     * count exists so a pattern cannot be missed, not so it can be automated.
     */
    'warnings_before_review' => 3,

    /*
     * A rating this low with at least this many ratings also raises the flag.
     * A rider nobody reports but everybody scores 2 is a problem the reports
     * queue will never show.
     */
    'rating_review_below' => 3.0,

    'rating_review_min_count' => 10,

];
