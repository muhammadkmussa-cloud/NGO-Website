/**
 * Pure decision logic for the donation return page (#/donate/verify/:reference).
 * Kept DOM-free so it stays unit-testable without a browser environment.
 */

/**
 * Maps a donation ledger status to the confirm-page presentation.
 * Polling continues only for transient states (gateway still deciding, or the
 * verify call itself was unreachable and a webhook may still land later).
 */
export function interpretDonationStatus(status) {
  const s = String(status || '').toLowerCase();
  if (s === 'completed') {
    return { tone: 'ok', heading: 'Payment received', hint: 'Thank you — your contribution has been recorded.', poll: false };
  }
  if (s.includes('verification failed')) {
    return { tone: 'pending', heading: 'Payment not confirmed yet', hint: 'We could not reach the payment gateway for a moment. This page will keep checking.', poll: true };
  }
  if (s.startsWith('failed')) {
    return { tone: 'fail', heading: 'Payment failed', hint: 'The payment did not complete. You were not charged, or the charge was reversed.', poll: false };
  }
  if (s.includes('pending') || s.includes('stk')) {
    return { tone: 'pending', heading: 'Payment processing', hint: 'We are waiting for confirmation from the payment gateway. This page updates automatically.', poll: true };
  }
  return { tone: 'pending', heading: 'Checking payment', hint: 'Confirming your contribution with the gateway.', poll: true };
}

/**
 * Strips everything Paystack appends after the reference. Paystack redirects
 * to `#/donate/verify/REF?reference=REF&trxref=REF`, and the router's
 * `([^/]+)` param capture swallows the query suffix whole.
 */
export function normalizeDonateReference(raw) {
  if (raw == null) return '';
  return String(raw).split('?')[0].trim();
}

/**
 * Maps the verify payload's optional subscription block to a badge presentation
 * for the return page. Returns null for one-time gifts or responses without
 * pledge state (pre-webhook, ticket orders, etc.).
 */
export function interpretSubscriptionBadge(subscription) {
  if (!subscription || typeof subscription !== 'object') return null;
  const status = String(subscription.status || '').toLowerCase();
  if (!status) return null;

  const next = formatNextPaymentDate(subscription.next_payment_date);
  const upcoming = next ? `Next charge: ${next}` : '';

  switch (status) {
    case 'active':
      return {
        tone: 'ok',
        label: 'Monthly pledge active',
        detail: upcoming || 'Your card will be charged automatically each month.'
      };
    case 'cancelling':
      return { tone: 'pending', label: 'Pledge ending soon', detail: 'This pledge will not renew — no further charges after the current period.' };
    case 'disabled':
      return { tone: 'fail', label: 'Pledge cancelled', detail: 'No further charges will be made.' };
    case 'past_due':
      return { tone: 'fail', label: 'Pledge payment failed', detail: 'Use Manage to update your card and keep this pledge going.' };
    default:
      return { tone: 'pending', label: `Pledge ${status}`, detail: upcoming };
  }
}

/** Renders a Paystack next-payment date for humans; '' when unusable. */
export function formatNextPaymentDate(raw) {
  if (!raw) return '';
  const d = new Date(String(raw));
  if (Number.isNaN(d.getTime())) return '';
  try {
    return d.toLocaleDateString('en-KE', { year: 'numeric', month: 'short', day: 'numeric' });
  } catch {
    return d.toISOString().slice(0, 10);
  }
}

/**
 * Classifies a verify-endpoint failure for the polling loop.
 * undefined => network/abort/timeout (no response at all); 429 => shared
 * per-IP verify throttle; >=500 => gateway-unreachable/DB blips (backend
 * answers 502/503 for those). Everything else (400/403/404) is terminal.
 */
export function isRetryableVerifyError(status) {
  return status === undefined || status === 429 || status >= 500;
}

export const DONATE_VERIFY_POLL_MS = 4000;
export const DONATE_VERIFY_MAX_POLLS = 45; // ~3 minutes — the webhook normally lands within seconds.
