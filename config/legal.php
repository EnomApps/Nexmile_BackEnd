<?php

/*
|--------------------------------------------------------------------------
| Terms, privacy and refunds
|--------------------------------------------------------------------------
| Written to describe what this platform actually does, not adapted from
| another company's policies — those describe a different entity, different
| jurisdictions and different practices, and using them would be both an
| infringement and inaccurate.
|
| Deliberately English only. Machine-translated legal terms are worse than
| untranslated ones.
|
| >>> Read before going live <<<
| The bracketed values below are placeholders. A policy naming the wrong
| entity or an unreachable grievance officer is worse than no policy: it is a
| public promise nobody can keep. Fill them in, then have a lawyer read the
| whole thing — this is an honest description of the system, not legal advice.
*/

return [

    'entity' => 'Nexmile India Pvt. Ltd.',

    /*
     * Left blank on purpose until the real values exist. Blank rows are
     * omitted from the page rather than rendered empty — a policy showing
     * "Officer:" followed by nothing looks broken, and inventing a placeholder
     * name would be worse: it is a public promise nobody can keep.
     *
     * Both must be filled before live payments: Razorpay checks the address
     * against your KYC, and the IT Rules 2021 require a named officer. Set
     * LEGAL_ADDRESS and LEGAL_GRIEVANCE_OFFICER in .env when they exist —
     * no deploy needed, and they stay out of the repository.
     */
    'address' => env('LEGAL_ADDRESS', ''),

    'grievance' => [
        'name' => env('LEGAL_GRIEVANCE_OFFICER', ''),
        'email' => 'support@nexmile.in',
    ],

    'documents' => [

        // ------------------------------------------------------------ TERMS

        'terms' => [
            'title' => 'Terms of Service',
            'meta' => 'The terms on which Nexmile provides its 1 km hyperlocal delivery service.',
            'updated' => '12 August 2026',
            'intro' => 'These terms govern your use of the Nexmile apps and website. By placing an order you accept them.',
            'sections' => [
                [
                    'heading' => 'What Nexmile is',
                    'body' => [
                        'Nexmile is a technology platform. We connect customers with nearby restaurants and shops, and with delivery partners who carry orders between them.',
                        'We do not cook. Food is prepared and sold by the restaurant named on your order, and that restaurant is responsible for its quality, safety, quantity and packaging. Nexmile is responsible for the platform and for arranging delivery.',
                        'Delivery is limited to approximately 1 km from the restaurant. A restaurant further away will not appear in your app, and no setting changes that.',
                    ],
                ],
                [
                    'heading' => 'Your account',
                    'body' => [
                        'You sign in with a one-time code sent to your mobile number or email. Keep access to that number and inbox secure — anyone who receives the code can sign in as you.',
                        'One account per person. You are responsible for activity on your account, and for the accuracy of the delivery address and contact number you provide.',
                        'You must be old enough to enter a contract under Indian law to place an order.',
                    ],
                ],
                [
                    'heading' => 'Placing an order',
                    'body' => [
                        'Prices, availability and opening hours are set by each restaurant and can change at any time. The price you pay is the one shown at checkout, and it is calculated by us — not by the app on your phone.',
                        'Placing an order is an offer to buy. The order is confirmed only when the restaurant accepts it. A restaurant may decline, and will give a reason you can read.',
                        'Delivery times shown are estimates based on preparation time and distance. They are not guarantees, and traffic, weather and kitchen load all affect them.',
                        'Every order shows an itemised total before you pay: item prices, packaging, delivery fee and GST. Nothing is added afterwards.',
                    ],
                ],
                [
                    'heading' => 'Payment',
                    'body' => [
                        'You may pay by cash on delivery or, where offered, online through our payment provider. We do not see or store your card, UPI or bank credentials at any point.',
                        'For cash orders, the amount shown in the app is the amount to hand to the delivery partner. Please have it ready.',
                        'An online order reaches the restaurant only after payment is confirmed. If payment does not complete, the order is cancelled and nothing is charged.',
                    ],
                ],
                [
                    'heading' => 'Cancellation',
                    'body' => [
                        'You can cancel free of charge until the restaurant accepts your order.',
                        'Once the restaurant has accepted, the kitchen may already have started and the order can no longer be cancelled in the app. Contact us and we will help where we can.',
                        'A restaurant may cancel an accepted order if something goes wrong in the kitchen. You are charged nothing, and any payment is refunded in full.',
                        'Full details are in our Refund and Cancellation Policy.',
                    ],
                ],
                [
                    'heading' => 'Delivery',
                    'body' => [
                        'Someone must be available at the address to receive the order. If nobody can be reached and the delivery partner has waited a reasonable time, the order may be treated as delivered and no refund given.',
                        'Please give an accurate address and a working contact number. Deliveries fail most often because a door cannot be found.',
                        'For self-pickup orders, collect from the restaurant within its opening hours.',
                    ],
                ],
                [
                    'heading' => 'Conduct',
                    'body' => [
                        'Treat restaurant staff and delivery partners with respect. We may suspend accounts that abuse them, that place false or repeatedly refused orders, or that attempt to defraud the platform.',
                        'Do not use the platform for anything unlawful, or attempt to interfere with, probe or overload our systems.',
                    ],
                ],
                [
                    'heading' => 'Restaurants and delivery partners',
                    'body' => [
                        'Restaurants must hold a valid FSSAI licence, which we verify before they can take orders. Delivery partners must hold a valid driving licence, vehicle registration and insurance, which we also verify.',
                        'Separate agreements govern their use of the platform, including commission and payouts.',
                    ],
                ],
                [
                    'heading' => 'Liability',
                    'body' => [
                        'We are liable for the platform and for arranging delivery. We are not liable for the quality, safety or preparation of food, which is the restaurant\'s responsibility as its manufacturer and seller.',
                        'Where we are liable, our liability for any order is limited to the amount you paid for it.',
                        'Nothing here limits any right you have under the Consumer Protection Act, 2019 that cannot be limited by agreement.',
                    ],
                ],
                [
                    'heading' => 'Changes and governing law',
                    'body' => [
                        'We may update these terms. The date at the top shows when they last changed, and continuing to use Nexmile means accepting the current version.',
                        'These terms are governed by the laws of India, and the courts of Tamil Nadu have jurisdiction.',
                    ],
                ],
            ],
        ],

        // ---------------------------------------------------------- PRIVACY

        'privacy' => [
            'title' => 'Privacy Policy',
            'meta' => 'What Nexmile collects, why, who we share it with, and how long we keep it.',
            'updated' => '12 August 2026',
            'intro' => 'This explains what we collect, why we need it, and what we do with it. We collect what the service needs to work, and not more.',
            'sections' => [
                [
                    'heading' => 'What we collect',
                    'body' => [
                        'From customers:',
                        [
                            'Your name, mobile number and email address',
                            'Delivery addresses, including their map coordinates',
                            'Your device location while you are choosing where to deliver, if you allow it',
                            'Order history, including what you ordered and any notes you left',
                            'Support messages you send us',
                        ],
                        'From delivery partners, additionally:',
                        [
                            'Identity and licence documents, and bank details for payment',
                            'Live location while you are on duty, which stops when you go offline',
                        ],
                        'From restaurants, additionally:',
                        [
                            'FSSAI, PAN, GST and bank details needed to verify and pay the business',
                        ],
                        'We do not collect or store card numbers, UPI PINs or bank credentials. Those go directly to our payment provider.',
                    ],
                ],
                [
                    'heading' => 'Why we need it',
                    'body' => [
                        'Your location and address decide which restaurants can reach you — a 1 km service cannot work without coordinates.',
                        'Your number is shared with the restaurant and the delivery partner handling your current order, so they can reach you if there is a problem with it. It is not shared for any other purpose, and access ends when the order does.',
                        'A rider\'s live location lets us assign the nearest available partner and lets you watch your order approach. It is visible to a customer only while that partner is carrying their order.',
                        'Identity documents let us verify that a real, licensed business or a real, insured rider is behind an account.',
                    ],
                ],
                [
                    'heading' => 'Who we share it with',
                    'body' => [
                        'Only where the service requires it:',
                        [
                            'The restaurant fulfilling your order — your name, contact number and order',
                            'The delivery partner carrying it — the same, plus your address',
                            'Our payment provider, to take payment and issue refunds',
                            'Our cloud hosting and email providers, who process data on our instructions',
                            'Authorities, where the law requires it',
                        ],
                        'We do not sell your personal data, and we do not share it for anyone else\'s advertising.',
                    ],
                ],
                [
                    'heading' => 'How it is protected',
                    'body' => [
                        'Traffic is encrypted in transit. Identity documents are stored privately and are never publicly accessible — they are served only through short-lived links to people authorised to review them.',
                        'Bank account numbers and Aadhaar numbers are write-only: once saved they are never returned by our systems, not even to the account that entered them.',
                        'Access to personal data inside Nexmile is limited to the people who need it to do their job.',
                    ],
                ],
                [
                    'heading' => 'How long we keep it',
                    'body' => [
                        'Order records, invoices and payment records are kept as long as tax and accounting law requires.',
                        'Verification documents are kept while the account is active and for as long afterwards as we must be able to show why we approved it.',
                        'You can delete your account from the app. We remove your profile and addresses, and keep only what we are legally required to retain — chiefly the financial record of orders already placed.',
                    ],
                ],
                [
                    'heading' => 'Your choices',
                    'body' => [
                        'You can review and correct your details in the app, remove saved addresses, turn off location permission in your device settings, and delete your account.',
                        'Turning off location does not stop the service, but you will have to place the map pin for a delivery address yourself.',
                        'To ask what we hold about you, or to have it corrected or erased, write to our Grievance Officer below.',
                    ],
                ],
                [
                    'heading' => 'Children',
                    'body' => [
                        'Nexmile is not intended for children. We do not knowingly collect data from anyone below the age at which they can enter a contract under Indian law.',
                    ],
                ],
                [
                    'heading' => 'Changes',
                    'body' => [
                        'If we change this policy we will update the date at the top, and tell you in the app if the change is significant.',
                    ],
                ],
            ],
        ],

        // ---------------------------------------------------------- REFUNDS

        'refunds' => [
            'title' => 'Refund and Cancellation Policy',
            'meta' => 'When a Nexmile order can be cancelled, and how refunds are handled.',
            'updated' => '12 August 2026',
            'intro' => 'Food is made to order, so the rules change once a kitchen has started. This sets out exactly when you can cancel and what happens to your money.',
            'sections' => [
                [
                    'heading' => 'Before the restaurant accepts',
                    'body' => [
                        'You can cancel free of charge at any point until the restaurant accepts your order. Nothing has been cooked and nobody has been dispatched.',
                        'If you paid online, the full amount is refunded automatically.',
                    ],
                ],
                [
                    'heading' => 'After the restaurant accepts',
                    'body' => [
                        'The order can no longer be cancelled in the app, because the kitchen may already be preparing it and that food cannot be sold to anyone else.',
                        'If something is wrong, contact us. We look at each case, and where the fault is ours or the restaurant\'s you will not be left out of pocket.',
                    ],
                ],
                [
                    'heading' => 'If the restaurant cancels or declines',
                    'body' => [
                        'You are charged nothing. A restaurant that declines must give a reason, and you will see it in the app.',
                        'Any online payment is refunded in full and automatically — you do not need to ask.',
                        'This applies whether the restaurant declines before starting or has to cancel afterwards.',
                    ],
                ],
                [
                    'heading' => 'If payment does not complete',
                    'body' => [
                        'An online order that is not paid for within a short window is cancelled and never reaches the restaurant.',
                        'If money left your account but the order did not confirm, it was not captured by us and your bank will return it — usually within a few working days. Contact us with the order number if it does not.',
                    ],
                ],
                [
                    'heading' => 'Problems with an order that arrived',
                    'body' => [
                        'Tell us as soon as you can, with the order number and a photograph where it helps. Reports made the same day are far easier to resolve.',
                        'Where an item is missing, wrong, or arrives in an unacceptable condition, we will refund that item or the order as appropriate, having checked with the restaurant.',
                        'Taste and preference are matters for the restaurant. Food that is not to your liking is not by itself grounds for a refund.',
                    ],
                ],
                [
                    'heading' => 'Undelivered orders',
                    'body' => [
                        'If a delivery partner cannot find the address or reach you on the number given, and has waited a reasonable time, the order may be treated as delivered and no refund given.',
                        'If we failed to deliver for a reason within our control, you are refunded in full.',
                    ],
                ],
                [
                    'heading' => 'How refunds are paid',
                    'body' => [
                        'Refunds go back to the original payment method. We send them immediately; your bank or provider then takes its own time, typically five to seven working days.',
                        'Cash orders have nothing to refund unless money was collected, in which case we arrange it with you directly.',
                        'We do not deduct a fee from a refund.',
                    ],
                ],
                [
                    'heading' => 'Food Rescue deals',
                    'body' => [
                        'Surplus items are sold at a discount, in limited quantity and within a time window. The same cancellation and refund rules apply.',
                        'These items are surplus prepared food, discounted because they would otherwise be wasted. They are within their safe period and meet the same food safety requirements as everything else on the platform.',
                    ],
                ],
            ],
        ],
    ],
];
