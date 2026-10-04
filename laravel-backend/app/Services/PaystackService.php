<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Services\Exceptions\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaystackService
{
    /**
     * Initializes a Paystack transaction (amount converted to minor units ×100).
     * Returns ['authorization_url' => ..., 'customer_message' => ...] or throws
     * a domain exception carrying the FastAPI-compatible 502 message.
     *
     * @throws PaymentGatewayException
     */
    public function initialize(
        string $email,
        float $amount,
        string $currency,
        string $reference,
        string $callbackUrl,
        ?string $plan = null
    ): array {
        $secret = (string) config('roi.paystack_secret_key');

        $payload = [
            'email' => $email !== '' ? $email : 'donor@example.com',
            'amount' => (int) round($amount * 100),
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
        ];
        // Monthly pledges: the plan code makes Paystack charge the plan amount
        // on an interval and open the subscription lifecycle (webhooks below).
        if ($plan !== null && $plan !== '') {
            $payload['plan'] = $plan;
        }

        try {
            $response = Http::withToken($secret)
                ->timeout(10)
                ->acceptJson()
                ->post('https://api.paystack.co/transaction/initialize', $payload);

            if ($response->status() === 200) {
                return [
                    'authorization_url' => $response->json('data.authorization_url'),
                    'customer_message' => 'Paystack checkout modal generated.',
                ];
            }

            // Full gateway body stays in the logs only — raw API responses must
            // never reach a donor's screen (they read like a crash).
            // 5xx is an incident (report/error); donor-side 4xx is noise (warning).
            $logLine = sprintf('Paystack initialize failed (HTTP %d): %s', $response->status(), $response->body());
            if ($response->status() >= 500) {
                report($logLine);
            } else {
                Log::warning($logLine);
            }

            throw new PaymentGatewayException(
                self::friendlyInitializeError(
                    $response->status(),
                    (string) $response->json('message'),
                    (string) $response->json('code')
                ),
                $response->status() >= 500 ? 502 : $response->status()
            );
        } catch (PaymentGatewayException $e) {
            throw $e;
        } catch (ConnectionException|Throwable $e) {
            report($e);
            throw new PaymentGatewayException(
                'We could not reach the payment service. No transaction was created or charged. Please try again.'
            );
        }
    }

    /**
     * Initiates a Paystack mobile-money (M-Pesa) charge (POST /charge). The
     * supporter authorises on their phone — an accepted response means only
     * that the request was dispatched. NEVER treat it as payment: the signed
     * charge.success webhook is the sole authority (spec §3).
     *
     * @return array{reference: string, message: string}
     *
     * @throws PaymentGatewayException
     */
    public function chargeMobileMoney(
        string $email,
        float $amount,
        string $currency,
        string $reference,
        string $phone
    ): array {
        $payload = [
            'email' => $email !== '' ? $email : 'supporter@example.com',
            'amount' => (int) round($amount * 100),
            'currency' => $currency,
            'reference' => $reference,
            'mobile_money' => ['phone' => self::e164($phone), 'provider' => 'mpesa'],
        ];

        try {
            $response = Http::withToken((string) config('roi.paystack_secret_key'))
                ->timeout(10)
                ->acceptJson()
                ->post('https://api.paystack.co/charge', $payload);

            if ($response->status() === 200 && (bool) $response->json('status')) {
                return [
                    'reference' => (string) ($response->json('data.reference') ?: $reference),
                    'message' => (string) ($response->json('message')
                        ?: 'M-Pesa request sent. Check your phone and enter your M-Pesa PIN.'),
                ];
            }

            $logLine = sprintf('Paystack mobile_money charge failed (HTTP %d): %s', $response->status(), $response->body());
            if ($response->status() >= 500) {
                report($logLine);
            } else {
                Log::warning($logLine);
            }

            throw new PaymentGatewayException(
                self::friendlyInitializeError(
                    $response->status(),
                    (string) $response->json('message'),
                    (string) $response->json('code')
                ),
                $response->status() >= 500 ? 502 : max(400, $response->status())
            );
        } catch (PaymentGatewayException $e) {
            throw $e;
        } catch (ConnectionException|Throwable $e) {
            report($e);
            throw new PaymentGatewayException(
                'We could not reach the payment service. No M-Pesa request was sent. Please try again.'
            );
        }
    }

    /**
     * Paystack M-Pesa expects E.164 with a leading '+': the docs require
     * 0722000000 to be sent as +254722000000. Our canonical stored form is
     * 254722000000 (Daraja STK wants no plus), so add it at this boundary.
     */
    private static function e164(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return $digits === '' ? $phone : '+'.$digits;
    }

    /**
     * Maps gateway failures to copy that is safe for a donor to read.
     * The raw message/code still reach the server log via report() above.
     */
    private static function friendlyInitializeError(int $status, string $message, string $code): string
    {
        return match (true) {
            $code === 'unsupported_currency' => 'This currency is not available for donations yet. Please choose KES or USD.',
            str_contains($message, 'No active channel') => 'This payment currency is not supported. Please choose KES or USD.',
            $status === 401 => 'The payment service rejected our credentials. Please contact the site team.',
            $status >= 500 => 'The payment service is temporarily unavailable. Please try again in a moment.',
            default => 'The payment gateway could not start this transaction. Please try again or contact us.',
        };
    }

    /**
     * Returns the Paystack monthly plan code for (currency, amount),
     * creating the plan on first use. Codes persist in site_settings —
     * plans are permanent on the account, so they must survive cache clears.
     *
     * @throws PaymentGatewayException
     */
    public function createOrGetMonthlyPlan(string $currency, float $amount): string
    {
        $minor = (int) round($amount * 100);
        $key = "paystack_plan:monthly:{$currency}:{$minor}";

        $cached = SiteSetting::query()->where('key', $key)->value('value');
        if (is_string($cached) && $cached !== '' && str_starts_with($cached, 'PLN_')) {
            return $cached;
        }

        try {
            $response = Http::withToken((string) config('roi.paystack_secret_key'))
                ->timeout(10)
                ->acceptJson()
                ->post('https://api.paystack.co/plan', [
                    'name' => "ROI Monthly {$currency} ".number_format($amount, 2),
                    'interval' => 'monthly',
                    'amount' => $minor,
                    'currency' => $currency,
                ]);

            $planCode = $response->status() === 200 ? $response->json('data.plan_code') : null;
            if (is_string($planCode) && str_starts_with($planCode, 'PLN_')) {
                SiteSetting::query()->updateOrCreate(['key' => $key], ['value' => $planCode]);

                return $planCode;
            }

            report(sprintf(
                'Paystack plan create failed (HTTP %d): %s',
                $response->status(),
                $response->body()
            ));
        } catch (ConnectionException|Throwable $e) {
            report($e);
        }

        throw new PaymentGatewayException(
            'We could not set up the monthly pledge. Please try again, or choose a one-time contribution.',
            502
        );
    }

    /**
     * Hosted Paystack page where the donor can view/update card details or
     * cancel the pledge (the sanctioned cancel path — we never store card data).
     * Returns ['url' => ..., 'expires_at' => ...].
     *
     * @throws PaymentGatewayException
     */
    public function subscriptionManageLink(string $code, string $token): array
    {
        try {
            $response = Http::withToken((string) config('roi.paystack_secret_key'))
                ->timeout(10)
                ->acceptJson()
                ->post("https://api.paystack.co/subscription/{$code}/manage/link", [
                    'code' => $code,
                    'token' => $token,
                ]);

            $url = $response->status() === 200
                ? ($response->json('data.url')
                    ?? $response->json('data.authorization_url')
                    ?? $response->json('data.link')
                    ?? $response->json('url'))
                : null;

            if (is_string($url) && $url !== '') {
                return [
                    'url' => $url,
                    // M-1: the hosted link is a short-lived JWT (~24h). Caching
                    // for a fixed 6 days would keep serving dead links — take
                    // the TTL from the token's own exp claim when present.
                    'expires_at' => self::manageLinkExpiry($url)->toISOString(),
                ];
            }

            report(sprintf(
                'Paystack manage link failed (HTTP %d): %s',
                $response->status(),
                $response->body()
            ));
        } catch (ConnectionException|Throwable $e) {
            report($e);
        }

        throw new PaymentGatewayException(
            'We could not open the pledge management page right now. Please try again shortly.',
            502
        );
    }

    /**
     * Extracts the exp claim from a JWT embedded anywhere in the manage URL.
     * Falls back to +24h when the URL carries no parseable token — never to a
     * multi-day cache that would outlive Paystack's own link.
     */
    private static function manageLinkExpiry(string $url): Carbon
    {
        foreach (preg_split('/[^A-Za-z0-9_\-.]+/', $url) ?: [] as $part) {
            if (! str_starts_with($part, 'ey') || ! str_contains($part, '.')) {
                continue;
            }

            $segments = explode('.', $part);
            if (count($segments) < 2) {
                continue;
            }

            $payloadSegment = $segments[1];
            $padded = $payloadSegment.str_repeat('=', (4 - strlen($payloadSegment) % 4) % 4);
            $payload = json_decode(base64_decode(strtr($padded, '-_', '+/')) ?: '', true);

            if (is_array($payload) && is_numeric($payload['exp'] ?? null)) {
                $exp = (int) $payload['exp'];
                if ($exp > time()) {
                    return Carbon::createFromTimestamp($exp);
                }
            }
        }

        return now()->addDay();
    }

    /**
     * Verifies a transaction against the live API and maps status to the internal state machine.
     * Returns the resolved donation status string.
     *
     * @throws PaymentGatewayException
     */
    public function verify(string $reference): string
    {
        try {
            $response = Http::withToken((string) config('roi.paystack_secret_key'))
                ->timeout(10)
                ->acceptJson()
                ->get("https://api.paystack.co/transaction/verify/{$reference}");

            if ($response->status() === 200) {
                return match ($response->json('data.status')) {
                    'success' => 'Completed',
                    'failed' => 'Failed',
                    // Final, non-recoverable states must reach a terminal status so
                    // the order/ticket is not stuck in limbo and capacity is released.
                    'abandoned' => 'Failed (Abandoned)',
                    'reversed' => 'Failed (Reversed)',
                    default => 'Pending ('.$response->json('data.status').')',
                };
            }

            throw new PaymentGatewayException("Paystack Verify API returned {$response->status()}");
        } catch (PaymentGatewayException $e) {
            throw $e;
        } catch (ConnectionException|Throwable $e) {
            throw new PaymentGatewayException(
                "Paystack verification service unreachable ({$e->getMessage()})."
            );
        }
    }

    /**
     * HMAC-SHA512 signature verification over the raw request body,
     * constant-time compared against x-paystack-signature.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        $computed = hash_hmac('sha512', $rawBody, (string) config('roi.paystack_secret_key'));

        return hash_equals($computed, (string) $signatureHeader);
    }

    public static function makeReference(string $gateway): string
    {
        // H-3: references gate public order/pass endpoints, so entropy matters.
        // 128-bit hex (was 32-bit) keeps the same ROI-{GW}-HEX shape.
        return sprintf('ROI-%s-%s', strtoupper(substr($gateway, 0, 3)), strtoupper(bin2hex(random_bytes(16))));
    }
}
