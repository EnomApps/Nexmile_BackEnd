# Admin — rider management

Private. Not served by the docs allowlist.

The admin answer to "what is going on with this rider". One roster page and one
page per rider, at `/admin/riders`.

## Why it exists

Everything on these pages already existed, scattered. Identity and documents
were on the KYC review screen, complaints on the conduct screen, earnings only
inside the rider's own app, duty status only in a database column, and position
only in Redis where no human could read it. Answering a rider's question meant
four screens and a database client, so in practice nobody answered it until a
rider or a customer forced it.

Nothing here is a new source of truth. Every figure is read from whatever
already owns it. A performance number computed on this page that disagreed with
the rider's own app would be worse than showing none, because an admin would
quote it to a rider and then have to take it back.

## The pages

| URL | What it is |
| --- | --- |
| `GET /admin/riders` | The roster. Search, duty and verification filters, sorting. |
| `GET /admin/riders/{rider}` | One rider, whole — including the KYC review that used to live at this URL. |
| `GET /admin/riders/{rider}/position` | JSON, for the live panel to poll. |

`admin/riders/{id}` **took over the verification URL for riders**, deliberately.
It is registered before the `{type}/{id}` catch-all in `routes/web.php`, and it
carries the document review and the verify/reject/suspend actions with it, so
nothing was lost. Merchants still use the catch-all, and the POST actions under
it still serve both.

### The roster

One search box covering name, phone, email, number plate and rider id, because
an admin has whichever detail the person on the phone gave them. Asking which
kind of thing it is first is how a search box goes unused.

A numeric term matches the id exactly **and** digit fragments of plates, phones
and emails. That is wanted: `38` should find every TN38 plate, and a
half-remembered plate is the commonest thing anybody searches on.

The column worth reading is **Reporting**. "On duty" is what the rider set;
"reporting" is whether a position arrived within `dispatch.presence_ttl_seconds`
(60s). A rider who is **on duty and not reporting is invisible to dispatch and
usually does not know it** — that pairing is the one to act on, and it is called
out in amber for exactly that reason.

Sorting by acceptance puts a rider who has never been offered anything *below*
0%, not at it. They have refused nothing, and a worst-first list that opens with
the newest rider on the roster sends an admin after the wrong person.

### Live position

Redis first, falling back to `riders.last_latitude/longitude` — the last fix
MySQL saw. The panel says **which of the two answered**, because an admin
looking at a dot needs to know whether it is live or the last thing recorded
before Redis restarted; those mean very different things about where the rider
is standing now.

"Keep refreshing" polls `/position` at half the staleness window, so a position
can never be shown as fresh after it has expired. Polling, not a socket: one
admin watching one rider is a request every twenty seconds, and a websocket for
that is infrastructure to operate, monitor and secure for a benefit nobody would
notice.

### Earnings

**Earned and paid are two different numbers**, and the page shows both plus the
gap:

- **Earned, all deliveries** — `SUM(orders.rider_payout)` over delivered orders.
- **Paid out** — `SUM(rider_payouts.net)` where the statement is `paid`.
- **Owed, not yet sent** — the `payable()` scope: `pending` and `failed`.
- **Since last statement** — delivered after the latest `period_end`.

That last one is the number a rider asking "where is my money" is really asking
about. It is deliberately not called pending: pending implies a transfer exists
somewhere, and none does.

The page also says when there are **no bank details on file**, because that is
the commonest reason a payout run skips somebody and it is invisible everywhere
else in the portal.

### Sign-ins and activity

Read from `refresh_tokens`, which has recorded all of this since authentication
was built — nothing was reading it. A row is created at sign-in, stamped on each
exchange, and marked `revoked_at` at sign-out rather than deleted, so the
history survives.

**Refreshing rotates the token**, so most rows are not sign-ins. A row counts as
a sign-in only when no other row was `replaced_by_id` into it. Without that
distinction an app renewing quietly every hour reads as a rider signing in
twenty times a day, and the one question this section answers — when did they
actually start work — becomes unanswerable.

## Location history

`rider_location_pings`. This is the one genuinely new thing on the page.

Live positions live in Redis, latest only, because dispatch asks "who is near
this restaurant now" and never "where was this rider at 14:30". That answered
every question the platform had until somebody disputed a delivery — and then
there was nothing to look at, because the only stored fix had overwritten all
the others.

### It is not the ping stream

The rider app pings every ten seconds while carrying. A parked rider would write
360 identical rows an hour, which is the write rate `config/tracking.php` warns
about and the reason no trail was stored before.

**Only fixes that moved the rider at least `tracking.history.min_metres` (20m)
are kept.** A stationary rider costs one row, and the trail still draws as a
continuous line, because the points that matter are the ones where the position
changed. The first fix of a shift is always kept — there is nothing yet to
compare it against, and a trail that starts at the second fix starts wherever
the rider happened to be when they moved rather than where they began.

The cost: a trail cannot say *how long* a rider stood still, only that they did
and between which two fixes. That is enough for every question the page exists
to answer.

The write happens in `LocationController::recordTrail()`, not in
`RiderLocationService` — the service is about Redis, and `nexmile:redis-health`
exercises it with a rider id that does not exist, which would leave rows behind
or fail on the foreign key.

A kept ping records the **order being carried**, resolved at write time rather
than inferred from timestamps later. A fix taken seconds either side of a
handover is otherwise guesswork, and those are exactly the ones somebody comes
back to ask about.

A **mock-GPS ping leaves no trail**. It is refused before any of this, so a
faked position never becomes part of a record an admin later reads as evidence.

### Retention is not optional

A trail is personal data about somebody's working day. Keeping it because
deleting it was never scheduled is not a decision anybody made — it is the
absence of one.

```
nexmile:prune-location-history [--days=N] [--dry-run]
```

Scheduled daily at 03:50 in `routes/console.php`. It deletes in chunks, because
a single DELETE across a month of pings holds a lock long enough to be felt by
riders pinging into the same table. It **refuses a retention below one day**: a
typo in a cron entry must not be the thing that wipes every trail on the
platform.

| Setting | Default | Meaning |
| --- | --- | --- |
| `TRACKING_HISTORY_ENABLED` | `true` | Off stores nothing new. Existing rows stay. |
| `TRACKING_HISTORY_RETENTION_DAYS` | `30` | The stated period. Thirty days covers the window a delivery can still be disputed in. |
| `TRACKING_HISTORY_MIN_METRES` | `20` | Below this, the ping is dropped. |

**Raising the retention needs a reason that survives being written down.** The
operational question — "did the rider actually reach this address on a disputed
order" — is answered inside thirty days. Indefinite retention is an exposure
with no benefit to match it.

### Sizing

At 20m, a rider working a ten-hour shift around a 1 km zone writes a few hundred
rows a day rather than 1,440. Twenty riders over a thirty-day window is a table
in the low hundreds of thousands of rows — the smallest indexed table on the
box. The numbers only break if the distance gate regresses, and nothing visible
breaks when it does; the table just quietly becomes the largest in the database.
That is why `AdminRiderManagementTest` pins it from both sides.

## The map needs a second Maps key

`GOOGLE_MAPS_BROWSER_KEY`, and it **cannot be the server key**.

The server key is restricted to the backend's IP address, which a browser
request can never satisfy. A key that works in a browser is necessarily visible
in the page, so it is restricted by HTTP referrer instead and given only the
**Maps JavaScript API**. Putting the server key in a page would publish a key
carrying Directions and Distance Matrix to anybody who opened the source.

Unset, the page says so and falls back to coordinates with a link out to Google
Maps — which answers the same question with one more click. It does not draw an
empty grey box that looks like a broken map. `AdminRiderManagementTest` asserts
the server key is never rendered into the page.

See `docs/MAPS.md` for the full key inventory.

## What is deliberately not shown

**Aadhaar and the full bank account number.** Both are on the `Rider` model's
`$hidden` list, neither is needed to answer any question this page exists for,
and a screen an admin leaves open on a desk should not display them. They are
readable from the documents when actually needed, which leaves a trail in the
document access logs.

**A rider profile photo.** There is no such column anywhere yet. It needs image
storage separate from the private document bucket — which holds Aadhaar,
licences and bank statements — plus a moderation path, so it is a decision
rather than a field.

## Tests

`tests/Feature/AdminRiderManagementTest.php`, 22 tests. The ones that matter
most are not about the page:

- a ping that moved is kept; ten pings from a parked bike write nothing
- the first fix of a shift is always kept
- a kept ping carries its order
- a mock-GPS ping leaves no trail
- the pruner deletes past retention, refuses zero days, and dry-runs clean
- the server Maps key is never rendered into the page
