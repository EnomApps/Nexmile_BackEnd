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
| 9 | Order board — accept a job | **Live** |
| 10 | Pickup — confirm the code | **Live** |
| 11 | Delivery — confirm handover | **Live** |
| 12 | Earnings and history | Not built (EP13) |

### One thing that is not built and changes how you design screen 9

**There are no push notifications yet.** A rider only sees a new order while
the app is open and polling. Build screen 9 as a board the rider watches, not
as an alert that arrives — and poll `/rider/orders/available` every 10–15
seconds while it is foregrounded.

That is workable for a pilot with a few riders. It stops working the moment a
phone goes in a pocket, so push is coming; the board does not change when it
does.

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

## 9. Order board

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

Other 422s worth handling by message: *"Finish your current delivery first"*,
*"Go online before accepting orders"*, *"Your licence or insurance has
expired"*.

## 10. Pickup

```
POST /rider/orders/{id}/pickup   { "pickup_code": "4821" }
```

The merchant reads out four digits. A wrong code is a 422 on `pickup_code` —
let the rider retype it rather than bouncing them out of the screen.

There is no "I collected it" button without the code. It is the evidence that
the right rider took the right order, and it is what a disputed delivery is
settled with.

## 11. Delivery

```
POST /rider/orders/{id}/deliver
```

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

**All eleven screens, against real endpoints.** Nothing needs stubbing.

1. App shell, token storage with the refresh mutex
2. Screens 1–6 — sign in and the onboarding wizard
3. Screen 7 — duty toggle, gated on `can_go_online`
4. Screens 8–11 — the working shift: ping, board, accept, pickup, deliver

Only earnings is left, and it changes nothing you build now.

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
