# Rider app — complete flow

Base URL `https://api.nexmile.in/api/v1` · always send `Accept: application/json`
Interactive docs: **https://api.nexmile.in/docs/api**
Postman: `docs/postman/Nexmile-Rider.postman_collection.json`

## Read this first

The split here is the opposite of the customer app. **Onboarding is complete
and is the larger half of the work.** Everything about actually working a shift
— receiving offers, pickup, delivery — is still being built.

| # | Screen | Status |
|---|---|---|
| 1 | Splash / session restore | **Live** |
| 2 | Sign in (OTP) | **Live** |
| 3 | Onboarding wizard — profile | **Live** |
| 4 | Onboarding wizard — document numbers | **Live** |
| 5 | Onboarding wizard — 6 uploads | **Live** |
| 6 | Submit + waiting for verification | **Live** |
| 7 | Duty toggle (go online / offline) | **Live** |
| 8 | Location ping while on duty | Not built (EP9) |
| 9 | Incoming order offer, accept/decline | Not built (EP8) |
| 10 | Pickup — confirm the code | Not built (EP8) |
| 11 | Delivery — confirm handover | Not built (EP10) |
| 12 | Earnings and history | Not built (EP13) |

Screens 1–7 can be built against real endpoints today, and that is genuinely
most of the app's screens. Stub 8–12 behind an interface.

---

## 1. Splash

Read the stored `refresh_token` → `POST /auth/refresh`. Success, then decide
where to land by calling `GET /rider/profile`:

- `can_accept_orders: true` → screen 7 (duty)
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

Gate the whole working UI on `can_accept_orders` from `GET /rider/profile`.

---

## Not built yet

Intent, not a contract. **These shapes will change** — code against your own
interface.

### 8. Location ping (EP9)

While `available` or `on_order`, push GPS every few seconds. The server keeps
it in Redis GEO, which is what dispatch searches to find riders within 1 km.

Battery matters more here than in the other app — this runs for a whole shift.
Expect a coarse interval when idle and a fine one when on an order.

### 9. Order offer (EP8)

Dispatch pushes an offer with pickup and drop points, distance and payout. The
rider accepts or declines against a countdown; declining passes it on.

A rider is **never offered an order they placed themselves** — that guard is on
the server, so the app does not need to check.

### 10. Pickup (EP8)

At the restaurant the merchant reads out a **pickup code**. The rider enters it
to confirm collection, which moves the order to `picked_up`.

The code is the proof that the right rider took the right order — it is what a
disputed delivery is settled with, so there is no "I collected it" button
without it.

### 11. Delivery (EP10)

Confirm handover to the customer. Order becomes `delivered`.

### 12. Earnings (EP13)

Per-shift and per-order payouts, settlement history.

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

1. App shell, token storage with the refresh mutex
2. Screens 1–7 against live endpoints — that is most of the app
3. `DispatchRepository` / `DeliveryRepository` interfaces with fakes, plus the
   offer, pickup and delivery UI on top

Onboarding is fully testable today: sign up, fill the wizard, submit, and ask
the backend to approve you in the admin portal.
