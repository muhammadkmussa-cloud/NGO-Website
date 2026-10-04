// MediaHub page — vanilla port of pages/MediaHub.jsx.
// Featured theater view, taxonomy filters, live-sync button, local lightbox playback.
import { getMedia } from '../api.js';
import { icon } from '../icons.js';
import { escapeHtml, imageWithFallback, fmtDate } from '../ui.js';
import { openVideoLightbox } from '../components/videoLightbox.js';

const TAXONOMIES = ['All', 'Mentorship Sessions', 'Community Outreach', 'Educational Content', 'Events & Conferences', 'Success Stories'];

export function renderMediaHub(root) {
  let media = [];
  let loading = true;
  let activeFilter = 'All';

  root.innerHTML = `
    <div class="py-16 sm:py-24 bg-slate-900 min-h-screen space-y-20">

      <div class="max-w-7xl px-4 sm:px-6 lg:px-8 text-center max-w-3xl mx-auto space-y-4">
        <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-red-500/10 border border-red-500/30 text-red-500 text-xs font-bold uppercase tracking-widest">
          ${icon('tv', 'w-4 h-4 animate-pulse')}
          <span>DEMO TV Broadcast Network</span>
        </div>
        <h1 class="text-4xl sm:text-6xl font-black text-white">
          DEMO TV: Community Storytelling & Broadcast Network
        </h1>
        <p class="text-base sm:text-lg text-slate-300 leading-relaxed font-normal">
          Our centralized digital storytelling portal. Powered by automated YouTube syndication and local database caching to preserve community retention.
        </p>
      </div>

      <div id="roi-theater" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8"></div>

      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row md:items-center justify-between border-b border-slate-800 pb-6 gap-4">
          <div id="roi-tax-filters" class="flex flex-wrap items-center gap-2"></div>

          <button id="roi-yt-sync"
            class="text-xs bg-slate-800 hover:bg-slate-700 text-sky-400 px-3.5 py-2 rounded-xl border border-slate-700 flex items-center gap-2 self-end md:self-auto transition-colors transform hover:scale-105"
            title="Fetch live channel snippets">
            ${icon('refresh-cw', 'w-3.5 h-3.5 text-sky-400')}
            <span>Sync Live YouTube Feed</span>
          </button>
        </div>

        <div id="roi-media-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 pt-10"></div>
      </div>

    </div>`;

  const theaterEl = root.querySelector('#roi-theater');
  const filtersEl = root.querySelector('#roi-tax-filters');
  const gridEl = root.querySelector('#roi-media-grid');
  const syncBtn = root.querySelector('#roi-yt-sync');

  function paintTheater() {
    const featuredVideo = media.find((m) => m.is_featured) || media[0];
    if (!featuredVideo) {
      theaterEl.innerHTML = '';
      return;
    }
    theaterEl.innerHTML = `
      <div class="bg-slate-950 border-2 border-slate-800 rounded-3xl overflow-hidden shadow-2xl relative grid grid-cols-1 lg:grid-cols-12 group hover:border-red-500/50 transition-colors">

        <div data-open-video="${escapeHtml(String(featuredVideo.id || featuredVideo.youtube_id))}"
             class="lg:col-span-8 relative aspect-video bg-black cursor-pointer overflow-hidden">
          ${imageWithFallback(
            featuredVideo.thumbnail_url || `https://img.youtube.com/vi/${featuredVideo.youtube_id}/maxresdefault.jpg`,
            featuredVideo.title,
            'w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 opacity-90',
            'Featured Theater Special'
          )}
          <div class="absolute inset-0 bg-black/40 group-hover:bg-black/20 transition-colors flex items-center justify-center pointer-events-none">
            <div class="w-20 h-20 rounded-full bg-red-600 group-hover:bg-red-500 text-white flex items-center justify-center shadow-2xl group-hover:scale-110 transition-transform">
              ${icon('play', 'w-8 h-8 fill-current ml-1')}
            </div>
          </div>
          <span class="absolute top-4 left-4 px-3 py-1 rounded-full bg-red-600 text-white text-xs font-black uppercase tracking-widest flex items-center gap-1.5 shadow-lg">
            ${icon('sparkles', 'w-3.5 h-3.5 animate-spin')}
            <span>Featured Theater Broadcast</span>
          </span>
        </div>

        <div class="lg:col-span-4 p-8 flex flex-col justify-between bg-slate-900 space-y-6 border-t lg:border-t-0 lg:border-l border-slate-800">
          <div class="space-y-3">
            <span class="text-xs font-bold text-amber-400 uppercase tracking-widest block">${escapeHtml(featuredVideo.category)}</span>
            <h3 class="text-2xl font-black text-white leading-tight">${escapeHtml(featuredVideo.title)}</h3>
            <p class="text-xs sm:text-sm text-slate-300 leading-relaxed line-clamp-4">${escapeHtml(featuredVideo.summary)}</p>
          </div>

          <div class="pt-4 border-t border-slate-800 space-y-3">
            <div class="flex items-center justify-between text-xs text-slate-400">
              <span class="flex items-center gap-1">${icon('clock', 'w-3.5 h-3.5')} ${escapeHtml(featuredVideo.duration || '12:45')}</span>
              <span class="flex items-center gap-1">${icon('calendar', 'w-3.5 h-3.5')} ${fmtDate(featuredVideo.published_at || Date.now())}</span>
            </div>
            <button data-open-video="${escapeHtml(String(featuredVideo.id || featuredVideo.youtube_id))}"
              class="w-full py-3.5 rounded-xl bg-red-600 hover:bg-red-500 text-white font-black text-xs uppercase tracking-wider flex items-center justify-center gap-2 shadow-lg shadow-red-600/25">
              ${icon('play', 'w-4 h-4 fill-current')}
              <span>Launch Theater Lightbox</span>
            </button>
          </div>
        </div>
      </div>`;
  }

  function mediaCard(vid) {
    return `
<div data-open-video="${escapeHtml(String(vid.id || vid.youtube_id))}"
            class="group cursor-pointer bg-slate-800/80 rounded-3xl overflow-hidden border border-slate-700 hover:border-red-500 transition-colors transform duration-300 flex flex-col hover-scale">
        <div class="relative aspect-video w-full overflow-hidden bg-slate-950">
          ${imageWithFallback(
            vid.thumbnail_url || `https://img.youtube.com/vi/${vid.youtube_id}/maxresdefault.jpg`,
            vid.title,
            'w-full h-full object-cover group-hover:scale-105 transition-transform duration-500',
            vid.category
          )}
          <div class="absolute inset-0 bg-black/40 group-hover:bg-black/20 transition-colors flex items-center justify-center pointer-events-none">
            <div class="w-12 h-12 rounded-full bg-red-600 group-hover:bg-red-500 text-white flex items-center justify-center shadow-xl group-hover:scale-110 transition-transform">
              ${icon('play', 'w-5 h-5 fill-current ml-0.5')}
            </div>
          </div>
          <span class="absolute bottom-3 right-3 px-2 py-0.5 rounded bg-black/80 text-[10px] font-mono font-bold text-white">${escapeHtml(vid.duration || '5:30')}</span>
          <span class="absolute top-3 left-3 px-2.5 py-1 rounded-full bg-slate-900/90 text-[10px] font-bold text-amber-400 uppercase tracking-wider">${escapeHtml(vid.category)}</span>
        </div>

        <div class="p-6 flex-1 flex flex-col justify-between space-y-3">
          <div>
            <h4 class="font-bold text-base text-white group-hover:text-red-400 transition-colors line-clamp-2 leading-snug">${escapeHtml(vid.title)}</h4>
            <p class="text-xs text-slate-400 mt-2 line-clamp-2 leading-relaxed">${escapeHtml(vid.summary || 'Empowering broadcast documenting youth potential in the coast.')}</p>
          </div>
          <div class="pt-3 border-t border-slate-700/60 flex items-center justify-between text-[11px] text-slate-500 font-medium">
            <span>DEMO TV Stream</span>
            <span>${fmtDate(vid.published_at || Date.now())}</span>
          </div>
        </div>
      </div>`;
  }

  function paintFilters() {
    filtersEl.innerHTML = TAXONOMIES.map(
      (tax) => `
      <button data-tax="${escapeHtml(tax)}" class="px-4 py-2 rounded-xl text-xs font-bold transition-colors transition-shadow transform ${
        activeFilter === tax ? 'bg-red-600 text-white shadow-lg shadow-red-600/20 scale-105' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
      }">${tax}</button>`
    ).join('');
    filtersEl.querySelectorAll('[data-tax]').forEach((b) =>
      b.addEventListener('click', () => {
        activeFilter = b.dataset.tax;
        paintFilters();
        paintGrid();
      })
    );
  }

  function paintGrid() {
    if (loading) {
      gridEl.innerHTML = `
        <div class="bg-slate-800 animate-pulse aspect-video rounded-3xl"></div>
        <div class="bg-slate-800 animate-pulse aspect-video rounded-3xl"></div>
        <div class="bg-slate-800 animate-pulse aspect-video rounded-3xl"></div>`;
      return;
    }
    const gridVideos = media.filter((m) => activeFilter === 'All' || m.category === activeFilter);
    if (gridVideos.length === 0) {
      gridEl.innerHTML = `<div class="col-span-full text-center py-12 text-slate-400">No videos match this category taxonomy.</div>`;
      return;
    }
    gridEl.innerHTML = gridVideos.map(mediaCard).join('');
  }

  function bindOpeners() {
    root.querySelectorAll('[data-open-video]').forEach((el) => {
      el.addEventListener('click', () => {
        const video = media.find((v) => String(v.id || v.youtube_id) === el.dataset.openVideo)
          || media.find((v) => v.is_featured)
          || media[0];
        openVideoLightbox(video);
      });
    });
  }

  syncBtn.addEventListener('click', async () => {
    // F-03: live refresh is destructive (cache wipe + quota spend) and now
    // admin-only. Anonymous visitors get a clear message instead of an outage banner.
    const { getAuthState } = await import('../store.js');
    if (!getAuthState().admin) {
      window.alert('Live YouTube sync requires an administrator session. The current cached feed is shown below.');
      return;
    }
    loading = true;
    syncBtn.querySelector('svg')?.classList.add('animate-spin');
    paintGrid();
    const fresh = await getMedia(true);
    media = fresh || [];
    loading = false;
    paintTheater();
    paintFilters();
    paintGrid();
    bindOpeners();
    syncBtn.querySelector('svg')?.classList.remove('animate-spin');
    window.alert('YouTube Data API v3 live feed synchronized!');
  });

  paintFilters();

  getMedia().then((data) => {
    if (data) media = data;
    loading = false;
    paintTheater();
    paintGrid();
    bindOpeners();
  });
}
