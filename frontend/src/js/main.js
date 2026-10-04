// Application entry point — replaces src/main.jsx + App.jsx.
// Boots the hash router, global header/footer (hidden on /admin routes),
// donation modal, global lightbox, toast, and fallback transparency banner.
import { startRouter, render, currentPath, register } from './router.js';
import { renderHeader } from './components/header.js';
import { renderFooter } from './components/footer.js';
import { openDonationModal } from './components/donationModal.js';
import { openVideoLightbox } from './components/videoLightbox.js';
import { listenForFallbackEvents } from './ui.js';

import { renderHome } from './pages/home.js';
import { renderAbout } from './pages/about.js';
import { renderEvents } from './pages/events.js';
import { renderBlog } from './pages/blog.js';
import { renderMediaHub } from './pages/mediaHub.js';
import { renderContact } from './pages/contact.js';
import { renderVolunteer } from './pages/volunteer.js';
import { renderAdminLogin } from './pages/adminLogin.js';
import { renderAdminDashboard } from './pages/adminDashboard.js';
import { renderChecking } from './pages/checking.js';
import { renderTickets } from './pages/tickets.js';
import { renderTicketOrder } from './pages/ticketOrder.js';
import { renderTicketRecover } from './pages/ticketRecover.js';
import { renderTicketPortal } from './pages/ticketPortal.js';
import { renderDonateVerify } from './pages/donateVerify.js';
import { renderSolutions } from './pages/solutions.js';
import { renderPortfolio } from './pages/portfolio.js';
import { renderStatus } from './pages/status.js';

const appEl = document.getElementById('app');
const headerRoot = document.getElementById('header-root');
const footerRoot = document.getElementById('footer-root');

let activeHeaderApi = null;

listenForFallbackEvents();

function mountRoute() {
  // The open gate station lives at the real path /checking (served by the
  // Laravel SPA shell). Render it standalone with no header/footer.
  if (window.location.pathname === '/checking' || window.location.pathname.startsWith('/checking/')) {
    activeHeaderApi?.destroy?.();
    headerRoot.innerHTML = '';
    footerRoot.innerHTML = '';
    activeHeaderApi = null;
    renderChecking(appEl);
    return;
  }

  const path = currentPath();
  const isAdminRoute = path.startsWith('/admin');

  // Header & Footer are hidden on admin routes (App.jsx behavior).
  if (!isAdminRoute && !activeHeaderApi) {
    activeHeaderApi = renderHeader(headerRoot, () => openDonationModal());
    renderFooter(footerRoot);
  } else if (isAdminRoute && activeHeaderApi) {
    activeHeaderApi.destroy?.();
    headerRoot.innerHTML = '';
    footerRoot.innerHTML = '';
    activeHeaderApi = null;
  }

  render(appEl);
  activeHeaderApi?.repaintActiveNav();
}

// Route registry — mirrors App.jsx <Routes>
register('/', (root) => renderHome(root, () => openDonationModal(), openVideoLightbox));
register('/about', renderAbout);
register('/events', renderEvents);
register('/blog', renderBlog);
register('/media', renderMediaHub);
register('/contact', renderContact);
register('/volunteer', renderVolunteer);
register('/solutions/portfolio', renderPortfolio);
register('/solutions', renderSolutions);
register('/status', renderStatus);
register('/admin', renderAdminLogin);
register('/admin/dashboard', renderAdminDashboard);
register('/tickets/recover', renderTicketRecover);
register('/tickets/order/:reference', renderTicketOrder);
register('/tickets/portal/:token', renderTicketPortal);
register('/tickets/:eventId', renderTickets);
register('/donate/verify/:reference', renderDonateVerify);

startRouter(() => {
  mountRoute();
});
