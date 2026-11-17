# Admin — restaurant management

Private. Not served by the docs allowlist.

The counterpart to `docs/ADMIN_RIDERS.md`, at `/admin/merchants`, and the
simpler of the two: a restaurant has one address and it never moves, so there
is no trail here, no retention period and no second Maps key.

## Why it exists

Before this, "what is going on with Hotel Vasanth" meant the verification queue
for their paperwork, the orders list filtered by hand for their orders, and
nothing at all for the two figures that actually say whether they are a good
restaurant to have.

As with the rider page, nothing here is a new source of truth. Money comes
from `EarningsService` — the same service behind the restaurant's own earnings
page — because two screens with their own arithmetic eventually disagree about
the same week, and the restaurant is the one who would be right.

## The pages

| URL | What it is |
| --- | --- |
| `GET /admin/merchants` | The roster. Search, state and verification filters, sorting. |
| `GET /admin/merchants/{merchant}` | One restaurant, whole — documents, food licence and commission included. |

`admin/merchants/{id}` **took over the verification URL**, registered ahead of
the old `{type}/{id}` catch-all. It carries the document review, the FSSAI
form, the commission terms and the verify/reject/suspend actions, so nothing
was lost by taking it.

**The generic review page is gone.** `AdminController::show()` and
`resources/views/admin/show.blade.php` were deleted rather than left shadowed —
both types have a section of their own now, and a dead Blade file that still
looks live is a trap for whoever edits it next and wonders why nothing changes.
The POST actions still live on `AdminController` and still take `$type`, since
they serve both sections. An unknown type like `/admin/customers/1` still 404s,
now because nothing routes it at all rather than because a constraint refused
it.

## Four reasons a kitchen is shut, and they look identical

The thing this page is really for. From a customer's phone they are the same
event — the restaurant simply isn't there:

1. The owner has **paused** (`is_accepting_orders`)
2. The clock says **closed** (`isOpenNow()`, from `merchant_operating_hours`)
3. The account is **not verified**
4. The **food licence has expired** (`fssaiBlocker()`)

And a fifth that is not a condition at all and defeats every one of them: the
restaurant is verified, open, accepting, licensed and has **no menu items, or
every item marked out of stock**. Healthy on every other screen in the portal
and completely unorderable. The page calls that out explicitly.

The roster can filter on paused, open, closed and licence problems. Open and
closed are filtered **after** the query rather than inside it — opening hours
are rows and `isOpenNow()` reads them in PHP — which is fine at this roster
size and worth knowing before the roster is much larger.

## The two figures that exist nowhere else

### Rejection rate

A restaurant that accepts a customer and then rejects the order costs a
customer, not an order. Nothing counted it per restaurant before, so a bad one
stayed invisible until somebody noticed a pattern by hand.

**Measured against what the kitchen was actually asked to make.** Orders
abandoned at payment (`pending_payment`) never reached them, and counting those
in the denominator would quietly improve the rate of every restaurant on the
platform — most of all the ones customers give up on, which is exactly
backwards. There is a test for this.

### Prep time, promised against actual

Promised is `estimated_prep_minutes` as quoted on the order; actual is
`accepted_at → ready_at`. Both averaged over the last 50 delivered orders.

Every customer ETA is built on the estimate, so when it is wrong it is wrong in
the same direction all day for every order that restaurant takes. A gap of five
minutes or more is flagged, alongside their profile's `avg_prep_time_minutes`
setting so an admin can see what they claimed.

This is also the figure `docs/DISPATCH.md` names as the thing missing before
dispatch can start hunting a rider at (prep − travel) instead of at acceptance.
Once there is real data here, that change becomes possible.

Averages are computed in PHP over recent orders rather than in SQL, same as the
rider page: `TIMESTAMPDIFF` is MySQL's and the suite runs on SQLite, so
driver-specific date arithmetic would leave this exercised only by hand. Null
rather than zero when nothing qualifies — zero reads as "instant" rather than
"never measured".

## Sign-ins

From `refresh_tokens`, same as the rider page, with the same distinction
between a sign-in and a quiet renewal. For a restaurant this answers a question
support asks often: is anybody actually logged into the merchant portal, or has
the tablet in the kitchen been signed out since Tuesday.

## What is not shown

The **full bank account number** — on the model's `$hidden` list, needed by
nothing on this page, readable from the bank proof when it is genuinely needed.

## Tests

`tests/Feature/AdminMerchantManagementTest.php`, 15 tests. The ones that matter:

- the rejection rate ignores checkouts the kitchen never saw
- the prep gap compares promised against actual
- a restaurant with no deliveries shows dashes, not zeroes
- everything green and still nothing orderable is called out
- earnings come from the service the merchant portal uses
- paused is distinguished from closed
- the full bank account number is not rendered
- what the old verification page could do, this page can still do

`AdminPortalTest`, `AdminComplianceTest` and `AdminCommissionAndDashboardTest`
all reach these URLs and pass unchanged — which is the real check that taking
the URL cost nothing.
