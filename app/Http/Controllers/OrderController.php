<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentIntent as PaymentIntentModel;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\StripeClient;

class OrderController extends Controller
{
    /**
     * Idempotent cart checkout.
     *
     * - If the user has no pending order → create order + PaymentIntent.
     * - If a pending order exists → update its amount and reuse the PaymentIntent
     *   (or create a new one if the old intent was cancelled).
     *
     * POST /api/orders/checkout
     * Body: { "items": [{ "product_id": 1, "quantity": 2 }, ...] }
     */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity'   => ['required', 'integer', 'min:1'],
        ]);

        // ── 1. Calculate total server-side ──────────────────────────────────
        $productIds = collect($data['items'])->pluck('product_id');
        $products   = Product::whereIn('id', $productIds)->get()->keyBy('id');

        $totalCents = 0;
        foreach ($data['items'] as $item) {
            $product     = $products[$item['product_id']];
            $totalCents += (int) round($product->price * 100) * $item['quantity'];
        }

        if ($totalCents < 50) {
            return response()->json(['message' => 'Order total is too low.'], 422);
        }

        $stripe = new StripeClient(env('STRIPE_SECRET_KEY'));
        $user   = $request->user();

        // ── 2. Look for an existing pending order for this user ─────────────
        $order = Order::where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($order) {
            // ── 3a. Pending order found — try to reuse the PaymentIntent ────
            $intent = null;

            if ($order->stripe_payment_intent_id) {
                $existing = $stripe->paymentIntents->retrieve($order->stripe_payment_intent_id);

                // Reuse if the intent is still open (not yet captured/cancelled)
                if (!in_array($existing->status, ['canceled', 'succeeded'])) {
                    // Update amount on Stripe if it changed
                    if ($existing->amount !== $totalCents) {
                        $existing = $stripe->paymentIntents->update(
                            $order->stripe_payment_intent_id,
                            ['amount' => $totalCents]
                        );
                    }
                    $intent = $existing;
                }
            }

            // If the old intent is gone/cancelled, create a fresh one
            if (!$intent) {
                $intent = $stripe->paymentIntents->create([
                    'amount'               => $totalCents,
                    'currency'             => $order->currency,
                    'payment_method_types' => ['card'],
                    'metadata'             => ['order_id' => $order->id],
                ]);

                PaymentIntentModel::create([
                    'order_id'      => $order->id,
                    'stripe_id'     => $intent->id,
                    'client_secret' => $intent->client_secret,
                    'status'        => $intent->status,
                ]);
            }

            // Update order with latest amount + intent IDs
            $order->update([
                'amount'                   => $totalCents,
                'stripe_payment_intent_id' => $intent->id,
                'stripe_client_secret'     => $intent->client_secret,
            ]);

            return response()->json([
                'id'            => $intent->id,
                'client_secret' => $intent->client_secret,
                'amount'        => $intent->amount,
                'currency'      => $intent->currency,
                'status'        => $intent->status,
            ]);
        }

        // ── 3b. No pending order — create a fresh order + PaymentIntent ─────
        $order = Order::create([
            'user_id'  => $user->id,
            'amount'   => $totalCents,
            'currency' => 'usd',
            'status'   => 'pending',
        ]);

        $intent = $stripe->paymentIntents->create([
            'amount'               => $order->amount,
            'currency'             => $order->currency,
            'payment_method_types' => ['card'],
            'metadata'             => ['order_id' => $order->id],
        ]);

        $order->update([
            'stripe_payment_intent_id' => $intent->id,
            'stripe_client_secret'     => $intent->client_secret,
        ]);

        PaymentIntentModel::create([
            'order_id'      => $order->id,
            'stripe_id'     => $intent->id,
            'client_secret' => $intent->client_secret,
            'status'        => $intent->status,
        ]);

        return response()->json([
            'id'            => $intent->id,
            'client_secret' => $intent->client_secret,
            'amount'        => $intent->amount,
            'currency'      => $intent->currency,
            'status'        => $intent->status,
        ]);
    }
}
