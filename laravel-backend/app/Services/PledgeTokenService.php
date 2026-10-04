<?php

namespace App\Services;

use App\Models\PledgePayment;

/**
 * HMAC-signed secure payment links (spec §6): /pledges/pay/{token}.
 * The token is bound to the pledge, the specific monthly payment, its amount
 * and billing period, plus an expiry — the amount a supporter can pay is
 * ALWAYS re-read from the database, never from the URL or request body.
 */
class PledgeTokenService
{
    public function mint(PledgePayment $payment): string
    {
        $payload = [
            'pid' => (int) $payment->id,
            'pl' => (int) $payment->pledge_id,
            // Fixed-point string: persisted decimals ("1000.00") and floats
            // (1000.0) must drift-match regardless of the model's origin.
            'amt' => number_format((float) $payment->amount_due, 2, '.', ''),
            'bm' => $payment->billing_month,
            'exp' => now()->addSeconds($this->ttlSeconds())->timestamp,
        ];
        $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        return $body.'.'.hash_hmac('sha256', $body, $this->secret());
    }

    /**
     * Returns the payment the token authorises, or null when the token is
     * forged, expired, orphaned, or its bound amount/period no longer matches
     * the database (tamper + drift guard).
     */
    public function verify(?string $token): ?PledgePayment
    {
        if (! $token || ! str_contains($token, '.')) {
            return null;
        }
        [$body, $sig] = explode('.', $token, 2);
        if (! hash_equals(hash_hmac('sha256', $body, $this->secret()), $sig)) {
            return null;
        }
        $payload = json_decode(base64_decode(strtr($body, '-_', '+/')) ?: '', true);
        if (! is_array($payload) || (int) ($payload['exp'] ?? 0) < now()->timestamp) {
            return null;
        }
        $payment = PledgePayment::find((int) ($payload['pid'] ?? 0));
        if (! $payment) {
            return null;
        }
        // The HMAC only proves we minted it — this proves it still describes
        // the payment it points at (amount edits / period drift => reject).
        if ((int) $payment->pledge_id !== (int) ($payload['pl'] ?? -1)
            || number_format((float) $payment->amount_due, 2, '.', '') !== (string) ($payload['amt'] ?? '')
            || $payment->billing_month !== (string) ($payload['bm'] ?? '')) {
            return null;
        }

        return $payment;
    }

    public function ttlSeconds(): int
    {
        return max(1, (int) config('roi.pledge_payment_link_ttl_hours')) * 3600;
    }

    protected function secret(): string
    {
        $secret = (string) (config('roi.jwt_secret_key') ?: config('app.key'));
        if ($secret === '') {
            // A forgeable fallback would let anyone mint payment links — fail loudly.
            throw new \RuntimeException('Pledge payment links require JWT_SECRET_KEY (or APP_KEY).');
        }

        return $secret;
    }
}
