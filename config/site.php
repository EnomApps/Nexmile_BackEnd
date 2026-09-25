<?php

/*
|--------------------------------------------------------------------------
| Marketing site configuration
|--------------------------------------------------------------------------
| Values that do not change between languages. All translatable copy lives
| in lang/{en,ta,hi}/site.php.
*/

return [

    /*
     * Languages offered in the site's language switcher.
     * 'label' is shown in the switcher, 'name' in the accessible title.
     */
    'locales' => [
        'en' => ['label' => 'EN', 'name' => 'English'],
        'ta' => ['label' => 'தமிழ்', 'name' => 'Tamil'],
        'hi' => ['label' => 'हिन्दी', 'name' => 'Hindi'],
    ],

    'founder' => 'Magendran Marthandan',

    /*
     * The registered company name, in one place because it appears in the
     * footer of every page, the page title, the policies and the OTP email.
     *
     * "Nexmile India Pvt. Ltd." was never approved by the MCA. The approved
     * name is NEXMILE INTEGRATED COMMERCE PRIVATE LIMITED, incorporated
     * 2026-09-09.
     *
     * `company` is the everyday short form; `company_legal` is the full
     * registered form and belongs anywhere the entity is named as a legal
     * party — the policies, and anything a payment provider checks.
     */
    'company' => 'Nexmile Integrated Commerce Pvt Ltd',
    'company_legal' => 'Nexmile Integrated Commerce Private Limited',

    /*
     * The Corporate Identity Number, from the certificate of incorporation.
     *
     * Not decoration: a company registered under the Companies Act has to
     * display its CIN on its website and its business correspondence, and a
     * payment provider looks for it when matching the site against the entity
     * it is about to settle money to.
     */
    'cin' => env('COMPANY_CIN', 'U53200TZ2026PTC040755'),

    /*
     * One place, because these appear on several pages and in the footer.
     * Changing a mailbox should not mean hunting through Blade files.
     *
     * These are the mailboxes that exist on the mail server, verbatim. The
     * repeated words in info.info@ and investor.investor@ read like mistakes
     * and are not: plain info@ and investors@ were never created, so mail to
     * them bounces. Check the mail admin before tidying any of these.
     */
    'email' => [
        'info' => 'info.info@nexmile.in',
        'investors' => 'investor.investor@nexmile.in',
        'support' => 'support.nexmile@nexmile.in',
        'ceo' => 'ceo@nexmile.in',
        'cfo' => 'cfo@nexmile.in',
    ],

    'website' => 'www.nexmile.in',

];
