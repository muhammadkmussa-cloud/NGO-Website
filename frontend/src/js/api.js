// API client — vanilla JS port of frontend/src/services/api.js (axios version).
// Preserves: base URL resolution, 5s timeout, Bearer token injection,
// STRICT_API_MODE error throwing, offline fallback engine with the
// `roi_api_fallback_triggered` CustomEvent, and demo-response synthesis.

import { FALLBACK_BLOGS, FALLBACK_EVENTS, FALLBACK_MEDIA, FALLBACK_METRICS, FALLBACK_PORTFOLIO, FALLBACK_SOLUTIONS, FALLBACK_TICKET_CATALOG, FALLBACK_FAQS, FALLBACK_INDUSTRIES, FALLBACK_TECH } from './data/fallbackData.js';
import { getToken } from './store.js';

// M-3: the API base is a deploy-time constant only. A client-controllable
// override (?apiBase=https://evil.tld) let a phishing link make this app ship
// the admin Bearer token to an attacker's origin.
const BASE_URL = window.ROI_API_BASE_URL || '/api';

// M-3/H-1: strict mode is the CODE DEFAULT — demo fallbacks require an explicit
// deploy-time opt-out (window.ROI_STRICT_API_MODE = false set before this module
// loads, e.g. from a small /js/roi-config.js served same-origin). An HTML inline
// script cannot carry this flag anymore because CSP script-src 'self' blocks
// inline scripts by design.
const STRICT_API_MODE = window.ROI_STRICT_API_MODE !== false;
const REQUEST_TIMEOUT_MS = 5000;

function nextRequestId() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
  return `roi-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

// M-3: the admin JWT is only ever needed on privileged endpoints. Attaching it
// to every request (public ones included) widened the blast radius of any
// cross-origin base-URL confusion; keep the token scoped to what requires it.
const TOKEN_ENDPOINT_PREFIXES = ['/admin/', '/public/media/refresh'];

function shouldAttachToken(endpoint) {
  return TOKEN_ENDPOINT_PREFIXES.some((prefix) => endpoint.startsWith(prefix));
}

async function request(method, endpoint, body) {
  const token = shouldAttachToken(endpoint) ? getToken() : null;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

  try {
    const res = await fetch(BASE_URL + endpoint, {
      method,
      headers: {
        'Content-Type': 'application/json',
        'X-Request-Id': nextRequestId(),
        ...(token ? { Authorization: `Bearer ${token}` } : {})
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
      signal: controller.signal
    });

    // Parse JSON when present; mirror axios behavior of res.data
    let data = null;
    const text = await res.text();
    if (text) {
      try { data = JSON.parse(text); } catch { data = text; }
    }

    return {
      ok: res.ok,
      status: res.status,
      data,
      // Axios-compatible error surface used throughout the UI code.
      response: res.ok ? undefined : { status: res.status, data }
    };
  } catch (err) {
    // Normalize network/abort failures into an axios-like error object.
    const message = err.name === 'AbortError' ? `timeout of ${REQUEST_TIMEOUT_MS}ms exceeded` : (err.message || 'Network Error');
    const e = new Error(message);
    e.response = undefined; // network errors have no server response (matches axios)
    throw e;
  } finally {
    clearTimeout(timer);
  }
}

function emitFallback(endpoint, err) {
  console.warn(`[OFFLINE PREVIEW FALLBACK] Backend unreachable for ${endpoint}: ${err.message}`);
  window.dispatchEvent(
    new CustomEvent('roi_api_fallback_triggered', {
      detail: { endpoint, error: err.message }
    })
  );
}

export const safeGet = async (endpoint, fallback) => {
  try {
    const res = await request('GET', endpoint);
    if (!res.ok) throw Object.assign(new Error(`Request failed with status code ${res.status}`), { response: res.response });
    return res.data;
  } catch (err) {
    if (STRICT_API_MODE) {
      console.error(`[STRICT PRODUCTION OUTAGE] Fatal API failure for ${endpoint}:`, err);
      throw err;
    }
    emitFallback(endpoint, err);
    return fallback;
  }
};

export const getBlogs = () => safeGet('/public/blog', FALLBACK_BLOGS);
export const getEvents = () => safeGet('/public/events', FALLBACK_EVENTS);
export const getMedia = (refresh = false) => safeGet(refresh ? '/public/media/refresh' : '/public/media', FALLBACK_MEDIA);
export const getLatestMedia = () => safeGet('/public/media/latest', FALLBACK_MEDIA.slice(0, 3));
export const getMetrics = () => safeGet('/public/metrics', FALLBACK_METRICS);

// Admin-editable site content (hero + impact metrics).
const SITE_FALLBACK = {
  hero: {
    eyebrow: 'Flagship Conference 2026',
    title: 'Youth Leadership Summit',
    description: 'Uniting 500+ coastal youth for mentorship, ethical leadership grounding, and digital career advancement.',
    image_url: 'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=900&q=80'
  },
  metrics: { youth_mentored: 120, events_hosted: 2, individuals_supported: 95, active_volunteers: 45 }
};
export const getSite = () => safeGet('/public/site', SITE_FALLBACK);

// Multipart image upload for the admin console (blog/event/hero media).
export const uploadImage = async (file) => {
  const token = getToken();
  const form = new FormData();
  form.append('file', file);
  const res = await fetch(BASE_URL + '/admin/uploads', {
    method: 'POST',
    headers: {
      'X-Request-Id': nextRequestId(),
      ...(token ? { Authorization: `Bearer ${token}` } : {})
    },
    body: form
  });
  const text = await res.text();
  let data = null;
  if (text) {
    try { data = JSON.parse(text); } catch { data = text; }
  }
  if (!res.ok) {
    const firstFieldError = data && data.errors && Object.values(data.errors)[0];
    const detail = (data && (data.detail || data.message))
      || (Array.isArray(firstFieldError) ? firstFieldError[0] : firstFieldError)
      || 'Upload failed';
    throw Object.assign(new Error(detail), { response: { status: res.status, data } });
  }
  return data;
};

export const submitVolunteer = async (data) => {
  // H-1: submissions must never fabricate success — errors always surface.
  const res = await request('POST', '/public/volunteer', data);
  if (!res.ok) throw Object.assign(new Error('Request failed'), { response: res.response });
  return res.data;
};

export const submitContact = async (data) => {
  // H-1: submissions must never fabricate success — errors always surface.
  const res = await request('POST', '/public/contact', data);
  if (!res.ok) throw Object.assign(new Error('Request failed'), { response: res.response });
  return res.data;
};

export const getHealth = () => safeGet('/health', { status: 'offline' });
export const getReady = () => safeGet('/ready', { status: 'degraded', checks: { database: 'unknown' } });

export const getSolutions = () => safeGet('/public/solutions', FALLBACK_SOLUTIONS);
export const getFaqs = () => safeGet('/public/solution-faqs', FALLBACK_FAQS);
export const getIndustries = () => safeGet('/public/industries', FALLBACK_INDUSTRIES);
export const getTechCapabilities = () => safeGet('/public/tech', FALLBACK_TECH);
export const getPortfolio = (featured = false) =>
  safeGet(featured ? '/public/portfolio?featured=1' : '/public/portfolio', featured ? FALLBACK_PORTFOLIO.filter((p) => p.is_featured) : FALLBACK_PORTFOLIO);
export const inquireSolution = async (data) => {
  const res = await request('POST', '/public/solutions/inquire', data);
  if (!res.ok) throw Object.assign(new Error(res.data?.detail || 'Request failed'), { response: res.response });
  return res.data;
};

export const getEventTickets = (eventId) =>
  safeGet(`/public/events/${eventId}/tickets`, FALLBACK_TICKET_CATALOG(eventId));

export const checkoutTickets = async (data) => {
  // H-1: money flows must never fabricate success — errors always surface.
  const res = await request('POST', '/tickets/checkout', data);
  if (!res.ok) throw Object.assign(new Error(res.data?.detail || 'Request failed'), { response: res.response });
  return res.data;
};

// H-3: order endpoints are gated by reference + buyer email confirmation.
export const getTicketOrder = (reference, email) =>
  safeGet(`/tickets/orders/${encodeURIComponent(reference || '')}${emailQuery(email)}`, null);
export const verifyTicketOrder = (reference, email) =>
  safeGet(`/tickets/orders/${encodeURIComponent(reference || '')}/verify${emailQuery(email)}`, null);

function emailQuery(email) {
  return email ? `?email=${encodeURIComponent(email)}` : '';
}

export const PAYBILLS_FALLBACK = {
  enabled: false,
  message: 'Our secure contribution channels are being prepared. Please contact our team in the meantime.'
};

export const getPaybills = () => safeGet('/payments/paybills', PAYBILLS_FALLBACK);

export const retryTicketStk = async (reference, buyerPhone, email) => {
  const res = await request('POST', `/tickets/orders/${encodeURIComponent(reference || '')}/stk-retry`, {
    email,
    buyer_phone: buyerPhone || undefined
  });
  if (!res.ok) throw Object.assign(new Error(res.data?.detail || 'Request failed'), { response: res.response });
  return res.data;
};

export const initiateDonation = async (data) => {
  // H-1: money flows must never fabricate success — errors always surface.
  const res = await request('POST', '/payments/checkout', data);
  if (!res.ok) throw Object.assign(new Error(res.data?.detail || 'Request failed'), { response: res.response });
  return res.data;
};

// Monthly M-Pesa pledge (plan A1): creates the pledge + first STK prompt in
// one call. Card pledges keep the Phase B `initiateDonation` path above — the
// two rails never mix (spec §14).
export const createMonthlyPledge = async (data) => {
  const res = await request('POST', '/pledges', data);
  if (!res.ok) throw Object.assign(new Error(res.data?.detail || 'Request failed'), { response: res.response });
  return res.data;
};

// Donation/ticket payment verification by reference alone (sanitized payload
// unless the donor email is confirmed server-side).
export const verifyDonationPayment = (reference) =>
  safeGet(`/payments/verify/${encodeURIComponent(reference || '')}`, null);

// Hosted Paystack pledge page (view card / cancel) for a monthly reference.
export const getDonationManageLink = async (reference) => {
  const res = await request('GET', `/payments/subscription/${encodeURIComponent(reference || '')}/manage`);
  if (!res.ok) throw Object.assign(new Error(res.data?.detail || 'Request failed'), { response: res.response });
  return res.data;
};

// Raw login POST for the auth store — mirrors AuthContext using api.post directly
// (login failures do NOT trigger the fallback banner).
export const postLogin = async (email, password) => request('POST', '/auth/login', { email, password });

export const postLogout = () => request('POST', '/auth/logout');

// Generic admin CRUD helpers used by the dashboard (raw, errors surface to caller).
export const adminPost = (endpoint, body) => request('POST', endpoint, body).then((r) => {
  if (!r.ok) throw Object.assign(new Error('Request failed'), { response: r.response });
  return r.data;
});
export const adminPut = (endpoint, body) => request('PUT', endpoint, body).then((r) => {
  if (!r.ok) throw Object.assign(new Error('Request failed'), { response: r.response });
  return r.data;
});
export const adminDelete = (endpoint) => request('DELETE', endpoint).then((r) => {
  if (!r.ok) throw Object.assign(new Error('Request failed'), { response: r.response });
  return r.data;
});

export { request };
