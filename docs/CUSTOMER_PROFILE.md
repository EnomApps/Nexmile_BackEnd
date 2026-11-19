# Customer profile page — preferences, feedback, veg mode

Private. Not served by the docs allowlist.

The app side asked for seven things for the new profile page. Three are built
here. The rest are recorded at the bottom with what each is waiting on, because
two of them are waiting on something that is not code.

## 1. Preferences

```
GET   /api/v1/profile/preferences
PATCH /api/v1/profile/preferences
```

Both answer the same shape, always with both fields:

```json
{ "data": { "veg_mode": false, "promotional_notifications": true } }
```

`PATCH` takes either field or neither. **An empty body is accepted**, not
refused — a `422` saying "you changed nothing" is an error message about a tap
that did no harm.

Both values are cast to real JSON booleans at the controller rather than left
to the model's casts, so no driver ever hands the app a `1` where it expects
`true`.

### On the user row, not in a preferences table

Two booleans read on nearly every app open. A join for two booleans is a join
for nothing. A third and a fourth would change that answer.

### `promotional_notifications` defaults to true, and that is deliberate

Defaulting it off would silence every offer for every customer who never finds
the setting, which is not consent — it is an unread switch. Opt-out is the
honest default; the setting exists so it can be turned off.

### The model carries its own defaults

`User::$attributes` repeats `veg_mode => false` and
`promotional_notifications => true`. The column defaults alone were not enough
and the test suite caught it: a `User` built in memory and used before being
read back has these as `null`, and `(bool) null` is `false` — which read as
"this customer turned offers off" for every account in its first moments.

### What the opt-out actually stops

`PushService::promotionalToUsers()` is the **only** door marketing may go
through. It filters the recipient list through `User::wantsPromotions()` before
dispatching, and the filter runs at dispatch rather than inside the job,
because a job retries and a consent check that runs on retry is one a failure
can skip.

Nothing sends marketing yet, which is exactly why the method exists now. The
first campaign will be written in a hurry, and choosing between two method
names is a smaller thing to get right under deadline than remembering to filter
a list of user ids.

**Order updates do not consult the flag and must not.** "Your rider is outside"
is not marketing, and a customer who muted offers has not asked to stop being
told where their food is. There is a test holding that distinction.

## 2. Rate the app

```
POST /api/v1/feedback   {"rating": 4, "message": "...", "platform": "android", "app_version": "1.4.0 (12)"}
```

`201` on success. `rating` is required, 1 to 5. Everything else is optional.

**`platform` and `app_version` are nullable on purpose.** Losing a customer's
sentence because their build forgot to name itself is a worse trade than
storing a row with an unknown platform. An unknown *value* is still refused —
`platform: "symbian"` is a bug worth reporting, a missing platform is not.

This is feedback about the **software**. Restaurant and rider reviews already
have their own tables and their own consequences; conflating them would put
"the map doesn't load on 2G" into a restaurant's rating.

### Closing an account keeps the sentence and drops the name

`user_id` is `nullOnDelete`. A customer leaving takes their name with them; the
row that told us the app was slow on 2G stays, and with nobody attached to it,
it is no longer personal data. That is also what makes it safe to keep
indefinitely.

### Reading it: `/admin/feedback`

An endpoint that collects feedback nobody reads is worse than no endpoint,
because it tells the customer they were heard. The page shows the average, the
count of one and two star ratings as its **own figure**, the last seven days
and the all-time total.

The separate low-rating count is the point: fifty fives and fifty ones average
the same as a hundred threes, and one of those is a problem. Filters are rating
band, platform and build.

**Read-only.** No reply, no resolve, no delete. This is a sentence about the
software, not a support ticket, and a half-built workflow invites somebody to
treat it as one.

## 3. `veg_only` on the home screen

`GET /api/v1/home?veg_only=1` now filters the restaurant sections, and
`meta.veg_only` echoes back what was applied.

It reuses the same `RestaurantFilters` object `GET /restaurants` builds, so veg
mode means one thing on both screens rather than two things that agree by
accident. One `$nearby` collection feeds every restaurant section, so the flag
applies to all of them at once.

Before this, veg mode had to abandon the home sections entirely and fall back
to the plain restaurant list — which made the one screen veg mode was supposed
to improve worse.

**Banners, cuisine tiles and curated collections are not filtered.** They are
merchandising, not restaurants or dishes, and they are the same for everybody —
which is also why they can be cached under one key while the restaurant
sections cannot.

## Still outstanding, and what each is waiting on

### Coupons (`GET /v1/coupons`) and coupons at checkout

Nothing exists: no table, no model, no redemption tracking. `PricingService`
has `$discount = 0.00;` hardcoded and `orders.discount_total` already flows
through to the invoice, so the slot is plumbed end to end and nothing feeds it.

Two product answers are needed before building: **who creates a coupon**, and
whether the discount is **platform-funded or billed back to the restaurant** —
the second changes the commission arithmetic, not just a column.

The shape proposed to the app side:

```
POST   /api/v1/restaurants/{merchant}/cart/coupon   {"code": "WELCOME50"}
DELETE /api/v1/restaurants/{merchant}/cart/coupon
```

Both return the full `CartResource` with `discount_total` filled in. The coupon
belongs to the **cart**, not to checkout: the customer must see the discount
before they pay, and checkout stays one call that reads what the cart already
holds.

**The usage limit must be enforced inside the checkout transaction**, with a
redemption row written in the same transaction. Checking it when the list is
fetched, or when the code is applied, lets two phones redeem the same
single-use coupon.

### `GET /v1/app/config`

Needs a settings table the ops team can edit, and the actual values from the
client: support phone, WhatsApp, email, safety line, hours, terms and privacy
URLs, and the FAQ list. The plumbing is a day; the content is a conversation.

### `POST /v1/support/safety-reports` — blocked on something that is not code

The spec says it *"must alert the operations team straight away (SMS, WhatsApp,
call or email), not only save it."* **No channel currently works:**

- SMS has no gateway. `app/Services/Sms/` holds only `LogSmsSender` and
  `NullSmsSender`, and `AppServiceProvider` binds `default => LogSmsSender`, so
  even `SMS_DRIVER=msg91` would silently keep writing to a log file.
- Mail depends on `MAIL_MAILER`, which is `log` in `.env.example`.

A customer pressing "report a safety emergency", getting a `201` and believing
somebody was told, when nothing reached a human, is **worse than not shipping
the button**. It converts a real emergency into a false sense of having
reported it.

The decision taken: store the report with the customer, their phone and their
active order, return `201`, log loudly, and **say plainly in the app that no
alert goes out yet**. "Call 112" stays the primary action on that screen until
there is a channel that works. Which makes the MSG91 account and DLT
registration the blocker here too — the same one phone-OTP sign-in is waiting
on.

## Tests

`tests/Feature/ProfilePreferencesTest.php`, 24 tests. The ones that matter:

- a new customer has offers on and veg mode off
- one field changes and both come back
- the answer is JSON booleans, not ones and zeroes
- a marketing push skips customers who opted out
- **an order update ignores the offer setting**
- feedback survives the account that sent it, without the name
- the admin page counts low ratings separately from the average
- a customer cannot read the admin feedback page

`tests/Feature/HomeScreenTest.php` gained three for veg mode.

`ApiDocsTest` caught `/v1/feedback` landing in no app document — the customer
document's prefix list in `AppServiceProvider::registerApiDocs()` needed it
adding. Worth knowing for the next endpoint: a new top-level prefix is not in
any document until it is named there.
