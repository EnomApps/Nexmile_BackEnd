# Merchant commission

What a restaurant pays Nexmile, how a launch offer ends, and the one decision
still outstanding.

## The model

Nexmile charges a percentage of **food and packaging** on each delivered
order. The delivery fee is not commissionable — it pays the rider and was
never the restaurant's revenue.

There is **no onboarding fee and no monthly subscription** anywhere in the
system. Not as a disabled feature — the concept does not exist. A restaurant
pays when it receives an order and at no other time.

Each order stores the commission it was priced with, so changing a rate never
rewrites what a restaurant was charged in the past. Those figures are already
on invoices and payout statements.

The ceiling is 30% (`checkout.max_commission_rate`). A fat-fingered 150 would
take more than the order is worth and produce a negative payout.

New restaurants start on `DEFAULT_COMMISSION_RATE`, currently **15** in
`.env`. The launch plan wants 10 — that is a one-line env change on the
server, not a code change.

## Tiers

The three tiers are rates, not features. Nothing in the code needs to know
which plan a restaurant is on.

| Plan | Rate | Notes |
| --- | --- | --- |
| Launch | 10% | First 3–6 months, then scheduled to rise |
| Standard | 12% | The main plan |
| Growth | 15% | Sponsored placement and promotion |

The Growth tier's *rate* works today. What it buys — sponsored placement,
priority visibility, featured positioning — is the merchandising system
(banners, collections, the home screen), which exists but has no concept of a
restaurant having paid for a slot. That is a separate build if it is sold as a
product rather than granted by hand.

## Ending a launch offer

A rate can be scheduled to change on a date:

> Ponnusamy Hotel — 10%, changes to 12% on 14 April 2026

Set on the merchant's admin page. Both fields or neither: a date with no rate
does nothing when it arrives, and a rate with no date never applies. Either
half alone is a launch offer that silently never ends.

**The rate is worked out from the date, not flipped by a job.** An order
placed at 00:01 on 14 April is priced at 12% whether or not any scheduled task
ever ran — which matters, because the scheduler needs a crontab line and a
launch offer still charging 10% in July because nobody installed it is a
revenue hole that would go unnoticed for months.

`nexmile:apply-commission-changes` runs daily and folds a past-due change into
the rate itself. That is tidying, not correctness: it stops the admin page
advertising a change that already happened, which is how somebody ends up
setting 12% by hand on top of a rate that is already 12%.

**Backdating is refused.** A change must start in the future. Moving a rate
backwards would charge a restaurant more for orders it has already cooked, on
terms it was never shown.

### The restaurant sees it coming

From the moment it is set, the restaurant's own earnings page says *"Your
commission changes to 12% on 14 April 2026"* — in English, Tamil or Hindi,
next to the commission figure it changes.

This is the point of the feature, more than the automation. A rate that rises
without warning is discovered while reconciling a payout, and an owner who
finds an extra two percent gone is right to leave. Writing it down in advance
is what makes the end of a launch offer a term they agreed to rather than
something that happened to them.

## Outstanding: GST on commission

**This is not implemented, and it needs a decision from the accountant before
it can be.**

The commission is a taxable service Nexmile supplies to the restaurant, so GST
is owed on it. What the system does today is deduct the percentage and nothing
else:

| ₹300 order at 12% | Today | If GST is added on top |
| --- | --- | --- |
| Commission | ₹36.00 | ₹36.00 |
| GST @ 18% | — | ₹6.48 |
| Restaurant receives | **₹264.00** | **₹257.52** |

₹6.48 per ₹300 order. At 30 orders a day that is roughly ₹5,800 a month per
restaurant.

The question is whether the quoted 12% is **inclusive or exclusive of GST** —
whether the restaurant pays ₹36 or ₹42.48. Both are implementable and the
difference is small per order and material per month. The tax is owed either
way; what is being decided is who bears it.

Until that is settled the code deducts the bare percentage, which matches
neither treatment explicitly. Worth resolving before the first restaurant is
signed, because changing it afterwards means renegotiating.
