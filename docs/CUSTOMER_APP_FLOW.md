# Customer app — complete flow

Base URL `https://api.nexmile.in/api/v1` · always send `Accept: application/json`
Interactive docs: **https://api.nexmile.in/docs/api**
Postman: `docs/postman/Nexmile-Customer.postman_collection.json`

## Read this first

**About a third of this flow has endpoints today.** Auth, profile and the
address book are live and stable. Everything from browsing restaurants onward
is still being built.

That is deliberate rather than an oversight — it is the order the backend is
being written in. Build the app shell, auth and address book against real
endpoints now, and stub the rest behind an interface so swapping in the real
call is a one-line change.

| # | Screen | Status |
|---|---|---|
| 1 | Splash / session restore | **Live** |
| 2 | Sign in (OTP) | **Live** |
| 3 | Location + address book | **Live** |
| 4 | Home — nearby restaurants | Not built (EP4) |
| 5 | Restaurant menu | Not built (EP3) |
| 6 | Cart | Not built (EP5) |
| 7 | Checkout | Not built (EP5) |
| 8 | Payment | Not built (EP6) |
| 9 | Live order tracking | Not built (EP9) |
| 10 | Order history + rating | Not built (EP12) |

---

## 1. Splash — restore the session

On launch, read the stored `refresh_token`.

- **No token** → screen 2.
- **Token present** → `POST /auth/refresh`. On success store the new pair and
  go to screen 4. On failure clear storage and go to screen 2.

Do not call `/auth/me` to decide this. The refresh call already tells you
whether the session is alive, and one round trip on a cold start is enough.

## 2. Sign in — OTP

There is **no separate registration endpoint**. The account is created on first
successful verification, so sign-up and sign-in are the same two calls.

```
POST /auth/otp/request
{ "email": "user@example.com", "intended_role": "customer" }
```

Today OTP goes by **email**. When SMS clears DLT registration you send
`"phone": "9876543210"` instead — same endpoint, same response, nothing else
changes. Build the field so it can switch.

```
POST /auth/otp/verify
{ "email": "user@example.com", "code": "123456", "device_name": "Pixel 8" }
```

Returns:

```json
{
  "data": {
    "user": { "id": 1, "role": "customer", "status": "active", "name": "Nexmile user" },
    "access_token": "…",
    "refresh_token": "…",
    "expires_in": 3600
  }
}
```

A customer is **`active` immediately** — no waiting, no verification queue.
Go straight to screen 3 (first run) or screen 4.

**Let any role through.** A rider or merchant signing in to order dinner is a
normal customer here. Only `status: "suspended"` blocks the app — a rider's
`status: "pending"` means "cannot work yet", not "cannot order". See
`docs/ROLES.md`.

Name is `"Nexmile user"` until they set one. Prompt for a real name on first
run via `PATCH /profile`.

## 3. Location and address book

**This is the screen that makes the product work.** Nexmile delivers inside
1 km, so an address without coordinates cannot be matched to a merchant at all
— `latitude` and `longitude` are required, not optional.

Ask for location permission here, with a sentence explaining why. Prefill from
GPS and let the user drag a pin to correct it.

| Method | Path | Purpose |
|---|---|---|
| GET | `/addresses` | list |
| POST | `/addresses` | create |
| GET | `/addresses/{id}` | one |
| PATCH | `/addresses/{id}` | update |
| DELETE | `/addresses/{id}` | remove |
| POST | `/addresses/{id}/default` | set default |

```json
{
  "label": "home",
  "line1": "12 Anna Salai",
  "landmark": "Opposite the bus stand",
  "city": "Madurai",
  "pincode": "625001",
  "latitude": 9.9252,
  "longitude": 78.1198,
  "contact_name": "Meena",
  "contact_phone": "9876543210"
}
```

`label` is one of `home`, `work`, `other`. Validation is India-specific:
pincode `[1-9]\d{5}`, phone `[6-9]\d{9}`.

Keep the selected address in app state — every screen after this one is scoped
to it. Changing address changes which restaurants exist.

---

## Not built yet

Everything below describes intent, not a contract. **The shapes will change.**
Do not code against them; code against your own interface and swap later.

### 4. Home — nearby restaurants (EP4)

A list of merchants within 1 km of the selected address, open now, ranked by
distance. Needs: name, photo, veg/non-veg, rating, prep time, delivery fee,
minimum order, open/closed.

Radius matching runs on Redis GEO server-side. The app sends the address id or
coordinates and receives the list — it does no distance maths itself.

### 5. Restaurant menu (EP3)

Categories with items underneath, in the merchant's own order. Each item has
name, description, photo, price, `compare_at_price` when discounted, veg flag,
egg flag, prep time, and option groups (spice level, add-ons) with price deltas.

**`is_available: false` must be visibly out of stock, not hidden.** Merchants
toggle this mid-service and a dish vanishing confuses people who came for it.

Item photos come back as **signed URLs valid for 24 hours**. Cache the image,
not the URL.

### 6–7. Cart and checkout (EP5)

One cart per merchant. A cart is priced server-side at checkout — never trust
totals computed in the app. The backend returns items total, packaging fee,
delivery fee, discount, tax and grand total as separate lines, because an
invoice has to be rebuildable exactly.

Fulfilment is `delivery` or `pickup`.

### 8. Payment (EP6)

Order is created as `pending_payment` and only becomes visible to the merchant
once paid. Provider not yet chosen.

### 9. Live tracking (EP9)

Poll order state. The lifecycle the customer sees:

```
placed → accepted → preparing → ready_for_pickup
       → rider_assigned → picked_up → delivered
```

Plus `rejected` and `cancelled`, both of which carry a reason string meant to
be shown verbatim — the merchant wrote it for the customer.

Rider location is served from Redis, so polling every few seconds is fine.

### 10. History and rating (EP12)

Past orders with their full price breakdown, reorder, and a rating per order.

---

## Rules that apply to every screen

**Tokens.** `access_token` lasts 60 minutes; `refresh_token` 30 days. On a 401,
refresh once and retry the original request.

**Serialise refreshes behind a single lock.** Two concurrent refreshes with the
same token are treated as a stolen token and every session is signed out — this
is deliberate reuse detection, not a bug. A screen that fires five parallel
requests on load will trip it without a mutex.

**Errors.** Validation failures are `422` with
`{"message": "...", "errors": {"field": ["..."]}}`. Everything else carries a
`message` written to be shown to the user. Show it rather than inventing your
own text — messages like "Your FSSAI licence is missing or expired" cannot be
guessed from a status code.

**Language.** Send `Accept-Language: ta`, `hi` or `en`. Server messages come
back translated. Persist the choice with `PATCH /profile` → `preferred_locale`.

**Money.** All amounts are decimals in rupees, sent as JSON numbers. Never
recompute a total in the app; display what the server sent.

## What to build now

1. App shell, routing, token storage with the refresh mutex
2. Screens 1–3 against the live endpoints
3. A `RestaurantRepository` / `MenuRepository` / `CartRepository` interface with
   fake in-memory implementations, plus the UI on top

By the time screens 4–7 are wired the endpoints will exist, and only the
repository implementations change.
