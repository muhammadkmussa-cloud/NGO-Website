import { getHealth, getReady } from '../api.js';
import { escapeHtml } from '../ui.js';
import { classifyProbe, sanitizeHealthPayload } from '../payments/hardening.js';

export async function renderStatus(root) {
  const strict = window.ROI_STRICT_API_MODE !== false;
  let health = null;
  let ready = null;
  try {
    health = sanitizeHealthPayload(await getHealth(), strict);
  } catch {
    health = { status: 'offline' };
  }
  try {
    ready = await getReady();
  } catch {
    ready = { status: 'degraded', checks: { database: 'unknown' } };
  }
  const tone = classifyProbe(health, ready);
  const badge = { ok: 'Operational', degraded: 'Degraded', down: 'Unavailable' }[tone];
  const color = { ok: 'bg-emerald-500', degraded: 'bg-amber-500', down: 'bg-rose-500' }[tone];

  root.innerHTML = `
    <section class="max-w-3xl mx-auto px-4 py-16">
      <p class="text-xs uppercase tracking-[0.3em] text-roi-gold">Platform status</p>
      <h1 class="font-display text-4xl mt-2 mb-6">DEMO digital services</h1>
      <div class="rounded-2xl border border-white/10 bg-roi-navy/40 p-6">
        <div class="flex items-center gap-3 mb-4">
          <span class="inline-block w-3 h-3 rounded-full ${color}"></span>
          <strong>${badge}</strong>
        </div>
        <dl class="grid gap-2 text-sm text-white/80">
          <!-- L-2: server strings are never trusted into innerHTML unescaped -->
          <div class="flex justify-between"><dt>API</dt><dd>${escapeHtml(health?.status) || '—'}</dd></div>
          <div class="flex justify-between"><dt>Readiness</dt><dd>${escapeHtml(ready?.status) || '—'}</dd></div>
          <div class="flex justify-between"><dt>Database</dt><dd>${escapeHtml(ready?.checks?.database) || '—'}</dd></div>
        </dl>
      </div>
    </section>
  `;
}
