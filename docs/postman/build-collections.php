<?php

/**
 * Generates the three Postman collections from one spec so they cannot drift
 * apart in conventions. Run from the project root.
 */

$BASE = 'https://api.nexmile.in/api/v1';

function req(string $name, string $method, string $path, ?array $body, string $desc, array $query = [], array $capture = [], bool $formData = false): array
{
    $headers = [['key' => 'Accept', 'value' => 'application/json']];

    if ($body !== null && ! $formData) {
        $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
    }

    $url = [
        'raw' => '{{base_url}}/'.$path,
        'host' => ['{{base_url}}'],
        'path' => explode('/', $path),
    ];

    if ($query) {
        $url['query'] = $query;
    }

    $r = [
        'name' => $name,
        'request' => [
            'method' => $method,
            'header' => $headers,
            'url' => $url,
            'description' => $desc,
        ],
        'response' => [],
    ];

    if ($formData) {
        $r['request']['body'] = ['mode' => 'formdata', 'formdata' => $body];
    } elseif ($body !== null) {
        $r['request']['body'] = [
            'mode' => 'raw',
            'raw' => json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    if ($capture) {
        $r['event'] = [[
            'listen' => 'test',
            'script' => ['type' => 'text/javascript', 'exec' => $capture],
        ]];
    }

    return $r;
}

/** Capture helper: pull a value out of the response into a collection variable. */
function grab(array $lines): array
{
    return array_merge(['const body = pm.response.json();'], $lines);
}

$tokenCapture = grab([
    'if (body.data && body.data.access_token) {',
    '    pm.collectionVariables.set("access_token", body.data.access_token);',
    '    pm.collectionVariables.set("refresh_token", body.data.refresh_token);',
    '}',
]);

// ---------------------------------------------------------------- CUSTOMER --

$customer = [
    'name' => 'Nexmile — Customer app',
    'description' => <<<'MD'
Everything the **customer app** needs. Rider and merchant endpoints live in their own collections.

**Order to run:** Authentication → Addresses → Restaurants → Cart → Checkout. The OTP verify request stores the tokens, and later requests capture restaurant, cart-line and order ids as they go, so the whole flow runs top to bottom without editing anything.

**Money is a JSON number** and loses its zero fraction: ₹430.00 arrives as `430`. Read it as `num`, never `double`.

**Refresh must be serialised behind one lock.** Two concurrent refreshes with the same token are treated as a stolen token and sign the user out everywhere.

Full walkthrough: `docs/CUSTOMER_APP_FLOW.md`
MD,
    'folders' => [
        ['1. Authentication', 'No separate registration endpoint — the account is created on first successful verification. Email OTP today; when SMS clears DLT you send `phone` instead of `email` on the same endpoint.', [
            req('Request OTP', 'POST', 'auth/otp/request', ['email' => '{{email}}', 'intended_role' => 'customer'],
                "Send `email` **or** `phone`, never both. `intended_role` only matters when the account does not exist yet — an existing user keeps the role they have."),
            req('Verify OTP', 'POST', 'auth/otp/verify', ['email' => '{{email}}', 'code' => '{{otp_code}}', 'device_name' => 'Pixel 8'],
                "Returns the user plus an access/refresh pair, and stores both in collection variables. A customer comes back `active` immediately — no waiting.",
                [], $tokenCapture),
            req('Refresh tokens', 'POST', 'auth/refresh', ['refresh_token' => '{{refresh_token}}'],
                "Call once on a 401, then retry the original request. **One at a time** — parallel refreshes are treated as a stolen token.",
                [], $tokenCapture),
            req('Who am I', 'GET', 'auth/me', null, 'The signed-in user, their role and status.'),
            req('Active sessions', 'GET', 'auth/sessions', null, 'Every device holding a live refresh token.'),
            req('Revoke one session', 'DELETE', 'auth/sessions/{{session_id}}', null, 'Sign one device out.'),
            req('Sign out', 'POST', 'auth/logout', null, 'Ends this session only.'),
            req('Sign out everywhere', 'POST', 'auth/logout-all', null, 'Ends every session on every device.'),
        ]],

        ['2. Profile', 'Shared by every role.', [
            req('My profile', 'GET', 'profile', null, 'Name, phone, email, preferred language.'),
            req('Update profile', 'PATCH', 'profile', ['name' => 'Meena R', 'preferred_locale' => 'ta'],
                "New accounts are named \"Nexmile user\" until this is called — prompt for a real name on first run. `preferred_locale` is `en`, `ta` or `hi`."),
            req('Delete my account', 'DELETE', 'profile', null, 'Irreversible.'),
        ]],

        ['3. Delivery addresses', "**latitude and longitude are required.** Delivery is capped at 1 km and matching is done on coordinates — an address without a pin cannot be matched to any restaurant.", [
            req('My addresses', 'GET', 'addresses', null, 'Newest first. The default one is flagged.', [], grab([
                'if (body.data && body.data.length) {',
                '    pm.collectionVariables.set("address_id", body.data[0].id);',
                '}',
            ])),
            req('Add address', 'POST', 'addresses', [
                'label' => 'home', 'contact_name' => 'Meena', 'contact_phone' => '9876543210',
                'line1' => '12 Anna Salai', 'line2' => null, 'landmark' => 'Opposite the bus stand',
                'city' => 'Madurai', 'pincode' => '625001',
                'latitude' => 9.9252, 'longitude' => 78.1198,
            ], "`label` is `home`, `work` or `other`. Prefill coordinates from GPS and let the user drag a pin.",
                [], grab([
                    'if (body.data && body.data.id) {',
                    '    pm.collectionVariables.set("address_id", body.data.id);',
                    '}',
                ])),
            req('One address', 'GET', 'addresses/{{address_id}}', null, 'A single address.'),
            req('Update address', 'PATCH', 'addresses/{{address_id}}', ['landmark' => 'Next to the temple'], 'Send only the fields being changed.'),
            req('Set as default', 'POST', 'addresses/{{address_id}}/default', null, 'The one checkout preselects.'),
            req('Delete address', 'DELETE', 'addresses/{{address_id}}', null, 'Past orders keep their own copy of the address.'),
        ]],

        ['4. Restaurants', 'Ranked open first, then nearest. Closed shops are listed below open ones rather than hidden — an empty screen at 3pm reads as "Nexmile does not work here".', [
            req('Nearby (by saved address)', 'GET', 'restaurants', null,
                "Gate the UI on `is_open`. `is_accepting_orders` and `within_operating_hours` say **why** it is shut — \"closed until 6pm\" and \"not taking orders\" are different messages.",
                [['key' => 'address_id', 'value' => '{{address_id}}']],
                grab([
                    'if (body.data && body.data.length) {',
                    '    pm.collectionVariables.set("restaurant_id", body.data[0].id);',
                    '}',
                ])),
            req('Nearby (by GPS)', 'GET', 'restaurants', null, 'Same endpoint using raw device coordinates.', [
                ['key' => 'latitude', 'value' => '9.9195'],
                ['key' => 'longitude', 'value' => '78.1193'],
                ['key' => 'search', 'value' => '', 'disabled' => true],
                ['key' => 'service_category', 'value' => 'food', 'disabled' => true],
            ]),
            req('Food Rescue deals nearby', 'GET', 'restaurants/deals', null,
                "Surplus food at a discount, soonest to expire first. Only deals orderable **right now** appear, so the screen never advertises something checkout would refuse. Show the countdown and `portions_left` — a rescue deal is a race.",
                [['key' => 'address_id', 'value' => '{{address_id}}']]),
            req('Restaurant details', 'GET', 'restaurants/{{restaurant_id}}', null, 'Storefront plus the weekly opening hours.'),
            req('Restaurant menu', 'GET', 'restaurants/{{restaurant_id}}/menu', null,
                "**`data.menu` is the whole menu** — loop it and you have every dish.\n\nA restaurant with no categories gets one group named \"Uncategorised\" with a null `id`. There is no second array to remember.\n\nOut-of-stock items are returned, not hidden. Show them struck through.",
                [], grab([
                    'const all = (body.data.menu || []).flatMap(c => c.items || []);',
                    'if (all.length) {',
                    '    pm.collectionVariables.set("menu_item_id", all[0].id);',
                    '}',
                ])),
        ]],

        ['5. Cart', 'One cart per restaurant, and they persist. Glancing at another shop does not empty the basket you started. Every response comes back fully priced — never compute a total in the app.', [
            req('Add item', 'POST', 'restaurants/{{restaurant_id}}/cart/items',
                ['menu_item_id' => '{{menu_item_id}}', 'quantity' => 2, 'option_ids' => [], 'notes' => 'less oil'],
                "Required choices are enforced **here**, not at checkout — a 422 on `option_ids` means open the customisation sheet.\n\nIdentical lines merge; different options stay separate.",
                [], grab([
                    'if (body.data && body.data.items && body.data.items.length) {',
                    '    pm.collectionVariables.set("cart_item_id", body.data.items[0].cart_item_id);',
                    '}',
                ])),
            req('View cart', 'GET', 'restaurants/{{restaurant_id}}/cart', null,
                "Gate the checkout button on **`can_checkout`** — it already accounts for the minimum, sold-out items, an empty cart and the restaurant being closed.\n\n`unavailable_items` names dishes that sold out while the cart sat there. Leave them in place and show them struck through; a basket that quietly shrinks is worse than one that explains itself.",
                [['key' => 'fulfilment_type', 'value' => 'delivery', 'description' => 'delivery | pickup — pickup previews the price with no delivery fee']]),
            req('Change quantity', 'PATCH', 'restaurants/{{restaurant_id}}/cart/items/{{cart_item_id}}', ['quantity' => 3],
                '`quantity: 0` removes the line, so the minus button needs no special case at the boundary.'),
            req('Remove a line', 'DELETE', 'restaurants/{{restaurant_id}}/cart/items/{{cart_item_id}}', null, 'Remove one line.'),
            req('Empty the cart', 'DELETE', 'restaurants/{{restaurant_id}}/cart', null, 'Clear everything.'),
            req('All open carts', 'GET', 'carts', null, 'Every restaurant with an unfinished basket — reads a "you have an unfinished order" banner.'),
        ]],

        ['6. Checkout and orders', 'Cash on delivery only for now. A COD order is created at `placed` and reaches the restaurant immediately.', [
            req('Place order (delivery)', 'POST', 'restaurants/{{restaurant_id}}/cart/checkout', [
                'fulfilment_type' => 'delivery', 'payment_method' => 'cod',
                'address_id' => '{{address_id}}', 'note' => 'Ring the bell twice',
            ], "Everything is re-checked here — availability, opening hours, the 1 km radius, the minimum. Expect 422s even when the cart looked fine, and show `errors.<field>[0]` verbatim:\n\n- `cart` — empty, below the minimum, or an item sold out\n- `merchant` — closed or not taking orders\n- `address_id` — outside the radius, or no pin\n- `payment_method` — not available yet\n\nThe cart is emptied on success, so tapping back cannot place it twice.",
                [], grab([
                    'if (body.data && body.data.id) {',
                    '    pm.collectionVariables.set("order_id", body.data.id);',
                    '}',
                ])),
            req('Place order (self-pickup)', 'POST', 'restaurants/{{restaurant_id}}/cart/checkout',
                ['fulfilment_type' => 'pickup', 'payment_method' => 'cod'],
                'No address needed and no delivery fee charged.'),
            req('Track order', 'GET', 'orders/{{order_id}}/track', null,
                "Small and cheap — poll every few seconds while in flight. Returns the status, the estimate, the pickup code, and the rider's name, phone and **live position** while they are carrying it.\n\n`cancellation_reason` is written by the merchant for the customer — show it verbatim."),
            req('Order history', 'GET', 'orders', null, 'Newest first. Each order keeps its own snapshotted prices and item names.',
                [['key' => 'active', 'value' => '1', 'description' => 'in-flight only, for a home-screen banner', 'disabled' => true]]),
            req('Order details', 'GET', 'orders/{{order_id}}', null, 'Full order with items, options and the status timeline.'),
            req('Tax invoice', 'GET', 'orders/{{order_id}}/invoice', null,
                'Returns a **printable HTML page**, not JSON — open it in a webview or the system browser.'),
            req('Cancel order', 'POST', 'orders/{{order_id}}/cancel', ['reason' => 'Ordered by mistake'],
                'Only while the status is `placed`. Once the restaurant accepts, this returns 422 — hide the button as soon as the status moves on.'),
        ]],
    ],
    'variables' => ['base_url' => $BASE, 'email' => 'customer@example.in', 'otp_code' => '', 'access_token' => '',
        'refresh_token' => '', 'session_id' => '', 'address_id' => '', 'restaurant_id' => '',
        'menu_item_id' => '', 'cart_item_id' => '', 'order_id' => ''],
];

// ------------------------------------------------------------------- RIDER --

$rider = [
    'name' => 'Nexmile — Rider app',
    'description' => <<<'MD'
Everything the **rider app** needs. Customer and merchant endpoints live in their own collections.

**Auth is the same as the customer app except `intended_role: "rider"`.** There is no separate registration endpoint — the account is created on first verification, and comes back `pending` until an admin approves the documents.

**There are no push notifications yet.** A rider only sees a new order while the app is open and polling, so build the board as something they watch — poll `/rider/orders/available` every 10–15 seconds while it is foregrounded.

Full walkthrough: `docs/RIDER_APP_FLOW.md`
MD,
    'folders' => [
        ['1. Authentication', "Identical to the customer app except `intended_role`. **Refuse non-riders**: if `role !== \"rider\"`, sign them out with \"This number is not registered as a delivery partner.\"", [
            req('Request OTP', 'POST', 'auth/otp/request', ['email' => '{{email}}', 'intended_role' => 'rider'],
                'The only difference from the customer app.'),
            req('Verify OTP', 'POST', 'auth/otp/verify', ['email' => '{{email}}', 'code' => '{{otp_code}}', 'device_name' => 'Redmi 12'],
                "The account comes back **`pending`** and that is correct — send them into the onboarding wizard, not a home screen.",
                [], $tokenCapture),
            req('Refresh tokens', 'POST', 'auth/refresh', ['refresh_token' => '{{refresh_token}}'],
                "One at a time. A rider signed out mid-shift loses an active delivery.", [], $tokenCapture),
            req('Sign out', 'POST', 'auth/logout', null, 'Ends this session.'),
        ]],

        ['2. Onboarding wizard', "**Drive every step from `GET /rider/kyc`** — never from local state. Riders change devices, and an admin can reject a document *after* submission.", [
            req('KYC status', 'GET', 'rider/kyc', null,
                "Returns `missing_documents`, `can_submit` and a per-document `rejection_reason`. Render the wizard from this and enable Submit on `can_submit`.\n\nRejection is a **normal path**, not an error — blurred photos are the most common outcome. Show the reason per document and reopen the wizard for exactly those."),
            req('Profile details', 'PATCH', 'rider/profile', [
                'full_name' => 'Selvam K', 'date_of_birth' => '1996-04-12',
                'vehicle_type' => 'motorcycle', 'vehicle_number' => 'TN59AB1234',
            ], '`vehicle_type` is `bicycle`, `motorcycle`, `scooter` or `ev`.'),
            req('Document numbers', 'PATCH', 'rider/kyc/details', [
                'aadhaar_number' => '123456789012', 'pan' => 'ABCDE1234F',
                'driving_licence_no' => 'TN5920190001234', 'driving_licence_expiry' => '2030-04-12',
                'vehicle_number' => 'TN59AB1234', 'rc_number' => 'RC123456',
                'insurance_number' => 'INS987654', 'insurance_expiry' => '2027-03-31',
                'bank_account_name' => 'Selvam K', 'bank_account_number' => '123456789012',
                'bank_ifsc' => 'SBIN0001234',
            ], "All optional — send what you have. Licence and insurance expiry **must be in the future**; surface that inline on the field.\n\nAadhaar and the bank account number are never returned once saved, so do not expect to read them back to prefill."),
            req('Upload a document', 'POST', 'rider/kyc/documents', [
                ['key' => 'type', 'value' => 'aadhaar_front', 'type' => 'text',
                    'description' => 'aadhaar_front | aadhaar_back | pan_card | driving_licence | vehicle_rc | vehicle_insurance'],
                ['key' => 'file', 'type' => 'file', 'src' => []],
            ], "**multipart/form-data.** JPG, PNG or PDF up to 10 MB — compress camera photos, a modern phone image exceeds that.\n\nRe-uploading a type replaces the previous file. Approved documents cannot be replaced.",
                [], [], true),
            req('Remove a document', 'DELETE', 'rider/kyc/documents/{{document_id}}', null, 'Pending or rejected only.'),
            req('Submit for verification', 'POST', 'rider/kyc/submit', null,
                'Once `can_submit` is true. Then a waiting screen — verification is a manual decision, usually within two working days.'),
        ]],

        ['3. Profile and duty', '', [
            req('My profile', 'GET', 'rider/profile', null,
                "Gate the duty toggle on **`can_go_online`** — paperwork only, true as soon as KYC is verified and both expiry dates are current.\n\n**Not `can_accept_orders`.** That one also requires `duty_status == available`, so it is false for every offline rider; gating the Go online button on it is a catch-22 the rider can never escape. Use it for the board and the accept button instead.\n\nWhen `can_go_online` is false, show **`offline_reason`** verbatim — it is the same string the duty-status endpoint refuses with, so the banner and the error cannot disagree.\n\n`kyc.documents_expired` and the two expiry dates are how you warn a rider a week ahead — otherwise they find out at 8pm when a shift starts."),
            req('Go on duty', 'POST', 'rider/duty-status', ['duty_status' => 'available'],
                "Accepts `offline`, `available`, `on_break` only. `on_order` is set by dispatch — a rider cannot declare themselves busy to skip the queue.\n\n403 with a specific reason when not eligible: documents unverified, or licence/insurance expired. Show the message.\n\n422 while carrying an order — finish or hand it back first."),
            req('Go offline', 'POST', 'rider/duty-status', ['duty_status' => 'offline'], 'Refused while an order is in hand.'),
        ]],

        ['4. Working a shift', 'Ready orders sit on a board that nearby on-duty riders poll, and the first to accept wins.', [
            req('Send location', 'POST', 'rider/location', ['latitude' => 9.9195, 'longitude' => 78.1193],
                "Every few seconds while on duty. **Doubles as a heartbeat** — stop pinging and the rider drops out of dispatch once the TTL lapses.\n\nAn offline rider gets 200 with `tracking: false`, not an error. Just stop the timer.\n\nBattery matters: coarse interval when idle, fine while carrying an order."),
            req('Order board', 'GET', 'rider/orders/available', null,
                "Nearest restaurant first. Poll every 10–15 seconds while the screen is open.\n\n**`dropoff` is absent until you accept** — a board every on-duty rider can poll must not double as a list of where customers live.\n\n**`collect_cash`** is what to collect at the door: the full total for COD, `0` if prepaid. Getting it wrong costs the rider their own money.\n\nAn empty board is **not an error** — offline, unverified, already carrying an order, or no position sent. `meta.can_accept` says which, so you can show the right empty state.",
                [], grab([
                    'if (body.data && body.data.length) {',
                    '    pm.collectionVariables.set("order_id", body.data[0].id);',
                    '}',
                ])),
            req('Accept an order', 'POST', 'rider/orders/{{order_id}}/accept', null,
                "First to accept wins. A 422 saying **\"Another rider took this order\"** is expected on a shared board — refresh the list and move on, do not show it as a failure."),
            req('Confirm pickup', 'POST', 'rider/orders/{{order_id}}/pickup', ['pickup_code' => '{{pickup_code}}'],
                "The merchant reads out four digits. A wrong code is a 422 on `pickup_code` — let the rider retype rather than bouncing them out.\n\nThere is no \"I collected it\" button without the code: it is the evidence a disputed delivery is settled with."),
            req('Hand the order back', 'POST', 'rider/orders/{{order_id}}/release', ['reason' => 'Puncture'],
                "Puts it back on the board for another rider. **Only before collection** — once the food is in the bag it has to be delivered."),
            req('Confirm delivery', 'POST', 'rider/orders/{{order_id}}/deliver', null,
                'Closes the order, sets the rider back to `available` and increments `completed_deliveries`.'),
            req('My current delivery', 'GET', 'rider/orders', null,
                '`?active=1` is what the working screen polls after a restart — never resume from local state, a shift outlives an app process.',
                [['key' => 'active', 'value' => '1']]),
            req('Order details', 'GET', 'rider/orders/{{order_id}}', null,
                'Full order with pickup and dropoff. 404 if it belongs to another rider.'),
        ]],
    ],
    'variables' => ['base_url' => $BASE, 'email' => 'rider@example.in', 'otp_code' => '', 'access_token' => '',
        'refresh_token' => '', 'document_id' => '', 'order_id' => '', 'pickup_code' => ''],
];

// ---------------------------------------------------------------- MERCHANT --

$merchant = [
    'name' => 'Nexmile — Merchant',
    'description' => <<<'MD'
Everything a **merchant** can do over the API.

**Merchants sign in with a password, not an OTP** — unlike customers and riders. Registration happens on the website at `nexmile.in/merchants/register`; the API endpoint exists for completeness.

Restaurants use the web portal at `nexmile.in/merchants` today, so this collection is mainly for a future merchant app and for testing. Everything here has a portal equivalent.

**Update is POST, not PATCH, on menu items** — PHP does not parse multipart bodies on PATCH, so a PATCH upload arrives with an empty `$_FILES`.

Full reference: `docs/MENU_AND_ORDERS.md`
MD,
    'folders' => [
        ['1. Authentication', 'Password-based. Registration is on the website, not in the app.', [
            req('Register', 'POST', 'merchant/register', [
                'owner_name' => 'Veeraiyan', 'phone' => '9876500111', 'email' => '{{email}}',
                'password' => '{{password}}', 'password_confirmation' => '{{password}}',
                'business_name' => 'Ponnusamy Hotel', 'business_phone' => '9876500222',
                'address_line1' => '1 Main Road', 'city' => 'Madurai', 'pincode' => '625001',
                'latitude' => 9.9195, 'longitude' => 78.1193,
            ], "Creates the account as `pending`. In practice merchants register on `nexmile.in/merchants/register`.",
                [], $tokenCapture),
            req('Sign in', 'POST', 'merchant/login', [
                'identifier' => '{{email}}', 'password' => '{{password}}', 'device_name' => 'Counter tablet',
            ], '`identifier` accepts the registered email **or** the 10-digit mobile number.', [], $tokenCapture),
            req('Who am I', 'GET', 'merchant/me', null, 'The signed-in merchant account.'),
            req('Sign out', 'POST', 'merchant/logout', null, 'Ends this session.'),
        ]],

        ['2. Profile and storefront', 'What a customer sees, and whether the shop is open.', [
            req('Business profile', 'GET', 'merchant/profile', null, 'KYC fields are read-only here.'),
            req('Update profile', 'PATCH', 'merchant/profile', [
                'business_name' => 'Ponnusamy Hotel', 'description' => 'Chettinad since 1982',
                'business_phone' => '9876500222', 'latitude' => 9.9195, 'longitude' => 78.1193,
                'avg_prep_time_minutes' => 20, 'packaging_fee' => 10,
                'min_order_value' => 99, 'supports_pickup' => true,
            ], "**Coordinates matter more than anything else here.** A merchant without them is invisible to every customer.\n\n`commission_rate` is deliberately **not** settable — it is a contract term, changed only by Nexmile."),
            req('Open or close the shop', 'POST', 'merchant/accepting-orders', ['is_accepting_orders' => true],
                "The most-used control a merchant has. Refuses to open with 403 until KYC is verified and the FSSAI licence is current."),
            req('Opening hours', 'GET', 'merchant/storefront/hours', null,
                'Plus `is_open_now` and `within_operating_hours`.'),
            req('Set opening hours', 'PUT', 'merchant/storefront/hours', ['hours' => [
                ['day_of_week' => 1, 'opens_at' => '11:00', 'closes_at' => '22:00'],
                ['day_of_week' => 2, 'is_closed' => true],
            ]], "**Replaces the whole week**, never merges — a partial write could leave the shop open on a day just closed. `day_of_week` is 0 Sunday to 6 Saturday; days omitted are closed.\n\nA closing time earlier than the opening time means past midnight: 18:00–01:00 is open all evening."),
            req('Upload logo or banner', 'POST', 'merchant/storefront/image', [
                ['key' => 'type', 'value' => 'logo', 'type' => 'text', 'description' => 'logo | banner'],
                ['key' => 'file', 'type' => 'file', 'src' => []],
            ], 'multipart. JPG, PNG or WebP up to 4 MB. The logo is the first thing a customer sees.', [], [], true),
            req('Remove logo or banner', 'DELETE', 'merchant/storefront/image/logo', null, 'Path segment is `logo` or `banner`.'),
        ]],

        ['3. Verification (KYC)', 'FSSAI certificate, PAN and bank proof are required before a merchant can trade.', [
            req('KYC status', 'GET', 'merchant/kyc', null, 'What is uploaded, what is missing, and whether it can be submitted.'),
            req('Upload a document', 'POST', 'merchant/kyc/documents', [
                ['key' => 'type', 'value' => 'fssai_certificate', 'type' => 'text',
                    'description' => 'fssai_certificate | pan_card | bank_proof | gst_certificate | shopfront_photo'],
                ['key' => 'file', 'type' => 'file', 'src' => []],
            ], 'multipart. JPG, PNG or PDF up to 10 MB.', [], [], true),
            req('Remove a document', 'DELETE', 'merchant/kyc/documents/{{document_id}}', null, 'Pending or rejected only.'),
            req('Submit for verification', 'POST', 'merchant/kyc/submit', null, 'Usually reviewed within two working days.'),
        ]],

        ['4. Menu — categories', 'Deleting a category keeps its items and clears their grouping.', [
            req('List categories', 'GET', 'merchant/categories', null, 'With item counts.', [], grab([
                'if (body.data && body.data.length) {',
                '    pm.collectionVariables.set("category_id", body.data[0].id);',
                '}',
            ])),
            req('Create category', 'POST', 'merchant/categories', ['name' => 'Biryani', 'sort_order' => 0, 'is_active' => true],
                'Inactive categories are hidden from customers.', [], grab([
                    'if (body.data && body.data.id) {',
                    '    pm.collectionVariables.set("category_id", body.data.id);',
                    '}',
                ])),
            req('Update category', 'PATCH', 'merchant/categories/{{category_id}}', ['name' => 'Biryani and rice'], 'Send only what changes.'),
            req('Delete category', 'DELETE', 'merchant/categories/{{category_id}}', null, 'Items survive and become uncategorised.'),
        ]],

        ['5. Menu — items', '', [
            req('List items', 'GET', 'merchant/menu-items', null, 'Filterable.', [
                ['key' => 'category_id', 'value' => '{{category_id}}', 'disabled' => true],
                ['key' => 'is_available', 'value' => '1', 'disabled' => true],
                ['key' => 'search', 'value' => 'biryani', 'disabled' => true],
            ], grab([
                'if (body.data && body.data.length) {',
                '    pm.collectionVariables.set("menu_item_id", body.data[0].id);',
                '}',
            ])),
            req('Create item', 'POST', 'merchant/menu-items', [
                ['key' => 'name', 'value' => 'Chicken Biryani', 'type' => 'text'],
                ['key' => 'description', 'value' => 'Seeraga samba rice', 'type' => 'text'],
                ['key' => 'category_id', 'value' => '{{category_id}}', 'type' => 'text'],
                ['key' => 'price', 'value' => '200', 'type' => 'text'],
                ['key' => 'compare_at_price', 'value' => '', 'type' => 'text', 'description' => 'struck-through "was" price'],
                ['key' => 'gst_rate', 'value' => '5', 'type' => 'text', 'description' => '5 | 12 | 18'],
                ['key' => 'is_veg', 'value' => '0', 'type' => 'text'],
                ['key' => 'contains_egg', 'value' => '0', 'type' => 'text'],
                ['key' => 'is_available', 'value' => '1', 'type' => 'text'],
                ['key' => 'prep_time_minutes', 'value' => '20', 'type' => 'text'],
                ['key' => 'image', 'type' => 'file', 'src' => []],
            ], 'multipart so the photo goes with the item in one call. GST is restricted to statutory rates.',
                [], grab([
                    'if (body.data && body.data.id) {',
                    '    pm.collectionVariables.set("menu_item_id", body.data.id);',
                    '}',
                ]), true),
            req('One item', 'GET', 'merchant/menu-items/{{menu_item_id}}', null, 'With its option groups.'),
            req('Update item', 'POST', 'merchant/menu-items/{{menu_item_id}}', [
                ['key' => 'price', 'value' => '210', 'type' => 'text'],
                ['key' => 'image', 'type' => 'file', 'src' => []],
            ], '**POST, not PATCH** — PHP does not parse multipart bodies on PATCH.', [], [], true),
            req('Out of stock / back on', 'POST', 'merchant/menu-items/{{menu_item_id}}/availability', ['is_available' => false],
                'Its own endpoint because it is the one action performed mid-service, on a phone.'),
            req('Remove photo', 'DELETE', 'merchant/menu-items/{{menu_item_id}}/image', null, 'Leaves the item in place.'),
            req('Delete item', 'DELETE', 'merchant/menu-items/{{menu_item_id}}', null, 'Soft delete — past orders keep resolving.'),
            req('Reorder the menu', 'POST', 'merchant/menu-items/reorder', ['ids' => [3, 1, 2]],
                'The whole ordered list in one call, so the menu cannot be left half-reordered.'),
        ]],

        ['6. Menu — choices', '"Spice level", "Add-ons", "Choose your rice". Without these half a menu cannot be expressed.', [
            req('List an item\'s groups', 'GET', 'merchant/menu-items/{{menu_item_id}}/option-groups', null, 'With their options.'),
            req('Create a group', 'POST', 'merchant/menu-items/{{menu_item_id}}/option-groups', [
                'name' => 'Spice level', 'selection' => 'single', 'is_required' => true,
                'options' => [
                    ['name' => 'Mild', 'price_delta' => 0],
                    ['name' => 'Medium', 'price_delta' => 0],
                    ['name' => 'Extra spicy', 'price_delta' => 20],
                ],
            ], "**Options are sent with the group**, never separately — a group with no choices is a dead end at checkout.\n\nRefused if a customer could never satisfy it: min above max, min above the number of choices, single-choice with max above 1, or required with min 0.",
                [], grab([
                    'if (body.data && body.data.id) {',
                    '    pm.collectionVariables.set("option_group_id", body.data.id);',
                    '    if (body.data.options && body.data.options.length) {',
                    '        pm.collectionVariables.set("option_id", body.data.options[0].id);',
                    '    }',
                    '}',
                ])),
            req('Update a group', 'PATCH', 'merchant/option-groups/{{option_group_id}}', [
                'name' => 'Spice level',
                'options' => [
                    ['id' => '{{option_id}}', 'name' => 'Mild', 'price_delta' => 0],
                    ['name' => 'Fiery', 'price_delta' => 25],
                ],
            ], 'Sending `options` reconciles the list: entries with an `id` are updated in place, new ones created, anything absent removed. Keeping ids matters — historical order lines reference them.'),
            req('Delete a group', 'DELETE', 'merchant/option-groups/{{option_group_id}}', null, 'Removes the group and its choices.'),
            req('One choice out of stock', 'POST', 'merchant/options/{{option_id}}/availability', ['is_available' => false],
                'The kitchen runs out of paneer, not of the whole "choose your filling" group.'),
        ]],

        ['7. Orders', 'Unpaid orders never appear — `pending_payment` is filtered out of every list.', [
            req('Live queue', 'GET', 'merchant/orders', null,
                "Oldest first: the ticket waiting longest needs attention. `?history=1` for completed ones, newest first.",
                [
                    ['key' => 'history', 'value' => '1', 'disabled' => true],
                    ['key' => 'status', 'value' => 'placed', 'disabled' => true],
                    ['key' => 'per_page', 'value' => '25', 'disabled' => true],
                ], grab([
                    'if (body.data && body.data.length) {',
                    '    pm.collectionVariables.set("order_id", body.data[0].id);',
                    '}',
                ])),
            req('Order details', 'GET', 'merchant/orders/{{order_id}}', null, 'Items, options, customer, timeline and the pickup code.'),
            req('Accept', 'POST', 'merchant/orders/{{order_id}}/accept', ['prep_minutes' => 25],
                'Optional `prep_minutes` — without it the merchant\'s configured average is used, so the customer always gets an estimate.'),
            req('Reject', 'POST', 'merchant/orders/{{order_id}}/reject', ['reason' => 'Kitchen closed early tonight, sorry.'],
                'Only before accepting. Reason required, minimum 10 characters — the customer reads it. No cancellation fee.'),
            req('Start preparing', 'POST', 'merchant/orders/{{order_id}}/preparing', null, 'From `accepted`.'),
            req('Ready for pickup', 'POST', 'merchant/orders/{{order_id}}/ready', null,
                'Puts a delivery order onto the rider board. The pickup code becomes visible at this point.'),
            req('Cancel after accepting', 'POST', 'merchant/orders/{{order_id}}/cancel', ['reason' => 'Gas cylinder ran out, sorry.'],
                "Different act from rejecting: the kitchen said yes and then something went wrong. Refused once a rider holds the order — at that point cancelling is a phone call."),
        ]],
    ],
    'variables' => ['base_url' => $BASE, 'email' => 'merchant@example.in', 'password' => 'secret123',
        'access_token' => '', 'refresh_token' => '', 'document_id' => '', 'category_id' => '',
        'menu_item_id' => '', 'option_group_id' => '', 'option_id' => '', 'order_id' => ''],
];

// ------------------------------------------------------------------ WRITE ---

foreach ([
    'Nexmile-Customer' => $customer,
    'Nexmile-Rider' => $rider,
    'Nexmile-Merchant' => $merchant,
] as $file => $spec) {
    $items = [];

    foreach ($spec['folders'] as [$name, $desc, $requests]) {
        $items[] = array_filter([
            'name' => $name,
            'description' => $desc ?: null,
            'item' => $requests,
        ], fn ($v) => $v !== null);
    }

    $collection = [
        'info' => [
            'name' => $spec['name'],
            'description' => $spec['description'],
            'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
        ],
        'auth' => [
            'type' => 'bearer',
            'bearer' => [['key' => 'token', 'value' => '{{access_token}}', 'type' => 'string']],
        ],
        'item' => $items,
        'variable' => array_map(
            fn ($k, $v) => ['key' => $k, 'value' => $v],
            array_keys($spec['variables']),
            array_values($spec['variables']),
        ),
    ];

    $path = "docs/postman/{$file}.postman_collection.json";
    file_put_contents($path, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

    $n = 0;
    foreach ($items as $folder) {
        $n += count($folder['item']);
    }
    printf("%-34s %d folders · %d requests\n", $file, count($items), $n);
}
