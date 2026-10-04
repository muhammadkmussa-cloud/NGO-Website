<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmailExistenceService
{
    /**
     * Disposable/temporary providers plus RFC 2606 reserved domains: rejected
     * outright without any DNS or SMTP traffic.
     */
    private const DISPOSABLE_DOMAINS = [
        '0-mail.com',
        '10minutemail.com',
        '10minutemail.net',
        'discard.email',
        'discardmail.com',
        'example.com',
        'example.net',
        'example.org',
        'fakeinbox.com',
        'getnada.com',
        'grr.la',
        'guerrillamail.com',
        'guerrillamail.info',
        'mailcatch.com',
        'maildrop.cc',
        'mailinator.com',
        'mailinator.net',
        'mailnesia.com',
        'sharklasers.com',
        'temp-mail.io',
        'temp-mail.org',
        'tempail.com',
        'tempinbox.com',
        'tempmail.com',
        'throwaway.email',
        'throwawaymail.com',
        'trashmail.com',
        'trashmail.net',
        'yopmail.com',
        'yopmail.fr',
    ];

    /**
     * IPv4 ranges still reachable after FILTER_FLAG_NO_PRIV_RANGE /
     * NO_RES_RANGE: CGNAT/ carrier-grade NAT (incl. cloud metadata peers),
     * IETF protocol assignments, and benchmarking networks.
     */
    private const BLOCKED_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '198.18.0.0/15',
    ];

    /**
     * Best-effort wall-clock budget for one verification. SMTP steps are
     * deadline-checked against it; DNS is bounded only by the OS resolver,
     * so treat this as a target, not a guarantee.
     */
    private const VERIFY_BUDGET_SECONDS = 3.0;

    private ?float $verifyDeadline = null;

    /**
     * Reject only what is clearly fake. Every uncertain signal (DNS failure,
     * blocked port 25, greylisting, accept-all providers, private MX hosts,
     * budget exhaustion) fails open so a real buyer is never turned away.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function assertAcceptable(string $email): void
    {
        if (!config('roi.email_existence_check', true)) {
            return;
        }

        $email = strtolower(trim($email));
        $domain = rtrim(substr((string) strrchr($email, '@'), 1), '.');

        $disposable = false;
        foreach (self::DISPOSABLE_DOMAINS as $bad) {
            if ($domain === $bad || str_ends_with($domain, '.' . $bad)) {
                $disposable = true;
                break;
            }
        }
        if ($disposable) {
            abort(response()->json([
                'detail' => 'Temporary or disposable email addresses are not allowed.',
            ], 400));
        }

        try {
            $verdict = Cache::remember(
                'email-existence:' . sha1($email),
                now()->addMinutes(10),
                function () use ($email, $domain) {
                    try {
                        return $this->verify($email, $domain);
                    } catch (\Throwable $e) {
                        Log::warning('Email existence check failed open.', [
                            'email_sha1' => sha1($email),
                            'error' => $e->getMessage(),
                        ]);

                        return 'pass';
                    }
                }
            );
        } catch (\Throwable $e) {
            Log::warning('Email existence cache unavailable, failing open.', [
                'error' => $e->getMessage(),
            ]);
            $verdict = 'pass';
        }

        if ($verdict === 'fail') {
            abort(response()->json([
                'detail' => 'That email address does not exist. Please check it and try again.',
            ], 400));
        }
    }

    protected function verify(string $email, string $domain): string
    {
        $this->verifyDeadline = microtime(true) + self::VERIFY_BUDGET_SECONDS;

        if (preg_match('/[^\x00-\x7F]/', $domain)) {
            if (!function_exists('idn_to_ascii')) {
                return 'pass';
            }
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return 'pass';
            }
            $domain = $ascii;
        }

        $hosts = $this->resolveMailHosts($domain);
        if ($hosts === null) {
            return 'pass';
        }
        if ($hosts === []) {
            return 'fail';
        }

        $definitiveFail = false;
        foreach (array_slice($hosts, 0, 2) as $host) {
            if ($this->budgetRemaining() <= 0) {
                return 'pass';
            }

            $ip = $this->resolveHost($host);
            if ($ip === null) {
                return 'pass';
            }

            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || $this->inBlockedRange($ip)
            ) {
                Log::info('Email existence probe skipped for non-public mail host.', [
                    'domain' => $domain,
                    'host' => $host,
                ]);

                return 'pass';
            }

            $exists = $this->rcptExists($ip, $email);
            if ($exists === true) {
                return 'pass';
            }
            if ($exists === false) {
                $definitiveFail = true;
                continue;
            }

            return 'pass';
        }

        return $definitiveFail ? 'fail' : 'pass';
    }

    /**
     * @return list<string>|null null when the resolver itself failed (uncertain),
     *                           [] only when records were authoritatively absent
     */
    protected function resolveMailHosts(string $domain): ?array
    {
        if ($domain === '') {
            return [];
        }

        $mx = @dns_get_record($domain, DNS_MX);
        if ($mx === false) {
            return null;
        }
        if (is_array($mx) && $mx !== []) {
            usort($mx, fn (array $a, array $b) => ((int) ($a['pri'] ?? 0)) <=> ((int) ($b['pri'] ?? 0)));
            $hosts = [];
            foreach ($mx as $record) {
                $host = rtrim(strtolower((string) ($record['target'] ?? '')), '.');
                if ($host !== '') {
                    $hosts[] = $host;
                }
            }
            if ($hosts !== []) {
                return $hosts;
            }
        }

        $a = @dns_get_record($domain, DNS_A);
        if ($a === false) {
            return null;
        }

        if (is_array($a) && $a !== []) {
            return [$domain];
        }

        return [];
    }

    protected function resolveHost(string $host): ?string
    {
        $ip = @gethostbyname($host);

        return is_string($ip) && $ip !== $host ? $ip : null;
    }

    /**
     * Best-effort SMTP mailbox probe. Returns true (mailbox exists), false
     * (definitively unknown recipient), or null whenever uncertain.
     */
    protected function rcptExists(string $ip, string $email): ?bool
    {
        if ($this->budgetRemaining() < 1.0) {
            return null;
        }

        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($ip, 25, $errno, $errstr, 2.0);
        if (!is_resource($fp)) {
            return null;
        }

        stream_set_timeout($fp, 2);

        try {
            if ($this->budgetRemaining() <= 0 || !str_starts_with($this->readReply($fp), '220')) {
                return null;
            }

            $this->writeCommand($fp, 'EHLO example.org');
            $ehlo = $this->readReply($fp);
            if ($ehlo === '') {
                return null;
            }
            if (str_starts_with($ehlo, '5')) {
                $this->writeCommand($fp, 'HELO example.org');
                if (!str_starts_with($this->readReply($fp), '250')) {
                    return null;
                }
            }

            if ($this->budgetRemaining() <= 0) {
                return null;
            }
            $this->writeCommand($fp, 'MAIL FROM:<postmaster@example.org>');
            if (!str_starts_with($this->readReply($fp), '250')) {
                return null;
            }

            if ($this->budgetRemaining() <= 0) {
                return null;
            }
            $this->writeCommand($fp, "RCPT TO:<{$email}>");
            $reply = $this->readReply($fp);
            if (str_starts_with($reply, '250') || str_starts_with($reply, '251')) {
                return true;
            }

            $enhancedUnknown = preg_match('/^(550|553)\s+5\.1\.[1-4]\b/i', $reply) === 1;
            $policyDenial = preg_match('/sender|relay|access|denied|policy|blocked/i', $reply) === 1;
            if ($enhancedUnknown && !$policyDenial) {
                return false;
            }

            return null;
        } finally {
            @fclose($fp);
        }
    }

    private function inBlockedRange(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }

        foreach (self::BLOCKED_RANGES as $cidr) {
            [$subnet, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);
            if (($long & $mask) === (ip2long($subnet) & $mask)) {
                return true;
            }
        }

        return false;
    }

    private function writeCommand($fp, string $command): void
    {
        @fwrite($fp, $command . "\r\n");
    }

    private function budgetRemaining(): float
    {
        if ($this->verifyDeadline === null) {
            return self::VERIFY_BUDGET_SECONDS;
        }

        return $this->verifyDeadline - microtime(true);
    }

    /**
     * Reads one full SMTP reply (multiline aware) with a wall-clock deadline
     * bounded by the verification budget and a line cap. Returns '' unless a
     * terminating line was seen, so a mid-reply stall can never make an
     * intermediate line decide the verdict — callers treat '' as uncertain
     * and fail open.
     */
    private function readReply($fp): string
    {
        $remaining = $this->budgetRemaining();
        if ($remaining <= 0) {
            return '';
        }

        $deadline = microtime(true) + min(2.0, $remaining);
        $reply = '';
        $lines = 0;

        while ($lines++ < 64 && microtime(true) < $deadline) {
            $line = fgets($fp, 1024);
            if ($line === false) {
                break;
            }
            $reply = trim($line);
            if (strlen($line) < 4 || in_array($line[3], [' ', "\r", "\n"], true)) {
                return $reply;
            }
        }

        return '';
    }
}
