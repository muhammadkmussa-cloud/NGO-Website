// Paystack return page: #/donate/verify/:reference
// Confirms a contribution after Paystack redirects the buyer back to the SPA.
import { getDonationManageLink, verifyDonationPayment } from '../api.js';
import { escapeHtml } from '../ui.js';
import {
  DONATE_VERIFY_MAX_POLLS as MAX_POLLS,
  DONATE_VERIFY_POLL_MS as POLL_MS,
  interpretDonationStatus,
  interpretSubscriptionBadge,
  isRetryableVerifyError,
  normalizeDonateReference
} from '../payments/donateFlow.js';

const TONE_STYLES = {
  ok: { dot: 'bg-emerald-500', text: 'text-emerald-400', border: 'border-emerald-500/40' },
  fail: { dot: 'bg-rose-500', text: 'text-rose-400', border: 'border-rose-500/40' },
  pending: { dot: 'bg-amber-400 animate-pulse', text: 'text-amber-300', border: 'border-amber-400/40' }
};

function formatAmount(amount, currency) {
  const n = Number(amount);
  if (!Number.isFinite(n)) return '';
  try {
    return `${n.toLocaleString('en-KE', { maximumFractionDigits: 2 })} ${escapeHtml(String(currency || 'KES'))}`;
  } catch {
    return `${n} ${escapeHtml(String(currency || 'KES'))}`;
  }
}

function friendlyError(err) {
  const detail = err?.response?.data?.detail;
  if (typeof detail === 'string' && detail.trim()) return detail.trim();
  const raw = String(err?.message || '');
  // Generic HTTP/runtime strings make poor user copy — keep the default instead.
  if (!raw || /status code|request failed|timeout|network error/i.test(raw)) return '';
  return raw;
}

export function renderDonateVerify(root, params = {}) {
  // Paystack may append ?reference=…&trxref=… after the fragment; the route
  // param can swallow that query string whole, so normalize it.
  const reference = normalizeDonateReference(params.reference);
  let pollTimer = null;
  let polls = 0;
  // Set on navigation so an in-flight verify can never re-arm the poller on a
  // detached instance (which would drain the shared per-IP verify throttle).
  let destroyed = false;

  root.innerHTML = `
    <div class="py-16 sm:py-20 bg-slate-900 min-h-screen">
      <div id="roi-donate-verify-body" class="max-w-lg mx-auto px-4 space-y-6">
        <div class="bg-slate-800 animate-pulse h-48 rounded-3xl"></div>
      </div>
    </div>`;

  const body = root.querySelector('#roi-donate-verify-body');

  function stopPolling() {
    if (pollTimer) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }
  }

  // Leaving the page cancels this instance's poller so stale pages never keep
  // draining the shared per-IP verify throttle (30/min).
  function onNavigate() {
    destroyed = true;
    stopPolling();
    window.removeEventListener('hashchange', onNavigate);
  }
  window.addEventListener('hashchange', onNavigate);

  function scheduleNext() {
    if (destroyed) return;
    polls += 1;
    pollTimer = setTimeout(load, POLL_MS);
  }

  function skeleton() {
    body.innerHTML = '<div class="bg-slate-800 animate-pulse h-48 rounded-3xl"></div>';
  }

  function notFound() {
    stopPolling();
    body.innerHTML = `
      <div class="bg-slate-950 border border-slate-800 rounded-3xl p-8 space-y-4 text-center">
        <h1 class="text-xl font-black text-white">Reference not found</h1>
        <p class="text-sm text-slate-300">We could not find payment reference
          <span class="font-mono text-amber-300">${escapeHtml(reference)}</span>.</p>
        <a href="#/" class="inline-block px-6 py-3 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-sm font-bold">Back home</a>
      </div>`;
  }

  function paintError(err) {
    stopPolling();
    polls = 0; // a manual retry earns a fresh polling budget
    const message = friendlyError(err);
    body.innerHTML = `
      <div class="bg-slate-950 border border-slate-800 rounded-3xl p-8 space-y-4 text-center">
        <h1 class="text-xl font-black text-white">Could not verify payment</h1>
        <p class="text-sm text-slate-300">${escapeHtml(message || 'We could not verify this payment right now — your payment is safe. Please check again.')}</p>
        <div class="flex items-center justify-center gap-3">
          <button type="button" id="roi-verify-retry" class="px-6 py-3 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-sm font-bold">Check again</button>
          <a href="#/" class="px-6 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm font-bold">Back home</a>
        </div>
      </div>`;
    body.querySelector('#roi-verify-retry')?.addEventListener('click', () => {
      skeleton();
      load();
    });
  }

  // Transient failure (gateway unreachable, throttle, timeout, 5xx, non-JSON
  // body): keep the "we keep checking" promise instead of dead-ending the donor.
  function paintTransient() {
    if (polls >= MAX_POLLS) {
      paintError(null);
      return;
    }
    const tone = TONE_STYLES.pending;
    body.innerHTML = `
      <div class="bg-slate-950 border ${tone.border} rounded-3xl p-8 space-y-5 text-center">
        <div role="status" aria-live="polite" class="inline-flex items-center gap-2 text-xs font-black uppercase tracking-widest ${tone.text}">
          <span class="w-2.5 h-2.5 rounded-full ${tone.dot}"></span>
          Payment not confirmed yet
        </div>
        <p class="text-sm text-slate-300">We could not reach the verification service for a moment. This page will keep checking.</p>
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 text-left text-xs font-mono text-slate-300">
          <div class="flex justify-between gap-3"><span class="text-slate-500">Reference</span><span class="break-all text-right">${escapeHtml(reference)}</span></div>
        </div>
        <p class="text-[11px] text-slate-500">Auto-refreshing while the payment settles…</p>
      </div>`;
    scheduleNext();
  }

  function paint(result) {
    const view = interpretDonationStatus(result.status);
    const tone = TONE_STYLES[view.tone] || TONE_STYLES.pending;
    const amount = formatAmount(result.amount, result.currency);
    const polling = view.poll && polls < MAX_POLLS;
    const exhausted = view.poll && !polling;
    const subBadge = interpretSubscriptionBadge(result.subscription);
    const badgeTone = subBadge ? (TONE_STYLES[subBadge.tone] || TONE_STYLES.pending) : null;
    const canManage = Boolean(result.subscription);

    body.innerHTML = `
      <div class="bg-slate-950 border ${tone.border} rounded-3xl p-8 space-y-5 text-center">
        <div role="status" aria-live="polite" class="inline-flex items-center gap-2 text-xs font-black uppercase tracking-widest ${tone.text}">
          <span class="w-2.5 h-2.5 rounded-full ${tone.dot}"></span>
          ${escapeHtml(view.heading)}
        </div>
        <p class="text-sm text-slate-300">${escapeHtml(view.hint)}</p>
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 text-left space-y-2 text-xs font-mono text-slate-300">
          <div class="flex justify-between gap-3"><span class="text-slate-500">Reference</span><span class="break-all text-right">${escapeHtml(result.reference || reference)}</span></div>
          ${amount ? `<div class="flex justify-between gap-3"><span class="text-slate-500">Amount</span><span>${amount}</span></div>` : ''}
          <div class="flex justify-between gap-3"><span class="text-slate-500">Status</span><span class="${tone.text}">${escapeHtml(result.status || 'Unknown')}</span></div>
          <div class="flex justify-between gap-3"><span class="text-slate-500">Gateway</span><span>${escapeHtml(result.gateway || '—')}</span></div>
        </div>
        ${subBadge ? `
        <div class="bg-slate-900 border ${badgeTone.border} rounded-2xl p-4 text-left space-y-1.5">
          <div class="inline-flex items-center gap-2 text-xs font-black uppercase tracking-widest ${badgeTone.text}">
            <span class="w-2 h-2 rounded-full ${badgeTone.dot}"></span>
            ${escapeHtml(subBadge.label)}
          </div>
          ${subBadge.detail ? `<p class="text-[11px] text-slate-400">${escapeHtml(subBadge.detail)}</p>` : ''}
        </div>` : ''}
        ${polling ? '<p class="text-[11px] text-slate-500">Auto-refreshing while the payment settles…</p>' : ''}
        <div class="flex items-center justify-center gap-3 flex-wrap">
          ${exhausted ? '<button type="button" id="roi-verify-retry" class="px-6 py-3 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-sm font-bold">Check again</button>' : ''}
          ${canManage ? '<button type="button" id="roi-verify-manage" class="px-6 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm font-bold">Manage pledge</button>' : ''}
          ${view.tone === 'fail' ? '<a href="#/" class="px-6 py-3 rounded-xl bg-amber-400 hover:bg-amber-300 text-slate-950 text-sm font-black">Try again</a>' : ''}
          <a href="#/" class="px-6 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm font-bold">Back home</a>
        </div>
        <p id="roi-verify-manage-error" class="hidden text-xs text-rose-400" role="alert"></p>
      </div>`;

    body.querySelector('#roi-verify-retry')?.addEventListener('click', () => {
      polls = 0;
      skeleton();
      load();
    });

    body.querySelector('#roi-verify-manage')?.addEventListener('click', () => {
      openManage(result.reference || reference);
    });

    if (polling) scheduleNext();
  }

  // Opens Paystack's hosted pledge page (update card / cancel). We never
  // handle card data ourselves — the link is minted server-side per reference.
  async function openManage(ref) {
    const btn = body.querySelector('#roi-verify-manage');
    const errEl = body.querySelector('#roi-verify-manage-error');
    // m-7: open the tab synchronously here, inside the click handler —
    // popup blockers reject window.open() issued after an await. The tab
    // starts blank and only navigates once the minted URL is in hand.
    let win = null;
    try {
      win = window.open('about:blank', '_blank');
    } catch {
      win = null;
    }
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Opening…';
    }
    if (errEl) errEl.classList.add('hidden');
    try {
      const link = await getDonationManageLink(ref);
      if (destroyed) {
        win?.close();
        return;
      }
      if (link?.url) {
        if (win) {
          // Null the opener BEFORE navigating — the about:blank document is
          // still same-origin here, so the assignment is allowed to stick.
          win.opener = null;
          win.location.replace(link.url);
        } else {
          // Popup was blocked outright — fallback still keeps noopener. This
          // runs after an await (outside the user gesture), so treat a
          // blocked result as a real failure instead of a silent no-op.
          const fallback = window.open(link.url, '_blank', 'noopener,noreferrer');
          if (!fallback) {
            throw new Error('Your browser blocked the pop-up — please allow pop-ups for this site and try again.');
          }
        }
        return;
      }
      throw new Error('The pledge page could not be opened right now.');
    } catch (err) {
      win?.close();
      if (destroyed) return;
      // A poll-driven paint() may have re-rendered body mid-flight — re-query
      // so the error lands on the live node instead of a detached copy.
      const liveErr = body.querySelector('#roi-verify-manage-error');
      if (liveErr) {
        liveErr.textContent = friendlyError(err) || 'We could not open the pledge page right now. Please try again shortly.';
        liveErr.classList.remove('hidden');
      }
    } finally {
      const liveBtn = body.querySelector('#roi-verify-manage');
      if (liveBtn && !destroyed) {
        liveBtn.disabled = false;
        liveBtn.textContent = 'Manage pledge';
      }
    }
  }

  async function load() {
    if (destroyed) return;
    try {
      const result = await verifyDonationPayment(reference);
      if (destroyed) return;
      if (typeof result !== 'object' || result === null) {
        // A proxy/captive portal can answer 200 with an HTML body — retryable.
        paintTransient();
        return;
      }
      if (!result.reference) {
        notFound();
        return;
      }
      paint(result);
    } catch (err) {
      if (destroyed) return;
      const status = err?.response?.status;
      if (status === 404) {
        notFound();
        return;
      }
      if (isRetryableVerifyError(status)) {
        paintTransient();
        return;
      }
      paintError(err);
    }
  }

  load();
}
