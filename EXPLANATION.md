# Laravel Backend — Full Explanation

This document covers everything that was done in this Laravel project, why each decision was made, and how everything connects together.

---

## 1. Why does this backend exist?

In the Flutter app (`tstripe`), the first version called the Stripe API directly from the app using the secret key (`sk_test_...`). That works for testing, but it is dangerous in production because:

- Anyone can decompile the app and extract the secret key
- With the secret key, anyone can create unlimited PaymentIntents on your Stripe account
- The server has no control over the amount — a user could modify the request and pay whatever they want

The solution is a backend server. The secret key lives only on the server. The app only needs the publishable key (`pk_test_...`). Here is the difference:

**Without backend (direct mode):**
```
Flutter app  ──── sk_test_ key ──▶  Stripe API   (dangerous: key is in the app)
```

**With backend (this project):**
```
Flutter app  ──▶  Laravel server  ──── sk_test_ key ──▶  Stripe API
                  (key lives here,                        (safe: key never leaves server)
                   never sent to app)
```

---

## 2. Project creation

```bash
cd ~/Desktop/laravels
composer create-project laravel/laravel tstripe-backend
```

This creates a fresh Laravel 11 project. Laravel is a PHP framework that makes it easy to build APIs, handle routing, connect to databases, and manage validation.

---

## 3. Stripe PHP package

```bash
composer require stripe/stripe-php
```

This installs the official Stripe PHP SDK (`stripe/stripe-php`). It provides `StripeClient` to call the Stripe API and `Webhook` to verify webhook signatures.

Without this package, you would have to write raw HTTP calls to the Stripe API manually. The SDK handles authentication, serialization, and errors automatically.

---

## 4. `.env` configuration

The `.env` file holds environment-specific secrets that must never be committed to version control. Key changes made:

```env
APP_NAME=TStripeBackend
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tstripe
DB_USERNAME=root
DB_PASSWORD=          # XAMPP default is empty

STRIPE_SECRET_KEY=sk_test_...       # from Stripe Dashboard → Developers → API keys
STRIPE_WEBHOOK_SECRET=whsec_...     # from `stripe listen` output (development)
```

### Why MySQL instead of SQLite?

Laravel 11 defaults to SQLite for simplicity, but XAMPP runs MySQL. XAMPP is a local server stack (Apache + MySQL + PHP) — changing `DB_CONNECTION` to `mysql` makes Laravel connect to the XAMPP MySQL server instead of an SQLite file.

### Where `STRIPE_SECRET_KEY` comes from

Stripe Dashboard → Developers → API keys → Secret key (`sk_test_...`).

This key is what allows the server to create PaymentIntents on Stripe. It must only exist on the server — never in the app.

### Where `STRIPE_WEBHOOK_SECRET` comes from

When you run `stripe listen --forward-to ...`, the CLI prints a `whsec_...` secret at startup. This is used to verify that webhook events actually came from Stripe (not from someone faking a request). More on this in section 8.

---

## 5. Sanctum authentication

Laravel Sanctum provides simple token-based API authentication. It was added to support user login and registration so that the products list and cart checkout are protected endpoints.

### Installation

```bash
composer require laravel/sanctum
php artisan install:api   # publishes Sanctum config + migration, adds HasApiTokens trait
php artisan migrate
```

### `User` model changes

`HasApiTokens` was added to the `User` model:

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable {
    use HasApiTokens, HasFactory, Notifiable;
    protected $fillable = ['name', 'email', 'password'];
}
```

`HasApiTokens` is what gives the user model the ability to create and revoke API tokens (`createToken`, `tokens()->delete()`).

### `AuthController`

Two public endpoints:

**`POST /api/auth/register`** — creates a new account
```
Body: { "name": "Alex", "email": "alex@example.com", "password": "secret123", "password_confirmation": "secret123" }
→ validates uniqueness of email
→ hashes the password with Hash::make()
→ creates user
→ creates a Sanctum token
→ returns { user: { id, name, email }, token }  (HTTP 201)
```

**`POST /api/auth/login`** — authenticates an existing account
```
Body: { "email": "alex@example.com", "password": "secret123" }
→ finds user by email
→ checks password with Hash::check()
→ revokes all previous tokens (one active session per user)
→ creates a fresh Sanctum token
→ returns { user: { id, name, email }, token }  (HTTP 200)
→ returns 401 if credentials are wrong
```

**`POST /api/auth/logout`** — protected, revokes the current token
```
Header: Authorization: Bearer <token>
→ $request->user()->currentAccessToken()->delete()
→ returns { message: "Logged out" }
```

### How Sanctum tokens work in API calls

After login or register, the app receives a plain-text token. Every subsequent request to a protected endpoint must include:
```
Authorization: Bearer <token>
```

Protected routes are wrapped in `auth:sanctum` middleware:
```php
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout',     [AuthController::class, 'logout']);
    Route::get('/products',         [ProductController::class, 'index']);
    Route::post('/orders/checkout', [OrderController::class, 'checkout']);
});
```

If the token is missing or invalid, Laravel returns `401 Unauthenticated` automatically — no code needed.

---

## 5a. Products

### Migration

```
id            — auto-incrementing primary key
name          — product name
description   — short description
price         — decimal(8,2) — e.g. 9.99
image_url     — nullable string
created_at / updated_at
```

### `Product` model

```php
protected $fillable = ['name', 'description', 'price', 'image_url'];
protected $casts    = ['price' => 'float'];
```

The `price` cast ensures the value comes out as a PHP float, not a string, when reading from the DB.

### Seeder — 15 products

```bash
php artisan make:seeder ProductSeeder
php artisan db:seed --class=ProductSeeder
```

15 electronics/accessories products with prices ranging from $4.99 to $99.99 are inserted. Run this once after migrating.

### `ProductController`

```php
public function index(): JsonResponse
{
    return response()->json(Product::all());
}
```

Protected by `auth:sanctum`. Returns the full products list as a JSON array. The Flutter app receives this and displays it in a grid.

---

## 5b. Database schema

### `orders` table (extended)

```
id                        — auto-incrementing primary key
user_id                   — foreign key → users.id (nullable, nullOnDelete)
amount                    — payment amount in cents (e.g. 999 = $9.99)
currency                  — 3-letter currency code, default 'usd'
status                    — 'pending' → 'paid' or 'failed'
stripe_payment_intent_id  — Stripe's pi_xxx ID, saved after PaymentIntent is created
stripe_client_secret      — the client_secret returned to the app
created_at / updated_at   — Laravel auto-manages these timestamps
```

`user_id` was added in a separate migration after the initial schema. It links every order to the authenticated user — required for the idempotent checkout logic (see section 5c).

Why `nullOnDelete`? If a user account is deleted, their orders become anonymous rather than cascade-deleting — useful for keeping financial audit records.

**Why store `stripe_payment_intent_id` and `stripe_client_secret` on the order?**

- `stripe_payment_intent_id` is needed to look up the order when the webhook fires (`payment_intent.succeeded` tells you the `pi_xxx` ID, not the order ID — but we also pass `order_id` in metadata as a shortcut)
- `stripe_client_secret` lets you retrieve the existing secret if the user retries a failed payment without creating a new PaymentIntent

### 5c. Idempotent cart checkout — `OrderController`

This is the most important piece of business logic in the backend. The problem it solves:

> Every time the user taps "Checkout", should a new Order and PaymentIntent be created?

**No.** Creating a new Order on every tap means a user who opens the payment sheet and cancels 5 times ends up with 5 stale `pending` orders in the database. Worse, each cancellation creates a new Stripe PaymentIntent that will just expire.

The `OrderController::checkout` endpoint is idempotent — calling it multiple times with the same cart has the same effect as calling it once.

**Logic flow:**

```
POST /api/orders/checkout
Body: { "items": [{ "product_id": 1, "quantity": 2 }, ...] }

1. Validate items — product_id must exist in products table, quantity >= 1

2. Calculate total server-side
   → load product prices from DB
   → total = sum(price * quantity) in cents
   → client NEVER sends an amount — only product IDs and quantities

3. Look for an existing pending order for this user:
   Order::where('user_id', $user->id)->where('status', 'pending')->first()

4a. Pending order exists:
    → retrieve the existing PaymentIntent from Stripe
    → if intent status is NOT 'canceled' or 'succeeded' (still open):
        → if amount changed: update the PaymentIntent amount on Stripe
        → update order amount in DB
        → return the existing client_secret  ← no new Order row created
    → if intent was cancelled:
        → create a new PaymentIntent
        → update the order with new intent IDs
        → save audit record in payment_intents table

4b. No pending order:
    → create new Order (with user_id, amount, currency='usd', status='pending')
    → create new Stripe PaymentIntent with metadata: { order_id }
    → save intent IDs on order
    → save audit record in payment_intents table

5. Return:
   { id, client_secret, amount, currency, status }
   (same shape as Stripe's own API — Flutter's PaymentIntent.fromMap works unchanged)
```

**Why server-side total calculation matters:**

If the client sent the total, a malicious user could send `amount: 1` for a $50 cart. By loading prices from the DB and calculating the total on the server, the client has zero control over what they pay.

**Why reuse the PaymentIntent?**

Stripe PaymentIntents stay alive with status `requires_payment_method` when cancelled by the user. They can be retried with the same `client_secret`. Reusing them means:
- One order row per checkout session (clean DB)
- If the amount changed (user adjusted cart), Stripe allows updating the amount on an open intent
- A fresh intent is only created when the old one is truly gone (cancelled or succeeded)

### `payment_intents` table

```
id             — auto-incrementing primary key
order_id       — foreign key → orders.id (cascade delete)
stripe_id      — pi_xxx (Stripe's PaymentIntent ID)
client_secret  — pi_xxx_secret_xxx
status         — requires_payment_method | succeeded | canceled
created_at / updated_at
```

This table is an **audit log**. Every PaymentIntent created on Stripe is recorded here. It answers questions like "how many times did the user attempt to pay order 5?" or "what was the status of that payment last Tuesday?". It is not required for the basic flow but is good practice.

---

## 6. Models

Laravel models are PHP classes that represent a database table. Each model provides methods to query and save data without writing raw SQL.

### `Order` model

```php
protected $fillable = [
    'user_id', 'amount', 'currency', 'status',
    'stripe_payment_intent_id', 'stripe_client_secret',
];
```

`$fillable` lists which columns are allowed to be mass-assigned (e.g. `Order::create([...])` ). Without this, Laravel blocks mass assignment as a security measure.

The `paymentIntents()` relationship means you can call `$order->paymentIntents` to get all PaymentIntent records for that order.

### `PaymentIntent` model

The `order()` relationship means you can call `$paymentIntent->order` to get the linked order. The `order_id` foreign key enforces referential integrity — a payment intent cannot exist without an order.

---

## 7. API routes (`routes/api.php`)

Laravel 11 does not create `routes/api.php` by default — it was created manually and registered in `bootstrap/app.php`:

```php
// bootstrap/app.php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',   // ← added this line
    ...
)
```

The full route table:

```php
// Public routes (no token needed)
Route::post('/auth/register',        [AuthController::class, 'register']);
Route::post('/auth/login',           [AuthController::class, 'login']);
Route::post('/webhooks/stripe',      [PaymentController::class, 'stripeWebhook']);
Route::post('/create-payment',       [PaymentController::class, 'createPayment']);

// Protected routes (Sanctum token required)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout',      [AuthController::class, 'logout']);
    Route::get('/products',          [ProductController::class, 'index']);
    Route::post('/orders/checkout',  [OrderController::class, 'checkout']);
});
```

All routes in `routes/api.php` are automatically prefixed with `/api`, so the full URLs are e.g.:
- `POST http://your-server:8000/api/auth/login`
- `GET  http://your-server:8000/api/products`
- `POST http://your-server:8000/api/orders/checkout`

`/api/webhooks/stripe` and `/api/create-payment` remain public — the webhook is verified by its Stripe signature (not a user token), and `create-payment` is the legacy Quick Pay endpoint kept for backwards compatibility.

---

## 8. `PaymentController`

### `createPayment` method

This is called by the Flutter app when the user taps Pay.

**Step by step:**

```
1. Validate the request
   - amount_in_cents: required integer, minimum 50 (Stripe minimum is 50 cents)
   - currency: optional string, 3 characters, defaults to 'usd'

2. Create an Order in the database with status='pending'
   - The order exists in the DB before any Stripe call
   - If anything fails after this, the order record shows what was attempted

3. Call Stripe API to create a PaymentIntent
   $stripe = new StripeClient(env('STRIPE_SECRET_KEY'));
   $intent = $stripe->paymentIntents->create([
       'amount'   => $order->amount,
       'currency' => $order->currency,
       'payment_method_types' => ['card'],
       'metadata' => ['order_id' => $order->id],  // ← key: used in webhook lookup
   ]);

   The 'metadata' field is arbitrary key-value data attached to the PaymentIntent on Stripe.
   When the webhook fires, Stripe includes this metadata — so we can find our order.

4. Save the Stripe IDs on the order
   $order->update([
       'stripe_payment_intent_id' => $intent->id,
       'stripe_client_secret'     => $intent->client_secret,
   ]);

5. Save an audit record in payment_intents table

6. Return JSON to the app:
   {
       "id": "pi_3ABC...",
       "client_secret": "pi_3ABC..._secret_XYZ",
       "amount": 999,
       "currency": "usd",
       "status": "requires_payment_method"
   }
```

The response shape matches exactly what Stripe's own API returns, which means the `PaymentIntent.fromMap()` method in Flutter works without any changes.

The Flutter app takes the `client_secret` from this response and passes it to `flutter_stripe`, which presents the payment sheet. The secret key never touched the app.

### `stripeWebhook` method

This is called by Stripe (not the app) after a payment event occurs.

**Step by step:**

```
1. Read the raw request body BEFORE any parsing
   $payload = $request->getContent();

   This is critical — Stripe's signature is computed over the raw bytes.
   If Laravel parses the body first (e.g. into JSON), the raw bytes change and
   signature verification fails.

2. Get the Stripe-Signature header
   $sigHeader = $request->header('Stripe-Signature');

   Stripe attaches this header to every webhook. It contains a timestamp and
   an HMAC signature computed with your STRIPE_WEBHOOK_SECRET.

3. Verify the signature
   $event = Webhook::constructEvent($payload, $sigHeader, env('STRIPE_WEBHOOK_SECRET'));

   If the signature is invalid (wrong secret, or someone faked the request),
   this throws SignatureVerificationException → we return 400 Bad Request.
   If it's valid, $event contains the verified event data.

4. Handle the event type
   switch ($event->type) {
       case 'payment_intent.succeeded':
           → find the order via metadata.order_id
           → update order status to 'paid'
           → update payment_intent status to 'succeeded'

       case 'payment_intent.payment_failed':
           → update order status to 'failed'
           → update payment_intent status to 'requires_payment_method'
   }

5. Return 200 OK
   Stripe expects a 2xx response within 30 seconds. If it doesn't get one,
   it will retry the webhook up to 3 days.
```

**Why not just trust the app?**

The Flutter app calls `presentPaymentSheet()` and gets a "success" result. Why not just have the app tell the server "payment succeeded"?

Because anyone can send that POST request without actually paying. Stripe's webhook is signed with a secret that only Stripe and your server know — it cannot be faked. The webhook is the only trustworthy source of truth for whether money actually moved.

---

## 9. CORS configuration

CORS (Cross-Origin Resource Sharing) is a browser security mechanism. When the Flutter web app (running at `localhost:PORT`) makes a request to the Laravel server (at `localhost:8000`), the browser checks whether the server allows it.

`config/cors.php`:
```php
'paths' => ['api/*'],         // apply to all /api routes
'allowed_origins' => ['*'],   // allow any origin (fine for development)
'allowed_methods' => ['*'],   // GET, POST, PUT, DELETE, etc.
'allowed_headers' => ['*'],   // Content-Type, Accept, Authorization, etc.
```

This only affects Flutter Web. Native iOS and Android apps are not browsers and do not perform CORS checks — they can call any server freely.

In production, replace `'*'` with your actual domain:
```php
'allowed_origins' => ['https://your-app.com'],
```

---

## 10. Running the server

### Prerequisites

1. XAMPP → start **MySQL** in the XAMPP control panel
2. Open phpMyAdmin (`http://localhost/phpmyadmin`) → create a database called `tstripe`
3. Fill in the Stripe secret key in `.env`

### Run migrations

```bash
cd ~/Desktop/laravels/tstripe-backend
php artisan migrate
```

This executes the two migration files and creates the `orders` and `payment_intents` tables in MySQL.

### Start the server

**For local testing (app on same machine):**
```bash
php artisan serve
# Listens on http://127.0.0.1:8000
```

**For device testing (app on phone/tablet on same Wi-Fi):**
```bash
php artisan serve --host=0.0.0.0 --port=8000
# Listens on all network interfaces — accessible at http://192.168.x.x:8000
```

`0.0.0.0` means "accept connections on all network interfaces", not just localhost. Without this, a device on the same Wi-Fi cannot reach the server even if they share the same router.

---

## 11. Stripe CLI and webhooks in development

Stripe's servers cannot reach `localhost`. So during development, you use the Stripe CLI as a tunnel:

```bash
stripe listen --forward-to http://192.168.100.93:8000/api/webhooks/stripe
```

What happens:
```
Stripe fires: payment_intent.succeeded
      ↓
Stripe CLI (running on your machine, persistent outbound connection)
      ↓
Re-posts the event to: http://192.168.100.93:8000/api/webhooks/stripe
      ↓
Laravel handles it and updates the DB
```

The CLI also prints a `whsec_...` secret at startup — paste that into `.env` as `STRIPE_WEBHOOK_SECRET`.

**You must keep `stripe listen` running** for webhooks to work. If you kill it, payments still process (the Stripe sheet works), but the order status in the DB will stay `pending` forever.

**Keep two terminals open:**
```
Terminal 1:  php artisan serve --host=0.0.0.0 --port=8000
Terminal 2:  stripe listen --forward-to http://192.168.100.93:8000/api/webhooks/stripe
```

In production, Stripe sends webhooks directly to your public URL — no CLI needed.

---

## 12. Testing with curl

Before connecting Flutter, you can test the endpoint directly:

```bash
curl -X POST http://localhost:8000/api/create-payment \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"amount_in_cents": 999, "currency": "usd"}'
```

Expected response:
```json
{
  "id": "pi_3ABC...",
  "client_secret": "pi_3ABC..._secret_XYZ",
  "amount": 999,
  "currency": "usd",
  "status": "requires_payment_method"
}
```

---

## 13. Full payment flow with this backend

```
1. User enters amount in Flutter app and taps Pay

2. Flutter calls POST /api/create-payment
   { "amount_in_cents": 999, "currency": "usd" }

3. Laravel:
   - Creates Order in DB (status=pending)
   - Calls Stripe API with sk_test_ key
   - Saves stripe_payment_intent_id on order
   - Returns { client_secret: "pi_xxx_secret_xxx" }

4. Flutter receives client_secret
   - Calls Stripe.instance.initPaymentSheet(paymentIntentClientSecret: secret)
   - Calls Stripe.instance.presentPaymentSheet()
   - User sees card form, enters 4242 4242 4242 4242

5. Stripe processes the payment

6. Stripe fires webhook: payment_intent.succeeded
   → Laravel receives it at POST /api/webhooks/stripe
   → Verifies signature with whsec_ secret
   → Finds order via metadata.order_id
   → Updates order status to 'paid'

7. Flutter shows "Payment successful!" (from presentPaymentSheet result)
```

The app and server each get their own confirmation independently:
- Flutter knows from `presentPaymentSheet()` → shows success UI
- Laravel knows from the webhook → marks order paid in DB, can send email, trigger fulfillment, etc.

---

## 14. File structure overview

```
tstripe-backend/
├── app/
│   ├── Console/Commands/
│   │   └── ReconcileStripePayments.php  ← safety net: sync missed webhook events from Stripe
│   ├── Http/Controllers/
│   │   ├── AuthController.php        ← register, login, logout (Sanctum)
│   │   ├── PaymentController.php     ← createPayment + stripeWebhook
│   │   ├── ProductController.php     ← index (returns all products)
│   │   └── OrderController.php       ← checkout (idempotent cart checkout)
│   └── Models/
│       ├── User.php                  ← HasApiTokens added for Sanctum
│       ├── Product.php               ← products table model
│       ├── Order.php                 ← orders table model (with user_id)
│       └── PaymentIntent.php         ← payment_intents table model (audit log)
├── bootstrap/
│   └── app.php                       ← registers routes/api.php
├── config/
│   └── cors.php                      ← allows cross-origin requests from Flutter web
├── database/
│   ├── migrations/
│   │   ├── ..._create_orders_table.php
│   │   ├── ..._create_payment_intents_table.php
│   │   ├── ..._create_products_table.php
│   │   └── ..._add_user_id_to_orders_table.php
│   └── seeders/
│       └── ProductSeeder.php         ← 15 sample products
├── routes/
│   ├── api.php                       ← all API routes (public + Sanctum-protected)
│   └── console.php                   ← scheduled commands (stripe:reconcile hourly)
└── .env                              ← DB credentials, STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET
```

---

## 15. How does Stripe know where to send the webhook?

Stripe doesn't know automatically — you have to tell it. There are two ways depending on whether you are in development or production.

### Development — Stripe CLI

When you run:
```bash
stripe listen --forward-to http://192.168.100.93:8000/api/webhooks/stripe
```

The CLI connects to Stripe's servers and registers your local URL **temporarily** for that session. Stripe sends all test events through the CLI tunnel to your machine. When you kill the CLI, Stripe stops sending.

### Production — Stripe Dashboard

You register your public URL once manually:
```
Stripe Dashboard
  → Developers
    → Webhooks
      → Add endpoint
        → URL: https://your-server.com/api/webhooks/stripe
        → Select events: payment_intent.succeeded, payment_intent.payment_failed
```

Stripe saves that URL permanently and sends matching events directly to your server — no CLI needed.

| | Development | Production |
|---|---|---|
| How Stripe knows the URL | `stripe listen` registers it temporarily | You register it permanently in Dashboard |
| Who forwards the event | Stripe CLI (tunnel) | Stripe directly |
| Stays registered | Only while CLI is running | Permanently |
| Webhook secret | Printed by CLI each run | Copied once from Dashboard |

---

## 16. How does Stripe know to send the webhook to YOUR server and not someone else's?

This is tied entirely to the **secret key**.

When your server calls Stripe with `sk_test_...`, Stripe identifies which Stripe account that key belongs to. The PaymentIntent is created **inside your account** — not in any global pool.

```
Your sk_test_ key  →  identifies your Stripe account  →  PaymentIntent stored there
```

When that PaymentIntent is paid, Stripe fires the webhook to the webhook URLs registered on **your account only**:

```
Your Stripe account
  ├── has your PaymentIntent (pi_xxx)
  ├── has your webhook URL registered
  └── when pi_xxx succeeds → fires webhook to YOUR URL only
```

If somebody else has a different Stripe account — their payments go to their webhooks, yours go to yours. The accounts are completely separate. Nobody else sees your events.

This is also why the secret key must never leave your server. Whoever has your `sk_test_` key can create PaymentIntents that bill YOUR account and receive YOUR webhooks.

### The role of `metadata`

When Laravel creates the PaymentIntent, it attaches your order ID:

```php
$stripe->paymentIntents->create([
    'amount'   => $order->amount,
    'currency' => $order->currency,
    'metadata' => ['order_id' => $order->id],  // ← stored on Stripe's side
]);
```

Stripe stores this metadata with the PaymentIntent. When the webhook fires, Stripe sends the full PaymentIntent back — including your metadata. This is how your server knows which order to mark as paid:

```
Webhook arrives:
{
  "type": "payment_intent.succeeded",
  "data": {
    "id": "pi_xxx",
    "metadata": { "order_id": "5" }   ← your data comes back
  }
}

→ find Order #5 in DB → update status to 'paid'
```

---

## 17. What is `STRIPE_WEBHOOK_SECRET` for?

Your webhook endpoint `POST /api/webhooks/stripe` is a public URL. Anyone on the internet can send a POST request to it — including attackers trying to fake a payment event.

**Without verification:**
```
Attacker sends:
POST /api/webhooks/stripe
{ "type": "payment_intent.succeeded", "data": { "metadata": { "order_id": "5" } } }

→ Server marks order 5 as paid
→ Attacker gets the product for free, without paying anything
```

**`STRIPE_WEBHOOK_SECRET` prevents this.**

When Stripe sends a real webhook, it computes an HMAC signature over the request body using your `STRIPE_WEBHOOK_SECRET` and attaches it as a header:

```
Stripe-Signature: t=1234567890,v1=abc123xyz...
```

Your server verifies it:
```php
Webhook::constructEvent($payload, $sigHeader, env('STRIPE_WEBHOOK_SECRET'));
```

This asks: *"Was this request signed with our shared secret?"*

```
Real Stripe request  →  signature matches  →  process the event
Fake request         →  signature wrong/missing  →  return 400, ignore it
```

Only Stripe knows your `STRIPE_WEBHOOK_SECRET` — so only Stripe can produce a valid signature. An attacker can copy the JSON body but cannot fake the signature without the secret.

### The two keys and what they each prove

| Key | Direction | Proves |
|---|---|---|
| `STRIPE_SECRET_KEY` | Your server → Stripe | That YOU are making a request to Stripe |
| `STRIPE_WEBHOOK_SECRET` | Stripe → Your server | That STRIPE is making a request to you |

They solve opposite directions of trust. Together they make the communication between your server and Stripe completely verified in both directions.

---

## 18. What if the server is down when a payment succeeds?

This is one of the most important edge cases in any payment system.

**The scenario:**
```
1. Client gets a PaymentIntent client_secret from your server   ✓
2. Your server crashes / goes down
3. Client completes payment — Stripe processes it successfully   ✓
4. Stripe fires: payment_intent.succeeded → but your server is down
5. Webhook delivery fails
6. Order stays 'pending' in DB forever                          ✗
```

### Layer 1 — Stripe automatically retries webhooks

Stripe does not give up after one failed delivery. It retries the webhook with exponential backoff for **up to 72 hours**. If your server comes back online within that window, the event will arrive and the order will be updated automatically — no manual action needed.

```
Server down for 30 minutes → Stripe retries → server back → webhook arrives → order marked paid ✓
```

### Layer 2 — Reconciliation command (safety net)

If your server was down longer than 72 hours, or you want to recover immediately after a restart without waiting for the next retry, use the reconciliation command:

```bash
php artisan stripe:reconcile
```

This command (`app/Console/Commands/ReconcileStripePayments.php`) queries the **Stripe Events API** directly for all `payment_intent.succeeded` and `payment_intent.payment_failed` events from the last 72 hours and updates any orders that are still `pending`:

```
Stripe Events API  →  list all succeeded/failed events since N hours ago
                   →  for each event: find order via metadata.order_id
                   →  if order is still 'pending' → update to 'paid' or 'failed'
```

Options:
```bash
php artisan stripe:reconcile              # default: last 72 hours
php artisan stripe:reconcile --hours=1   # last 1 hour only
php artisan stripe:reconcile --hours=168 # last 7 days
```

The command is **idempotent** — orders already marked `paid` are skipped, so running it multiple times is safe.

### Layer 3 — Hourly scheduler

The reconciliation command is also scheduled to run **every hour automatically** (configured in `routes/console.php`):

```php
Schedule::command(ReconcileStripePayments::class, ['--hours' => 72])
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
```

To activate the scheduler in production, add one cron entry to your server:
```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

To run it locally for testing:
```bash
php artisan schedule:work   # keeps scheduler running, fires commands on their intervals
php artisan schedule:run    # fires any commands due right now (one-shot)
```

### The complete safety net

| Layer | Mechanism | Window |
|---|---|---|
| 1 | Stripe webhook retries | Up to 72 hours automatically |
| 2 | `stripe:reconcile` on deploy | Run manually after recovery |
| 3 | Hourly scheduler | Ongoing background safety net |

### Why not just trust the client?

When `presentPaymentSheet()` succeeds in Flutter, the app knows the payment went through. Why not just have the app call `POST /api/orders/confirm`?

Because anyone can send that POST request without paying. The Stripe Events API and webhook signature are the only tamper-proof sources of truth for whether money actually moved. The client confirmation is useful for UX (showing a success screen) but must never be used to mark an order as paid on the server.
