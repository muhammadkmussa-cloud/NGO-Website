import { request } from '../api.js';
import { navigate } from '../router.js';
import { icon } from '../icons.js';
import { escapeHtml, showToast } from '../ui.js';

export function renderTicketRecover(root) {
  root.innerHTML = `
    <div class="py-16 sm:py-20 bg-slate-900 min-h-screen">
      <div class="max-w-lg mx-auto px-4 space-y-6">
        <a href="#/events" class="text-xs font-bold text-sky-400">${icon('arrow-up-right', 'w-3 h-3 inline rotate-180')} Events</a>
        <h1 class="text-3xl font-black text-white">Find my tickets</h1>
        <p class="text-sm text-slate-400">Enter the email used at checkout. We will open your private ticket portal if tickets exist. You can also open an order immediately with the payment reference.</p>

        <form id="roi-recover-email" class="bg-slate-950 border border-slate-800 rounded-3xl p-6 space-y-3">
          <input required type="email" name="email" placeholder="Email on the order" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm">
          <input name="reference" placeholder="Order reference (optional)" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm font-mono">
          <button class="w-full py-3 rounded-xl bg-amber-400 text-slate-950 font-black text-xs uppercase">Open my tickets</button>
        </form>

        <form id="roi-recover-lookup" class="bg-slate-950 border border-slate-800 rounded-3xl p-6 space-y-3">
          <h2 class="text-white font-bold text-sm">I have my reference</h2>
          <input required type="email" name="email" placeholder="Email" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm">
          <input required name="reference" placeholder="ROI-TCK-…" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm font-mono">
          <button class="w-full py-3 rounded-xl bg-sky-600 text-white font-black text-xs uppercase">Open order</button>
        </form>
        <p id="roi-recover-msg" class="text-xs text-slate-400"></p>
      </div>
    </div>`;

  const msg = root.querySelector('#roi-recover-msg');

  root.querySelector('#roi-recover-email').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
      const res = await request('POST', '/tickets/recover', {
        email: fd.get('email'),
        reference: fd.get('reference') || undefined
      });
      if (res.data?.portal_token) {
        navigate(`/tickets/portal/${encodeURIComponent(res.data.portal_token)}`);
      } else {
        const text = res.data?.message || 'No tickets were found for that email.';
        msg.textContent = text;
        showToast(text);
      }
    } catch (err) {
      showToast(err.response?.data?.detail || 'Recovery failed.');
    }
  });

  root.querySelector('#roi-recover-lookup').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
      const res = await request('POST', '/tickets/lookup-order', {
        email: fd.get('email'),
        reference: fd.get('reference')
      });
      if (!res.ok) {
        showToast(res.data?.detail || 'No matching order.');
        return;
      }
      if (res.data?.portal_token) {
        navigate(`/tickets/portal/${encodeURIComponent(res.data.portal_token)}`);
      } else {
        navigate(`/tickets/order/${fd.get('reference')}`);
      }
    } catch (err) {
      showToast(err.response?.data?.detail || 'Lookup failed.');
    }
  });
}
