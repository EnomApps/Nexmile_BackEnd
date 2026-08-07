# Google Maps Platform

## You probably need fewer Google calls than you think

The **1 km radius is not a Google feature here**, and it is already built.

**Riders** run on Redis GEO (`GEOADD` / `GEORADIUS`) in `RiderLocationService`
— their position changes every few seconds, which is what that structure is
for.

**Restaurants and zones** run on SQL plus haversine in `NearbyMerchantService`
— a bounding box on the indexed `(latitude, longitude)` columns, refined in
PHP. A merchant's address changes roughly never, so mirroring static rows into
Redis would buy nothing and add a synchronisation problem.

Every Google call is billed, so anything these two can answer should not go to
Google.

What Google is actually for:

| Need | Provider | Where |
|---|---|---|
| "Riders within 1 km of this shop" | **Redis GEO** | backend, free |
| "Restaurants within 1 km of this address" | **SQL + haversine** | backend, free |
| "Is this address inside our zone?" | **SQL + haversine** | backend, free |
| Address typed by a customer → lat/lng | Geocoding API | backend, billed |
| Road distance and ETA (not crow-flies) | Distance Matrix / Routes | backend, billed |
| The map the customer looks at | Maps SDK | Flutter apps, billed |
| Address autocomplete while typing | Places API | Flutter apps, billed |

Straight-line distance between two known points needs no API at all — it is
arithmetic, and over 1 km the error against road distance is small enough that
paying per call to improve it is rarely worth it.

## Use separate keys

**One key per consumer**, because a key can only be restricted as tightly as
its loosest user.

| Key | Restriction | APIs enabled |
|---|---|---|
| Server | **IP address** → the EC2 elastic IP | Geocoding, Distance Matrix |
| Android app | **Package name + SHA-1** | Maps SDK for Android, Places |
| iOS app | **Bundle ID** | Maps SDK for iOS, Places |

A key shipped inside an installed app is readable by anyone who wants it —
`strings` on the APK will find it. That is not a flaw to hide, it is why
platform restrictions exist. **The app keys are defended by restriction, never
by secrecy.** The server key is the one that must stay private, and it is also
the one an unrestricted leak would let a stranger bill to your account.

Set a **budget alert and a daily quota cap** on the project regardless. An
unrestricted key found by a scraper can run up thousands in a day; a quota cap
turns that into a broken feature instead of an invoice.

## Configuration

Server key only, and only in `.env` — never committed:

```env
GOOGLE_MAPS_SERVER_KEY=...
```

Read through `config('services.google_maps.key')`. The app keys live in the
Flutter repo (`AndroidManifest.xml` and `AppDelegate.swift`), not here.

## If a key is exposed

Rotate it. In Google Cloud Console → APIs & Services → Credentials, create a
replacement, restrict it, deploy it, then delete the old one. Restriction
limits the damage but does not undo the disclosure, and rotating costs two
minutes.
