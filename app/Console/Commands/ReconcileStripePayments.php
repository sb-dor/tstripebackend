<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\PaymentIntent as PaymentIntentModel;
use Illuminate\Console\Command;
use Stripe\StripeClient;

class ReconcileStripePayments extends Command
{
    protected $signature   = 'stripe:reconcile {--hours=72 : How many hours back to look}';
    protected $description = 'Reconcile missed Stripe webhook events (payment_intent.succeeded / failed).';

    /**
     * Query Stripe for recent events that our webhook may have missed (e.g. server was down).
     * Stripe retries webhooks for 72 h; this command covers any gap beyond that.
     *
     * Run on deploy and via the scheduler (see routes/console.php).
     */
    public function handle(): int
    {
        $hours  = (int) $this->option('hours');
        $since  = now()->subHours($hours)->timestamp;
        $stripe = new StripeClient(env('STRIPE_SECRET_KEY'));

        $this->info("Reconciling Stripe events from the last {$hours} hour(s)…");

        $processed = 0;

        foreach (['payment_intent.succeeded', 'payment_intent.payment_failed'] as $type) {
            $events = $stripe->events->all([
                'type'    => $type,
                'created' => ['gte' => $since],
            ]);

            foreach ($events->autoPagingIterator() as $event) {
                /** @var \Stripe\PaymentIntent $intent */
                $intent  = $event->data->object;
                $orderId = $intent->metadata->order_id ?? null;

                if (!$orderId) {
                    continue;
                }

                $order = Order::find($orderId);

                if (!$order || $order->status === 'paid') {
                    // Already handled — skip.
                    continue;
                }

                if ($type === 'payment_intent.succeeded') {
                    $order->update(['status' => 'paid']);
                    PaymentIntentModel::where('stripe_id', $intent->id)
                        ->update(['status' => 'succeeded']);

                    $this->line("  ✓ Order #{$orderId} → paid  (PI: {$intent->id})");
                } else {
                    $order->update(['status' => 'failed']);
                    PaymentIntentModel::where('stripe_id', $intent->id)
                        ->update(['status' => 'requires_payment_method']);

                    $this->line("  ✗ Order #{$orderId} → failed (PI: {$intent->id})");
                }

                $processed++;
            }
        }

        $this->info("Done. {$processed} order(s) reconciled.");

        return Command::SUCCESS;
    }
}
