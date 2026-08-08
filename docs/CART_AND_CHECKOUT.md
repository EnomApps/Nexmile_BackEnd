# Cart and checkout (EP5)

The keystone. Until this existed nothing could create an order, so the merchant
order screens had no data, dispatch had nothing to dispatch, and every screen
after the menu in the customer app was blocked.

## Carts

**One cart per customer per restaurant**, enforced by a unique key on
`(user_id, merchant_id)`.

Mixing shops in one cart would break the single-pickup model the 1 km radius is
built on. But clearing someone's basket because they glanced at another
restaurant is how you lose an order, so each shop keeps its own.

| Method | Path | Purpose |
|---|---|---|
| GET | `/v1/carts` | every open cart, newest first |
| GET | `/v1/restaurants/{id}/cart` | one cart, priced |
| POST | `/v1/restaurants/{id}/cart/items` | add |
| PATCH | `/v1/restaurants/{id}/cart/items/{item}` | change quantity |
| DELETE | `/v1/restaurants/{id}/cart/items/{item}` | remove a line |
| DELETE | `/v1/restaurants/{id}/cart` | empty it |
| POST | `/v1/restaurants/{id}/cart/checkout` | place the order |

Pass `?fulfilment_type=pickup` when reading a cart to see pickup pricing — the
delivery fee disappears, which changes the total the customer is deciding on.

**Quantity 0 removes the line**, so the app's minus button needs no special
case at the boundary.

**Identical lines merge.** Same dish, same options, same note becomes quantity
2 — a cart listing the same thing twice looks like a bug to the person holding
the phone. Two chai with different sugar levels stay two lines.

**Required choices are enforced on add, not at checkout.** Leaving it to the
last step means the customer discovers that a dish they added five minutes ago
was never valid.

**Items that sell out are named, not dropped.** `unavailable_items` lists them
and `can_checkout` goes false. A basket that quietly shrinks between screens is
worse than one that explains itself.

## Money

`PricingService` is the only place money is calculated. The cart preview and
the order that gets written both go through it, so what the customer was shown
and what they are billed cannot disagree.

**No client-supplied amount is ever read.** Totals arriving in a request are
ignored rather than validated — a validated total is still a number the
customer chose.

```
items_total       Σ (unit_price + options) × quantity
packaging_fee     merchant's setting
delivery_fee      flat ₹25, free above ₹299, never on pickup
discount_total    0 until coupons exist (EP?)
tax_total         per-item GST + 18% on the delivery fee
─────────────────────────────────────────────────
grand_total       items + packaging + delivery − discount + tax
```

Menu prices are **tax-exclusive**: GST is added at checkout and shown as its own
line, which is what `items_total` and `tax_total` being separate columns
already assumed. Each item carries its own rate, since prepared food and
packaged goods differ.

The delivery fee is **flat**, because the whole service area is 1 km. A
per-kilometre rate across a small town produces fees differing by a few rupees
— noise dressed up as precision.

**Commission is charged on food and packaging, never on the delivery fee.**
That fee pays the rider; it is not the merchant's revenue to be charged on.
`commission_rate` is deliberately **not mass-assignable** — it is a contract
term, not a merchant preference, and must never be settable by the account it
charges.

Every intermediate total is rounded as it is produced rather than once at the
end, so the lines a customer can add up themselves sum to the total they are
charged. A breakdown that is a paisa out is a support ticket, however
defensible the arithmetic.

## Checkout

A cart is a working document; an order is a commitment. Everything that could
have changed while the customer was shopping is re-checked at this point,
because the gap between the two is where a bad ticket reaches a kitchen:

- cart is not empty
- no item has sold out — **named** in the error, so the customer knows what to remove
- merchant is KYC-verified, accepting orders, within opening hours, FSSAI current
- the delivery address is inside the radius of **that restaurant**
- basket meets the merchant's minimum
- payment method is one that can actually complete

The address is resolved **through the authenticated user**, so another
customer's `address_id` is a 404 rather than a way to have food sent to a
stranger.

### What gets written

Order rows **snapshot** name, unit price, options, GST rate and the delivery
address. A merchant renaming a dish or changing its price must never rewrite a
past order, and certainly not an invoice.

The cart is **consumed**, not kept — leaving it would let a customer place the
same order twice by tapping back.

Redis live state is written outside the transaction and its failure swallowed;
it is a cache of MySQL.

### Payment

**Cash on delivery only.** No gateway is integrated yet, and offering a method
that cannot complete is worse than not offering it — the customer loses their
basket at the last step. COD is also how most of this market already pays.

A COD order is created at `placed`, so the merchant sees it immediately. When a
gateway lands, those orders will sit at `pending_payment` until the webhook
confirms — which is why the merchant queue already filters that status out.

## The customer's orders

| Method | Path | Purpose |
|---|---|---|
| GET | `/v1/orders` | history, newest first; `?active=1` for in-flight |
| GET | `/v1/orders/{id}` | full order with items and timeline |
| GET | `/v1/orders/{id}/track` | small, cheap, poll every few seconds |
| POST | `/v1/orders/{id}/cancel` | before the restaurant accepts |

`/track` is deliberately minimal: it reads Redis and falls back to MySQL rather
than loading the whole order each time.

**Cancellation is allowed only while `placed`.** Once the merchant accepts, the
kitchen may already have started and cancelling would waste food someone is
part way through cooking. The refusal says so rather than failing generically.
No cancellation fee — nobody had started work.

## Money in JSON, again

`grand_total` of ₹430.00 encodes as `430`, not `430.0`. **Read money as `num`,
never `double`** — `as double` throws on whole rupees and succeeds on ₹92.50,
which is the worst possible way round. The test suite asserts money through a
helper for exactly this reason.
