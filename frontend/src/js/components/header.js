// Header — vanilla port of components/Header.jsx (sticky scroll state, pill nav,
// mobile drawer, logo 5-clicks-in-3s easter egg to #/admin).
import { ROI_LOGO_DATA_URI } from '../assets/logoDataUri.js';
import { icon } from '../icons.js';
import { navigate, currentPath } from '../router.js';

const NAV_LINKS = [
  { name: 'Home', path: '/' },
  { name: 'About Us', path: '/about' },
  { name: 'Events', path: '/events' },
  { name: 'Solutions', path: '/solutions' },
  { name: 'Blog', path: '/blog' },
  { name: 'Media Hub', path: '/media' },
  { name: 'Contact', path: '/contact' }
];

export function renderHeader(container, onOpenDonate) {
  let scrolled = false;
  let mobileMenuOpen = false;
  let clickTimes = [];

  container.innerHTML = `
    <header id="roi-header" class="sticky top-0 z-50 overflow-x-hidden transition-colors transition-shadow duration-300 bg-slate-900/80 py-3 sm:py-4 xl:py-5">
      <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 flex items-center justify-between gap-3 min-w-0">

        <a href="#/" id="roi-logo-link" class="flex items-center space-x-3 group shrink-0" aria-label="Demo NGO home">
          <img src="${ROI_LOGO_DATA_URI}" alt="Demo NGO Official Logo"
               class="h-9 sm:h-11 md:h-12 lg:h-14 w-auto object-contain group-hover:scale-105 transition-transform drop-shadow-md">
          <div class="hidden 2xl:flex flex-col border-l border-slate-700/80 pl-3">
            <span class="text-[10px] uppercase tracking-widest text-amber-400 font-extrabold flex items-center gap-1">
              <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
              Harbor City, Kenya
            </span>
            <span class="text-[9px] text-slate-400 font-mono">Coastal Headquarters</span>
          </div>
        </a>

        <nav id="roi-desktop-nav" class="hidden lg:flex items-center space-x-0.5 xl:space-x-1 bg-slate-800/60 p-1.5 rounded-full border border-slate-700/60 backdrop-blur-sm mx-2 min-w-0"></nav>

        <div class="hidden lg:flex items-center space-x-2 xl:space-x-3 shrink-0">
          <a href="#/volunteer" class="flex items-center space-x-1.5 px-3.5 py-2 rounded-xl text-xs font-bold text-sky-400 border border-sky-500/40 hover:bg-sky-500/10 transition-colors hover:scale-105 transform">
            ${icon('users', 'w-3.5 h-3.5')}
            <span>Volunteer</span>
          </a>

          <button id="roi-donate-btn" class="flex items-center space-x-1.5 px-5 py-2.5 rounded-xl text-xs font-extrabold text-slate-900 bg-gradient-to-r from-amber-400 via-amber-500 to-amber-400 hover:from-amber-300 hover:to-amber-500 shadow-lg shadow-amber-500/20 transition-colors transform hover:scale-105 active:scale-95">
            ${icon('heart', 'w-3.5 h-3.5 fill-slate-900 text-slate-900')}
            <span>DONATE</span>
          </button>
        </div>

        <div class="flex lg:hidden items-center gap-2 sm:gap-2.5 shrink-0 max-w-[58vw]">
          <button id="roi-mobile-donate-btn" class="min-h-10 max-w-full px-3 sm:px-4 py-2 rounded-xl text-xs sm:text-sm font-extrabold text-slate-900 bg-gradient-to-r from-amber-300 via-amber-400 to-amber-500 flex items-center gap-1.5 shadow-lg shadow-amber-500/20 active:scale-95 transition-transform">
            ${icon('heart', 'w-3 h-3 fill-slate-950')}
            <span class="truncate">Donate</span>
          </button>
          <button id="roi-mobile-toggle" class="min-h-10 min-w-10 p-2 text-slate-300 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400/80 rounded-xl bg-slate-800/70 border border-slate-700/80" aria-label="Toggle navigation menu" aria-controls="roi-mobile-drawer" aria-expanded="false"></button>
        </div>
      </div>

      <div id="roi-mobile-drawer" class="hidden lg:hidden bg-slate-900/95 border-y border-slate-800 px-3 sm:px-6 pt-3 pb-6 space-y-3 mt-3 animate-fadeIn shadow-2xl shadow-black/40">
        <div id="roi-mobile-links" class="flex flex-col space-y-1"></div>
        <div class="pt-4 border-t border-slate-800 flex flex-col gap-3">
          <a href="#/volunteer" id="roi-mobile-volunteer" class="flex items-center justify-center gap-2 py-2.5 rounded-lg text-xs font-bold bg-sky-500/20 text-sky-300 border border-sky-500/40">
            ${icon('users', 'w-4 h-4')}
            <span>Volunteer Now</span>
          </a>
        </div>
      </div>
    </header>`;

  const headerEl = container.querySelector('#roi-header');
  const desktopNav = container.querySelector('#roi-desktop-nav');
  const drawer = container.querySelector('#roi-mobile-drawer');
  const toggleBtn = container.querySelector('#roi-mobile-toggle');
  const mobileLinks = container.querySelector('#roi-mobile-links');

  const navLinkClass = (link) =>
    `px-2.5 xl:px-3.5 py-1.5 rounded-full text-xs font-medium whitespace-nowrap transition-colors transition-shadow duration-200 ${
      currentPath() === link.path
        ? 'bg-gradient-to-r from-sky-600 to-sky-500 text-white shadow-md shadow-sky-500/25 font-semibold'
        : 'text-slate-300 hover:text-white hover:bg-slate-700/50'
    }`;

  const mobileLinkClass = (link) =>
    `px-3 py-2.5 rounded-lg text-sm font-medium ${
      currentPath() === link.path
        ? 'bg-sky-600/20 text-sky-400 font-bold border border-sky-500/30'
        : 'text-slate-300 hover:bg-slate-800'
    }`;

  function paintNav() {
    desktopNav.innerHTML = NAV_LINKS.map(
      (l) => `<a href="#${l.path}" data-nav-path="${l.path}" class="${navLinkClass(l)}">${l.name}</a>`
    ).join('');
    mobileLinks.innerHTML = NAV_LINKS.map(
      (l) => `<a href="#${l.path}" data-nav-path="${l.path}" class="${mobileLinkClass(l)}">${l.name}</a>`
    ).join('');
  }

  function applyScrollState() {
    headerEl.classList.toggle('bg-slate-900/95', scrolled);
    headerEl.classList.toggle('backdrop-blur-md', scrolled);
    headerEl.classList.toggle('shadow-lg', scrolled);
    headerEl.classList.toggle('shadow-black/40', scrolled);
    headerEl.classList.toggle('border-b', scrolled);
    headerEl.classList.toggle('border-slate-800', scrolled);
    headerEl.classList.toggle('py-2.5', scrolled);
    headerEl.classList.toggle('bg-slate-900/80', !scrolled);
    headerEl.classList.toggle('py-3', !scrolled);
    headerEl.classList.toggle('sm:py-4', !scrolled);
    headerEl.classList.toggle('xl:py-5', !scrolled);
  }

  function paintMobileToggleIcon() {
    toggleBtn.innerHTML = icon(mobileMenuOpen ? 'x' : 'menu', 'w-6 h-6');
    toggleBtn.setAttribute('aria-expanded', String(mobileMenuOpen));
  }

  function setDrawer(open) {
    mobileMenuOpen = open;
    drawer.classList.toggle('hidden', !mobileMenuOpen);
    paintMobileToggleIcon();
  }

  function closeDrawer({ focusToggle = false } = {}) {
    const wasOpen = mobileMenuOpen;
    setDrawer(false);
    if (focusToggle && wasOpen) toggleBtn.focus();
  }

  const onScroll = () => {
    scrolled = window.scrollY > 20;
    applyScrollState();
  };

  const onDocumentClick = (e) => {
    if (!mobileMenuOpen || drawer.contains(e.target) || toggleBtn.contains(e.target)) return;
    closeDrawer();
  };

  const onDocumentKeydown = (e) => {
    if (e.key === 'Escape' && mobileMenuOpen) closeDrawer({ focusToggle: true });
  };

  const onResize = () => {
    if (window.innerWidth >= 1024 && mobileMenuOpen) closeDrawer();
  };

  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onResize, { passive: true });

  // Logo easter egg: 5 clicks within 3 seconds → secret admin console.
  container.querySelector('#roi-logo-link').addEventListener('click', (e) => {
    const now = Date.now();
    clickTimes = clickTimes.filter((t) => now - t <= 3000);
    clickTimes.push(now);
    if (clickTimes.length >= 5) {
      e.preventDefault();
      clickTimes = [];
      navigate('/admin');
    }
  });

  container.querySelector('#roi-donate-btn').addEventListener('click', onOpenDonate);
  container.querySelector('#roi-mobile-donate-btn').addEventListener('click', () => {
    closeDrawer();
    onOpenDonate();
  });
  toggleBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    setDrawer(!mobileMenuOpen);
  });
  document.addEventListener('click', onDocumentClick);
  document.addEventListener('keydown', onDocumentKeydown);
  mobileLinks.addEventListener('click', closeDrawer);
  container.querySelector('#roi-mobile-volunteer').addEventListener('click', closeDrawer);
  drawer.addEventListener('click', (e) => {
    if (e.target.closest('a')) closeDrawer();
  });

  paintNav();
  paintMobileToggleIcon();

  return {
    repaintActiveNav: paintNav,
    destroy() {
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', onResize);
      document.removeEventListener('click', onDocumentClick);
      document.removeEventListener('keydown', onDocumentKeydown);
    }
  };
}
