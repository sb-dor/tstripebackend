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

## 5. Database schema

Two tables were created.

### `orders` table

```
id                        — auto-incrementing primary key
amount                    — payment amount in cents (e.g. 999 = $9.99)
currency                  — 3-letter currency code, default 'usd'
status                    — 'pending' → 'paid' or 'failed'
stripe_payment_intent_id  — Stripe's pi_xxx ID, saved after PaymentIntent is created
stripe_client_secret      — the client_secret returned to the app
created_at / updated_at   — Laravel auto-manages these timestamps
```

**Why store `stripe_payment_intent_id` and `stripe_client_secret` on the order?**

- `stripe_payment_intent_id` is needed to look up the order when the webhook fires (`payment_intent.succeeded` tells you the `pi_xxx` ID, not the order ID — but we also pass `order_id` in metadata as a shortcut)
- `stripe_client_secret` lets you retrieve the existing secret if the user retries a failed payment without creating a new PaymentIntent

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
    'amount', 'currency', 'status',
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

The two routes:

```php
Route::post('/create-payment', [PaymentController::class, 'createPayment']);
Route::post('/webhooks/stripe', [PaymentController::class, 'stripeWebhook']);
```

Both are `POST` because:
- `create-payment` receives data from the app and creates a resource
- `webhooks/stripe` receives events from Stripe

All routes in `routes/api.php` are automatically prefixed with `/api`, so the full URLs are:
- `POST http://your-server:8000/api/create-payment`
- `POST http://your-server:8000/api/webhooks/stripe`

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
│   ├── Http/Controllers/
│   │   └── PaymentController.php     ← createPayment + stripeWebhook
│   └── Models/
│       ├── Order.php                 ← orders table model
│       └── PaymentIntent.php         ← payment_intents table model
├── bootstrap/
│   └── app.php                       ← registers routes/api.php
├── config/
│   └── cors.php                      ← allows cross-origin requests from Flutter web
├── database/migrations/
│   ├── ..._create_orders_table.php
│   └── ..._create_payment_intents_table.php
├── routes/
│   └── api.php                       ← POST /api/create-payment, POST /api/webhooks/stripe
└── .env                              ← DB credentials, STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET
```
