<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentIntent as PaymentIntentModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

class PaymentController extends Controller
{
    /**
     * Create a Stripe PaymentIntent and return the client_secret to the app.
     *
     * The Flutter app calls this endpoint instead of calling Stripe directly,
     * so the secret key never leaves the server.
     *
     * POST /api/create-payment
     * Body: { "amount_in_cents": 999, "currency": "usd" }
     */
    public function createPayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount_in_cents' => ['required', 'integer', 'min:50'],
            'currency'        => ['sometimes', 'string', 'size:3'],
        ]);

        $currency = $data['currency'] ?? 'usd';

        // 1. Create a pending order in the database
        $order = Order::create([
            'amount'   => $data['amount_in_cents'],
            'currency' => $currency,
            'status'   => 'pending',
        ]);

        // 2. Create a PaymentIntent on Stripe (secret key stays here on the server)
        $stripe = new StripeClient(env('STRIPE_SECRET_KEY'));

        $intent = $stripe->paymentIntents->create([
            'amount'                    => $order->amount,
            'currency'                  => $order->currency,
            'payment_method_types'      => ['card'],
            'metadata'                  => ['order_id' => $order->id],
        ]);

        // 3. Save the Stripe IDs on the order for webhook lookup later
        $order->update([
            'stripe_payment_intent_id' => $intent->id,
            'stripe_client_secret'     => $intent->client_secret,
        ]);

        // 4. Save audit log entry
        PaymentIntentModel::create([
            'order_id'      => $order->id,
            'stripe_id'     => $intent->id,
            'client_secret' => $intent->client_secret,
            'status'        => $intent->status,
        ]);

        // 5. Return the same shape as a raw Stripe PaymentIntent response,
        //    so PaymentIntent.fromMap() in Flutter works without changes.
        return response()->json([
            'id'            => $intent->id,
            'client_secret' => $intent->client_secret,
            'amount'        => $intent->amount,
            'currency'      => $intent->currency,
            'status'        => $intent->status,
        ]);
    }

    /**
     * Handle Stripe webhook events.
     *
     * Stripe calls this endpoint after a payment succeeds or fails.
     * The signature is verified with STRIPE_WEBHOOK_SECRET so we can trust the event.
     *
     * POST /api/webhooks/stripe
     */
    public function stripeWebhook(Request $request): JsonResponse
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                env('STRIPE_WEBHOOK_SECRET')
            );
        } catch (SignatureVerificationException $e) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $intentData = $event->data->object;

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $orderId = $intentData->metadata->order_id ?? null;
                if ($orderId) {
                    Order::where('id', $orderId)->update(['status' => 'paid']);
                    PaymentIntentModel::where('stripe_id', $intentData->id)
                        ->update(['status' => 'succeeded']);
                }
                break;

            case 'payment_intent.payment_failed':
                $orderId = $intentData->metadata->order_id ?? null;
                if ($orderId) {
                    Order::where('id', $orderId)->update(['status' => 'failed']);
                    PaymentIntentModel::where('stripe_id', $intentData->id)
                        ->update(['status' => 'requires_payment_method']);
                }
                break;
        }

        return response()->json(['received' => true]);
    }
}
