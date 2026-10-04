// Shared UI utilities: toast, fallback banner, DOM helpers, ImageWithFallback port.
import { icon } from './icons.js';

export function escapeHtml(str) {
  return String(str ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

export const fmtDate = (value) => {
  if (!value) return '';
  try {
    return new Date(value).toLocaleDateString();
  } catch {
    return '';
  }
};

export function qs(sel, root = document) {
  return root.querySelector(sel);
}

export function qsa(sel, root = document) {
  return Array.from(root.querySelectorAll(sel));
}

// ------------------------------------------------------------- Toast (Notification.jsx)

export function showToast(message) {
  if (!message) return;
  document.getElementById('roi-toast-root')?.remove();
  const el = document.createElement('div');
  el.id = 'roi-toast-root';
  el.className = 'fixed bottom-4 inset-x-4 sm:bottom-6 sm:left-auto sm:right-6 z-[150] sm:max-w-sm bg-slate-800 border border-emerald-500/50 rounded-xl shadow-2xl p-4 animate-slideUp';
  el.innerHTML = `
    <div class="flex items-start gap-3">
      <span class="text-emerald-400 shrink-0 mt-0.5">${icon('check-circle', 'w-5 h-5')}</span>
      <p class="text-xs text-slate-200 leading-relaxed">${escapeHtml(message)}</p>
      <button id="roi-toast-close" class="ml-auto text-slate-400 hover:text-white shrink-0 transition-colors" aria-label="Close notification">
        ${icon('x', 'w-4 h-4')}
      </button>
    </div>`;
  document.body.appendChild(el);
  const close = () => el.remove();
  el.querySelector('#roi-toast-close').addEventListener('click', close);
  setTimeout(() => el.remove(), 5000);
}

// ------------------------------------------- Fallback transparency banner (App.jsx)

export function showFallbackBanner(endpoint) {
  const existing = document.getElementById('roi-fallback-banner');
  if (existing) {
    existing.querySelector('span[data-msg]').textContent =
      `Backend API server unreachable (${endpoint}). Displaying mock preview data. Set VITE_STRICT_API_MODE=true to enforce strict production error throwing.`;
    return;
  }
  const banner = document.createElement('div');
  banner.id = 'roi-fallback-banner';
  banner.className = 'bg-amber-500 text-slate-950 px-3 sm:px-4 py-2.5 text-xs font-bold flex items-start sm:items-center gap-2 justify-between z-[200] shadow-md animate-fadeIn relative';
  banner.innerHTML = `
    <div class="flex flex-wrap sm:flex-nowrap items-center gap-2 mx-auto max-w-7xl min-w-0">
      <span class="shrink-0 inline-block align-middle">${icon('alert-triangle', 'w-4 h-4 shrink-0')}</span>
      <span data-msg class="min-w-0 flex-1 line-clamp-2 sm:truncate">Backend API server unreachable (${endpoint}). Displaying mock preview data. Set VITE_STRICT_API_MODE=true to enforce strict production error throwing.</span>
      <button id="roi-retry-btn" class="sm:ml-3 px-3 py-1.5 rounded-lg bg-slate-950 text-amber-400 hover:bg-slate-900 transition-colors text-[10px] font-black uppercase tracking-wider shrink-0 shadow flex items-center gap-1">
        <span>Retry Connection</span>
      </button>
    </div>
    <button id="roi-banner-close" class="p-1 hover:bg-amber-400 rounded shrink-0">${icon('x', 'w-4 h-4')}</button>`;
  banner.querySelector('#roi-retry-btn').addEventListener('click', () => window.location.reload());
  banner.querySelector('#roi-banner-close').addEventListener('click', () => banner.remove());
  document.body.prepend(banner);
}

export function listenForFallbackEvents() {
  window.addEventListener('roi_api_fallback_triggered', (e) => {
    const { endpoint } = e.detail || {};
    showFallbackBanner(endpoint);
  });
}

// -------------------------------------------------- ImageWithFallback (component port)

const FALLBACK_THEMES = [
  { match: ['conference', 'events'], gradient: 'from-amber-600 via-orange-600 to-red-700', badge: 'Flagship Conference', iconName: 'calendar' },
  { match: ['media', 'tv', 'sessions', 'outreach'], gradient: 'from-rose-600 via-pink-600 to-purple-800', badge: 'DEMO TV Broadcast', iconName: 'tv' },
  { match: ['education', 'tech', 'mentorship'], gradient: 'from-sky-600 via-cyan-600 to-blue-800', badge: 'Digital Education', iconName: 'book-open' },
  { match: ['impact', 'support'], gradient: 'from-emerald-600 via-green-600 to-teal-800', badge: 'Social Impact', iconName: 'heart' }
];

/**
 * Returns markup for an image that degrades into a category-themed gradient panel
 * when the source fails to load (ports the ImageWithFallback component).
 */
export function imageWithFallback(src, alt, className, category, headingOverride) {
  const safeAlt = escapeHtml(alt || '');
  const safeSrc = src ? escapeHtml(src) : null;
  const imgTag = safeSrc
    ? `<img src="${safeSrc}" alt="${safeAlt}" loading="lazy" class="${className}" data-has-src="1">`
    : `<div class="${className}"></div>`;

  // The placeholder is rendered hidden behind the img and revealed on error.
  const theme =
    FALLBACK_THEMES.find((t) => t.match.some((m) => (category || '').toLowerCase().includes(m))) ||
    { gradient: 'from-sky-700 via-blue-800 to-slate-900', badge: 'Community Asset', iconName: 'sparkles' };

  const heading = escapeHtml(headingOverride || alt || 'DEMO Harbor City Community Asset');

  const placeholder = `
    <div data-img-fallback class="${className} hidden bg-gradient-to-br ${theme.gradient} items-center justify-center p-6 text-center">
      <div class="flex flex-col items-center gap-2 opacity-90">
        <span class="px-2.5 py-0.5 rounded-full bg-slate-950/60 text-[9px] font-black tracking-widest uppercase text-white/90 border border-white/20">${theme.badge}</span>
        <span class="text-white/90 mt-1">${icon(theme.iconName, 'w-8 h-8')}</span>
        <h4 class="text-white text-sm font-bold leading-tight mt-1 line-clamp-2">${heading}</h4>
        <p class="text-white/70 text-[10px] font-mono mt-auto pt-2">Harbor City, Kenya • Verified Coastal HQ</p>
      </div>
    </div>`;

  const wrapperClass = `${className.includes('absolute') ? '' : ''}`.trim();

  return `<span data-ifb class="block relative ${wrapperClass}">
    ${placeholder}
    ${imgTag}
  </span>`;
}

// Delegated error handling for every imageWithFallback instance on the page.
document.addEventListener('error', (e) => {
  const img = e.target;
  if (!(img instanceof HTMLImageElement)) return;
  const wrap = img.closest('[data-ifb]');
  if (!wrap) return;
  img.classList.add('hidden');
  const ph = wrap.querySelector('[data-img-fallback]');
  if (ph) {
    ph.classList.remove('hidden');
    ph.classList.add('flex');
  }
}, true);

// ---------------------------------------------------------------- Misc helpers

/** Fullscreen overlay helper used by modals; returns a teardown fn. */
export function openOverlay(id, innerHTML, onClose) {
  document.getElementById(id)?.remove();
  const overlay = document.createElement('div');
  overlay.id = id;
  overlay.innerHTML = innerHTML;
  document.body.appendChild(overlay);
  overlay.querySelectorAll('[data-close-overlay]').forEach((btn) =>
    btn.addEventListener('click', () => {
      overlay.remove();
      onClose && onClose();
    })
  );
  return () => overlay.remove();
}
