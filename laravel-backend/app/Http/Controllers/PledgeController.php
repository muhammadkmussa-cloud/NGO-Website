<?php

namespace App\Http\Controllers;

use App\Models\Pledge;
use App\Models\PledgePayment;
use App\Services\AuditLogger;
use App\Services\Exceptions\PaymentGatewayException;
use App\Services\PledgeService;
use App\Services\PledgeTokenService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Monthly M-Pesa pledge endpoints (spec §2 create, §6–§7 secure pay page,
 * §8 retry, §10 server-side status). Amounts and eligibility always come from
 * the database — request bodies can never reprice a month.
 */
class PledgeController extends Controller
{
    public function __construct(
        private PledgeService $pledges,
        private PledgeTokenService $tokens,
        private AuditLogger $audit,
    ) {}

    /** POST /api/pledges — create a monthly M-Pesa pledge + first STK prompt. */
    public function store(Request $request): JsonResponse
    {
        if (! config('roi.payments_enabled')) {
            return response()->json(['detail' => 'Payments are temporarily unavailable.'], 503);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email:rfc', 'max:191'],
            'phone' => ['required', 'string', 'max:32'],
            // Paystack mobile-money ceiling is KES 150,000/transaction.
            'amount' => ['required', 'numeric', 'min:1', 'max:150000'],
        ]);

        try {
            [$pledge, $created] = $this->pledges->createPledge($data);
        } catch (LockTimeoutException) {
            // Contended duplicate guard — nothing was created; ask for a beat.
            return response()->json([
                'detail' => 'Another submission is being processed — please try again in a moment.',
            ], 429);
        } catch (PaymentGatewayException $e) {
            // Bad phone/format — nothing was created.
            return response()->json(['detail' => $e->getMessage()], $e->statusCode);
        }

        $payment = $this->pledges->ensureObligation($pledge);

        $message = 'M-Pesa request sent. Check your phone and enter your M-Pesa PIN.';
        $chargeError = false;
        if ($payment->status === PledgePayment::STATUS_PAID) {
            $message = "This month's pledge is already paid. Thank you!";
        } else {
            try {
                // Pass the submitted phone so a supporter can correct it before
                // the prompt (initiateCharge normalizes + persists it).
                $initiated = $this->pledges->initiateCharge($payment, (string) $data['phone']);
                $message = $initiated['message'];
            } catch (PaymentGatewayException $e) {
                // The pledge + failed attempt exist (audit §21) and the month
                // is DUE again — report a truthful retryable payload instead of
                // a bare error, so the UI can offer a retry (spec §18).
                $message = $e->getMessage();
                $chargeError = true;
            }
        }

        return response()->json([
            'pledge' => $pledge->fresh()->toApiArray(),
            'payment' => $payment->fresh()->toApiArray(),
            'message' => $message,
            'charge_error' => $chargeError,
        ], $created ? 201 : 200);
    }

    /**
     * GET /pledges/pay/{token} — the mobile-friendly payment page (§7).
     * Renders an invalid/expired state instead of leaking whether an id exists.
     */
    public function payPage(string $token)
    {
        $payment = $this->tokens->verify($token);
        if (! $payment) {
            return response()->view('pledges.pay', [
                'state' => 'invalid',
                'token' => $token,
            ], 404)->header('Cache-Control', 'no-store');
        }

        $payment->load('pledge');

        // §21: a valid token was actually opened by a supporter. Status
        // polling deliberately does NOT audit — it hits /status, not here.
        $this->audit->record(
            (string) $payment->pledge->email,
            'Payment link used',
            sprintf('pledge=%d month=%s', $payment->pledge_id, $payment->billing_month)
        );

        return response()->view('pledges.pay', [
            'state' => $payment->status === PledgePayment::STATUS_PAID ? 'paid' : 'due',
            'payment' => $payment,
            'pledge' => $payment->pledge,
            'token' => $token,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * POST /api/pledges/pay/{token} — start this month's M-Pesa request.
     * Optional {phone} updates the pledge's number first (§7). The body is
     * never trusted for amount/currency/period — those come from the token's
     * bound database row (§6).
     */
    public function pay(Request $request, string $token): JsonResponse
    {
        // Same kill switch as store(): with payments off, no new STK prompt
        // may leave the system (the webhook would 503 its settlement anyway).
        if (! config('roi.payments_enabled')) {
            return response()->json(['detail' => 'Payments are temporarily unavailable.'], 503);
        }

        $payment = $this->tokens->verify($token);
        if (! $payment) {
            return response()->json([
                'detail' => 'This payment link is invalid or has expired.',
            ], 404);
        }

        if ($payment->status === PledgePayment::STATUS_PAID) {
            return response()->json([
                'detail' => "This month's pledge is already paid. Thank you!",
                'status' => PledgePayment::STATUS_PAID,
            ], 409);
        }

        $data = $request->validate([
            'phone' => ['sometimes', 'string', 'max:32'],
        ]);

        try {
            $initiated = $this->pledges->initiateCharge(
                $payment,
                $data['phone'] ?? null,
            );

            return response()->json([
                'status' => $payment->fresh()->status,
                'billing_month' => $payment->billing_month,
                'message' => $initiated['message'],
            ], 202);
        } catch (PaymentGatewayException $e) {
            return response()->json(['detail' => $e->getMessage()], $e->statusCode);
        }
    }

    /**
     * POST /api/pledges/pay/{token}/cancel — §17 supporter cancellation with
     * the same secure token that pays the month (no separate link surface).
     * Idempotent: an already-cancelled pledge answers the same 200.
     */
    public function cancel(string $token): JsonResponse
    {
        $payment = $this->tokens->verify($token);
        if (! $payment) {
            return response()->json([
                'detail' => 'This payment link is invalid or has expired.',
            ], 404);
        }

        $this->pledges->cancelFromPayment($payment);

        return response()->json([
            'status' => Pledge::STATUS_CANCELLED,
            'message' => 'Your monthly pledge has been cancelled. No further payments will be requested.',
        ]);
    }

    /** GET /api/pledges/pay/{token}/status — server-side truth for polling (§10). */
    public function payStatus(string $token): JsonResponse
    {
        $payment = $this->tokens->verify($token);
        if (! $payment) {
            return response()->json([
                'detail' => 'This payment link is invalid or has expired.',
            ], 404);
        }

        return response()->json([
            'status' => $payment->status,
            'billing_month' => $payment->billing_month,
            'amount_due' => (float) $payment->amount_due,
            'currency' => $payment->currency,
            'paid_at' => $payment->paid_at?->format('Y-m-d\TH:i:s'),
            'attempts' => $payment->attempts,
        ]);
    }
}
