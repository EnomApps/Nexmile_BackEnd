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

    'email' => [
        'info' => 'info@nexmile.in',
        'business' => 'business@nexmile.in',
        'investors' => 'investors@nexmile.in',
    ],

    'website' => 'www.nexmile.in',

];
