// Contact page — vanilla port of pages/Contact.jsx (inquiry form + info cards + maps iframe).
import { submitContact } from '../api.js';
import { icon } from '../icons.js';
import { showToast } from '../ui.js';

export function renderContact(root) {
  root.innerHTML = `
    <div class="py-16 sm:py-24 bg-slate-900 min-h-screen space-y-20">

      <div class="max-w-7xl px-4 sm:px-6 lg:px-8 text-center max-w-3xl mx-auto space-y-4">
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-bold">
          ${icon('message-square', 'w-3.5 h-3.5')}
          <span>Coastal Communication Desk</span>
        </div>
        <h1 class="text-4xl sm:text-6xl font-black text-white">
          Get in Touch with DEMO Harbor City
        </h1>
        <p class="text-base sm:text-lg text-slate-300 leading-relaxed">
          Have questions about our Youth Leadership Summit summit, youth mentorship cohorts, or partnering with Demo NGO? Our team in Harbor City is ready to assist.
        </p>
      </div>

      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-start">

          <div class="lg:col-span-5 space-y-8 bg-slate-800/80 border border-slate-700 p-8 rounded-3xl">
            <div>
              <h3 class="text-xl font-black text-white">Verified Contact Channels</h3>
              <p class="text-xs text-slate-400 mt-1">Bound to official DEMO production endpoints.</p>
            </div>

            <div class="space-y-6 text-sm">
              <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center shrink-0">${icon('map-pin', 'w-5 h-5')}</div>
                <div>
                  <span class="font-bold text-white block">Physical Operations</span>
                  <span class="text-xs text-slate-300">Harbor City, Kenya (Riverside &amp; the community hub Centers)</span>
                </div>
              </div>

              <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-xl bg-sky-500/20 text-sky-400 flex items-center justify-center shrink-0">${icon('mail', 'w-5 h-5')}</div>
                <div>
                  <span class="font-bold text-white block">Primary Email</span>
                  <a href="mailto:hello@example.org" class="text-xs text-sky-400 hover:underline break-all">hello@example.org</a>
                </div>
              </div>

              <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-xl bg-purple-500/20 text-purple-400 flex items-center justify-center shrink-0">${icon('clock', 'w-5 h-5')}</div>
                <div>
                  <span class="font-bold text-white block">Operational Hours</span>
                  <span class="text-xs text-slate-300">Monday - Friday: 08:30 AM - 05:00 PM EAT</span>
                  <span class="text-xs text-amber-400 block">Weekends: Community Outreach &amp; Mentorship</span>
                </div>
              </div>
            </div>
          </div>

          <div class="lg:col-span-7 bg-slate-800/80 border border-slate-700 p-8 sm:p-10 rounded-3xl">
            <h3 class="text-2xl font-black text-white mb-6">Send an Inquiry to DEMO Desk</h3>

            <form id="roi-contact-form" class="space-y-6">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div>
                  <label class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-2">Your Name</label>
                  <input type="text" id="roi-contact-name" required placeholder="Full Name"
                    class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-base focus:outline-none focus:border-sky-500">
                </div>
                <div>
                  <label class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-2">Email Address</label>
                  <input type="email" id="roi-contact-email" required placeholder="email@domain.com"
                    class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-base focus:outline-none focus:border-sky-500">
                </div>
              </div>

              <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-2">Inquiry Subject</label>
                <select id="roi-contact-subject" class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-base focus:outline-none focus:border-sky-500">
                  <option value="General Inquiry">General Inquiry</option>
                  <option value="Youth Leadership Summit Conference Sponsorship">Youth Leadership Summit Conference Sponsorship</option>
                  <option value="Youth Mentorship Partnership">Youth Mentorship Partnership</option>
                  <option value="Tech Bootcamp Collaboration">Tech Bootcamp Collaboration</option>
                  <option value="Media & Documentary Requests">Media &amp; Documentary Requests</option>
                </select>
              </div>

              <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-2">Your Message</label>
                <textarea rows="5" id="roi-contact-message" required placeholder="Detail your inquiry or collaboration proposal..."
                  class="w-full px-4 py-3 rounded-xl bg-slate-900 border border-slate-700 text-white text-base focus:outline-none focus:border-sky-500 resize-none"></textarea>
              </div>

              <button type="submit" id="roi-contact-submit"
                class="w-full py-4 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-400 hover:to-teal-500 text-slate-950 font-black text-sm uppercase tracking-wider shadow-xl shadow-emerald-500/20 transition-colors flex items-center justify-center gap-2">
                ${icon('send', 'w-4 h-4')}
                <span>Submit Inquiry</span>
              </button>
            </form>
          </div>
        </div>
      </div>

      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-slate-800/80 border border-slate-700 p-4 rounded-3xl overflow-hidden space-y-4">
          <div class="flex items-center justify-between px-4 pt-2">
            <span class="text-xs font-bold uppercase text-amber-400 tracking-wider flex items-center gap-2">
              ${icon('map-pin', 'w-4 h-4')}
              <span>Interactive Operations Center Map</span>
            </span>
            <span class="text-xs text-slate-400">Harbor City</span>
          </div>

          <div class="w-full h-80 sm:h-96 rounded-2xl overflow-hidden bg-slate-950 relative border border-slate-700 flex flex-col items-center justify-center gap-3 text-center px-6">
            ${icon('map-pin', 'w-8 h-8 text-amber-400')}
            <p class="text-sm font-bold text-white">Service area map</p>
            <p class="text-xs text-slate-400 max-w-sm">This showcase runs without a live map embed. Program locations are managed from the admin console.</p>
          </div>
        </div>
      </div>

    </div>`;

  root.querySelector('#roi-contact-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = root.querySelector('#roi-contact-name').value.trim();
    const email = root.querySelector('#roi-contact-email').value.trim();
    const subject = root.querySelector('#roi-contact-subject').value;
    const message = root.querySelector('#roi-contact-message').value;
    const submitBtn = root.querySelector('#roi-contact-submit');
    const label = submitBtn.querySelector('span:last-child');

    if (!name || !email || !message) return;

    submitBtn.disabled = true;
    label.textContent = 'Routing Asynchronous Inquiry...';

    try {
      await submitContact({ name, email, subject, message });
      submitBtn.disabled = false;
      label.textContent = 'Submit Inquiry';
      showToast(`Thank you, ${name}! Your inquiry has been securely routed to hello@example.org.`);
      root.querySelector('#roi-contact-name').value = '';
      root.querySelector('#roi-contact-email').value = '';
      root.querySelector('#roi-contact-message').value = '';
    } catch (err) {
      submitBtn.disabled = false;
      label.textContent = 'Submit Inquiry';
      showToast('Submission failed: we could not reach the server. Please try again shortly.');
      console.error('[DEMO] contact submission failed:', err);
    }
  });
}
