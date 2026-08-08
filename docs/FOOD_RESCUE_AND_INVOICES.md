# Food Rescue (EP14) and tax invoices (EP11)

## Food Rescue

Surplus food a kitchen would otherwise throw away, sold at a discount inside a
window and in a fixed quantity.

The schema for this shipped with EP1 — `is_surplus_deal`,
`surplus_available_from`, `surplus_available_until`, `surplus_quantity` on
`menu_items`, plus `scopeSurplusActive()` — and nothing read or wrote any of it
for months, while `nexmile.in/food-rescue` advertised the feature to customers.

### A deal is a dish wearing a hat

Not a separate product. Same dish, same kitchen, temporarily cheaper and
finite — modelling it separately would duplicate every option group and every
photo, and a merchant would have to maintain two of everything.

Offering one moves the usual price into `compare_at_price` and sets the deal
price. **Without the struck-through original a rescue deal is just a cheap
dish**, and the point is that it is visibly a rescue.

Withdrawing puts the price back.

### Three ways a deal ends

| | |
|---|---|
| The window closes | `surplus_available_until` passes |
| The portions run out | `surplus_quantity` reaches 0 |
| The merchant withdraws it | dish returns to its usual price |

Whichever happens first. `SurplusService::isLive()` is the single answer to
"can this be ordered right now", and it is what the API reports as
`is_rescue_deal` — so **an app never advertises a deal checkout would refuse**.

### The last portion cannot be sold twice

Claiming portions is one conditional UPDATE, for the same reason the rider
claim is:

```php
DB::table('menu_items')
    ->where('id', $item->id)
    ->where('surplus_quantity', '>=', $quantity)
    ->where(/* still inside its window */)
    ->decrement('surplus_quantity', $quantity);   // affected rows must be 1
```

Reading the count and writing it back would let two customers take the last two
portions at the same instant and drive the count negative. The claim happens
**inside the checkout transaction**, so if it fails the order is never created
— a customer is told the deal sold out rather than being charged for food that
does not exist.

Portions are **not** returned when an order is rejected. By then the kitchen
has usually used or binned the food, and inventing stock that no longer exists
is worse than losing one sale.

### Endpoints

| Method | Path | Who |
|---|---|---|
| GET | `/v1/restaurants/deals` | customer — live deals nearby, soonest to expire first |
| GET | `/merchants/food-rescue` | merchant portal |
| POST | `/merchants/food-rescue/{item}` | offer a dish |
| DELETE | `/merchants/food-rescue/{item}` | withdraw |

The deals endpoint reuses `NearbyMerchantService`, so the radius, verification
and open-first rules cannot drift from the ordinary restaurant list.

Every menu item now carries `is_rescue_deal` and, when it is one, a `rescue`
block with `portions_left`, the window and the `saving`.

## Tax invoices

A GST-registered restaurant has to be able to produce one, and every figure was
already snapshotted on the order.

```
GET /merchants/orders/{id}/invoice     merchant's copy
GET /v1/orders/{id}/invoice            customer's copy
```

A printable HTML page, not JSON — this exists to be saved as a PDF or handed to
an accountant, and nobody does either with an API response. Self-contained and
black-on-white, because an invoice ends up in places that have no CDN.

**Nothing is recomputed.** Every number is read off the order, which
snapshotted its prices and rates at checkout. An invoice that disagrees with
what the customer paid is worse than no invoice.

### CGST and SGST, never IGST

Nexmile delivers within 1 km, so supply is **always intra-state** and the tax
divides equally into CGST and SGST. An inter-state order is impossible by
construction, which is why the code does not branch on it.

The **delivery fee is its own tax line** at 18%, separate from food at 5%.
Folding them together would misstate both.

### The invoice number

`INV-` plus the order number, rather than a separate sequence. A gapless series
is a statutory requirement and a counter that can be written twice is exactly
how gaps appear — the order number is already unique and already on every other
document the customer, merchant and rider hold.
