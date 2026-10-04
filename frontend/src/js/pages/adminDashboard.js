// AdminDashboard page — vanilla port of pages/AdminDashboard.jsx.
// Auth guard (raw localStorage check preserved), 7 tab panels, optimistic CRUD,
// native confirm() deletes, client-side CSV export, 1200ms artificial media sync.
import { getAuthState, logout } from '../store.js';
import { navigate } from '../router.js';
import { request, uploadImage } from '../api.js';
import { icon } from '../icons.js';
import { escapeHtml, fmtDate, showToast } from '../ui.js';
import { ROI_LOGO_DATA_URI } from '../assets/logoDataUri.js';
import { isOpenInquiry, nextInquiryStatuses } from '../payments/inquiryWorkflow.js';
import { normalizeTicketCode } from '../payments/ticketCheckIn.js';
import { eatToUtc, utcToEat } from '../tz.js';

export function renderAdminDashboard(root) {
  const auth = getAuthState();

  // Front-end guard: the in-memory session is the source of truth (the JWT is
  // no longer persisted to localStorage, so there is nothing to read there).
  if (!auth.admin) {
    navigate('/admin');
    return;
  }

  const state = {
    activeTab: 'overview',
    stats: {
      total_volunteers: 65,
      total_donations_kes: 254500,
      total_events: 14,
      total_articles: 18,
      recent_inquiries_count: 5
    },
    blogs: [],
    eventsList: [],
    volunteersList: [],
    inquiriesList: [],
    leadersList: [],
    solutionsList: [],
    solutionInquiries: [],
    inquiryStatusFilter: '',
    portfolioList: [],
    faqsList: [],
    industriesList: [],
    techList: [],
    editingFaq: null,
    editingIndustry: null,
    editingTech: null,
    ticketTypes: [],
    ticketOrders: [],
    ticketStats: {
      ticket_types: 0,
      orders_total: 0,
      orders_completed: 0,
      orders_pending: 0,
      tickets_issued: 0,
      tickets_checked_in: 0,
      check_in_rate: 0,
      revenue_kes: 0
    },
    ticketSearch: '',
    ticketStatusFilter: '',
    checkInCode: '',
    ticketForm: { event_id: '', name: '', description: '', price: '0', quantity: '', max_per_order: '10', sales_start: '', sales_end: '', is_active: true },
    editingTicketType: null,
    ticketModal: false,
    blogModal: false,
    editingBlog: null,
    blogForm: { title: '', summary: '', content: '', category: 'Social Impact', author: 'DEMO Desk', image_url: '', is_published: true },
    eventModal: false,
    editingEvent: null,
    eventForm: { title: '', date: '', time: '09:00 AM EAT', location: 'Harbor City, Kenya', description: '', category: 'Conference', image_url: '', is_active: true },
    leaderModal: false,
    editingLeader: null,
    leaderForm: { name: '', role: '', bio: '' },
    volSkillFilter: 'All',
    volSearch: '',
    featuredOverrideId: '',
    syncingMedia: false,
    siteLoaded: false,
    siteLoading: false,
    siteForm: {
      hero_eyebrow: 'Flagship Conference 2026',
      hero_title: 'Youth Leadership Summit',
      hero_description: '',
      hero_image_url: '',
      metric_youth_mentored: '120',
      metric_events_hosted: '2',
      metric_individuals_supported: '95',
      metric_active_volunteers: '45'
    }
  };

  root.innerHTML = `
    <div class="min-h-screen bg-slate-950 text-slate-100 flex flex-col md:flex-row">

      <aside class="w-full md:w-64 bg-slate-900 border-b md:border-b-0 md:border-r border-slate-800 p-4 md:p-6 flex flex-col justify-between shrink-0 min-w-0">
        <div class="space-y-4 md:space-y-8 min-w-0">
          <div class="flex items-center space-x-3 pb-2">
            <img src="${ROI_LOGO_DATA_URI}" alt="DEMO Official Logo" class="h-8 w-auto object-contain">
            <div><span class="text-[10px] text-emerald-400 font-mono block">1/1 Slot Active</span></div>
          </div>
          <nav id="roi-tabs-nav" class="flex md:block gap-2 md:space-y-1.5 overflow-x-auto mobile-scroll pb-2 md:pb-0" aria-label="Admin sections"></nav>
        </div>

        <div class="pt-3 md:pt-6 mt-3 md:mt-0 border-t border-slate-800 flex md:block items-center justify-between gap-3 md:space-y-3">
          <div class="hidden md:block text-[11px] text-slate-500 truncate">User: ${escapeHtml(auth.admin?.email || 'Administrator')}</div>
          <button type="button" id="roi-logout"
            class="w-full sm:w-auto md:w-full px-4 py-2.5 rounded-xl bg-red-600/20 hover:bg-red-600 text-red-400 hover:text-white font-bold text-xs flex items-center justify-center gap-2 transition-colors">
            ${icon('log-out', 'w-3.5 h-3.5')}
            <span>Close Session</span>
          </button>
        </div>
      </aside>

      <main class="flex-1 min-w-0 p-4 sm:p-6 lg:p-10 overflow-y-auto max-w-7xl">
        <div id="roi-panel-root"></div>
      </main>

      <div id="roi-modal-root"></div>
    </div>`;

  const TABS = [
    { id: 'overview', label: 'Dashboard Home', iconName: 'shield-check' },
    { id: 'blog', label: 'Blog Manager', iconName: 'book-open' },
    { id: 'events', label: 'Events Manager Grid', iconName: 'calendar' },
    { id: 'media', label: 'Media Controller', iconName: 'tv' },
    { id: 'leaders', label: 'Stewards Manager', iconName: 'award' },
    { id: 'volunteers', label: 'Volunteer Registry', iconName: 'users' },
    { id: 'inquiries', label: 'Inquiries Capture Desk', iconName: 'message-square' },
    { id: 'tickets', label: 'Ticketing Desk', iconName: 'calendar' },
    { id: 'solutions', label: 'Digital Solutions', iconName: 'globe' },
    { id: 'site', label: 'Site Content', iconName: 'layout' },
    { id: 'branch-content', label: 'Branch Content', iconName: 'layout' }
  ];

  const nav = root.querySelector('#roi-tabs-nav');
  const panelRoot = root.querySelector('#roi-panel-root');
  const modalRoot = root.querySelector('#roi-modal-root');

  // ------------------------------------------------------------ Data loading

  async function fetchDashboardData() {
    const get = (path, fallback) => request('GET', path).then((r) => r.data).catch(() => fallback);
    try {
      const [st, bl, ev, vl, iq, ld, tt, to, ts, sl, si, pf, fq, ind, tech] = await Promise.all([
        get('/admin/stats', state.stats),
        get('/public/blog', []),
        get('/public/events', []),
        get('/admin/volunteers', []),
        get('/admin/inquiries', []),
        get('/public/leaders', []),
        get('/admin/ticket-types', []),
        get('/admin/ticket-orders', []),
        get('/admin/ticket-stats', state.ticketStats),
        get('/admin/solutions', []),
        get('/admin/solution-inquiries', []),
        get('/admin/portfolio', []),
        get('/admin/solution-faqs', []),
        get('/admin/industries', []),
        get('/admin/tech', [])
      ]);

      if (st) state.stats = st;
      if (bl) state.blogs = bl;
      if (ev) state.eventsList = ev;
      if (vl) state.volunteersList = vl;
      if (iq) state.inquiriesList = iq;
      if (ld) state.leadersList = ld;
      if (tt) state.ticketTypes = tt;
      if (to) state.ticketOrders = to;
      if (ts) state.ticketStats = ts;
      if (sl) state.solutionsList = sl;
      if (si) state.solutionInquiries = si;
      if (pf) state.portfolioList = pf;
      if (fq) state.faqsList = fq;
      if (ind) state.industriesList = ind;
      if (tech) state.techList = tech;
    } catch (err) {
      console.warn('Using offline simulated admin dashboard data');
    }

    paint();
  }

  function paintNav() {
    nav.innerHTML = TABS.map(
      (t) => `
      <button type="button" data-tab="${t.id}"
        class="shrink-0 md:w-full px-4 py-3 rounded-xl text-xs font-bold flex items-center gap-2 md:gap-3 whitespace-nowrap transition-colors transition-shadow ${
          state.activeTab === t.id ? 'bg-sky-600 text-white shadow-lg shadow-sky-600/25' : 'text-slate-400 hover:text-white hover:bg-slate-800'
        }">
        ${icon(t.iconName, 'w-4 h-4 shrink-0')}
        <span>${t.label}</span>
      </button>`
    ).join('');
    nav.querySelectorAll('[data-tab]').forEach((b) =>
      b.addEventListener('click', () => {
        state.activeTab = b.dataset.tab;
        paintNav();
        paint();
      })
    );
  }

  // ------------------------------------------------------------- Panels

  function panelOverview() {
    return `
      <div class="space-y-8 animate-fadeIn">
        <h2 class="text-2xl font-black text-white">Singular Administrative Overview</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
          <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl">
            <span class="text-xs text-slate-400 uppercase font-bold">Total Volunteer Count</span>
            <span class="text-3xl font-black text-sky-400 block mt-2">${escapeHtml(String(state.stats.total_volunteers))}</span>
            <span class="text-[10px] text-emerald-400 mt-1 block">+12 this month</span>
          </div>
          <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl">
            <span class="text-xs text-slate-400 uppercase font-bold">Donations Volume (KES)</span>
            <span class="text-3xl font-black text-amber-400 block mt-2">KSh ${Number(state.stats.total_donations_kes).toLocaleString()}</span>
            <span class="text-[10px] text-slate-500 mt-1 block">Paystack / M-Pesa</span>
          </div>
          <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl">
            <span class="text-xs text-slate-400 uppercase font-bold">Active Event Itineraries</span>
            <span class="text-3xl font-black text-purple-400 block mt-2">${escapeHtml(String(state.stats.total_events))}</span>
            <span class="text-[10px] text-slate-500 mt-1 block">Including Youth Leadership Summit</span>
          </div>
          <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl">
            <span class="text-xs text-slate-400 uppercase font-bold">Recent Form Inquiries</span>
            <span class="text-3xl font-black text-emerald-400 block mt-2">${escapeHtml(String(state.stats.recent_inquiries_count))}</span>
            <span class="text-[10px] text-sky-400 mt-1 block">Routed to DEMO Desk</span>
          </div>
        </div>

        <div class="bg-slate-900/60 border border-slate-800 p-6 rounded-2xl space-y-4 text-xs leading-relaxed text-slate-300">
          <h3 class="font-bold text-white uppercase text-sm border-l-2 border-sky-500 pl-2">System Architecture Compliance</h3>
          <p>The administrative structure conforms strictly to Section 5 of the Product Requirements Document: exactly one (1) global slot is provisioned. All public submission pipelines (Volunteer Engine, Donation Checkout, Inquiries Capture) feed securely into this immutable control center.</p>
        </div>
      </div>`;
  }

  function panelBlog() {
    return `
      <div class="space-y-6 animate-fadeIn">
        <div class="flex items-center justify-between">
          <h2 class="text-2xl font-black text-white">Blog &amp; Content Manager</h2>
          <button type="button" data-action="new-blog"
            class="px-4 py-2 rounded-xl bg-amber-400 hover:bg-amber-300 text-slate-950 font-black text-xs flex items-center gap-1.5 shadow">
            ${icon('plus', 'w-4 h-4')}
            <span>Write New Article</span>
          </button>
        </div>

        <div class="space-y-4">
          ${state.blogs.map((b) => `
            <div class="bg-slate-900 border border-slate-800 p-5 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div class="space-y-1 max-w-xl">
                <div class="flex items-center gap-2">
                  <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${b.is_published ? 'bg-emerald-500/20 text-emerald-300' : 'bg-slate-700 text-slate-400'}">
                    ${b.is_published ? 'Published' : 'Draft Hidden'}
                  </span>
                  <span class="text-xs text-amber-400 font-bold">${escapeHtml(b.category)}</span>
                </div>
                <h4 class="font-bold text-white text-base">${escapeHtml(b.title)}</h4>
                <p class="text-xs text-slate-400 line-clamp-1">${escapeHtml(b.summary)}</p>
              </div>
              <div class="flex items-center gap-2 self-end sm:self-auto">
                <button type="button" data-action="edit-blog" data-id="${b.id}" title="Edit Article & Styles"
                  class="p-2 rounded-lg bg-slate-800 hover:bg-sky-600 text-slate-300 hover:text-white transition-colors">${icon('edit-3', 'w-4 h-4')}</button>
                <button type="button" data-action="delete-blog" data-id="${b.id}" title="Prune Entry"
                  class="p-2 rounded-lg bg-slate-800 hover:bg-red-600 text-slate-300 hover:text-white transition-colors">${icon('trash-2', 'w-4 h-4')}</button>
              </div>
            </div>`).join('')}
        </div>
      </div>`;
  }

  function panelEvents() {
    return `
      <div class="space-y-6 animate-fadeIn">
        <div class="flex items-center justify-between">
          <div>
            <h2 class="text-2xl font-black text-white">Events Operational Grid</h2>
            <p class="text-xs text-slate-400">Instantly append, change, or remove upcoming activities.</p>
          </div>
          <button type="button" data-action="new-event"
            class="px-4 py-2 rounded-xl bg-sky-500 hover:bg-sky-400 text-white font-black text-xs flex items-center gap-1.5 shadow">
            ${icon('plus', 'w-4 h-4')}
            <span>Append Activity</span>
          </button>
        </div>

        <div class="overflow-x-auto bg-slate-900 border border-slate-800 rounded-2xl">
          <table class="w-full text-left border-collapse text-xs">
            <thead>
              <tr class="bg-slate-950 border-b border-slate-800 text-slate-400 uppercase font-mono">
                <th class="p-4">Title / Event</th>
                <th class="p-4">Schedule Date</th>
                <th class="p-4">Location</th>
                <th class="p-4">Category</th>
                <th class="p-4">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60">
              ${state.eventsList.map((ev) => `
                <tr class="hover:bg-slate-800/50">
                  <td class="p-4 font-bold text-white max-w-xs truncate">${escapeHtml(ev.title)}</td>
                  <td class="p-4 font-mono text-amber-400">${escapeHtml(ev.date)}</td>
                  <td class="p-4 text-slate-300">${escapeHtml(ev.location)}</td>
                  <td class="p-4"><span class="px-2 py-1 rounded bg-sky-500/10 text-sky-400 font-semibold">${escapeHtml(ev.category)}</span></td>
                  <td class="p-4 flex gap-2">
                    <button type="button" data-action="edit-event" data-id="${ev.id}" class="text-sky-400 hover:underline">Change</button>
                    <button type="button" data-action="delete-event" data-id="${ev.id}" class="text-red-400 hover:underline">Remove</button>
                  </td>
                </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </div>`;
  }

  function panelMedia() {
    return `
      <div class="space-y-8 max-w-2xl animate-fadeIn">
        <div>
          <h2 class="text-2xl font-black text-white">Media Controller &amp; API Override</h2>
          <p class="text-xs text-slate-400 mt-1">Manual synchronization override trigger for YouTube Data API v3 cache.</p>
        </div>

        <form id="roi-media-sync-form" class="bg-slate-900 border border-slate-800 p-8 rounded-3xl space-y-6">
          <div>
            <label class="text-xs font-bold uppercase tracking-wider text-amber-400 block mb-2">Override Global "Featured Theater" Video ID (Optional)</label>
            <input type="text" id="roi-media-override" placeholder="e.g. dQw4w9WgXcQ or YouTube Video ID" value="${escapeHtml(state.featuredOverrideId)}"
              class="w-full px-4 py-3 rounded-xl bg-slate-950 border border-slate-700 text-white font-mono text-xs focus:outline-none focus:border-amber-400">
            <span class="text-[10px] text-slate-500 mt-1 block">Leaving blank will simply purge the cache and pull the latest channel snippets.</span>
          </div>

          <button type="submit" id="roi-media-sync-btn" ${state.syncingMedia ? 'disabled' : ''}
            class="w-full py-4 rounded-xl bg-red-600 hover:bg-red-500 text-white font-black text-xs uppercase tracking-wider flex items-center justify-center gap-2 shadow-lg shadow-red-600/25">
            ${icon('refresh-cw', `w-4 h-4 ${state.syncingMedia ? 'animate-spin' : ''}`)}
            <span>${state.syncingMedia ? 'Purging Local Cache & Querying API...' : 'Trigger Manual Synchronization Override'}</span>
          </button>
        </form>

        <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 text-xs text-slate-400 space-y-2">
          <strong class="text-white block uppercase">Quota Management Rule</strong>
          <p>To strictly adhere to Google Cloud API quota bounds, public page views do not hit live API endpoints directly. Metadata is cached directly inside PostgreSQL \`media_items\`. Use this controller panel whenever DEMO broadcasts a new Youth Leadership Summit documentary or empowerment talk.</p>
        </div>
      </div>`;
  }

  function panelLeaders() {
    return `
      <div class="space-y-6 animate-fadeIn">
        <div class="flex items-center justify-between">
          <div>
            <h2 class="text-2xl font-black text-white">Stewards &amp; Leadership</h2>
            <p class="text-xs text-slate-400">Manage the 'Meet the Dedicated Stewards' section.</p>
          </div>
          <button type="button" data-action="new-leader"
            class="px-4 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-white font-black text-xs flex items-center gap-1.5 shadow">
            ${icon('plus', 'w-4 h-4')}
            <span>Add Steward</span>
          </button>
        </div>

        <div class="overflow-x-auto bg-slate-900 border border-slate-800 rounded-2xl">
          <table class="w-full text-left border-collapse text-xs">
            <thead>
              <tr class="bg-slate-950 border-b border-slate-800 text-slate-400 uppercase font-mono">
                <th class="p-4">Name</th>
                <th class="p-4">Role</th>
                <th class="p-4">Bio</th>
                <th class="p-4">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60">
              ${state.leadersList.map((ld) => `
                <tr class="hover:bg-slate-800/50">
                  <td class="p-4 font-bold text-white">${escapeHtml(ld.name)}</td>
                  <td class="p-4 text-sky-400 font-semibold uppercase">${escapeHtml(ld.role)}</td>
                  <td class="p-4 text-slate-300 max-w-xs truncate">${escapeHtml(ld.bio)}</td>
                  <td class="p-4 flex gap-2">
                    <button type="button" data-action="edit-leader" data-id="${ld.id}" class="text-sky-400 hover:underline">Change</button>
                    <button type="button" data-action="delete-leader" data-id="${ld.id}" class="text-red-400 hover:underline">Remove</button>
                  </td>
                </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </div>`;
  }

  function filteredVols() {
    return state.volunteersList.filter((v) => {
      const mSkill = state.volSkillFilter === 'All' || String(v.primary_skill).toLowerCase().includes(state.volSkillFilter.toLowerCase());
      const mSearch =
        !state.volSearch ||
        String(v.full_name).toLowerCase().includes(state.volSearch.toLowerCase()) ||
        String(v.email).toLowerCase().includes(state.volSearch.toLowerCase());
      return mSkill && mSearch;
    });
  }

  function panelVolunteers() {
    const rows = filteredVols(); // L-6: no demo-PII fallback — real data or empty state
    return `
      <div class="space-y-6 animate-fadeIn">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h2 class="text-2xl font-black text-white">Centralized Volunteer Registry</h2>
            <p class="text-xs text-slate-400">Review skills submitted through the public Registration Engine.</p>
          </div>
          <button type="button" data-action="export-csv"
            class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-extrabold text-xs flex items-center gap-2 shadow-lg shadow-emerald-500/20 transition-colors transform hover:scale-105">
            ${icon('download', 'w-4 h-4')}
            <span>Export Complete Registry (CSV)</span>
          </button>
        </div>

        <div class="flex flex-col sm:flex-row gap-3">
          <div class="relative flex-1">
            <span class="absolute left-3.5 top-3 text-slate-500 pointer-events-none inline-block align-middle">${icon('search', 'w-4 h-4')}</span>
            <input type="text" id="roi-vol-search" placeholder="Search volunteer by name or email..." value="${escapeHtml(state.volSearch)}"
              class="w-full pl-10 pr-4 py-2.5 rounded-xl bg-slate-900 border border-slate-800 text-white text-xs focus:outline-none focus:border-sky-500">
          </div>
          <select id="roi-vol-skill-filter" class="px-4 py-2.5 rounded-xl bg-slate-900 border border-slate-800 text-white text-xs focus:outline-none">
            <option value="All" ${state.volSkillFilter === 'All' ? 'selected' : ''}>All Skills</option>
            <option value="Mentorship" ${state.volSkillFilter === 'Mentorship' ? 'selected' : ''}>Mentorship</option>
            <option value="Event Coordination" ${state.volSkillFilter === 'Event Coordination' ? 'selected' : ''}>Event Coordination</option>
            <option value="Graphic Design" ${state.volSkillFilter === 'Graphic Design' ? 'selected' : ''}>Graphic Design</option>
            <option value="Photography" ${state.volSkillFilter === 'Photography' ? 'selected' : ''}>Photography</option>
            <option value="Teaching" ${state.volSkillFilter === 'Teaching' ? 'selected' : ''}>Teaching</option>
          </select>
        </div>

        <div class="overflow-x-auto bg-slate-900 border border-slate-800 rounded-2xl">
          <table class="w-full text-left border-collapse text-xs">
            <thead>
              <tr class="bg-slate-950 border-b border-slate-800 text-slate-400 uppercase font-mono">
                <th class="p-4">ID</th>
                <th class="p-4">Full Name</th>
                <th class="p-4">Contact Details</th>
                <th class="p-4">Primary Skill</th>
                <th class="p-4">Availability Window</th>
                <th class="p-4">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/60">
              ${rows.length === 0 ? '<tr><td colspan="6" class="p-8 text-center text-slate-500 text-xs">No volunteer registrations yet — new submissions appear here in real time.</td></tr>' : ''}
              ${rows.map((vol) => `
                <tr class="hover:bg-slate-800/50">
                  <td class="p-4 font-mono text-slate-400">#${vol.id}</td>
                  <td class="p-4 font-bold text-white">${escapeHtml(vol.full_name)}</td>
                  <td class="p-4">
                    <span class="block text-sky-400">${escapeHtml(vol.email)}</span>
                    <span class="text-[10px] text-slate-500 font-mono">${escapeHtml(vol.phone)}</span>
                  </td>
                  <td class="p-4"><span class="px-2.5 py-1 rounded-full bg-amber-500/10 text-amber-400 font-bold">${escapeHtml(vol.primary_skill)}</span></td>
                  <td class="p-4 text-slate-300 max-w-xs truncate">${escapeHtml(vol.availability)}</td>
                  <td class="p-4">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold ${vol.status === 'Approved' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-amber-500/20 text-amber-300'}">${escapeHtml(vol.status)}</span>
                  </td>
                </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </div>`;
  }

  function eventTitle(eventId) {
    return state.eventsList.find((e) => String(e.id) === String(eventId))?.title || `Event #${eventId}`;
  }

  function filteredTicketOrders() {
    return state.ticketOrders.filter((o) => {
      const q = state.ticketSearch.toLowerCase();
      const matchesSearch = !q
        || String(o.reference).toLowerCase().includes(q)
        || String(o.buyer_name).toLowerCase().includes(q)
        || String(o.buyer_email).toLowerCase().includes(q);
      const matchesStatus = !state.ticketStatusFilter || o.status === state.ticketStatusFilter;
      return matchesSearch && matchesStatus;
    });
  }

  function panelTickets() {
    const s = state.ticketStats;
    const orders = filteredTicketOrders();
    return `
      <div class="space-y-8 animate-fadeIn">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div>
            <h2 class="text-2xl font-black text-white">Ticketing Desk</h2>
            <p class="text-xs text-slate-400">Inventory, door check-in, order ledger, and delivery.</p>
          </div>
          <div class="flex gap-2">
            <a href="/checking" class="px-4 py-2 rounded-xl bg-sky-600 text-white font-black text-xs">QR gate</a>
            <button type="button" data-action="export-ticket-csv" class="px-4 py-2 rounded-xl bg-emerald-500 text-slate-950 font-black text-xs">${icon('download', 'w-4 h-4 inline')} Export orders</button>
            <button type="button" data-action="new-ticket-type" class="px-4 py-2 rounded-xl bg-amber-400 text-slate-950 font-black text-xs">${icon('plus', 'w-4 h-4 inline')} Add ticket type</button>
          </div>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl"><span class="text-[10px] uppercase text-slate-400 font-bold">Revenue (KES)</span><span class="block text-2xl font-black text-amber-400 mt-1">${Number(s.revenue_kes || 0).toLocaleString()}</span></div>
          <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl"><span class="text-[10px] uppercase text-slate-400 font-bold">Tickets issued</span><span class="block text-2xl font-black text-sky-400 mt-1">${s.tickets_issued || 0}</span></div>
          <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl"><span class="text-[10px] uppercase text-slate-400 font-bold">Checked in</span><span class="block text-2xl font-black text-emerald-400 mt-1">${s.tickets_checked_in || 0} <span class="text-xs text-slate-500">(${Math.round((s.check_in_rate || 0) * 100)}%)</span></span></div>
          <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl"><span class="text-[10px] uppercase text-slate-400 font-bold">Pending / checked-in</span><span class="block text-2xl font-black text-purple-400 mt-1">${s.orders_pending || 0} / ${s.tickets_checked_in || 0}</span></div>
        </div>

        <form id="roi-checkin-form" class="bg-slate-900 border border-slate-800 rounded-2xl p-4 flex flex-col sm:flex-row gap-3">
          <input id="roi-checkin-code" value="${escapeHtml(state.checkInCode)}" placeholder="Scan or type ticket code (DEMO-XXXX-XXXX)" class="flex-1 px-4 py-2.5 rounded-xl bg-slate-950 border border-slate-700 text-white font-mono text-xs">
          <button type="submit" class="px-5 py-2.5 rounded-xl bg-sky-600 text-white font-black text-xs">Check in</button>
        </form>
        <div id="roi-checkin-result"></div>

        <div class="overflow-x-auto bg-slate-900 border border-slate-800 rounded-2xl">
          <table class="w-full text-left text-xs">
            <thead><tr class="bg-slate-950 text-slate-400 uppercase font-mono">
              <th class="p-4">Type</th><th class="p-4">Event</th><th class="p-4">Price</th><th class="p-4">Sold / left</th><th class="p-4">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-800/60">
              ${state.ticketTypes.map((t) => `
                <tr>
                  <td class="p-4 font-bold text-white">${escapeHtml(t.name)}</td>
                  <td class="p-4 text-slate-300">${escapeHtml(eventTitle(t.event_id))}</td>
                  <td class="p-4 text-amber-400">${escapeHtml(t.currency)} ${Number(t.price).toLocaleString()}</td>
                  <td class="p-4 text-slate-300">${t.sold_count || 0} / ${t.unlimited ? '∞' : t.remaining}</td>
                  <td class="p-4 flex gap-2">
                    <button type="button" data-action="edit-ticket-type" data-id="${t.id}" class="text-sky-400">Change</button>
                    <button type="button" data-action="delete-ticket-type" data-id="${t.id}" class="text-red-400">Remove</button>
                  </td>
                </tr>`).join('') || '<tr><td class="p-4 text-slate-500" colspan="5">No ticket types yet.</td></tr>'}
            </tbody>
          </table>
        </div>

        <div class="flex flex-col sm:flex-row gap-3">
          <input id="roi-ticket-search" value="${escapeHtml(state.ticketSearch)}" placeholder="Search reference, buyer, email…" class="flex-1 px-4 py-2.5 rounded-xl bg-slate-900 border border-slate-800 text-white text-xs">
          <select id="roi-ticket-status" class="px-4 py-2.5 rounded-xl bg-slate-900 border border-slate-800 text-white text-xs">
            <option value="" ${!state.ticketStatusFilter ? 'selected' : ''}>All statuses</option>
            <option value="Completed" ${state.ticketStatusFilter === 'Completed' ? 'selected' : ''}>Completed</option>
            <option value="Pending Paystack Checkout" ${state.ticketStatusFilter === 'Pending Paystack Checkout' ? 'selected' : ''}>Pending Paystack</option>
            <option value="STK Prompt Dispatched" ${state.ticketStatusFilter === 'STK Prompt Dispatched' ? 'selected' : ''}>STK pending</option>
          </select>
        </div>

        <div class="space-y-3">
          ${orders.map((o) => `
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-xs flex flex-col sm:flex-row justify-between gap-4">
              <div>
                <div class="font-mono text-amber-400">${escapeHtml(o.reference)}</div>
                <div class="text-white font-bold">${escapeHtml(o.buyer_name)}</div>
                <div class="text-slate-400">${escapeHtml(o.buyer_email)} · ${escapeHtml(o.status)} · ${escapeHtml(eventTitle(o.event_id))}</div>
                <div class="text-[10px] ${o.status === 'Completed' ? 'text-emerald-400' : 'text-slate-500'}">${o.status === 'Completed' ? 'Available in portal' : 'Awaiting payment'}</div>
              </div>
                <div class="text-right space-y-2">
                  <div class="text-slate-300">${escapeHtml(o.currency)} ${Number(o.amount).toLocaleString()}</div>
                  <div class="text-[10px] text-slate-500">${(o.tickets || []).length} ticket(s)</div>
                </div>
            </div>`).join('') || '<p class="text-slate-500 text-xs">No orders match.</p>'}
        </div>
      </div>`;
  }

  function panelSolutions() {
    const newCount = state.solutionInquiries.filter((i) => i.status === 'New').length;
    return `
      <div class="space-y-6 animate-fadeIn">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="text-2xl font-black text-white">DEMO Digital Solutions</h2>
            <p class="text-xs text-slate-400">Publish offerings, reorder the catalog, and close briefing requests.</p>
          </div>
          <button type="button" data-action="new-solution" class="px-4 py-2 rounded-xl bg-amber-400 text-slate-950 font-black text-xs">${icon('plus', 'w-4 h-4 inline')} Add solution</button>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl"><span class="text-[10px] uppercase text-slate-400 font-bold">Catalog items</span><span class="block text-2xl font-black text-sky-400 mt-1">${state.solutionsList.length}</span></div>
          <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl"><span class="text-[10px] uppercase text-slate-400 font-bold">New briefings</span><span class="block text-2xl font-black text-amber-400 mt-1">${newCount}</span></div>
        </div>
        <div class="space-y-3">
          ${state.solutionsList.map((s, idx) => `
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-xs flex flex-col sm:flex-row justify-between gap-3">
              <div>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold ${s.is_published ? 'bg-emerald-500/20 text-emerald-300' : 'bg-slate-700 text-slate-400'}">${s.is_published ? 'Live' : 'Draft'}</span>
                <span class="text-amber-400 font-bold ml-2">${escapeHtml(s.category)}</span>
                <div class="text-white font-black text-sm mt-1">${escapeHtml(s.title)}</div>
                <p class="text-slate-400 mt-1">${escapeHtml(s.summary)}</p>
              </div>
              <div class="flex flex-wrap gap-2 items-start">
                <button type="button" data-action="move-solution" data-id="${s.id}" data-dir="-1" ${idx === 0 ? 'disabled' : ''} class="px-2 py-1 rounded bg-slate-800">Up</button>
                <button type="button" data-action="move-solution" data-id="${s.id}" data-dir="1" ${idx === state.solutionsList.length - 1 ? 'disabled' : ''} class="px-2 py-1 rounded bg-slate-800">Down</button>
                <button type="button" data-action="toggle-solution" data-id="${s.id}" class="text-sky-400">${s.is_published ? 'Unpublish' : 'Publish'}</button>
                <button type="button" data-action="edit-solution" data-id="${s.id}" class="text-sky-400">Edit</button>
                <button type="button" data-action="delete-solution" data-id="${s.id}" class="text-red-400">Remove</button>
              </div>
            </div>`).join('') || '<p class="text-slate-500 text-xs">No solutions yet.</p>'}
        </div>
        <div class="flex items-center justify-between">
          <h3 class="text-white font-bold">Portfolio cases</h3>
          <button type="button" data-action="new-portfolio" class="text-amber-400 font-black text-xs">Add case</button>
        </div>
        ${state.portfolioList.map((p) => `
          <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-xs flex justify-between gap-3">
            <div>
              ${p.is_featured ? '<span class="text-amber-400 font-bold">Featured · </span>' : ''}
              <span class="text-white font-black">${escapeHtml(p.title)}</span>
              <div class="text-slate-400">${escapeHtml(p.client || '')} · ${escapeHtml(p.year || '')}</div>
            </div>
            <div class="flex gap-2">
              <button type="button" data-action="edit-portfolio" data-id="${p.id}" class="text-sky-400">Edit</button>
              <button type="button" data-action="delete-portfolio" data-id="${p.id}" class="text-red-400">Remove</button>
            </div>
          </div>`).join('') || '<p class="text-slate-500 text-xs">No case studies yet.</p>'}
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
          <h3 class="text-white font-bold">Briefing pipeline</h3>
          <select id="roi-inquiry-status-filter" class="px-3 py-2 rounded-xl bg-slate-900 border border-slate-800 text-white text-xs">
            <option value="" ${!state.inquiryStatusFilter ? 'selected' : ''}>All</option>
            ${['New', 'In Review', 'Quoted', 'Won', 'Lost', 'Resolved'].map((s) =>
              `<option value="${s}" ${state.inquiryStatusFilter === s ? 'selected' : ''}>${s}</option>`).join('')}
          </select>
        </div>
        ${state.solutionInquiries.filter((i) => !state.inquiryStatusFilter || i.status === state.inquiryStatusFilter).map((i) => `
          <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-xs space-y-2">
            <div class="flex justify-between gap-3">
              <div>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold ${isOpenInquiry(i.status) ? 'bg-sky-500/20 text-sky-400' : 'bg-slate-800 text-slate-500'}">${escapeHtml(i.status)}</span>
                <div class="text-white font-bold mt-1">${escapeHtml(i.name)} · ${escapeHtml(i.email)}</div>
                <div class="text-sky-400">${escapeHtml(i.solution_title || 'General')}</div>
                <p class="text-slate-400 mt-1">${escapeHtml(i.message)}</p>
                ${i.quoted_amount != null ? `<p class="text-amber-400">Quote: KES ${Number(i.quoted_amount).toLocaleString()}</p>` : ''}
                ${i.notes ? `<p class="text-slate-500">Notes: ${escapeHtml(i.notes)}</p>` : ''}
              </div>
            </div>
            <div class="flex flex-wrap gap-2">
              ${nextInquiryStatuses(i.status).map((st) =>
                `<button type="button" data-action="advance-inquiry" data-id="${i.id}" data-status="${st}" class="px-2 py-1 rounded bg-slate-800 text-sky-300">${escapeHtml(st)}</button>`
              ).join('')}
              <button type="button" data-action="note-inquiry" data-id="${i.id}" class="px-2 py-1 rounded bg-slate-800 text-amber-300">Note / quote</button>
            </div>
          </div>`).join('') || '<p class="text-slate-500 text-xs">No inquiries yet.</p>'}
      </div>`;
  }

  function panelBranchContent() {
    const rowActions = (kind, item) => `
      <div class="flex flex-wrap gap-2 items-start shrink-0">
        <button type="button" data-action="toggle-${kind}" data-id="${item.id}" class="text-sky-400">${item.is_published ? 'Unpublish' : 'Publish'}</button>
        <button type="button" data-action="edit-${kind}" data-id="${item.id}" class="text-sky-400">Edit</button>
        <button type="button" data-action="delete-${kind}" data-id="${item.id}" class="text-red-400">Remove</button>
      </div>`;

    const faqRow = (f) => `
      <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-xs flex flex-col sm:flex-row justify-between gap-3">
        <div class="min-w-0">
          <span class="px-2 py-0.5 rounded text-[10px] font-bold ${f.is_published ? 'bg-emerald-500/20 text-emerald-300' : 'bg-slate-700 text-slate-400'}">${f.is_published ? 'Live' : 'Draft'}</span>
          ${f.group ? `<span class="text-amber-400 font-bold ml-2">${escapeHtml(f.group)}</span>` : ''}
          <div class="text-white font-black text-sm mt-1">${escapeHtml(f.question)}</div>
          <p class="text-slate-400 mt-1 line-clamp-2">${escapeHtml(f.answer)}</p>
        </div>
        ${rowActions('faq', f)}
      </div>`;

    const industryRow = (i) => `
      <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-xs flex flex-col sm:flex-row justify-between gap-3">
        <div class="min-w-0">
          <span class="px-2 py-0.5 rounded text-[10px] font-bold ${i.is_published ? 'bg-emerald-500/20 text-emerald-300' : 'bg-slate-700 text-slate-400'}">${i.is_published ? 'Live' : 'Draft'}</span>
          <span class="text-white font-black text-sm ml-2">${escapeHtml(i.name)}</span>
          <p class="text-slate-400 mt-1">${escapeHtml(i.summary || '')}</p>
          <span class="text-slate-500">icon: ${escapeHtml(i.icon || 'globe')}</span>
        </div>
        ${rowActions('industry', i)}
      </div>`;

    const techRow = (t) => `
      <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 text-xs flex flex-col sm:flex-row justify-between gap-3">
        <div class="min-w-0">
          <span class="px-2 py-0.5 rounded text-[10px] font-bold ${t.is_published ? 'bg-emerald-500/20 text-emerald-300' : 'bg-slate-700 text-slate-400'}">${t.is_published ? 'Live' : 'Draft'}</span>
          ${t.group ? `<span class="text-emerald-400 font-bold ml-2">${escapeHtml(t.group)}</span>` : ''}
          <span class="text-white font-black text-sm ml-2">${escapeHtml(t.name)}</span>
          <p class="text-slate-400 mt-1">${escapeHtml(t.description || '')}</p>
          <span class="text-slate-500">icon: ${escapeHtml(t.icon || 'code')}</span>
        </div>
        ${rowActions('tech', t)}
      </div>`;

    return `
      <div class="space-y-10 animate-fadeIn">
        <div>
          <h2 class="text-2xl font-black text-white">Branch Content</h2>
          <p class="text-xs text-slate-400">FAQs, industries, and technology capabilities shown on the public /solutions page.</p>
        </div>

        <section class="space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="text-white font-bold">FAQs (${state.faqsList.length})</h3>
            <button type="button" data-action="new-faq" class="px-4 py-2 rounded-xl bg-amber-400 text-slate-950 font-black text-xs">${icon('plus', 'w-4 h-4 inline')} Add FAQ</button>
          </div>
          ${state.faqsList.map(faqRow).join('') || '<p class="text-slate-500 text-xs">No FAQs yet.</p>'}
        </section>

        <section class="space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="text-white font-bold">Industries (${state.industriesList.length})</h3>
            <button type="button" data-action="new-industry" class="px-4 py-2 rounded-xl bg-amber-400 text-slate-950 font-black text-xs">${icon('plus', 'w-4 h-4 inline')} Add industry</button>
          </div>
          ${state.industriesList.map(industryRow).join('') || '<p class="text-slate-500 text-xs">No industries yet.</p>'}
        </section>

        <section class="space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="text-white font-bold">Technology capabilities (${state.techList.length})</h3>
            <button type="button" data-action="new-tech" class="px-4 py-2 rounded-xl bg-amber-400 text-slate-950 font-black text-xs">${icon('plus', 'w-4 h-4 inline')} Add capability</button>
          </div>
          ${state.techList.map(techRow).join('') || '<p class="text-slate-500 text-xs">No capabilities yet.</p>'}
        </section>
      </div>`;
  }

  function panelInquiries() {
    const rows = state.inquiriesList; // L-6: no demo-PII fallback
    return `
      <div class="space-y-6 animate-fadeIn">
        <div>
          <h2 class="text-2xl font-black text-white">Contact &amp; Partnership Inquiries</h2>
          <p class="text-xs text-slate-400">Messages submitted through the public Contact form.</p>
        </div>

        <div class="space-y-4">
          ${rows.length === 0 ? '<p class="text-slate-500 text-xs py-6 text-center">No inquiries yet — contact-form submissions appear here.</p>' : ''}
          ${rows.map((inq) => `
            <div class="bg-slate-900 border border-slate-800 p-6 rounded-2xl flex flex-col sm:flex-row sm:items-start justify-between gap-4">
              <div class="space-y-2 max-w-2xl">
                <div class="flex items-center gap-2">
                  <span class="px-2 py-0.5 rounded text-[10px] font-bold ${inq.status === 'New' ? 'bg-sky-500/20 text-sky-400 animate-pulse' : 'bg-slate-800 text-slate-500'}">${inq.status}</span>
                  <span class="text-xs font-mono text-slate-400">${fmtDate(inq.created_at || Date.now())}</span>
                </div>
                <h4 class="font-bold text-white text-base">${escapeHtml(inq.subject || 'General Inquiry')}</h4>
                <p class="text-xs text-slate-300 leading-relaxed bg-slate-950 p-3 rounded-xl border border-slate-800/80">"${escapeHtml(inq.message)}"</p>
                <div class="text-[11px] text-amber-400 font-semibold pt-1">From: ${escapeHtml(inq.name)} (${escapeHtml(inq.email)})</div>
              </div>

              ${inq.status !== 'Resolved' ? `
                <button type="button" data-action="resolve-inquiry" data-id="${inq.id}"
                  class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-emerald-600 text-slate-300 hover:text-white transition-colors text-xs font-bold flex items-center gap-1.5 self-end sm:self-auto shrink-0">
                  ${icon('check', 'w-4 h-4 text-emerald-400')}
                  <span>Mark Resolved</span>
                </button>` : ''}
            </div>`).join('')}
        </div>
      </div>`;
  }

  function panelSite() {
    const f = state.siteForm;
    const preview = f.hero_image_url
      ? `<img src="${escapeHtml(f.hero_image_url)}" class="h-32 w-full object-cover rounded-xl border border-slate-700">`
      : `<div class="h-32 w-full rounded-xl border border-dashed border-slate-700 flex items-center justify-center text-slate-500 text-xs">No hero image set</div>`;
    return `
      <div class="space-y-8 animate-fadeIn">
        <h2 class="text-2xl font-black text-white">Site Content</h2>
        <form id="roi-site-form" class="space-y-6">
          <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
            <h3 class="font-bold text-white uppercase text-sm border-l-2 border-amber-500 pl-2">Home Hero</h3>
            <div data-preview-for="site" class="max-w-md">${preview}</div>
            <div class="max-w-md">
              <label class="block text-xs font-bold text-slate-400 mb-2">Hero image (JPG/PNG/WebP)</label>
              <input type="file" accept="image/*" data-upload-for="site"
                class="w-full text-xs text-slate-300 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-amber-400 file:text-slate-950 file:font-bold">
              <input type="hidden" data-sf="hero_image_url" value="${escapeHtml(f.hero_image_url || '')}">
            </div>
            <div class="max-w-md space-y-3">
              <div>
                <label class="block text-xs font-bold text-slate-400 mb-1">Eyebrow label</label>
                <input type="text" data-sf="hero_eyebrow" value="${escapeHtml(f.hero_eyebrow || '')}" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm">
              </div>
              <div>
                <label class="block text-xs font-bold text-slate-400 mb-1">Title</label>
                <input type="text" data-sf="hero_title" value="${escapeHtml(f.hero_title || '')}" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm">
              </div>
              <div>
                <label class="block text-xs font-bold text-slate-400 mb-1">Description</label>
                <textarea rows="3" data-sf="hero_description" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm resize-none">${escapeHtml(f.hero_description || '')}</textarea>
              </div>
            </div>
          </div>

          <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
            <h3 class="font-bold text-white uppercase text-sm border-l-2 border-sky-500 pl-2">Impact Metrics (home page)</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              ${[
                ['metric_youth_mentored', 'Youth Mentored'],
                ['metric_events_hosted', 'Events Hosted'],
                ['metric_individuals_supported', 'Individuals Supported'],
                ['metric_active_volunteers', 'Volunteer Network']
              ].map(([key, label]) => `
                <div>
                  <label class="block text-xs font-bold text-slate-400 mb-1">${label}</label>
                  <input type="number" min="0" data-sf="${key}" value="${escapeHtml(f[key] || '0')}" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm">
                </div>`).join('')}
            </div>
          </div>

          <button type="submit" class="px-6 py-4 rounded-xl bg-amber-400 hover:bg-amber-300 text-slate-950 font-black uppercase tracking-wider text-xs">Save Site Content</button>
        </form>
      </div>`;
  }

  function bindSitePanel() {
    bindImageUploads(panelRoot, 'site', 'sf', 'hero_image_url');
    const form = panelRoot.querySelector('#roi-site-form');
    if (!form) return;
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      form.querySelectorAll('[data-sf]').forEach((input) => {
        state.siteForm[input.dataset.sf] = input.value;
      });
      const payload = {
        hero_eyebrow: state.siteForm.hero_eyebrow,
        hero_title: state.siteForm.hero_title,
        hero_description: state.siteForm.hero_description,
        hero_image_url: state.siteForm.hero_image_url || null,
        metric_youth_mentored: Number(state.siteForm.metric_youth_mentored) || 0,
        metric_events_hosted: Number(state.siteForm.metric_events_hosted) || 0,
        metric_individuals_supported: Number(state.siteForm.metric_individuals_supported) || 0,
        metric_active_volunteers: Number(state.siteForm.metric_active_volunteers) || 0
      };
      try {
        await request('PUT', '/admin/site', payload);
        showToast('Site content updated.');
      } catch (err) {
        showToast(err.message || 'Could not save site content.');
      }
    });
  }

  // Site content is loaded on demand when the tab is opened (the dashboard's
  // bulk load is slow on shared hosting, so we don't rely on it for this panel).
  async function loadSiteContent() {
    state.siteLoading = true;
    try {
      const res = await request('GET', '/admin/site');
      if (res?.data) {
        state.siteForm = {
          hero_eyebrow: res.data.hero?.eyebrow ?? state.siteForm.hero_eyebrow,
          hero_title: res.data.hero?.title ?? state.siteForm.hero_title,
          hero_description: res.data.hero?.description ?? state.siteForm.hero_description,
          hero_image_url: res.data.hero?.image_url ?? state.siteForm.hero_image_url,
          metric_youth_mentored: String(res.data.metrics?.youth_mentored ?? state.siteForm.metric_youth_mentored),
          metric_events_hosted: String(res.data.metrics?.events_hosted ?? state.siteForm.metric_events_hosted),
          metric_individuals_supported: String(res.data.metrics?.individuals_supported ?? state.siteForm.metric_individuals_supported),
          metric_active_volunteers: String(res.data.metrics?.active_volunteers ?? state.siteForm.metric_active_volunteers)
        };
        state.siteLoaded = true;
      }
    } catch (err) {
      // Keep defaults; reopening the tab retries.
    } finally {
      state.siteLoading = false;
    }
    if (state.activeTab === 'site') {
      panelRoot.innerHTML = panelSite();
      bindSitePanel();
    }
  }

  // Generic: wire a file input to upload and set the sibling hidden field + preview.
  function bindImageUploads(scope, uploadKey, dataPrefix, hiddenKey = 'image_url') {
    scope.querySelectorAll(`[data-upload-for="${uploadKey}"]`).forEach((fileInput) => {
      fileInput.addEventListener('change', async () => {
        const file = fileInput.files?.[0];
        if (!file) return;
        showToast('Uploading image…');
        try {
          const res = await uploadImage(file);
          const hidden = scope.querySelector(`[data-${dataPrefix}="${hiddenKey}"]`);
          if (hidden) hidden.value = res.url;
          const preview = scope.querySelector(`[data-preview-for="${uploadKey}"]`);
          if (preview) preview.innerHTML = `<img src="${escapeHtml(res.url)}" class="h-32 w-full object-cover rounded-xl border border-slate-700">`;
          showToast('Image uploaded.');
        } catch (err) {
          showToast(err.message || 'Upload failed.');
        }
      });
    });
  }

  function paint() {
    switch (state.activeTab) {
      case 'overview': panelRoot.innerHTML = panelOverview(); break;
      case 'blog': panelRoot.innerHTML = panelBlog(); break;
      case 'events': panelRoot.innerHTML = panelEvents(); break;
      case 'media':
        panelRoot.innerHTML = panelMedia();
        bindMediaPanel();
        break;
      case 'leaders': panelRoot.innerHTML = panelLeaders(); break;
      case 'volunteers':
        panelRoot.innerHTML = panelVolunteers();
        bindVolunteersPanel();
        break;
      case 'inquiries': panelRoot.innerHTML = panelInquiries(); break;
      case 'tickets':
        panelRoot.innerHTML = panelTickets();
        bindTicketsPanel();
        break;
      case 'solutions':
        panelRoot.innerHTML = panelSolutions();
        panelRoot.querySelector('#roi-inquiry-status-filter')?.addEventListener('change', (e) => {
          state.inquiryStatusFilter = e.target.value;
          paint();
        });
        break;
      case 'branch-content': panelRoot.innerHTML = panelBranchContent(); break;
      case 'site':
        panelRoot.innerHTML = panelSite();
        bindSitePanel();
        if (!state.siteLoaded && !state.siteLoading) loadSiteContent();
        break;
    }
    bindPanelActions();
  }

  // ------------------------------------------------------- Panel actions

  function bindMediaPanel() {
    const form = root.querySelector('#roi-media-sync-form');
    if (!form) return;
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      state.featuredOverrideId = root.querySelector('#roi-media-override').value;
      state.syncingMedia = true;
      paint(); // re-renders with spinner
      try {
        await request('POST', `/admin/media/sync?featured_youtube_id=${encodeURIComponent(state.featuredOverrideId || '')}`).catch(() => {});
      } catch {}
      setTimeout(() => {
        state.syncingMedia = false;
        showToast(
          state.featuredOverrideId
            ? `Featured Theater video ID updated to ${state.featuredOverrideId}. Cache purged.`
            : 'Local YouTube metadata cache synchronized with DEMO TV channel API.'
        );
        state.featuredOverrideId = '';
        paint();
      }, 1200);
    });
  }

  function bindVolunteersPanel() {
    const searchEl = root.querySelector('#roi-vol-search');
    searchEl.addEventListener('input', () => {
      state.volSearch = searchEl.value; // no repaint: preserves focus while filtering on next render
      // The React version filters live; replicate by repainting only the table body:
      state.volSearch = searchEl.value;
      paintLiveVolunteerRows();
    });
    root.querySelector('#roi-vol-skill-filter').addEventListener('change', (e) => {
      state.volSkillFilter = e.target.value;
      paint();
    });
  }

  function paintLiveVolunteerRows() {
    // Lightweight in-place refresh of the volunteers table without full repaint.
    const rows = filteredVols(); // L-6: no demo-PII fallback — real data or empty state
    const table = panelRoot.querySelector('tbody');
    if (!table) return;
    table.innerHTML = rows
      .map(
        (vol) => `
      <tr class="hover:bg-slate-800/50">
        <td class="p-4 font-mono text-slate-400">#${vol.id}</td>
        <td class="p-4 font-bold text-white">${escapeHtml(vol.full_name)}</td>
        <td class="p-4"><span class="block text-sky-400">${escapeHtml(vol.email)}</span><span class="text-[10px] text-slate-500 font-mono">${escapeHtml(vol.phone)}</span></td>
        <td class="p-4"><span class="px-2.5 py-1 rounded-full bg-amber-500/10 text-amber-400 font-bold">${escapeHtml(vol.primary_skill)}</span></td>
        <td class="p-4 text-slate-300 max-w-xs truncate">${escapeHtml(vol.availability)}</td>
        <td class="p-4"><span class="px-2 py-0.5 rounded text-[10px] font-bold ${vol.status === 'Approved' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-amber-500/20 text-amber-300'}">${escapeHtml(vol.status)}</span></td>
      </tr>`
      )
      .join('');
  }

  function bindTicketsPanel() {
    const form = root.querySelector('#roi-checkin-form');
    if (!form) return;
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const input = form.querySelector('#roi-checkin-code');
      const code = normalizeTicketCode(input.value);
      if (!code) return;
      const resultEl = root.querySelector('#roi-checkin-result');
      if (resultEl) resultEl.innerHTML = '';
      try {
        const res = await request('POST', `/admin/tickets/${encodeURIComponent(code)}/check-in`);
        const r = res.data?.result;
        if (res.ok && r === 'admitted') {
          showToast(`Admitted ${res.data?.attendee_name || code}`);
          const ts = await request('GET', '/admin/ticket-stats').catch(() => null);
          if (ts?.data) state.ticketStats = ts.data;
          input.value = '';
          paint();
        } else if (r === 'already') {
          const detail = res.data?.detail || 'Ticket already checked in.';
          // Re-query after the await: paint() may have rebuilt the panel.
          const live = root.querySelector('#roi-checkin-result');
          if (live) {
            live.innerHTML = `
              <div class="mt-3 flex flex-wrap items-center justify-between gap-3 bg-amber-500/10 border border-amber-500/40 rounded-xl px-4 py-3">
                <span class="text-xs text-amber-100">${escapeHtml(detail)}</span>
                <button type="button" id="roi-checkin-undo" class="px-3 py-1.5 rounded-lg bg-slate-900/70 text-[11px] font-bold text-white">Undo check-in</button>
              </div>`;
            const undoBtn = live.querySelector('#roi-checkin-undo');
            undoBtn?.addEventListener('click', async () => {
              if (undoBtn.disabled) return;
              undoBtn.disabled = true;
              try {
                const u = await request('POST', `/admin/tickets/${encodeURIComponent(code)}/undo-check-in`);
                if (u.ok) {
                  showToast('Check-in undone.');
                  const chip = root.querySelector('#roi-checkin-result');
                  if (chip) chip.innerHTML = '';
                  const ts = await request('GET', '/admin/ticket-stats').catch(() => null);
                  if (ts?.data) state.ticketStats = ts.data;
                  paint();
                } else {
                  undoBtn.disabled = false;
                  showToast(u.data?.detail || 'Undo failed.');
                }
              } catch (err) {
                undoBtn.disabled = false;
                showToast(err.message || 'Undo failed.');
              }
            });
          } else {
            showToast(detail);
          }
        } else if (r === 'void') {
          showToast('Ticket has been voided.');
        } else if (r === 'unpaid') {
          showToast('Order is not paid.');
        } else if (r === 'not_found') {
          showToast('Ticket not found.');
        } else {
          showToast(res.data?.detail || 'Check-in failed.');
        }
      } catch (err) {
        showToast(err.message || 'Check-in failed.');
      }
    });
  }

  function bindPanelActions() {
    panelRoot.querySelectorAll('[data-action]').forEach((btn) => {
      // Vanilla-port fix: these handlers must run on CLICK, not once per paint
      // (previously every panel auto-fired all its actions on render).
      btn.addEventListener('click', () => {
      const action = btn.dataset.action;
      const id = btn.dataset.id;

      if (action === 'new-blog') {
        state.editingBlog = null;
        state.blogForm = { title: '', summary: '', content: '', category: 'Social Impact', author: 'DEMO Desk', image_url: '', is_published: true };
        openBlogModal();
      }
      if (action === 'edit-blog') {
        const b = state.blogs.find((x) => String(x.id) === id);
        if (b) {
          state.editingBlog = b;
          state.blogForm = { ...b };
          openBlogModal();
        }
      }
      if (action === 'delete-blog') handleDeleteBlog(id);
      if (action === 'new-event') {
        state.editingEvent = null;
        state.eventForm = { title: '', date: '', time: '09:00 AM EAT', location: 'Harbor City, Kenya', description: '', category: 'Workshop', image_url: '', is_active: true, ticket_sales_enabled: true, capacity: '' };
        openEventModal();
      }
      if (action === 'edit-event') {
        const ev = state.eventsList.find((x) => String(x.id) === id);
        if (ev) {
          state.editingEvent = ev;
          state.eventForm = { ...ev, capacity: ev.capacity == null ? '' : String(ev.capacity), ticket_sales_enabled: ev.ticket_sales_enabled !== false };
          openEventModal();
        }
      }
      if (action === 'delete-event') handleDeleteEvent(id);
      if (action === 'new-leader') {
        state.editingLeader = null;
        state.leaderForm = { name: '', role: '', bio: '' };
        openLeaderModal();
      }
      if (action === 'edit-leader') {
        const ld = state.leadersList.find((x) => String(x.id) === id);
        if (ld) {
          state.editingLeader = ld;
          state.leaderForm = { ...ld };
          openLeaderModal();
        }
      }
      if (action === 'delete-leader') handleDeleteLeader(id);
      if (action === 'resolve-inquiry') handleResolveInquiry(id);
      if (action === 'export-csv') handleExportCSV();
      if (action === 'new-ticket-type') {
        state.editingTicketType = null;
        state.ticketForm = { event_id: String(state.eventsList[0]?.id || ''), name: '', description: '', price: '0', quantity: '', max_per_order: '10', sales_start: '', sales_end: '', is_active: true };
        openTicketModal();
      }
      if (action === 'edit-ticket-type') {
        const t = state.ticketTypes.find((x) => String(x.id) === id);
        if (t) {
          state.editingTicketType = t;
          state.ticketForm = {
            event_id: String(t.event_id),
            name: t.name,
            description: t.description || '',
            price: String(t.price),
            quantity: t.quantity == null ? '' : String(t.quantity),
            max_per_order: String(t.max_per_order || 10),
            sales_start: utcToEat(t.sales_start),
            sales_end: utcToEat(t.sales_end),
            is_active: t.is_active
          };
          openTicketModal();
        }
      }
      if (action === 'delete-ticket-type') handleDeleteTicketType(id);
      if (action === 'export-ticket-csv') handleExportTicketCsv();
      if (action === 'new-solution') {
        state.editingSolution = null;
        openSolutionModal({ title: '', category: 'Platform', summary: '', description: '', price_label: '', is_published: true });
      }
      if (action === 'edit-solution') {
        const s = state.solutionsList.find((x) => String(x.id) === id);
        if (s) {
          state.editingSolution = s;
          openSolutionModal(s);
        }
      }
      if (action === 'delete-solution') handleDeleteSolution(id);
      if (action === 'toggle-solution') handleToggleSolution(id);
      if (action === 'move-solution') handleMoveSolution(id, Number(btn.dataset.dir));
      if (action === 'resolve-solution-inquiry') handleResolveSolutionInquiry(id);
      if (action === 'advance-inquiry') handleAdvanceInquiry(id, btn.dataset.status);
      if (action === 'note-inquiry') handleNoteInquiry(id);
      if (action === 'new-portfolio') {
        state.editingPortfolio = null;
        openPortfolioModal({ title: '', client: '', year: '', summary: '', outcome: '', image_url: '', digital_solution_id: '', is_published: true, is_featured: false });
      }
      if (action === 'edit-portfolio') {
        const p = state.portfolioList.find((x) => String(x.id) === id);
        if (p) {
          state.editingPortfolio = p;
          openPortfolioModal(p);
        }
      }
      if (action === 'delete-portfolio') handleDeletePortfolio(id);
      if (action === 'new-faq') {
        state.editingFaq = null;
        openFaqModal({ question: '', answer: '', group: '', is_published: true });
      }
      if (action === 'edit-faq') {
        const f = state.faqsList.find((x) => String(x.id) === id);
        if (f) { state.editingFaq = f; openFaqModal(f); }
      }
      if (action === 'delete-faq') handleDeleteBranchItem('faq', id, '/admin/solution-faqs', state.faqsList);
      if (action === 'toggle-faq') handleToggleBranchItem('faq', id, '/admin/solution-faqs');
      if (action === 'new-industry') {
        state.editingIndustry = null;
        openIndustryModal({ name: '', icon: 'briefcase', summary: '', is_published: true });
      }
      if (action === 'edit-industry') {
        const i = state.industriesList.find((x) => String(x.id) === id);
        if (i) { state.editingIndustry = i; openIndustryModal(i); }
      }
      if (action === 'delete-industry') handleDeleteBranchItem('industry', id, '/admin/industries', state.industriesList);
      if (action === 'toggle-industry') handleToggleBranchItem('industry', id, '/admin/industries');
      if (action === 'new-tech') {
        state.editingTech = null;
        openTechModal({ name: '', icon: 'code', description: '', group: '', is_published: true });
      }
      if (action === 'edit-tech') {
        const t = state.techList.find((x) => String(x.id) === id);
        if (t) { state.editingTech = t; openTechModal(t); }
      }
      if (action === 'delete-tech') handleDeleteBranchItem('tech', id, '/admin/tech', state.techList);
      if (action === 'toggle-tech') handleToggleBranchItem('tech', id, '/admin/tech');
      });
    });
  }

  // ------------------------------------------------------------- CRUD ops

  async function handleDeleteBlog(id) {
    if (!window.confirm('Are you sure you want to prune this article?')) return;
    await request('DELETE', `/admin/blog/${id}`).catch(() => {});
    state.blogs = state.blogs.filter((b) => String(b.id) !== String(id));
    showToast('Article pruned from database.');
    paint();
  }

  async function handleDeleteEvent(id) {
    if (!window.confirm('Remove this event from schedule?')) return;
    await request('DELETE', `/admin/events/${id}`).catch(() => {});
    state.eventsList = state.eventsList.filter((ev) => String(ev.id) !== String(id));
    showToast('Event removed.');
    paint();
  }

  async function handleDeleteLeader(id) {
    if (!window.confirm('Remove this steward?')) return;
    await request('DELETE', `/admin/leaders/${id}`).catch(() => {});
    state.leadersList = state.leadersList.filter((ld) => String(ld.id) !== String(id));
    showToast('Steward removed.');
    paint();
  }

  async function handleDeleteTicketType(id) {
    if (!window.confirm('Remove this ticket type?')) return;
    await request('DELETE', `/admin/ticket-types/${id}`).catch(() => {});
    state.ticketTypes = state.ticketTypes.filter((t) => String(t.id) !== String(id));
    showToast('Ticket type removed.');
    paint();
  }

  async function handleDeleteSolution(id) {
    if (!window.confirm('Remove this digital solution?')) return;
    await request('DELETE', `/admin/solutions/${id}`).catch(() => {});
    state.solutionsList = state.solutionsList.filter((s) => String(s.id) !== String(id));
    showToast('Solution removed.');
    paint();
  }

  async function handleToggleSolution(id) {
    const s = state.solutionsList.find((x) => String(x.id) === String(id));
    if (!s) return;
    const res = await request('PUT', `/admin/solutions/${id}`, { is_published: !s.is_published });
    if (res.data) state.solutionsList = state.solutionsList.map((x) => (String(x.id) === String(id) ? res.data : x));
    showToast(res.data?.is_published ? 'Solution published.' : 'Solution unpublished.');
    paint();
  }

  async function handleMoveSolution(id, dir) {
    const ids = state.solutionsList.map((s) => s.id);
    const idx = ids.findIndex((x) => String(x) === String(id));
    const next = idx + dir;
    if (idx < 0 || next < 0 || next >= ids.length) return;
    [ids[idx], ids[next]] = [ids[next], ids[idx]];
    const res = await request('PUT', '/admin/solutions/reorder', { ids });
    if (res.data) state.solutionsList = res.data;
    paint();
  }

  async function handleResolveSolutionInquiry(id) {
    await request('PUT', `/admin/solution-inquiries/${id}/read`).catch(() => {});
    state.solutionInquiries = state.solutionInquiries.map((i) => (String(i.id) === String(id) ? { ...i, status: 'Resolved' } : i));
    showToast('Briefing marked resolved.');
    paint();
  }

  async function handleAdvanceInquiry(id, status) {
    const res = await request('PUT', `/admin/solution-inquiries/${id}`, { status });
    if (!res.ok) {
      showToast(res.data?.detail || 'Cannot change status.');
      return;
    }
    if (res.data) state.solutionInquiries = state.solutionInquiries.map((i) => (String(i.id) === String(id) ? res.data : i));
    showToast(`Moved to ${status}.`);
    paint();
  }

  async function handleNoteInquiry(id) {
    const current = state.solutionInquiries.find((i) => String(i.id) === String(id));
    const notes = window.prompt('Internal notes', current?.notes || '');
    if (notes === null) return;
    const quote = window.prompt('Quoted amount KES (blank to skip)', current?.quoted_amount ?? '');
    const payload = { notes };
    if (quote !== '' && quote != null) payload.quoted_amount = Number(quote);
    const res = await request('PUT', `/admin/solution-inquiries/${id}`, payload);
    if (res.data) state.solutionInquiries = state.solutionInquiries.map((i) => (String(i.id) === String(id) ? res.data : i));
    showToast('Inquiry updated.');
    paint();
  }

  function openSolutionModal(form) {
    modalRoot.innerHTML = `
      <div class="fixed inset-0 z-[170] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-700 w-full max-w-lg rounded-3xl p-6 space-y-4 relative max-h-[90vh] overflow-y-auto">
          <button type="button" data-close-modal class="absolute top-6 right-6 text-slate-400">${icon('x', 'w-5 h-5')}</button>
          <h3 class="text-xl font-black text-white">${state.editingSolution ? 'Edit solution' : 'New solution'}</h3>
          <form id="roi-solution-form" class="space-y-3 text-xs">
            <input required placeholder="Title" value="${escapeHtml(form.title || '')}" data-sf="title" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">
            <input placeholder="Service category (e.g. Websites and Digital Presence)" value="${escapeHtml(form.service_category || '')}" data-sf="service_category" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">
            <select data-sf="category" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">
              ${['Platform', 'Education', 'Media', 'Advisory', 'Custom Development', 'Commerce', 'Events', 'Integrations', 'Automation', 'Consulting', 'Support'].map((c) => `<option ${form.category === c ? 'selected' : ''}>${c}</option>`).join('')}
            </select>
            <input placeholder="Price label" value="${escapeHtml(form.price_label || '')}" data-sf="price_label" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">
            <textarea required rows="2" placeholder="Summary" data-sf="summary" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">${escapeHtml(form.summary || '')}</textarea>
            <textarea required rows="4" placeholder="Description" data-sf="description" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">${escapeHtml(form.description || '')}</textarea>
            <textarea rows="2" placeholder="Features (comma-separated, e.g. Responsive design, SEO optimization)" data-sf="__features_text" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">${escapeHtml(Array.isArray(form.features) ? form.features.join(', ') : '')}</textarea>
            <label class="flex items-center gap-2 text-slate-300"><input type="checkbox" data-sf="is_published" ${form.is_published ? 'checked' : ''}> Publish on /solutions</label>
            <button class="w-full py-3 rounded-xl bg-amber-400 text-slate-950 font-black uppercase">Save solution</button>
          </form>
        </div>
      </div>`;
    modalRoot.querySelectorAll('[data-close-modal]').forEach((b) => b.addEventListener('click', closeModal));
    modalRoot.querySelector('#roi-solution-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const payload = {};
      modalRoot.querySelectorAll('[data-sf]').forEach((input) => {
        payload[input.dataset.sf] = input.type === 'checkbox' ? input.checked : input.value;
      });
      // features arrive as comma-separated text; convert to array for the API
      const featuresText = payload.__features_text || '';
      delete payload.__features_text;
      const features = featuresText.split(',').map((f) => f.trim()).filter(Boolean);
      payload.features = features.length ? features : null;
      payload.service_category = payload.service_category || null;
      try {
        if (state.editingSolution) {
          const res = await request('PUT', `/admin/solutions/${state.editingSolution.id}`, payload);
          if (res.data) state.solutionsList = state.solutionsList.map((s) => (s.id === state.editingSolution.id ? res.data : s));
          showToast('Solution updated.');
        } else {
          const res = await request('POST', '/admin/solutions', payload);
          if (res.data) state.solutionsList = [...state.solutionsList, res.data];
          showToast('Solution created.');
        }
        closeModal();
        paint();
      } catch (err) {
        showToast(err.response?.data?.detail || 'Could not save solution.');
      }
    });
  }

  async function handleDeletePortfolio(id) {
    if (!window.confirm('Remove this case study?')) return;
    await request('DELETE', `/admin/portfolio/${id}`).catch(() => {});
    state.portfolioList = state.portfolioList.filter((p) => String(p.id) !== String(id));
    showToast('Portfolio item removed.');
    paint();
  }

  function openPortfolioModal(form) {
    const opts = state.solutionsList.map((s) =>
      `<option value="${s.id}" ${String(form.digital_solution_id) === String(s.id) ? 'selected' : ''}>${escapeHtml(s.title)}</option>`
    ).join('');
    modalRoot.innerHTML = `
      <div class="fixed inset-0 z-[170] flex items-center justify-center p-4 bg-black/80">
        <div class="bg-slate-900 border border-slate-700 w-full max-w-lg rounded-3xl p-6 space-y-3 relative max-h-[90vh] overflow-y-auto">
          <button type="button" data-close-modal class="absolute top-6 right-6 text-slate-400">${icon('x', 'w-5 h-5')}</button>
          <h3 class="text-xl font-black text-white">${state.editingPortfolio ? 'Edit case' : 'New case study'}</h3>
          <form id="roi-portfolio-form" class="space-y-3 text-xs">
            <input required placeholder="Title" value="${escapeHtml(form.title || '')}" data-pf="title" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">
            <select data-pf="digital_solution_id" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white"><option value="">Unlinked</option>${opts}</select>
            <div class="grid grid-cols-2 gap-2">
              <input placeholder="Client" value="${escapeHtml(form.client || '')}" data-pf="client" class="px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">
              <input placeholder="Year" value="${escapeHtml(form.year || '')}" data-pf="year" class="px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">
            </div>
            <input placeholder="Image URL" value="${escapeHtml(form.image_url || '')}" data-pf="image_url" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">
            <textarea required rows="2" placeholder="Summary" data-pf="summary" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">${escapeHtml(form.summary || '')}</textarea>
            <textarea rows="2" placeholder="Outcome" data-pf="outcome" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">${escapeHtml(form.outcome || '')}</textarea>
            <label class="flex items-center gap-2 text-slate-300"><input type="checkbox" data-pf="is_published" ${form.is_published ? 'checked' : ''}> Published</label>
            <label class="flex items-center gap-2 text-slate-300"><input type="checkbox" data-pf="is_featured" ${form.is_featured ? 'checked' : ''}> Featured</label>
            <button class="w-full py-3 rounded-xl bg-amber-400 text-slate-950 font-black uppercase">Save case</button>
          </form>
        </div>
      </div>`;
    modalRoot.querySelectorAll('[data-close-modal]').forEach((b) => b.addEventListener('click', closeModal));
    modalRoot.querySelector('#roi-portfolio-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const payload = {};
      modalRoot.querySelectorAll('[data-pf]').forEach((input) => {
        payload[input.dataset.pf] = input.type === 'checkbox' ? input.checked : input.value;
      });
      payload.digital_solution_id = payload.digital_solution_id ? Number(payload.digital_solution_id) : null;
      try {
        if (state.editingPortfolio) {
          const res = await request('PUT', `/admin/portfolio/${state.editingPortfolio.id}`, payload);
          if (res.data) state.portfolioList = state.portfolioList.map((p) => (p.id === state.editingPortfolio.id ? res.data : p));
          showToast('Case study updated.');
        } else {
          const res = await request('POST', '/admin/portfolio', payload);
          if (res.data) state.portfolioList = [res.data, ...state.portfolioList];
          showToast('Case study created.');
        }
        closeModal();
        paint();
      } catch (err) {
        showToast(err.response?.data?.detail || 'Could not save case study.');
      }
    });
  }

  // ------------------------------------------- Branch content CRUD (FAQ/Industry/Tech)

  const BRANCH_LABELS = { faq: 'FAQ', industry: 'Industry', tech: 'Capability' };

  function listFor(kind) {
    return kind === 'faq' ? state.faqsList : kind === 'industry' ? state.industriesList : state.techList;
  }
  function setListFor(kind, list) {
    if (kind === 'faq') state.faqsList = list;
    else if (kind === 'industry') state.industriesList = list;
    else state.techList = list;
  }

  async function handleDeleteBranchItem(kind, id, endpoint, list) {
    if (!window.confirm(`Remove this ${BRANCH_LABELS[kind].toLowerCase()}?`)) return;
    const res = await request('DELETE', `${endpoint}/${id}`).catch(() => null);
    if (res === null) {
      showToast(`Could not remove ${BRANCH_LABELS[kind].toLowerCase()}.`);
      return;
    }
    setListFor(kind, list.filter((x) => String(x.id) !== String(id)));
    showToast(`${BRANCH_LABELS[kind]} removed.`);
    paint();
  }

  async function handleToggleBranchItem(kind, id, endpoint) {
    const item = listFor(kind).find((x) => String(x.id) === String(id));
    if (!item) return;
    const res = await request('PUT', `${endpoint}/${id}`, { is_published: !item.is_published });
    if (res.data) setListFor(kind, listFor(kind).map((x) => (String(x.id) === String(id) ? res.data : x)));
    showToast(res.data?.is_published ? `${BRANCH_LABELS[kind]} published.` : `${BRANCH_LABELS[kind]} unpublished.`);
    paint();
  }

  async function saveBranchItem(kind, endpoint, payload) {
    const editing = kind === 'faq' ? state.editingFaq : kind === 'industry' ? state.editingIndustry : state.editingTech;
    try {
      if (editing) {
        const res = await request('PUT', `${endpoint}/${editing.id}`, payload);
        if (res.data) setListFor(kind, listFor(kind).map((x) => (x.id === editing.id ? res.data : x)));
        showToast(`${BRANCH_LABELS[kind]} updated.`);
      } else {
        const res = await request('POST', endpoint, payload);
        if (res.data) setListFor(kind, [...listFor(kind), res.data]);
        showToast(`${BRANCH_LABELS[kind]} created.`);
      }
      closeModal();
      paint();
    } catch (err) {
      showToast(err.response?.data?.detail || `Could not save ${BRANCH_LABELS[kind].toLowerCase()}.`);
    }
  }

  const branchInputCls = 'w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white';

  function openFaqModal(form) {
    modalRoot.innerHTML = `
      <div class="fixed inset-0 z-[170] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-700 w-full max-w-lg rounded-3xl p-6 space-y-3 relative max-h-[90vh] overflow-y-auto">
          <button type="button" data-close-modal class="absolute top-6 right-6 text-slate-400">${icon('x', 'w-5 h-5')}</button>
          <h3 class="text-xl font-black text-white">${state.editingFaq ? 'Edit FAQ' : 'New FAQ'}</h3>
          <form id="roi-faq-form" class="space-y-3 text-xs">
            <input required placeholder="Question" value="${escapeHtml(form.question || '')}" data-bf="question" class="${branchInputCls}">
            <textarea required rows="4" placeholder="Answer" data-bf="answer" class="${branchInputCls}">${escapeHtml(form.answer || '')}</textarea>
            <input placeholder="Group (optional — e.g. General, Process)" value="${escapeHtml(form.group || '')}" data-bf="group" class="${branchInputCls}">
            <label class="flex items-center gap-2 text-slate-300"><input type="checkbox" data-bf="is_published" ${form.is_published ? 'checked' : ''}> Published</label>
            <button class="w-full py-3 rounded-xl bg-amber-400 text-slate-950 font-black uppercase">Save FAQ</button>
          </form>
        </div>
      </div>`;
    modalRoot.querySelectorAll('[data-close-modal]').forEach((b) => b.addEventListener('click', closeModal));
    modalRoot.querySelector('#roi-faq-form').addEventListener('submit', (e) => {
      e.preventDefault();
      const payload = {};
      modalRoot.querySelectorAll('[data-bf]').forEach((input) => {
        payload[input.dataset.bf] = input.type === 'checkbox' ? input.checked : input.value;
      });
      saveBranchItem('faq', '/admin/solution-faqs', payload);
    });
  }

  function openIndustryModal(form) {
    modalRoot.innerHTML = `
      <div class="fixed inset-0 z-[170] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-700 w-full max-w-lg rounded-3xl p-6 space-y-3 relative max-h-[90vh] overflow-y-auto">
          <button type="button" data-close-modal class="absolute top-6 right-6 text-slate-400">${icon('x', 'w-5 h-5')}</button>
          <h3 class="text-xl font-black text-white">${state.editingIndustry ? 'Edit industry' : 'New industry'}</h3>
          <form id="roi-industry-form" class="space-y-3 text-xs">
            <input required placeholder="Name (e.g. Schools & Education)" value="${escapeHtml(form.name || '')}" data-bf="name" class="${branchInputCls}">
            <input placeholder="Icon name (briefcase, users, building…)" value="${escapeHtml(form.icon || 'briefcase')}" data-bf="icon" class="${branchInputCls}">
            <textarea rows="2" placeholder="Summary" data-bf="summary" class="${branchInputCls}">${escapeHtml(form.summary || '')}</textarea>
            <label class="flex items-center gap-2 text-slate-300"><input type="checkbox" data-bf="is_published" ${form.is_published ? 'checked' : ''}> Published</label>
            <button class="w-full py-3 rounded-xl bg-amber-400 text-slate-950 font-black uppercase">Save industry</button>
          </form>
        </div>
      </div>`;
    modalRoot.querySelectorAll('[data-close-modal]').forEach((b) => b.addEventListener('click', closeModal));
    modalRoot.querySelector('#roi-industry-form').addEventListener('submit', (e) => {
      e.preventDefault();
      const payload = {};
      modalRoot.querySelectorAll('[data-bf]').forEach((input) => {
        payload[input.dataset.bf] = input.type === 'checkbox' ? input.checked : input.value;
      });
      saveBranchItem('industry', '/admin/industries', payload);
    });
  }

  function openTechModal(form) {
    modalRoot.innerHTML = `
      <div class="fixed inset-0 z-[170] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
        <div class="bg-slate-900 border border-slate-700 w-full max-w-lg rounded-3xl p-6 space-y-3 relative max-h-[90vh] overflow-y-auto">
          <button type="button" data-close-modal class="absolute top-6 right-6 text-slate-400">${icon('x', 'w-5 h-5')}</button>
          <h3 class="text-xl font-black text-white">${state.editingTech ? 'Edit capability' : 'New capability'}</h3>
          <form id="roi-tech-form" class="space-y-3 text-xs">
            <input required placeholder="Name (e.g. Laravel (PHP))" value="${escapeHtml(form.name || '')}" data-bf="name" class="${branchInputCls}">
            <input placeholder="Group (Backend, Frontend, DevOps…)" value="${escapeHtml(form.group || '')}" data-bf="group" class="${branchInputCls}">
            <input placeholder="Icon name (code, database, cloud…)" value="${escapeHtml(form.icon || 'code')}" data-bf="icon" class="${branchInputCls}">
            <textarea rows="2" placeholder="Description" data-bf="description" class="${branchInputCls}">${escapeHtml(form.description || '')}</textarea>
            <label class="flex items-center gap-2 text-slate-300"><input type="checkbox" data-bf="is_published" ${form.is_published ? 'checked' : ''}> Published</label>
            <button class="w-full py-3 rounded-xl bg-amber-400 text-slate-950 font-black uppercase">Save capability</button>
          </form>
        </div>
      </div>`;
    modalRoot.querySelectorAll('[data-close-modal]').forEach((b) => b.addEventListener('click', closeModal));
    modalRoot.querySelector('#roi-tech-form').addEventListener('submit', (e) => {
      e.preventDefault();
      const payload = {};
      modalRoot.querySelectorAll('[data-bf]').forEach((input) => {
        payload[input.dataset.bf] = input.type === 'checkbox' ? input.checked : input.value;
      });
      saveBranchItem('tech', '/admin/tech', payload);
    });
  }

  async function handleResolveInquiry(id) {
    await request('PUT', `/admin/inquiries/${id}/read`).catch(() => {});
    state.inquiriesList = state.inquiriesList.map((inq) => (String(inq.id) === String(id) ? { ...inq, status: 'Resolved' } : inq));
    showToast('Inquiry marked as resolved.');
    paint();
  }

  // F-05: escape quotes AND neutralize spreadsheet formula injection (=-+@).
  function csvCell(val) {
    let str = String(val ?? '');
    if (/^[=+@\t\r-]/.test(str)) str = `'${str}`;
    return `"${str.replaceAll('"', '""')}"`;
  }

  async function handleExportTicketCsv() {
    let csv =
      'data:text/csv;charset=utf-8,Reference,Buyer Name,Buyer Email,Status,Amount,Currency,Event,Tickets\n';
    (state.ticketOrders || []).forEach((o) => {
      csv +=
        `${csvCell(o.reference)},${csvCell(o.buyer_name)},${csvCell(o.buyer_email)},` +
        `${csvCell(o.status)},${csvCell(o.amount)},${csvCell(o.currency)},` +
        `${csvCell(eventTitle(o.event_id))},${csvCell((o.tickets || []).length)}\n`;
    });
    const link = document.createElement('a');
    link.href = encodeURI(csv);
    link.download = 'roi_ticket_orders.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showToast('Ticket orders CSV exported.');
  }

  function handleExportCSV() {
    let csvContent = 'data:text/csv;charset=utf-8,ID,Full Name,Email Address,Phone Number,Primary Skill,Availability,Motivation,Status\n';
    // L-6: export REAL records only — never fabricate sample people.
    const sampleVols = state.volunteersList;

    // Mirror backend F-05: escape quotes AND neutralize spreadsheet formulas.
    const cell = csvCell;
    sampleVols.forEach((v) => {
      csvContent += `${v.id},${cell(v.full_name)},${cell(v.email)},${cell(v.phone)},${cell(v.primary_skill)},${cell(v.availability)},${cell(v.motivation || '')},${cell(v.status)}\n`;
    });

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', 'roi_volunteers_registry.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showToast('Volunteer registry CSV successfully exported.');
  }

  // --------------------------------------------------------------- Modals

  function closeModal() {
    modalRoot.innerHTML = '';
  }

  function openBlogModal() {
    modalRoot.innerHTML = `
      <div class="fixed inset-0 z-[170] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fadeIn">
        <div class="bg-slate-900 border border-slate-700 w-full max-w-xl rounded-3xl p-6 sm:p-8 space-y-6 relative max-h-[90vh] overflow-y-auto">
          <button type="button" data-close-modal class="absolute top-6 right-6 text-slate-400 hover:text-white">${icon('x', 'w-5 h-5')}</button>
          <h3 class="text-xl font-black text-white">${state.editingBlog ? 'Edit Article Structure' : 'Publish Field Operational Story'}</h3>

          <form id="roi-blog-form" class="space-y-4 text-xs">
            <input type="text" required placeholder="Article Title" value="${escapeHtml(state.blogForm.title)}" data-bf="title"
              class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm focus:outline-none focus:border-amber-400">
            <div class="grid grid-cols-2 gap-3">
              <select data-bf="category" class="px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:outline-none">
                ${['Social Impact', 'Mentorship', 'Education', 'Success Stories'].map(
                  (c) => `<option value="${c}" ${state.blogForm.category === c ? 'selected' : ''}>${c}</option>`
                ).join('')}
              </select>
              <input type="text" placeholder="Author Name" value="${escapeHtml(state.blogForm.author || '')}" data-bf="author"
                class="px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:outline-none">
            </div>
            <div class="space-y-2">
              <label class="block text-slate-400 font-bold uppercase text-[10px] tracking-wider">Feature Image</label>
              <div data-preview-for="bf" class="max-w-xs">
                ${state.blogForm.image_url
                  ? `<img src="${escapeHtml(state.blogForm.image_url)}" class="h-24 w-full object-cover rounded-xl border border-slate-700">`
                  : `<div class="h-24 w-full rounded-xl border border-dashed border-slate-700 flex items-center justify-center text-slate-500 text-[10px]">No image</div>`}
              </div>
              <input type="file" accept="image/*" data-upload-for="bf"
                class="w-full text-xs text-slate-300 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-amber-400 file:text-slate-950 file:font-bold">
              <input type="hidden" data-bf="image_url" value="${escapeHtml(state.blogForm.image_url || '')}">
            </div>
            <textarea rows="2" required placeholder="Short Article Summary" data-bf="summary"
              class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:outline-none resize-none">${escapeHtml(state.blogForm.summary || '')}</textarea>
            <textarea rows="6" required placeholder="Full Rich Text Story Content..." data-bf="content"
              class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:outline-none resize-none">${escapeHtml(state.blogForm.content || '')}</textarea>

            <label class="flex items-center gap-2 cursor-pointer text-slate-300">
              <input type="checkbox" data-bf="is_published" ${state.blogForm.is_published ? 'checked' : ''} class="rounded bg-slate-800 border-slate-600 text-amber-500">
              <span>Publish publicly to Blog Space immediately</span>
            </label>

            <button type="submit" class="w-full py-4 rounded-xl bg-amber-400 hover:bg-amber-300 text-slate-950 font-black uppercase tracking-wider">Save Article Pipeline</button>
          </form>
        </div>
      </div>`;

    modalRoot.querySelectorAll('[data-close-modal]').forEach((b) => b.addEventListener('click', closeModal));

    bindImageUploads(modalRoot, 'bf', 'bf');

    modalRoot.querySelector('#roi-blog-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      readFormFields('#roi-blog-form', 'bf');
      try {
        if (state.editingBlog) {
          await request('PUT', `/admin/blog/${state.editingBlog.id}`, state.blogForm).catch(() => {});
          state.blogs = state.blogs.map((b) => (b.id === state.editingBlog.id ? { ...b, ...state.blogForm } : b));
          showToast('Article successfully updated in public blog space.');
        } else {
          const newB = { ...state.blogForm, id: Date.now(), slug: String(state.blogForm.title).toLowerCase().replace(/\s+/g, '-'), created_at: new Date().toISOString() };
          await request('POST', '/admin/blog', state.blogForm).catch(() => {});
          state.blogs = [newB, ...state.blogs];
          showToast('New story published to DEMO platform.');
        }
        closeModal();
        paint();
      } catch (err) {}
    });
  }

  function openEventModal() {
    modalRoot.innerHTML = `
      <div class="fixed inset-0 z-[170] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fadeIn">
        <div class="bg-slate-900 border border-slate-700 w-full max-w-lg rounded-3xl p-6 sm:p-8 space-y-6 relative max-h-[90vh] overflow-y-auto">
          <button type="button" data-close-modal class="absolute top-6 right-6 text-slate-400 hover:text-white">${icon('x', 'w-5 h-5')}</button>
          <h3 class="text-xl font-black text-white">${state.editingEvent ? 'Change Event Details' : 'Append Upcoming Activity'}</h3>

          <form id="roi-event-form" class="space-y-4 text-xs">
            <input type="text" required placeholder="Event Title (e.g. Youth Leadership Summit 2026)" value="${escapeHtml(state.eventForm.title)}" data-ef="title"
              class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm focus:outline-none">
            <div class="grid grid-cols-2 gap-3">
              <input type="text" required placeholder="Date String (e.g. Aug 14-16)" value="${escapeHtml(state.eventForm.date)}" data-ef="date"
                class="px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:outline-none">
              <select data-ef="category" class="px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:outline-none">
                ${['Flagship Conference', 'Workshop', 'Outreach'].map(
                  (c) => `<option value="${c}" ${state.eventForm.category === c ? 'selected' : ''}>${c}</option>`
                ).join('')}
              </select>
            </div>
            <input type="text" placeholder="Location (e.g. the community hub, Harbor City)" value="${escapeHtml(state.eventForm.location)}" data-ef="location"
              class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:outline-none">
            <textarea rows="4" required placeholder="Event Description..." data-ef="description"
              class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:outline-none resize-none">${escapeHtml(state.eventForm.description || '')}</textarea>
            <div class="space-y-2">
              <label class="block text-slate-400 font-bold uppercase text-[10px] tracking-wider">Event Image</label>
              <div data-preview-for="ef" class="max-w-xs">
                ${state.eventForm.image_url
                  ? `<img src="${escapeHtml(state.eventForm.image_url)}" class="h-24 w-full object-cover rounded-xl border border-slate-700">`
                  : `<div class="h-24 w-full rounded-xl border border-dashed border-slate-700 flex items-center justify-center text-slate-500 text-[10px]">No image</div>`}
              </div>
              <input type="file" accept="image/*" data-upload-for="ef"
                class="w-full text-xs text-slate-300 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-sky-500 file:text-white file:font-bold">
              <input type="hidden" data-ef="image_url" value="${escapeHtml(state.eventForm.image_url || '')}">
            </div>
            <input type="number" min="1" placeholder="Event capacity (blank = unlimited)" value="${escapeHtml(String(state.eventForm.capacity || ''))}" data-ef="capacity"
              class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:outline-none">
            <label class="flex items-center gap-2 text-slate-300">
              <input type="checkbox" data-ef="ticket_sales_enabled" ${state.eventForm.ticket_sales_enabled !== false ? 'checked' : ''}> Ticket sales enabled
            </label>

            <button type="submit" class="w-full py-4 rounded-xl bg-sky-500 hover:bg-sky-400 text-white font-black uppercase tracking-wider">Confirm Operational Schedule</button>
          </form>
        </div>
      </div>`;

    modalRoot.querySelectorAll('[data-close-modal]').forEach((b) => b.addEventListener('click', closeModal));

    bindImageUploads(modalRoot, 'ef', 'ef');

    modalRoot.querySelector('#roi-event-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      readFormFields('#roi-event-form', 'ef');
      if (state.editingEvent) {
        await request('PUT', `/admin/events/${state.editingEvent.id}`, state.eventForm).catch(() => {});
        state.eventsList = state.eventsList.map((ev) => (ev.id === state.editingEvent.id ? { ...ev, ...state.eventForm } : ev));
        showToast('Event schedule modified.');
      } else {
        const newE = { ...state.eventForm, id: Date.now() };
        await request('POST', '/admin/events', state.eventForm).catch(() => {});
        state.eventsList = [newE, ...state.eventsList];
        showToast('New activity appended to calendar.');
      }
      closeModal();
      paint();
    });
  }

  function openTicketModal() {
    const eventOpts = state.eventsList.map(
      (ev) => `<option value="${ev.id}" ${String(state.ticketForm.event_id) === String(ev.id) ? 'selected' : ''}>${escapeHtml(ev.title)}</option>`
    ).join('');
    modalRoot.innerHTML = `
      <div class="fixed inset-0 z-[170] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fadeIn">
        <div class="bg-slate-900 border border-slate-700 w-full max-w-lg rounded-3xl p-6 sm:p-8 space-y-6 relative max-h-[90vh] overflow-y-auto">
          <button type="button" data-close-modal class="absolute top-6 right-6 text-slate-400 hover:text-white">${icon('x', 'w-5 h-5')}</button>
          <h3 class="text-xl font-black text-white">${state.editingTicketType ? 'Edit ticket type' : 'New ticket type'}</h3>
          <form id="roi-ticket-form" class="space-y-4 text-xs">
            <select data-tf="event_id" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">${eventOpts}</select>
            <input required placeholder="Name" value="${escapeHtml(state.ticketForm.name || '')}" data-tf="name" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">
            <textarea rows="2" placeholder="Description" data-tf="description" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">${escapeHtml(state.ticketForm.description || '')}</textarea>
            <div class="grid grid-cols-2 gap-3">
              <input type="number" min="0" required placeholder="Price" value="${escapeHtml(String(state.ticketForm.price || '0'))}" data-tf="price" class="px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">
              <input type="number" min="1" placeholder="Qty (blank=∞)" value="${escapeHtml(String(state.ticketForm.quantity || ''))}" data-tf="quantity" class="px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">
            </div>
            <input type="number" min="1" max="20" placeholder="Max per email (total)" value="${escapeHtml(String(state.ticketForm.max_per_order || '10'))}" data-tf="max_per_order" aria-describedby="tf-max-per-email-help" class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white">
            <p id="tf-max-per-email-help" class="text-slate-400 mt-1">Per order, and as a cumulative total across all of that email's orders.</p>
            <div class="grid grid-cols-2 gap-3">
              <label class="text-slate-400">Sales start (EAT)<input type="datetime-local" value="${escapeHtml(state.ticketForm.sales_start || '')}" data-tf="sales_start" class="mt-1 w-full px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white"></label>
              <label class="text-slate-400">Sales end (EAT)<input type="datetime-local" value="${escapeHtml(state.ticketForm.sales_end || '')}" data-tf="sales_end" class="mt-1 w-full px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white"></label>
            </div>
            <label class="flex items-center gap-2 text-slate-300"><input type="checkbox" data-tf="is_active" ${state.ticketForm.is_active ? 'checked' : ''}> Active</label>
            <button type="submit" class="w-full py-4 rounded-xl bg-amber-400 text-slate-950 font-black uppercase">Save ticket type</button>
          </form>
        </div>
      </div>`;
    modalRoot.querySelectorAll('[data-close-modal]').forEach((b) => b.addEventListener('click', closeModal));
    modalRoot.querySelector('#roi-ticket-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = modalRoot.querySelector('#roi-ticket-form');
      const payload = {};
      form.querySelectorAll('[data-tf]').forEach((input) => {
        payload[input.dataset.tf] = input.type === 'checkbox' ? input.checked : input.value;
      });
      payload.event_id = Number(payload.event_id);
      payload.price = Number(payload.price);
      payload.max_per_order = Number(payload.max_per_order || 10);
      payload.quantity = payload.quantity === '' ? null : Number(payload.quantity);
      payload.sales_start = payload.sales_start ? (eatToUtc(payload.sales_start) ?? payload.sales_start) : null;
      payload.sales_end = payload.sales_end ? (eatToUtc(payload.sales_end) ?? payload.sales_end) : null;
      payload.currency = 'KES';
      try {
        if (state.editingTicketType) {
          const res = await request('PUT', `/admin/ticket-types/${state.editingTicketType.id}`, payload);
          if (res.data) state.ticketTypes = state.ticketTypes.map((t) => (t.id === state.editingTicketType.id ? res.data : t));
          showToast('Ticket type updated.');
        } else {
          const res = await request('POST', '/admin/ticket-types', payload);
          if (res.data) state.ticketTypes = [res.data, ...state.ticketTypes];
          showToast('Ticket type created.');
        }
        closeModal();
        paint();
      } catch (err) {
        showToast(err.response?.data?.detail || 'Could not save ticket type.');
      }
    });
  }

  function openLeaderModal() {
    modalRoot.innerHTML = `
      <div class="fixed inset-0 z-[170] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fadeIn">
        <div class="bg-slate-900 border border-slate-700 w-full max-w-lg rounded-3xl p-6 sm:p-8 space-y-6 relative max-h-[90vh] overflow-y-auto">
          <button type="button" data-close-modal class="absolute top-6 right-6 text-slate-400 hover:text-white">${icon('x', 'w-5 h-5')}</button>
          <h3 class="text-xl font-black text-white">${state.editingLeader ? 'Change Steward Details' : 'Add New Steward'}</h3>

          <form id="roi-leader-form" class="space-y-4 text-xs">
            <input type="text" required placeholder="Name (e.g. Fatuma Bakari)" value="${escapeHtml(state.leaderForm.name)}" data-lf="name"
              class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm focus:outline-none">
            <input type="text" required placeholder="Role (e.g. Lead Coordinator & Founder)" value="${escapeHtml(state.leaderForm.role)}" data-lf="role"
              class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:outline-none">
            <textarea rows="4" required placeholder="Biography..." data-lf="bio"
              class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white focus:outline-none resize-none">${escapeHtml(state.leaderForm.bio)}</textarea>

            <button type="submit" class="w-full py-4 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-white font-black uppercase tracking-wider">Save Steward Profile</button>
          </form>
        </div>
      </div>`;

    modalRoot.querySelectorAll('[data-close-modal]').forEach((b) => b.addEventListener('click', closeModal));

    modalRoot.querySelector('#roi-leader-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      readFormFields('#roi-leader-form', 'lf');
      if (state.editingLeader) {
        await request('PUT', `/admin/leaders/${state.editingLeader.id}`, state.leaderForm).catch(() => {});
        state.leadersList = state.leadersList.map((ld) => (ld.id === state.editingLeader.id ? { ...ld, ...state.leaderForm } : ld));
        showToast('Steward details modified.');
      } else {
        const newL = { ...state.leaderForm, id: Date.now() };
        await request('POST', '/admin/leaders', state.leaderForm).catch(() => {});
        state.leadersList = [...state.leadersList, newL];
        showToast('New steward added.');
      }
      closeModal();
      paint();
    });
  }

  /** Reads inputs tagged data-<prefix> into the corresponding state form object. */
  function readFormFields(formSelector, prefix) {
    const form = modalRoot.querySelector(formSelector);
    form.querySelectorAll(`[data-${prefix}]`).forEach((input) => {
      const key = input.dataset[prefix];
      if (input.type === 'checkbox') {
        state[`${prefix === 'bf' ? 'blogForm' : prefix === 'ef' ? 'eventForm' : 'leaderForm'}`][key] = input.checked;
      } else {
        state[`${prefix === 'bf' ? 'blogForm' : prefix === 'ef' ? 'eventForm' : 'leaderForm'}`][key] = input.value;
      }
    });
  }

  // -------------------------------------------------------------- Logout

  root.querySelector('#roi-logout').addEventListener('click', () => {
    logout();
    navigate('/admin');
    showToast('Singular administrator session securely closed.');
  });

  paintNav();
  fetchDashboardData();
}
