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
  "estimated_minutes": 8,
  "stale_after_seconds": 45,
  "route": "yzlcAkr}bMpG}C...",
  "rider": {
    "name": "Ravi", "phone": "98...", "vehicle_number": "TN58...",
    "latitude": 9.92, "longitude": 78.12, "heading": 135,
    "updated_at": "2026-11-09T10:15:30Z"
  }
}
```

**`rider.updated_at` is the fix time**, not the time of the request. Past
`stale_after_seconds` since then, show "location updating" rather than a rider
standing still in a street. That number is published here so the backend and
both apps cannot drift to different ideas of stale.

Only the order's own customer may read it. Anyone else gets a 404.

### When the position is filled

While the order is `rider_assigned` or `picked_up`, and not otherwise. After
delivery the rider is still named and callable — a customer with a problem
needs that — but `latitude`, `longitude` and `heading` go null and
`estimated_minutes` goes null with them. Where a rider goes next is their own
business.

### Which leg `route` draws

| Order status | Line drawn | Why |
|---|---|---|
| Before pickup | restaurant → customer | The rider is riding the *other* way, to the kitchen. Drawing their path would show a line moving away from the person watching it. |
| `picked_up` | rider → customer | Now they are the same journey. |

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

## Configuration

```env
TRACKING_ROUTE_ENABLED=true
TRACKING_ROUTE_DRIVER=google
TRACKING_ROUTE_TTL=45
TRACKING_ROUTE_MOVED=200
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
