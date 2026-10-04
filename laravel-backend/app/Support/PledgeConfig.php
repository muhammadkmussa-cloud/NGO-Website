<?php

namespace App\Support;

/**
 * Testable parsing/normalization for the pledge configuration (spec §11, §19,
 * §6). config/roi.php delegates here so the rules can be unit-tested without
 * manipulating the process environment.
 */
class PledgeConfig
{
    /**
     * '1,3,7' → [1, 3, 7]. Drops non-positive values and blanks (an empty
     * result disables reminders entirely).
     */
    public static function parseReminderDays(string $raw): array
    {
        return array_values(array_filter(
            array_map('intval', explode(',', $raw)),
            fn (int $days): bool => $days > 0,
        ));
    }

    /** Per-run email batch size — always at least 1 (never silently disabled). */
    public static function parseBatchSize(string $raw): int
    {
        return max(1, (int) $raw);
    }

    /** Billing timezone — falls back to Nairobi on unknown identifiers (§19). */
    public static function normalizeTimezone(string $raw): string
    {
        $tz = trim($raw);

        try {
            new \DateTimeZone($tz);

            return $tz;
        } catch (\Exception) {
            return 'Africa/Nairobi';
        }
    }

    /** Secure-link TTL in hours — floored at 1 so links can never be born expired (§6). */
    public static function parseLinkTtlHours(string $raw): int
    {
        return max(1, (int) $raw);
    }
}
