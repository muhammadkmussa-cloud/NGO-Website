<?php

namespace App\Http\Controllers;

use App\Models\Donation;
use App\Models\TicketOrder;
use App\Services\AuditLogger;
use App\Services\Exceptions\PaymentGatewayException;
use App\Services\MpesaService;
use App\Services\PaystackService;
use App\Services\PledgeService;
use App\Services\TicketService;
use App\Support\DevBypass;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(
        protected PaystackService $paystack,
        protected MpesaService $mpesa,
        protected TicketService $ticketService,
        protected AuditLogger $audit,
        protected PledgeService $pledges,
    ) {}

    /** POST /api/payments/checkout */
    public function checkout(Request $request): JsonResponse
    {
        if (! config('roi.payments_enabled')) {
            return response()->json(['detail' => 'Payments are temporarily unavailable.'], 503);
        }

        $data = $request->validate([
            'donor_name' => ['sometimes', 'string'],
            // A monthly pledge is contacted by email (receipts, pledge updates)
            // and, for the M-Pesa rail, by reminders — require it for monthly.
            // One-time gifts stay email-optional (the sentinel fallback below).
            'email' => [
                'nullable',
                'email:rfc',
                Rule::requiredIf(fn () => strtolower(trim((string) $request->input('frequency'))) === 'monthly'),
            ],
            // F-11: bound the amount (float-column overflow / vanity records) and
            // pin currency to the supported set (parity with the frontend switcher):
            // Paystack (Kenya) accepts only KES and USD — EUR/GBP are rejected
            // by the gateway with "no active channel".
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000000'],
            'currency' => ['sometimes', 'string', 'in:KES,USD'],
            'gateway' => ['required', 'string'],
            'phone_number' => ['nullable', 'string'],
            'frequency' => ['sometimes', 'string'],
        ]);

        if (app()->environment('local') && env('CHECKOUT_DEBUG')) {
            fwrite(STDERR, 'DEBUG types: '.json_encode(array_map('gettype', $data)).PHP_EOL);
        }
        $donorName = $data['donor_name'] ?? 'Anonymous';
        $currency = $data['currency'] ?? 'KES';
        // Unknown values collapse to one-time — never invent a pledge cadence.
        $frequency = strtolower((string) ($data['frequency'] ?? 'one-time')) === 'monthly' ? 'monthly' : 'one-time';
        $gateway = $data['gateway'];
        $reference = PaystackService::makeReference($gateway);

        $authUrl = null;
        $customerMessage = 'Transaction initiated.';
        $initialStatus = 'Pending Verification';
        $checkoutId = null;
        $merchantId = null;

        if (strtolower($gateway) === 'paystack') {
            $initialStatus = 'Pending Paystack Checkout';

            if (DevBypass::enabled()) {
                $authUrl = "https://checkout.paystack.com/verified-sandbox-{$reference}";
                $customerMessage = 'Sandbox verified checkout modal generated.';
            } else {
                $origin = $request->headers->get('origin') ?: $request->getSchemeAndHttpHost();
                // Hash-safe callback (same pattern as TicketService) so Paystack
                // returns the buyer to the SPA route, not a server-side 404.
                $callbackUrl = rtrim($origin, '/')."/#/donate/verify/{$reference}";

                try {
                    // Monthly pledges charge via a plan code — that is what turns
                    // the transaction into a real recurring subscription.
                    $planCode = $frequency === 'monthly'
                        ? $this->paystack->createOrGetMonthlyPlan($currency, (float) $data['amount'])
                        : null;

                    $result = $this->paystack->initialize(
                        email: (string) ($data['email'] ?? ''),
                        amount: (float) $data['amount'],
                        currency: $currency,
                        reference: $reference,
                        callbackUrl: $callbackUrl,
                        plan: $planCode,
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

                // M-Pesa is a KES rail — never charge a USD amount as if it were KES.
                if ($currency !== 'KES') {
                    return response()->json([
                        'detail' => 'M-Pesa contributions must be in KES.',
                    ], 400);
                }

                // Card authorization is the only rail Paystack can recur on —
                // an M-Pesa "monthly pledge" could never renew.
                if ($frequency === 'monthly') {
                    return response()->json([
                        'detail' => 'Monthly pledges require a card. Please choose One Time for M-Pesa.',
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

        if (! $donation && ! $ticketOrder) {
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
            if (DevBypass::enabled()) {
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
            // Pledge state for the return-page badge (non-sensitive: no code/token).
            'subscription' => $donation && $donation->subscription_status !== null ? [
                'status' => $donation->subscription_status,
                'next_payment_date' => $donation->next_payment_date,
            ] : null,
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

        if (! $this->mpesa->verifyCallbackToken($request->query('token'))) {
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

            if (! $pending && $merchantReqId) {
                $pending = Donation::where('merchant_request_id', $merchantReqId)->first();
            }

            if (! $pending) {
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

        if ($environment === 'production' && ! $secretConfigured) {
            return response()->json(['detail' => 'Paystack webhook is not configured'], 403);
        }

        $signatureValid = $this->paystack->verifyWebhookSignature($rawBody, $request->header('x-paystack-signature'));
        $bypassAllowed = DevBypass::enabled() && ! $secretConfigured;

        if (! $signatureValid && ! $bypassAllowed) {
            return response()->json(['detail' => 'Invalid Paystack signature verification'], 400);
        }

        $event = $request->json('event');
        $reference = $request->json('data.reference');

        // Monthly pledge references (ROI-PLE-*) settle here — idempotent,
        // amount+currency verified against the obligation row (spec §9).
        if (is_string($reference) && str_starts_with($reference, 'ROI-PLE-')) {
            $pledgeData = $request->json('data');
            $this->pledges->settleFromWebhook(
                $reference,
                is_array($pledgeData) ? $pledgeData : [],
                is_string($event) ? $event : '',
            );

            return response()->json(['status' => 'success']);
        }

        $donation = $reference ? Donation::where('reference', (string) $reference)->first() : null;
        $ticketOrder = $reference ? $this->ticketService->findByPaymentHint(null, null, (string) $reference) : null;

        if ($event === 'charge.success') {
            if ($donation) {
                $donation->status = 'Completed';
                $donation->save();
                // Initial monthly charge: link the subscription that Paystack
                // created for this checkout (fast, reference-exact path).
                $this->captureSubscriptionInfo($donation, $request);
            }
            if ($ticketOrder) {
                $this->ticketService->fulfill($ticketOrder);
            }
            if (! $donation && ! $ticketOrder) {
                // No local row for this reference: a renewal charge for a
                // subscription we already track (first charge is always
                // reference-exact, so it lands in the branch above).
                $this->recordRenewalCharge($request);
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
        } elseif (in_array($event, [
            'subscription.create', 'subscription.disable', 'subscription.not_renew',
            'invoice.payment_failed', 'invoice.update',
        ], true)) {
            $this->handleSubscriptionEvent($event, $request);
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

        $shortcode = trim((string) config('roi.mpesa_shortcode'));

        return response()->json([
            'enabled' => true,
            'business_name' => 'REACHING OUT INITIATIVE',
            // Without a paybill number the KCB card would render "Paybill —" in
            // the offline instructions — omit it until it is configured.
            'kcb_mpesa' => $shortcode === '' ? null : [
                'channel' => 'M-Pesa Paybill via KCB Bank',
                'paybill' => $shortcode,
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

    /**
     * GET /api/payments/subscription/{reference}/manage
     * Returns the hosted Paystack manage link (card details / cancel) for a
     * monthly pledge. URL is minted on first request and cached on the row
     * until Paystack's expiry — we never handle card data ourselves.
     */
    public function subscriptionManage(string $reference): JsonResponse
    {
        if (! config('roi.payments_enabled')) {
            return response()->json(['detail' => 'Payments are temporarily unavailable.'], 503);
        }

        $donation = Donation::where('reference', $reference)->first();
        if (! $donation || $donation->subscription_code === null) {
            return response()->json(['detail' => 'No pledge is linked to this reference.'], 404);
        }

        if (
            $donation->subscription_manage_url !== null
            && $donation->manage_link_expires_at !== null
            && $donation->manage_link_expires_at->isFuture()
        ) {
            return response()->json([
                'url' => $donation->subscription_manage_url,
                'expires_at' => $donation->manage_link_expires_at->toISOString(),
            ]);
        }

        if ($donation->subscription_token === null || $donation->subscription_token === '') {
            return response()->json([
                'detail' => 'This pledge cannot be managed online yet. Please check your payment receipt email for a management link.',
            ], 409);
        }

        try {
            $link = $this->paystack->subscriptionManageLink(
                $donation->subscription_code,
                $donation->subscription_token
            );
        } catch (PaymentGatewayException $e) {
            return response()->json(['detail' => $e->getMessage()], $e->statusCode);
        }

        $donation->subscription_manage_url = $link['url'];
        $donation->manage_link_expires_at = Carbon::parse($link['expires_at']);
        $donation->save();

        // M-2: minting a hosted manage link is a privileged-ish action
        // (card update / cancel) — audit who requested it, once per mint.
        $this->audit->record(
            (string) ($donation->email ?? 'unknown'),
            'Pledge manage link minted',
            "reference={$reference}"
        );

        return response()->json($link);
    }

    /**
     * charge.success fast path: when Paystack includes the subscription on the
     * checkout charge, bind it to this donation by exact reference.
     */
    private function captureSubscriptionInfo(Donation $donation, Request $request): void
    {
        if ($donation->frequency !== 'monthly') {
            return;
        }

        $sub = self::extractSubscription($request->json('data.subscription'));
        if ($sub === []) {
            return;
        }

        $donation->subscription_code ??= $sub['code'];
        if ($donation->subscription_token === null && $sub['token'] !== null) {
            $donation->subscription_token = $sub['token'];
        }
        $donation->subscription_status ??= 'active';
        $nextPaymentDate = $request->json('data.subscription.next_payment_date');
        if ($donation->next_payment_date === null && is_string($nextPaymentDate) && $nextPaymentDate !== '') {
            $donation->next_payment_date = $nextPaymentDate;
        }
        $donation->save();
    }

    /**
     * subscription.create / disable / not_renew / invoice.* lifecycle sync.
     */
    private function handleSubscriptionEvent(string $event, Request $request): void
    {
        $raw = $request->json('data');
        $data = is_array($raw) ? $raw : [];
        $sub = self::extractSubscription($data['subscription'] ?? null);
        $code = $sub['code']
            ?? (is_string($data['subscription_code'] ?? null) && str_starts_with($data['subscription_code'], 'SUB_')
                ? $data['subscription_code']
                : null);

        if ($event === 'subscription.create') {
            $token = $sub['token']
                ?? (is_string($data['email_token'] ?? null) && $data['email_token'] !== '' ? $data['email_token'] : null);
            $this->linkSubscriptionFromWebhook($data, $code, $token);

            return;
        }

        if ($code === null) {
            return;
        }

        // m-1: only the parent checkout row carries pledge lifecycle state —
        // renewal children share subscription_code and must never be flipped
        // (they are historical Completed credits, not the mandate).
        $status = match ($event) {
            'subscription.disable' => 'disabled',
            'subscription.not_renew' => 'cancelling',
            'invoice.payment_failed' => 'past_due',
            default => null,
        };

        if ($status !== null) {
            Donation::where('subscription_code', $code)->orderBy('id')->first()
                ?->update(['subscription_status' => $status]);

            return;
        }

        if ($event === 'invoice.update') {
            // Paid renewal invoices: keep the parent's schedule fields fresh.
            // The renewal itself is ledgered from charge.success (see
            // recordRenewalCharge) so one event never double-credits.
            $this->refreshParentSchedule($data, $code);
        }
    }

    /**
     * Bind subscription.create to the pending monthly donation it created:
     * exact email + amount + currency within 24h. Paystack retries this event,
     * so an already-linked code short-circuits first.
     */
    private function linkSubscriptionFromWebhook(array $data, ?string $code, ?string $token): void
    {
        if ($code === null) {
            return;
        }

        $already = Donation::where('subscription_code', $code)->orderBy('id')->first();
        if ($already) {
            $already->subscription_status = (string) ($data['status'] ?? 'active');
            if ($already->subscription_token === null && is_string($token) && $token !== '') {
                $already->subscription_token = $token;
            }
            if (is_string($data['next_payment_date'] ?? null) && $data['next_payment_date'] !== '') {
                $already->next_payment_date = $data['next_payment_date'];
            }
            $already->save();

            return;
        }

        $email = strtolower(trim((string) data_get($data, 'customer.email', '')));
        $minor = $data['amount'] ?? null;
        $currency = data_get($data, 'plan.currency');

        $candidate = null;
        if ($email !== '' && $minor !== null && is_string($currency) && $currency !== '') {
            $candidate = Donation::query()
                ->where('frequency', 'monthly')
                ->whereNull('subscription_code')
                ->whereIn('status', ['Pending Paystack Checkout', 'Completed'])
                ->where('email', $email)
                ->where('currency', $currency)
                ->whereRaw('ROUND(amount * 100) = ?', [(int) $minor])
                ->where('created_at', '>=', now()->subDay())
                // M-4: a settled Completed row beats a still-pending checkout
                // of the same amount — the paid one is the real mandate.
                ->orderByRaw("CASE WHEN status = 'Completed' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')
                ->first();
        }

        if (! $candidate) {
            // Never guess a link — surface for manual reconciliation instead.
            report(sprintf(
                'Paystack subscription.create unmatched: code=%s email=%s amount=%s plan=%s',
                $code,
                $email,
                $minor,
                data_get($data, 'plan.plan_code', '-')
            ));

            return;
        }

        $candidate->subscription_code = $code;
        $candidate->subscription_token = is_string($token) && $token !== '' ? $token : null;
        $candidate->subscription_status = (string) ($data['status'] ?? 'active');
        $candidate->next_payment_date = is_string($data['next_payment_date'] ?? null) && $data['next_payment_date'] !== ''
            ? $data['next_payment_date']
            : null;
        $candidate->save();
    }

    /** Renewal charge with no local reference → new Completed ledger row. */
    private function recordRenewalCharge(Request $request): void
    {
        $data = $request->json('data');
        $data = is_array($data) ? $data : [];
        $reference = is_string($data['reference'] ?? null) ? $data['reference'] : '';
        if ($reference === '') {
            // Dropped renewal (no reference) must still surface for reconciliation.
            report('Paystack renewal charge dropped: missing reference in charge.success payload');

            return;
        }

        $sub = self::extractSubscription($data['subscription'] ?? null);
        if ($sub === []) {
            // No local row AND no subscription node: this renewal cannot be
            // ledgered — report it instead of silently swallowing the money.
            report("Paystack renewal charge dropped: no local reference and no subscription info (ref={$reference})");

            return;
        }

        $parent = Donation::where('subscription_code', $sub['code'])->orderBy('id')->first();
        if (! $parent) {
            report("Paystack renewal charge matched no parent subscription: {$sub['code']}");

            return;
        }

        // Deterministic per-charge key: webhook retries hit the unique
        // reference constraint instead of creating a second credit.
        $renewalReference = 'ROI-REN-'.strtoupper(substr(hash('sha256', $reference), 0, 32));
        $amountMinor = $data['amount'] ?? null;

        try {
            Donation::query()->firstOrCreate(
                ['reference' => $renewalReference],
                [
                    'donor_name' => $parent->donor_name,
                    'email' => $parent->email,
                    'amount' => $amountMinor !== null ? ((int) $amountMinor) / 100 : $parent->amount,
                    'currency' => $parent->currency,
                    'gateway' => $parent->gateway,
                    'frequency' => 'monthly',
                    'status' => 'Completed',
                    'subscription_code' => $sub['code'],
                    'subscription_status' => 'active',
                ],
            );
        } catch (UniqueConstraintViolationException) {
            // C-1: ONLY a true duplicate is safe to swallow (concurrent webhook
            // retry already inserted this reference). Every other database
            // failure — NOT NULL, FK, CHECK, I/O — must propagate so Paystack
            // sees the 5xx and retries instead of the renewal vanishing.
            return;
        }

        // Webhook delivery order is not guaranteed: a late success event must
        // never resurrect a pledge the supporter (or Paystack) already cancelled.
        if (! in_array($parent->subscription_status, ['disabled', 'cancelling'], true)) {
            $parent->subscription_status = 'active';
        }
        $nextPaymentDate = data_get($data, 'subscription.next_payment_date') ?? $data['next_payment_date'] ?? null;
        if (is_string($nextPaymentDate) && $nextPaymentDate !== '') {
            $parent->next_payment_date = $nextPaymentDate;
        }
        $parent->save();
    }

    private function refreshParentSchedule(array $data, string $code): void
    {
        $parent = Donation::where('subscription_code', $code)->orderBy('id')->first();
        if (! $parent) {
            return;
        }

        if ((bool) ($data['paid'] ?? false)
            && ! in_array($parent->subscription_status, ['disabled', 'cancelling'], true)) {
            $parent->subscription_status = 'active';
        }
        $nextPaymentDate = data_get($data, 'subscription.next_payment_date') ?? $data['next_payment_date'] ?? null;
        if (is_string($nextPaymentDate) && $nextPaymentDate !== '') {
            $parent->next_payment_date = $nextPaymentDate;
        }
        $parent->save();
    }

    /**
     * Accepts either the bare code string 'SUB_…' or the webhook object
     * {subscription_code, email_token}. Returns [] when nothing usable.
     */
    private static function extractSubscription(mixed $node): array
    {
        if (is_string($node) && str_starts_with($node, 'SUB_')) {
            return ['code' => $node, 'token' => null];
        }

        if (is_array($node)) {
            $code = $node['subscription_code'] ?? null;
            if (is_string($code) && str_starts_with($code, 'SUB_')) {
                $token = $node['email_token'] ?? null;

                return ['code' => $code, 'token' => is_string($token) && $token !== '' ? $token : null];
            }
        }

        return [];
    }
}
