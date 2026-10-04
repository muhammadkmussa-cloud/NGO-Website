// ROI Digital Solutions — public branch page.
// Sections: hero, mission, relationship to ROI, services, industries, tech
// capabilities, digital transformation, development process, portfolio,
// FAQs, request-a-solution form, contact CTA.
import { getSolutions, getFaqs, getIndustries, getTechCapabilities, getPortfolio, inquireSolution } from '../api.js';
import { icon } from '../icons.js';
import { escapeHtml, imageWithFallback, showToast } from '../ui.js';

const PROCESS_STEPS = [
  { n: '01', title: 'Discover', text: 'We audit your current operations, interview stakeholders, and define measurable goals before any code is written.' },
  { n: '02', title: 'Design', text: 'Wireframes and architecture documents are reviewed with you. You approve scope and budget before build begins.' },
  { n: '03', title: 'Build', text: 'Two-week sprints with a working demo at each review. Automated tests cover every feature we ship.' },
  { n: '04', title: 'Launch', text: 'Zero-downtime deployment, staff training, data migration, and documentation handover.' },
  { n: '05', title: 'Support', text: 'Managed hosting, security patches, monitoring, and a Kenya-based support team you can call.' }
];

function sectionHeader(kicker, kickerColor, title) {
  return `
    <div class="text-center max-w-3xl mx-auto space-y-3">
      <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full ${kickerColor} text-xs font-bold uppercase tracking-wider">
        <span>${escapeHtml(kicker)}</span>
      </div>
      <h2 class="text-3xl sm:text-4xl font-black text-white">${title}</h2>
    </div>`;
}

export function renderSolutions(root) {
  root.innerHTML = `
    <div class="bg-slate-900 min-h-screen">

      <!-- 1. HERO -->
      <section class="py-20 sm:py-28 relative overflow-hidden border-b border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 grid grid-cols-1 lg:grid-cols-12 gap-12 items-center relative z-10">
          <div class="lg:col-span-7 space-y-6 text-center lg:text-left">
            <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-sky-500/10 border border-sky-500/30 text-sky-400 text-xs font-bold uppercase tracking-widest">
              ${icon('globe', 'w-3.5 h-3.5')}
              <span>A Division of Reaching Out Initiative</span>
            </div>
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight text-white leading-[1.1]">
              Digital Transformation &amp; Software Solutions for Coastal Kenya
            </h1>
            <p class="text-base sm:text-lg text-slate-300 max-w-2xl mx-auto lg:mx-0 leading-relaxed">
              We help businesses, institutions, NGOs, schools, and community organizations solve real operational problems using digital technology — from M-Pesa payment systems to full management platforms.
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-4 pt-4">
              <a href="#request-solution" class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-sky-500 hover:bg-sky-400 text-white font-extrabold text-sm flex items-center justify-center gap-2 transition-colors transform hover:scale-[1.02]">
                ${icon('send', 'w-4 h-4')}
                <span>Request a Solution</span>
              </a>
              <a href="#services" class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-slate-800 hover:bg-slate-700 text-white font-bold text-sm border border-slate-700 flex items-center justify-center gap-2 transition-colors transform hover:scale-[1.02]">
                ${icon('layout', 'w-4 h-4 text-sky-400')}
                <span>Explore Services</span>
              </a>
            </div>
          </div>
        </div>
      </section>

      <!-- 2. MISSION / PURPOSE -->
      <section id="mission" class="py-20 sm:py-24 border-b border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          ${sectionHeader('Our Purpose', 'bg-sky-500/10 border border-sky-500/30 text-sky-400', 'Technology that solves operational problems')}
          <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mt-12 max-w-6xl mx-auto">
            <div class="bg-slate-950 border border-slate-800 rounded-3xl p-8 space-y-3">
              <div class="w-12 h-12 rounded-xl bg-sky-500/10 border border-sky-500/30 flex items-center justify-center text-sky-400">${icon('lightbulb', 'w-6 h-6')}</div>
              <h3 class="text-xl font-black text-white">Practical first</h3>
              <p class="text-sm text-slate-300 leading-relaxed">We start with the problem your team actually faces — manual registers, lost records, missed payments — and design technology around it.</p>
            </div>
            <div class="bg-slate-950 border border-slate-800 rounded-3xl p-8 space-y-3">
              <div class="w-12 h-12 rounded-xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-amber-400">${icon('users', 'w-6 h-6')}</div>
              <h3 class="text-xl font-black text-white">Built for local reality</h3>
              <p class="text-sm text-slate-300 leading-relaxed">M-Pesa flows, unreliable connectivity, CBC grading, county reporting — we build for how Kenya works, not how Silicon Valley wishes it did.</p>
            </div>
            <div class="bg-slate-950 border border-slate-800 rounded-3xl p-8 space-y-3">
              <div class="w-12 h-12 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400">${icon('heart-handshake', 'w-6 h-6')}</div>
              <h3 class="text-xl font-black text-white">Mission-aligned</h3>
              <p class="text-sm text-slate-300 leading-relaxed">Every engagement funds mentorship cohorts and digital labs for vulnerable youth in Tudor, Kisauni, and Likoni.</p>
            </div>
          </div>
        </div>
      </section>

      <!-- 3. RELATIONSHIP TO ROI -->
      <section id="about-roi" class="py-20 sm:py-24 bg-slate-950 border-b border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center max-w-6xl mx-auto">
            <div class="space-y-5">
              <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs font-bold uppercase tracking-wider">Part of something bigger</div>
              <h2 class="text-3xl sm:text-4xl font-black text-white">ROI Digital Solutions is the technology arm of Reaching Out Initiative</h2>
              <p class="text-base text-slate-300 leading-relaxed">
                Reaching Out Initiative (ROI) is a registered community-based organization in Mombasa that has mentored hundreds of coastal youth through ethical leadership training, digital education, and its flagship Vijana Na Maadili conference.
              </p>
              <p class="text-base text-slate-300 leading-relaxed">
                ROI Digital Solutions is the division that builds and maintains the platforms behind that mission — the conference ticketing system, the media hub, and the digital labs. The same engineering team now offers those capabilities commercially, so every shilling earned strengthens programs for vulnerable young people.
              </p>
              <a href="#/" class="inline-flex items-center gap-2 text-sm font-bold text-sky-400 hover:text-sky-300">Learn about the nonprofit ${icon('arrow-right', 'w-4 h-4')}</a>
            </div>
            <div class="bg-slate-900 border border-slate-800 rounded-3xl p-8 space-y-6">
              <div class="flex items-start gap-4">
                <div class="w-11 h-11 rounded-xl bg-sky-500/10 border border-sky-500/30 flex items-center justify-center text-sky-400 shrink-0">${icon('award', 'w-5 h-5')}</div>
                <div><h4 class="font-black text-white text-sm">Registered CBO</h4><p class="text-xs text-slate-400 mt-1">Operating in Mombasa since 2021, headquartered in Tudor with programs across Kisauni and Likoni.</p></div>
              </div>
              <div class="flex items-start gap-4">
                <div class="w-11 h-11 rounded-xl bg-red-500/10 border border-red-500/30 flex items-center justify-center text-red-400 shrink-0">${icon('tv', 'w-5 h-5')}</div>
                <div><h4 class="font-black text-white text-sm">Runs its own stack</h4><p class="text-xs text-slate-400 mt-1">Ticketing, media syndication, and lab portals are built and operated by this same team — dogfooding everything we sell.</p></div>
              </div>
              <div class="flex items-start gap-4">
                <div class="w-11 h-11 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400 shrink-0">${icon('heart-handshake', 'w-5 h-5')}</div>
                <div><h4 class="font-black text-white text-sm">Revenue fuels mission</h4><p class="text-xs text-slate-400 mt-1">Commercial contracts subsidize free seats in youth bootcamps and volunteer-run community programs.</p></div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- 4. SERVICES -->
      <section id="services" class="py-20 sm:py-24 border-b border-slate-800 scroll-mt-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          ${sectionHeader('What We Build', 'bg-sky-500/10 border border-sky-500/30 text-sky-400', 'Sixteen service lines, one accountable team')}
          <div id="roi-services-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mt-12">
            <div class="bg-slate-950 animate-pulse h-56 rounded-3xl"></div>
            <div class="bg-slate-950 animate-pulse h-56 rounded-3xl"></div>
            <div class="bg-slate-950 animate-pulse h-56 rounded-3xl"></div>
          </div>
        </div>
      </section>

      <!-- 5. INDUSTRIES SERVED -->
      <section id="industries" class="py-20 sm:py-24 bg-slate-950 border-b border-slate-800 scroll-mt-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          ${sectionHeader('Who We Serve', 'bg-amber-500/10 border border-amber-500/30 text-amber-400', 'Industries and organization types')}
          <div id="roi-industries-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mt-12">
            <div class="bg-slate-900 animate-pulse h-40 rounded-3xl"></div>
            <div class="bg-slate-900 animate-pulse h-40 rounded-3xl"></div>
            <div class="bg-slate-900 animate-pulse h-40 rounded-3xl"></div>
            <div class="bg-slate-900 animate-pulse h-40 rounded-3xl"></div>
          </div>
        </div>
      </section>

      <!-- 6. TECHNOLOGY CAPABILITIES -->
      <section id="tech" class="py-20 sm:py-24 border-b border-slate-800 scroll-mt-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          ${sectionHeader('Under the Hood', 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400', 'Technology capabilities')}
          <div id="roi-tech-groups" class="mt-12 max-w-6xl mx-auto space-y-10">
            <div class="bg-slate-950 animate-pulse h-48 rounded-3xl"></div>
          </div>
        </div>
      </section>

      <!-- 7. DIGITAL TRANSFORMATION EXPLANATION -->
      <section id="transformation" class="py-20 sm:py-24 bg-gradient-to-r from-sky-900 via-slate-900 to-amber-950/40 border-b border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center max-w-6xl mx-auto">
            <div class="space-y-5">
              <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 border border-white/20 text-sky-300 text-xs font-bold uppercase tracking-wider">Beyond websites</div>
              <h2 class="text-3xl sm:text-4xl font-black text-white">What digital transformation actually means</h2>
              <p class="text-base text-slate-200 leading-relaxed">
                A website does not transform an organization. Transformation happens when the way you record, move, and act on information changes: fees collected over M-Pesa reconciling themselves; beneficiaries tracked from intake to outcome; stock counted once instead of three times.
              </p>
              <p class="text-base text-slate-200 leading-relaxed">
                We map your current processes, identify where information stalls or duplicates, and rebuild those steps as software — then train your team until the new way is simply the way.
              </p>
            </div>
            <div class="space-y-4">
              <div class="bg-slate-950/80 border border-slate-800 rounded-2xl p-6">
                <h4 class="text-xs font-black uppercase tracking-widest text-red-400 mb-2">Before</h4>
                <ul class="space-y-2 text-sm text-slate-400">
                  <li>Paper registers and scattered Excel files</li>
                  <li>Cash-only collection with manual receipting</li>
                  <li>Reporting assembled days before deadlines</li>
                  <li>No single source of truth for records</li>
                </ul>
              </div>
              <div class="bg-slate-950/80 border border-emerald-700/40 rounded-2xl p-6">
                <h4 class="text-xs font-black uppercase tracking-widest text-emerald-400 mb-2">After</h4>
                <ul class="space-y-2 text-sm text-slate-300">
                  <li>One database, role-based access, full audit trail</li>
                  <li>M-Pesa STK payments reconciled automatically</li>
                  <li>Dashboards and exports available on demand</li>
                  <li>Staff trained, documentation handed over</li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- 8. DEVELOPMENT PROCESS -->
      <section id="process" class="py-20 sm:py-24 border-b border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          ${sectionHeader('How We Work', 'bg-sky-500/10 border border-sky-500/30 text-sky-400', 'A five-step delivery process')}
          <div id="roi-process-steps" class="mt-12 max-w-6xl mx-auto grid grid-cols-1 md:grid-cols-5 gap-5">
            ${PROCESS_STEPS.map((step) => `
              <div class="relative bg-slate-950 border border-slate-800 rounded-2xl p-6 hover:border-sky-500/40 transition-colors">
                <span class="text-3xl font-black text-sky-500/30 font-mono">${step.n}</span>
                <h3 class="text-lg font-black text-white mt-2">${step.title}</h3>
                <p class="text-xs text-slate-400 mt-2 leading-relaxed">${step.text}</p>
              </div>`).join('')}
          </div>
        </div>
      </section>

      <!-- 9. PORTFOLIO / CASE STUDIES -->
      <section id="portfolio" class="py-20 sm:py-24 bg-slate-950 border-b border-slate-800 scroll-mt-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          ${sectionHeader('Proof of Work', 'bg-amber-500/10 border border-amber-500/30 text-amber-400', 'Case studies')}
          <div id="roi-portfolio-grid" class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-12">
            <div class="bg-slate-900 animate-pulse h-64 rounded-3xl"></div>
            <div class="bg-slate-900 animate-pulse h-64 rounded-3xl"></div>
            <div class="bg-slate-900 animate-pulse h-64 rounded-3xl"></div>
          </div>
        </div>
      </section>

      <!-- 10. FAQS -->
      <section id="faqs" class="py-20 sm:py-24 border-b border-slate-800 scroll-mt-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
          ${sectionHeader('Questions', 'bg-sky-500/10 border border-sky-500/30 text-sky-400', 'Frequently asked questions')}
          <div id="roi-faq-list" class="mt-12 space-y-4"></div>
        </div>
      </section>

      <!-- 11 + 12. REQUEST A SOLUTION / CONTACT CTA -->
      <section id="request-solution" class="py-20 sm:py-24 bg-slate-950 scroll-mt-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-start max-w-6xl mx-auto">
            <div class="lg:col-span-5 space-y-5">
              <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold uppercase tracking-wider">Start here</div>
              <h2 class="text-3xl sm:text-4xl font-black text-white">Request a solution briefing</h2>
              <p class="text-base text-slate-300 leading-relaxed">Tell us the operational problem you face. We will respond within two business days with questions, options, and an honest assessment of fit — including when we are not the right partner.</p>
              <div class="pt-4 space-y-3 border-t border-slate-800">
                <a href="#/contact" class="flex items-center gap-3 text-sm font-bold text-sky-400 hover:text-sky-300">${icon('message-square', 'w-4 h-4')} Prefer to talk? Use the general contact desk</a>
                <a href="tel:+254745273556" class="flex items-center gap-3 text-sm font-bold text-sky-400 hover:text-sky-300">${icon('phone', 'w-4 h-4')} +254 745 273 556</a>
                <a href="mailto:reachingoutinitiative2021@gmail.com" class="flex items-center gap-3 text-sm font-bold text-sky-400 hover:text-sky-300 break-all">${icon('mail', 'w-4 h-4')} reachingoutinitiative2021@gmail.com</a>
              </div>
            </div>
            <form id="roi-solution-inquire" class="lg:col-span-7 bg-slate-900 border border-slate-800 rounded-3xl p-8 space-y-4">
              <div>
                <label for="roi-sol-service" class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-2">Service of interest</label>
                <select id="roi-sol-service" name="digital_solution_id" class="w-full px-4 py-3 rounded-xl bg-slate-950 border border-slate-700 text-white text-base focus:outline-none focus:border-sky-500">
                  <option value="">General inquiry</option>
                </select>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label for="roi-sol-name" class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-2">Your name *</label>
                  <input required id="roi-sol-name" name="name" autocomplete="name" placeholder="e.g. Amina Omar" class="w-full px-4 py-3 rounded-xl bg-slate-950 border border-slate-700 text-white text-base focus:outline-none focus:border-sky-500">
                </div>
                <div>
                  <label for="roi-sol-email" class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-2">Email *</label>
                  <input required type="email" id="roi-sol-email" name="email" autocomplete="email" placeholder="you@organization.org" spellcheck="false" class="w-full px-4 py-3 rounded-xl bg-slate-950 border border-slate-700 text-white text-base focus:outline-none focus:border-sky-500">
                </div>
              </div>
              <div>
                <label for="roi-sol-org" class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-2">Organization</label>
                <input id="roi-sol-org" name="organization" autocomplete="organization" placeholder="School, NGO, business name…" class="w-full px-4 py-3 rounded-xl bg-slate-950 border border-slate-700 text-white text-base focus:outline-none focus:border-sky-500">
              </div>
              <div>
                <label for="roi-sol-msg" class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-2">The problem you want solved *</label>
                <textarea required rows="5" id="roi-sol-msg" name="message" placeholder="Describe what your team struggles with today — registers, payments, records, reporting…" class="w-full px-4 py-3 rounded-xl bg-slate-950 border border-slate-700 text-white text-base focus:outline-none focus:border-sky-500 resize-none"></textarea>
              </div>
              <button type="submit" class="w-full py-4 rounded-2xl bg-sky-500 hover:bg-sky-400 text-white font-extrabold text-sm uppercase tracking-wider flex items-center justify-center gap-2 transition-colors transform hover:scale-[1.01]">
                ${icon('send', 'w-4 h-4')}
                <span>Send Inquiry</span>
              </button>
              <p class="text-xs text-slate-500 text-center pt-1">Replies come from the ROI Digital Solutions desk within two business days.</p>
            </form>
          </div>
        </div>
      </section>
    </div>`;

  // ---- In-page anchor navigation ---------------------------------------
  // Bare-hash anchors (#services) collide with the hash router (which would
  // treat "services" as an unknown route and blank the page), so intercept
  // them here and smooth-scroll instead.
  root.querySelectorAll('a[href^="#"]:not([href^="#/"])').forEach((a) => {
    a.addEventListener('click', (e) => {
      const target = document.getElementById(a.getAttribute('href').slice(1));
      if (!target) return;
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  // ---- Data hydration -------------------------------------------------

  getSolutions().then((items) => {
    const list = items || [];
    const grid = root.querySelector('#roi-services-grid');
    if (!grid) return;
    grid.innerHTML = list.map((s) => `
      <article class="group bg-slate-950 border border-slate-800 rounded-3xl p-6 space-y-3 hover:border-sky-500/40 transition-colors flex flex-col">
        <div class="w-12 h-12 rounded-xl bg-sky-500/10 border border-sky-500/30 flex items-center justify-center text-sky-400 group-hover:bg-sky-500/20 transition-colors">
          ${icon(s.icon || 'globe', 'w-6 h-6')}
        </div>
        <span class="text-[10px] uppercase text-amber-400 font-bold tracking-wider">${escapeHtml(s.service_category || s.category)}</span>
        <h3 class="text-lg font-black text-white leading-snug">${escapeHtml(s.title)}</h3>
        <p class="text-sm text-slate-300 leading-relaxed flex-1">${escapeHtml(s.summary)}</p>
        ${Array.isArray(s.features) && s.features.length ? `
          <ul class="space-y-1.5 pt-2 border-t border-slate-800/70">
            ${s.features.slice(0, 3).map((f) => `<li class="text-xs text-slate-400 flex items-center gap-2"><span class="text-emerald-400 shrink-0">${icon('check', 'w-3 h-3')}</span>${escapeHtml(f)}</li>`).join('')}
          </ul>` : ''}
        ${s.price_label ? `<p class="text-xs text-sky-400 font-bold pt-1">${escapeHtml(s.price_label)}</p>` : ''}
      </article>`).join('');

    const sel = root.querySelector('#roi-sol-service');
    if (sel) {
      sel.innerHTML = '<option value="">General inquiry</option>' + list.map((s) =>
        `<option value="${escapeHtml(String(s.id))}">${escapeHtml(s.title)}</option>`).join('');
    }
  });

  getIndustries().then((items) => {
    const grid = root.querySelector('#roi-industries-grid');
    if (!grid) return;
    const list = items || [];
    grid.innerHTML = list.map((i) => `
      <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 space-y-3 hover:border-amber-500/40 transition-colors">
        <div class="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-amber-400">${icon(i.icon || 'briefcase', 'w-5 h-5')}</div>
        <h3 class="font-black text-white text-sm leading-snug">${escapeHtml(i.name)}</h3>
        <p class="text-xs text-slate-400 leading-relaxed">${escapeHtml(i.summary || '')}</p>
      </div>`).join('') || '<p class="text-slate-400">Industries will appear here shortly.</p>';
  });

  getTechCapabilities().then((items) => {
    const wrap = root.querySelector('#roi-tech-groups');
    if (!wrap) return;
    const list = items || [];
    const groups = {};
    for (const t of list) {
      const g = t.group || 'Other';
      (groups[g] = groups[g] || []).push(t);
    }
    wrap.innerHTML = Object.entries(groups).map(([group, techs]) => `
      <div class="bg-slate-950 border border-slate-800 rounded-3xl p-6 sm:p-8">
        <h3 class="text-xs font-black uppercase tracking-widest text-emerald-400 mb-5">${escapeHtml(group)}</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-4">
          ${techs.map((t) => `
            <div class="flex items-start gap-3">
              <div class="w-9 h-9 rounded-lg bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400 shrink-0">${icon(t.icon || 'code', 'w-4 h-4')}</div>
              <div class="min-w-0">
                <span class="block text-sm font-bold text-white">${escapeHtml(t.name)}</span>
                ${t.description ? `<span class="block text-xs text-slate-400 mt-0.5 leading-relaxed">${escapeHtml(t.description)}</span>` : ''}
              </div>
            </div>`).join('')}
        </div>
      </div>`).join('') || '<p class="text-slate-400">Capabilities will appear here shortly.</p>';
  });

  getPortfolio().then((items) => {
    const grid = root.querySelector('#roi-portfolio-grid');
    if (!grid) return;
    const list = (items || []).filter((p) => p.is_featured !== false).slice(0, 9);
    grid.innerHTML = list.map((p) => `
      <article class="bg-slate-900 border border-slate-800 rounded-3xl overflow-hidden flex flex-col hover:border-amber-500/40 transition-colors">
        <div class="aspect-[16/9] bg-slate-950">
          ${imageWithFallback(p.image_url || '', p.title, 'w-full h-full object-cover opacity-90', p.client || 'ROI')}
        </div>
        <div class="p-6 space-y-2 flex-1 flex flex-col">
          <span class="text-[10px] uppercase text-sky-400 font-bold tracking-wider">${escapeHtml([p.client, p.location].filter(Boolean).join(' · '))}</span>
          <h3 class="text-lg font-black text-white leading-snug">${escapeHtml(p.title)}</h3>
          <p class="text-sm text-slate-300 leading-relaxed flex-1">${escapeHtml(p.summary || '')}</p>
          ${p.outcome ? `<p class="text-xs text-emerald-300 pt-2 border-t border-slate-800">${icon('check-circle-2', 'w-3.5 h-3.5 inline mr-1')}${escapeHtml(p.outcome)}</p>` : ''}
        </div>
      </article>`).join('') || '<p class="text-slate-400">Case studies will appear here shortly.</p>';
  });

  getFaqs().then((items) => {
    const wrap = root.querySelector('#roi-faq-list');
    if (!wrap) return;
    const list = items || [];
    wrap.innerHTML = list.map((f) => `
      <details class="group bg-slate-950 border border-slate-800 rounded-2xl overflow-hidden">
        <summary class="cursor-pointer select-none list-none px-6 py-4 flex items-center justify-between gap-4 hover:bg-slate-900 transition-colors">
          <span class="text-sm font-bold text-white">${escapeHtml(f.question)}</span>
          <span class="text-sky-400 shrink-0 group-open:rotate-180 transition-transform">${icon('plus', 'w-4 h-4')}</span>
        </summary>
        <div class="px-6 pb-5 pt-0">
          <p class="text-sm text-slate-300 leading-relaxed border-l-2 border-slate-700 pl-4">${escapeHtml(f.answer)}</p>
        </div>
      </details>`).join('') || '<p class="text-slate-400">FAQs will appear here shortly.</p>';
  });

  // ---- Inquiry form ----------------------------------------------------
  const form = root.querySelector('#roi-solution-inquire');
  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const payload = Object.fromEntries(fd.entries());
    if (!payload.digital_solution_id) delete payload.digital_solution_id;
    else payload.digital_solution_id = Number(payload.digital_solution_id);

    const submitBtn = e.target.querySelector('button[type=submit]');
    submitBtn.disabled = true;
    submitBtn.style.opacity = '0.6';
    try {
      await inquireSolution(payload);
      showToast('Inquiry received. ROI Digital Solutions will follow up.');
      e.target.reset();
    } catch (err) {
      showToast(err.response?.data?.detail || 'Could not send inquiry. Please try again or email us directly.');
    } finally {
      submitBtn.disabled = false;
      submitBtn.style.opacity = '';
    }
  });
}