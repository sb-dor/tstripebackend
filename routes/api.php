<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Public:
|   POST /api/auth/register  — register with name, email, password
|   POST /api/auth/login     — login with email + password
|   POST /api/create-payment          — direct Stripe call (no-backend test mode)
|   POST /api/webhooks/stripe         — Stripe webhook events
|
| Protected (Sanctum token required):
|   POST /api/auth/logout
|   GET  /api/products
|   POST /api/orders/checkout         — cart checkout, creates order + PaymentIntent
|
*/

// Auth (public)
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login',    [AuthController::class, 'login']);

// Stripe webhook — verified by signature inside controller, no auth middleware
Route::post('/webhooks/stripe', [PaymentController::class, 'stripeWebhook']);

// Direct payment (no-backend test mode, kept for backwards compat)
Route::post('/create-payment', [PaymentController::class, 'createPayment']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout',     [AuthController::class, 'logout']);
    Route::get('/products',         [ProductController::class, 'index']);
    Route::post('/orders/checkout', [OrderController::class, 'checkout']);
});
