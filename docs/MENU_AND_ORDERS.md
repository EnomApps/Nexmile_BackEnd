# Menu and orders (EP3, EP5, EP8)

Merchants manage their menu and work their order queue in the portal at
`nexmile.in/merchants`, or over the JSON API with the same rules — both go
through the same FormRequests and the same services, so they cannot drift.

## Menu

### Structure

A merchant owns categories and items. An item may sit in a category or be
uncategorised; deleting a category keeps its items and clears their grouping
rather than deleting a merchant's dishes because they reorganised the menu.

Items carry the fields a customer sees (name, description, photo, price, veg
marker) and the fields the platform needs (GST rate, prep time). GST is
restricted to the statutory rates in `config/menu.php` — a merchant typing
7% would produce an invoice that cannot be filed.

### Photos

Dish photos go to the same private S3 bucket as KYC documents, under a
`menu/{merchant_id}/` prefix, and are read through **signed URLs valid for 24
hours**.

That bucket blocks all public access, which is right for an Aadhaar and merely
inconvenient for a photo of a biryani. The alternative — a second public bucket
plus CloudFront — is the better end state, but it is AWS console work, and the
API returns a URL string either way. Moving later changes nothing for the apps.

The TTL is long deliberately: a customer scrolling a menu for ten minutes must
not watch the images expire, and the link has to outlive the client's own image
cache or every scroll re-downloads.

Uploads are JPG, PNG or WebP up to 4 MB. As with KYC, **the server limits must
stay above the app limit** or PHP discards the file before validation runs —
see [KYC.md](KYC.md#server-limits-must-stay-above-the-app-limit).

### Endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/v1/merchant/categories` | list, with item counts |
| POST | `/v1/merchant/categories` | create |
| PATCH | `/v1/merchant/categories/{id}` | rename, reorder, activate |
| DELETE | `/v1/merchant/categories/{id}` | delete; items become uncategorised |
| GET | `/v1/merchant/menu-items` | list; filter by `category_id`, `is_available`, `search` |
| POST | `/v1/merchant/menu-items` | create (multipart, `image` optional) |
| GET | `/v1/merchant/menu-items/{id}` | one item with option groups |
| POST | `/v1/merchant/menu-items/{id}` | update (multipart) |
| POST | `/v1/merchant/menu-items/{id}/availability` | out of stock / back on |
| DELETE | `/v1/merchant/menu-items/{id}/image` | remove the photo |
| DELETE | `/v1/merchant/menu-items/{id}` | soft delete |
| POST | `/v1/merchant/menu-items/reorder` | `{"ids": [...]}` in display order |

**Update is POST, not PATCH.** PHP does not parse multipart bodies on PATCH, so
a PATCH upload arrives with an empty `$_FILES`. The validation rules key
"create vs update" off the route parameter rather than the HTTP verb for the
same reason.

**Availability is its own endpoint.** It is the one action a merchant performs
mid-service, on a phone, when the kitchen runs out — it must not require
sending the whole item back.

## Orders

### What a merchant may do

```
placed ──accept──> accepted ──preparing──> preparing
   │                   │                       │
   │                   └────────ready──────────┤
   │                                           ▼
   └──reject──> rejected              ready_for_pickup
```

Everything after `ready_for_pickup` — rider assignment, pickup, delivery —
belongs to dispatch and the rider app (EP8, EP10). **A merchant cannot mark an
order delivered**; they never handle it after pickup, so there is no route for
it.

### How the merchant knows a rider collected the order

The **pickup code** on the order. It is generated when the order is placed and
appears on the merchant's screen the moment the food is ready — not before,
because showing it while the kitchen is still cooking invites a rider to take
an order that is not made yet.

The merchant reads the code out; the rider confirms it in their app; the order
moves to `picked_up`. The merchant's handover panel then shows who took it —
name, mobile, vehicle number — and when.

**The rider half does not exist yet.** `POST /v1/rider/orders/{id}/pickup` is
EP8/EP10 work. Until it ships, an order sits at `ready_for_pickup` and the
panel reads "Waiting for a rider to be assigned." Nothing on the merchant side
needs to change when it lands: the panel already reads `rider_id` and
`picked_up_at`, so it fills in on its own.

That is also why a code, not a button. A merchant tapping "the rider took it"
proves nothing — the code is evidence that the right rider collected the right
order, and it is what a disputed delivery is settled with.

`OrderStatusService` is the only way status changes. A controller setting
`$order->status` directly would skip the history row, the timestamp and the
Redis mirror — and those three are what the customer's tracking screen, the
dispatch queue and the payout report each read.

Refusals name the actual state. A merchant tapping Accept on an order the
customer cancelled two seconds earlier gets "This order was cancelled.", not a
generic failure.

### Rules

**Unpaid orders never reach the merchant.** `pending_payment` is filtered out
of every list: the customer may still be on the payment screen, and showing it
would put a ticket in the kitchen for money that never arrives.

**The live queue is oldest first.** The ticket waiting longest is the one that
needs attention. History is newest first.

**Rejection needs a reason of at least 10 characters**, because the customer
reads it, and it carries **no cancellation fee** — the customer did nothing
wrong, and charging them for the merchant's decision would be indefensible.

**Accepting always produces an estimate.** If the merchant does not supply
`prep_minutes`, it falls back to their configured `avg_prep_time_minutes`, so
the customer always sees a time.

**Redis is written outside the transaction and its failure is swallowed.** It
is a cache of MySQL; losing it costs the tracking screen its latency, not the
order its state.

### Endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/v1/merchant/orders` | live queue; `?history=1` or `?status=` |
| GET | `/v1/merchant/orders/{id}` | full order with items, timeline, customer |
| POST | `/v1/merchant/orders/{id}/accept` | optional `prep_minutes` |
| POST | `/v1/merchant/orders/{id}/reject` | `reason` required, min 10 chars |
| POST | `/v1/merchant/orders/{id}/preparing` | |
| POST | `/v1/merchant/orders/{id}/ready` | puts a delivery order into dispatch |

Lists are paginated (`per_page`, max 100).

## Scoping

Every merchant-owned record resolves **through the authenticated merchant**,
never from the id in the URL:

```php
$this->merchant($request)->menuItems()->findOrFail($item);
```

That is what turns another merchant's id into a 404 instead of a data leak, and
it is covered by tests for items, categories, orders and reordering.

## Money in JSON

Money is a JSON **number**. A whole-rupee price encodes as `260`, not `260.0` —
JSON cannot preserve the zero fraction.

**Flutter: read money as `num`, never as `double`.** `data['price'] as double`
throws on a ₹260 dish and succeeds on a ₹259.50 one, which is the worst kind of
bug to find in production. Use `(data['price'] as num).toDouble()`.

## Testing before customer checkout exists

Nothing can place an order until EP5, so the order screens have no data to show.
Create one:

```bash
php artisan nexmile:demo-order                          # oldest merchant
php artisan nexmile:demo-order --merchant=veera@enom.ai
php artisan nexmile:demo-order --status=accepted
```

It prices the order off the merchant's real menu when they have one.

**It refuses to run on production** — it writes an order nobody paid for, which
would land in a real kitchen queue and pollute payout reporting. Before launch,
when the only merchants are your own test accounts, override it:

```bash
php artisan nexmile:demo-order --merchant=you@example.com --force
```

`--force` still asks for confirmation, and declines under `--no-interaction`,
so a deploy script can never create one by accident.

Remove them again when you are done:

```bash
php artisan nexmile:demo-order --clean
```

Demo orders are numbered `NXD…` so they can be found and hard deleted — not
soft deleted, because a fictional row that still sits in the table is waiting
to confuse a payout query that forgets the global scope. The demo customer
account goes with them once it has no orders left.

**Do this before real merchants are live.** Once they are, a demo order in the
queue is a ticket someone will try to cook.
