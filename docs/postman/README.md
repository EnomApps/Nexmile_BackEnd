# Postman collections

One collection per app, so nothing you import is noise.

| File | For | Requests |
|---|---|---|
| `Nexmile-Customer.postman_collection.json` | the customer app | 35 |
| `Nexmile-Rider.postman_collection.json` | the rider app | 21 |
| `Nexmile-Merchant.postman_collection.json` | merchant API / portal testing | 39 |

Every request is checked against the live route table — if it is in a
collection, it exists on the server.

## Setup

Import the one you need. Each carries its own variables, so **do not share an
environment between them** — three separate `access_token`s is the point.

`base_url` is preset to `https://api.nexmile.in/api/v1`. Change it once, at the
collection level, to point somewhere else.

## Running a flow

Each collection runs top to bottom without editing anything. Requests capture
what the next one needs:

- **Customer** — sign in → `access_token`; addresses → `address_id`;
  restaurants → `restaurant_id`; menu → `menu_item_id`; add to cart →
  `cart_item_id`; checkout → `order_id`
- **Rider** — sign in → `access_token`; the order board → `order_id`
- **Merchant** — sign in → `access_token`; categories → `category_id`;
  items → `menu_item_id`; option groups → `option_group_id` and `option_id`;
  orders → `order_id`

Two things you have to fill in by hand: **`otp_code`**, from the email that
arrives, and **`pickup_code`** on the rider collection, which the merchant
reads off their screen when the food is ready.

## Testing the whole loop

The three collections meet in the middle. To exercise a real delivery:

1. **Customer** — sign in, save an address near a test restaurant, add to cart,
   check out
2. **Merchant** — accept the order, mark it ready
3. **Rider** — go on duty, send one location ping, take it off the board, enter
   the pickup code, deliver

A rider can place customer orders with the same login, but **will never be
offered their own order** — that is a deliberate anti-fraud guard, so use a
separate customer account for step 1.

## Auth differs by role

| | How they sign in |
|---|---|
| Customer | OTP, `intended_role: customer` |
| Rider | OTP, `intended_role: rider` |
| Merchant | **email/phone + password** |

There is no separate registration endpoint for customers or riders — the
account is created on first successful verification. Merchants register on the
website at `nexmile.in/merchants/register`.

## Two things that catch people out

**Money is a JSON number and loses its zero fraction.** ₹430.00 arrives as
`430`, ₹92.50 as `92.5`. Read it as `num`, never `double` — `as double` throws
on the round number and works on the decimal, so it passes every test until a
customer orders something ending in .00.

**Serialise token refreshes behind one lock.** Two concurrent refreshes with
the same token are treated as a stolen token and sign the user out everywhere.
That is deliberate reuse detection, but a screen firing parallel requests on
load will trip it.

## Regenerating

These are generated from `build-collections.php` in this folder, so the three
cannot drift apart in conventions. Edit the spec and run it from the project
root:

```bash
php docs/postman/build-collections.php
```

## Full walkthroughs

- `docs/CUSTOMER_APP_FLOW.md`
- `docs/RIDER_APP_FLOW.md`
- `docs/MENU_AND_ORDERS.md` — merchant behaviour and rules
- Interactive reference: **https://api.nexmile.in/docs/api**
