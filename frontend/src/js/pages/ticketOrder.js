import { getTicketOrder, retryTicketStk, verifyTicketOrder, getPaybills } from '../api.js';
import { icon } from '../icons.js';
import { escapeHtml, showToast } from '../ui.js';
import { isTerminalOrderStatus, shouldPollPayment } from '../payments/ticketPayment.js';
import { qrDataUrl, downloadTicketPng } from '../payments/ticketImage.js';

export function renderTicketOrder(root, params = {}) {
  const reference = params.reference;
  let pollTimer = null;
  let polls = 0;

  // H-3: the API requires the buyer's email as ownership confirmation.
  // Reuse the email captured at checkout when available; otherwise ask for it.
  let buyerEmail = '';
  try { buyerEmail = sessionStorage.getItem('roi_buyer_email') || ''; } catch {}

  root.innerHTML = `
    <div class="py-16 sm:py-20 bg-slate-900 min-h-screen">
      <div id="roi-order-body" class="max-w-2xl mx-auto px-4 space-y-6">
        <div class="bg-slate-800 animate-pulse h-48 rounded-3xl"></div>
      </div>
    </div>`;

  const body = root.querySelector('#roi-order-body');

  // Live M-Pesa paybill details (source of truth = /payments/paybills); used by
  // the manual-paybill hint so we never hardcode stale numbers in the UI.
  let paybillsCache = null;
  getPaybills().then((p) => { paybillsCache = p; }).catch(() => {});

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function askForEmail(previousAttempt = '') {
    body.innerHTML = `
      <div class="bg-slate-950 border border-slate-800 rounded-3xl p-8 max-w-lg mx-auto space-y-4">
        <h1 class="text-xl font-black text-white">Confirm your email</h1>
        <p class="text-sm text-slate-400">Enter the email you used at checkout to open order
          <span class="font-mono text-amber-300">${escapeHtml(reference || '')}</span>.</p>
        ${previousAttempt ? `<p class="text-xs text-red-300">We could not find this order for ${escapeHtml(previousAttempt)}. Check for typos.</p>` : ''}
        <form id="roi-order-email-form" class="space-y-3">
          <input required type="email" id="roi-order-email" value="${escapeHtml(previousAttempt)}" placeholder="you@example.com"
            class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm">
          <button type="submit" class="w-full px-4 py-3 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold">Open my order</button>
        </form>
        <a href="#/tickets/recover" class="text-xs font-bold text-sky-400">Lost your tickets? Recover by email</a>
      </div>`;
    body.querySelector('#roi-order-email-form').addEventListener('submit', (e) => {
      e.preventDefault();
      const candidate = String(body.querySelector('#roi-order-email').value || '').trim();
      body.innerHTML = '<div class="bg-slate-800 animate-pulse h-48 rounded-3xl"></div>';
      verifyTicketOrder(reference, candidate)
        .then((order) => {
          // Store only a VALIDATED email so a typo never poisons future visits.
          buyerEmail = candidate;
          try { sessionStorage.setItem('roi_buyer_email', buyerEmail); } catch {}
          paint(order || null);
          if (order && shouldPollPayment(order.status)) startPolling();
        })
        .catch(() => {
          askForEmail(candidate);
        });
    });
  }

  function paint(order) {
    if (!order) {
      // A 404 can mean "unknown reference" OR "email did not match" (H-3).
      if (!buyerEmail) {
        askForEmail();
        return;
      }
      body.innerHTML = `<p class="text-slate-300">We could not find order ${escapeHtml(reference || '')} for ${escapeHtml(buyerEmail)}. Check the email and <button type="button" id="roi-retry-email" class="text-sky-400 font-bold">try again</button>, or recover your tickets below.</p>
        <a href="#/tickets/recover" class="text-xs font-bold text-sky-400">Recover tickets</a>`;
      body.querySelector('#roi-retry-email')?.addEventListener('click', () => {
        const previous = buyerEmail;
        buyerEmail = '';
        try { sessionStorage.removeItem('roi_buyer_email'); } catch {}
        askForEmail(previous);
      });
      return;
    }
    const tickets = order.tickets || [];
    const pending = shouldPollPayment(order.status);
    const failed = String(order.status || '').toLowerCase().startsWith('failed');
    const paybill = paybillsCache?.kcb_mpesa?.paybill || paybillsCache?.equity_bank?.paybill || null;

    body.innerHTML = `
      <a href="#/events" class="text-xs font-bold text-sky-400">${icon('arrow-up-right', 'w-3 h-3 inline rotate-180')} Events</a>
      <a href="#/tickets/recover" class="text-xs font-bold text-slate-400">Lost your tickets?</a>
      <div class="bg-slate-950 border border-slate-800 rounded-3xl p-8 space-y-4">
        <span class="text-[10px] uppercase tracking-wider text-amber-400 font-bold">Payment ${escapeHtml(order.reference)}</span>
        <h1 class="text-2xl font-black text-white">${escapeHtml(order.event?.title || 'Ticket order')}</h1>
        <p class="text-sm text-slate-300">Status: <strong class="text-white">${escapeHtml(order.status)}</strong></p>
        <p class="text-sm text-slate-400">${escapeHtml(order.buyer_name)} · ${escapeHtml(order.buyer_email)}</p>
        <p class="text-amber-400 font-black">${escapeHtml(order.currency || 'KES')} ${Number(order.amount || 0).toLocaleString()}</p>
        ${order.customer_message ? `<p class="text-xs text-slate-400">${escapeHtml(order.customer_message)}</p>` : ''}

        ${pending ? `
          <div class="p-4 rounded-2xl bg-sky-500/10 border border-sky-500/40 text-sky-200 text-xs space-y-2">
            <p class="font-bold text-white">Waiting for ${escapeHtml(order.gateway)} confirmation…</p>
            <p>Approve the M-Pesa STK prompt if it appeared, or complete Paystack checkout. This page refreshes automatically.</p>
            <p>Manual M-Pesa: Paybill <span class="font-mono text-amber-300">${escapeHtml(paybill || '—')}</span>, Account <span class="font-mono text-amber-300">${escapeHtml(order.reference)}</span></p>
            <div class="flex flex-wrap gap-2 pt-2">
              <button type="button" id="roi-verify-now" class="px-3 py-2 rounded-lg bg-sky-600 text-white font-bold">I have paid — verify</button>
              <button type="button" id="roi-stk-retry" class="px-3 py-2 rounded-lg bg-slate-800 text-slate-200 font-bold">Resend STK</button>
            </div>
          </div>` : ''}

        ${failed ? `
          <div class="p-4 rounded-2xl bg-red-500/10 border border-red-500/40 text-red-200 text-xs">
            Payment did not complete. Inventory has been released. You can start a new checkout from the event page.
          </div>` : ''}

        ${tickets.length ? `
          <div class="pt-4 border-t border-slate-800 space-y-3">
            <div class="flex items-center justify-between gap-2">
              <h2 class="text-white font-bold text-sm">Your tickets</h2>
              <button type="button" id="roi-download-all" class="px-3 py-1.5 rounded-lg bg-amber-400 text-slate-950 text-[11px] font-black">${icon('download', 'w-3 h-3 inline')} Download all</button>
            </div>
            <p class="text-[11px] text-emerald-400">Your tickets are ready. Save or download each one — present the QR at the gate.</p>
            ${tickets.map((t) => `
              <div class="flex items-center gap-4 bg-slate-900 rounded-xl px-4 py-3">
                <img src="${qrDataUrl(t.code)}" alt="QR ${escapeHtml(t.code)}" class="w-16 h-16 rounded bg-white">
                <div class="flex-1">
                  <div class="font-mono text-amber-300">${escapeHtml(t.code)}</div>
                  <div class="text-[10px] uppercase text-slate-400">${escapeHtml(t.ticket_type_name || '')} · ${escapeHtml(t.status)}</div>
                </div>
                <div class="flex flex-col gap-1 items-end">
                  <button type="button" data-ticket-download="${escapeHtml(t.code)}" class="text-[10px] font-bold text-sky-400">Download</button>
                  <button type="button" data-copy-code="${escapeHtml(t.code)}" class="text-[10px] font-bold text-slate-400">Copy</button>
                </div>
              </div>`).join('')}
          </div>` : (!failed ? `<p class="text-xs text-slate-500">Tickets appear here after payment is confirmed on the donation ledger.</p>` : '')}
      </div>`;

    body.querySelector('#roi-verify-now')?.addEventListener('click', () => refresh(true));
    body.querySelector('#roi-stk-retry')?.addEventListener('click', async () => {
      try {
        const updated = await retryTicketStk(order.reference, order.buyer_phone, buyerEmail || order.buyer_email);
        showToast(updated.customer_message || 'STK re-sent.');
        paint(updated);
        startPolling();
      } catch (err) {
        showToast(err.response?.data?.detail || 'Could not resend STK.');
      }
    });

    if (tickets.length) {
      body.querySelectorAll('[data-ticket-download]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const code = btn.getAttribute('data-ticket-download');
          const t = tickets.find((x) => x.code === code);
          if (!t) return;
          try { await downloadTicketPng(t, order); showToast('Ticket downloaded.'); }
          catch { showToast('Could not generate ticket image.'); }
        });
      });
      body.querySelector('#roi-download-all')?.addEventListener('click', async () => {
        for (const t of tickets) {
          try { await downloadTicketPng(t, order); } catch {}
          await new Promise((r) => setTimeout(r, 250));
        }
        showToast('All tickets downloaded.');
      });
      body.querySelectorAll('[data-copy-code]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const code = btn.getAttribute('data-copy-code');
          try { await navigator.clipboard.writeText(code); showToast('Code copied.'); }
          catch { showToast('Could not copy.'); }
        });
      });
    }
  }

  async function refresh(forceVerify) {
    if (!buyerEmail) {
      askForEmail();
      return null;
    }
    try {
      const order = forceVerify ? await verifyTicketOrder(reference, buyerEmail) : await getTicketOrder(reference, buyerEmail);
      paint(order);
      if (order && isTerminalOrderStatus(order.status)) stopPolling();
      return order;
    } catch {
      paint(null);
      stopPolling();
      return null;
    }
  }

  function startPolling() {
    stopPolling();
    polls = 0;
    pollTimer = setInterval(async () => {
      polls += 1;
      const order = await refresh(true);
      if (!order || isTerminalOrderStatus(order.status) || polls >= 20) stopPolling();
    }, 3000);
  }

  if (!buyerEmail) {
    askForEmail();
    return;
  }

  verifyTicketOrder(reference, buyerEmail)
    .then((order) => {
      paint(order || null);
      if (order && shouldPollPayment(order.status)) startPolling();
    })
    .catch(() => getTicketOrder(reference, buyerEmail)
      .then((order) => {
        paint(order);
        if (order && shouldPollPayment(order.status)) startPolling();
      })
      .catch(() => paint(null)));
}
