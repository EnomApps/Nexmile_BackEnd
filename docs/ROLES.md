# Roles — and what happens when a rider wants to order food

## How roles work today

`users.role` is a **single** value: customer, rider, merchant or admin. Both
`users.phone` and `users.email` are **unique**.

`intended_role` on the OTP request only applies when the account is created.
An existing user signing in keeps the role they already have — see
`OtpService::resolveUser()`. So a rider who signs into the customer app with
their own mobile number gets their **rider** account back, not a new customer
one, and they cannot register a second account on the same number.

## The decision: ordering is not a role

**Placing an order requires an authenticated, active account — not the
customer role.**

A rider eats. A merchant orders from the shop across the road. A support agent
places a test order. Treating "customer" as a permission to be withheld means
inventing second accounts for people who already have one, and there is no
second phone number to register it with.

So `role` describes what a user can do **in addition** to ordering:

| Role | Can order | Also can |
|---|---|---|
| customer | yes | — |
| rider | yes | go on duty, accept deliveries |
| merchant | yes | run a storefront, manage a menu |
| admin | yes | verify KYC, suspend accounts |

Concretely, EP5 checkout must be gated on `auth:sanctum` plus an active
account, **not** on `role:customer`. The address book already works this way.

The alternative — a `role_user` pivot so one person holds several roles — is
more machinery than this buys. One primary role plus "everyone can order"
covers every case we actually have.

## Two guards this creates

### A rider must never be dispatched their own order

The obvious fraud: order food, get assigned to deliver it, mark it delivered,
keep it. The order is paid for, so this is not theft of food so much as theft
of the delivery fee — repeatedly, and at will.

Dispatch must exclude the ordering user from the candidate riders:

```php
// EP8 — when this ships, this line is not optional.
$candidates->reject(fn ($rider) => $rider->user_id === $order->user_id);
```

Cheap to write now, expensive to notice later — it looks like a rider who is
simply very fast.

### A merchant ordering from their own storefront

Less serious, but it distorts commission and payout: the merchant pays a
commission to themselves and the order counts toward their own sales figures.
Block it at checkout rather than untangling it in reporting.

## What the app developer needs to know

**Both apps share one login and one account.** The response to OTP verification
carries `role` and `status`. Each app must read them and route accordingly:

- The **customer app** should let any role through to the home screen. A rider
  signing in to order dinner is a normal user with a rider badge.
- The **rider app** must check `role === 'rider'` and refuse anything else with
  "This number is not registered as a delivery partner" — otherwise a customer
  who downloads the rider app gets an empty duty screen and no explanation.
- The rider app must also gate the duty toggle on `can_go_online`, which stays
  false until KYC is verified and both expiry dates are current. Not
  `can_accept_orders` — that also requires being on duty, so it is false for
  every offline rider and gating the toggle on it locks them out for good.

A rider's `status` is `pending` until their documents are approved. **That must
not block them from ordering** — it means "cannot work yet", not "cannot use
Nexmile". Only `suspended` blocks everything.
