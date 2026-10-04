<?php

namespace App\Services;

use App\Services\Exceptions\PaymentGatewayException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class MpesaService
{
    /**
     * Formats phone numbers into the Daraja 2547XXXXXXXX structure.
     * Mirrors format_mpesa_phone: 07XXXXXXXX → 2547XXXXXXXX, 2547XXXXXXXX kept,
     * bare 9 digits prefixed with 254, anything else rejected.
     */
    public function formatPhone(?string $phone): string
    {
        if (!$phone) {
            throw new PaymentGatewayException(
                'Phone number is required for M-Pesa STK Push checkout.',
                400
            );
        }

        $cleaned = preg_replace('/\D/', '', $phone);

        if (str_starts_with($cleaned, '0') && strlen($cleaned) === 10) {
            return '254' . substr($cleaned, 1);
        }
        if (str_starts_with($cleaned, '254') && strlen($cleaned) === 12) {
            return $cleaned;
        }
        if (strlen($cleaned) === 9) {
            return '254' . $cleaned;
        }

        throw new PaymentGatewayException(
            'Invalid M-Pesa phone number format. Please enter a valid Kenyan mobile number (e.g., 0712345678 or 254712345678).',
            400
        );
    }

    public function isSandboxMode(): bool
    {
        // M-2: simulated STK dispatch requires the explicit dev-bypass opt-in,
        // same as every other gateway-free convenience. Without it, missing
        // credentials surface as an honest gateway error instead of a fake
        // success that can never complete.
        $credentialsMissing = !config('roi.mpesa_consumer_key') || !config('roi.mpesa_consumer_secret');

        return \App\Support\DevBypass::enabled() && $credentialsMissing;
    }

    /**
     * Executes the STK Push flow. Returns
     * ['checkout_request_id' => ..., 'merchant_request_id' => ..., 'customer_message' => ...].
     *
     * @throws PaymentGatewayException
     */
    public function stkPush(
        float $amount,
        string $formattedPhone,
        ?string $origin,
        string $accountReference = 'DEMO Empowerment',
        string $transactionDesc = 'Donation to Demo NGO Harbor City',
    ): array
    {
        if ($this->isSandboxMode()) {
            // Deterministic sandbox simulation matching the FastAPI dev stubs.
            $hex = fn (int $len) => strtoupper(bin2hex(random_bytes(intdiv($len, 2))));

            return [
                'checkout_request_id' => 'ws_CO_SIM_' . $hex(14),
                'merchant_request_id' => 'MRC_SIM_' . $hex(10),
                'customer_message' => "Daraja verified sandbox STK Push sent to {$formattedPhone}.",
            ];
        }

        $shortcode = (string) config('roi.mpesa_shortcode');
        $passkey = (string) config('roi.mpesa_passkey');
        $timestamp = Carbon::now('Africa/Nairobi')->format('YmdHis');
        $password = base64_encode("{$shortcode}{$passkey}{$timestamp}");

        // Shortcode 174379 is the default Lipa Na M-Pesa Online Sandbox shortcode.
        $baseUrl = trim($shortcode) === '174379'
            ? 'https://sandbox.safaricom.co.ke'
            : 'https://api.safaricom.co.ke';

        try {
            $authResponse = Http::withBasicAuth(
                (string) config('roi.mpesa_consumer_key'),
                (string) config('roi.mpesa_consumer_secret')
            )->timeout(10)->get("{$baseUrl}/oauth/v1/generate", ['grant_type' => 'client_credentials']);

            if ($authResponse->status() !== 200) {
                throw new PaymentGatewayException(
                    "Safaricom Daraja OAuth authentication failed: {$authResponse->body()}"
                );
            }

            $accessToken = $authResponse->json('access_token');

            $stkResponse = Http::withToken($accessToken)
                ->timeout(10)
                ->acceptJson()
                ->post("{$baseUrl}/mpesa/stkpush/v1/processrequest", [
                    'BusinessShortCode' => $shortcode,
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'TransactionType' => 'CustomerPayBillOnline',
                    'Amount' => (int) $amount,
                    'PartyA' => $formattedPhone,
                    'PartyB' => $shortcode,
                    'PhoneNumber' => $formattedPhone,
                    'CallBackURL' => $this->resolveCallbackUrl($origin),
                    'AccountReference' => 'DEMO Empowerment',
                    'TransactionDesc' => 'Donation to Demo NGO Harbor City',
                ]);

            if ($stkResponse->status() !== 200) {
                throw new PaymentGatewayException(
                    "Safaricom Daraja STK Push failed: {$stkResponse->body()}"
                );
            }

            return [
                'checkout_request_id' => $stkResponse->json('CheckoutRequestID'),
                'merchant_request_id' => $stkResponse->json('MerchantRequestID'),
                'customer_message' => "Daraja STK Push sent to {$formattedPhone}.",
            ];
        } catch (PaymentGatewayException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PaymentGatewayException(
                "Safaricom Daraja STK Push service unreachable ({$e->getMessage()})."
            );
        }
    }

    /**
     * Callback URL normalization ported from the FastAPI implementation:
     * forces the tail to /api/payments/webhook/mpesa and appends the hardened
     * webhook token as a query parameter when configured.
     */
    public function resolveCallbackUrl(?string $origin): string
    {
        $token = (string) config('roi.mpesa_webhook_token');
        $suffix = '/api/payments/webhook/mpesa';

        $configured = (string) config('roi.mpesa_callback_url');
        if ($configured !== '') {
            $base = rtrim($configured, '/');
            foreach (['/api/payments', '/api'] as $marker) {
                if (str_contains($base, $marker)) {
                    $base = explode($marker, $base)[0];
                    break;
                }
            }
            $url = $base . $suffix;
        } else {
            $base = rtrim((string) $origin ?: (string) config('app.url'), '/');
            if (str_contains($base, 'localhost') || str_contains($base, '127.0.0.1')) {
                return 'https://example.com' . $suffix;
            }
            $url = $base . $suffix;
        }

        return $token !== '' ? "{$url}?token={$token}" : $url;
    }

    /**
     * Hardened inbound callback verification: rejects callbacks that do not carry
     * the shared token configured in MPESA_WEBHOOK_TOKEN. Fails CLOSED in
     * production when no token is configured (F-02) — an unauthenticated
     * webhook that can flip donation statuses is never acceptable there.
     * Local development keeps the open default so sandbox stubs still flow.
     */
    public function verifyCallbackToken(?string $providedToken): bool
    {
        $token = (string) config('roi.mpesa_webhook_token');

        if ($token === '') {
            // M-2: sandbox callbacks require the explicit dev-bypass opt-in;
            // production always fails closed.
            return \App\Support\DevBypass::enabled();
        }

        return is_string($providedToken) && hash_equals($token, $providedToken);
    }
}
