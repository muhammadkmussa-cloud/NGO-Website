// Home page — vanilla port of pages/Home.jsx + components/Hero.jsx, Metrics.jsx, LatestMedia.jsx.
// Framer Motion entrance animations are replaced with CSS keyframe classes (.anim-hero-left / .anim-hero-right).
import { getSite, getLatestMedia } from '../api.js';
import { icon } from '../icons.js';
import { escapeHtml, imageWithFallback } from '../ui.js';

export function renderHome(root, onOpenDonate, onSelectVideo) {
  root.innerHTML = `
    <div class="space-y-0">
      <section class="relative overflow-hidden pt-12 pb-24 lg:pt-20 lg:pb-36 bg-slate-900">

        <div class="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-sky-500/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute bottom-10 right-10 w-[400px] h-[400px] bg-amber-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
          <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">

            <div class="lg:col-span-7 space-y-6 text-center lg:text-left anim-hero-left">
              <div class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-amber-400 mb-2">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                Demo NGO • Harbor City, Kenya
              </div>
              <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight text-white leading-[1.1]">
                Empowering Youth Through Mentorship & Technology
              </h1>

              <p class="text-base sm:text-lg text-slate-300 max-w-2xl mx-auto lg:mx-0 font-normal leading-relaxed">
                <strong class="text-white">Demo NGO (DEMO)</strong> is a community-driven organization in Harbor City dedicated to empowering young people, supporting vulnerable communities, and creating meaningful opportunities for positive change through youth empowerment, mentorship, Islamic and values-based programmes, community service, and our flagship annual <em class="text-amber-400 not-italic font-semibold">Youth Leadership Summit</em> conference.
              </p>

              <div class="flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-4 pt-4">
                <button id="roi-hero-donate" class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-gradient-to-r from-amber-400 to-amber-500 hover:from-amber-300 hover:to-amber-400 text-slate-950 font-black text-sm flex items-center justify-center gap-2 shadow-xl shadow-amber-500/20 hover-scale">
                  ${icon('heart', 'w-4 h-4 fill-slate-950')}
                  <span>DONATE TO THE CAUSE</span>
                </button>

                <a href="#/volunteer" class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-slate-800 hover:bg-slate-700 text-white font-bold text-sm border border-slate-700 flex items-center justify-center gap-2 hover-scale">
                  ${icon('users', 'w-4 h-4 text-sky-400')}
                  <span>Join Volunteer Network</span>
                </a>
              </div>
            </div>

            <div class="lg:col-span-5 relative anim-hero-right">
              <div class="relative mx-auto max-w-md lg:max-w-none">

                <div id="roi-hero-media" class="rounded-3xl overflow-hidden border border-slate-700 bg-slate-800 shadow-2xl shadow-black/80 group aspect-[4/3] relative">
                  ${imageWithFallback(
                    'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=900&q=80',
                    'Youth Leadership Summit Conference',
                    'w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 opacity-85',
                    'Flagship Conference 2026'
                  )}
                  <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/40 to-transparent flex flex-col justify-end p-6 sm:p-8 pointer-events-none">
                    <span id="roi-hero-eyebrow" class="text-xs font-bold uppercase tracking-widest text-amber-400 mb-1">Flagship Conference 2026</span>
                    <h3 id="roi-hero-title" class="text-2xl font-black text-white">Youth Leadership Summit</h3>
                    <p id="roi-hero-desc" class="text-sm text-slate-300 mt-2 line-clamp-2">Uniting 500+ coastal youth for mentorship, ethical leadership grounding, and digital career advancement.</p>
                    <a href="#/events" class="mt-4 inline-flex items-center gap-2 text-xs font-bold text-sky-400 hover:text-sky-300 pointer-events-auto">
                      <span>View Conference Itinerary</span>
                      ${icon('arrow-right', 'w-4 h-4')}
                    </a>
                  </div>
                </div>

              </div>
            </div>
          </div>
        </div>
      </section>

      <section id="roi-metrics-section" class="py-16 bg-slate-950 border-y border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div class="text-center max-w-2xl mx-auto mb-12">
            <h2 class="text-xs font-bold uppercase tracking-widest text-sky-400 mb-2">Verifiable Milestones</h2>
            <p class="text-2xl sm:text-3xl font-black text-white">Measurable Impact Across the coast</p>
          </div>
          <div id="roi-metrics-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6"></div>
        </div>
      </section>

      <section class="py-20 bg-slate-900">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

          <div class="flex flex-col sm:flex-row sm:items-end justify-between mb-12 gap-4">
            <div>
              <div class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-red-500 mb-2">
                ${icon('tv', 'w-4 h-4 animate-pulse')}
                <span>DEMO TV Broadcast</span>
              </div>
              <h2 class="text-2xl sm:text-4xl font-black text-white">Latest from Demo Media</h2>
            </div>
            <a href="#/media" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-xs font-bold text-sky-400 transition-colors transform hover:scale-105">
              <span>Explore Full Media Hub</span>
              ${icon('arrow-right', 'w-4 h-4')}
            </a>
          </div>

          <div id="roi-latest-grid" class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="bg-slate-800 animate-pulse aspect-video rounded-2xl"></div>
            <div class="bg-slate-800 animate-pulse aspect-video rounded-2xl"></div>
            <div class="bg-slate-800 animate-pulse aspect-video rounded-2xl"></div>
          </div>
        </div>
      </section>

      <section class="py-20 bg-gradient-to-r from-sky-900 via-slate-900 to-amber-950/40 border-t border-slate-800 relative overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 flex flex-col lg:flex-row items-center justify-between gap-10">
          <div class="space-y-4 max-w-2xl text-center lg:text-left">
            <span class="px-3 py-1 rounded-full bg-amber-400 text-slate-950 font-black text-[11px] uppercase tracking-wider">Annual Flagship Conference</span>
            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-black text-white leading-tight">Youth Leadership Summit 2026</h2>
            <p class="text-sm sm:text-base text-slate-300 leading-relaxed">Convening hundreds of youth, changemakers, and industry titans in Harbor City to ground ethical leadership and ignite economic independence across the coast.</p>
          </div>

          <div class="flex flex-col sm:flex-row gap-4 w-full lg:w-auto">
            <a href="#/events" class="px-8 py-4 rounded-2xl bg-sky-500 hover:bg-sky-400 text-white font-extrabold text-sm flex items-center justify-center gap-2 shadow-xl shadow-sky-500/20 transition-colors transform hover:scale-105">
              ${icon('calendar', 'w-4 h-4')}
              <span>Explore Conference Details</span>
            </a>
            <button id="roi-flagship-donate" class="px-8 py-4 rounded-2xl bg-amber-400 hover:bg-amber-300 text-slate-950 font-extrabold text-sm flex items-center justify-center gap-2 shadow-xl shadow-amber-500/20 transition-colors transform hover:scale-105">
              ${icon('heart', 'w-4 h-4 fill-slate-950')}
              <span>Sponsor a Youth Delegate</span>
            </button>
          </div>
        </div>
      </section>

      <section class="py-24 bg-slate-950 text-center relative px-4 border-t border-slate-800">
        <div class="max-w-3xl mx-auto space-y-6">
          <div class="w-16 h-16 rounded-2xl bg-sky-500/10 border border-sky-500/30 text-sky-400 flex items-center justify-center mx-auto">
            ${icon('users', 'w-8 h-8 animate-pulse')}
          </div>
          <h2 class="text-3xl sm:text-4xl font-black text-white">Ready to Make a Tangible Difference?</h2>
          <p class="text-sm sm:text-base text-slate-400 leading-relaxed">Whether you are a mentor, developer, event planner, graphic designer, or teacher—your unique skills can empower vulnerable youth in Northside, Riverside, Southside, and across Harbor City.</p>
          <div class="pt-2">
            <a href="#/volunteer" class="inline-flex items-center gap-2 px-8 py-4 rounded-2xl bg-gradient-to-r from-sky-500 to-blue-600 hover:from-sky-400 hover:to-blue-500 text-white font-black text-sm shadow-xl shadow-sky-500/25 transition-colors transform hover:scale-105">
              <span>Submit Volunteer Application</span>
              ${icon('arrow-right', 'w-4 h-4')}
            </a>
          </div>
        </div>
      </section>
    </div>`;

  // Wire donate triggers
  const openDonate = () => onOpenDonate();
  document.getElementById('roi-hero-donate').addEventListener('click', openDonate);
  document.getElementById('roi-flagship-donate').addEventListener('click', openDonate);

  // ---- Hero + Metrics (admin-editable via /api/public/site)
  let metrics = { youth_mentored: 120, events_hosted: 2, individuals_supported: 95, active_volunteers: 45 };

  function paintMetrics() {
    const stats = [
      { label: 'Youth Mentored', value: `${metrics.youth_mentored}+`, iconName: 'users', color: 'from-sky-500 to-blue-600', description: 'Equipped with career & ethical skills' },
      { label: 'Events Hosted', value: `${metrics.events_hosted}+`, iconName: 'calendar', color: 'from-amber-500 to-orange-600', description: 'Including Youth Leadership Summit conference' },
      { label: 'Individuals Supported', value: `${metrics.individuals_supported}+`, iconName: 'heart-handshake', color: 'from-emerald-500 to-teal-600', description: 'Vulnerable families & students aided' },
      { label: 'Volunteer Network', value: `${metrics.active_volunteers}+`, iconName: 'award', color: 'from-purple-500 to-indigo-600', description: 'Active changemakers in Harbor City' }
    ];
    document.getElementById('roi-metrics-grid').innerHTML = stats.map(
      (stat) => `
      <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 relative overflow-hidden group hover:border-slate-700 hover-scale">
        <div class="flex items-center justify-between mb-4">
          <div class="w-12 h-12 rounded-xl bg-gradient-to-tr ${stat.color} flex items-center justify-center text-white shadow-lg">
            ${icon(stat.iconName, 'w-6 h-6')}
          </div>
          <span class="text-3xl lg:text-4xl font-black text-white group-hover:text-amber-400 transition-colors">${escapeHtml(stat.value)}</span>
        </div>
        <h3 class="text-sm font-bold text-slate-200">${stat.label}</h3>
        <p class="text-xs text-slate-400 mt-1">${stat.description}</p>
      </div>`
    ).join('');
  }

  paintMetrics();
  getSite().then((site) => {
    if (site?.metrics) {
      metrics = { ...metrics, ...site.metrics };
      paintMetrics();
    }
    if (site?.hero) {
      const img = document.querySelector('#roi-hero-media img');
      if (img && site.hero.image_url) img.src = site.hero.image_url;
      const eyebrow = document.getElementById('roi-hero-eyebrow');
      const title = document.getElementById('roi-hero-title');
      const desc = document.getElementById('roi-hero-desc');
      if (eyebrow && site.hero.eyebrow) eyebrow.textContent = site.hero.eyebrow;
      if (title && site.hero.title) title.textContent = site.hero.title;
      if (desc && site.hero.description) desc.textContent = site.hero.description;
    }
  });

  // ---- LatestMedia
  function mediaCard(vid) {
    return `
<div data-video-id="${escapeHtml(String(vid.id || vid.youtube_id))}"
            class="group cursor-pointer bg-slate-800/80 rounded-2xl overflow-hidden border border-slate-700/80 hover:border-sky-500 transition-colors transform duration-300 flex flex-col hover-scale">
        <div class="relative aspect-video w-full overflow-hidden bg-slate-950">
          ${imageWithFallback(
            vid.thumbnail_url || `https://img.youtube.com/vi/${vid.youtube_id}/maxresdefault.jpg`,
            vid.title,
            'w-full h-full object-cover group-hover:scale-105 transition-transform duration-500',
            vid.category || 'DEMO TV Special'
          )}
          <div class="absolute inset-0 bg-black/40 group-hover:bg-black/20 transition-colors flex items-center justify-center pointer-events-none">
            <div class="w-14 h-14 rounded-full bg-red-600 group-hover:bg-red-500 text-white flex items-center justify-center shadow-xl group-hover:scale-110 transition-transform">
              ${icon('play', 'w-6 h-6 fill-current ml-1')}
            </div>
          </div>
          <span class="absolute bottom-3 right-3 px-2 py-1 rounded bg-black/80 text-[11px] font-mono font-bold text-white">${escapeHtml(vid.duration || '5:30')}</span>
          <span class="absolute top-3 left-3 px-2.5 py-1 rounded-full bg-sky-600/90 text-[10px] font-bold text-white uppercase tracking-wider">${escapeHtml(vid.category || 'Outreach')}</span>
        </div>

        <div class="p-5 flex-1 flex flex-col justify-between space-y-3">
          <div>
            <h3 class="font-bold text-base text-white group-hover:text-sky-400 transition-colors line-clamp-2 leading-snug">${escapeHtml(vid.title)}</h3>
            <p class="text-sm text-slate-400 mt-2 line-clamp-2 leading-relaxed">${escapeHtml(vid.summary || 'Inspiring video storytelling documenting youth transformation in Harbor City.')}</p>
          </div>
          <div class="pt-3 border-t border-slate-700/60 flex items-center justify-between text-[11px] text-slate-500 font-medium">
            <span>Channel: DEMO TV</span>
            <span>${new Date(vid.published_at || Date.now()).toLocaleDateString()}</span>
          </div>
        </div>
      </div>`;
  }

  getLatestMedia().then((data) => {
    const grid = document.getElementById('roi-latest-grid');
    if (!grid) return;
    const videos = data || [];
    if (!videos.length) return;
    grid.innerHTML = videos.slice(0, 3).map(mediaCard).join('');
    grid.querySelectorAll('[data-video-id]').forEach((card) =>
      card.addEventListener('click', () => onSelectVideo(videos.find((v) => String(v.id || v.youtube_id) === card.dataset.videoId)))
    );
  });
}
