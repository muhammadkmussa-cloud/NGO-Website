import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { FALLBACK_EVENTS, FALLBACK_PORTFOLIO, FALLBACK_SOLUTIONS, FALLBACK_TICKET_CATALOG } from '../src/js/data/fallbackData.js';
import {
  checkoutCurrency,
  checkoutGateway,
  checkoutMode,
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
import { eatToUtc, utcToEat } from '../src/js/tz.js';
import {
  formatNextPaymentDate,
  interpretDonationStatus,
  interpretSubscriptionBadge,
  isRetryableVerifyError,
  normalizeDonateReference
} from '../src/js/payments/donateFlow.js';

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

test('free carts claim without payment chrome, paid carts keep gateways', () => {
  assert.equal(checkoutMode([]), 'empty');
  assert.equal(checkoutMode([{ type: { price: 0 }, quantity: 2 }]), 'free');
  assert.equal(checkoutMode([{ type: { price: 500 }, quantity: 1 }]), 'paid');
  assert.equal(checkoutMode([{ type: { price: 0 } }, { type: { price: 100 } }]), 'paid');
  assert.equal(checkoutGateway('free', 'Paystack'), 'Free');
  assert.equal(checkoutGateway('paid', 'M-Pesa'), 'M-Pesa');
  assert.equal(checkoutGateway('empty', 'Paystack'), 'Paystack');
  assert.equal(validateTicketPayment({ items: [{ ticket_type_id: 1, quantity: 1 }], gateway: 'Free', buyerPhone: '', total: 0 }), null);
});

test('admin sales windows convert between EAT and UTC', () => {
  assert.equal(eatToUtc('2026-12-01T09:00'), '2026-12-01T06:00');
  assert.equal(utcToEat('2026-12-01T06:00'), '2026-12-01T09:00');
  assert.equal(eatToUtc('2026-09-30T20:29'), '2026-09-30T17:29');
  assert.equal(utcToEat('2026-09-30T17:29'), '2026-09-30T20:29');
  assert.equal(eatToUtc(''), null);
  assert.equal(utcToEat(''), '');
  assert.equal(utcToEat(eatToUtc('2026-06-15T14:45')), '2026-06-15T14:45');
  assert.equal(eatToUtc(utcToEat('2026-06-15T14:45')), '2026-06-15T14:45');
});

test('every src module parses (import smoke test)', async () => {
  const base = new URL('../src/js/', import.meta.url);
  const files = readdirSync(base, { recursive: true })
    .map(String)
    .filter((f) => f.endsWith('.js') && !f.includes('vendor') && f !== 'main.js');
  // main.js is excluded: it touches document/window at import time (browser only).
  assert.ok(files.length > 10, 'expected to find source modules');
  const previousWindow = global.window;
  const previousDocument = global.document;
  // api.js/ui.js/router.js/header.js touch window/document at import time;
  // stub them so Node can parse every module. main.js stays excluded (DOM app bootstrap).
  global.window = {
    ROI_API_BASE_URL: '/api',
    addEventListener() {},
    removeEventListener() {},
    location: { pathname: '/', hash: '', search: '' }
  };
  global.document = {
    addEventListener() {},
    removeEventListener() {},
    getElementById: () => null,
    querySelector: () => null,
    querySelectorAll: () => [],
    createElement: () => ({ style: {}, classList: { add() {}, remove() {} }, setAttribute() {}, appendChild() {} }),
    body: { appendChild() {} },
    documentElement: { classList: { add() {}, remove() {} } }
  };
  try {
    for (const f of files) {
      await import(new URL(f, base));
    }
  } finally {
    global.window = previousWindow;
    global.document = previousDocument;
  }
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

test('gate page exposes no undo; admin desk exposes undo', () => {
  const gate = readFileSync(new URL('../src/js/pages/checking.js', import.meta.url), 'utf8');
  assert.equal(gate.includes('roi-gate-undo'), false);
  assert.equal(gate.includes('undo-check-in'), false);

  const admin = readFileSync(new URL('../src/js/pages/adminDashboard.js', import.meta.url), 'utf8');
  assert.ok(admin.includes('id="roi-checkin-undo"'));
  assert.ok(admin.includes('/undo-check-in'));
  assert.equal(admin.includes('/gate/tickets/'), false);
});

test('donation verify status mapping drives polling and tone', () => {
  const completed = interpretDonationStatus('Completed');
  assert.equal(completed.tone, 'ok');
  assert.equal(completed.poll, false);

  const failed = interpretDonationStatus('Failed (Abandoned)');
  assert.equal(failed.tone, 'fail');
  assert.equal(failed.poll, false);

  const pending = interpretDonationStatus('Pending Paystack Checkout');
  assert.equal(pending.tone, 'pending');
  assert.equal(pending.poll, true);

  const unreachable = interpretDonationStatus('Verification Failed (Gateway Unreachable)');
  assert.equal(unreachable.tone, 'pending');
  assert.equal(unreachable.poll, true);

  const unknown = interpretDonationStatus('Something New');
  assert.equal(unknown.tone, 'pending');
  assert.equal(unknown.poll, true);

  const empty = interpretDonationStatus('');
  assert.equal(empty.tone, 'pending');
  assert.equal(empty.poll, true);
});

test('donations: reference normalization + retryable classification', () => {
  // Paystack appends ?reference=…&trxref=… after the hash fragment.
  assert.equal(normalizeDonateReference('REF?reference=REF&trxref=REF'), 'REF');
  assert.equal(normalizeDonateReference('REF'), 'REF');
  assert.equal(normalizeDonateReference('  spaced  '), 'spaced');
  assert.equal(normalizeDonateReference(undefined), '');
  assert.equal(normalizeDonateReference(null), '');

  assert.equal(isRetryableVerifyError(undefined), true); // network/abort/timeout
  assert.equal(isRetryableVerifyError(429), true); // shared verify throttle
  assert.equal(isRetryableVerifyError(500), true);
  assert.equal(isRetryableVerifyError(502), true); // gateway unreachable
  assert.equal(isRetryableVerifyError(503), true); // payments disabled
  assert.equal(isRetryableVerifyError(400), false);
  assert.equal(isRetryableVerifyError(403), false);
  assert.equal(isRetryableVerifyError(404), false);
});

test('donation return route is registered hash-safe', () => {
  const main = readFileSync(new URL('../src/js/main.js', import.meta.url), 'utf8');
  assert.ok(main.includes("register('/donate/verify/:reference'"));

  const controller = readFileSync(
    new URL('../../laravel-backend/app/Http/Controllers/PaymentController.php', import.meta.url),
    'utf8'
  );
  assert.ok(controller.includes('/#/donate/verify/'));
  assert.equal(controller.includes('/donate/verify?reference='), false);
});

test('donation modal: KES+USD only, loading state, no M-Pesa gateway, conditional KCB card', () => {
  const modal = readFileSync(new URL('../src/js/components/donationModal.js', import.meta.url), 'utf8');

  // KES + USD only — Paystack (Kenya) rejects EUR/GBP with "no active channel".
  assert.ok(modal.includes("['KES', 'USD']"));
  assert.equal(modal.includes('EUR'), false);
  assert.equal(modal.includes('GBP'), false);
  assert.ok(modal.includes('International cards welcome'));

  // A1: paybills starts null (loading) so the modal never flashes
  // "temporarily unavailable" while the API is in flight.
  assert.ok(modal.includes('let paybills = null'));
  assert.ok(modal.includes('data-payments-loading'));
  assert.ok(modal.includes('Loading contribution channels'));

  // A4: M-Pesa Push gateway hidden until Daraja is configured in prod.
  assert.equal(modal.includes('data-set-gateway'), false);

  // A5: KCB card only renders when the backend actually returns one.
  assert.ok(modal.includes('pb.kcb_mpesa || null'));
  assert.ok(modal.includes('${kcb ? `'));
});

test('monthly pledge badge maps subscription states for the return page', () => {
  assert.equal(interpretSubscriptionBadge(null), null);
  assert.equal(interpretSubscriptionBadge(undefined), null);
  assert.equal(interpretSubscriptionBadge({}), null);
  assert.equal(interpretSubscriptionBadge({ status: '' }), null);

  const active = interpretSubscriptionBadge({
    status: 'active',
    next_payment_date: '2026-11-01T00:00:00.000Z'
  });
  assert.equal(active.tone, 'ok');
  assert.match(active.label, /active/i);
  assert.match(active.detail, /Next charge/);

  const cancelling = interpretSubscriptionBadge({ status: 'cancelling' });
  assert.equal(cancelling.tone, 'pending');
  assert.match(cancelling.detail, /will not renew/);

  const disabled = interpretSubscriptionBadge({ status: 'disabled' });
  assert.equal(disabled.tone, 'fail');
  assert.match(disabled.detail, /No further charges/);

  const pastDue = interpretSubscriptionBadge({ status: 'past_due' });
  assert.equal(pastDue.tone, 'fail');
  assert.match(pastDue.detail, /Manage/);

  // Unknown status never crashes — stays a neutral pending badge.
  const unknown = interpretSubscriptionBadge({ status: 'weird' });
  assert.equal(unknown.tone, 'pending');
});

test('formatNextPaymentDate renders humans and tolerates junk', () => {
  assert.equal(formatNextPaymentDate(null), '');
  assert.equal(formatNextPaymentDate(undefined), '');
  assert.equal(formatNextPaymentDate(''), '');
  assert.equal(formatNextPaymentDate('not-a-date'), '');
  assert.match(formatNextPaymentDate('2026-11-01T00:00:00.000Z'), /2026/);
});

test('monthly pledge UI wiring: modal note, verify badge, manage link', () => {
  const modal = readFileSync(new URL('../src/js/components/donationModal.js', import.meta.url), 'utf8');
  assert.ok(modal.includes("freqBtn('monthly', 'Monthly Pledge')"));
  assert.ok(modal.includes("s.frequency === 'monthly'"));
  assert.ok(modal.includes('Charged automatically each month'));

  const verify = readFileSync(new URL('../src/js/pages/donateVerify.js', import.meta.url), 'utf8');
  assert.ok(verify.includes('interpretSubscriptionBadge'));
  assert.ok(verify.includes('id="roi-verify-manage"'));
  assert.ok(verify.includes('getDonationManageLink'));
  // The page never renders raw pledge secrets.
  assert.equal(verify.includes('subscription_code'), false);
  assert.equal(verify.includes('subscription_token'), false);

  const api = readFileSync(new URL('../src/js/api.js', import.meta.url), 'utf8');
  assert.ok(api.includes('export const getDonationManageLink'));
  assert.ok(api.includes('`/payments/subscription/${encodeURIComponent(reference || \'\')}/manage`'));

  const controller = readFileSync(
    new URL('../../laravel-backend/app/Http/Controllers/PaymentController.php', import.meta.url),
    'utf8'
  );
  assert.ok(controller.includes('function subscriptionManage'));
  assert.ok(controller.includes('createOrGetMonthlyPlan'));
});

test('A1: monthly pledge Card|Mobile Money selector routes to /api/pledges', () => {
  const modal = readFileSync(new URL('../src/js/components/donationModal.js', import.meta.url), 'utf8');
  const api = readFileSync(new URL('../src/js/api.js', import.meta.url), 'utf8');
  const controller = readFileSync(
    new URL('../../laravel-backend/app/Http/Controllers/PledgeController.php', import.meta.url),
    'utf8'
  );

  // Selector exists only for KES monthly pledges (USD monthly = card-only).
  assert.ok(modal.includes("s.frequency === 'monthly' && s.currency === 'KES'"));
  assert.ok(modal.includes('data-set-pledge-method'));
  assert.ok(modal.includes("'card', 'credit-card', 'Card'"));
  assert.ok(modal.includes("'mobile_money', 'smartphone', 'Mobile Money'"));
  // A4: the old Daraja gateway toggle never comes back — Mobile Money is a
  // pledge payment method, not a global gateway switch.
  assert.equal(modal.includes('data-set-gateway'), false);

  // Choosing Mobile Money reveals the phone field (it never existed before)
  // and requires the email that carries the receipt + secure link.
  assert.ok(modal.includes('data-donor-phone'));
  assert.ok(modal.includes("'Email is required for a monthly pledge"));

  // The new rail posts to /api/pledges; the card path still uses checkout.
  assert.ok(modal.includes('createMonthlyPledge'));
  assert.ok(api.includes('export const createMonthlyPledge'));
  assert.ok(api.includes("request('POST', '/pledges'"));
  assert.ok(modal.includes('initiateDonation'));

  // Branch-aware copy: card wording is preserved, M-Pesa wording exists.
  assert.ok(modal.includes('Charged automatically each month by card'));
  assert.ok(modal.includes('M-Pesa prompt on your phone'));

  // Backend contract: truthful charge_error payload + KES ceiling + phone.
  assert.ok(controller.includes("'charge_error'"));
  assert.ok(controller.includes('max:150000'));
  assert.ok(controller.includes("'phone' => ['required'"));
});

test('monthly pledges require an email on both rails', () => {
  const modal = readFileSync(new URL('../src/js/components/donationModal.js', import.meta.url), 'utf8');
  const payments = readFileSync(
    new URL('../../laravel-backend/app/Http/Controllers/PaymentController.php', import.meta.url),
    'utf8'
  );

  // Frontend: the monthly guard and the input's required attribute cover card
  // AND mobile money (no longer M-Pesa-only).
  assert.ok(modal.includes("state.frequency === 'monthly' && !email"));
  assert.ok(modal.includes("state.frequency === 'monthly' ? ' required' : ''"));
  // The donor@example.com sentinel fallback is one-time only.
  assert.ok(modal.includes("state.frequency === 'monthly' ? email : (email || 'donor@example.com')"));

  // Backend: /payments/checkout requires a valid email when frequency=monthly.
  assert.ok(payments.includes('Rule::requiredIf'));
  assert.ok(payments.includes("=== 'monthly'"));
});
