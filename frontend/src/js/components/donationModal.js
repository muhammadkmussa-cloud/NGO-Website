// DonationModal — vanilla port of components/DonationModal.jsx.
// Two tabs (digital gateways / offline paybills), currency presets, gateway switcher
// forcing KES for M-Pesa, click-to-copy offline utility with 2.5s "Copied" state.
import { initiateDonation, getPaybills } from '../api.js';
import { safeClipboardCopy } from '../storage.js';
import { icon } from '../icons.js';
import { escapeHtml } from '../ui.js';
import { showToast } from '../ui.js';
import { isSafePaystackUrl } from '../payments/ticketPayment.js';

const CURRENCY_SYMBOLS = { KES: 'KSh ', USD: '$', EUR: '€', GBP: '£' };
const PRESET_AMOUNTS = {
  KES: [500, 1000, 2500, 5000, 10000],
  USD: [10, 25, 50, 100, 250],
  EUR: [10, 25, 50, 100, 250],
  GBP: [10, 25, 50, 100, 250]
};

export function openDonationModal(onSuccessNotification) {
  const rootId = 'roi-donation-modal';
  document.getElementById(rootId)?.dispatchEvent(new CustomEvent('roi:teardown'));

  // Component-local reactive state (mirrors the React useState cluster).
  const state = {
    currency: 'KES',
    amount: 1000,
    customAmount: '',
    frequency: 'one-time',
    gateway: 'Paystack',
    loading: false,
    copiedField: null,
    activeTab: 'digital',
    checkoutError: null
  };

  const overlay = document.createElement('div');
  overlay.id = rootId;
  document.body.appendChild(overlay);

  // Live M-Pesa paybill details (source of truth = /payments/paybills); the
  // offline copy-utility renders from this instead of hardcoded literals.
  let paybills = {
    enabled: false,
    message: 'Our secure contribution channels are being prepared. Please contact our team in the meantime.'
  };
  getPaybills()
    .then((p) => { paybills = p; render(); })
    .catch(() => {});

  let copiedTimer = null;
  const teardown = () => {
    if (copiedTimer) clearTimeout(copiedTimer);
    overlay.onclick = null;
    document.removeEventListener('keydown', onKeydown);
    overlay.remove();
  };

  const onKeydown = (e) => {
    if (e.key === 'Escape') teardown();
  };
  document.addEventListener('keydown', onKeydown);
  overlay.addEventListener('roi:teardown', teardown, { once: true });

  function copyRow(labelText, valueText, valueClass, fieldName) {
    const s = state;
    const safeValue = escapeHtml(valueText);
    return `
      <div class="bg-slate-900/80 p-3 rounded-xl border border-slate-700 flex items-center justify-between gap-3 min-w-0">
        <div class="min-w-0">
          <span class="text-[10px] text-slate-400 uppercase block">${labelText}</span>
          <span class="font-mono font-black text-sm break-all ${valueClass}">${safeValue}</span>
        </div>
        <button type="button" data-copy="${safeValue}" data-field="${fieldName}"
          class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-sky-600 text-slate-300 hover:text-white transition-colors flex items-center gap-1 font-bold text-[10px] shrink-0">
          ${s.copiedField === fieldName ? icon('check', 'w-3.5 h-3.5 text-emerald-400') : icon('copy', 'w-3.5 h-3.5')}
          <span>${s.copiedField === fieldName ? 'Copied' : 'Copy'}</span>
        </button>
      </div>`;
  }

  function offlinePaybillHtml() {
    const pb = paybills;
    // Fallback to empty strings if paybills unavailable
    const kcb = (pb && pb.kcb_mpesa) || { paybill: '—', account: '—' };
    const equity = (pb && pb.equity_bank) || { paybill: '—', account: '—' };
    const biz = (pb && pb.business_name) || 'REACHING OUT INITIATIVE';
    const badge = (p) => `<span class="px-2 py-0.5 rounded bg-sky-500/20 text-sky-300 text-[10px] font-mono font-bold">Paybill ${escapeHtml(p)}</span>`;
    return `
      <div class="space-y-6 animate-fadeIn">
        <div class="p-3 sm:p-4 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs flex items-start sm:items-center gap-3">
          ${icon('smartphone', 'w-5 h-5 shrink-0 text-amber-400')}
          <span>Utilize the <strong>Click to Copy</strong> utility below to prevent manual typing errors in your SIM Toolkit / Bank App.</span>
        </div>

        <div class="p-4 sm:p-6 rounded-2xl bg-slate-800/90 border border-sky-500/40 relative space-y-4">
          <div class="flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between border-b border-slate-700/80 pb-3">
            <span class="text-xs font-black uppercase text-sky-400 tracking-wider">M-Pesa Route (SADAQA)</span>
            ${badge(kcb.paybill)}
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
            ${copyRow('Paybill Number', kcb.paybill, 'text-white', 'kcb_paybill')}
            ${copyRow('Account Number', kcb.account, 'text-amber-400', 'kcb_account')}
          </div>
          <div class="text-[11px] text-slate-400 font-medium pt-1">
            Business Name: <span class="text-white font-bold">${escapeHtml(biz)}</span>
          </div>
        </div>

        <div class="p-4 sm:p-6 rounded-2xl bg-slate-800/90 border border-amber-500/40 relative space-y-4">
          <div class="flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between border-b border-slate-700/80 pb-3">
            <span class="text-xs font-black uppercase text-amber-400 tracking-wider">M-Pesa Route (ORPHANS)</span>
            ${badge(equity.paybill)}
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
            ${copyRow('Paybill Number', equity.paybill, 'text-white', 'equity_paybill')}
            ${copyRow('Account Number', equity.account, 'text-amber-400', 'equity_account')}
          </div>
          <div class="text-[11px] text-slate-400 font-medium pt-1 flex flex-col sm:flex-row gap-1 sm:items-center sm:justify-between">
            <span>Business Name: <strong class="text-white">${escapeHtml(biz)}</strong></span>
            <span class="text-emerald-400 text-[10px]">Airtel Money / Equitel Supported</span>
          </div>
        </div>

        <div class="bg-slate-950 p-4 rounded-2xl border border-slate-800 text-[11px] text-slate-400 space-y-2">
          <span class="text-white font-bold block uppercase text-xs">SIM Toolkit Instructions</span>
          <ol class="list-decimal list-inside space-y-1 pl-1">
            <li>Go to your M-Pesa / Airtel Money menu and select <strong>Lipa na M-Pesa</strong>.</li>
            <li>Select <strong>Pay Bill</strong> and enter Business No. <code class="text-amber-400">${escapeHtml(kcb.paybill)}</code>.</li>
            <li>Paste the copied Account Number (<code class="text-sky-400">${escapeHtml(kcb.account)}</code> or <code class="text-sky-400">${escapeHtml(equity.account)}</code>).</li>
            <li>Enter amount and your PIN to complete.</li>
          </ol>
        </div>
      </div>`;
  }

  function render() {
    const s = state;
    const paymentsEnabled = paybills?.enabled === true;
    overlay.className = 'fixed inset-0 z-[100] flex items-start justify-center p-2 sm:p-4 bg-slate-950/80 backdrop-blur-md animate-fadeIn overflow-y-auto mobile-scroll';

    const currencyBtn = (curr) => `
      <button type="button" data-set-currency="${curr}"
        class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-colors ${
          s.currency === curr ? 'bg-sky-500 text-white shadow' : 'text-slate-400 hover:text-slate-200'
        }">${curr}</button>`;

    const freqBtn = (freq, label) => `
      <button type="button" data-set-frequency="${freq}"
        class="px-3.5 py-1.5 rounded-lg text-xs font-bold capitalize transition-colors ${
          s.frequency === freq ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-400 hover:text-slate-200'
        }">${label}</button>`;

    const presetBtn = (preset) => `
      <button type="button" data-set-preset="${preset}"
        class="py-3 px-2 rounded-xl text-sm font-black border transition-colors ${
          Number(s.amount) === preset && !s.customAmount
            ? 'bg-sky-600/20 border-sky-500 text-sky-400 shadow-lg shadow-sky-500/10'
            : 'bg-slate-800/60 border-slate-700 text-slate-300 hover:border-slate-600'
        }">${CURRENCY_SYMBOLS[s.currency]}${preset}</button>`;

    const tabBtn = (tab, iconName, label) => `
      <button type="button" data-set-tab="${tab}"
        class="min-h-11 px-2 py-2 rounded-lg text-xs font-bold transition-colors flex items-center justify-center gap-2 text-center ${
          s.activeTab === tab ? 'bg-amber-400 text-slate-950 shadow-md' : 'text-slate-300 hover:text-white'
        }">
        ${icon(iconName, 'w-4 h-4')}
        <span>${label}</span>
      </button>`;

    overlay.innerHTML = `
      <div role="dialog" aria-modal="true" aria-labelledby="roi-donate-title" class="bg-slate-900 border border-slate-700 w-full max-w-2xl max-h-[calc(100dvh-1rem)] sm:max-h-[calc(100dvh-4rem)] rounded-2xl sm:rounded-3xl overflow-hidden shadow-2xl shadow-black my-2 sm:my-8 flex flex-col">

        <div class="bg-gradient-to-r from-sky-600 via-sky-700 to-slate-900 p-4 sm:p-8 text-white relative shrink-0">
          <button type="button" id="roi-donate-close" class="absolute top-3 right-3 sm:top-6 sm:right-6 p-2 rounded-full bg-black/20 hover:bg-black/40 text-slate-300 hover:text-white transition-colors" aria-label="Close donation dialog">
            ${icon('x', 'w-5 h-5')}
          </button>
          <div class="flex items-start sm:items-center gap-3 pr-10">
            <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-2xl bg-amber-400 text-slate-950 flex items-center justify-center font-black shadow-lg shrink-0">
              ${icon('heart', 'w-5 h-5 sm:w-6 sm:h-6 fill-slate-950')}
            </div>
            <div class="min-w-0">
              <h3 id="roi-donate-title" class="text-lg sm:text-2xl font-black leading-tight">Support Reaching Out Initiative</h3>
              <p class="text-xs text-sky-200 mt-1">Uplifting youth across Mombasa, Kenya. Every contribution fuels real social change.</p>
            </div>
          </div>

          ${paymentsEnabled ? `<div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-4 sm:mt-6 bg-slate-950/40 p-1.5 rounded-xl border border-white/10">
            ${tabBtn('digital', 'credit-card', 'Digital Gateways (Card / M-Pesa Push)')}
            ${tabBtn('offline', 'smartphone', 'Kenya Offline Paybills (Copy Utility)')}
          </div>` : ''}
        </div>

        <div class="p-4 sm:p-8 overflow-y-auto mobile-scroll min-h-0">
          ${!paymentsEnabled ? `
            <div data-payments-disabled class="py-4 sm:py-8 text-center space-y-5">
              <div class="w-14 h-14 mx-auto rounded-2xl bg-amber-400/15 border border-amber-400/30 text-amber-300 flex items-center justify-center">
                ${icon('clock', 'w-7 h-7')}
              </div>
              <div class="space-y-2">
                <h4 class="text-xl sm:text-2xl font-black text-white">Donations are temporarily unavailable.</h4>
                <p class="text-sm text-slate-300 max-w-md mx-auto leading-relaxed">${escapeHtml(paybills?.message || 'Our contribution channels are being prepared for a secure launch. Please check back soon.')}</p>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-w-lg mx-auto">
                <a href="mailto:reachingoutinitiative2021@gmail.com" class="min-h-11 px-4 py-3 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-bold text-sm flex items-center justify-center gap-2 transition-colors">
                  ${icon('mail', 'w-4 h-4')} Contact our team
                </a>
                <a href="tel:+254745273556" class="min-h-11 px-4 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-white font-bold text-sm flex items-center justify-center gap-2 transition-colors">
                  ${icon('phone', 'w-4 h-4')} +254 745 273 556
                </a>
              </div>
              <p class="text-[11px] text-slate-500">No payment request or transaction has been created.</p>
            </div>
          ` : `${s.checkoutError ? `
            <div class="p-4 mb-6 rounded-2xl bg-red-500/10 border-2 border-red-500 text-red-300 text-xs font-semibold flex items-start gap-3 animate-shake">
              ${icon('alert-circle', 'w-5 h-5 text-red-500 shrink-0 mt-0.5')}
              <div>
                <span class="font-bold text-white block uppercase text-[11px]">Transaction Aborted</span>
                <span>${escapeHtml(s.checkoutError)}</span>
              </div>
            </div>` : ''}

          ${s.activeTab === 'digital' ? `
            <form id="roi-donate-form" class="space-y-5 sm:space-y-6">

              <div class="flex flex-col sm:flex-row gap-4 justify-between items-center pb-4 border-b border-slate-800">
                <div class="grid grid-cols-4 gap-1.5 bg-slate-800 p-1 rounded-xl border border-slate-700 w-full sm:w-auto">
                  ${['KES', 'USD', 'EUR', 'GBP'].map(currencyBtn).join('')}
                </div>
                <div class="grid grid-cols-2 gap-1.5 bg-slate-800 p-1 rounded-xl border border-slate-700 w-full sm:w-auto">
                  ${freqBtn('one-time', 'One Time')}
                  ${freqBtn('monthly', 'Monthly Pledge')}
                </div>
              </div>

              <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-2.5">Select Contribution Amount (${s.currency})</label>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2.5">
                  ${PRESET_AMOUNTS[s.currency].map(presetBtn).join('')}
                </div>
                <div class="mt-3">
                  <input type="number" data-custom-amount placeholder="Or enter custom amount in ${s.currency}..." value="${escapeHtml(s.customAmount)}"
                    class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm focus:outline-none focus:border-sky-500 transition-colors">
                </div>
              </div>

              <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-2.5">Select Secure Gateway</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <button type="button" data-set-gateway="Paystack"
                    class="p-3 rounded-xl border text-left flex items-center gap-3 transition-colors ${
                      s.gateway === 'Paystack' ? 'bg-emerald-500/10 border-emerald-500 text-emerald-400' : 'bg-slate-800/60 border-slate-700 text-slate-300'
                    }">
                    ${icon('globe', 'w-5 h-5 shrink-0')}
                    <div>
                      <span class="text-xs font-bold block">Paystack</span>
                      <span class="text-[10px] text-slate-400 block">Cards &amp; Mobile Money</span>
                    </div>
                  </button>

                  <button type="button" data-set-gateway="M-Pesa"
                    class="p-3 rounded-xl border text-left flex items-center gap-3 transition-colors ${
                      s.gateway === 'M-Pesa' ? 'bg-sky-500/10 border-sky-500 text-sky-400' : 'bg-slate-800/60 border-slate-700 text-slate-300'
                    }">
                    ${icon('smartphone', 'w-5 h-5 shrink-0')}
                    <div>
                      <span class="text-xs font-bold block">M-Pesa Push</span>
                      <span class="text-[10px] text-slate-400 block">Instant STK Prompt</span>
                    </div>
                  </button>
                </div>
              </div>

              <div class="space-y-3 pt-2">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <input type="text" data-donor-name placeholder="Your Full Name (Optional)"
                    class="px-4 py-2.5 rounded-xl bg-slate-800 border border-slate-700 text-white text-xs focus:outline-none focus:border-sky-500">
                  <input type="email" data-donor-email placeholder="Email Address for Receipt"
                    class="px-4 py-2.5 rounded-xl bg-slate-800 border border-slate-700 text-white text-xs focus:outline-none focus:border-sky-500">
                </div>

                ${s.gateway === 'M-Pesa' ? `
                  <input type="tel" data-donor-phone required placeholder="M-Pesa Phone Number (e.g. 0712345678 or +2547...)"
                    class="w-full px-4 py-2.5 rounded-xl bg-slate-800 border border-sky-500 text-white text-xs focus:outline-none">` : ''}
              </div>

              <button type="submit" ${s.loading ? 'disabled' : ''}
                class="w-full px-3 py-4 rounded-2xl bg-gradient-to-r from-amber-400 via-amber-500 to-amber-400 hover:from-amber-300 hover:to-amber-400 text-slate-950 font-black text-xs sm:text-sm tracking-wider uppercase shadow-xl shadow-amber-500/25 transition-colors transform flex items-center justify-center gap-2 hover:scale-[1.01] leading-snug">
                ${icon('lock', 'w-4 h-4')}
                <span class="min-w-0 text-center">${s.loading ? 'Initiating Encrypted Banking Handshake...' : `Complete ${CURRENCY_SYMBOLS[s.currency]}${s.customAmount || s.amount} Contribution`}</span>
              </button>

              <div class="flex items-start sm:items-center justify-center gap-2 text-[11px] text-slate-500 text-center">
                ${icon('shield-check', 'w-3.5 h-3.5 text-emerald-500')}
                <span>256-Bit SSL Bank-Grade Encryption. Verified East Africa &amp; Global Ecosystems.</span>
              </div>
            </form>` : `
            ${offlinePaybillHtml()}
             `}`}
        </div>
      </div>`;

    bindEvents();
  }

  function bindEvents() {
    overlay.querySelector('#roi-donate-close').addEventListener('click', teardown);
    overlay.onclick = (e) => {
      if (e.target === overlay) teardown();
    };

    overlay.querySelectorAll('[data-set-tab]').forEach((b) =>
      b.addEventListener('click', () => {
        state.activeTab = b.dataset.setTab;
        state.checkoutError = null;
        render();
      })
    );

    overlay.querySelectorAll('[data-set-currency]').forEach((b) =>
      b.addEventListener('click', () => {
        state.currency = b.dataset.setCurrency;
        state.amount = PRESET_AMOUNTS[state.currency][1];
        state.customAmount = '';
        render();
      })
    );

    overlay.querySelectorAll('[data-set-frequency]').forEach((b) =>
      b.addEventListener('click', () => {
        state.frequency = b.dataset.setFrequency;
        render();
      })
    );

    overlay.querySelectorAll('[data-set-preset]').forEach((b) =>
      b.addEventListener('click', () => {
        state.amount = b.dataset.setPreset;
        state.customAmount = '';
        render();
      })
    );

    overlay.querySelectorAll('[data-set-gateway]').forEach((b) =>
      b.addEventListener('click', () => {
        state.gateway = b.dataset.setGateway;
        if (state.gateway === 'M-Pesa') state.currency = 'KES'; // M-Pesa forces KES
        render();
      })
    );

    const customInput = overlay.querySelector('[data-custom-amount]');
    if (customInput) {
      customInput.addEventListener('input', () => {
        state.customAmount = customInput.value; // no re-render: preserves typing focus (matches onChange)
        paintSubmitLabel();
      });
    }

    overlay.querySelectorAll('[data-copy]').forEach((b) =>
      b.addEventListener('click', () => {
        safeClipboardCopy(b.dataset.copy);
        state.copiedField = b.dataset.field;
        render();
        if (copiedTimer) clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => {
          state.copiedField = null;
          render();
        }, 2500);
      })
    );

    const form = overlay.querySelector('#roi-donate-form');
    if (form) form.addEventListener('submit', handleDigitalCheckout);
  }

  function paintSubmitLabel() {
    const span = overlay.querySelector('#roi-donate-form button[type="submit"] span:last-child');
    if (span) {
      span.textContent = state.loading
        ? 'Initiating Encrypted Banking Handshake...'
        : `Complete ${CURRENCY_SYMBOLS[state.currency]}${state.customAmount || state.amount} Contribution`;
    }
  }

  async function handleDigitalCheckout(e) {
    e.preventDefault();
    state.checkoutError = null;

    const donorName = overlay.querySelector('[data-donor-name]')?.value.trim() || '';
    const email = overlay.querySelector('[data-donor-email]')?.value.trim() || '';
    const phone = overlay.querySelector('[data-donor-phone]')?.value.trim() || '';
    const finalAmt = state.customAmount ? parseFloat(state.customAmount) : Number(state.amount);
    if (!finalAmt || finalAmt <= 0) return;

    state.loading = true;
    paintSubmitLabel();

    try {
      const res = await initiateDonation({
        donor_name: donorName || 'Anonymous Well-wisher',
        email: email || 'donor@example.com',
        amount: finalAmt,
        currency: state.currency,
        gateway: state.gateway,
        phone_number: phone || undefined,
        frequency: state.frequency
      });

      state.loading = false;
      teardown();
      if (isSafePaystackUrl(res.authorization_url)) {
        window.open(res.authorization_url, '_blank', 'noopener');
      } else if (res.authorization_url) {
        console.error('[ROI] rejected unexpected authorization_url host:', res.authorization_url);
      }
      showToast(`Donation pledge initialized! Transaction reference: ${res.reference}. ${res.customer_message || ''}`);
      onSuccessNotification && onSuccessNotification(res);
    } catch (err) {
      state.loading = false;
      // Strictly forbid false donation confirmations on connection or API failures
      state.checkoutError =
        (err.response && err.response.data && err.response.data.detail) ||
        err.message ||
        'Payment gateway server unreachable. No transaction was created or charged. Please check your internet connection.';
      render();
    }
  }

  render();
  return teardown;
}
