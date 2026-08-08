# Dispatch and delivery (EP8, EP9, EP10)

The half that turns a cooked order into a delivered one. Before this, an order
reached `ready_for_pickup` and stopped dead — a customer could pay, a kitchen
could cook, and no food could leave the building.

## The full lifecycle

```
placed ─accept─> accepted ─preparing─> preparing ─ready─> ready_for_pickup
                                                                │
                                            rider accepts ──────┤
                                                                ▼
                                                        rider_assigned
                                                                │
                                              pickup code ──────┤
                                                                ▼
                                                          picked_up
                                                                │
                                                  deliver ──────┤
                                                                ▼
                                                          delivered
```

Merchant transitions and rider transitions are two halves of one state machine
in `OrderStatusService`. A rider cannot accept an order that is not ready,
collect one they were not assigned, or deliver one they never collected — each
would leave the customer's tracking screen describing something that did not
happen.

## Board, not push

Ready orders appear on a **list that nearby on-duty riders poll**, and the
first to accept wins.

The alternative — offer to the single nearest rider, wait, pass it on if they
decline or time out — allocates better at volume, but it only works with a
queue worker running to expire offers. **There is no worker on this
deployment**, and without one a declined offer strands the order with nobody
looking at it. An order that silently stalls is far worse than a rider choosing
from a short list.

`DispatchQueueService` already has the offer, lock and TTL primitives for push
when volume justifies running a worker. Switch with `DISPATCH_MODE`.

### The race is settled by one UPDATE

```php
Order::whereKey($id)
    ->whereNull('rider_id')
    ->where('status', OrderStatus::ReadyForPickup->value)
    ->update(['rider_id' => $rider->id]);   // affected rows must be exactly 1
```

Two riders tapping Accept in the same instant produce one winner and one 422.
No lock, no isolation level to reason about, and correct across separate PHP
processes. The loser is told *"Another rider took this order"* — expected on a
shared board, not an error worth alarming anyone about.

## Guards

**A rider is never offered their own order.** Filtered out of the board *and*
refused on accept. Otherwise: order food, deliver it to yourself, mark it
delivered, keep the delivery fee — repeatable at will, and it looks like a
rider who is simply very fast.

**One order at a time** (`max_concurrent_orders_per_rider`). At 1 km, batching
a second drop saves a few minutes and costs that customer their food going cold
in a bag. Raise it only with evidence.

**The drop address is withheld until the job is taken.** A board that any
on-duty rider can poll must not double as a list of where customers live.

**Self-pickup orders never reach the board** — the customer is collecting.

**KYC, expiry and duty status are all re-checked on accept**, not just when
going online. A licence can lapse mid-shift.

## The pickup code

Four digits, generated when the order is placed, shown to the merchant only
once the food is ready.

The merchant reads it out; the rider enters it. Compared with `hash_equals` —
the code is short, and it costs nothing to compare properly.

Without it, "picked up" is a button a rider could press from anywhere. With it,
it is evidence that the right rider collected the right order, which is what a
disputed delivery is settled with.

## Location (EP9)

```
POST /v1/rider/location   { "latitude": 9.93, "longitude": 78.12 }
```

Every few seconds while on duty. Positions go to a **Redis geo set**, not the
orders table — no relational table should absorb that write volume.

The ping doubles as a **heartbeat**. A rider whose app is killed or loses signal
drops out of dispatch on its own once the TTL lapses, so a phone in a pocket
cannot hold an order nobody is riding towards.

A copy is written to MySQL as a fallback so a Redis restart does not empty
every rider's board. It is a single row update, not a history — the trail is
not worth the write volume.

**An offline rider is not tracked.** The endpoint returns `tracking: false`
rather than an error; the app just stops pinging.

### What the customer sees

`GET /v1/orders/{id}/track` includes the rider's live position — but **only
while `rider_assigned` or `picked_up`**. Once delivered, a rider's movements
are their own business.

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| POST | `/v1/rider/location` | GPS ping + heartbeat |
| GET | `/v1/rider/orders/available` | the board, nearest restaurant first |
| GET | `/v1/rider/orders` | own orders; `?active=1` for the job in hand |
| GET | `/v1/rider/orders/{id}` | one order |
| POST | `/v1/rider/orders/{id}/accept` | claim it |
| POST | `/v1/rider/orders/{id}/pickup` | `pickup_code` required |
| POST | `/v1/rider/orders/{id}/deliver` | closes the order |

Delivering sets the rider back to `available` and increments
`completed_deliveries`.

## Cash

`collect_cash` on every rider order is the grand total minus anything already
captured online — the whole amount for a COD order, zero for a prepaid one.

Queried rather than read off a loaded relation: getting it wrong costs the
rider money out of their own pocket, so it must not depend on what a caller
remembered to eager load.

## Not built

- **Earnings and settlement** (EP13). Riders can be paid manually at first.
- **Push notifications.** A rider currently has to be looking at the board to
  see a new order. This is the biggest remaining gap in the product, and it is
  not in any epic yet.

## Ways out when something goes wrong

Every one of these existed as a hole rather than a feature. Without them a
kitchen with a gas failure, a rider with a puncture and a support agent taking
a phone call all had nothing to do but wait.

### A rider cannot clock off carrying an order

`POST /v1/rider/duty-status` refuses `offline` and `on_break` while the rider
has an active order. Otherwise the order keeps their `rider_id`, sits in
flight, and nobody is accountable for food already in a bag.

### A rider can hand an order back — before collecting it

```
POST /v1/rider/orders/{id}/release   { "reason": "Puncture" }
```

Clears `rider_id`, returns the order to `ready_for_pickup`, and puts it back on
the board for someone else. The rider goes back to `available`.

**Only before pickup.** `release` is not a transition from `picked_up`, so food
already in a bag cannot be abandoned this way — at that point a human has to be
involved.

### A merchant can cancel after accepting

```
POST /v1/merchant/orders/{id}/cancel   { "reason": "Gas cylinder ran out, sorry." }
```

A different act from rejecting: the kitchen said yes and then something went
wrong. Reason is required and shown to the customer; no cancellation fee.

Refused once a rider holds the order — *"A rider is already collecting this
order. Call them before cancelling."* At that point somebody is standing at a
counter, and cancelling is a conversation rather than a button.

### Admin can see and cancel anything

`/admin/orders` — three views:

| View | Shows |
|---|---|
| In flight | everything not yet delivered or cancelled |
| **Needs attention** | ready for over 10 minutes with **no rider assigned** |
| All | everything, searchable by order number or phone |

The stale view is the answer to "an order nobody accepted sits at
`ready_for_pickup` forever". At 1 km a rider is minutes away, so ten minutes
already means something is wrong. The tab carries a count so it is visible
without looking.

The detail page shows both parties, the rider, the full money breakdown, the
items with their options, and the status timeline with **who** made each change
and when. That last part is what makes "who cancelled this and why" answerable.

Admin cancel works from any non-terminal status — the escape hatch for an order
no rider ever took, or one stuck behind a problem nobody else can resolve.
