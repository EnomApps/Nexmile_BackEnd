<?php

/*
|--------------------------------------------------------------------------
| Push notification copy — Tamil (தமிழ்)
|--------------------------------------------------------------------------
| Machine-assisted translation. NEEDS NATIVE SPEAKER REVIEW before launch —
| these are read on a lock screen with no context around them, so a clumsy
| phrase has nowhere to hide.
|
| Brand terms deliberately left in English: Nexmile.
*/

return [

    'merchant' => [
        'new_order' => [
            'title' => 'புதிய ஆர்டர்',
            'body' => 'ஆர்டர் #:number காத்திருக்கிறது. சமைக்கத் தொடங்க ஏற்றுக்கொள்ளுங்கள்.',
        ],
        'rider_assigned' => [
            'title' => 'டெலிவரி நபர் வருகிறார்',
            'body' => ':rider எடுக்க வருகிறார். ஆர்டரைத் தயாராக வைத்திருங்கள்.',
        ],
    ],

    'rider' => [
        'offer' => [
            'title' => 'டெலிவரி கிடைக்கிறது',
            'body' => ':restaurant-ல் இருந்து எடுத்து :area-க்கு சேர்க்க வேண்டும். ஏற்க ஆப்பைத் திறங்கள்.',
        ],
    ],

    'customer' => [
        'accepted' => [
            'title' => 'ஆர்டர் ஏற்கப்பட்டது',
            'body' => ':restaurant சமைக்கிறது. சுமார் :minutes நிமிடங்கள்.',
        ],
        'rejected' => [
            'title' => 'ஆர்டரை ஏற்க முடியவில்லை',
            'body' => ':reason செலுத்திய தொகை முழுமையாகத் திரும்பப் பெறப்படுகிறது.',
        ],
        'ready' => [
            'title' => 'உங்கள் உணவு தயார்',
            'body' => 'டெலிவரி பார்ட்னர் எடுக்கக் காத்திருக்கிறோம்.',
        ],
        'rider_assigned' => [
            'title' => 'விரைவில் புறப்படுகிறது',
            'body' => ':rider உங்கள் ஆர்டரை எடுக்கிறார்.',
        ],
        'picked_up' => [
            'title' => 'டெலிவரிக்கு புறப்பட்டது',
            'body' => 'உங்கள் ஆர்டர் உணவகத்திலிருந்து புறப்பட்டது. ஆப்பில் கண்காணியுங்கள்.',
        ],
        'delivered' => [
            'title' => 'சேர்ப்பிக்கப்பட்டது',
            'body' => 'உணவை ரசியுங்கள். மதிப்பிட தட்டுங்கள்.',
        ],
        'cancelled' => [
            'title' => 'ஆர்டர் ரத்து செய்யப்பட்டது',
            'body' => ':reason செலுத்திய தொகை முழுமையாகத் திரும்பப் பெறப்படுகிறது.',
        ],
    ],

];
