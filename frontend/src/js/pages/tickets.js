import { checkoutTickets, getEventTickets, getPaybills } from '../api.js';
import { navigate } from '../router.js';
import { icon } from '../icons.js';
import { escapeHtml, showToast } from '../ui.js';
import {
  checkoutCurrency,
  checkoutGateway,
  checkoutMode,
  shouldRedirectPaystack,
  validateTicketPayment
} from '../payments/ticketPayment.js';
import { canIncrement, maxPurchasable, saleStateLabel } from '../payments/ticketSales.js';

export function renderTickets(root, params = {}) {
  const eventId = params.eventId;
  const state = {
    loading: true,
    catalog: null,
    qty: {},
    submitting: false,
    gateway: 'Paystack',
    tab: 'digital',
    checkoutError: null,
    paybills: null,
    buyer: { name: '', email: '', phone: '' }
  };

  root.innerHTML = `
    <div class="py-16 sm:py-20 bg-slate-900 min-h-screen">
      <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8" id="roi-ticket-body">
        <div class="bg-slate-800 animate-pulse h-40 rounded-3xl"></div>
      </div>
    </div>`;

  const body = root.querySelector('#roi-ticket-body');

  function selectedItems() {
    if (!state.catalog) return [];
    return state.catalog.ticket_types
      .map((t) => ({ ticket_type_id: t.id, quantity: Number(state.qty[t.id] || 0), type: t }))
      .filter((l) => l.quantity > 0);
  }

  function totalAmount() {
    return selectedItems().reduce((sum, l) => sum + l.type.price * l.quantity, 0);
  }

  function paint() {
    if (state.loading) return;
    if (!state.catalog) {
      body.innerHTML = `<p class="text-slate-300">Event not found.</p>`;
      return;
    }
    const evt = state.catalog.event;
    const types = state.catalog.ticket_types || [];
    const currency = checkoutCurrency(state.gateway, types[0]?.currency || 'KES');
    const total = totalAmount();
    const mode = checkoutMode(selectedItems());
    if (mode === 'empty') state.checkoutError = null;

    body.innerHTML = `
      <a href="#/events" class="text-xs font-bold text-sky-400 hover:text-sky-300">${icon('arrow-up-right', 'w-3 h-3 inline rotate-180')} Back to events</a>
      <div class="space-y-2">
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs font-bold">
          ${icon('lock', 'w-3.5 h-3.5')}
          <span>Secure ticket checkout</span>
        </div>
        <h1 class="text-3xl sm:text-5xl font-black text-white">${escapeHtml(evt.title)}</h1>
        <p class="text-sm text-slate-300">${escapeHtml(evt.date)} · ${escapeHtml(evt.location)}</p>
        ${evt.ticket_sales_enabled === false ? '<p class="text-amber-400 text-xs font-bold">Sales are closed for this event.</p>' : ''}
        ${evt.remaining_capacity != null ? `<p class="text-xs text-slate-400">${evt.remaining_capacity} of ${evt.capacity} event seats left</p>` : ''}
      </div>

      ${types.length === 0 ? `
        <div class="bg-slate-800/60 border border-slate-700 rounded-3xl p-10 text-center text-slate-300">
          Tickets are not on sale for this event yet. Use RSVP on the events page.
        </div>` : `
        <div class="space-y-4">
          ${types.map((t) => {
            const max = maxPurchasable(t);
            const q = Number(state.qty[t.id] || 0);
            const incDisabled = !canIncrement(t, q);
            const stateLabel = saleStateLabel(t.sale_state || (t.on_sale ? 'on_sale' : 'inactive'));
            return `
              <div class="bg-slate-950 border border-slate-800 rounded-3xl p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="space-y-1">
                  <h3 class="text-lg font-black text-white">${escapeHtml(t.name)}</h3>
                  <p class="text-xs text-slate-400 max-w-xl">${escapeHtml(t.description || '')}</p>
                  <p class="text-amber-400 font-black text-sm">${t.price > 0 ? `${escapeHtml(t.currency)} ${Number(t.price).toLocaleString()}` : 'Free'}</p>
                  <p class="text-[11px] ${t.on_sale ? 'text-slate-500' : 'text-amber-400'}">
                    ${t.on_sale ? escapeHtml(stateLabel) : `<span class="inline-block px-2 py-0.5 rounded-full bg-amber-500/15 border border-amber-500/40 font-bold">${escapeHtml(stateLabel)}</span>`} · ${t.unlimited ? 'Unlimited' : `${t.remaining} left`} · max ${max} / order
                  </p>
                </div>
                <div class="flex items-center gap-3">
                  <button type="button" data-dec="${t.id}" class="w-9 h-9 rounded-xl bg-slate-800 text-white font-black hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-slate-800" ${q <= 0 ? 'disabled' : ''}>−</button>
                  <span class="w-8 text-center font-mono text-white">${q}</span>
                  <button type="button" data-inc="${t.id}" class="w-9 h-9 rounded-xl bg-slate-800 text-white font-black hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-slate-800" ${incDisabled ? 'disabled' : ''}>+</button>
                </div>
              </div>`;
          }).join('')}
        </div>

        ${mode === 'empty' ? `
        <div class="bg-slate-950 border border-slate-800 rounded-3xl p-6 sm:p-8 text-center">
          <p class="text-sm text-slate-300 font-semibold">Select at least one ticket above to continue.</p>
        </div>` : `
        <div class="bg-slate-950 border border-slate-800 rounded-3xl p-6 sm:p-8 space-y-6">
          ${mode === 'paid' ? `
          <div class="flex gap-2 bg-slate-900 p-1.5 rounded-xl border border-slate-800">
            <button type="button" data-tab="digital" class="flex-1 py-2 rounded-lg text-xs font-bold ${state.tab === 'digital' ? 'bg-amber-400 text-slate-950' : 'text-slate-300'}">Paystack / M-Pesa Push</button>
            ${state.paybills?.enabled !== false ? `<button type="button" data-tab="offline" class="flex-1 py-2 rounded-lg text-xs font-bold ${state.tab === 'offline' ? 'bg-amber-400 text-slate-950' : 'text-slate-300'}">Offline Paybill</button>` : ''}
          </div>` : ''}

          ${state.checkoutError ? `
            <div class="p-4 rounded-2xl bg-red-500/10 border border-red-500 text-red-300 text-xs font-semibold">
              ${escapeHtml(typeof state.checkoutError === 'string' ? state.checkoutError : 'Checkout failed')}
            </div>` : ''}

          ${mode === 'free' ? `
          <form id="roi-ticket-form" class="space-y-4">
            <p class="text-xs font-bold text-emerald-400 uppercase tracking-wider">Free tickets — no payment required</p>
            <input required name="buyer_name" value="${escapeHtml(state.buyer.name)}" placeholder="Full name" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm">
            <input required type="email" name="buyer_email" value="${escapeHtml(state.buyer.email)}" placeholder="Email for tickets" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm">
            <div class="flex items-center justify-between text-sm">
              <span class="text-slate-400">Nothing to pay</span>
              <span class="text-emerald-400 font-black">${currency} 0</span>
            </div>
            <button type="submit" ${state.submitting ? 'disabled' : ''}
              class="w-full py-3.5 rounded-xl bg-gradient-to-r from-amber-400 to-amber-500 text-slate-950 font-black text-xs uppercase tracking-wider disabled:opacity-60">
              ${state.submitting ? 'Claiming tickets…' : 'Claim free tickets'}
            </button>
            <p class="text-[11px] text-slate-500 text-center flex items-center justify-center gap-1">${icon('shield-check', 'w-3.5 h-3.5 text-emerald-500')} Your tickets are issued instantly and sent to your email.</p>
          </form>` : state.tab === 'digital' ? `
          <form id="roi-ticket-form" class="space-y-4">
            <input required name="buyer_name" value="${escapeHtml(state.buyer.name)}" placeholder="Full name" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm">
            <input required type="email" name="buyer_email" value="${escapeHtml(state.buyer.email)}" placeholder="Email for tickets" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <button type="button" data-gateway="Paystack" class="p-3 rounded-xl border text-left ${state.gateway === 'Paystack' ? 'border-emerald-500 bg-emerald-500/10 text-emerald-300' : 'border-slate-700 text-slate-300'}">
                ${icon('globe', 'w-5 h-5')}
                <span class="block text-xs font-bold mt-1">Paystack</span>
                <span class="text-[10px] text-slate-400">Cards &amp; mobile money</span>
              </button>
              <button type="button" data-gateway="M-Pesa" class="p-3 rounded-xl border text-left ${state.gateway === 'M-Pesa' ? 'border-sky-500 bg-sky-500/10 text-sky-300' : 'border-slate-700 text-slate-300'}">
                ${icon('smartphone', 'w-5 h-5')}
                <span class="block text-xs font-bold mt-1">M-Pesa Push</span>
                <span class="text-[10px] text-slate-400">STK prompt · KES only</span>
              </button>
            </div>
            ${state.gateway === 'M-Pesa' ? `
              <input type="tel" name="buyer_phone" value="${escapeHtml(state.buyer.phone)}" placeholder="M-Pesa phone (0712345678)" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-sky-500 text-white text-sm">
            ` : `<input type="tel" name="buyer_phone" value="${escapeHtml(state.buyer.phone)}" placeholder="Phone (optional)" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm">`}
            <div class="flex items-center justify-between text-sm">
              <span class="text-slate-400">Payable now (${currency})</span>
              <span class="text-amber-400 font-black">${currency} ${total.toLocaleString()}</span>
            </div>
            <button type="submit" ${state.submitting ? 'disabled' : ''}
              class="w-full py-3.5 rounded-xl bg-gradient-to-r from-amber-400 to-amber-500 text-slate-950 font-black text-xs uppercase tracking-wider disabled:opacity-60">
              ${state.submitting ? 'Initiating payment…' : `Pay ${currency} ${total.toLocaleString()}`}
            </button>
            <p class="text-[11px] text-slate-500 text-center flex items-center justify-center gap-1">${icon('shield-check', 'w-3.5 h-3.5 text-emerald-500')} Same Paystack &amp; Daraja rails as ROI donations.</p>
          </form>` : `
          <div class="space-y-3 text-xs text-slate-300">
            <p>Reserve digitally first, then pay via Lipa na M-Pesa using the order reference as the account number. Or use the organisation paybills:</p>
            ${state.paybills?.kcb_mpesa ? `
              <div class="bg-slate-900 rounded-xl p-4 space-y-1">
                <div class="text-sky-400 font-bold">KCB Paybill ${escapeHtml(state.paybills.kcb_mpesa.paybill)}</div>
                <div>Account ${escapeHtml(state.paybills.kcb_mpesa.account)}</div>
              </div>
              <div class="bg-slate-900 rounded-xl p-4 space-y-1">
                <div class="text-amber-400 font-bold">Equity Paybill ${escapeHtml(state.paybills.equity_bank.paybill)}</div>
                <div>Account ${escapeHtml(state.paybills.equity_bank.account)}</div>
              </div>` : `<p>${escapeHtml(state.paybills?.message || 'Paybill details are unavailable right now. Please contact our team.')}</p>`}
          </div>`}
        </div>`}
      `}
    `;

    body.querySelectorAll('[data-inc]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.inc;
        const ticket = state.catalog?.ticket_types?.find((x) => String(x.id) === String(id));
        const current = Number(state.qty[id] || 0);
        if (ticket && !canIncrement(ticket, current)) return;
        state.qty[id] = current + 1;
        paint();
      });
    });
    body.querySelectorAll('[data-dec]').forEach((btn) => {
      btn.addEventListener('click', () => {
        state.qty[btn.dataset.dec] = Math.max(0, Number(state.qty[btn.dataset.dec] || 0) - 1);
        paint();
      });
    });
    body.querySelectorAll('[data-tab]').forEach((btn) => {
      btn.addEventListener('click', () => {
        state.tab = btn.dataset.tab;
        paint();
      });
    });
    body.querySelectorAll('[data-gateway]').forEach((btn) => {
      btn.addEventListener('click', () => {
        state.gateway = btn.dataset.gateway;
        paint();
      });
    });

    const form = body.querySelector('#roi-ticket-form');
    if (form) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        state.buyer = {
          name: String(fd.get('buyer_name') || ''),
          email: String(fd.get('buyer_email') || ''),
          phone: String(fd.get('buyer_phone') || '')
        };
        const lines = selectedItems();
        const items = lines.map(({ ticket_type_id, quantity }) => ({ ticket_type_id, quantity }));
        const gateway = checkoutGateway(checkoutMode(lines), state.gateway);
        const error = validateTicketPayment({
          items,
          gateway,
          buyerPhone: state.buyer.phone,
          total: totalAmount()
        });
        if (error) {
          state.checkoutError = error;
          showToast(error);
          paint();
          return;
        }
        state.submitting = true;
        state.checkoutError = null;
        paint();
        try {
          const order = await checkoutTickets({
            event_id: Number(evt.id),
            buyer_name: state.buyer.name,
            buyer_email: state.buyer.email,
            buyer_phone: state.buyer.phone || undefined,
            gateway,
            items
          });
          // H-3: remember the buyer email so the order page can confirm ownership.
          try { sessionStorage.setItem('roi_buyer_email', state.buyer.email); } catch {}
          if (shouldRedirectPaystack(order.authorization_url)) {
            window.location.href = order.authorization_url;
            return;
          }
          navigate(`/tickets/order/${order.reference}`);
        } catch (err) {
          const detail = err.response?.data?.detail;
          state.checkoutError = typeof detail === 'string' ? detail : (gateway === 'Free' ? 'Could not claim tickets.' : 'Could not initiate payment.');
          state.submitting = false;
          paint();
        }
      });
    }
  }

  Promise.all([getEventTickets(eventId), getPaybills()]).then(([data, bills]) => {
    state.catalog = data;
    state.paybills = bills;
    state.loading = false;
    paint();
  });
}
