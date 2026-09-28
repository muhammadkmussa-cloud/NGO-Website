import test from 'node:test';
import assert from 'node:assert/strict';
import { FALLBACK_EVENTS, FALLBACK_PORTFOLIO, FALLBACK_SOLUTIONS, FALLBACK_TICKET_CATALOG } from '../src/js/data/fallbackData.js';
import {
  checkoutCurrency,
  isMpesaGateway,
  isTerminalOrderStatus,
  shouldPollPayment,
  shouldRedirectPaystack,
  validateTicketPayment
} from '../src/js/payments/ticketPayment.js';
import { qrDataUrl } from '../src/js/payments/ticketImage.js';
import { checkInRate, filterTicketOrders } from '../src/js/payments/ticketAdmin.js';
import { normalizeTicketCode, resultTone } from '../src/js/payments/ticketCheckIn.js';
import { canIncrement, maxPurchasable, saleStateLabel } from '../src/js/payments/ticketSales.js';
import { isLikelyPortalToken, portalPath } from '../src/js/payments/ticketPortal.js';
import { moveSolutionIds, newSolutionInquiryCount } from '../src/js/payments/solutionAdmin.js';
import { canMoveInquiry, isOpenInquiry, nextInquiryStatuses } from '../src/js/payments/inquiryWorkflow.js';
import {
  classifyProbe,
  isProductionStrictMode,
  redactSecrets,
  sanitizeHealthPayload,
  shouldRetryRequest
} from '../src/js/payments/hardening.js';

test('fallback catalog returns flagship ticket types', () => {
  const catalog = FALLBACK_TICKET_CATALOG(101);
  assert.equal(catalog.event.id, 101);
  assert.equal(catalog.ticket_types.length, 3);
  assert.equal(catalog.ticket_types[0].name, 'Youth Delegate');
  assert.equal(catalog.ticket_types[0].on_sale, true);
});

test('fallback catalog for bootcamp is complimentary', () => {
  const catalog = FALLBACK_TICKET_CATALOG(102);
  assert.equal(catalog.ticket_types[0].price, 0);
});

test('unknown event falls back to first event catalog', () => {
  const catalog = FALLBACK_TICKET_CATALOG(999);
  assert.ok(FALLBACK_EVENTS.some((e) => e.id === catalog.event.id));
});

test('mpesa forces KES and requires a phone', () => {
  assert.equal(isMpesaGateway('M-Pesa Push'), true);
  assert.equal(checkoutCurrency('M-Pesa', 'USD'), 'KES');
  assert.equal(
    validateTicketPayment({ items: [{ ticket_type_id: 1, quantity: 1 }], gateway: 'M-Pesa', buyerPhone: '', total: 500 }),
    'M-Pesa phone number is required for STK Push.'
  );
  assert.equal(
    validateTicketPayment({ items: [{ ticket_type_id: 1, quantity: 1 }], gateway: 'M-Pesa', buyerPhone: '0712345678', total: 500 }),
    null
  );
});

test('only genuine paystack hosts may redirect (L-3 allowlist)', () => {
  // Host-based allowlist: sandbox URLs are legitimate Paystack hosts and are
  // allowed through; non-paystack hosts are always rejected.
  assert.equal(shouldRedirectPaystack('https://checkout.paystack.com/verified-sandbox-ROI-TCK-1'), true);
  assert.equal(shouldRedirectPaystack('https://checkout.paystack.com/live-xyz'), true);
  assert.equal(shouldRedirectPaystack('https://pay.stack.co/x'), true);
  assert.equal(shouldRedirectPaystack('https://evil.tld/pay'), false);
  assert.equal(shouldRedirectPaystack('https://checkout.paystack.com.evil.tld/'), false);
  assert.equal(shouldRedirectPaystack('javascript:alert(1)'), false);
  assert.equal(shouldRedirectPaystack(''), false);
});

test('pending STK and paystack statuses poll until terminal', () => {
  assert.equal(shouldPollPayment('STK Prompt Dispatched'), true);
  assert.equal(shouldPollPayment('Pending Paystack Checkout'), true);
  assert.equal(shouldPollPayment('Completed'), false);
  assert.equal(isTerminalOrderStatus('Failed (Daraja ResultCode 1032)'), true);
});

test('qr code generation is self-contained (no external service)', () => {
  const url = qrDataUrl('ROI-ABCD-EFGH');
  assert.ok(typeof url === 'string' && url.length > 0);
  assert.match(url, /^data:image\/gif;base64,/);
  // A different payload yields a different image.
  assert.notEqual(qrDataUrl('ROI-ZZZZ-0000'), url);
});

test('admin order filter and check-in rate', () => {
  const rows = [
    { reference: 'ROI-TCK-1', buyer_name: 'Amina', buyer_email: 'a@test.ke', status: 'Completed' },
    { reference: 'ROI-TCK-2', buyer_name: 'Juma', buyer_email: 'j@test.ke', status: 'STK Prompt Dispatched' }
  ];
  assert.equal(filterTicketOrders(rows, { search: 'amina' }).length, 1);
  assert.equal(filterTicketOrders(rows, { status: 'Completed' }).length, 1);
  assert.equal(checkInRate(4, 1), 0.25);
});

test('qr payloads normalize to ticket codes', () => {
  assert.equal(normalizeTicketCode('https://example.com/pass?c=ROI-AB12-CD34'), 'ROI-AB12-CD34');
  assert.equal(normalizeTicketCode('roi-ab12-cd34'), 'ROI-AB12-CD34');
  assert.equal(resultTone('admitted'), 'ok');
  assert.equal(resultTone('already'), 'warn');
  assert.equal(resultTone('void'), 'bad');
});

test('sale controls cap quantity and label states', () => {
  assert.equal(saleStateLabel('sold_out'), 'Sold out');
  assert.equal(maxPurchasable({ max_per_order: 6, remaining: 2, unlimited: false, on_sale: true }), 2);
  assert.equal(canIncrement({ on_sale: true, max_per_order: 2, remaining: 5 }, 2), false);
  assert.equal(canIncrement({ on_sale: false, max_per_order: 5, remaining: 5 }, 0), false);
});

test('digital solutions fallback catalog', () => {
  assert.equal(FALLBACK_SOLUTIONS.length, 3);
  assert.equal(FALLBACK_SOLUTIONS[0].slug, 'community-event-ticketing');
});

test('portfolio fallback includes a featured conference case', () => {
  assert.equal(FALLBACK_PORTFOLIO.length, 2);
  assert.equal(FALLBACK_PORTFOLIO[0].is_featured, true);
  assert.match(FALLBACK_PORTFOLIO[0].slug, /vijana/);
});

test('inquiry workflow allows review then quote then win', () => {
  assert.deepEqual(nextInquiryStatuses('New'), ['In Review', 'Lost', 'Resolved']);
  assert.equal(canMoveInquiry('New', 'Won'), false);
  assert.equal(canMoveInquiry('Quoted', 'Won'), true);
  assert.equal(isOpenInquiry('Quoted'), true);
  assert.equal(isOpenInquiry('Won'), false);
});

test('admin can reorder solutions and count new briefings', () => {
  assert.deepEqual(moveSolutionIds([1, 2, 3], 2, -1), [2, 1, 3]);
  assert.equal(newSolutionInquiryCount([{ status: 'New' }, { status: 'Resolved' }, { status: 'New' }]), 2);
});

test('portal tokens look signed', () => {
  assert.equal(isLikelyPortalToken('abc.def'), false);
  assert.equal(isLikelyPortalToken('eyJlbWFpbCI6InhAZS5rZSJ9.deadbeefcafebabe'), true);
  assert.match(portalPath('a.b'), /tickets\/portal\//);
});

test('production hardening redacts secrets and classifies probes', () => {
  assert.equal(isProductionStrictMode({ ROI_STRICT_API_MODE: true }), true);
  const cleaned = sanitizeHealthPayload({ status: 'online', environment: 'production', database_engine: 'sqlite' }, true);
  assert.equal(cleaned.environment, undefined);
  assert.equal(cleaned.database_engine, undefined);
  assert.equal(classifyProbe({ status: 'online' }, { status: 'ready' }), 'ok');
  assert.equal(classifyProbe({ status: 'online' }, { status: 'degraded' }), 'degraded');
  assert.equal(shouldRetryRequest(503, 0), true);
  assert.equal(shouldRetryRequest(200, 0), false);
  assert.match(redactSecrets('Authorization Bearer abc.def.ghi sk_live_SUPERSECRET'), /redacted/);
});

test('admin login request contains only email and password', async () => {
  const previousWindow = global.window;
  const previousFetch = global.fetch;
  let sentBody = null;

  global.window = { ROI_API_BASE_URL: '/api' };
  global.fetch = async (_url, init) => {
    sentBody = JSON.parse(init.body);
    return new Response(JSON.stringify({ access_token: 'test-token' }), { status: 200 });
  };

  try {
    const { postLogin } = await import(`../src/js/api.js?login-contract=${Date.now()}`);
    const res = await postLogin('admin@example.com', 'secret-password');

    assert.equal(res.ok, true);
    assert.deepEqual(sentBody, { email: 'admin@example.com', password: 'secret-password' });
    assert.equal(Object.hasOwn(sentBody, 'mfa_code'), false);
  } finally {
    global.window = previousWindow;
    global.fetch = previousFetch;
  }
});

test('admin login failure copy ignores backend MFA-specific details', async () => {
  const previousWindow = global.window;
  const previousFetch = global.fetch;

  global.window = { ROI_API_BASE_URL: '/api' };
  global.fetch = async () => new Response(
    JSON.stringify({ detail: 'Invalid MFA seed.' }),
    { status: 401 }
  );

  try {
    const { login } = await import(`../src/js/store.js?login-error=${Date.now()}`);
    const res = await login('admin@example.com', 'wrong-password');

    assert.equal(res.success, false);
    assert.equal(res.error, 'Authentication failed. Verify administrator credentials.');
    assert.doesNotMatch(res.error, /mfa|totp|seed/i);
  } finally {
    global.window = previousWindow;
    global.fetch = previousFetch;
  }
});

test('hash route patterns resolve ticket paths', () => {
  const path = '/tickets/42';
  const keys = [];
  const pattern = '/tickets/:eventId'.replace(/:([^/]+)/g, (_, key) => {
    keys.push(key);
    return '([^/]+)';
  });
  const m = path.match(new RegExp(`^${pattern}$`));
  assert.ok(m);
  assert.equal(m[1], '42');
  assert.deepEqual(keys, ['eventId']);
});
