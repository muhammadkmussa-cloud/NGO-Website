<?php

namespace App\Http\Controllers;

use App\Models\Donation;
use App\Models\TicketOrder;
use App\Services\Exceptions\PaymentGatewayException;
use App\Services\MpesaService;
use App\Services\PaystackService;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PaystackService $paystack,
        protected MpesaService $mpesa,
        protected TicketService $ticketService,
    ) {
    }

    /** POST /api/payments/checkout */
    public function checkout(Request $request): JsonResponse
    {
        if (! config('roi.payments_enabled')) {
            return response()->json(['detail' => 'Payments are temporarily unavailable.'], 503);
        }

        $data = $request->validate([
            'donor_name' => ['sometimes', 'string'],
            'email' => ['nullable', 'email:rfc'],
            // F-11: bound the amount (float-column overflow / vanity records) and
            // pin currency to the supported set (parity with the frontend switcher).
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000000'],
            'currency' => ['sometimes', 'string', 'in:KES,USD,EUR,GBP'],
            'gateway' => ['required', 'string'],
            'phone_number' => ['nullable', 'string'],
            'frequency' => ['sometimes', 'string'],
        ]);

        if (app()->environment('local') && env('CHECKOUT_DEBUG')) {
            fwrite(STDERR, "DEBUG types: " . json_encode(array_map('gettype', $data)) . PHP_EOL);
        }
        $donorName = $data['donor_name'] ?? 'Anonymous';
        $currency = $data['currency'] ?? 'KES';
        $frequency = $data['frequency'] ?? 'one-time';
        $gateway = $data['gateway'];
        $reference = PaystackService::makeReference($gateway);

        $authUrl = null;
        $customerMessage = 'Transaction initiated.';
        $initialStatus = 'Pending Verification';
        $checkoutId = null;
        $merchantId = null;

        if (strtolower($gateway) === 'paystack') {
            $initialStatus = 'Pending Paystack Checkout';

            if (\App\Support\DevBypass::enabled()) {
                $authUrl = "https://checkout.paystack.com/verified-sandbox-{$reference}";
                $customerMessage = 'Sandbox verified checkout modal generated.';
            } else {
                $origin = $request->headers->get('origin') ?: $request->getSchemeAndHttpHost();
                $callbackUrl = rtrim($origin, '/') . "/donate/verify?reference={$reference}";

                try {
                    $result = $this->paystack->initialize(
                        email: (string) ($data['email'] ?? ''),
                        amount: (float) $data['amount'],
                        currency: $currency,
                        reference: $reference,
                        callbackUrl: $callbackUrl,
                    );
                    $authUrl = $result['authorization_url'];
                    $customerMessage = $result['customer_message'];
                } catch (PaymentGatewayException $e) {
                    return response()->json(['detail' => $e->getMessage()], $e->statusCode);
                }
            }
        } elseif (in_array(strtolower($gateway), ['m-pesa', 'mpesa', 'm-pesa push'], true)) {
            $initialStatus = 'Pending M-Pesa STK Prompt';

            try {
                // F-11: Daraja STK Push requires whole KES amounts of at least 1.
                if ((float) $data['amount'] < 1) {
                    return response()->json([
                        'detail' => 'M-Pesa contributions must be at least KES 1.',
                    ], 400);
                }

                $formattedPhone = $this->mpesa->formatPhone($data['phone_number'] ?? null);

                $result = $this->mpesa->stkPush((float) $data['amount'], $formattedPhone, $request->getSchemeAndHttpHost());
                $checkoutId = $result['checkout_request_id'];
                $merchantId = $result['merchant_request_id'];
                $customerMessage = $result['customer_message'];
                $initialStatus = 'STK Prompt Dispatched';
            } catch (PaymentGatewayException $e) {
                return response()->json(['detail' => $e->getMessage()], $e->statusCode);
            }
        } else {
            return response()->json([
                'detail' => 'Unsupported payment gateway. Only Paystack and M-Pesa are accepted.',
            ], 400);
        }

        $donation = Donation::create([
            'donor_name' => $donorName,
            // Pydantic CheckoutInitiate defaulted a missing email to donor@example.com.
            'email' => $data['email'] ?? 'donor@example.com',
            'amount' => (float) $data['amount'],
            'currency' => $currency,
            'gateway' => $gateway,
            'frequency' => $frequency,
            'reference' => $reference,
            'checkout_request_id' => $checkoutId,
            'merchant_request_id' => $merchantId,
            'status' => $initialStatus,
        ]);

        return response()->json(
            $donation->toApiArray(authorizationUrl: $authUrl, customerMessage: $customerMessage)
        );
    }

    /**
     * GET /api/payments/verify/{reference}
     * H-3: references alone are not credentials. Status polling after a payment
     * redirect only needs the transaction state — donor identity, ticket codes
     * and QR URLs are withheld unless the caller also confirms the buyer email.
     */
    public function verify(Request $request, string $reference): JsonResponse
    {
        if (! config('roi.payments_enabled')) {
            return response()->json(['detail' => 'Payments are temporarily unavailable.'], 503);
        }

        $donation = Donation::where('reference', $reference)->first();
        $ticketOrder = $this->ticketService->findByPaymentHint(null, null, $reference);

        if (!$donation && !$ticketOrder) {
            return response()->json(['detail' => 'Payment transaction reference not found'], 404);
        }

        // Ownership confirmation (optional): unlocks the full payload with PII.
        // The 'donor@example.com' sentinel used for anonymous donations is
        // treated as unconfirmable — it is public knowledge, not a secret.
        $confirmedEmail = strtolower(trim((string) $request->query('email', '')));
        $donationEmail = strtolower((string) ($donation?->email ?? ''));
        $orderEmail = strtolower((string) ($ticketOrder?->buyer_email ?? ''));
        $emailConfirmed = $confirmedEmail !== '' && (
            ($donationEmail === $confirmedEmail && $donationEmail !== 'donor@example.com')
            || $orderEmail === $confirmedEmail
        );

        $gateway = strtolower((string) ($donation?->gateway ?? $ticketOrder?->gateway ?? ''));

        if ($gateway === 'paystack') {
            if (\App\Support\DevBypass::enabled()) {
                if ($donation) {
                    $donation->status = 'Completed';
                    $donation->save();
                }
                if ($ticketOrder) {
                    $this->ticketService->fulfill($ticketOrder);
                }

                return response()->json($this->verifyResponse($request, $donation?->fresh(), $ticketOrder, $emailConfirmed));
            }

            try {
                $resolved = $this->paystack->verify($reference);
                if ($donation) {
                    $donation->status = $resolved;
                    $donation->save();
                }
                if ($ticketOrder && $resolved === 'Completed') {
                    $this->ticketService->fulfill($ticketOrder);
                } elseif ($ticketOrder && str_starts_with($resolved, 'Failed')) {
                    // Terminal failure (Failed / Failed (Abandoned) / Failed (Reversed)):
                    // release reserved capacity. In-flight 'Pending (...)' is left alone.
                    $this->ticketService->markFailed($ticketOrder, $resolved);
                }

                return response()->json($this->verifyResponse($request, $donation?->fresh(), $ticketOrder?->fresh(), $emailConfirmed));
            } catch (PaymentGatewayException $e) {
                if ($donation && $e->getMessage() !== '' && str_contains($e->getMessage(), 'unreachable')) {
                    $donation->status = 'Verification Failed (Gateway Unreachable)';
                    $donation->save();
                }

                return response()->json(['detail' => $e->getMessage()], $e->statusCode);
            }
        }

        if ($ticketOrder && $donation && $donation->status === 'Completed' && $ticketOrder->status !== 'Completed') {
            $this->ticketService->fulfill($ticketOrder);
        }

        return response()->json($this->verifyResponse($request, $donation, $ticketOrder, $emailConfirmed));
    }

    /**
     * H-3: sanitized status payload unless the buyer email was confirmed —
     * never expose donor name/email or ticket codes/QR via reference alone.
     */
    protected function verifyResponse(Request $request, ?Donation $donation, ?TicketOrder $ticketOrder, bool $emailConfirmed): array
    {
        if ($emailConfirmed) {
            return ($donation ?? $ticketOrder)->toApiArray();
        }

        $source = $donation ?? $ticketOrder;

        return [
            'reference' => $source->reference,
            'gateway' => $source->gateway,
            'amount' => (float) $source->amount,
            'currency' => $source->currency,
            'status' => $source->status,
            // Non-sensitive ledger metadata kept for API-contract stability.
            'frequency' => $donation?->frequency ?? ($ticketOrder !== null ? 'ticket' : null),
            'created_at' => $source->created_at?->toISOString(),
            'customer_message' => null,
            'authorization_url' => null,
            'sanitized' => true,
        ];
    }

    /**
     * POST /api/payments/webhook/mpesa — Safaricom Daraja STK callback.
     * HARDENED (user-approved deviation): rejects callbacks missing the configured
     * MPESA_WEBHOOK_TOKEN; unknown references are never mutated.
     */
    public function mpesaWebhook(Request $request): JsonResponse
    {
        if (! config('roi.payments_enabled')) {
            return response()->json(['detail' => 'Payments are temporarily unavailable.'], 503);
        }

        if (!$this->mpesa->verifyCallbackToken($request->query('token'))) {
            return response()->json(['detail' => 'Invalid M-Pesa webhook token verification'], 403);
        }

        // Safaricom-format parsing only: if the body is malformed we cannot act
        // on it, so accept-and-drop (parity with FastAPI). Our own DB/persistence
        // failures are handled separately below so a real payment is never lost.
        try {
            $payload = $request->json()->all();
            $callback = data_get($payload, 'Body.stkCallback', []);
            $resultCode = data_get($callback, 'ResultCode');
            $checkoutReqId = data_get($callback, 'CheckoutRequestID');
            $merchantReqId = data_get($callback, 'MerchantRequestID');
        } catch (\Throwable $e) {
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }

        // Our persistence/fulfillment is within our control. If it throws (DB
        // hiccup, fulfilment error), report it and return a non-zero ResultCode so
        // Safaricom retries delivery — instead of silently marking success.
        try {
            // 1. Match via CheckoutRequestID → 2. MerchantRequestID → 3. metadata reference.
            $pending = null;
            if ($checkoutReqId) {
                $pending = Donation::where('checkout_request_id', $checkoutReqId)->first();
            }

            if (!$pending && $merchantReqId) {
                $pending = Donation::where('merchant_request_id', $merchantReqId)->first();
            }

            if (!$pending) {
                foreach (data_get($callback, 'CallbackMetadata.Item', []) as $metaItem) {
                    if (in_array(data_get($metaItem, 'Name'), ['AccountReference', 'BillRefNumber', 'Reference']) && data_get($metaItem, 'Value')) {
                        $pending = Donation::where('reference', (string) data_get($metaItem, 'Value'))->first();
                        if ($pending) {
                            break;
                        }
                    }
                }
            }

            // Only an explicit ResultCode 0 means success; null/missing/anything
            // else is a failure (strict compare so a missing code can't become 0).
            $success = ($resultCode === 0 || $resultCode === '0');

            if ($pending) {
                if ($success) {
                    $pending->status = 'Completed';
                } else {
                    $pending->status = "Failed (Daraja ResultCode {$resultCode})";
                }
                $pending->save();
            }

            $ticketOrder = $this->ticketService->findByPaymentHint(
                $checkoutReqId ? (string) $checkoutReqId : null,
                $merchantReqId ? (string) $merchantReqId : null,
                null,
            );
            if ($ticketOrder) {
                if ($success) {
                    $this->ticketService->fulfill($ticketOrder);
                } else {
                    $this->ticketService->markFailed($ticketOrder, "Failed (Daraja ResultCode {$resultCode})");
                }
            }
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Internal processing error']);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    /**
     * POST /api/payments/webhook/paystack — HMAC-SHA512 signature enforced in all
     * environments except when ENVIRONMENT is development/test with no secret configured.
     */
    public function paystackWebhook(Request $request): JsonResponse
    {
        if (! config('roi.payments_enabled')) {
            return response()->json(['detail' => 'Payments are temporarily unavailable.'], 503);
        }

        $rawBody = $request->getContent();
        $environment = strtolower((string) config('roi.environment'));
        $secretConfigured = (bool) config('roi.paystack_secret_key');

        if ($environment === 'production' && !$secretConfigured) {
            return response()->json(['detail' => 'Paystack webhook is not configured'], 403);
        }

        $signatureValid = $this->paystack->verifyWebhookSignature($rawBody, $request->header('x-paystack-signature'));
        $bypassAllowed = \App\Support\DevBypass::enabled() && !$secretConfigured;

        if (!$signatureValid && !$bypassAllowed) {
            return response()->json(['detail' => 'Invalid Paystack signature verification'], 400);
        }

        $event = $request->json('event');
        $reference = $request->json('data.reference');
        $donation = $reference ? Donation::where('reference', (string) $reference)->first() : null;
        $ticketOrder = $reference ? $this->ticketService->findByPaymentHint(null, null, (string) $reference) : null;

        if ($event === 'charge.success') {
            if ($donation) {
                $donation->status = 'Completed';
                $donation->save();
            }
            if ($ticketOrder) {
                $this->ticketService->fulfill($ticketOrder);
            }
        } elseif ($event === 'charge.failed') {
            // H-1: only reached after signature validation. Mark terminal and
            // release any reserved ticket capacity so nothing hangs.
            if ($donation) {
                $donation->status = 'Failed';
                $donation->save();
            }
            if ($ticketOrder) {
                $this->ticketService->markFailed($ticketOrder, 'Failed');
            }
        }

        return response()->json(['status' => 'success']);
    }

    /** GET /api/payments/paybills — offline manual-payment instructions. */
    public function paybills(): JsonResponse
    {
        if (! config('roi.payments_enabled')) {
            return response()->json([
                'enabled' => false,
                'message' => 'Our secure contribution channels are being prepared. Please contact our team in the meantime.',
            ]);
        }

        return response()->json([
            'enabled' => true,
            'business_name' => 'REACHING OUT INITIATIVE',
            'kcb_mpesa' => [
                'channel' => 'M-Pesa Paybill via KCB Bank',
                'paybill' => (string) config('roi.mpesa_shortcode'),
                'account' => '000004',
                'business_name' => 'REACHING OUT INITIATIVE',
            ],
            'equity_bank' => [
                'channel' => 'M-Pesa / Airtel Money / Equitel via Equity Bank',
                'paybill' => '000002',
                'account' => '000003',
                'business_name' => 'REACHING OUT INITIATIVE',
            ],
        ]);
    }
}
