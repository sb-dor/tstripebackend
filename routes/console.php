<?php

use App\Console\Commands\ReconcileStripePayments;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Commands
|--------------------------------------------------------------------------
|
| stripe:reconcile — safety net for missed webhook events.
| Stripe retries webhooks for 72 h; this catches anything beyond that window.
|
| To run locally:  php artisan schedule:work
| Production cron: * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
|
*/
Schedule::command(ReconcileStripePayments::class, ['--hours' => 72])
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
