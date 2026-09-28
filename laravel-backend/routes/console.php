<?php

use Illuminate\Support\Facades\Schedule;

// Replaces the Vercel Cron trigger for POST /api/youtube/cron-sync.
// The HTTP endpoint remains available for external schedulers.
Schedule::command('roi:youtube-sync')->everySixHours();
