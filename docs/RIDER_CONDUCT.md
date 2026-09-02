# Rider conduct

How a customer says something went wrong with a rider, what an admin does
about it, and what it takes before anyone loses work.

The short version, for the client: **nothing happens to a rider
automatically.** A report is what a customer said. A warning is what Nexmile
decided after a person read it. Three warnings inside ninety days flags the
rider for review — a prompt to open their file, not a dismissal. Termination
stays a deliberate human act, on a different screen, by someone who read the
whole record.

That gap is the design, not an omission. Collapse it and a rider's income
belongs to whoever complains loudest — and the most motivated complainant on
any delivery platform is someone who wants their money back.

---

## What the customer sees

Two endpoints, both authenticated as the customer.

### `GET /api/v1/report-categories`

The list of reasons, served from the server so it can change without an app
release and so the app can never send something the backend does not know.

```json
{
  "data": [
    { "value": "rude",          "label": "Rude or aggressive" },
    { "value": "extra_money",   "label": "Asked for extra money" },
    { "value": "unsafe",        "label": "Unsafe or dangerous riding" },
    { "value": "food_damaged",  "label": "Food spilled or tampered with" },
    { "value": "never_arrived", "label": "Marked delivered but never arrived" },
    { "value": "very_late",     "label": "Very late with no explanation" },
    { "value": "other",         "label": "Something else" }
  ]
}
```

Render them in the order returned. Do not hardcode the list.

### `POST /api/v1/orders/{order}/report-rider`

```json
{
  "category": "extra_money",
  "description": "Asked for ₹50 on top of the bill."
}
```

`description` is optional and capped at 1000 characters. `201` on success:

```json
{ "message": "Thank you. Our team will look into this." }
```

The wording is deliberate. It does not promise an outcome — a customer told
"action will be taken" expects to hear that it was, and most reports end in a
conversation nobody outside sees.

**Refused with `422`** when the order is not finished yet, when the customer
already reported that delivery, or when the category is unknown. **`404`** when
the order is not theirs. Show `errors.order[0]` or `errors.category[0]`.

### Where to put it in the app

On the completed-order screen, next to the rating — not instead of it. A star
rating cannot carry "he shouted at me"; nobody reads three stars that way. One
report per order, so hide the entry point once `201` came back.

The button should only appear once the order reaches `delivered` or
`cancelled`. A complaint that lands while the rider is still carrying the food
invites the obvious next step — suspending them — which strands the order the
customer is still waiting for.

---

## What the admin sees

`nexmile.in/admin/conduct`, in the admin nav under **Conduct**.

**The queue.** Every report, newest first, filtered by *Waiting to be read*,
*Safety and money*, or *Everything*. The four categories marked serious —
rudeness, extra money, unsafe riding, marked-delivered-but-never-arrived — are
money and safety, not service quality, and get their own filter so they never
sit behind twenty complaints about a late idli.

**Two buttons on a pending report, each requiring a written note:**

- **Uphold and warn** — records the decision *and* issues the warning as one
  act. A warning that can be issued without reading a report is a warning
  nobody had to justify.
- **Dismiss** — recorded, not deleted. The next admin reading this rider's
  file needs to see that a complaint was made and answered, or the same
  question gets re-litigated every time.

The note is compulsory on both. It is what the rider is owed if they ask what
happened.

**The rider's file** (`/admin/conduct/riders/{id}`) — rating, warnings still
counting, reports waiting, and every report and warning in full. There is also
a **Record a warning** form for something raised outside the app: a merchant
called, an admin witnessed it. Forcing a fake customer report to record those
would corrupt the reports table permanently.

**Needs review** sits at the top of the queue: riders the system thinks
someone should look at.

---

## When a rider gets flagged

Two triggers, both configurable in `config/conduct.php`:

| Trigger | Default |
| --- | --- |
| Warnings inside the window | 3 |
| Window length | 90 days |
| Rating below | 3.0 |
| Ratings needed before the rating counts | 10 |

**Warnings expire.** One bad month should not follow someone forever, and a
rider with nothing to gain from improving is a rider who leaves.

**The rating trigger exists because the queue alone would miss people.** A
rider nobody bothers to report but everybody quietly scores 2 never appears in
a list built out of complaints. Ten ratings before it counts — three bad nights
out of three is noise.

## Termination

Deliberately **not** a button on this screen. Suspending an account lives on
the rider's own record under Verification, where the person doing it has the
KYC, the payout history and the delivery record in front of them.

The process the flag is meant to support:

1. A warning is issued, with a note, each time a report is upheld.
2. At three inside ninety days the rider is flagged for review.
3. An admin opens the file and reads what actually happened — three late
   deliveries in monsoon week is not three refunds demanded at the door.
4. If it warrants it, the account is suspended from the rider's record.

Step 3 is not automatable, and the count exists so a pattern cannot be missed —
not so the decision can be handed to arithmetic.

---

## Tables

`rider_reports` — order, rider, reporter, category, description, status,
reviewer, reviewed_at, review_note. Unique on `(order_id, reported_by_user_id)`:
saying it twice does not make it twice as true, and three warnings is a
threshold one angry evening should not be able to reach alone.

`rider_warnings` — rider, optional report, reason, who issued it. Nullable
report on purpose, for the merchant-complaint case above.
