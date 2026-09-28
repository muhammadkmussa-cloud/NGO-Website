// Footer — vanilla port of components/Footer.jsx (4-column grid, social SVGs, compliance bar).
import { ROI_LOGO_DATA_URI } from '../assets/logoDataUri.js';
import { icon, facebookIcon, instagramIcon, youtubeIcon, tiktokIcon } from '../icons.js';

export function renderFooter(container) {
  container.innerHTML = `
    <footer class="bg-slate-950 border-t border-slate-800 text-slate-400 text-sm pt-16 pb-8">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10 pb-12 border-b border-slate-800/80">

          <div class="space-y-4">
            <div class="flex items-center space-x-3">
              <img src="${ROI_LOGO_DATA_URI}" alt="ROI Official Logo" class="h-10 sm:h-12 w-auto object-contain drop-shadow">
            </div>
            <p class="text-xs leading-relaxed text-slate-400 pt-1">
              Dedicated to uplifting vulnerable youth across coastal Kenya through ethical mentorship, digital education, and impactful social transformation programs anchored in community solidarity.
            </p>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-900 border border-slate-800 text-[11px] text-sky-400">
              <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
              <span>Flagship Asset: Vijana Na Maadili</span>
            </div>
          </div>

          <div class="space-y-3">
            <h3 class="text-xs font-bold uppercase tracking-wider text-white border-l-2 border-sky-500 pl-2">Main Links</h3>
            <ul class="space-y-2 text-xs">
              <li><a href="#/" class="hover:text-sky-400 transition-colors block">Home</a></li>
              <li><a href="#/about" class="hover:text-sky-400 transition-colors block">About Us</a></li>
              <li><a href="#/events" class="hover:text-sky-400 transition-colors block">Events &amp; Conferences</a></li>
              <li><a href="#/solutions" class="hover:text-sky-400 transition-colors block">Digital Solutions</a></li>
              <li><a href="#/solutions/portfolio" class="hover:text-sky-400 transition-colors block">Solutions Portfolio</a></li>
              <li><a href="#/blog" class="hover:text-sky-400 transition-colors block">Blog &amp; Field Stories</a></li>
              <li><a href="#/media" class="hover:text-sky-400 transition-colors block">Reaching Out Media Hub</a></li>
              <li><a href="#/contact" class="hover:text-sky-400 transition-colors block">Contacts &amp; Inquiries</a></li>
              <li><a href="#/status" class="hover:text-sky-400 transition-colors block">Platform status</a></li>
              <li><a href="#/tickets/recover" class="hover:text-sky-400 transition-colors block">Find my tickets</a></li>
            </ul>
          </div>

          <div class="space-y-3">
            <h3 class="text-xs font-bold uppercase tracking-wider text-white border-l-2 border-amber-500 pl-2">Direct Contact</h3>
            <div class="space-y-2.5 text-xs">
              <div class="flex items-start gap-2.5">
                ${icon('map-pin', 'w-4 h-4 text-amber-500 shrink-0 mt-0.5')}
                <div>
                  <span class="text-white font-medium block">Region</span>
                  <span>Mombasa, Kenya (Coastal HQ)</span>
                </div>
              </div>

              <div class="flex items-center gap-2.5">
                ${icon('mail', 'w-4 h-4 text-sky-400 shrink-0')}
                <a href="mailto:reachingoutinitiative2021@gmail.com" class="hover:text-white transition-colors truncate">
                  reachingoutinitiative2021@gmail.com
                </a>
              </div>

              <div class="flex items-center gap-2.5">
                ${icon('phone', 'w-4 h-4 text-emerald-400 shrink-0')}
                <div class="flex flex-col">
                  <a href="tel:+254745273556" class="hover:text-white transition-colors">+254 745 273 556</a>
                  <a href="tel:+254734292124" class="hover:text-white transition-colors">+254 734 292 124</a>
                </div>
              </div>
            </div>
          </div>

          <div class="space-y-3">
            <h3 class="text-xs font-bold uppercase tracking-wider text-white border-l-2 border-emerald-500 pl-2">Verified Social Channels</h3>
            <p class="text-xs text-slate-400">
              Connect with ROI Media across our verified digital endpoints:
            </p>

            <div class="flex flex-wrap gap-3 pt-2">
              <a href="https://www.facebook.com/share/1D2APpFpRf/?mibextid=wwXIfr" target="_blank" rel="noreferrer"
                 title="Official Facebook Page"
                 class="w-10 h-10 rounded-xl bg-slate-900 border border-slate-800 flex items-center justify-center text-slate-300 hover:text-white hover:bg-blue-600 hover:border-blue-500 transition-colors transform hover:scale-110 shadow-lg">
                ${facebookIcon()}
              </a>

              <a href="https://www.instagram.com/reachingoutinitiative.ke?igsh=MWdlejI2ZTBqbGdjbg==" target="_blank" rel="noreferrer"
                 title="Official Instagram Profile"
                 class="w-10 h-10 rounded-xl bg-slate-900 border border-slate-800 flex items-center justify-center text-slate-300 hover:text-white hover:bg-gradient-to-tr hover:from-amber-500 hover:via-rose-600 hover:to-purple-600 transition-colors transform hover:scale-110 shadow-lg">
                ${instagramIcon()}
              </a>

              <a href="https://youtube.com/@reachingoutinitiativemedia?si=-eGVYbZPPU8ONGkT" target="_blank" rel="noreferrer"
                 title="Reaching Out Initiative Media YouTube"
                 class="w-10 h-10 rounded-xl bg-slate-900 border border-slate-800 flex items-center justify-center text-slate-300 hover:text-white hover:bg-red-600 hover:border-red-500 transition-colors transform hover:scale-110 shadow-lg">
                ${youtubeIcon()}
              </a>

              <a href="https://www.tiktok.com/@reachingoutinitiative.ke?_r=1&_t=ZS-97VFl5MuWkZ" target="_blank" rel="noreferrer"
                 title="Official TikTok Profile"
                 class="w-10 h-10 rounded-xl bg-slate-900 border border-slate-800 flex items-center justify-center text-slate-300 hover:text-white hover:bg-black hover:border-cyan-400 transition-colors transform hover:scale-110 shadow-lg">
                ${tiktokIcon()}
              </a>
            </div>
          </div>
        </div>

        <div class="pt-8 flex flex-col md:flex-row items-center justify-between text-xs text-slate-500 gap-4">
          <div class="flex items-center space-x-6">
            <span class="hover:text-slate-400 cursor-pointer">Privacy Policy</span>
            <span class="hover:text-slate-400 cursor-pointer">Terms of Service</span>
            <span class="text-slate-600">|</span>
            <span>Registration: CBO/MSA/2021/8841</span>
          </div>

          <div class="font-medium text-slate-400">
            © 2026 Reaching Out Initiative. All Rights Reserved.
          </div>
        </div>

      </div>
    </footer>`;
}
