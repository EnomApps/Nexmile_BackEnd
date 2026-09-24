<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rider payouts
    |--------------------------------------------------------------------------
    | What a rider is actually paid, and when.
    |
    | Earnings have been tracked per order since dispatch was built, but
    | nothing ever added them up and sent money. A rider looking at a balance
    | they cannot receive is worse than one shown nothing.
    */

    /*
     * Monday to Sunday, paid the following Wednesday. The same shape the
     * restaurants are on, and the same shape every rider has already seen from
     * Swiggy and Zomato — familiar beats clever for the number somebody feeds
     * their family on.
     */
    'period_days' => 7,

    /*
     * Below this, the week rolls into the next one rather than generating a
     * transfer. A ₹40 bank transfer costs more in fees and reconciliation than
     * it moves, and a rider would rather have ₹40 added to next week than a
     * line in a statement.
     */
    'minimum_transfer' => env('RIDER_MINIMUM_TRANSFER', 100),

    /*
    |--------------------------------------------------------------------------
    | TDS
    |--------------------------------------------------------------------------
    | Tax deducted at source, which the platform withholds and pays to the
    | government on the rider's behalf.
    |
    | **Every figure below needs the accountant's sign-off before it runs.**
    | Deducting is a legal obligation once the thresholds are crossed, and
    | deducting money that is then not remitted is an offence — the deduction
    | is only half the job. Quarterly returns (Form 26Q) and the rider's
    | certificate (Form 16A) live outside this system.
    */

    'tds' => [

        /*
         * Off until somebody has confirmed the treatment. A platform that
         * quietly starts withholding from riders' pay on a developer's reading
         * of the Income Tax Act is worse than one that withholds nothing.
         */
        'enabled' => env('TDS_ENABLED', false),

        /*
         * 1% for an individual contractor, which is what a rider is under
         * section 194C. The same rate happens to apply under 194-O if a rider
         * is treated as an e-commerce participant instead — it is the
         * threshold below that differs, not the rate.
         */
        'rate' => env('TDS_RATE', 1.0),

        /*
         * No PAN means 20% under section 206AA — a penalty rate, not a choice.
         * The right answer is to collect the PAN, which onboarding already
         * asks for; this exists so a missing one is handled lawfully rather
         * than by quietly deducting 1% and leaving the platform liable for the
         * difference.
         */
        'rate_without_pan' => env('TDS_RATE_WITHOUT_PAN', 20.0),

        /*
         * Aggregate paid in the financial year before anything is deducted.
         *
         * ₹1,00,000 is the 194C figure. Under 194-O it is ₹5,00,000, and the
         * difference is not academic: a rider earning ₹6,000 a week crosses
         * one in about four months and the other in about a year and a half.
         *
         * Which section applies to a delivery rider is the question for the
         * accountant, and it is the reason none of this is switched on.
         */
        'annual_threshold' => env('TDS_ANNUAL_THRESHOLD', 100000),

        /*
         * A single payment this large is deducted from regardless of the
         * annual total — also 194C. Unlikely on a weekly cycle, and cheap to
         * honour.
         */
        'single_payment_threshold' => env('TDS_SINGLE_THRESHOLD', 30000),

        /*
         * India's financial year starts in April, not January. Getting this
         * wrong would reset every rider's running total nine months early and
         * under-deduct for the rest of the year.
         */
        'financial_year_starts_month' => 4,
    ],

];
