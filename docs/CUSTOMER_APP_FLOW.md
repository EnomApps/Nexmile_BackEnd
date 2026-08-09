# Customer app — complete flow

Base URL `https://api.nexmile.in/api/v1` · always send `Accept: application/json`
Interactive docs: **https://api.nexmile.in/docs/api**
Postman: `docs/postman/Nexmile-Customer.postman_collection.json`

## Read this first

**The whole ordering journey is live.** A customer can sign in, find a
restaurant, build a basket, place an order, watch it being cooked and see it in
their history — all against real endpoints today.

Two things are not built: **online payment** (cash on delivery only for now)
and **ratings**. Neither blocks the app.

| # | Screen | Status |
|---|---|---|
| 1 | Splash / session restore | **Live** |
| 2 | Sign in (OTP) | **Live** |
| 3 | Location + address book | **Live** |
| 4 | Home — nearby restaurants | **Live** |
| 5 | Restaurant menu | **Live** |
| 6 | Cart | **Live** |
| 7 | Checkout | **Live** |
| 8 | Payment | **COD only** — no gateway yet |
| 9 | Live order tracking | **Live** |
| 10 | Order history | **Live** · rating not built |

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

## 4. Home — nearby restaurants

```
GET /restaurants?address_id=3
GET /restaurants?latitude=9.9195&longitude=78.1193
```

Send **either** an `address_id` from the customer's address book **or** raw
`latitude`/`longitude` from GPS. Optional: `service_category`, `search`,
`per_page`.

The app does no distance maths. It sends a point and receives a paginated,
ranked list plus `meta.radius_metres` — useful for "No restaurants within
1 km of this address" rather than a bare empty state.

Ranking is **open first, then nearest**. A closer kitchen that cannot cook is
worth less than one two hundred metres further that can.

Closed restaurants are **listed, not hidden**, ranked below open ones. A
customer in a small town at 3pm would otherwise see an empty screen and
conclude Nexmile does not work there.

Three fields say whether they can order, and they are deliberately separate:

| Field | Meaning |
|---|---|
| `is_open` | can order right now — the one to gate the UI on |
| `is_accepting_orders` | the merchant's own switch |
| `within_operating_hours` | the clock |

"Closed until 6pm" and "temporarily not taking orders" are different messages,
and only one of them is worth waiting for.

Also returns `avg_prep_time_minutes`, `min_order_value`, `packaging_fee`,
`supports_pickup`, `distance_metres`, `logo_url`, `banner_url`.

## 5. Restaurant menu

```
GET /restaurants/{id}          — storefront + operating hours
GET /restaurants/{id}/menu     — storefront + full menu
```

**`data.menu` is the whole menu.** Categories in the merchant's own order, each
with its `items`. Loop it and you have every dish.

A restaurant with no categories gets one group named "Uncategorised" with a
**null `id`** — treat it like any other, or just render its items without a
header. There is no second array to remember.

Each item carries name, description, `image_url`, `price`, `compare_at_price`
when discounted, `is_veg`, `contains_egg`, `prep_time_minutes`, `gst_rate` and
`option_groups` (spice level, add-ons) with `price_delta` per option.

**`is_available: false` must be visibly out of stock, not hidden.** Merchants
toggle this mid-service and a dish vanishing confuses people who came for it.

Inactive *categories* are already filtered out server-side — that is a merchant
retiring a section, not a temporary shortage.

Item photos are **signed URLs valid for 24 hours**. Cache the image, not the
URL.

---

## 6. Cart

**One cart per restaurant**, and they persist. Glancing at another shop does
not empty the basket you already started — `GET /carts` returns every open one,
which is what a "you have an unfinished order" banner reads.

| Method | Path | Purpose |
|---|---|---|
| GET | `/carts` | all open carts |
| GET | `/restaurants/{id}/cart` | one cart, priced |
| POST | `/restaurants/{id}/cart/items` | add |
| PATCH | `/restaurants/{id}/cart/items/{item}` | change quantity |
| DELETE | `/restaurants/{id}/cart/items/{item}` | remove a line |
| DELETE | `/restaurants/{id}/cart` | empty it |

```json
POST /restaurants/7/cart/items
{ "menu_item_id": 42, "quantity": 2, "option_ids": [3, 9], "notes": "less oil" }
```

Every cart response comes back **fully priced** — you never compute a total:

```json
{
  "items": [ { "cart_item_id": 5, "name": "Chicken Biryani", "quantity": 2,
               "unit_price": 200, "options_total": 20, "line_total": 440,
               "options": [ { "group_name": "Spice level", "name": "Extra spicy",
                              "price_delta": 20 } ] } ],
  "totals": { "items_total": 440, "packaging_fee": 10, "delivery_fee": 0,
              "discount_total": 0, "tax_total": 22, "grand_total": 472 },
  "free_delivery_applied": true,
  "minimum_order_value": 0,
  "meets_minimum": true,
  "unavailable_items": [],
  "can_checkout": true
}
```

**`quantity: 0` removes the line** — the minus button needs no special case at
the boundary.

**Identical lines merge.** Same dish, same options, same note becomes quantity
2. Different options stay separate lines.

**Required choices are enforced when adding**, so a 422 on `option_ids` means
open the customisation sheet. Do not wait for checkout to find out.

**`unavailable_items` names dishes that sold out** while the cart sat there.
They stay in the cart and `can_checkout` goes false — show them struck through
with a Remove button. Do not silently drop them.

Pass `?fulfilment_type=pickup` when reading a cart to preview pickup pricing;
the delivery fee disappears, which changes what the customer is deciding on.

**Gate the checkout button on `can_checkout`.** It already accounts for the
minimum, sold-out items, an empty cart and the restaurant being closed.

## 7. Checkout

```json
POST /restaurants/7/cart/checkout
{ "fulfilment_type": "delivery", "payment_method": "cod",
  "address_id": 3, "note": "Ring the bell twice" }
```

Returns the created order. **The cart is emptied** — tapping back cannot place
it twice.

`address_id` is required for `delivery` and ignored for `pickup`.

Everything is re-checked at this point, so expect 422s here even when the cart
looked fine a moment ago. Show `errors.<field>[0]` verbatim:

| Field | Means |
|---|---|
| `cart` | empty, below the minimum, or an item sold out |
| `merchant` | closed, or stopped taking orders |
| `address_id` | outside the 1 km radius, or has no pin |
| `payment_method` | not available yet |

## 8. Payment

**Cash on delivery only.** `payment_method` must be `"cod"`; anything else is
refused. There is no gateway yet, and offering a method that cannot complete
would lose the basket at the final step.

A COD order is created at **`placed`**, so it reaches the restaurant
immediately — there is no payment screen to build yet. When a gateway lands,
those orders will start at `pending_payment` and you will need one.

## 9. Live tracking

```
GET /orders/{id}/track
```

Deliberately small and cheap — poll it every few seconds while an order is in
flight. It reads Redis and falls back to MySQL.

```
placed → accepted → preparing → ready_for_pickup
       → rider_assigned → picked_up → delivered
```

Plus `rejected` and `cancelled`. Both carry `cancellation_reason` meant to be
shown **verbatim** — the merchant wrote it for the customer.

Returns `estimated_prep_minutes` once accepted, the lifecycle timestamps, the
`pickup_code`, and rider name and vehicle number once one is assigned.

**Rider assignment and live rider location are not built yet** (EP8/EP9), so
`rider` stays null and an order currently stops at `ready_for_pickup`. Nothing
in your tracking screen needs to change when that lands.

### Cancelling

```
POST /orders/{id}/cancel   { "reason": "Ordered by mistake" }
```

**Only while `placed`.** Once the restaurant accepts, the kitchen may have
started and the call returns 422 with "The restaurant has already started your
order." Hide the cancel button as soon as the status leaves `placed`.

## Food Rescue deals

```
GET /restaurants/deals?address_id=3
GET /restaurants/deals?latitude=…&longitude=…
```

Surplus food nearby at a discount, **soonest to expire first**. Same radius and
verification rules as the restaurant list.

Only deals that are orderable *right now* appear — inside their window with
portions left — so the screen never advertises something checkout would refuse.

Every menu item also carries `is_rescue_deal`, and when it is one:

```json
"rescue": {
  "portions_left": 3,
  "available_from": "2026-08-09T20:00:00+05:30",
  "available_until": "2026-08-09T22:30:00+05:30",
  "saving": 110
}
```

**Show the countdown and the portions left.** A rescue deal is a race, and
hiding that makes it look like an ordinary discount.

`is_rescue_deal` can go false while a customer is looking at it — the window
closes or someone takes the last portion. Adding it to a cart then returns 422
on `menu_item_id`, and checkout returns 422 on `cart` if it sells out between
adding and paying. Both messages are written to be shown as they are.

## 11. Tax invoice

```
GET /orders/{id}/invoice
```

Returns a **printable HTML page**, not JSON — open it in a webview or the
system browser. It is meant to be saved as a PDF, and every figure comes from
what the order was actually charged.

## 10. Order history

| Method | Path | Purpose |
|---|---|---|
| GET | `/orders` | newest first, paginated |
| GET | `/orders?active=1` | in-flight only — for a home-screen banner |
| GET | `/orders/{id}` | full order, items, timeline |

Every order keeps its **own** price breakdown and item names, snapshotted when
it was placed. A restaurant renaming a dish or changing its price does not
alter what a past order says.

**Ratings are not built** (EP12).

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

**Money.** Amounts are rupees sent as JSON **numbers**, and a whole-rupee value
loses its zero fraction: ₹430.00 arrives as `430`, ₹92.50 as `92.5`.

**Read money as `num`, never `double`.** `data['grand_total'] as double` throws
on a ₹430 order and succeeds on a ₹92.50 one — the worst possible way round,
because it passes every test until a customer orders a round number. Use
`(data['grand_total'] as num).toDouble()`.

**Never recompute a total in the app.** The server is the only thing that
prices a basket; display what it sent. Any figure the app calculates will
eventually disagree with the bill, and the bill wins.

## What to build now

**All ten screens, against real endpoints.** Nothing needs stubbing any more.

Suggested order, so each step is testable end to end:

1. App shell, routing, token storage with the refresh mutex
2. Screens 1–3 — sign in and the address book
3. Screens 4–5 — discovery and menu
4. Screens 6–8 — cart, checkout, COD confirmation
5. Screens 9–10 — tracking and history

The only things you will come back for are an online payment screen and
ratings, and neither changes anything you build now.

You can exercise the whole flow yourself with the Postman collection: sign in,
save an address near a test restaurant, add to cart, check out. The order
appears in the merchant portal at `nexmile.in/merchants/orders`, where it can
be accepted and marked ready — and your tracking screen will follow it.
