# Dispatch and delivery (EP8, EP9, EP10)

The half that turns a cooked order into a delivered one. Before this, an order
reached `ready_for_pickup` and stopped dead — a customer could pay, a kitchen
could cook, and no food could leave the building.

## The full lifecycle

```
placed ─accept─> accepted ─preparing─> preparing ─ready─> ready_for_pickup
                     │                     │                     │
                     └──── dispatch ───────┴─── a rider accepts ──┤
                           starts here                            ▼
                                                           rider_assigned
                                                                  │
                                              "I'm at the shop" ──┤
                                                                  ▼
                                                        reached_restaurant
                                                                  │
                                                   pickup code ───┤
                                                                  ▼
                                                            picked_up
                                                                  │
                                              "I'm at the door" ──┤
                                                                  ▼
                                                         reached_customer
                                                                  │
                                                       deliver ───┤
                                                                  ▼
                                                            delivered
```

Merchant transitions and rider transitions are two halves of one state machine
in `OrderStatusService`. A rider cannot accept an order that is not ready,
collect one they were not assigned, or deliver one they never collected — each
would leave the customer's tracking screen describing something that did not
happen.

`reached_restaurant` and `reached_customer` are **optional**. A rider holding a
bag must never be blocked by a button they did not press, so `pickup` is
allowed from either `rider_assigned` or `reached_restaurant`, and `deliver`
from either `picked_up` or `reached_customer`.

## Two columns, not one chain

`status` is the kitchen's and the rider's. **`dispatch_status` is the hunt for
a rider**, and it runs alongside.

| `dispatch_status` | Means |
|---|---|
| `null` | self-pickup, or not dispatched yet |
| `searching` | offers are out; at least one phone is ringing |
| `assigned` | a rider accepted |
| `no_rider_found` | every wave went out and nobody took it — **needs a person** |
| `idle` | handed back, not yet re-offered |

The tempting shape is one chain: `confirmed → searching_rider → assigned`. It
loses the kitchen. Dispatch now starts when the merchant **accepts**, so an
order is being cooked *and* being offered at the same moment, and a single
column cannot say both. Worse, collapsing them takes "mark ready" away from a
merchant who is still cooking — breaking the kitchen screen on exactly the
orders that are going well.

So a rider can accept an order that is still `preparing`. They get the job, the
addresses and the first-mile credit; the status stays with the kitchen until
`ready`, at which point it moves straight to `rider_assigned` because somebody
is already standing there.

### Who can see it

Published on `GET /v1/orders/{id}` and `GET /v1/orders/{id}/track`, as
`dispatch_status` plus a translated `dispatch_label`. It is written in **both**
dispatch modes, so the customer's screen does not have to know which one is
running.

The customer app reads `searching` for *"finding a delivery partner"* and
`no_rider_found` for a real message rather than the same spinner. Without the
second one there is no way for an app to tell a slow search from a failed one,
and a customer sits watching a spinner over food nobody is coming for.

Riders never see it. It is the dispatcher's own state, and a rider has no
question it answers.

## Board or push

Set by `DISPATCH_MODE`. Both are implemented; the board is the default.

### Board (`DISPATCH_MODE=board`)

Ready orders appear on a **list that nearby on-duty riders poll**, and the
first to accept wins. It needs no queue worker and no Redis, which is why it
shipped first.

It stops working the moment riders are actually riding. Nobody reads a list
while on a bike.

### Push (`DISPATCH_MODE=push`)

The order is offered to the riders nearest the restaurant. Their phones ring
with a full-screen card and a countdown, and the first to accept wins.

> **Push needs a queue worker running.** Every timeout in it — the offer
> window, the next wave, giving up — is a delayed `CloseDispatchWave` job. With
> no worker, the first wave goes out and *nothing ever closes it*: the order
> sits in `searching` forever and no human is told. Check
> `systemctl status nexmile-worker` before switching the mode.
>
> There is deliberately **no scheduler fallback and no sweeper**. A second
> mechanism that quietly covers for a missing worker is a second mechanism to
> be wrong about, and it would hide the thing that actually needs fixing.

#### The waves

| Wave | Radius | Riders | Window |
|---|---|---|---|
| 1 | 2 km | 5 | 30 s |
| 2 | 4 km | 10 | 30 s |
| 3 | 6 km | 15 | 30 s |
| — | — | — | `no_rider_found` |

All env-settable (`DISPATCH_WAVE1_RADIUS` and friends).

**These radii are Swiggy's, and Swiggy charges Swiggy's fee.** Nexmile delivers
1 km for about ₹29. A rider 6 km from the restaurant is a twenty-minute ride
*before* the delivery starts — which no rider takes twice, and which arrives
cold if they do. Expect to cut wave 3, and possibly wave 2, once there is real
acceptance data.

With the rider pool at launch, wave 1 alone is enough: offer to every idle
rider within a couple of kilometres, first accept wins.

#### A rider holding a card is not a duty status

"Has a popup showing" is **derived from the offers table**, not stored on the
rider:

```php
RiderOffer::where('rider_id', $id)->live()->exists()
```

where `live()` is `status = pending AND expires_at > now()`. Both halves
matter. A pending row whose window has passed is an offer nothing got round to
expiring yet — the queue was slow, the worker restarted — and treating it as
live would hold a rider out of dispatch on the strength of a job that may never
run.

A stored `OFFER_PENDING` flag has to be cleared by something, and whatever
clears it will one day not run, leaving a rider who looks busy forever and
quietly stops earning. An offer row stops counting on its own.

#### The server owns expiry

The card counts down to an `expires_at` **the server set**, not from a duration
the app was handed. A phone with a wrong clock, a message that sat in a queue,
an app resumed from the background twenty seconds late — each of those leaves a
card ringing for an order somebody else is already carrying, and the rider
finds out by tapping Accept and being refused.

The data message also carries an FCM **TTL** equal to the window. A card
delivered after its window is not late, it is wrong: it would ring for food
another rider has collected.

#### Letting it ring has a cost; declining does not

Three unanswered offers in a row and the rider goes **off duty**
(`DISPATCH_AUTO_OFFLINE_MISSES`, 0 disables).

Not a punishment — a phone at the bottom of a bag. Every wave that includes a
rider who is not there is thirty seconds the customer waits for nothing, and
dispatch has no other way to tell that rider apart from one who is deciding.

**Declining is answering** and resets the counter. A system that punishes an
honest "no" teaches riders to let it ring instead, which costs everyone thirty
seconds rather than none.

`riders.offers_received` / `offers_accepted` carry the acceptance rate;
`Rider::acceptanceRate()` returns `null` for a rider who has never been offered
anything, because 0% would read as somebody who refuses everything.

#### The offers table is kept, not cleaned up

A row exists for every offer ever made. That is the record of how long dispatch
took and who was asked, which is the only way to answer *"why did this delivery
take forty minutes"* after the fact.

The one exception is **re-dispatch** after a rider hands an order back: those
rows are deleted, because `(order_id, rider_id)` is unique and a rider who
declined forty minutes ago — while mid-delivery — is a perfectly good candidate
now.

### The race is settled by one UPDATE

```php
Order::whereKey($id)
    ->whereNull('rider_id')
    ->where('dispatch_status', DispatchStatus::Searching->value)
    ->whereNotIn('status', Order::finishedStatuses())
    ->update([...]);   // affected rows must be exactly 1
```

Two riders tapping Accept in the same millisecond is the **normal** case, not
the edge case. One winner, one refusal. No lock to acquire, nothing to release
if the process dies mid-way, and correct across separate PHP workers on
separate machines.

The board does the same thing against `status = ready_for_pickup`.

### What the loser is told, and why the code matters

| Code | Means | What the popup should do |
|---|---|---|
| **200** | it is yours | open the trip screen |
| **409** | somebody was faster, or the order was cancelled | close the card, say "taken" |
| **410** | the window closed | close the card **silently** — the countdown already ran out on screen |
| **422** | the *rider* cannot take it (off duty, lapsed documents, already carrying) | show the message |
| **404** | not this rider's offer | close the card |

Collapsing 409 and 410 into one code would make the app guess, and guessing
wrong means either a rider who is told nothing or a rider who is told twice
about something they watched happen.

### Withdrawing a card

When somebody wins, or the order is cancelled, every other live offer is
cancelled and its rider gets a data message of its own:

```json
{ "type": "OFFER_CANCELLED", "order_id": "41", "reason": "taken" }
```

Sent rather than left to expire, because twenty-nine seconds of ringtone for an
order that is already gone is how a rider learns to ignore the sound.
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
| POST | `/v1/rider/location` | GPS ping + heartbeat; `is_mock` is refused |
| POST | `/v1/rider/duty-status` | go online / offline / on break |
| GET | `/v1/rider/offers/current` | the card that should be on screen, or `null` |
| POST | `/v1/rider/offers/{ulid}/accept` | claim it — 200 / 409 / 410 / 422 |
| POST | `/v1/rider/offers/{ulid}/reject` | decline, optional `reason` |
| GET | `/v1/rider/orders/available` | the board (board mode), nearest restaurant first |
| GET | `/v1/rider/orders` | own orders; `?active=1` for the job in hand |
| GET | `/v1/rider/orders/{id}` | one order |
| POST | `/v1/rider/orders/{id}/accept` | claim it off the board |
| POST | `/v1/rider/orders/{id}/reached-restaurant` | tells the kitchen somebody is waiting |
| POST | `/v1/rider/orders/{id}/pickup` | `pickup_code` required |
| POST | `/v1/rider/orders/{id}/reached-customer` | tells the customer the rider is outside |
| POST | `/v1/rider/orders/{id}/deliver` | closes the order |
| POST | `/v1/rider/orders/{id}/release` | hand it back; re-dispatches |
| GET | `/v1/rider/orders/{id}/route` | turn-by-turn to the next stop |

Ops, for orders dispatch could not place:

| Method | Path | Purpose |
|---|---|---|
| GET | `/v1/admin/dispatch/stranded` | failed **and** stuck orders, with `riders_on_duty` |
| POST | `/v1/admin/dispatch/{id}/retry` | put it back out from wave one |
| POST | `/v1/admin/dispatch/{id}/assign` | hand it to a named rider |

`stranded` reports two kinds, and the second is the one that bites. **Failed**
is an order dispatch gave up on, which is honest. **Stuck** is one that has
been `searching` far longer than its waves should have taken — which almost
always means the queue worker is not running.

Manual assignment skips every eligibility check except the ones that would be
fraud or a legal problem. A rider whose licence has expired cannot be handed a
delivery by anybody, including an admin.

Delivering sets the rider back to `available` and increments
`completed_deliveries`.

## Cash

`collect_cash` on every rider order is the grand total minus anything already
captured online — the whole amount for a COD order, zero for a prepaid one.

Queried rather than read off a loaded relation: getting it wrong costs the
rider money out of their own pocket, so it must not depend on what a caller
remembered to eager load.

## Not built

- **WebSockets.** The client spec asks for a socket channel alongside FCM, for
  instant delivery while the app is open and faster cancellations. Not built:
  there is no broker on this deployment, and adding one is real infrastructure
  — a port, nginx, a supervised process. A high-priority data message plus
  `GET /offers/current` on resume covers the same ground for now. Worth
  revisiting when a dropped offer is measurably costing orders.
- **Dispatch timed to prep.** Starting at (prep time − travel time) is the
  right answer and needs prep estimates we do not yet trust. `accepted` and
  `ready` are the two honest approximations, and `DISPATCH_START_AT` picks one.
- **Ranking beyond distance.** Rating, acceptance rate, today's earnings and
  vehicle type all belong in the ordering eventually. Distance first until
  there is enough data for the rest to mean anything.
- **Batching two drops onto one rider.** `max_concurrent_orders_per_rider` is
  1. At 1 km the second customer's food goes cold in a bag.
- **Earnings and settlement** (EP13). Riders can be paid manually at first.

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

Clears `rider_id`, and the rider goes back to `available`.

Two shapes, because offer dispatch assigns a rider while the food is still
cooking. If the order was **ready**, the status walks back to
`ready_for_pickup`. If the kitchen never stopped, there is no status to walk
back — the rider is simply removed, and a history row is written by hand so
that *"a rider gave this back"* is still on the record. Losing that is losing
the only evidence of why a delivery took twice as long as it should have.

In push mode it then **re-dispatches from wave one**. On the board this needed
nothing — the order reappeared on a list people were already polling. With
offers, nobody is looking at anything, so an order handed back and not
re-offered stops existing until a human notices.

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
