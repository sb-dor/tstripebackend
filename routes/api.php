<?php

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| POST /api/create-payment   — creates a Stripe PaymentIntent server-side
| POST /api/webhooks/stripe  — receives Stripe webhook events
|
*/

Route::post('/create-payment', [PaymentController::class, 'createPayment']);

// Webhook route — signature is verified inside the controller,
// so it does not need any additional auth middleware.
Route::post('/webhooks/stripe', [PaymentController::class, 'stripeWebhook']);
