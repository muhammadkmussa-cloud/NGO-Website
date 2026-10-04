// Volunteer page — vanilla port of pages/Volunteer.jsx (skill picker, availability
// checkboxes, PRD 4.3 confirmation overlay shown on success OR error).
import { submitVolunteer } from '../api.js';
import { icon } from '../icons.js';
import { escapeHtml, showToast } from '../ui.js';

const SKILLS = [
  'Mentorship',
  'Event Coordination',
  'Graphic Design',
  'Photography & Videography',
  'Teaching & Tech Literacy',
  'Logistics & Community Outreach'
];

const AVAIL_OPTIONS = [
  'Weekends (Outreach & Bootcamps)',
  'Weekdays (Remote & Planning)',
  'Youth Leadership Summit Annual Conference Special'
];

export function renderVolunteer(root) {
  const state = {
    primarySkill: 'Mentorship',
    availability: [],
    loading: false,
    successOverlay: false,
    lastSkillAtSubmit: 'Mentorship'
  };

  root.innerHTML = `
    <div class="py-16 sm:py-24 bg-slate-900 min-h-screen space-y-16">

      <div class="max-w-7xl px-4 sm:px-6 lg:px-8 text-center max-w-3xl mx-auto space-y-4">
        <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-sky-500/10 border border-sky-500/30 text-sky-400 text-xs font-bold uppercase tracking-widest">
          ${icon('users', 'w-4 h-4')}
          <span>Volunteer Registry Pipeline</span>
        </div>
        <h1 class="text-4xl sm:text-6xl font-black text-white">
          Join the Harbor City Changemaker Network
        </h1>
        <p class="text-base sm:text-lg text-slate-300 leading-relaxed font-normal">
          Lend your unique skills to mentor Harbor City youth, coordinate our flagship <strong class="text-white">Youth Leadership Summit</strong> conference, and drive grassroots transformation.
        </p>
      </div>

      <div class="max-w-3xl mx-auto px-4 sm:px-6">
        <div class="bg-slate-800/80 border border-slate-700 p-8 sm:p-12 rounded-3xl shadow-2xl relative">

          <form id="roi-vol-form" class="space-y-8">

            <div class="space-y-4">
              <h3 class="text-sm font-extrabold uppercase tracking-widest text-amber-400 border-l-2 border-amber-400 pl-3">1. Personal Credentials</h3>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="text-xs text-slate-400 font-bold block mb-1.5">Full Name *</label>
                  <input type="text" id="roi-vol-name" required placeholder="e.g. Salim Hassan"
                    class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-base focus:outline-none focus:border-sky-500">
                </div>
                <div>
                  <label class="text-xs text-slate-400 font-bold block mb-1.5">Email Address *</label>
                  <input type="email" id="roi-vol-email" required placeholder="salim@gmail.com"
                    class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-base focus:outline-none focus:border-sky-500">
                </div>
              </div>

              <div>
                <label class="text-xs text-slate-400 font-bold block mb-1.5">WhatsApp / Phone Number *</label>
                <input type="tel" id="roi-vol-phone" required placeholder="+254 7..."
                  class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-base focus:outline-none focus:border-sky-500">
              </div>
            </div>

            <div class="space-y-4">
              <h3 class="text-sm font-extrabold uppercase tracking-widest text-sky-400 border-l-2 border-sky-400 pl-3">2. Primary Skill Contribution *</h3>
              <div id="roi-vol-skills" class="grid grid-cols-1 sm:grid-cols-2 gap-3"></div>
            </div>

            <div class="space-y-4">
              <h3 class="text-sm font-extrabold uppercase tracking-widest text-emerald-400 border-l-2 border-emerald-400 pl-3">3. Availability Windows *</h3>
              <div id="roi-vol-avail" class="space-y-3"></div>
            </div>

            <div class="space-y-4">
              <h3 class="text-sm font-extrabold uppercase tracking-widest text-purple-400 border-l-2 border-purple-400 pl-3">4. Brief Motivation Statement</h3>
              <textarea rows="4" id="roi-vol-motivation" placeholder="Why do you want to volunteer with Demo NGO in Harbor City?"
                class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-base focus:outline-none focus:border-purple-400 resize-none"></textarea>
            </div>

            <button type="submit" id="roi-vol-submit"
              class="w-full py-4 rounded-2xl bg-gradient-to-r from-sky-500 via-blue-600 to-sky-500 hover:from-sky-400 hover:to-blue-500 text-white font-black text-sm uppercase tracking-wider shadow-2xl shadow-sky-500/30 transition-colors transform hover:scale-[1.01]">
              Submit Changemaker Application
            </button>
          </form>
        </div>
      </div>

      <div id="roi-vol-overlay-root"></div>
    </div>`;

  const skillsEl = root.querySelector('#roi-vol-skills');
  const availEl = root.querySelector('#roi-vol-avail');
  const overlayRoot = root.querySelector('#roi-vol-overlay-root');
  const submitBtn = root.querySelector('#roi-vol-submit');

  function paintSkills() {
    skillsEl.innerHTML = SKILLS.map(
      (skill) => `
      <button type="button" data-skill="${escapeHtml(skill)}"
        class="p-4 rounded-2xl border text-left flex items-center justify-between transition-colors ${
          state.primarySkill === skill
            ? 'bg-sky-500/20 border-sky-500 text-white font-bold shadow-lg shadow-sky-500/10'
            : 'bg-slate-900/60 border-slate-700 text-slate-300 hover:border-slate-600'
        }">
        <span class="text-xs">${escapeHtml(skill)}</span>
        ${state.primarySkill === skill ? icon('check-circle-2', 'w-4 h-4 text-sky-400 shrink-0 ml-2') : ''}
      </button>`
    ).join('');
    skillsEl.querySelectorAll('[data-skill]').forEach((b) =>
      b.addEventListener('click', () => {
        state.primarySkill = b.dataset.skill;
        paintSkills();
      })
    );
  }

  function paintAvailability() {
    availEl.innerHTML = AVAIL_OPTIONS.map(
      (opt) => `
      <label data-opt="${escapeHtml(opt)}"
        tabindex="0" role="checkbox" aria-checked="${state.availability.includes(opt)}"
        class="p-4 rounded-2xl border flex items-center gap-3.5 cursor-pointer transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 ${
          state.availability.includes(opt)
            ? 'bg-emerald-500/10 border-emerald-500 text-emerald-300 font-bold'
            : 'bg-slate-900/60 border-slate-700 text-slate-300 hover:border-slate-600'
        }">
        <input type="checkbox" ${state.availability.includes(opt) ? 'checked' : ''} tabindex="-1"
          class="w-4 h-4 rounded text-emerald-500 focus:ring-0 bg-slate-800 border-slate-600 pointer-events-none">
        <span class="text-xs">${escapeHtml(opt)}</span>
      </label>`
    ).join('');
    availEl.querySelectorAll('[data-opt]').forEach((label) => {
      label.addEventListener('keydown', (e) => {
        if (e.key !== ' ' && e.key !== 'Enter') return;
        e.preventDefault();
        toggleAvailability(label);
      });
      label.addEventListener('click', (e) => {
        // Fix: label-activation forwards a synthetic click whose target is the
        // checkbox — handling it here toggled every selection twice (net zero),
        // making the form impossible to submit via mouse/touch.
        if (e.target.matches('input')) return; // ignore label-forwarded synthetic activation
        toggleAvailability(label);
      });
    });

    function toggleAvailability(label) {
      const opt = label.dataset.opt;
      if (state.availability.includes(opt)) {
        state.availability = state.availability.filter((a) => a !== opt);
      } else {
        state.availability = [...state.availability, opt];
      }
      paintAvailability();
    }
  }

  function showSuccessOverlay() {
    overlayRoot.innerHTML = `
      <div class="fixed inset-0 z-[160] flex items-center justify-center p-4 bg-slate-950/90 backdrop-blur-md animate-fadeIn">
        <div class="bg-slate-900 border-2 border-sky-500 max-w-lg w-full rounded-3xl p-8 sm:p-10 text-center space-y-6 shadow-2xl relative animate-scaleUp">
          <button type="button" id="roi-vol-overlay-close" class="absolute top-6 right-6 text-slate-400 hover:text-white">${icon('x', 'w-5 h-5')}</button>
          <div class="w-20 h-20 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center mx-auto border border-emerald-500/40">
            ${icon('heart-handshake', 'w-10 h-10 animate-bounce')}
          </div>
          <h3 class="text-2xl sm:text-3xl font-black text-white">Application Successfully Recorded!</h3>
          <p class="text-xs sm:text-sm text-slate-300 leading-relaxed font-normal">
            Thank you for stepping up! Your volunteer application has been written directly to the centralized database table. Our single administrator will review your skills (${escapeHtml(state.lastSkillAtSubmit)}) and coordinate your deployment for upcoming Harbor City outreach.
          </p>
          <button type="button" id="roi-vol-return"
            class="w-full py-4 rounded-xl bg-sky-500 hover:bg-sky-400 text-white font-extrabold text-xs uppercase tracking-wider">Return to Community Portal</button>
        </div>
      </div>`;
    const close = () => (overlayRoot.innerHTML = '');
    overlayRoot.querySelector('#roi-vol-overlay-close').addEventListener('click', close);
    overlayRoot.querySelector('#roi-vol-return').addEventListener('click', close);
  }

  root.querySelector('#roi-vol-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fullName = root.querySelector('#roi-vol-name').value.trim();
    const email = root.querySelector('#roi-vol-email').value.trim();
    const phone = root.querySelector('#roi-vol-phone').value.trim();
    const motivation = root.querySelector('#roi-vol-motivation').value;

    if (!fullName || !email || !phone || state.availability.length === 0) {
      window.alert('Please fill in all required fields and select at least one availability window.');
      return;
    }

    state.loading = true;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting Asynchronous Registry...';

    try {
      await submitVolunteer({
        full_name: fullName,
        email,
        phone,
        primary_skill: state.primarySkill,
        availability: state.availability.join(', '),
        motivation
      });
      state.loading = false;
      state.lastSkillAtSubmit = state.primarySkill;
      state.successOverlay = true;

      // Reset form fields (mirrors React state resets)
      root.querySelector('#roi-vol-name').value = '';
      root.querySelector('#roi-vol-email').value = '';
      root.querySelector('#roi-vol-phone').value = '';
      root.querySelector('#roi-vol-motivation').value = '';
      state.availability = [];
      paintAvailability();

      submitBtn.disabled = false;
      submitBtn.textContent = 'Submit Changemaker Application';
      showSuccessOverlay();
    } catch (err) {
      state.loading = false;
      submitBtn.disabled = false;
      submitBtn.textContent = 'Submit Changemaker Application';
      showToast('Submission failed: we could not reach the server. Please try again shortly.');
      console.error('[DEMO] volunteer submission failed:', err);
    }
  });

  paintSkills();
  paintAvailability();
}
