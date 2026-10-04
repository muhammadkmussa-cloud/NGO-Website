/** Shared ticket-payment rules used by the checkout UI and unit tests. */

export function isMpesaGateway(gateway) {
  return ['m-pesa', 'mpesa', 'm-pesa push'].includes(String(gateway || '').toLowerCase());
}

export function checkoutCurrency(gateway, ticketCurrency = 'KES') {
  return isMpesaGateway(gateway) ? 'KES' : (ticketCurrency || 'KES');
}

// Checkout UI mode for a cart of selected ticket lines:
//   'empty' → nothing selected yet, 'free' → every line is KES 0,
//   'paid'  → at least one priced line (mixed carts count as paid).
export function checkoutMode(items) {
  if (!items || items.length === 0) return 'empty';
  return items.every((l) => Number(l.type?.price || 0) === 0) ? 'free' : 'paid';
}

// Free carts must not send a real gateway — 'Free' is stored on the order
// (backend skips gateway logic whenever the amount is 0).
export function checkoutGateway(mode, selectedGateway) {
  return mode === 'free' ? 'Free' : selectedGateway;
}

export function validateTicketPayment({ items, gateway, buyerPhone, total }) {
  if (!items || items.length === 0) {
    return 'Select at least one ticket.';
  }
  if (isMpesaGateway(gateway) && total > 0 && !String(buyerPhone || '').trim()) {
    return 'M-Pesa phone number is required for STK Push.';
  }
  if (isMpesaGateway(gateway) && total > 0 && total < 1) {
    return 'M-Pesa ticket purchases must be at least KES 1.';
  }
  return null;
}

// L-3: only genuine Paystack checkout hosts may navigate the buyer away.
const PAYSTACK_URL_RE = /^https:\/\/(checkout\.paystack\.com|pay\.stack\.co)\//;

export function isSafePaystackUrl(url) {
  return PAYSTACK_URL_RE.test(String(url || ''));
}

export function shouldRedirectPaystack(authorizationUrl) {
  return isSafePaystackUrl(authorizationUrl);
}

export function isTerminalOrderStatus(status) {
  const s = String(status || '').toLowerCase();
  return s === 'completed' || s.startsWith('failed');
}

export function shouldPollPayment(status) {
  if (isTerminalOrderStatus(status)) return false;
  const s = String(status || '').toLowerCase();
  return s.includes('pending') || s.includes('stk');
}
