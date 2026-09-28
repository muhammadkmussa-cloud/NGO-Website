import { request } from '../api.js';
import { icon } from '../icons.js';
import { escapeHtml, showToast } from '../ui.js';
import { qrDataUrl, downloadTicketPng } from '../payments/ticketImage.js';

export function renderTicketPortal(root, params = {}) {
  const token = params.token || '';
  root.innerHTML = `
    <div class="py-16 bg-slate-900 min-h-screen">
      <div id="roi-portal" class="max-w-2xl mx-auto px-4 space-y-6">
        <div class="bg-slate-800 animate-pulse h-40 rounded-3xl"></div>
      </div>
    </div>`;
  const box = root.querySelector('#roi-portal');

  request('GET', `/tickets/portal/${encodeURIComponent(token)}`).then((res) => {
    if (!res.ok) {
      box.innerHTML = `<p class="text-slate-300">${escapeHtml(res.data?.detail || 'This portal link is invalid or expired.')} <a class="text-sky-400" href="#/tickets/recover">Request a new one</a>.</p>`;
      return;
    }
    const orders = res.data.orders || [];
    const portalEmail = res.data.email;
    box.innerHTML = `
      <a href="#/tickets/recover" class="text-xs font-bold text-sky-400">${icon('arrow-up-right', 'w-3 h-3 inline rotate-180')} Recover another email</a>
      <h1 class="text-3xl font-black text-white">Your tickets</h1>
      <p class="text-sm text-slate-400">${escapeHtml(portalEmail)}</p>
      ${orders.length === 0 ? '<p class="text-slate-400 text-sm">No orders on this email yet.</p>' : orders.map((o) => `
        <div class="bg-slate-950 border border-slate-800 rounded-3xl p-6 space-y-3" data-order-ref="${escapeHtml(o.reference)}">
          <div class="flex items-center justify-between gap-2">
            <div>
              <div class="font-mono text-amber-400">${escapeHtml(o.reference)}</div>
              <h2 class="text-white font-black">${escapeHtml(o.event?.title || 'Event')}</h2>
              <p class="text-xs text-slate-400">${escapeHtml(o.status)} · ${escapeHtml(o.currency)} ${Number(o.amount || 0).toLocaleString()}</p>
            </div>
            <button type="button" class="px-3 py-1.5 rounded-lg bg-amber-400 text-slate-950 text-[11px] font-black" data-download-all="${escapeHtml(o.reference)}">${icon('download', 'w-3 h-3 inline')} Download all</button>
          </div>
          ${(o.tickets || []).map((t) => `
            <div class="flex items-center gap-3 bg-slate-900 rounded-xl px-3 py-2">
              <img src="${qrDataUrl(t.code)}" class="w-12 h-12 bg-white rounded" alt="QR ${escapeHtml(t.code)}">
              <span class="font-mono text-amber-300">${escapeHtml(t.code)}</span>
              <span class="text-[10px] uppercase text-slate-500">${escapeHtml(t.ticket_type_name || '')} · ${escapeHtml(t.status)}</span>
              <button type="button" class="ml-auto text-[10px] font-bold text-sky-400" data-portal-download="${escapeHtml(t.code)}">Download</button>
            </div>`).join('')}
        </div>`).join('')}
    `;

    const byRef = Object.fromEntries(orders.map((o) => [o.reference, o]));
    box.querySelectorAll('[data-portal-download]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const ref = btn.closest('[data-order-ref]')?.getAttribute('data-order-ref');
        const order = byRef[ref];
        const t = order?.tickets?.find((x) => x.code === btn.getAttribute('data-portal-download'));
        if (!t || !order) return;
        try { await downloadTicketPng(t, order); showToast('Ticket downloaded.'); }
        catch { showToast('Could not generate ticket image.'); }
      });
    });
    box.querySelectorAll('[data-download-all]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const order = byRef[btn.getAttribute('data-download-all')];
        if (!order) return;
        for (const t of (order.tickets || [])) {
          try { await downloadTicketPng(t, order); } catch {}
          await new Promise((r) => setTimeout(r, 250));
        }
        showToast('All tickets downloaded.');
      });
    });
  }).catch(() => {
    box.innerHTML = '<p class="text-slate-300">Could not load portal.</p>';
    showToast('Could not load portal.');
  });
}
