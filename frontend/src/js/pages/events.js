// Events page — vanilla port of pages/Events.jsx + components/EventCard.jsx.
// Includes category tabs, PRD-mandated empty fallback, and the client-side RSVP modal.
import { getEvents } from '../api.js';
import { navigate } from '../router.js';
import { icon } from '../icons.js';
import { escapeHtml, imageWithFallback, showToast } from '../ui.js';

const CATEGORIES = ['All', 'Flagship Conference', 'Workshop', 'Outreach'];

function eventCard(evt) {
  return `
<div data-event-id="${evt.id}"
        class="bg-slate-900 border border-slate-800 rounded-3xl overflow-hidden shadow-xl hover:border-sky-500 transition-colors transform duration-300 flex flex-col group hover-scale">
      <div class="relative aspect-[16/9] bg-slate-950 overflow-hidden">
        ${imageWithFallback(
          evt.image_url || 'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=800&q=80',
          evt.title,
          'w-full h-full object-cover group-hover:scale-105 transition-transform duration-700',
          evt.category || 'Conference'
        )}
        <div class="absolute top-4 left-4 bg-slate-900/95 px-3 py-1 rounded-full border border-slate-700 text-[11px] font-bold text-amber-400">
          ${escapeHtml(evt.category || 'Conference')}
        </div>
      </div>

      <div class="p-6 flex-1 flex flex-col justify-between space-y-4">
        <div class="space-y-3">
          <div class="flex items-center gap-2 text-xs font-bold text-sky-400">
            ${icon('calendar', 'w-4 h-4 shrink-0')}
            <span>${escapeHtml(evt.date)}</span>
          </div>
          <h3 class="text-lg font-black text-white group-hover:text-amber-400 transition-colors leading-snug">${escapeHtml(evt.title)}</h3>
          <p id="roi-desc-${evt.id}" class="text-xs text-slate-300 leading-relaxed line-clamp-3">${escapeHtml(evt.description)}</p>
          <button type="button" data-more="${evt.id}" aria-expanded="false" aria-controls="roi-desc-${evt.id}"
            class="hidden text-xs font-bold text-sky-400 hover:text-sky-300 transition-colors">More</button>
        </div>

        <div class="pt-4 border-t border-slate-800 space-y-3 text-xs text-slate-400">
          <div class="flex items-center gap-2">
            ${icon('clock', 'w-3.5 h-3.5 text-slate-500 shrink-0')}
            <span>${escapeHtml(evt.time || '09:00 AM EAT')}</span>
          </div>
          <div class="flex items-center gap-2">
            ${icon('map-pin', 'w-3.5 h-3.5 text-slate-500 shrink-0')}
            <span class="truncate">${escapeHtml(evt.location || 'Harbor City, Kenya')}</span>
          </div>

          <button data-tickets="${evt.id}"
            class="w-full mt-2 py-3 rounded-xl bg-gradient-to-r from-amber-400 to-amber-500 hover:from-amber-300 hover:to-amber-400 text-slate-950 font-black text-xs transition-colors flex items-center justify-center gap-1.5">
            <span>Get Tickets</span>
            ${icon('arrow-up-right', 'w-4 h-4')}
          </button>
          <button data-rsvp="${evt.id}"
            class="w-full py-3 rounded-xl bg-slate-800 hover:bg-sky-600 text-slate-200 hover:text-white font-bold text-xs transition-colors flex items-center justify-center gap-1.5">
            <span>RSVP / Inquire About Event</span>
            ${icon('arrow-up-right', 'w-4 h-4')}
          </button>
        </div>
      </div>
    </div>`;
}

export function renderEvents(root) {
  let events = [];
  let loading = true;
  let category = 'All';

  root.innerHTML = `
    <div class="py-16 sm:py-24 bg-slate-900 min-h-screen space-y-16">

      <div class="max-w-7xl px-4 sm:px-6 lg:px-8 text-center max-w-3xl mx-auto space-y-4">
        <h1 class="text-4xl sm:text-6xl font-black text-white">
          Community Events & Youth Conferences in Harbor City
        </h1>
        <p class="text-base sm:text-lg text-slate-300 leading-relaxed">
          Join our youth workshops, tech bootcamps, community cleanups, and our annual flagship <strong class="text-white">Youth Leadership Summit</strong> youth empowerment summit.
          <a href="#/tickets/recover" class="block text-sky-400 font-bold text-sm pt-2">Already booked? Find my tickets</a>
        </p>

        <div id="roi-event-tabs" class="flex flex-wrap items-center justify-center gap-2 pt-6"></div>
      </div>

      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div id="roi-events-body" class="grid grid-cols-1 md:grid-cols-3 gap-8">
          <div class="bg-slate-800 animate-pulse aspect-[4/5] rounded-3xl"></div>
          <div class="bg-slate-800 animate-pulse aspect-[4/5] rounded-3xl"></div>
          <div class="bg-slate-800 animate-pulse aspect-[4/5] rounded-3xl"></div>
        </div>
      </div>

      <div id="roi-rsvp-root"></div>
    </div>`;

  const tabsEl = root.querySelector('#roi-event-tabs');
  const bodyEl = root.querySelector('#roi-events-body');

  function filteredEvents() {
    return events.filter(
      (e) => category === 'All' || e.category === category || (category === 'Flagship Conference' && String(e.title).includes('Youth Leadership Summit'))
    );
  }

  function paintTabs() {
    tabsEl.innerHTML = CATEGORIES.map(
      (cat) => `
      <button data-cat="${escapeHtml(cat)}" class="px-4 py-2 rounded-xl text-xs font-bold transition-colors transition-shadow transform ${
        category === cat
          ? 'bg-gradient-to-r from-sky-600 to-sky-500 text-white shadow-lg shadow-sky-500/20 scale-105'
          : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
      }">${cat}</button>`
    ).join('');
    tabsEl.querySelectorAll('[data-cat]').forEach((b) =>
      b.addEventListener('click', () => {
        category = b.dataset.cat;
        paintTabs();
        paintBody();
      })
    );
  }

  function paintBody() {
    const list = filteredEvents();
    if (!loading && list.length === 0) {
      // Friendly Fallback View mandated by PRD 3.2
      bodyEl.className = '';
      bodyEl.innerHTML = `
        <div class="bg-slate-800/60 border border-slate-700/80 rounded-3xl p-12 text-center max-w-lg mx-auto space-y-4 animate-fadeIn">
          <div class="w-16 h-16 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center mx-auto">
            ${icon('alert-circle', 'w-8 h-8')}
          </div>
          <h3 class="text-xl font-bold text-white">No Upcoming Events</h3>
          <p class="text-sm text-slate-300 leading-relaxed">There are no events at the moment. Check back soon!</p>
          <button id="roi-reset-category" class="px-5 py-2.5 rounded-xl bg-sky-500 hover:bg-sky-400 text-white font-bold text-xs">View All Past &amp; Active Schedules</button>
        </div>`;
      bodyEl.querySelector('#roi-reset-category').addEventListener('click', () => {
        category = 'All';
        paintTabs();
        paintBody();
      });
      return;
    }
    bodyEl.className = 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8';
    bodyEl.innerHTML = list.map(eventCard).join('');
    bodyEl.querySelectorAll('[data-tickets]').forEach((btn) =>
      btn.addEventListener('click', () => navigate(`/tickets/${btn.dataset.tickets}`))
    );
    bodyEl.querySelectorAll('[data-rsvp]').forEach((btn) =>
      btn.addEventListener('click', () => {
        const evt = events.find((e) => String(e.id) === btn.dataset.rsvp);
        if (evt) openRsvpModal(evt);
      })
    );
    bodyEl.querySelectorAll('[data-more]').forEach((btn) => {
      const p = bodyEl.querySelector(`#roi-desc-${btn.dataset.more}`);
      if (!p || p.scrollHeight <= p.clientHeight + 1) return;
      btn.classList.remove('hidden');
      btn.addEventListener('click', () => {
        const expanded = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', String(!expanded));
        p.classList.toggle('line-clamp-3', expanded);
        btn.textContent = expanded ? 'More' : 'Less';
      });
    });
  }

  function openRsvpModal(evt) {
    const rsvpRoot = root.querySelector('#roi-rsvp-root');
    rsvpRoot.innerHTML = `
      <div class="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm animate-fadeIn">
        <div class="bg-slate-900 border border-slate-700 w-full max-w-md rounded-3xl overflow-hidden shadow-2xl p-6 sm:p-8 relative space-y-6">
          <button type="button" id="roi-rsvp-close" class="absolute top-6 right-6 text-slate-400 hover:text-white">${icon('x', 'w-5 h-5')}</button>
          <div>
            <span class="text-[10px] font-bold uppercase tracking-wider text-sky-400 block">Attendee Registration</span>
            <h3 class="text-xl font-black text-white mt-1 leading-snug">${escapeHtml(evt.title)}</h3>
            <p class="text-xs text-slate-400 mt-2">${escapeHtml(evt.date)} • ${escapeHtml(evt.location)}</p>
          </div>

          <form id="roi-rsvp-form" class="space-y-4">
            <input type="text" id="roi-rsvp-name" placeholder="Your Full Name" required
              class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-xs focus:outline-none focus:border-sky-500">
            <input type="email" id="roi-rsvp-email" placeholder="Email Address for Badge" required
              class="w-full px-4 py-3 rounded-xl bg-slate-800 border border-slate-700 text-white text-xs focus:outline-none focus:border-sky-500">
            <button type="submit"
              class="w-full py-3.5 rounded-xl bg-gradient-to-r from-sky-500 to-blue-600 hover:from-sky-400 hover:to-blue-500 text-white font-black text-xs uppercase tracking-wider shadow-lg">
              Confirm Free Delegate RSVP
            </button>
          </form>
        </div>
      </div>`;

    const close = () => (rsvpRoot.innerHTML = '');
    rsvpRoot.querySelector('#roi-rsvp-close').addEventListener('click', close);
    rsvpRoot.querySelector('#roi-rsvp-form').addEventListener('submit', (e) => {
      e.preventDefault();
      const email = rsvpRoot.querySelector('#roi-rsvp-email').value;
      close(); // Purely client-side confirmation — no API call (exact parity).
      showToast(`RSVP confirmed for ${evt.title}! We have sent attendee information to ${email}.`);
    });
  }

  paintTabs();

  getEvents().then((data) => {
    if (data) events = data;
    loading = false;
    paintBody();
  });
}
