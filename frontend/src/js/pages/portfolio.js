import { getPortfolio } from '../api.js';
import { icon } from '../icons.js';
import { escapeHtml, imageWithFallback } from '../ui.js';

export function renderPortfolio(root) {
  root.innerHTML = `
    <div class="py-16 sm:py-24 bg-slate-900 min-h-screen space-y-10">
      <div class="max-w-3xl mx-auto px-4 text-center space-y-4">
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs font-bold">
          ${icon('award', 'w-3.5 h-3.5')}
          <span>Case studies</span>
        </div>
        <h1 class="text-4xl font-black text-white">DEMO Digital Solutions portfolio</h1>
        <p class="text-slate-300">Work shipped for coastal partners — ticketing, labs, and media. <a href="#/solutions" class="text-sky-400 font-bold">Request a briefing</a>.</p>
      </div>
      <div id="roi-portfolio-grid" class="max-w-6xl mx-auto px-4 grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-slate-800 animate-pulse h-64 rounded-3xl"></div>
        <div class="bg-slate-800 animate-pulse h-64 rounded-3xl"></div>
      </div>
    </div>`;

  getPortfolio().then((items) => {
    const grid = root.querySelector('#roi-portfolio-grid');
    const list = items || [];
    grid.innerHTML = list.map((p) => `
      <article class="bg-slate-950 border border-slate-800 rounded-3xl overflow-hidden">
        <div class="aspect-[16/9] bg-slate-900">
          ${imageWithFallback(p.image_url || '', p.title, 'w-full h-full object-cover', p.client || 'DEMO')}
        </div>
        <div class="p-6 space-y-2">
          ${p.is_featured ? '<span class="text-[10px] uppercase text-amber-400 font-black">Featured</span>' : ''}
          <h2 class="text-xl font-black text-white">${escapeHtml(p.title)}</h2>
          <p class="text-xs text-sky-400">${escapeHtml(p.client || '')} · ${escapeHtml(p.location || '')} · ${escapeHtml(p.year || '')}</p>
          <p class="text-sm text-slate-400">${escapeHtml(p.summary)}</p>
          ${p.outcome ? `<p class="text-xs text-emerald-300">${escapeHtml(p.outcome)}</p>` : ''}
          ${p.solution_title ? `<p class="text-[11px] text-slate-500">Offering: ${escapeHtml(p.solution_title)}</p>` : ''}
        </div>
      </article>`).join('') || '<p class="text-slate-400">Portfolio cases will appear here.</p>';
  });
}
