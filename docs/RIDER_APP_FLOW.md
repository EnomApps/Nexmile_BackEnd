# Rider app — complete flow

Base URL `https://api.nexmile.in/api/v1` · always send `Accept: application/json`
Interactive docs: **https://api.nexmile.in/docs/api**
Postman: `docs/postman/Nexmile-Rider.postman_collection.json`

## Read this first

**The whole shift is live.** Onboarding, going on duty, taking an order,
collecting it and delivering it all have real endpoints today.

Only **earnings** is unbuilt, and riders can be paid manually until it lands.

| # | Screen | Status |
|---|---|---|
| 1 | Splash / session restore | **Live** |
| 2 | Sign in (OTP) | **Live** |
| 3 | Onboarding wizard — profile | **Live** |
| 4 | Onboarding wizard — document numbers | **Live** |
| 5 | Onboarding wizard — 6 uploads | **Live** |
| 6 | Submit + waiting for verification | **Live** |
| 7 | Duty toggle (go online / offline) | **Live** |
| 8 | Location ping while on duty | **Live** |
| 9 | **Incoming order card — rings, counts down, accept or decline** | **Live** |
| 9b | Order board — accept a job off a list | **Live** |
| 10 | Pickup — confirm the code | **Live** |
| 11 | Delivery — confirm handover | **Live** |
| 12 | Earnings and history | Not built (EP13) |

### Two ways an order reaches a rider, and you need both

**The card** (screen 9) is the one that matters. The order is offered to the
riders nearest the restaurant, the phone rings, a full-screen card counts down
from 30 seconds, and the first rider to accept gets it. This is the Swiggy /
Zomato model and it is what the product needs: nobody reads a list while on a
bike.

**The board** (screen 9b) is the same orders as a pollable list. Keep it. It is
the fallback when the server is running `DISPATCH_MODE=board`, it is what a
rider looks at while idle, and it costs you one screen you have already built.

Which one is live is a **server setting**, not something the app chooses. Build
both; if offers are switched off, `/offers/current` simply always returns
`null` and the board fills up instead.

---

## 1. Splash

Read the stored `refresh_token` → `POST /auth/refresh`. Success, then decide
where to land by calling `GET /rider/profile`:

- `can_go_online: true` → screen 7 (duty)
- otherwise → resume the wizard at whatever step `GET /rider/kyc` says is next

Never resume from local state. Riders change devices, and an admin can reject a
document *after* it was submitted — the server knows where they are, the phone
does not.

## 2. Sign in

Identical to the customer app **except one field**:

```
POST /auth/otp/request
{ "email": "rider@example.com", "intended_role": "rider" }
```

```
POST /auth/otp/verify
{ "email": "rider@example.com", "code": "123456", "device_name": "Redmi 12" }
```

There is **no separate registration endpoint** — the account is created on
first verification.

**The account comes back `status: "pending"`, and that is correct.** A rider
cannot work until an admin has approved their documents. Send them into the
wizard, not to an empty home screen.

**Refuse non-riders here.** If `role !== "rider"`, sign them out and show
"This number is not registered as a delivery partner." Otherwise a customer who
downloads the wrong app lands on a dead duty screen with no explanation.

The reverse is fine and expected: a rider can use the *customer* app with the
same account to order their own food. See `docs/ROLES.md`.

## 3–6. The onboarding wizard

**Drive every step from `GET /rider/kyc`.** It returns what is uploaded, what
is missing, and whether submission is allowed:

```json
{
  "data": {
    "status": "pending",
    "missing_documents": ["vehicle_rc", "vehicle_insurance"],
    "can_submit": false,
    "documents": [
      {
        "id": 4,
        "type": "driving_licence",
        "label": "Driving licence",
        "status": "rejected",
        "rejection_reason": "Photo is blurred, the number is unreadable.",
        "download_url": "https://…signed…"
      }
    ]
  }
}
```

Render the wizard from `missing_documents` and enable Submit on `can_submit`.
Do not track progress locally.

### 3. Profile

`PATCH /rider/profile` — `full_name`, `date_of_birth`, `vehicle_type`,
`vehicle_number`.

`vehicle_type` is one of **`bicycle`, `motorcycle`, `scooter`, `ev`**.

### 4. Document numbers

`PATCH /rider/kyc/details` — all fields optional, send what you have:

| Field | Format |
|---|---|
| `aadhaar_number` | 12 digits |
| `pan` | `ABCDE1234F` |
| `driving_licence_no` | ≤ 20 chars |
| `driving_licence_expiry` | date, **must be in the future** |
| `vehicle_number` | ≤ 15 chars |
| `rc_number` | ≤ 30 chars |
| `insurance_number` | ≤ 40 chars |
| `insurance_expiry` | date, **must be in the future** |
| `bank_account_name` | ≤ 255 |
| `bank_account_number` | ≤ 30 |
| `bank_ifsc` | `SBIN0001234` |

An expired licence or insurance is rejected at this step with a clear message —
surface it inline on the field, not as a toast.

Bank details are how the rider gets paid. Aadhaar and bank account number are
**never returned by the API** once saved, only whether they are on file. Do not
expect to read them back to prefill.

### 5. Six uploads

`POST /rider/kyc/documents` as **`multipart/form-data`** with `type` and `file`.

1. `aadhaar_front`
2. `aadhaar_back`
3. `pan_card`
4. `driving_licence`
5. `vehicle_rc`
6. `vehicle_insurance`

JPG, PNG or PDF, **max 10 MB**. Compress camera photos before upload — a modern
phone image can exceed that, and an oversized file fails with a size message
rather than succeeding quietly.

Re-uploading a type replaces the previous file. `DELETE /rider/kyc/documents/{id}`
removes a pending or rejected one. **Approved documents cannot be replaced or
deleted** — they are the evidence behind a verification decision.

### 6. Submit and wait

`POST /rider/kyc/submit` once `can_submit` is true.

Then a waiting screen. Verification is a manual admin decision, usually within
two working days. Poll `GET /rider/kyc` on resume.

If it comes back `rejected`, show `rejection_reason` per document and reopen
the wizard for exactly those documents. This is a normal path, not an error —
blurred photos are the single most common reason.

## 7. Duty toggle

```
POST /rider/duty-status
{ "duty_status": "available" }
```

Accepts **`offline`, `available`, `on_break`** only.

`on_order` exists but is **set by dispatch, never by the app.** A rider cannot
declare themselves busy to skip the queue.

Going `available` returns **403** with a specific reason when the rider is not
eligible:

- *"Your documents are still being verified."* — KYC not approved yet
- *"Your licence or insurance has expired. Upload current documents to go online."*

**That second one will happen to working riders, months after onboarding.**
`GET /rider/profile` returns `kyc.documents_expired` and the two expiry dates —
warn a week ahead rather than letting someone discover it at 8pm when they try
to start a shift.

Gate the whole working UI on **`can_go_online`** from `GET /rider/profile`.

**Not `can_accept_orders`.** They read alike and mean different things:

| Field | Question it answers | While offline |
|---|---|---|
| `can_go_online` | May this rider go on duty at all? Paperwork only. | `true` if verified |
| `can_accept_orders` | Is this rider dispatchable *right now*? | always `false` |

`can_accept_orders` includes `duty_status == available`, so gating the Go
online button on it is a catch-22 — false until the rider is online, and they
cannot get online while it is false. Use it for the board and the accept
button, never for the toggle that gets them there.

When `can_go_online` is `false`, show **`offline_reason`** verbatim rather than
your own copy. It is the same string `POST /rider/duty-status` refuses with, so
the banner and the error cannot disagree. It is `null` when nothing is blocking.

---

## 8. Location ping

```
POST /rider/location   { "latitude": 9.9195, "longitude": 78.1193 }
```

Send every few seconds while on duty. Positions go to Redis, which is what
dispatch searches.

**This doubles as a heartbeat.** Stop pinging and the rider drops out of
dispatch once the TTL lapses — which is the correct behaviour for a killed app,
but means a rider who is genuinely working must keep pinging.

An offline rider gets `200` with `data.tracking: false`, not an error. Just
stop the timer.

Battery matters more here than anywhere in the customer app — this runs for a
whole shift. A coarse interval when idle and a fine one while carrying an order
is the right shape.

## 9. Incoming order card

### The push that starts it

A **data-only, high-priority FCM message**. No `notification` block, so nothing
is drawn by the system — `onMessageReceived` is called and the app puts the card
up itself. That is the only way to get something that rings for thirty seconds,
counts down, and takes itself down again.

```json
{
  "type": "ORDER_OFFER",
  "offer_id": "01JCQ2W8XK3N4P5Q6R7S8T9V0W",
  "order_id": "41",
  "order_number": "NX261113ABCD",
  "expires_at": "2026-11-13T18:42:31+00:00",
  "seconds_left": "30",
  "restaurant": "Ponnusamy Hotel",
  "restaurant_area": "Madurai",
  "restaurant_latitude": "9.9195",
  "restaurant_longitude": "78.1193",
  "pickup_distance_metres": "240",
  "dropoff_area": "Madurai",
  "delivery_distance_metres": "620",
  "item_count": "3",
  "earnings": "34.50",
  "collect_cash": "430.00",
  "wave": "1"
}
```

**Every value is a string.** FCM data payloads are string-to-string; parse the
numbers yourself.

**There is no customer address and no customer phone.** One offer goes to up to
fifteen riders and fourteen of them will never touch this order. You get both
the moment the accept returns 200.

**Count down to `expires_at`, not from `seconds_left`.** The server owns expiry.
`seconds_left` is there only so a phone with a badly wrong clock shows something
sensible instead of a card that is already over or never ends.

**Dedupe on `offer_id`.** The same offer can arrive twice.

The message carries an FCM TTL equal to the window, so a card that would arrive
after its window is dropped rather than held for a phone that is switched off.

### Read it back; do not trust the push alone

```
GET /rider/offers/current
```

```json
{
  "data": {
    "offer_id": "01JCQ2W8XK3N4P5Q6R7S8T9V0W",
    "status": "pending",
    "expires_at": "2026-11-13T18:42:31+00:00",
    "seconds_left": 24,
    "wave": 1,
    "order": {
      "id": 41, "order_number": "NX261113ABCD", "item_count": 3,
      "order_value": 430, "collect_cash": 430, "delivery_distance_metres": 620,
      "customer_note": "No onions",
      "status": "accepted", "ready_at": null, "estimated_prep_minutes": 20
    },
    "pickup": { "name": "Ponnusamy Hotel", "area": "Madurai",
                "latitude": 9.9195, "longitude": 78.1193,
                "distance_metres": 240 },
    "dropoff": { "area": "Madurai" },
    "earnings": 34.5
  }
}
```

`"data": null` means nothing is ringing. That is a **200**, not a 404 — "you
have no offer" is a question with an answer.

**Call this on cold start and every time the app resumes.** A data message can
be dropped by the system, arrive twice, or land on a phone that was switched
off. A rider staring at a card for an order somebody else collected ten minutes
ago is worse than a rider who saw no card at all.

Note `order.status` can be `accepted` or `preparing` — **the food may still be
cooking when the offer goes out**, deliberately, so the rider travels while it
cooks. `estimated_prep_minutes` is there to tell the rider the wait is expected
rather than a problem.

### Accepting

```
POST /rider/offers/{offer_id}/accept
```

Five answers, and they are different on purpose:

| Code | Means | Do this |
|---|---|---|
| **200** | it is yours | open the trip screen; the body is the full order, with the drop address and the customer's phone |
| **409** | somebody was faster, or the order was cancelled | close the card, show "Order taken by another rider" |
| **410** | the window closed | close the card **silently** — the countdown already ran out on screen |
| **422** | the *rider* cannot take it | show `errors.rider[0]` — "Finish your current delivery first", "Go online before accepting orders", "Your licence or insurance has expired" |
| **404** | not this rider's offer | close the card |

Accepting the **same** offer twice returns 200 both times. A dropped response
and a retry must not tell a rider they lost an order they are already carrying.

### Declining

```
POST /rider/offers/{offer_id}/reject   { "reason": "Too far" }
```

`reason` is optional. Always 200, even for an offer that has already ended.

**Declining does not count against the rider.** Letting it ring out does: three
in a row and the server takes them off duty and sends
`type: rider.auto_offline`. Handle that by flipping the duty toggle to off — it
has already happened server-side, so a toggle that still shows "online" is a
rider who thinks they are working and is not.

### Withdrawal

```json
{ "type": "OFFER_CANCELLED", "order_id": "41", "reason": "taken" }
```

Another data message. Stop the ringtone and dismiss the card for that
`order_id`, whatever offer it came from. Sent rather than left to expire,
because twenty-nine seconds of ringtone for an order that is already gone is
how a rider learns to ignore the sound.

`reason` is `taken`, `order_cancelled` or `assigned_by_ops`.

### Everything Android needs for this to work at all

This is the part that fails silently, and all of it is app-side:

- **Channel `nexmile_orders`** must exist, created by the app, with high
  importance. A mismatch means every notification is dropped with no error.
  Give the offer card its own channel too if you want a different sound.
- **`POST_NOTIFICATIONS`** (API 33+).
- **Location: fine *and* background** ("Allow all the time"), with a foreground
  service of type `location` while on duty.
- **`SYSTEM_ALERT_WINDOW`** for the floating card over other apps.
- **`USE_FULL_SCREEN_INTENT`** — on API 34+ this is not auto-granted for
  non-calling apps. Check `canUseFullScreenIntent()` and send the rider to
  settings if false.
- **Battery optimisation exemption and autostart** on Xiaomi, Oppo, Vivo,
  Realme and Samsung, or the service is killed and the rider silently stops
  receiving work.
- **Locked phone**: high-importance notification with a full-screen intent to an
  activity with `setShowWhenLocked(true)` and `setTurnScreenOn(true)` — the
  incoming-call technique.
- **Alarm audio stream** for the ringtone, so it plays at low ringer volume.
- **One card at a time.** The server will not send a second offer to a rider
  who has one live, but guard it anyway.

Put all of this behind **one guided setup screen** during onboarding. A rider
who gets through onboarding with two of these missing looks, from the outside,
exactly like a backend that does not send notifications.

### Mock locations are refused

```
POST /rider/location   { ..., "is_mock": true }   →  422
```

Send `is_mock` from `Location.isFromMockProvider`. A faked position would put
the rider into dispatch near a restaurant they are nowhere near and pay them a
first mile they never rode, so the server drops the ping and the response
carries `data.mock_location: true`. Show the rider that mock locations have to
be off.

## 9b. Order board


```
GET /rider/orders/available
```

Ready orders near the rider, **nearest restaurant first**. Poll every 10–15
seconds while the screen is open.

```json
{
  "data": [ {
    "id": 41, "order_number": "NX260808ABCD", "status": "ready_for_pickup",
    "pickup": { "name": "Ponnusamy Hotel", "address": "1 Main Road, Madurai",
                "phone": "9876500000", "latitude": 9.9195, "longitude": 78.1193,
                "distance_metres": 240 },
    "delivery_distance_metres": 620,
    "item_count": 3, "order_value": 430, "delivery_fee": 25, "collect_cash": 430
  } ],
  "meta": { "can_accept": true }
}
```

**`dropoff` is absent until the rider accepts.** A board every on-duty rider can
poll must not double as a list of where customers live. Do not build the map
screen expecting it before acceptance.

**`collect_cash` is what to collect at the door** — the full total for a COD
order, `0` for a prepaid one. Show it prominently; getting it wrong costs the
rider money out of their own pocket.

The board is **empty**, not an error, when the rider is offline, unverified,
already carrying an order, or has never sent a position. `meta.can_accept`
tells you which — use it to show the right empty state rather than "no orders
nearby" when the real reason is an expired licence.

A rider is **never shown an order they placed themselves**. The guard is on the
server; the app does not need to check.

### Accepting

```
POST /rider/orders/{id}/accept
```

First to accept wins. A **422 with `errors.order`** meaning *"Another rider took
this order"* is expected on a shared board — refresh the list and move on, do
not show it as a failure.

(The offer card uses a different endpoint and answers **409** for the same
situation. Both exist; neither replaces the other.)

Other 422s worth handling by message: *"Finish your current delivery first"*,
*"Go online before accepting orders"*, *"Your licence or insurance has
expired"*.

## 10. Pickup

```
POST /rider/orders/{id}/reached-restaurant      optional
POST /rider/orders/{id}/pickup   { "pickup_code": "4821" }
```

`reached-restaurant` tells the kitchen somebody is waiting, which is the single
most useful thing a merchant can be told. It moves the order to
`reached_restaurant`.

**It is optional.** `pickup` works from `rider_assigned` or
`reached_restaurant` — a rider holding a bag must never be blocked by a button
they did not press.

It is also not what waiting pay is measured from. That comes from the GPS
pings the app already sends, deliberately: a button pays whatever the rider
taps.

The merchant reads out four digits. A wrong code is a 422 on `pickup_code` —
let the rider retype it rather than bouncing them out of the screen.

There is no "I collected it" button without the code. It is the evidence that
the right rider took the right order, and it is what a disputed delivery is
settled with.

## 11. Delivery

```
POST /rider/orders/{id}/reached-customer        optional
POST /rider/orders/{id}/deliver
```

`reached-customer` notifies the customer that the rider is outside — the one
notification in the whole set that saves somebody time rather than telling them
what they could have guessed. A customer who reads it is at the door when the
rider gets there, instead of the rider standing in a stairwell on the phone.

Optional in the same way: `deliver` works from `picked_up` or
`reached_customer`.

Closes the order, sets the rider back to `available` and increments
`completed_deliveries`. Return them to the board.

## The job in hand

```
GET /rider/orders?active=1     the current delivery
GET /rider/orders              history, newest first
GET /rider/orders/{id}         one order
```

`?active=1` is what a working screen polls after a restart — never resume from
local state, because a rider changes devices and a shift outlives an app
process.

## 12. Earnings — not built

Per-shift and per-order payouts and settlement history are EP13. Riders are
paid manually until then.

---

## Rules that apply to every screen

**Tokens.** `access_token` 60 minutes, `refresh_token` 30 days. On 401, refresh
once and retry.

**Serialise refreshes behind a single lock.** Two concurrent refreshes with the
same token are treated as a stolen token and sign out every session — deliberate
reuse detection. A screen firing parallel requests on load will trip it.

This matters more in the rider app than the customer one: a rider signed out
mid-shift loses an active delivery.

**Errors.** `422` carries `{"errors": {"field": [...]}}` for form fields.
`403` carries a `message` written to be shown verbatim — "Your licence or
insurance has expired" cannot be guessed from the status code.

**Language.** `Accept-Language: ta`, `hi` or `en`; persist via
`PATCH /profile` → `preferred_locale`. Tamil matters most here — many riders
will not read English comfortably.

## What to build now

**Every screen, against real endpoints.** Nothing needs stubbing.

1. App shell, token storage with the refresh mutex
2. Screens 1–6 — sign in and the onboarding wizard
3. The permission setup screen — channel, notifications, background location,
   overlay, full-screen intent, battery, autostart. Before screen 7, because
   none of the rest works without it
4. Screen 7 — duty toggle, gated on `can_go_online`, with the foreground
   location service
5. Screen 9 — the offer card: data message, countdown to `expires_at`,
   ringtone, accept with 409 / 410 handling, `OFFER_CANCELLED`,
   `/offers/current` on resume
6. Screens 9b–11 — board, pickup with the code, the two arrival buttons,
   deliver

Only earnings is left, and it changes nothing you build now.

### Not built on the server, so do not build against it

- **No WebSocket.** The spec asks for one alongside FCM. There is no broker on
  this deployment. A high-priority data message plus `/offers/current` on
  resume covers it; raise it again if a dropped offer turns out to be costing
  real orders.
- **No batching.** One order per rider.
- **No delivery OTP.** The pickup code is at the restaurant end only.

## Testing the whole thing yourself

The loop is testable end to end without anyone else:

1. Sign up as a rider, complete the wizard, submit
2. Ask the backend to verify you in the admin portal
3. Go on duty and send one location ping
4. From the **customer** Postman collection, place an order at a nearby
   restaurant
5. In the **merchant portal**, accept it and mark it ready
6. Back in the rider app: it appears on the board — accept, take the pickup
   code from the merchant screen, deliver

A rider account can place customer orders with the same login, so you do not
need a second phone number to test — but you will not be offered your own
order, so use a separate customer account for step 4.
