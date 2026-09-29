# Nadgo Orders API

A small PHP API for **nad-go.com** orders, built for **GoDaddy cPanel** shared
hosting. It handles a two-step checkout, an email-verified order-tracking
portal, and admin verification/fulfilment — backed by MySQL, with SMTP
notifications from `noreply@nad-go.com`.

## Order flow

```
ACCOUNT register / login    email + password -> session token
STEP 1  create_order        customer details + products  -> order saved (payment: pending)
STEP 2  payment_info        show bank / crypto details for that order
        submit_payment      customer uploads receipt or crypto tx-id -> payment: submitted
ADMIN   admin_verify_payment confirm/reject the proof    -> payment: paid | rejected
        admin_update_delivery processing/shipped/delivered (customer is emailed)
TRACK   track_orders        logged-in customer views their orders + status
```

`payment_status`: `pending → submitted → paid | rejected`
`delivery_status`: `pending → processing → shipped → delivered | cancelled`

## Endpoints

All requests go to `index.php?action=<name>`. JSON endpoints take a JSON body;
`submit_payment` takes `multipart/form-data`.

| Action | Method | Auth | Purpose |
|--------|--------|------|---------|
| `register` | POST (JSON) | `X-API-Key` | Create an account (email + password) → session token |
| `login` | POST (JSON) | `X-API-Key` | Email + password → session token |
| `request_password_reset` | POST (JSON) | `X-API-Key` | Email a reset code (`{email}`) |
| `reset_password` | POST (JSON) | `X-API-Key` | Set new password with code (`{email,code,password}`) → session token |
| `contact` | POST (JSON) | `X-API-Key` | "Request more info" form → emails admins (no DB) |
| `stripe_webhook` | POST | Stripe signature | Marks card orders paid (called by Stripe) |
| `create_order` | POST (JSON) | `X-API-Key` + login | Step 1 — create an order (logged in, or register inline) |
| `payment_info` | GET | order token / session | Step 2 — order + bank/crypto details |
| `submit_payment` | POST (multipart) | order token / session | Step 2 — submit receipt / tx-id |
| `track_orders` | POST | `X-Session-Token` | List the logged-in user's orders |
| `get_order` | GET | order token / session | One order's full detail |
| `get_profile` | GET | `X-Session-Token` | The user's editable profile |
| `update_profile` | POST (JSON) | `X-Session-Token` | Edit contact + address |
| `admin_list_orders` | GET | `X-Admin-Key` | List/filter all orders |
| `admin_verify_payment` | POST (JSON) | `X-Admin-Key` | Confirm/reject payment |
| `admin_update_delivery` | POST (JSON) | `X-Admin-Key` | Set delivery status |
| `admin_get_receipt` | GET | `X-Admin-Key` | Stream a receipt file |
| `admin_test_email` | GET | `X-Admin-Key` | Send a test email (`?to=`) via the configured transport |
| `admin_logs` | GET | `X-Admin-Key` | Recent request logs (filters: `action_filter`, `status`, `errors_only`, `limit`) |
| `admin_php_log` | GET | `X-Admin-Key` | Tail of the PHP `error_log` file(s) (`?lines=`) |

**Per-order auth** = send the order's `tracking_token` (header `X-Tracking-Token`
or a `tracking_token` field) **or** a valid `X-Session-Token` whose email owns
the order. The token is returned by `create_order` and listed in `track_orders`.

## Auth keys (set in `config.php`)

- `api_key` — frontend key for `create_order` (sent as `X-API-Key`).
- `admin_api_key` — single admin token for `admin_*` (sent as `X-Admin-Key`).
  The planned admin portal will live on this server and use this one token.
- `app_secret` — signs OTP hashes and tracking session tokens. Server-side only.

Generate each: `php -r "echo bin2hex(random_bytes(32));"`

## Files

```
index.php              action router + per-endpoint auth + CORS
config.example.php     copy to config.php and fill in
src/Database.php       PDO/MySQL wrapper
src/Auth.php           keys, signed session tokens, OTP
src/Uploads.php        receipt validation + storage
src/Mailer.php         SMTP emails (PHPMailer: bundled or composer)
src/OrderService.php   all business logic
src/RateLimiter.php    DB-backed sliding-window rate limiter
src/RequestLog.php     request logging + PHP error-log tail
src/StripeGateway.php  Stripe Checkout session + webhook verification
lib/PHPMailer/         bundled PHPMailer (no composer needed)
sql/schema.sql         users, orders, order_items, payments, rate_hits, request_logs
uploads/receipts/      stored receipts (NOT web-served)
admin/index.html       single-token admin portal (static page)
admin/logs.html        developer log console (served at /logs)
assets/                put logo.png here (used by portal + emails)
composer.json          optional — pull PHPMailer via composer instead
```

## Branding & theme

- Set your branding in `config.php → brand`: `name`, `color` (primary, default
  `#0F2057`), `logo_url`, `support_email`, `website_url`.
- **Logo:** drop `assets/logo.png` (served at `https://api.nad-go.com/assets/logo.png`).
  It appears in the admin portal and as the header of every email. Until it
  exists, the portal hides the image and emails fall back to the brand name.
- **Emails** use a responsive, branded HTML template (logo header, item table,
  totals, footer) with a hidden preheader for the inbox preview.
- **Admin portal** has a light/dark toggle (🌙/☀️) in the header; the choice is
  remembered per browser. Light is the default.

## Admin portal

`admin/index.html` is a self-contained page (no build step). `index.php` renders
it on a plain `GET /`, so the login page is the **root of the API subdomain**:
just open `https://api.nad-go.com/`. Paste the **admin token** (`admin_api_key`)
and you can:

- list/filter orders by payment & delivery status,
- view each order's items + submitted payment proofs,
- open receipt files (fetched with the admin token, never public),
- mark a payment **paid** or **reject** it with a reason,
- update **delivery status** (carrier / tracking # / note) — the customer is emailed.

The token is kept in the browser's `localStorage`; "Log out" clears it. It calls
the same admin endpoints, so it's just a UI over the API.

## Anti-spam: accounts, honeypot & Turnstile

- **Login gate** — `create_order` requires a logged-in `X-Session-Token` (or
  inline email+password), so every order is tied to a real account.
- **Honeypot** — register/order/payment requests must include an empty field
  named by `honeypot_field` (default `website`); a filled value is rejected (422).
- **Cloudflare Turnstile** — when `turnstile.enabled` is true, `register` and
  guest `create_order` require a valid Turnstile token (sent as `turnstile_token`
  in the body, or the `CF-Turnstile-Token` header). Configure `turnstile.site_key`
  (frontend) + `secret_key` (server) in `config.php`. While `enabled` is false the
  check is skipped; if enabled but unconfigured it logs a warning and passes.

## Accounts & login

Authentication is **email + password**. The `users` table holds each customer's
account: credentials (`password_hash`, bcrypt) plus an editable profile (contact
+ default address), keyed by email.

- **`register`** — `{ email, password, ...optional profile, website:"" , turnstile_token }`
  creates the account and returns a `session_token`. Password ≥ 8 chars.
- **`login`** — `{ email, password }` returns a `session_token` (valid 24h by
  default, see `config.session.ttl_seconds`).
- Send the token as **`X-Session-Token`** on `create_order`, `track_orders`,
  `get_profile`, `update_profile`.
- **`create_order`** requires being logged in. If no session is sent, you may
  pass `email` + `password` in the body: an existing account is logged in
  (correct password required) or a new one is created inline — so customers can
  make an account *while* placing their first order.
- Each order stores its own `ship_*` snapshot; editing the profile changes the
  default/future address, never where past orders shipped.
- `get_profile` / `update_profile` edit name, phone, address (email is the
  identity and isn't editable).

**Forgot password:** `request_password_reset {email}` emails a short code (valid
~15 min, attempts capped); `reset_password {email, code, password}` sets the new
password and returns a session token (auto-login). Codes are stored hashed in
`password_resets`. Honeypot + Turnstile + rate limits guard the request step.
(Existing DBs: import `sql/migrations/2026-06-04_password_resets.sql`.)

Bot/abuse protection on the account entry points: **honeypot** field (empty
`website`) + **Cloudflare Turnstile** on `register` and guest `create_order`,
plus rate limits (`register`, `login`, `create_order`).

## Payment methods

Three methods, chosen via `payment_method` on `create_order`:

- **`bank`** / **`crypto`** — manual. The customer sees the bank/crypto details
  (`payment_instructions`), pays, then submits a receipt/tx-id (`submit_payment`);
  an admin verifies it. Order goes `pending → submitted → paid`.
- **`card`** — Stripe Checkout (hosted). `create_order` with `payment_method:"card"`
  returns a **`checkout_url`**; the frontend redirects the customer there. When
  Stripe confirms payment, it calls `stripe_webhook`, which marks the order
  **paid** automatically (no admin step) and sets delivery to `processing`.

Stripe setup (`config.php → stripe`): set `enabled:true`, `secret_key`,
`webhook_secret`, and `success_url`/`cancel_url`. In Stripe → Developers →
Webhooks, add endpoint `https://api.nad-go.com/?action=stripe_webhook` for the
`checkout.session.completed` event. No SDK/composer needed (REST + cURL). If
Stripe is disabled or fails, `create_order` still saves the order and returns a
`checkout_error` instead of a URL — the customer can pick a manual method.

## Email delivery

Notifications are sent through a pluggable transport (`config.php → mail`):

- `mail.transport` — `resend` (default; Resend HTTP API), `brevo` (Brevo HTTP API),
  or `smtp` (PHPMailer). The HTTP-API transports only need outbound HTTPS, so they
  work even when cPanel blocks outbound SMTP ports.
- **Resend:** set `mail.resend.api_key` (starts with `re_`). Verify `nad-go.com`
  under resend.com → Domains; until then you can send FROM `onboarding@resend.dev`.
- `mail.from_email` / `from_name` — sender; **must be on a domain verified with the
  provider**, or it refuses to send.
- `mail.admin_emails` — array; admin notifications go to **all** of them
  (e.g. `admin@nad-go.com`, `accounts@nad-go.com`).

Two admin notifications are sent (every `admin_emails` entry): **(1)** when an
order is placed, and **(2)** when a customer submits a payment. The only
customer-facing email is the **password-reset code**. The **contact** form goes
to `mail.contact_emails` (falls back to `admin_emails` if empty) — so you can
route enquiries to a different inbox (e.g. `info@nad-go.com`). All recipient
mailboxes must **exist** to receive.

**Test it:** `GET ?action=admin_test_email&to=you@example.com` (header
`X-Admin-Key`) sends a branded test through the configured transport and returns
the provider error if it fails.

## Developer logs

A hidden, admin-only log console lives at **`https://api.nad-go.com/logs`** (URL
only — no link from the portal). Open it, paste the admin token, and you get:

- **Requests** — every API call is logged to the `request_logs` table: time, IP,
  method, action, HTTP status, duration (ms), logged-in actor, and any error.
  **No request bodies, passwords, tokens or payment data are stored** — metadata
  only. Filter by action/status, "errors only", and limit; optional 5s auto-refresh.
- **PHP error log** — tails the server's `error_log` (the `ini_get('error_log')`
  file plus any cPanel per-directory `error_log` files under the app), so
  uncaught PHP errors are visible without SSH.

Configure in `config.php → logging` (`enabled`, `retention_days`; old rows are
auto-pruned). The viewer's own polling (`admin_logs`/`admin_php_log`) is not
logged, to avoid noise.

**Existing database?** Add just this table without a full re-import:
import `sql/migrations/2026-06-03_request_logs.sql`.

## Rate limiting

Abuse-prone endpoints are throttled with a sliding window stored in the
`rate_hits` table (no Redis/APCu needed). Defaults in `config.php → rate_limits`:
`create_order` 30/h per IP, `register` 10/h per IP, `login` 10 / 15min per IP+email,
`submit_payment` 20/h. Over the limit returns **429** with a `Retry-After`
header. Set a limit to `0` to disable it.

## Deploy to cPanel (GoDaddy)

1. **Database** — cPanel → *MySQL® Databases*: create a DB + user (note the
   prefixed names). Import `sql/schema.sql` via *phpMyAdmin → Import*.
2. **Upload** — deploy these files to the **`api.nad-go.com` docroot** (create the
   subdomain in cPanel → *Domains/Subdomains* and point it at this folder). The
   API is then `https://api.nad-go.com/?action=...` and the admin login is
   `https://api.nad-go.com/`.
3. **Configure** — copy `config.example.php` to `config.php`; fill DB, SMTP, the
   three keys, and your **payment instructions** (bank account + crypto wallets).
4. **Email** — set `mail.brevo.api_key` and verify the `from_email` sender (or
   authenticate the domain) in Brevo. Make sure the `admin_emails` mailboxes
   actually exist on your mail host so they can receive. (See "Email delivery".)
5. **Uploads** — make sure `uploads/receipts/` is writable by PHP (755/775).
   Receipts are never web-served (`.htaccess` denies the folder); admins fetch
   them through `admin_get_receipt`.
6. **PHP 8.1+** — set it in cPanel → *Select PHP Version* if needed.

Optional composer instead of the bundled PHPMailer:
```bash
composer install   # index.php auto-loads vendor/ if present; lib/PHPMailer is the fallback
```

## Examples

**Register or log in first** to get a session token:
```bash
# Register (new account)
curl -X POST "https://api.nad-go.com/?action=register" \
  -H "Content-Type: application/json" -H "X-API-Key: FRONTEND_KEY" \
  -d '{"email":"jane@example.com","password":"hunter2pass","website":"","turnstile_token":"..."}'
#   -> { "ok":true, "session_token":"...", "expires_at":... }

# Login (returning account)
curl -X POST "https://api.nad-go.com/?action=login" \
  -H "Content-Type: application/json" -H "X-API-Key: FRONTEND_KEY" \
  -d '{"email":"jane@example.com","password":"hunter2pass"}'
```

**Step 1 — create order** (returns `tracking_token`). Send the `X-Session-Token`
from register/login. Fields mirror the checkout form; `apartment`/`image_url` are
optional, totals computed from items if omitted. (For inline guest checkout,
omit the session header and include `password` in the body instead.)
```bash
curl -X POST "https://api.nad-go.com/?action=create_order" \
  -H "Content-Type: application/json" -H "X-API-Key: FRONTEND_KEY" \
  -H "X-Session-Token: SESSION_TOKEN" \
  -d '{
    "website":"",
    "email":"jane@example.com", "phone":"+44 7700 900000",
    "first_name":"Jane", "last_name":"Doe",
    "address":"1 Market St", "apartment":"Flat 2",
    "city":"London", "postcode":"E1 6AN", "country":"United Kingdom",
    "currency":"GBP", "payment_method":"bank",
    "subtotal":297.50, "shipping":0, "tax":59.50, "tax_rate":20, "total":357.00,
    "items":[
      {"product_name":"NadGo Injection Pen","dosage":"500mg",
       "purchase_type":"Subscribe & Save","sku":"NADGO-500-SUB","price":148.75,"quantity":2},
      {"product_name":"NadGo Injection Pen","dosage":"1000mg",
       "purchase_type":"One-time purchase","sku":"NADGO-1000","price":245.00,"quantity":1}
    ]
  }'
```
Required: `email`, `phone`, `first_name`, `last_name`, `address`, `city`,
`postcode`, `country`, and at least one item with `product_name` + `price`.

Each item may carry the two variant axes — `dosage` (e.g. `500mg`) and
`purchase_type` (e.g. `Subscribe & Save` / `One-time purchase`). They're stored
separately and also combined into a display `variant` label automatically (you
can override by sending `variant` explicitly). `sku` and `image_url` are optional.

**Step 2 — payment instructions** for that order:
```bash
curl "https://api.nad-go.com/?action=payment_info&ref=NG-XXXX" \
  -H "X-Tracking-Token: <tracking_token>"
```

**Step 2 — submit proof** (crypto tx-id and/or receipt image):
```bash
curl -X POST "https://api.nad-go.com/?action=submit_payment&ref=NG-XXXX" \
  -H "X-Tracking-Token: <tracking_token>" \
  -F "method=crypto" -F "tx_id=0xabc123..." -F "receipt=@/path/receipt.png"
```

**Tracking portal** — log in, then list orders:
```bash
# login (above) gives you a session_token, then:
curl -X POST "https://api.nad-go.com/?action=track_orders" \
  -H "X-Session-Token: <session_token>"
```

**Admin** — verify payment / set delivery / view receipt:
```bash
curl -X POST "https://api.nad-go.com/?action=admin_verify_payment" \
  -H "Content-Type: application/json" -H "X-Admin-Key: ADMIN_KEY" \
  -d '{"ref":"NG-XXXX","decision":"verified"}'

curl -X POST "https://api.nad-go.com/?action=admin_update_delivery" \
  -H "Content-Type: application/json" -H "X-Admin-Key: ADMIN_KEY" \
  -d '{"ref":"NG-XXXX","delivery_status":"shipped","shipping_carrier":"DHL","shipping_tracking_no":"123"}'

curl "https://api.nad-go.com/?action=admin_get_receipt&payment_id=5" -H "X-Admin-Key: ADMIN_KEY"
```

## Security notes

- **Receipts & card data:** receipt files live outside the web path and are only
  streamed to admins. No card numbers/CVV are ever stored — payment is by bank
  transfer or crypto.
- **`X-API-Key` in the browser:** if `create_order` is called from the static
  nad-go.com bundle, the key is visible to anyone viewing the site. Prefer
  calling it from a server/SSR layer; otherwise treat it as a public throttle
  key and add rate limiting / captcha.
- **Passwords:** stored as bcrypt hashes (`password_hash`/`password_verify`),
  never plaintext. Login failures return a generic "Incorrect email or password"
  and are rate-limited to deter brute force.
- **CORS:** browser calls are allowed only from `cors_allowed_origins` in config.

## Tests

`php -l` passes on all files. Auth logic (signed session tokens, OTP hashing,
key checks) is covered by a quick runtime self-test; run it with PHP CLI if you
want to re-verify after edits. Full end-to-end requires the live MySQL + SMTP on
cPanel — use the `curl` examples above.
