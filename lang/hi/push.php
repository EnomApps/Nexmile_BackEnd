<?php

/*
|--------------------------------------------------------------------------
| Push notification copy — Hindi (हिन्दी)
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
            'title' => 'नया ऑर्डर',
            'body' => 'ऑर्डर #:number इंतज़ार कर रहा है। पकाना शुरू करने के लिए स्वीकार करें।',
        ],
        'rider_assigned' => [
            'title' => 'राइडर आ रहा है',
            'body' => ':rider लेने आ रहे हैं। ऑर्डर तैयार रखें।',
        ],
    ],

    'rider' => [
        'offer' => [
            'title' => 'डिलीवरी उपलब्ध',
            'body' => ':restaurant से पिकअप, :area में ड्रॉप। स्वीकार करने के लिए ऐप खोलें।',
        ],
    ],

    'customer' => [
        'accepted' => [
            'title' => 'ऑर्डर स्वीकार हुआ',
            'body' => ':restaurant पका रहा है। लगभग :minutes मिनट।',
        ],
        'rejected' => [
            'title' => 'ऑर्डर स्वीकार नहीं हो सका',
            'body' => ':reason भुगतान पूरा वापस किया जा रहा है।',
        ],
        'ready' => [
            'title' => 'आपका खाना तैयार है',
            'body' => 'डिलीवरी पार्टनर के आने का इंतज़ार है।',
        ],
        'rider_assigned' => [
            'title' => 'जल्द निकल रहा है',
            'body' => ':rider आपका ऑर्डर ले रहे हैं।',
        ],
        'picked_up' => [
            'title' => 'डिलीवरी के लिए निकला',
            'body' => 'आपका ऑर्डर रेस्तराँ से निकल चुका है। ऐप में ट्रैक करें।',
        ],
        'delivered' => [
            'title' => 'डिलीवर हो गया',
            'body' => 'खाने का आनंद लें। रेटिंग देने के लिए टैप करें।',
        ],
        'cancelled' => [
            'title' => 'ऑर्डर रद्द हुआ',
            'body' => ':reason भुगतान पूरा वापस किया जा रहा है।',
        ],
    ],

];
