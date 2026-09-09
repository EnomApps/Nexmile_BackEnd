# Rider referrals

A rider invites someone they know, and earns when that person starts working.

The cheapest recruiting a delivery platform does, and the person who arrives
already knows what the work is because somebody doing it told them.

---

## The rule that shapes everything

**Nothing is paid for signing up. Every rupee is attached to deliveries the new
rider actually completed.**

A referral bonus is the most abused feature on every gig platform there has
ever been, because a bonus for an account existing is free money to anyone with
a spare SIM card and an afternoon. A bonus for fifty real deliveries cannot be
earned by anything except fifty real deliveries — which is the same work
Nexmile wanted doing anyway.

## What it pays

Configured in `config/referrals.php`, not written into the app:

| The friend completes | The referrer earns |
| --- | --- |
| 10 deliveries | ₹500 |
| 50 deliveries | ₹2,000 |

**These amounts are a commercial decision that has not been taken.** They are
Swiggy's, copied from the screenshots the client sent. At ₹2,500 a head a
hundred referrals is ₹2.5 lakh, so this belongs in a budget before it belongs
in a launch.

The threshold and amount are copied onto each bonus when it is earned, so
changing the scheme later never restates what somebody was already paid.

## The endpoints

All under `/api/v1/rider/`, rider role only.

### `GET /rider/referrals`

The whole screen in one call — the list, plus:

```json
"meta": {
  "earned": 3750.00,
  "joined": 6,
  "invited": 9,
  "max_per_referral": 2500.00,
  "milestones": [
    { "deliveries": 10, "amount": 500 },
    { "deliveries": 50, "amount": 2000 }
  ]
}
```

**`max_per_referral` is the "Earn up to ₹X" headline.** Read it — do not print
an amount into the app, or the banner and the scheme drift apart the first time
the numbers change.

`joined` counts people who actually signed up, not invitations sent. "6 friends
referred" meaning six numbers typed into a form is a number the rider knows is
not true.

### `POST /rider/referrals`

```json
{ "phone": "+91 98765 43210", "name": "Murugan", "city": "Madurai" }
```

Any format — spaces, `+91`, dashes. Stored as ten digits, so a friend signing
up as `9876543210` matches an invitation typed as `+91 98765 43210`.

`201` on success. **`422` with `errors.phone[0]` for each refusal**, and the
message says which:

- your own number
- somebody who already has a Nexmile account
- a number another rider has an open invitation on
- one you already invited
- too many invitations still open (20)
- not ten digits

Show the message as it comes back.

**Nexmile does not text the number.** The response says *"Invitation saved. Ask
them to sign up with that number"* — the rider invites their friend themselves,
over WhatsApp or in person. A platform that sends an SMS to any number a
stranger types into a form is a platform being used to spam people.

### `GET /rider/referrals/{id}`

One invitation's timeline. Same shape as a row in the list.

## The progress screen

Each row carries `state`, and the steps behind it:

```json
{
  "state": "working",
  "invited_at": "...", "joined_at": "...", "onboarded_at": "...",
  "deliveries": 12,
  "steps": [
    { "deliveries": 10, "amount": 500, "done": true,  "remaining": 0,  "earned_at": "..." },
    { "deliveries": 50, "amount": 2000, "done": false, "remaining": 38, "earned_at": null }
  ]
}
```

`state` is one of `invited`, `joined`, `onboarded`, `working`, `expired`.

**Use `remaining` in the UI.** "38 more deliveries" is a reason for the referrer
to ring their friend; "not yet" is not.

### One deliberate difference from the Swiggy screen

The Swiggy timeline shows **App Installed** as its own step. Ours does not,
because we cannot observe it — the first thing the server can actually see is
somebody signing up. A tick saying "App Installed" that really means "signed
up" would be a small lie on a screen about money, and the rest of the screen is
asking to be believed.

The states we do show are all things that genuinely happened.

## What stops the obvious abuse

| | |
| --- | --- |
| Referring yourself | Refused, in any phone format |
| Referring an existing user | Refused — they are not a recruit |
| Two riders claiming one recruit | First invitation wins; enforced by a unique column |
| Paying a milestone twice | Unique index, not a check that could be raced |
| Invite farming | 20 open invitations at once |
| Invites hoarding numbers | Expire after 60 days, freeing the number |
| A referrer suspended for fraud | Stops earning; past bonuses stand |

The phone is masked (`98••••••16`) everywhere it is shown. The referrer typed
it and knows it, but their own screen is not the place to print somebody else's
mobile in full — a shared or shoulder-surfed phone makes it everyone's.

## What is not built

**Paying the money out.** Bonuses are recorded in `rider_referral_bonuses` and
shown to the rider, but nothing adds them to a payout run — rider payouts
themselves are still per-order figures on the order, with no settlement cycle.
Whoever pays riders will need to include this table.

**An admin view.** Nothing surfaces referral patterns for fraud monitoring —
one address referring twelve people, a cluster of accounts that all stop at
exactly fifty deliveries. The guards above are preventive; there is no
detection behind them.
