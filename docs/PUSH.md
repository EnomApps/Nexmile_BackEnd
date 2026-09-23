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
| `order.picked_up` | ✅ | | | Food left the restaurant |
| `order.delivered` | ✅ | | | Handed over |
| `order.cancelled` | ✅ | | | Cancelled after acceptance |

**The customer app receives seven of the nine.** `order.placed` is the "new
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
