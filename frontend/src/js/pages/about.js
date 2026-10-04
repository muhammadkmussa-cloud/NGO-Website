// About page — vanilla port of pages/About.jsx (pillars, timeline, leaders from API).
import { request } from '../api.js';
import { icon } from '../icons.js';
import { escapeHtml } from '../ui.js';

const PILLARS = [
  {
    title: 'Ethical Mentorship',
    iconName: 'shield',
    color: 'text-sky-400 bg-sky-500/10 border-sky-500/30',
    description: 'Rigorous 12-week character, integrity, and leadership readiness circles pairing vulnerable coastal youth with accomplished community professionals.'
  },
  {
    title: 'Digital Education & Software Services',
    iconName: 'book-open',
    color: 'text-amber-400 bg-amber-500/10 border-amber-500/30',
    description: 'We teach coding, web design, digital marketing, and freelancing while building websites, web apps, portfolios, and custom software systems.'
  },
  {
    title: 'Impactful Social Programs',
    iconName: 'heart',
    color: 'text-emerald-400 bg-emerald-500/10 border-emerald-500/30',
    description: 'Community environmental cleanups, coastal marine conservation, clean water filtration distribution, and direct support for vulnerable households.'
  }
];

const TIMELINE = [
  { year: '2021', title: 'Origins in Harbor City, Kenya', desc: 'Founded by passionate local changemakers who recognized the urgent need for structured youth support amidst rising coastal unemployment.' },
  { year: '2023', title: 'Official CBO Registration', desc: 'Officially registered as a Community-Based Organization (CBO), establishing the foundation for our youth empowerment initiatives.' },
  { year: '2024', title: 'Youth Leadership Summit Vol 1', desc: 'Launched the first Youth Leadership Summit, bringing together over 400 youth for spiritual enrichment and ethical leadership.' },
  { year: '2025', title: 'Youth Leadership Summit Vol 2', desc: 'Held the second Youth Leadership Summit, bringing together over 600 youth under the theme “Safari ya Ramadhani.”' },
  { year: '2026', title: 'Youth Leadership Summit Vol 3', desc: 'The third Youth Leadership Summit will be held under the theme “MIND VS NAFS”, alongside the launch of DEMO Digital Solutions.' }
];

export function renderAbout(root) {
  root.innerHTML = `
    <div class="py-16 sm:py-24 bg-slate-900 space-y-24">

      <div class="max-w-7xl px-4 sm:px-6 lg:px-8 text-center max-w-3xl mx-auto space-y-6">
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-800 border border-slate-700 text-amber-400 text-xs font-bold">
          ${icon('map-pin', 'w-3.5 h-3.5')}
          <span>Coastal Headquarters: Harbor City, Kenya</span>
        </div>
        <h1 class="text-4xl sm:text-6xl font-black text-white">
          Our Story & Mission: Uplifting Harbor City's Youth
        </h1>
        <p class="text-base sm:text-lg text-slate-300 leading-relaxed font-normal">
          The <strong class="text-white">Demo NGO (DEMO)</strong> is a dedicated community-based organization registered and operating in Harbor City, Kenya. We exist to uplift vulnerable young individuals through holistic empowerment.
        </p>
      </div>

      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-16">
          <h2 class="text-xs font-bold uppercase tracking-widest text-sky-400 mb-2">Pillars of Transformation</h2>
          <p class="text-3xl font-black text-white">How We Create Lasting Community Change</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
          ${PILLARS.map(
            (pillar) => `
            <div class="bg-slate-800/80 border border-slate-700 p-8 rounded-3xl space-y-4 hover:border-slate-500 transition-colors transform hover-scale">
              <div class="w-14 h-14 rounded-2xl border flex items-center justify-center ${pillar.color}">
                ${icon(pillar.iconName, 'w-7 h-7')}
              </div>
              <h3 class="text-xl font-bold text-white">${pillar.title}</h3>
              <p class="text-sm text-slate-300 leading-relaxed">${pillar.description}</p>
            </div>`
          ).join('')}
        </div>
      </div>

      <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-16">
          <h2 class="text-xs font-bold uppercase tracking-widest text-amber-400 mb-2">Chronological Milestones</h2>
          <p class="text-3xl font-black text-white">The Journey of Demo NGO</p>
        </div>

        <div class="relative border-l-2 border-sky-500/30 ml-4 sm:ml-32 space-y-12">
          ${TIMELINE.map(
            (item) => `
            <div class="relative pl-8 sm:pl-10 group">
              <div class="absolute -left-[9px] top-1.5 w-4 h-4 rounded-full bg-slate-900 border-4 border-sky-500 group-hover:scale-125 group-hover:bg-amber-400 transition-colors transform"></div>
              <span class="sm:absolute sm:-left-32 sm:top-1 font-black text-amber-400 font-mono text-lg block mb-1 sm:mb-0">${item.year}</span>
              <div class="bg-slate-800/60 border border-slate-700/80 p-6 rounded-2xl group-hover:border-sky-500/50 transition-colors">
                <h4 class="text-lg font-bold text-white">${item.title}</h4>
                <p class="text-xs sm:text-sm text-slate-300 mt-2 leading-relaxed">${item.desc}</p>
              </div>
            </div>`
          ).join('')}
        </div>
      </div>

      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-16">
          <h2 class="text-xs font-bold uppercase tracking-widest text-emerald-400 mb-2">Community Leadership</h2>
          <p class="text-3xl font-black text-white">Meet the Dedicated Stewards of DEMO</p>
        </div>

        <div id="roi-leaders-grid" class="grid grid-cols-1 md:grid-cols-3 gap-8"></div>
      </div>

    </div>`;

  // Leaders fetched via raw api.get — failures logged only, list stays empty (exact parity).
  request('GET', '/public/leaders')
    .then((res) => {
      if (!res.ok) throw new Error('leaders fetch failed');
      const grid = document.getElementById('roi-leaders-grid');
      if (!grid) return;
      grid.innerHTML = res.data
        .map(
          (leader) => `
          <div class="bg-slate-800/80 border border-slate-700 rounded-3xl overflow-hidden shadow-xl group hover:border-sky-500 transition-colors transform hover-scale p-6 space-y-4 text-center sm:text-left flex flex-col justify-center min-h-[200px]">
            <h4 class="text-xl font-bold text-white">${escapeHtml(leader.name)}</h4>
            <span class="text-xs font-bold text-sky-400 block uppercase tracking-wider">${escapeHtml(leader.role)}</span>
            <p class="text-sm text-slate-300 pt-2 leading-relaxed">${escapeHtml(leader.bio)}</p>
          </div>`
        )
        .join('');
    })
    .catch((err) => console.error(err));
}
