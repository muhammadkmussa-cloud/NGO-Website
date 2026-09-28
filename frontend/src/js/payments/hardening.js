/** Production-hardening helpers for the SPA (spec 12). */

export function isProductionStrictMode(win = typeof window !== 'undefined' ? window : {}) {
  return win.ROI_STRICT_API_MODE !== false;
}

export function sanitizeHealthPayload(payload, production = false) {
  if (!payload || typeof payload !== 'object') return { status: 'unknown' };
  const next = { ...payload };
  if (production) {
    delete next.database_engine;
    delete next.environment;
    delete next.debug;
  }
  return next;
}

export function classifyProbe(health, ready) {
  if (!health || health.status !== 'online') return 'down';
  if (!ready || ready.status !== 'ready') return 'degraded';
  return 'ok';
}

export function requestIdFromHeaders(headers) {
  if (!headers) return null;
  if (typeof headers.get === 'function') return headers.get('X-Request-Id') || headers.get('x-request-id');
  return headers['X-Request-Id'] || headers['x-request-id'] || null;
}

export function shouldRetryRequest(status, attempt, maxAttempts = 2) {
  if (attempt >= maxAttempts) return false;
  return status === 429 || status === 502 || status === 503;
}

export function redactSecrets(text) {
  return String(text || '')
    .replace(/Bearer\s+[A-Za-z0-9._-]+/gi, 'Bearer [redacted]')
    .replace(/(sk_live_|sk_test_)[A-Za-z0-9]+/g, '$1[redacted]');
}
