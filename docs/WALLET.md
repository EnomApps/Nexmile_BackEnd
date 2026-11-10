# Customer wallet

A closed-loop balance. Money goes in through Razorpay and comes out only as
food.

## The rule that is not a product decision

**The wallet must never become withdrawable to a bank account.**

A closed-loop prepaid balance — spendable only with the issuer — sits outside
the RBI's prepaid payment instrument licensing regime. One that can be cashed
out does not: that is a different financial product, needing authorisation,
with capital and escrow obligations attached.

So there is no withdrawal endpoint, and adding one is a legal question before
it is an engineering one. Refunds go back to the wallet for money that came
from the wallet, and back to the card for money that came from the card.
Nothing converts a balance into cash.

Two other things the client's accountant should confirm before this is switched
on in production:

- **Where topped-up money is held.** Customer money sitting as a balance is not
  revenue until it is spent. Whether it needs a separate account is their call.
- **GST timing** — whether the liability arises on the top-up or on the order
  it eventually pays for.

## Limits

| Setting | Default | |
|---|---|---|
| `WALLET_MIN_TOPUP` | ₹10 | Below this the gateway's own fee eats the top-up |
| `WALLET_MAX_TOPUP` | ₹10,000 | Blast radius for a stolen card tried here |
| `WALLET_MAX_BALANCE` | ₹10,000 | A balance past the RBI's minimum-detail PPI cap invites a question |

## How money moves

### In

```
POST /v1/wallet/topups   { "amount": 500 }
  → opens a Razorpay order, returns razorpay_order_id + the publishable key
POST /v1/wallet/topups/{id}/verify
  → signature checked, wallet credited
```

The publishable key comes back in the response rather than being compiled into
the app, so rotating it does not need a release. **The secret never leaves the
server.**

A top-up is credited by whichever of two things arrives first:

1. The app's `verify` call, after the SDK returns
2. **Razorpay's `payment.captured` webhook**

The webhook is not a backup — it is the one that matters. If a customer's
network drops after they pay, `verify` never arrives, and the webhook is the
only thing that turns their money into a balance.

Whichever is second finds `credited_at` already set, inside the lock, and does
nothing.

### Out

Only by ordering. `POST /v1/restaurants/{id}/cart/checkout` takes
`use_wallet: true`:

| Balance vs bill | `payment_method` | What happens |
|---|---|---|
| Covers it | `wallet` | Debited in full, order placed immediately |
| Partial | `cod` | Debited as far as it goes, rider collects `cod_amount` |

The order payload carries `wallet_amount` and `cod_amount`. **`cod_amount` is
what a rider asks for at the door** — stored, not computed from the total, so a
later refund cannot change the number a rider already read off their screen.

Wallet money cannot be combined with a card or UPI payment. Splitting a basket
across a balance and a gateway leaves a part-paid order when the card fails,
and the honest version of that feature is a larger one than this.

**The wallet works while the gateway is off.** Its money moved at top-up time,
so spending it needs nobody's permission — a customer with ₹500 sitting there
should not be told to pay cash because Razorpay is down. Topping *up* does need
a gateway, and is refused with a 503 when there is none.

### Back

Cancelling an order credits the wallet part back as a `refund` transaction, in
the same database transaction that cancels the order. A cancellation that
succeeded without its refund would be money quietly kept.

## Why it is built this way

### The balance is stored, not summed

`wallets.balance` is a column. Summing the ledger on every read could never
drift, but it also cannot be locked cheaply, and a spend has to lock something:
two phones checking out at once both read ₹200, both decide it covers ₹150, and
the customer eats ₹300 of food for ₹200.

The ledger is the check on it. `nexmile:check-wallets` re-adds every
transaction and reports any wallet that disagrees. It **reports and never
repairs** — a wallet that disagrees with its history is a bug upstream, and
writing the sum over the balance would hide the evidence while leaving the
cause in place.

Worth running on a cron once this is live.

### Every credit has an idempotency key

`topup:41`, `order:1203`, `refund:1203`. The unique index on
`wallet_transactions.idempotency_key` means a duplicate cannot be inserted even
if the lock were somehow bypassed.

This is what makes a redelivered webhook, a double-tapped verify and a retry
after a timeout all safe.

### A wallet can never go negative

Checked in `WalletService::move()`, which throws rather than writing. Reaching
that point means a caller skipped the lock or did its own arithmetic — and
losing the transaction, checkout included, is far better than losing the money.

### Only `WalletService` writes `balance`

Not tidiness — it is the reason the locking can be trusted. A second writer
elsewhere would not take the same lock, and the bug would appear weeks later as
a balance wrong by one order, with nothing in the logs.

## Tables

| | |
|---|---|
| `wallets` | The balance, and the row that gets locked |
| `wallet_transactions` | The ledger that explains the balance |
| `wallet_topups` | Money coming in, and its life at the gateway |

`wallet_transactions.balance_after` is redundant and worth it: a customer
asking "where did my ₹200 go" is answered by reading down a column rather than
by adding up a history and hoping.

Transactions are written once and never edited. A mistake is corrected by
another row — an `adjustment`, always explained in `description` — so the
history still adds up to the balance.

## Not built

- **No admin screen.** Ops cannot see a balance or post an adjustment without
  database access.
- **No expiry.** Balances sit until spent, which is also the friendliest
  behaviour and avoids a different set of questions.
- **No wallet-plus-card split**, as above.
- **Nothing reconciles against Razorpay's settlement report.** The webhook is
  trusted as the record of what was captured.
