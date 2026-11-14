# Push notifications

FCM HTTP v1. The legacy server-key API is retired and is not used anywhere.

## Who receives what

**This table is the thing to read before testing.** Every type below is real,
but each goes to one app — and testing a customer device against a
merchant-targeted type produces a silent, correct nothing that looks exactly
like a broken integration. That is not hypothetical; it cost a round trip.

| Type | Customer | Merchant | Rider | Fires when |
|---|:--:|:--:|:--:|---|
| `order.placed` | | ✅ | | Customer checks out |
| `order.accepted` | ✅ | | | Kitchen accepts, with a time |
| `order.rejected` | ✅ | | | Kitchen refuses; money is coming back |
| `order.ready` | ✅ | | | Food is ready for collection |
| `order.offer` | | | ✅ | A ready order needs a rider |
| `order.rider_assigned` | ✅ | ✅ | | A rider took the job |
| `order.rider_waiting` | | ✅ | | The rider is at the counter |
| `order.picked_up` | ✅ | | | Food left the restaurant |
| `order.rider_outside` | ✅ | | | The rider is at the door |
| `order.delivered` | ✅ | | | Handed over |
| `order.cancelled` | ✅ | | | Cancelled after acceptance |
| `ORDER_OFFER` | | | ✅ | A rider is being rung for an order (data only) |
| `OFFER_CANCELLED` | | | ✅ | That card is withdrawn (data only) |
| `rider.auto_offline` | | | ✅ | Three offers rung out; the rider is off duty |
| `order.no_rider` | | ✅ | | Every wave failed. The shop has to be told |
| `test` | ✅ | ✅ | ✅ | `nexmile:test-push` only. Never from a real event |

**This table is the full list of `data.type` values**, which is a question the
app side has had to ask. Nothing else is sent, and a type not in this table
arriving on a device is a bug rather than something to handle.

Everything here is a notification message except the two in capitals, which
are data-only and are the ringing card. The naming is inconsistent on purpose — the spec
these were built from named them that way, and quietly renaming a field in a
contract somebody has already coded against is worse than an ugly pair of
conventions.

**`order.offer` and `ORDER_OFFER` are different things.** The first is the
board — "a ready order needs a rider", sent when `DISPATCH_MODE=board`. The
second is the ringing card, sent when it is `push`. Only one mode runs at a
time, so a rider build should handle both and will see one.

**Every message carries `data.order_id`** except `rider.auto_offline`, which
is about the rider rather than an order, and `test`. So an app can open the
order screen from any of the rest without checking which type it was.

**The customer app receives eight of them.** `order.placed` is the "new
order in your kitchen" alert for a shop whose tablet is on a shelf, and
`order.offer` is the one that justifies the whole feature — an order going cold
until a rider's phone rings.

`order.rider_assigned` is the only type sent to two apps, as two separate
messages with different wording.

### Testing a customer device

Place an order, then **accept it from the merchant portal**. That fires
`order.accepted`. Testing with `order.placed` will keep showing nothing,
correctly.

## Payload

Every message carries `data.type` and `data.order_id`. **Both are strings** —
FCM rejects a data payload containing any other scalar, so `order_id` is cast
before sending. Deep links are built from it.

```json
{
  "notification": { "title": "...", "body": "..." },
  "data": { "type": "order.accepted", "order_id": "8" },
  "android": {
    "priority": "high",
    "notification": { "channel_id": "nexmile_orders" }
  },
  "apns": {
    "headers": { "apns-priority": "10" },
    "payload": { "aps": { "sound": "default" } }
  }
}
```

**The Android channel must match the one the app creates.** It comes from
`FCM_ANDROID_CHANNEL`, default `nexmile_orders`. A mismatch is silent: the
notification arrives with no sound and nothing reports an error.

**Sound differs by platform.** Android takes it from the channel, so the server
says nothing about it. iOS names the file on every message, from
`FCM_IOS_SOUND`. That stays `default` until an iOS build actually ships
bundling the asset — naming a file the app does not carry makes iOS play
nothing at all, which reads as a missing notification rather than a missing
sound.

## Data messages, for the ringing order card

A notification message is drawn by the operating system, which means it draws
a line of text in the tray. There is no notification shape that rings for
thirty seconds, counts down, and takes itself down when another rider gets
there first — and there is no notification shape that can be *withdrawn*.

So a rider offer is a **data-only message**: no `notification` key at all. FCM
decides by its presence, and including an empty one would have Android draw a
blank tray entry and never call the app.

```json
{
  "data": {
    "type": "ORDER_OFFER",
    "offer_id": "01JCQ2W8XK3N4P5Q6R7S8T9V0W",
    "order_id": "41",
    "expires_at": "2026-11-13T18:42:31+00:00",
    "seconds_left": "30",
    "restaurant": "Ponnusamy Hotel",
    "restaurant_latitude": "9.9195",
    "dropoff_area": "Madurai",
    "order_status": "accepted",
    "estimated_prep_minutes": "12",
    "earnings": "34.50",
    "collect_cash": "420.00"
  },
  "android": { "priority": "high", "direct_boot_ok": true, "ttl": "30s" },
  "apns": {
    "headers": { "apns-push-type": "background", "apns-priority": "5" },
    "payload": { "aps": { "content-available": 1 } }
  }
}
```

`PushSender::sendData()`, via `PushService::dataToUsers()` and the
`SendDataPush` job.

**Every value is a string.** FCM data payloads are string-to-string, and a
number anywhere in there makes FCM reject the whole message rather than the
one field.

**The field names are the same as `GET /v1/rider/offers/current`**, which
returns the same card flat, in real types, with a few extra fields that are
too long or too private for a 4 KB push. The app parses both with one parser,
so a name that differs between the two draws the card wrong down exactly one
path — the push path, which only runs on a real device and so fails where
nobody is watching. A test asserts every pushed field exists in
`RiderOfferResource`; `type` is the envelope and is excluded.

**`order_status` and `estimated_prep_minutes` are in there because the card
goes out before the food exists.** Dispatch starts when the merchant accepts,
so a rider who rides straight over waits at the counter. A card that can say
"ready in about 12 min" is the difference between an expected wait and a rider
who thinks the shop is messing them about.

**`priority: high` is what makes a ringing card possible.** A normal-priority
data message is held until the phone next wakes on its own, which on a locked
phone in a pocket can be minutes — long after the offer expired.

**`direct_boot_ok`** delivers to an app that has not been opened since the
phone was switched on. A rider who reboots at the start of a shift and does not
open the app would otherwise receive nothing at all.

**`ttl` is the offer window.** Past it, FCM drops the message rather than
holding it for a phone that is switched off. A card delivered after its window
is not late, it is wrong: it would ring for food another rider has collected.

**`SendDataPush` has `tries = 1`**, unlike `SendPushNotification`'s three. A
notification thirty seconds late is merely late. A ringing card thirty seconds
late rings for an order somebody else has. The dispatch waves *are* the retry.

### iOS will not ring for this

A background push is throttled by the system and may be held for minutes, so a
data message is the wrong tool for an offer on an iPhone. That needs a
notification with a critical alert entitlement, or the call-style interface
CallKit provides.

The rider app ships on Android. The `apns` block above exists to be correct
rather than to be used — `apns-push-type: background` with priority 5 is what
Apple requires for a payload with no alert, and priority 10 there is rejected
outright.

### What the app has to do with it

The data message **starts** the ring. It is not the source of truth about it:

```
GET /v1/rider/offers/current   →  { "data": null }  or the live card
```

Read on cold start and on resume from the background, because a data message
can be dropped by the system, arrive twice, or land on a phone that was
switched off. A rider staring at a card for an order somebody else collected
ten minutes ago is worse than a rider who saw no card at all.

Withdrawal is its own message:

```json
{ "type": "OFFER_CANCELLED", "order_id": "41", "reason": "taken" }
```

Dedupe on `offer_id`, and act on `OFFER_CANCELLED` for `order_id` whatever the
card on screen came from.
## One notification per order, not one per stage

Every message about an order carries a collapse key of `order-{id}` —
`android.notification.tag` and `apns-collapse-id`, two names for one idea.

So the stages of a delivery **replace** each other on the lock screen rather
than stacking: accepted becomes ready, becomes rider assigned, becomes
delivered. Each one still alerts — Android re-posts and re-buzzes when it
replaces — so nothing is missed. What is avoided is a customer who left their
phone alone finding five entries for one delivery with the current one buried
at the bottom.

Two different orders never collapse into each other, which is why it is keyed
on the order rather than on the person. A notification with no `order_id`
behind it — the test one — carries no key and stands on its own.

Both fields are **omitted** rather than sent as null when there is nothing to
collapse against: FCM rejects the whole message for a null header, so an
unrelated notification would fail outright rather than simply not collapsing.

### `preparing` deliberately does not notify

Nine stages exist; eight notify. A customer told "accepted, about 20 minutes"
does not need "now preparing" thirty seconds later, and a notification for
every status change is how people learn to swipe them all away — taking the
rider offer with them.

## The language it arrives in

Every notification is written in the recipient's own language, from
`users.preferred_locale` — not the sender's, and not the server default.

**A push cannot use `Accept-Language`, and an API response cannot use
`preferred_locale`.** They are two different mechanisms for one setting, and
the split catches people out:

| | Language comes from |
|---|---|
| API responses — `status_label`, error messages | the request's `Accept-Language` header |
| Notifications | the recipient's `users.preferred_locale` |

A notification is rendered inside a queue worker with no request to read a
header from, which is why it cannot use the header. So **an app offering a
language picker has to do both**: send the header, and `PATCH /v1/profile`
with `preferred_locale`. Doing only the first gives Tamil screens and English
notifications, and nothing anywhere reports a problem.

This is the one thing in here that is easy to get wrong without noticing. A
notification is rendered inside a queue worker, long after the request that
caused it, and a worker has no locale of its own beyond `APP_LOCALE`. So
rendering the copy at the call site means rendering it in English and posting
English to a rider who chose Tamil, with `lang/ta/push.php` sitting there
unused and nothing failing anywhere.

Callers therefore pass a **translation key**, never finished text:

```php
$push->toUser($order->customer, PushService::CUSTOMER,
    'push.customer.accepted',
    ['restaurant' => $name, 'minutes' => 20],
    ['type' => 'order.accepted', 'order_id' => (string) $order->id]);
```

The key holds `.title` and `.body`, and `PushService::deliver()` resolves both
per recipient. Devices are grouped by language first, so a rider board that
reaches eight riders in two languages is two renders, not eight.

A blank or missing locale falls back to `APP_LOCALE`. A key missing from `ta`
or `hi` falls back to English, which is why a test asserts all three files hold
the same keys — a silent fallback is indistinguishable from working.

**If you add a notification, add the key to all three language files.** The
Tamil and Hindi copy is machine-assisted and still needs a native speaker's
read before launch; the file headers say so.

## Registering a device

```
POST   /v1/devices   { "token": "...", "platform": "android|ios", "app": "customer|rider" }
DELETE /v1/devices   { "token": "..." }
```

Call `POST` after sign-in and again whenever FCM rotates the token — reinstall,
restore to a new phone, and occasionally for no reason. Registering the same
token twice is safe.

`DELETE` on sign-out is not optional. Without it the phone keeps buzzing for a
shift somebody else is working, and that person has no way to stop it.

It only forgets a token belonging to the **calling account**. On sign-out that
is whoever registered it, so nothing legitimate is lost — and without the
scope any signed-in account could silence another person's phone with a token
it happened to learn.

`app` matters. One person may hold both apps — a rider ordering their own
dinner is a customer — and the two installs must not receive each other's
alerts.

## Sending is always queued

FCM v1 has no multicast; the batch endpoint went with the legacy API. One HTTP
request per device, so a rider fan-out is dozens of calls and has no business
happening inside a web request.

**This means a queue worker is required.** With none running, notifications
queue silently and never send — and `queue:failed` stays empty, because nothing
failed, nothing was attempted. That is the first thing to check when push
"stops working".

```bash
systemctl status nexmile-worker
php artisan queue:monitor default
```

`deploy.sh` restarts `nexmile-worker` after a successful health check, so the
worker never runs code from a release that is about to be rolled back.

## Diagnosing

```bash
php artisan nexmile:test-push <user-id|phone|email> --app=customer --now
```

Reports exactly what FCM returned. `--now` sends inline, bypassing the queue —
if that works and the queued path does not, the worker is the problem.

### Run it as the PHP-FPM user

The service account key is chmod 600 and owned by the PHP-FPM user, so
**`--now` as `ubuntu` cannot read it** and fails with "Permission denied".
That is the file permissions working, not push being broken: the queue worker
runs as the owner and sends perfectly well.

```bash
sudo -u www-data php artisan nexmile:test-push <user> --app=rider --now
```

**A bare `--now` that succeeds is not proof the key is readable.** The OAuth
access token is cached for fifty minutes, so once the worker has minted one,
any user who can reach the cache sends happily without ever opening the file —
and the same command then fails an hour later with nothing having changed. The
command now says so when it happens, rather than printing a cheerful "Sent."

This bit once already: `is_file()` was standing in for "readable" in the
preflight, so the command reported a readable key and failed on the next line.
A diagnostic that lies is worse than no diagnostic, because it sends the person
reading it somewhere else entirely.

The three failures worth recognising:

**`UNREGISTERED`** / **`INVALID_ARGUMENT`** on the token — the install is gone.
Handled automatically: the row is deleted rather than retried.

**`SENDER_ID_MISMATCH`** — the service account belongs to a different Firebase
project than the one that issued the token. Check `FCM_PROJECT_ID` against the
app's `google-services.json`.

**`THIRD_PARTY_AUTH_ERROR`** — usually the APNs key missing or wrong, on iOS.

Anything else is logged and the token is left alone. A transient FCM error must
never delete a working device.

## Configuration

| Key | Notes |
|---|---|
| `PUSH_DRIVER` | `fcm` to send. Empty logs what would have been sent |
| `FCM_PROJECT_ID` | Must match the app's `google-services.json` |
| `FCM_CREDENTIALS` | Absolute path to the service-account JSON, **outside the repo**, readable by the PHP-FPM user |
| `FCM_ANDROID_CHANNEL` | Must match the channel the app creates |
| `FCM_IOS_SOUND` | `default` until an iOS build bundles the asset |

The credentials file holds a private key that can notify every install of both
apps. It lives at `/etc/nexmile/firebase.json`, chmod 600, owned by the PHP-FPM
user, and is never committed.
