# Mobile OTP auth (EP2)

Phone-number login for every role, with short-lived access tokens and
long-lived refresh tokens.

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
| POST | `/auth/otp/request` | — | Send a code to a mobile number |
| POST | `/auth/otp/verify` | — | Exchange the code for tokens |
| POST | `/auth/refresh` | — | Rotate the token pair |
| GET | `/auth/me` | Bearer | Current user |
| POST | `/auth/logout` | Bearer | Sign out this device |
| POST | `/auth/logout-all` | Bearer | Sign out everywhere |
| GET | `/auth/sessions` | Bearer | List signed-in devices |
| DELETE | `/auth/sessions/{id}` | Bearer | Sign out one device |

### Request a code

```http
POST /auth/otp/request
{ "phone": "9876543210", "intended_role": "customer" }
```

`intended_role` accepts `customer` or `rider` only, and applies solely when the
number has never signed in before. Merchant and admin accounts cannot be
created this way.

```json
{
  "message": "A verification code has been sent to your mobile number.",
  "data": { "phone": "9876543210", "expires_in": 300, "resend_after": 60 }
}
```

### Verify

```http
POST /auth/otp/verify
{ "phone": "9876543210", "code": "123456", "device_name": "pixel-8" }
```

```json
{
  "data": {
    "user": { "id": 1, "phone": "9876543210", "role": "customer", "status": "active" },
    "access_token": "1|xxxxx",
    "refresh_token": "64-hex-characters",
    "token_type": "Bearer",
    "expires_in": 3600
  }
}
```

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
| Codes per number per hour | 5 | `otp.max_per_hour` |
| Requests per IP per hour | 20 | controller |
| Access token life | 60 min | `ACCESS_TOKEN_TTL_MINUTES` |
| Refresh token life | 30 days | `REFRESH_TOKEN_TTL_DAYS` |

## Security decisions

**Codes are stored hashed.** A leaked database dump must not hand an attacker
working login codes for every pending sign-in.

**Requesting a new code burns the old one.** Two live codes for one number
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

## Sending SMS

No gateway is wired up yet. `SMS_DRIVER=log` writes the message to
`storage/logs/laravel.log`, so the whole flow works end to end today:

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
