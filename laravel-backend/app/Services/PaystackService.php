<?php

namespace App\Services;

use App\Services\Exceptions\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
        string $callbackUrl
    ): array {
        $secret = (string) config('roi.paystack_secret_key');

        try {
            $response = Http::withToken($secret)
                ->timeout(10)
                ->acceptJson()
                ->post('https://api.paystack.co/transaction/initialize', [
                    'email' => $email !== '' ? $email : 'donor@example.com',
                    'amount' => (int) round($amount * 100),
                    'currency' => $currency,
                    'reference' => $reference,
                    'callback_url' => $callbackUrl,
                ]);

            if ($response->status() === 200) {
                return [
                    'authorization_url' => $response->json('data.authorization_url'),
                    'customer_message' => 'Paystack checkout modal generated.',
                ];
            }

            throw new PaymentGatewayException(
                sprintf(
                    'Paystack payment gateway initialize failed (HTTP %d): %s',
                    $response->status(),
                    $response->body()
                )
            );
        } catch (PaymentGatewayException $e) {
            throw $e;
        } catch (ConnectionException|Throwable $e) {
            throw new PaymentGatewayException(
                "Paystack payment gateway initialize failed: Network or gateway connection error ({$e->getMessage()})."
            );
        }
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
                    default => 'Pending (' . $response->json('data.status') . ')',
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
