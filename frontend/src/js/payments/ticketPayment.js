/** Shared ticket-payment rules used by the checkout UI and unit tests. */

export function isMpesaGateway(gateway) {
  return ['m-pesa', 'mpesa', 'm-pesa push'].includes(String(gateway || '').toLowerCase());
}

export function checkoutCurrency(gateway, ticketCurrency = 'KES') {
  return isMpesaGateway(gateway) ? 'KES' : (ticketCurrency || 'KES');
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
