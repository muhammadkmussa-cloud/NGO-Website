<?php

use App\Support\PledgeConfig;

return [
    'project_name' => env('PROJECT_NAME', 'Demo NGO (DEMO) API'),
    'project_version' => env('PROJECT_VERSION', '2.0.0'),

    // Lifecycle switch: development / test enables seeding, sandbox payments, webhook-sig bypass
    'environment' => env('ENVIRONMENT', 'production'),

    // M-2: dev/test payment conveniences (sandbox checkouts, unsigned webhooks,
    // gateway-free completion) require this EXPLICIT opt-in — ENVIRONMENT alone
    // is no longer sufficient. Refused outright in production.
    'allow_dev_payment_bypasses' => env('ALLOW_DEV_PAYMENT_BYPASSES', false),

    // Operational kill switch for all new paid transactions. Complimentary
    // tickets remain available; paid donation/ticket initiation fails closed.
    'payments_enabled' => filter_var(env('PAYMENTS_ENABLED', false), FILTER_VALIDATE_BOOL),

    // Fail-open email realism check on new ticket checkouts: disposable-domain
    // blocklist, then MX/A lookup with a best-effort SMTP mailbox probe.
    // Uncertain outcomes always allow the purchase.
    'email_existence_check' => filter_var(env('EMAIL_EXISTENCE_CHECK', true), FILTER_VALIDATE_BOOL),

    // Security & Single Admin Slot Auth
    'jwt_secret_key' => env('JWT_SECRET_KEY'),
    'jwt_algorithm' => env('JWT_ALGORITHM', 'HS256'),
    'access_token_expire_minutes' => (int) env('ACCESS_TOKEN_EXPIRE_MINUTES', 480),

    // Trusted reverse-proxy IPs/CIDRs so Request::ip() reflects the real client
    // (correct per-IP rate limits and password brute-force cap behind a proxy).
    //   - ''  trusts nothing (direct connections; safe default, no spoof vector)
    //   - '*' trusts all (ONLY when a proxy overwrites X-Forwarded-For)
    //   - '1.2.3.4,10.0.0.0/8' trusts those specific proxies
    'trusted_proxies' => env('ROI_TRUSTED_PROXIES', ''),

    // Single Admin Credentials
    'admin_email' => env('ADMIN_EMAIL'),
    'admin_password_hash' => env('ADMIN_PASSWORD_HASH'),

    // External Live API Keys
    'youtube_api_key' => env('YOUTUBE_API_KEY', ''),
    'youtube_channel_id' => env('YOUTUBE_CHANNEL_ID', 'UC_demomedia'),
    'paystack_secret_key' => env('PAYSTACK_SECRET_KEY', ''),
    'mpesa_consumer_key' => env('MPESA_CONSUMER_KEY', ''),
    'mpesa_consumer_secret' => env('MPESA_CONSUMER_SECRET', ''),
    'mpesa_passkey' => env('MPESA_PASSKEY', ''),
    'mpesa_callback_url' => env('MPESA_CALLBACK_URL', ''),
    'mpesa_shortcode' => env('MPESA_SHORTCODE'),

    // Hardened webhook shared secret (appended to the Daraja CallBackURL and verified on inbound callbacks)
    'mpesa_webhook_token' => env('MPESA_WEBHOOK_TOKEN', ''),

    // Shared secret for POST /api/youtube/cron-sync. Required in production (F-03):
    // the scheduler passes it as ?secret= (or X-Cron-Secret header). Empty + production => 403.
    'youtube_cron_secret' => env('YOUTUBE_CRON_SECRET', ''),

    // Monthly pledge collection (M-Pesa-first).
    // Reminder schedule: days AFTER the due date to re-nudge while unpaid
    // (spec §11; empty disables reminders entirely).
    'pledge_reminder_days' => PledgeConfig::parseReminderDays((string) env('PLEDGE_REMINDER_DAYS', '1,3,7')),
    // Cap on reminder emails processed per cron run (spec §20 — keeps a large
    // backlog from becoming one giant SMTP burst).
    'pledge_email_batch_size' => PledgeConfig::parseBatchSize((string) env('PLEDGE_EMAIL_BATCH_SIZE', '25')),
    // Billing months and due dates are computed in this timezone (spec §19)
    // while timestamps stay on the app's UTC storage convention.
    'pledge_timezone' => PledgeConfig::normalizeTimezone((string) env('PLEDGE_TIMEZONE', 'Africa/Nairobi')),
    // Secure /pledges/pay/{token} links expire after this many hours (spec §6).
    'pledge_payment_link_ttl_hours' => PledgeConfig::parseLinkTtlHours((string) env('PLEDGE_PAYMENT_LINK_TTL_HOURS', '168')),

    // Vanity dashboard constants (parity with FastAPI implementation)
    'system_health_default' => 'Optimal (Vercel + Supabase Synchronized)',
];
