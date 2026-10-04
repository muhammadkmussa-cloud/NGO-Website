// VideoLightbox — vanilla port of components/VideoLightbox.jsx (z-[110] overlay,
// autoplaying YouTube embed, category/date/title/summary footer).
import { icon } from '../icons.js';
import { escapeHtml, fmtDate } from '../ui.js';

export function openVideoLightbox(video) {
  if (!video) return;
  document.getElementById('roi-video-lightbox')?.remove();

  const overlay = document.createElement('div');
  overlay.id = 'roi-video-lightbox';
  overlay.className = 'fixed inset-0 z-[110] flex items-center justify-center p-4 sm:p-6 bg-black/90 backdrop-blur-lg animate-fadeIn';
  overlay.innerHTML = `
    <div class="relative w-full max-w-4xl bg-slate-900 rounded-3xl overflow-hidden border border-slate-700 shadow-2xl">

      <div class="flex items-center justify-between px-6 py-4 bg-slate-950 border-b border-slate-800 text-white">
        <div class="flex items-center gap-2">
          ${icon('tv', 'w-4 h-4 text-red-500 animate-pulse')}
          <span class="text-xs font-bold tracking-wider uppercase text-slate-300">Demo Media (DEMO TV)</span>
        </div>
        <button type="button" id="roi-lightbox-close" class="p-1.5 rounded-full bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition-colors">
          ${icon('x', 'w-5 h-5')}
        </button>
      </div>

      <div class="relative aspect-video w-full bg-black">
        <iframe
          src="https://www.youtube.com/embed/${encodeURIComponent(video.youtube_id || 'dQw4w9WgXcQ')}?autoplay=1"
          title="${escapeHtml(video.title)}"
          class="w-full h-full border-0"
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
          allowfullscreen></iframe>
      </div>

      <div class="p-6 bg-slate-900 space-y-2">
        <div class="flex items-center gap-3 text-xs text-amber-400 font-bold uppercase">
          <span>${escapeHtml(video.category || 'Empowerment Special')}</span>
          <span>•</span>
          <span class="flex items-center gap-1 text-slate-400 font-normal">
            ${icon('calendar', 'w-3.5 h-3.5')}
            ${fmtDate(video.published_at || Date.now())}
          </span>
        </div>
        <h3 class="text-lg sm:text-xl font-extrabold text-white">${escapeHtml(video.title)}</h3>
        <p class="text-xs sm:text-sm text-slate-300 leading-relaxed">${escapeHtml(video.summary)}</p>
      </div>

    </div>`;

  overlay.querySelector('#roi-lightbox-close').addEventListener('click', () => overlay.remove());
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) overlay.remove();
  });
  document.body.appendChild(overlay);
}
