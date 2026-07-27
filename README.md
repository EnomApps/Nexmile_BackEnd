# Nexmile — Backend API

Laravel API for **enom.express / Nexmile**, the 1 km ultra-hyperlocal delivery platform.
This repo is the **core API** consumed by four frontends: Customer app (Flutter), Rider app
(Flutter), Merchant web portal (React) and Admin web portal (React).

Compute-heavy dispatch/geo/ETA work lives in a separate Python service, coupled via Redis.

## Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 12 (PHP 8.2+) |
| Database | MySQL / MariaDB |
| API auth | Laravel Sanctum (Bearer tokens) |
| Real-time state | Redis *(added in EP9)* |
| Dispatch engine | Python service *(added in EP7)* |

## Local setup

```bash
git clone https://github.com/EnomApps/Nexmile_BackEnd.git
cd Nexmile_BackEnd

composer install
cp .env.example .env
php artisan key:generate
```

Create the database, then set credentials in `.env`:

```sql
CREATE DATABASE nexmile CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```env
DB_CONNECTION=mysql
DB_DATABASE=nexmile
DB_USERNAME=root
DB_PASSWORD=
```

Run migrations and start the server:

```bash
php artisan migrate
php artisan serve
```

API base URL: `http://127.0.0.1:8000/api/v1`

## Conventions

- All routes are versioned under `/api/v1`.
- Roles are typed enums in `app/Enums` — never raw strings.
- Every response is JSON; send `Accept: application/json` so validation failures
  return **422** instead of a redirect.
- Protected routes use `auth:sanctum` plus the `role:` middleware alias
  (e.g. `role:merchant`, `role:admin`).

## Merchant Portal endpoints (EP2)

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| POST | `/api/v1/merchant/register` | — | Create owner account + business profile |
| POST | `/api/v1/merchant/login` | — | Exchange credentials for a Bearer token |
| GET | `/api/v1/merchant/me` | Bearer | Current merchant + KYC status |
| POST | `/api/v1/merchant/logout` | Bearer | Revoke the current token only |

### Register

`POST /api/v1/merchant/register`

Required: `owner_name`, `phone`, `email`, `password`, `password_confirmation`,
`business_name`, `address_line1`, `city`, `pincode`.

Optional: `business_phone`, `business_email`, `address_line2`, `state`,
`latitude`, `longitude`, `fssai_license_no`, `fssai_expiry_date`, `gstin`, `pan`,
`preferred_locale` (`en` | `ta`).

```json
{
  "owner_name": "Karthik Raja",
  "phone": "9876543210",
  "email": "karthik@example.com",
  "password": "Nexmile2026",
  "password_confirmation": "Nexmile2026",
  "business_name": "Saravana Bhavan Madurai",
  "address_line1": "12 West Masi Street",
  "city": "Madurai",
  "pincode": "625001",
  "latitude": 9.9252007,
  "longitude": 78.1197754
}
```

Returns **201** with the user, nested merchant profile, and a Bearer token.
The account is created as `status: pending` / `kyc_status: pending` and
**cannot accept orders** until an admin verifies KYC.

### Login

`POST /api/v1/merchant/login`

`identifier` accepts **either** the registered email or the 10-digit mobile number.

```json
{ "identifier": "9876543210", "password": "Nexmile2026", "device_name": "merchant-web" }
```

Returns **200** with a Bearer token. Throttled to **5 failed attempts per
identifier + IP per minute**, then **429**.

### Authenticated calls

```
Authorization: Bearer <token>
Accept: application/json
```

## Validation rules (India)

| Field | Rule |
|---|---|
| `phone` | 10 digits, starts with 6–9 |
| `pincode` | 6 digits, cannot start with 0 |
| `pan` | `ABCDE1234F` — auto-uppercased |
| `gstin` | 15 characters — auto-uppercased |
| `fssai_license_no` | exactly 14 digits |
| `fssai_expiry_date` | must be a future date |
| `password` | min 8 chars, letters + numbers |

## Roadmap

Work is tracked against the epics in `Jira-Board-Breakdown.md`.

- **EP2** — merchant auth ✅ · OTP verification, rider & customer onboarding, admin approval *(next)*
- **EP3** — restaurant, menu, category, item APIs
- **EP4** — geo discovery within 1 km + expand-ring
- **EP5** — cart, order creation, pricing
- **EP6** — Razorpay, COD, wallet ledger
- **EP7** — Python dispatch service
