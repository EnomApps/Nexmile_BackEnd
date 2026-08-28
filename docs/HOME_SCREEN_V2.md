# Home screen v2 — what was built

Answering `API-REQUEST-home-v2.md` section by section. Everything below is
live on `/api/v1`, authenticated with the customer bearer token as usual.

Nothing here changes an existing response in a breaking way. Every item is a
new endpoint, a new field, or a new optional query parameter — an older build
of the app is unaffected.

---

## The two decisions you asked for

**Ratings are in this release.** Build the badge.

**Dish search: `?search=` was extended.** No new endpoint. It matches
restaurant names *and* dish names now, restricted to items that are actually
available — sending someone to a shop for a dish that is off the menu is worse
than no result.

---

## 1. Ratings

```
POST /v1/orders/{id}/review     { "rating": 4, "rider_rating": 5, "comment": "..." }
GET  /v1/orders/{id}/review
```

One per order, only once it is `delivered`, only your own order. `rider_rating`
is ignored on an order nobody delivered.

```json
{
  "data": {
    "id": 12,
    "order_id": 88,
    "rating": 4,
    "rider_rating": 5,
    "comment": "Hot and on time.",
    "created_at": "2026-08-28T14:20:11.000000Z"
  }
}
```

`GET` returns `"data": null` when it has not been rated, so you can tell
"not rated" from "rated 0".

**`rating` on a restaurant is `null` until three people have rated it**, exactly
as you asked. One five-star review from the owner's cousin is not a rating, and
one bad night should not brand a new kitchen 1.0 for good. `GET /v1/filters`
returns `meta.min_ratings_to_publish` so you can explain a hidden badge rather
than leaving a gap.

Refusals are 422 with a message worth showing:

- `You can review an order once it has been delivered.`
- `You have already reviewed this order.`

Someone else's order id is a 404, not a 403 — it does not confirm the order
exists.

---

## 2. `GET /v1/home`

```
GET /v1/home?address_id=1
GET /v1/home?latitude=13.03&longitude=80.10
```

Built as you specified. Sections are ordered by the server, and an empty
section is omitted rather than sent empty — no banners configured means no
`banners` section at all, so do not rely on a fixed index.

```json
{
  "data": {
    "sections": [
      {
        "type": "banners",
        "items": [
          {
            "id": 1,
            "image_url": "https://…/banner.jpg?signature=…",
            "alt_text": "Items at 50% off",
            "action": { "type": "collection", "value": "under-250" },
            "starts_at": "2026-08-28T00:00:00.000000Z",
            "ends_at": "2026-09-04T00:00:00.000000Z"
          }
        ]
      },
      {
        "type": "cuisines",
        "items": [
          { "slug": "biryani", "name": "Biryani", "image_url": "https://…/biryani.png?signature=…" }
        ]
      },
      {
        "type": "collection_tile",
        "title": "Meals under ₹250",
        "slug": "under-250",
        "image_url": "https://…/tile.png?signature=…"
      },
      {
        "type": "restaurants",
        "title": "Recommended for you",
        "layout": "grid",
        "items": [ /* restaurant resource */ ]
      },
      {
        "type": "restaurants",
        "title": "Featured",
        "layout": "list",
        "items": [ /* restaurant resource */ ]
      }
    ]
  },
  "meta": { "radius_metres": 1000 }
}
```

`title` is localised server-side from the caller's locale, as agreed.

**Banner actions**: `none`, `restaurant` (value is an id), `collection` (slug),
`cuisine` (slug), `url`. `action.value` is absent when the type is `none`.

**`image_url` is a signed URL with an expiry.** Storefront images already work
this way. Do not cache one past its lifetime — re-fetch the section instead.

Banners outside their campaign window disappear on their own; nobody has to be
awake at midnight to pull one.

---

## 3. New fields on the restaurant resource

On `GET /v1/restaurants`, `GET /v1/restaurants/{id}`, inside `/v1/home`,
inside `/v1/collections/{slug}`, and on `GET /v1/favourites`.

```json
{
  "rating": 4.2,
  "rating_count": 128,
  "is_pure_veg": false,
  "cost_for_two": 300,
  "cuisines": ["biryani", "south-indian"],
  "offers": [
    { "label": "Food Rescue deals live", "type": "food_rescue" },
    { "label": "Free delivery above ₹299", "type": "free_delivery" }
  ],
  "has_free_delivery": true,
  "is_favourite": false
}
```

`cuisines` holds **slugs**, matching what the cuisine rail sends back as a
filter.

**`offers` is derived, not stored — and this is deliberate.** A merchant who
could type their own offer label could promise a discount the pricing code has
never heard of, and the customer would find out at the payment screen. Offers
come from what checkout will actually do. `label` is localised and rendered
verbatim, as you asked; show the first one on a card.

---

## 4. Query parameters on `GET /v1/restaurants`

All optional. Absent means no filter, exactly as before.

| Parameter | Values |
|---|---|
| `sort` | `relevance` (default), `rating`, `delivery_time`, `cost_low_high`, `cost_high_low` |
| `cuisine[]` | slug, repeatable — `?cuisine[]=biryani&cuisine[]=pizza` |
| `rating_min` | `3.5`, `4.0` |
| `cost_for_two` | `0-150`, `150-300`, `300-` — the sheet's bracket, parsed for you |
| `cost_min` / `cost_max` | integer rupees, if you prefer to send bounds |
| `veg_only`, `free_delivery`, `no_packaging_fee`, `near_and_fast`, `has_offers`, `open_now` | `1` |

**Note the `[]`** on cuisine — Laravel needs it for a repeated parameter.

Every sort keeps open restaurants above closed ones, and unrated restaurants
sort last under `rating` — unrated is not the same as bad, but it cannot
outrank a kitchen that has earned a score.

### meta

```json
"meta": {
  "radius_metres": 1000,
  "total": 42,
  "applied_filters": { "cuisine": ["biryani"], "rating_min": 4.0 },
  "current_page": 1,
  "last_page": 3,
  "per_page": 15
}
```

`total` drives "Show results (42)". It comes from the paginator, so it is the
count **after** filtering and across all pages, not the size of the page.

`applied_filters` omits anything not applied, so a present key means an active
chip. It is `{}` when nothing is filtered.

### `near_and_fast` is defined by us, as you asked

Inside **half** the search radius, prep time at or under **30 minutes**, and
currently open. It lives in `config/discovery.php` — tell us if that feels
wrong in practice and we will move the number, not the meaning.

---

## 5. `GET /v1/filters`

Every `key` is a real query parameter on `GET /v1/restaurants`, so you can
build the request straight from this response without a lookup table.

```json
{
  "data": [
    { "key": "sort", "title": "Sort by", "type": "single_choice",
      "options": [{ "value": "rating", "label": "Rating" }] },
    { "key": "rating_min", "title": "Restaurant rating", "type": "single_choice",
      "options": [{ "value": "3.5", "label": "Rated 3.5+" }] },
    { "key": "cuisine", "title": "Cuisine", "type": "multi_choice",
      "options": [{ "value": "biryani", "label": "Biryani" }] },
    { "key": "cost_for_two", "title": "Dish price", "type": "range_choice",
      "options": [{ "value": "0-150", "label": "Less than ₹150" }] },
    { "key": "veg_only", "title": "Pure veg", "type": "toggle", "options": [] }
  ],
  "meta": { "min_ratings_to_publish": 3 }
}
```

Types are the four you listed. Titles and labels are localised server-side.

**`free_delivery` is deliberately absent.** The threshold is platform-wide
today, so that filter would match every restaurant and narrow nothing. It
appears automatically once free delivery varies by merchant — treat the list as
dynamic, which is the point.

The `cuisine` section is omitted while no cuisines are configured.

---

## 6. `GET /v1/collections/{slug}`

```
GET /v1/collections/under-250?address_id=1
```

Takes a location like the rest of discovery — a curated list still respects the
delivery radius, or it advertises restaurants that cannot deliver.

```json
{
  "data": {
    "slug": "under-250",
    "title": "Meals under ₹250",
    "subtitle": "Full meals, nothing over ₹250",
    "banner_url": "https://…/header.jpg?signature=…",
    "restaurants": [ /* restaurant resource */ ]
  },
  "meta": { "radius_metres": 1000, "total": 6 }
}
```

An inactive or unknown slug is a 404.

---

## 7. Favourites

```
GET    /v1/favourites                     → restaurant resources
POST   /v1/restaurants/{id}/favourite     → 200
DELETE /v1/restaurants/{id}/favourite     → 200
```

`POST` is idempotent — two taps leave one row. `is_favourite` is on the
restaurant resource, so the list does not need a second call.

---

## 8. Dish search

`?search=dosa` matches restaurant names and available dish names, returning the
restaurants. No second endpoint, no second call.

---

## Your two housekeeping points

**`item_count`** — you were right. `GET /v1/carts` now returns it alongside
`items`, so the basket badge no longer means parsing every line of every cart.

**`cart_item_id`** — the API has always sent `cart_item_id`; the schema is what
was wrong. Our documentation generator cannot see inside the pricing service
and guessed. The field name is not changing, because your code already works
against it. Treat this document as the contract where the two disagree.

**Types**: everything new sends numbers as numbers and booleans as booleans, as
requested. `rating` is a float or null. `cost_for_two` is an integer or null.

---

## What still needs us

Nothing blocking. Two things worth knowing:

**Content has to be created before sections appear.** Banners, cuisines and
collections are managed at `/admin/home-screen`. Until someone adds them,
`/v1/home` returns only the restaurant sections — which is correct behaviour,
not a bug.

**`cost_for_two`, `is_pure_veg` and `cuisines` are per-restaurant fields that
start empty.** They are set on the merchant record; until they are filled in,
`cost_for_two` is null and cuisine filters match nothing. Worth seeding a few
real restaurants before testing those filters, or they will look broken.
