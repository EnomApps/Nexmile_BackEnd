# Door photos, receivers and bestsellers

Private. Not served by the docs allowlist.

Four requests from the customer app's new address flow, three of which needed
backend work and one of which was already done. The one with a real design
decision in it is the second.

## 1. A photo of the door

```
POST   /api/v1/addresses/{address}/photo    multipart, one file in `photo`
DELETE /api/v1/addresses/{address}/photo
```

Answers `200` with the address in the same shape as `GET /addresses/{id}`,
`photo_url` filled in. `422` under `errors.photo` for a bad file, `404` for an
address that is not the caller's — 404 rather than 403, because a 403 confirms
the address exists.

`photo_url` is on **every** address response: list, show, create, update. Null
when there is no photo, which the app reads as "offer to add one".

### Why it exists

A Tier-2 address is frequently a plot number painted on a wall that is not the
gate, three doors off one staircase, and no flat numbers anywhere. "Green door,
second floor, past the water tank" is a sentence nobody types into an address
form and a photo says at a glance.

### The link is short-lived on purpose

`media.door_url_ttl_minutes`, default **180**, against 24 hours for a dish
photo. The same response carries the coordinates, so photo plus latitude and
longitude is the answer to "where does this person live". Three hours outlives a
slow delivery and an app backgrounded at the gate; it does not outlive a URL
pasted into a log.

The upload goes to the private media disk with a UUID filename — a
user-supplied name can carry path separators and a second extension — under
`door-photos/{user_id}/`, so a stray object is traceable to a person.

### Replacing a photo does not blank a delivery in progress

Orders snapshot the address rather than referencing it, and that now includes
`orders.delivery_photo_path`. So the obvious implementation — `ImageService::attach()`,
which deletes the replaced object — would have emptied the door photo on every
past order and, worse, on one in flight: a customer tidying up their address
book at 8pm blanks the photo the rider at their gate is looking at.

`AddressController::forgetPhoto()` therefore keeps the old object while any
order still points at it. There is a test for exactly this.

Nothing prunes the kept objects yet. They are reachable only through a signed
link off an order nobody but that order's rider can see, and when an address the
customer has already deleted stops being ours to hold is a question for the
order retention policy, not for this endpoint.

## 2. What the rider is given, and when

The app side asked for `contact_name`, `contact_phone`, `photo_url`, `line1` and
`landmark` **"in the order offer and the active order"**.

**The active order: yes.** `RiderOrderResource.dropoff` now carries the receiver,
the door photo, and `line1`, `line2` and `landmark` as separate fields as well
as inside the composed `address` line. Three rather than the two asked for: in
the customer app `line1` is typed by hand (flat, floor, house number), `line2`
is the street and `landmark` the locality, so the street — the part a rider
actually navigates by — would have been the one left out.

**The ringing offer: no.** An offer goes to up to fifteen riders a wave and most
of them decline. Putting the customer's name, mobile number, flat and a
photograph of their front door on that card hands all of it to every rider who
said no. `offerPayload()` deliberately sends `dropoff_area` and
`delivery_distance_metres` — enough to judge whether you want the job, not
enough to know where anybody lives — and `RiderOrderResource` gates `dropoff`
on `rider_id !== null` for the same reason, so the pollable board does not
double as a list of customer addresses.

Nothing is lost by refusing. `POST /offers/{offer}/accept` already answers with
the full `RiderOrderResource`, loaded to match `GET /rider/orders/{order}`
exactly, specifically so the app can put the response straight onto the delivery
screen. The receiver and the door arrive the instant the rider takes the job —
which is the first moment they are any of their business.

Two tests hold this line: one on the offer payload, one on the available board.

### Receiver, not account holder

`delivery_contact_name` and `delivery_contact_phone` are whoever is taking the
delivery, which is not always who paid. A customer ordering for their parents
puts their parents' number here, and a rider who rings the account holder
instead is calling a different city.

## 3. Receiver fields — already done, confirmed

`contact_name` and `contact_phone` were on `addresses` from the first migration,
accepted by `POST /addresses` and `PATCH /addresses/{id}`, snapshotted onto the
order at checkout and returned by `AddressResource`.

`AddressRequest` validates the phone as `regex:/^[6-9]\d{9}$/` and fails with
`422` under `errors.contact_phone`, message "Enter a valid 10-digit Indian
mobile number." Nothing to change.

## 4. Bestsellers

`is_bestseller`, true or false, on every dish in `GET /restaurants/{id}/menu`.

A stored column recomputed by `nexmile:mark-bestsellers`, scheduled at 04:10.
Working it out per request would mean a `GROUP BY` over `order_items` on every
menu open for an answer that changes once a day.

```
nexmile:mark-bestsellers [--merchant=] [--dry-run]
```

Config in `config/menu.php`:

| Key | Default | |
| --- | --- | --- |
| `per_merchant` | 5 | How many dishes can hold the badge |
| `window_days` | 30 | How far back to count |
| `min_orders` | 5 | Units sold before a dish qualifies at all |

**Quantity, not order count.** Two plates of idli on one bill is two plates
sold, and a dish ordered in pairs is exactly what the badge should find.

**Delivered orders only.** A rejected order is evidence of demand, not of a dish
worth recommending to somebody who will then be told no — counting rejections
would hand the badge to whatever the kitchen keeps running out of. Abandoned
checkouts never reached the kitchen at all.

**`min_orders` is the part that matters.** On a menu with four days of history
the top three dishes are whatever three people happened to order. Below the
threshold a restaurant simply has no bestsellers, and a badge that is sometimes
absent is worth more than one that is always there.

The flag is deliberately **not** in `MenuItem::$fillable`. A badge the seller
awards itself is an advertisement; this one is earned from delivered orders or
not at all. The command writes it through the query builder.

## What the app does without us

Noted so nobody builds it twice: address search is Google Places (New) called
from the app with the app's own keys; "use current location" uses the existing
`GET /restaurants?latitude=&longitude=` and checkout still needs a saved
`address_id`; and menu search, the veg/egg/non-veg filters, rating, offers and
sorting all run in the app on the menu response we already send.

## Tests

- `tests/Feature/DoorPhotoTest.php` — 14 tests. Upload, replace, remove, bad
  file, another customer's address, the order's own copy, replacement during a
  live delivery, what the assigned rider sees, and the two that keep the door
  off the offer card and off the available board.
- `tests/Feature/BestsellerTest.php` — 12 tests. Mostly about when the badge
  should *not* appear.

Both extend `CartTest`, so each file also re-runs the cart suite.

## Still open

The app uploads the photo immediately after the address saves, and treats a
failed upload as a saved address without a photo. That is the right shape, but
it means a customer on a bad connection in a basement has an address and no
photo and no prompt to retry later. Worth a nudge in the app rather than
anything here.

Riders have not been told the photo exists. It is theirs to see only while
assigned, but it belongs in the same DPDP notice as the location trail — see
`docs/TRACKING.md`.
