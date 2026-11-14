# Live order tracking

The map a customer watches while their food is on its way: where the rider is,
how long until it arrives, and the line between the two.

Everything here is read every few seconds, by every waiting customer, for the
whole delivery. That makes two kinds of mistake expensive in a way a normal
endpoint's are not — a wrong timestamp shows a rider frozen in a street they
left ten minutes ago, and an uncached route turns one dinner into a hundred
billed Directions calls.

## The rider app: sending position

`POST /v1/rider/location`

```json
{ "latitude": 9.92, "longitude": 78.12, "accuracy_metres": 8,
  "heading": 135, "speed": 6.2, "recorded_at": "2026-11-09T10:15:30Z" }
```

| Field | | |
|---|---|---|
| `latitude`, `longitude` | required | |
| `accuracy_metres` | optional | 0–10000 |
| `heading` | optional | 0–360 compass bearing, so the marker points where the bike is going |
| `speed` | optional | metres per second, as the platform APIs report it |
| `recorded_at` | optional | **when the phone took the fix** |

Every optional field is genuinely optional — a build that sends only latitude
and longitude keeps working exactly as before.

**`recorded_at` is not when we received it.** A rider goes through a basement
car park, the app buffers, and six fixes arrive at once in the wrong order.
Stamped with arrival time they would scatter the rider across the customer's
map; stamped with the fix time, the stale ones are discarded.

The response says whether the ping was used:

```json
{ "message": "Location updated.", "data": { "tracking": true, "accepted": true } }
```

- `tracking: false` — the rider is off duty. **The app should stop sending.**
- `accepted: false` — a newer position is already stored, so this one changed
  nothing. Still a 200: the app did nothing wrong and has nothing to retry.

A fix older than `tracking.max_ping_age_seconds` (2 minutes) is refused
outright, even with nothing stored. A reconnecting phone replaying a tunnel's
worth of buffered positions is not news.

## The customer app: reading it

`GET /v1/orders/{id}/track`

```json
{
  "status": "picked_up",
  "status_label": "On the way",
  "dispatch_status": "assigned",
  "dispatch_label": "Delivery partner assigned",
  "estimated_minutes": 8,
  "stale_after_seconds": 45,
  "route": "yzlcAkr}bMpG}C...",
  "rider": {
    "name": "Ravi", "phone": "98...", "vehicle_number": "TN58...",
    "vehicle_type": "scooter",
    "latitude": 9.92, "longitude": 78.12, "heading": 135,
    "updated_at": "2026-11-09T10:15:30Z"
  }
}
```

### `dispatch_status` is a second column, not a later stage

Where the search for a rider stands — `searching`, `assigned`,
`no_rider_found`, `idle` — and a different question from where the food is. An
order is `preparing` and `searching` at the same moment, because dispatch
starts when the kitchen accepts.

Null before dispatch has started, and null throughout when offers are switched
off. `dispatch_label` is the same fact already translated.

**`no_rider_found` is the one an app must not swallow.** The food is getting
cold and nobody is coming for it. A customer watching a spinner will never
learn that on their own, and the spinner looks exactly like `searching`.

There is no rider photo and no vehicle model to show. `vehicle_type` is one of
`bicycle`, `motorcycle`, `scooter`, `ev` — enough for the right icon. Rider
photographs exist only as KYC documents in a private bucket, next to their
Aadhaar; showing one to a customer would need a separate profile photo that a
rider uploads for that purpose.

**`rider.updated_at` is the fix time**, not the time of the request. Past
`stale_after_seconds` since then, show "location updating" rather than a rider
standing still in a street. That number is published here so the backend and
both apps cannot drift to different ideas of stale.

Only the order's own customer may read it. Anyone else gets a 404.

### When the position is filled

While the order is `rider_assigned`, `reached_restaurant`, `picked_up` or
`reached_customer`, and not otherwise. After delivery the rider is still named
and callable — a customer with a problem needs that — but `latitude`,
`longitude` and `heading` go null and `estimated_minutes` goes null with them.
Where a rider goes next is their own business.

**The two arrival statuses are in that list deliberately.** They sit either
side of pickup, and leaving them out made the rider vanish from the map for
exactly as long as they stood at the counter and then again at the door — the
two moments a customer watching a late delivery most wants to see somebody
there.

### Which leg `route` draws

| Order status | Line drawn | Why |
|---|---|---|
| Before pickup | restaurant → customer | The rider is riding the *other* way, to the kitchen. Drawing their path would show a line moving away from the person watching it. |
| After pickup | rider → customer | Now they are the same journey. |

Keyed on `picked_up_at` being set rather than on the status being `picked_up`,
because `reached_customer` is also after pickup. Matching the one status sent a
rider already standing at the door back through "ride to the kitchen, plus
handover, plus the delivery", adding minutes to an estimate at the exact moment
it should have been counting down to zero.

`route` is Google's encoded overview polyline — the string form every map SDK
draws directly. **Null is an ordinary answer**, not an error: routing switched
off, no key, no network, nowhere to ride. Treat it as "show the markers, skip
the line".

### How `estimated_minutes` is built

Minutes until the food arrives, not until it is cooked.

Before pickup it is the rider's ride to the kitchen, plus
`tracking.handover_minutes`, plus the delivery leg. Counting only the last is
how a fifteen minute promise quietly becomes eighteen.

Sources in descending order of trust:

1. A routed duration, using `duration_in_traffic` where Google gives it
2. The straight line at `tracking.average_speed_kmh` — deliberately
   pessimistic, because an arrival that proves early is a pleasant surprise and
   one that proves late is a support call
3. The kitchen's own prep estimate

Null only when none of those exist. "Arriving in —" beats a confident wrong
number.

The ride to the restaurant is **never** routed. It is a short hop inside a 1 km
zone, it changes every few seconds, and paying Google to refine a two-minute
guess on every poll would cost more than the order makes.

## Cost control

This is the part that decides the maps bill.

A customer polling every 8 seconds across a 15 minute delivery is about 110
requests. At one Directions call each, one order costs more than it earns. The
route is therefore cached per order and reused:

| Setting | Default | |
|---|---|---|
| `TRACKING_ROUTE_TTL` | 45s | How long a computed route is reused |
| `TRACKING_ROUTE_MOVED` | 200m | Redraw early once the rider has ridden this far |

Within a 1 km delivery, 200 m is a quarter of the journey — far enough that the
old line is visibly wrong. A leg that does not move (restaurant → customer,
while the rider fetches the food) is simply reused until the TTL lapses.

Failures are cached for a minute, so one maps outage is not one Directions call
per customer per poll.

The cached route is dropped when the order reaches a terminal status. The
rider's own position is deliberately left alone — it is keyed per rider, not
per order, and they are very likely already carrying the next delivery.

## The rider app: navigating

The rider navigates inside our app rather than being handed to Google Maps
with the order screen left behind it. That means we buy the turns.

```
GET /v1/rider/orders/{order}/route?latitude=…&longitude=…&target=pickup|dropoff
```

`latitude` and `longitude` are where the rider is now. `target` is the
restaurant (`pickup`) or the customer (`dropoff`).

```json
{ "data": {
  "target": "dropoff",
  "polyline": "<encoded polyline of the whole route>",
  "distance_metres": 2300,
  "duration_seconds": 540,
  "steps": [
    { "instruction": "Turn right onto 3rd Street",
      "maneuver": "turn-right",
      "distance_metres": 200, "duration_seconds": 40,
      "end_latitude": 9.9300, "end_longitude": 78.1200 }
  ] } }
```

Instructions are **plain text**. Google sends HTML — street names in `<b>`, and
a `<div>` with no separator before "Destination will be on the left" — and it
is stripped here rather than in the app, because an app that renders our
markup is an app we break by changing providers. Tags become a space, not
nothing, or "Turn right onto<div>3rd Street" runs two words together.

`maneuver` is **null on the first and last steps**, which are "head north" and
"arrive" rather than turns. An app that assumes an icon per step draws a wrong
arrow on the rider's first instruction.

Instructions come in the rider's own language, read off `Accept-Language` and
allowlisted to the three the platform ships. An API request carries no session,
so the app is the only thing that knows which one the rider picked.

### Who may ask, and what the refusals mean

| | |
|---|---|
| Not the assigned rider | **404** — another rider's order is not something to confirm the existence of, and the drop address is where a customer lives |
| Delivered, cancelled or rejected | **403** — a finished order's address stops being that rider's business |
| No maps key configured | **503** — permanent until somebody sets one |
| No road to the stop, or an address with no coordinates | **422** |

Both 503 and 422 are a dotted straight line in the app, not an error shown to
the rider.

### Cost control, again

Each call is a billed Directions request set against a delivery fee of about
₹29. A rider parked just off the route, or an app retrying a failure, can ask
every few seconds for a quarter of an hour — which spends more on directions
than the delivery earns.

| Setting | Default | |
|---|---|---|
| `NAV_COOLDOWN` | 10s | Serve the route we already have rather than buying it again |
| `NAV_MAX_PER_ORDER` | 15 | Billed calls per order, after which the last route is reused |

The cooldown is deliberately **shorter than the app's own 15 seconds**, so a
rider who has genuinely gone off route is never held up by it. What it catches
is a retry loop, a second device, or two screens open on one order.

Both limits serve a slightly older route rather than refusing. A rider
mid-delivery with no directions is a worse outcome than a route ten seconds out
of date, so every limit here degrades to something stale rather than to
nothing — including a call that fails after a good one, which keeps serving the
good one rather than dropping the rider onto a straight line mid-junction.

A failed call still costs the cooldown. Otherwise a maps outage is one billed
request per rider per poll: the failure returns instantly, the app retries, and
nothing is holding the door.

## Configuration

```env
TRACKING_ROUTE_ENABLED=true
TRACKING_ROUTE_DRIVER=google
TRACKING_ROUTE_TTL=45
TRACKING_ROUTE_MOVED=200

NAV_COOLDOWN=10
NAV_MAX_PER_ORDER=15
```

Off by default. Without it the map still shows a moving rider and an estimate
from the straight line — exactly what the app did before routes existed.

Needs `GOOGLE_MAPS_SERVER_KEY`. **Use a separate key from the app keys**,
restricted by IP to this server and to the Directions API alone. See
[MAPS.md](MAPS.md) — a key is only as restricted as its loosest user.

## Where the position lives

Redis, latest only, never a database row per ping. Pings arrive every 10
seconds per carrying rider and every 25 when idle, which is a write rate no
relational table should absorb.

A single indexed row on `riders` is also updated, but only as a fallback for
the ops board after a Redis restart. It is not a trail and there is no history.

The ping doubles as a heartbeat: a rider whose app is killed drops out of
dispatch on its own once the TTL lapses, so a phone in a pocket cannot hold an
order nobody is riding towards.

## Not built

Polling, not push. A WebSocket or an FCM data message would cut the request
volume, but polling every 8 seconds is cheap on our side — the cost is the
Directions calls, and those are already capped by the cache regardless of how
often anyone asks.
