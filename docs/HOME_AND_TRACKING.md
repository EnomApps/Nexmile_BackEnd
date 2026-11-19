# Home cards, order again, and the rider on the tracking screen

Private. Not served by the docs allowlist.

Four requests from the customer app. One was already built, two are built here,
and half of the fourth is waiting on a decision nobody has made.

## 1. Offers on restaurant cards — already built

`offers` has been on every restaurant in `GET /restaurants` and `GET /home`
since the home screen work: a list of `{label, type}`, empty when there are
none, first in the list being the one worth showing.

It is **derived from what the pricing code actually does**, not stored — see
`Merchant::offers()`. An offer nobody honours is worse than no offer, so the
two sources are the same source. Today it produces:

| `type` | When |
| --- | --- |
| `food_rescue` | The restaurant has at least one live surplus deal, by the same test the deals endpoint uses |
| `free_delivery` | `checkout.free_delivery_above` is set |

Both labels are translated strings, so they arrive in the customer's own
language and should be printed exactly as sent.

The one thing to know for later: these are **not coupons**. When the coupon
table exists, a coupon valid at a restaurant will want to appear here too, and
that will be the first time this list has an entry that is not derivable from
config.

## 2. The delivery window on a card

`eta_min_minutes` and `eta_max_minutes`, on each restaurant in
`GET /restaurants` and `GET /home`.

**Cooking plus the ride out to the customer. Not plus the ride to the
kitchen.** Dispatch starts hunting a rider the moment the merchant accepts, so
that leg runs while the food is cooking; adding both would double-count the
overlap and quote every card about ten minutes long.

Road distance where routing measured it, straight line otherwise — a straight
line across a river sets an expectation nothing can keep, so the road figure
wins where it exists.

The ride is converted with **`tracking.average_speed_kmh`**, the same figure
the live tracking map counts down with. One rule, so the promise on the card
and the countdown during the delivery cannot drift apart.

### Two settings, in `config/discovery.php`

| Key | Default | |
| --- | --- | --- |
| `eta.spread_minutes` | 5 | How wide the band is |
| `eta.floor_minutes` | 15 | Nothing is quoted faster than this |

The floor is the one that matters. A rider still has to be found, reach the
counter, be handed a bag and park at the other end; a card reading "4-9 min"
for the restaurant next door is a complaint written in advance.

### Absent, not null, when it cannot be measured

A storefront fetched on its own carries no customer position, so there is no
distance and both keys are **omitted**. Same convention as
`road_distance_metres`: show it when it is there, fall back to
`avg_prep_time_minutes` when it is not.

## 3. Order it again

```
POST /api/v1/orders/{order}/reorder
```

Answers with the cart in the same shape as `GET /restaurants/{id}/cart`, plus a
top-level `skipped` list.

```json
{
  "message": "Added back to your basket, minus what is unavailable.",
  "data": { "...the cart..." },
  "skipped": [{ "name": "Mutton Biryani" }]
}
```

Built on `CartService::add()` rather than inserting cart rows directly, so a
reorder goes through exactly the rules a tap goes through: the dish must be on
that restaurant's menu, in stock, inside its deal window if it is one, and its
options must still exist and still satisfy the group's rules.

### Two decisions worth keeping

**The cart is replaced, not added to.** "Order again" means *that* order.
Merging it into whatever was left in the basket from yesterday produces a third
basket the customer never asked for. The clear and the re-add happen in one
transaction.

**Nothing is dropped silently.** A dish that cannot be reordered is named, from
the order's own snapshot, so the customer is told *which* dish is missing
rather than how many are. A basket that quietly comes back one dish short is a
customer paying for an order they did not mean to place — which is a worse
failure than refusing the whole thing.

A line is skipped when the dish was removed from the menu, is out of stock, is
a rescue deal outside its window, or had a choice attached that the restaurant
has since deleted. All four mean the same thing to the customer, so they get
the same answer.

### The fallback path also works now

The app side offered to rebuild the basket itself if the endpoint was hard.
`OrderItemResource` already carried `menu_item_id`; it now also carries
`item_option_id` on each option, null once that choice has been removed. The
endpoint is the better path because it applies the menu's rules rather than
asking the app to, but the other way through exists.

## 4. The rider on the tracking screen

`GET /orders/{id}/track`, inside `rider`:

- **`rating`** — the rider's score, or `null` until enough people have rated.
  Null, never `0.0`: a zero beside a stranger's name at the door reads as a
  warning where a new rider is only new. `rating_count` is sent alongside, for
  the same reason the restaurant card sends it.
- **`photo_url`** — **always `null` today**, and sent anyway so the app can
  ship the layout now and light up when there is something to show.

### Why there is no rider photo

Riders have no photo column, and adding one is not the work. The work is
deciding:

- **who takes the picture** — the rider during onboarding, or an admin during
  verification
- **whether a rider may decline** and still work
- **what happens to it when they leave**, which the DPDP retention schedule has
  to answer alongside the location trail in `docs/TRACKING.md`

A photograph of a worker's face, shown to every customer they deliver to, is
not a field to add because an app has space for it. The admin rider page has
the same gap for the same reason — see `docs/ADMIN_RIDERS.md`.

When those three are answered the implementation is small: a column, an upload
through `ImageService` exactly like the door photo, and a short-lived signed
URL. The door photo in `docs/DOOR_PHOTO.md` is the pattern to copy, including
its TTL reasoning.

## Tests

`tests/Feature/ReorderAndEtaTest.php`, 15 tests. The ones that matter:

- the same basket comes back, with its chosen options
- reordering **replaces** the basket rather than adding to it
- an out-of-stock dish is named, not dropped
- a deleted dish is named from the order snapshot
- a mixed order adds what survives and lists what does not
- another customer's order is a 404
- the card quotes cooking plus the ride, as a band
- nothing is promised faster than the floor
- a storefront with no customer position omits the window
- tracking sends the rider's rating and a null photo
- a new rider has no rating rather than zero
