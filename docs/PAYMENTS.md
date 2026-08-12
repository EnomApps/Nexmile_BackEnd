# Online payment (EP6, EP7)

Razorpay, which is an **aggregator**: one integration covers UPI (GPay,
PhonePe, Paytm), cards, netbanking and wallets. Integrating those separately
would mean several merchant accounts and several settlement cycles for the same
money.

Cash on delivery stays alongside it — it is how most of this market pays, and
it is the only method that still works when a customer's bank is having a bad
morning.

## Two rules

**An unpaid order never reaches a kitchen.** Online orders are created at
`pending_payment`, which the merchant queue already filters out, and only move
to `placed` once the provider confirms the money. `placed_at` is stamped then,
not at checkout.

**The client is never the authority.** An app reporting success is a hint. A
signature is evidence the *message* is authentic. The provider's own record is
evidence the *money moved* — and both the client path and the webhook converge
on one method so they cannot disagree.

## The flow

```
checkout (payment_method: upi)  →  order at pending_payment
POST /v1/orders/{id}/payment    →  gateway_order_id, amount_paise, key
        app opens the Razorpay SDK
POST /v1/orders/{id}/payment/confirm  →  signature checked, order placed
        …and independently…
POST /api/webhooks/razorpay     →  the same thing, authoritatively
```

The confirm call exists so the customer's screen reacts immediately. **It is
not required.** If the app dies on the bank's page, the webhook still completes
the order — which is exactly the case a client callback cannot cover.

## Why the webhook is the authority

It arrives whether or not the phone survived the redirect, and it retries until
we answer. That makes every write in the path idempotent: a webhook landing
after the client already confirmed finds the work done and changes nothing.

It is unauthenticated by necessity — Razorpay has no token to present — so
**the signature is the only thing between that URL and a stranger pushing
unpaid orders into kitchens**. It is checked over the *raw body*, because
re-encoding a decoded payload changes bytes and breaks the comparison for
reasons nobody enjoys debugging at midnight.

A verified webhook always returns 200, even for events we ignore. Anything else
makes Razorpay retry, and a bug in our handling would become a storm of
redeliveries.

## Refunds

A paid order that is cancelled or rejected — by the customer, the merchant or
an admin — refunds automatically. COD orders fall straight through; nothing was
taken.

Refunds are keyed on the payment, so a retried job or a second cancellation
cannot send the money twice.

If the gateway refuses, the refund is recorded as `failed` and **the
cancellation still stands**. The order is already cancelled and the customer
already told; failing here would roll that back and leave them with neither
their food nor an explanation. A human settles the money.

## Abandoned payments

A customer who closes the app mid-payment leaves an order nobody will pay for.
It never reaches a kitchen, so it is invisible — but if it took the last Food
Rescue portion, nobody else can buy that portion either.

```bash
php artisan nexmile:expire-unpaid
```

Runs every five minutes on the scheduler. Cancels orders unpaid for longer than
`abandon_after_minutes` (20) and **releases their rescue portions** — the one
cancellation where giving stock back is unambiguously right, because the
kitchen never saw the order.

This needs the scheduler running:

```
* * * * * cd /var/www/nexmile && php artisan schedule:run >> /dev/null 2>&1
```

Without that line, unpaid orders accumulate and rescue stock stays held.

## Setting it up

1. Razorpay dashboard → **Settings → API Keys** → generate. Test keys first.
2. **Settings → Webhooks** → add `https://api.nexmile.in/api/webhooks/razorpay`,
   subscribe to `payment.captured` and `payment.failed`, and set a secret.
3. On the server:

```env
PAYMENT_GATEWAY=razorpay
RAZORPAY_KEY_ID=rzp_test_xxxxxxxx
RAZORPAY_KEY_SECRET=xxxxxxxx
RAZORPAY_WEBHOOK_SECRET=xxxxxxxx
```

```bash
php artisan config:clear && php artisan config:cache
sudo systemctl reload php8.5-fpm
```

**Until `PAYMENT_GATEWAY` is set, checkout offers cash only** and refuses
online methods with "Online payment is not available yet." That is deliberate:
offering a method that cannot complete loses the basket at the final step.

The **key secret and webhook secret never leave the server**. Only
`RAZORPAY_KEY_ID` is published, and it reaches the app through
`/v1/orders/{id}/payment` rather than being compiled into the binary — so
rotating it does not need an app release.

## Money

Razorpay works in **paise**. Rupees only appear at the edges, and the
conversion rounds rather than truncates: `430.10 * 100` is 43009.999… in binary
floating point, and truncating that bills the customer a paisa short every
single time.

## Swapping providers

`PaymentGateway` is four methods. A different aggregator is one new class and
one line in `AppServiceProvider` — the same shape as the SMS driver, and the
reason no gateway name appears anywhere outside `app/Services/Payments`.

`FakeGateway` backs local development and the test suite. It signs with a real
HMAC, so the verification path is genuinely exercised rather than stubbed to
"yes" — a fake that always agreed would let a broken signature check pass.
Payment ids prefixed `pay_fail_` report a failure, so the unhappy path is
reachable without a real bank declining a card.
