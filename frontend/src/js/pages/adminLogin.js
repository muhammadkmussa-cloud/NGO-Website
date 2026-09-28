// AdminLogin page — vanilla port of pages/AdminLogin.jsx (fixed email slot,
// password-only auth, shake error banner, JWT session storage).
import { login } from '../store.js';
import { navigate } from '../router.js';
import { icon } from '../icons.js';

export function renderAdminLogin(root) {
  root.innerHTML = `
    <div class="min-h-screen py-20 bg-slate-950 flex items-center justify-center p-4">
      <div class="w-full max-w-md bg-slate-900 border border-slate-800 rounded-3xl p-8 sm:p-10 shadow-2xl relative overflow-hidden">

        <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-sky-500 via-amber-500 to-red-500"></div>

        <div class="text-center space-y-3 mb-8">
          <div class="w-14 h-14 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-amber-400 flex items-center justify-center mx-auto">
            ${icon('shield-alert', 'w-7 h-7')}
          </div>
          <h1 class="text-2xl font-black text-white">Singular Secure Console</h1>
          <p class="text-sm text-slate-400">Capped to exactly 1 global administrative slot. No multi-tenant routes or self-service registration permitted.</p>
        </div>

        <div id="roi-login-error" class="hidden p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-xs font-semibold mb-6 animate-shake"></div>

        <form id="roi-login-form" class="space-y-5">
          <div>
            <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block mb-1.5">Global Slot Email</label>
            <div class="relative">
              <span class="absolute left-3.5 top-3.5 text-slate-500 pointer-events-none">${icon('mail', 'w-4 h-4')}</span>
              <!-- M-4: the admin email is a credential, not UI decoration — never pre-fill or hint it. -->
              <input type="email" id="roi-login-email" required placeholder="Administrator email"
                autocomplete="username"
                class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-xs focus:outline-none focus:border-sky-500">
            </div>
          </div>

          <div>
            <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block mb-1.5">Admin Password</label>
            <div class="relative">
              <span class="absolute left-3.5 top-3.5 text-slate-500 pointer-events-none">${icon('lock', 'w-4 h-4')}</span>
              <input type="password" id="roi-login-password" required placeholder="Enter Admin Password"
                autocomplete="current-password"
                class="w-full pl-10 pr-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-xs focus:outline-none focus:border-sky-500">
            </div>
          </div>

          <button type="submit" id="roi-login-submit"
            class="w-full mt-4 py-4 rounded-xl bg-gradient-to-r from-sky-500 via-amber-500 to-sky-500 hover:from-sky-400 hover:to-amber-400 text-slate-950 font-black text-xs uppercase tracking-wider shadow-lg transition-colors">
            Authenticate Global Slot
          </button>
        </form>

        <div class="mt-8 text-center border-t border-slate-800/80 pt-6">
          <span class="text-[11px] text-slate-500">Secured by rate-limited password auth + session token (in-memory, not persisted) • Reaching Out Initiative Mombasa</span>
        </div>

      </div>
    </div>`;

  const errorEl = root.querySelector('#roi-login-error');
  const submitBtn = root.querySelector('#roi-login-submit');

  root.querySelector('#roi-login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    errorEl.classList.add('hidden');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Verifying Session Guard & JWT...';

    const email = root.querySelector('#roi-login-email').value;
    const password = root.querySelector('#roi-login-password').value;

    const res = await login(email, password);
    submitBtn.disabled = false;
    submitBtn.textContent = 'Authenticate Global Slot';

    if (res.success) {
      navigate('/admin/dashboard');
    } else {
      errorEl.textContent = res.error || 'Invalid administrator credentials.';
      errorEl.classList.remove('hidden');
    }
  });
}
