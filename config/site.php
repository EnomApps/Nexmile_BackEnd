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
