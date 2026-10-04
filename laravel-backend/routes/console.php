<?php

use Illuminate\Support\Facades\Schedule;

// Replaces the Vercel Cron trigger for POST /api/youtube/cron-sync.
// The HTTP endpoint remains available for external schedulers.
Schedule::command('roi:youtube-sync')->everySixHours();

// Monthly pledge collection (spec §11/§19). Billing runs each morning at
// 09:00 in the supporter's billing timezone (Nairobi); reminders run hourly
// (idempotent via the email ledger, batched per PLEDGE_EMAIL_BATCH_SIZE).
Schedule::command('roi:pledges-bill')
    ->dailyAt('09:00')
    ->timezone((string) config('roi.pledge_timezone'))
    ->withoutOverlapping();

Schedule::command('roi:pledges-remind')
    ->hourlyAt(5)
    ->timezone((string) config('roi.pledge_timezone'))
    ->withoutOverlapping();
