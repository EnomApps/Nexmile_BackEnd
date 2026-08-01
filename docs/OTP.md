# OTP auth (EP2)

Passwordless login for every role, with short-lived access tokens and
long-lived refresh tokens.

**The channel follows the identifier.** Send an `email` and the code is emailed;
send a `phone` and it is texted. Nothing else changes, so moving the apps from
email login to SMS login later needs no backend work and no config flag — the
client simply starts sending a phone number.

Email is the channel in use now, because SMS in India requires DLT registration
(entity, sender ID and template approval) before a single message is delivered.

Base URL: `https://api.nexmile.in/api/v1`
Always send `Accept: application/json`.

## Why Sanctum and not JWT

The ticket says JWT; this is built on Sanctum tokens. Sanctum was chosen for
the project at the outset and the merchant API already runs on it — adding a
second auth system alongside it would mean two token formats, two revocation
paths and two sets of bugs.

The capability the ticket asks for is delivered in full: a short-lived access
token, a refresh token, rotation, and per-device session control. The
difference is that revocation is immediate (the token is deleted server-side)
rather than waiting for a JWT to expire, which for a delivery app handling
payments is the safer behaviour.

## Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/auth/otp/request` | — | Send a code by email or SMS |
| POST | `/auth/otp/verify` | — | Exchange the code for tokens |
| POST | `/auth/refresh` | — | Rotate the token pair |
| GET | `/auth/me` | Bearer | Current user |
| POST | `/auth/logout` | Bearer | Sign out this device |
| POST | `/auth/logout-all` | Bearer | Sign out everywhere |
| GET | `/auth/sessions` | Bearer | List signed-in devices |
| DELETE | `/auth/sessions/{id}` | Bearer | Sign out one device |

### Request a code

Send **either** `email` **or** `phone` — never both. Sending both is rejected
with a 422, because there would be no way to tell which channel you meant.

```http
POST /auth/otp/request
{ "email": "karthik@example.in", "intended_role": "customer" }
```

```http
POST /auth/otp/request
{ "phone": "9876543210", "intended_role": "customer" }
```

`intended_role` accepts `customer` or `rider` only, and applies solely when the
identifier has never signed in before. Merchant and admin accounts cannot be
created this way.

```json
{
  "message": "A verification code has been sent to your email address.",
  "data": {
    "identifier": "karthik@example.in",
    "channel": "email",
    "expires_in": 300,
    "resend_after": 60
  }
}
```

`identifier` is the normalised value — email addresses come back lowercased.
Use it as-is when verifying.

### Verify

Send the same identifier used to request the code.

```http
POST /auth/otp/verify
{ "email": "karthik@example.in", "code": "123456", "device_name": "pixel-8" }
```

```json
{
  "data": {
    "user": {
      "id": 1,
      "email": "karthik@example.in",
      "phone": null,
      "role": "customer",
      "status": "active"
    },
    "access_token": "1|xxxxx",
    "refresh_token": "64-hex-characters",
    "token_type": "Bearer",
    "expires_in": 3600
  }
}
```

An account created by email has a **null phone** until SMS login is enabled,
and vice versa.

Store the refresh token in secure storage — Keychain on iOS, EncryptedSharedPreferences
on Android. It is as sensitive as a password.

### Refresh

```http
POST /auth/refresh
{ "refresh_token": "..." }
```

Returns a **new pair**. The old access token stops working immediately, and the
old refresh token cannot be used again.

Call this when a request returns 401, then retry the original request once.

## Rules

| Setting | Value | Config |
|---|---|---|
| Code length | 6 digits | `otp.length` |
| Code lifetime | 5 minutes | `otp.ttl_seconds` |
| Wrong attempts allowed | 5 | `otp.max_attempts` |
| Resend cooldown | 60s | `otp.resend_cooldown_seconds` |
| Codes per identifier per hour | 5 | `otp.max_per_hour` |
| Requests per IP per hour | 20 | controller |
| Access token life | 60 min | `ACCESS_TOKEN_TTL_MINUTES` |
| Refresh token life | 30 days | `REFRESH_TOKEN_TTL_DAYS` |

## Security decisions

**Codes are stored hashed.** A leaked database dump must not hand an attacker
working login codes for every pending sign-in.

**Requesting a new code burns the old one.** Two live codes for one identifier
would double the guessing surface.

**The attempt budget burns the code, not just the request.** Once five wrong
guesses are used the code is consumed, so the correct code stops working too.
Without that, the limit is only a speed bump.

**Refresh tokens rotate, and reuse is treated as theft.** Using a refresh token
issues a new one and marks the old as replaced. If a replaced token is ever
presented again, either it was stolen or it is being replayed — there is no way
to tell which, so every session for that user is revoked and the user signs in
again. This is the standard defence against refresh-token theft.

**Codes are generated with `random_int`.** `rand()` and `mt_rand()` are
predictable from previous outputs and must never be used for anything a user
authenticates with.

**A suspended account is rejected after the code is verified**, not before, so
the response cannot be used to discover which numbers are suspended.

## Sending email

Email is the live channel. It needs a working mail transport — until one is
configured, `MAIL_MAILER=log` writes the whole message to
`storage/logs/laravel.log` and nothing is actually delivered:

```bash
grep -A2 "Subject: .* is your Nexmile" storage/logs/laravel.log | tail -5
```

For real delivery, set SMTP credentials for a mailbox on your own domain:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=no-reply@nexmile.in
MAIL_PASSWORD=...
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS="no-reply@nexmile.in"
MAIL_FROM_NAME="Nexmile"
```

Add **SPF and DKIM** records for nexmile.in before going live. Login codes sent
from a domain with no mail authentication land in spam, and a user who never
sees the code simply cannot sign in.

Amazon SES is the natural fit given the stack is already on AWS, but a new SES
account starts in sandbox mode and can only send to verified addresses — the
move to production access takes a support request, so start it early.

## Sending SMS

No gateway is wired up yet, and Indian SMS needs DLT registration first.
`SMS_DRIVER=log` writes the message to `storage/logs/laravel.log`, so the flow
works end to end today:

```bash
grep "is your Nexmile" storage/logs/laravel.log | tail -1
```

For app development without reading server logs, set a fixed code:

```env
OTP_FIXED_CODE=123456
```

**This only works when `APP_ENV` is `local` or `testing`.** The check is in
`OtpService::generateCode()` and is deliberately not configurable — a fixed
code in production would be a master key to every account.

### Adding a real provider

1. Implement `App\Contracts\SmsSender` — one `send(string $phone, string $message)` method
2. Register it in `AppServiceProvider::register()`
3. Set `SMS_DRIVER` and the provider credentials in `.env`

Nothing else changes. MSG91 and Fast2SMS are the usual choices for Indian
numbers; Twilio costs noticeably more per message here.

Send OTPs over a **transactional** route, not promotional — promotional SMS is
blocked for numbers registered on DND, which would silently lock those users
out of the app.

## Notes for the Flutter developer

- Keep the access token in memory, the refresh token in secure storage
- On a 401, refresh once and retry; if the refresh also fails, send the user to login
- Do not refresh on a timer — refresh in response to a 401
- Two calls refreshing at once will trip the reuse detection and sign the user
  out everywhere. Serialise refreshes behind a single lock
- `device_name` shows in the user's session list; send something recognisable
  such as the device model
