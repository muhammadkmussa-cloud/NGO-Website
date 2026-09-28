<?php

namespace App\Support;

/**
 * M-2: development/test convenience bypasses (sandbox checkouts, unsigned
 * webhooks, gateway-free completion) are OPT-IN. They must never activate
 * merely because ENVIRONMENT is misconfigured — and are hard-refused at boot
 * in production (AppServiceProvider::enforceProductionHardening).
 */
final class DevBypass
{
    public static function enabled(): bool
    {
        $environment = strtolower((string) config('roi.environment'));

        return in_array($environment, ['development', 'test'], true)
            && (bool) config('roi.allow_dev_payment_bypasses');
    }
}
