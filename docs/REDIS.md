# Redis — live state and dispatch

Redis holds operational state that changes faster than MySQL should be asked to
absorb: rider GPS pings, in-flight order state, countdown timers and dispatch
queues.

**MySQL stays the source of truth.** Everything here is rebuildable from it, so
losing Redis costs latency, not data.

## Databases

Each concern gets its own database on the same server.

| DB | Connection | Holds |
|---:|---|---|
| 0 | `default` | general use |
| 1 | `cache` | application cache |
| 2 | `live` | rider positions, order state, timers |
| 3 | `dispatch` | dispatch queues, offer windows, locks |

They are separated for a specific reason: `php artisan cache:clear` issues a
`FLUSHDB` against the cache database. If live state shared that database,
clearing a cache mid-service would drop every rider's position and strand
in-flight orders.

Test runs use databases 11–13 (see `phpunit.xml`) so they cannot touch
development state.

## Keyspace

All keys are built in `app/Support/RedisKeys.php` — add new ones there rather
than inline, so the keyspace stays reviewable in one place.

Laravel prefixes every key with `REDIS_PREFIX` (default `nexmile-database-`).

| Key | Type | TTL | Purpose |
|---|---|---|---|
| `zone:{zone}:riders:geo` | geo set | — | rider positions in a zone |
| `rider:{rider}:state` | hash | — | duty status, last position, heartbeat time |
| `rider:{rider}:heartbeat` | string | 60s | presence; absence means the app went silent |
| `order:{order}:state` | hash | 6h | status, merchant, rider, ETA |
| `orders:active` | set | — | in-flight order ids |
| `timer:{type}:{order}` | string | varies | prep / arrival / offer countdown |
| `dispatch:zone:{zone}:queue` | list | — | orders waiting for a rider |
| `dispatch:offer:{order}` | string | 20s | rider currently holding the offer |
| `dispatch:lock:{order}` | string | 30s | stops two workers assigning the same order |

Timers store the deadline as the value and the countdown as the TTL, so
"how long is left" is a `TTL` lookup and expiry needs no sweeper job.

Rider presence is a separate key because Redis cannot expire an individual geo
set member. Positions linger; `ridersNear()` cross-checks the heartbeat and
skips riders who have stopped reporting.

## Services

| Class | Responsibility |
|---|---|
| `RiderLocationService` | GPS pings, presence, proximity search |
| `OrderStateService` | live order state, active-order set |
| `DeliveryTimerService` | prep, arrival and offer countdowns |
| `DispatchQueueService` | queues, offer windows, worker locks |

This ticket provides transport only. Matching and ranking logic lives in the
Python dispatch service (EP7), which reads and writes the same keys on
database 3.

## Health check

```bash
php artisan redis:health
```

Pings all four connections, then exercises a geo search, order state, a timer,
the queue and offer exclusivity, cleaning up after itself. Run it after any
deploy that touches Redis configuration.

## Client caveat

`predis` and `phpredis` accept **different option formats** for some commands.
`GEORADIUS` wants `['WITHDIST' => true]` under predis and `['WITHDIST']` under
phpredis, and the wrong shape is dropped silently — you get members back with
no distances rather than an error.

Where it matters, commands are sent with `executeRaw()`, which is identical on
both clients. Note that `executeRaw()` **bypasses Laravel's key prefixing**, so
raw commands must apply the prefix themselves.

## Local development on Windows

There is no native Redis for Windows and no `phpredis` build in XAMPP. Either:

- **WSL** — `wsl --install`, then `sudo apt install redis-server`
- **Memurai** — a Redis-compatible Windows service
- **Redis for Windows** — the community 5.x build at
  [tporadowski/redis](https://github.com/tporadowski/redis/releases), run with
  `redis-server.exe --port 6379`

Then set `REDIS_CLIENT=predis` locally. The server uses `phpredis`, which is
faster but needs the PHP extension.

Redis 5 lacks `GEOSEARCH` (6.2+), which is why `GEORADIUS` is used — it behaves
the same on 5, 6 and 7. Worth revisiting once every environment runs 7.x.

## Server

Already installed with the rest of the stack:

```bash
sudo systemctl status redis-server
redis-cli ping          # PONG
```

Redis listens on `127.0.0.1` only. **Do not expose 6379 publicly** — an open
Redis with no password is trivially compromised.

For a single instance the default `redis.conf` is fine. Set a `maxmemory` limit
with `maxmemory-policy noeviction` before launch, so that if memory does fill,
writes fail loudly instead of Redis silently evicting live rider positions.
