// Blog page — vanilla port of pages/Blog.jsx + components/BlogCard.jsx.
// Search + category chips, full-article reader modal with clipboard share.
import { getBlogs } from '../api.js';
import { icon } from '../icons.js';
import { escapeHtml, imageWithFallback, fmtDate } from '../ui.js';
import { safeClipboardCopy } from '../storage.js';

const CATEGORIES = ['All', 'Mentorship', 'Education', 'Success Stories', 'Social Impact'];

function blogCard(post) {
  return `
    <article data-post-id="${post.id}"
      class="bg-slate-900 border border-slate-800 rounded-3xl overflow-hidden shadow-xl hover:border-amber-500 transition-colors transform duration-300 flex flex-col cursor-pointer group hover-scale">
      <div class="relative aspect-[16/10] bg-slate-950 overflow-hidden">
        ${imageWithFallback(
          post.image_url || 'https://images.unsplash.com/photo-1529156069898-49953e39b3ac?auto=format&fit=crop&w=800&q=80',
          post.title,
          'w-full h-full object-cover group-hover:scale-105 transition-transform duration-700',
          post.category || 'Social Impact'
        )}
        <span class="absolute top-4 right-4 bg-amber-500 text-slate-950 text-[10px] font-black px-3 py-1 rounded-full uppercase tracking-wider">${escapeHtml(post.category || 'Impact')}</span>
      </div>

      <div class="p-6 flex-1 flex flex-col justify-between space-y-4">
        <div class="space-y-2.5">
          <div class="flex items-center gap-3 text-[11px] text-slate-400">
            <span class="flex items-center gap-1">
              ${icon('calendar', 'w-3 h-3 text-sky-400')}
              ${fmtDate(post.created_at || Date.now())}
            </span>
            <span>•</span>
            <span class="flex items-center gap-1 truncate max-w-[140px]">
              ${icon('user', 'w-3 h-3 text-amber-400')}
              ${escapeHtml(post.author || 'DEMO Team')}
            </span>
          </div>

          <h3 class="text-lg font-bold text-white group-hover:text-sky-400 transition-colors leading-snug line-clamp-2">${escapeHtml(post.title)}</h3>
          <p class="text-sm text-slate-300 line-clamp-3 leading-relaxed">${escapeHtml(post.summary)}</p>
        </div>

        <div class="pt-3 border-t border-slate-800 flex items-center justify-between text-xs font-bold text-sky-400 group-hover:text-amber-400">
          <span>Read Field Story</span>
          ${icon('book-open', 'w-4 h-4')}
        </div>
      </div>
    </article>`;
}

export function renderBlog(root) {
  let posts = [];
  let loading = true;
  let activeCategory = 'All';
  let searchQuery = '';

  root.innerHTML = `
    <div class="py-16 sm:py-24 bg-slate-900 min-h-screen space-y-16">

      <div class="max-w-7xl px-4 sm:px-6 lg:px-8 text-center max-w-3xl mx-auto space-y-4">
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs font-bold">
          ${icon('book-open', 'w-3.5 h-3.5')}
          <span>Field Operational Updates</span>
        </div>
        <h1 class="text-4xl sm:text-6xl font-black text-white">
          Field Stories & Youth Impact Reports
        </h1>
        <p class="text-base sm:text-lg text-slate-300 leading-relaxed">Dynamic case studies documenting youth mentorship cohorts, digital literacy impacts, and community transformation in Harbor City.</p>

        <div class="pt-6 space-y-4 max-w-xl mx-auto">
          <div class="relative">
            <span class="absolute left-4 top-3.5 text-slate-400 pointer-events-none inline-block align-middle">${icon('search', 'w-4 h-4')}</span>
            <input type="text" id="roi-blog-search" placeholder="Search field stories by keyword or title..."
              class="w-full pl-11 pr-4 py-3 rounded-2xl bg-slate-800 border border-slate-700 text-white text-xs focus:outline-none focus:border-amber-400 transition-colors">
          </div>

          <div id="roi-blog-cats" class="flex flex-wrap items-center justify-center gap-1.5"></div>
        </div>
      </div>

      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div id="roi-blog-body" class="grid grid-cols-1 md:grid-cols-3 gap-8">
          <div class="bg-slate-800 animate-pulse aspect-[16/12] rounded-3xl"></div>
          <div class="bg-slate-800 animate-pulse aspect-[16/12] rounded-3xl"></div>
          <div class="bg-slate-800 animate-pulse aspect-[16/12] rounded-3xl"></div>
        </div>
      </div>

      <div id="roi-reader-root"></div>
    </div>`;

  const catsEl = root.querySelector('#roi-blog-cats');
  const bodyEl = root.querySelector('#roi-blog-body');
  const searchEl = root.querySelector('#roi-blog-search');

  function filteredPosts() {
    return posts.filter((p) => {
      const matchesCat = activeCategory === 'All' || p.category === activeCategory;
      const q = (searchQuery || '').toLowerCase();
      const matchesSearch =
        !searchQuery ||
        String(p.title).toLowerCase().includes(q) ||
        String(p.summary).toLowerCase().includes(q);
      return matchesCat && matchesSearch;
    });
  }

  function paintCats() {
    catsEl.innerHTML = CATEGORIES.map(
      (cat) => `
      <button data-cat="${escapeHtml(cat)}" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-colors transition-shadow ${
        activeCategory === cat ? 'bg-amber-400 text-slate-950 shadow-md' : 'bg-slate-800/80 text-slate-300 hover:bg-slate-700'
      }">${cat}</button>`
    ).join('');
    catsEl.querySelectorAll('[data-cat]').forEach((b) =>
      b.addEventListener('click', () => {
        activeCategory = b.dataset.cat;
        paintCats();
        paintBody();
      })
    );
  }

  function paintBody() {
    const list = filteredPosts();
    if (!loading && list.length === 0) {
      bodyEl.className = '';
      bodyEl.innerHTML = `<div class="text-center py-16 text-slate-400"><p>No articles match your criteria.</p></div>`;
      return;
    }
    bodyEl.className = 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8';
    bodyEl.innerHTML = list.map(blogCard).join('');
    bodyEl.querySelectorAll('[data-post-id]').forEach((card) =>
      card.addEventListener('click', () => {
        const post = posts.find((p) => String(p.id) === card.dataset.postId);
        if (post) openReader(post);
      })
    );
  }

  function openReader(post) {
    const readerRoot = root.querySelector('#roi-reader-root');
    readerRoot.innerHTML = `
      <div class="fixed inset-0 z-[130] flex items-center justify-center p-4 sm:p-6 bg-slate-950/85 backdrop-blur-md animate-fadeIn overflow-y-auto">
        <div class="bg-slate-900 border border-slate-700 w-full max-w-3xl rounded-3xl overflow-hidden shadow-2xl my-8 relative">

          <div class="sticky top-0 bg-slate-900/90 backdrop-blur-md px-6 py-4 border-b border-slate-800 flex items-center justify-between z-10">
            <span class="text-xs font-bold text-amber-400 uppercase tracking-widest">${escapeHtml(post.category)}</span>
            <button type="button" id="roi-reader-close" class="p-1.5 rounded-full bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white">${icon('x', 'w-5 h-5')}</button>
          </div>

          <div class="aspect-[16/8] bg-slate-950 overflow-hidden relative">
            ${imageWithFallback(post.image_url, post.title, 'w-full h-full object-cover', post.category)}
          </div>

          <div class="p-6 sm:p-10 space-y-6 text-slate-200">
            <div class="flex items-center gap-4 text-xs text-slate-400 border-b border-slate-800 pb-4">
              <span class="flex items-center gap-1.5 font-semibold text-sky-400">
                ${icon('user', 'w-4 h-4')}
                ${escapeHtml(post.author || 'DEMO Communications')}
              </span>
              <span>•</span>
              <span class="flex items-center gap-1.5">
                ${icon('calendar', 'w-4 h-4')}
                ${fmtDate(post.created_at || Date.now())}
              </span>
            </div>

            <h2 class="text-2xl sm:text-4xl font-black text-white leading-tight">${escapeHtml(post.title)}</h2>

            <div class="text-sm sm:text-base leading-relaxed space-y-4 whitespace-pre-line font-normal text-slate-300">${escapeHtml(post.content)}</div>

            <div class="pt-8 border-t border-slate-800 flex items-center justify-between text-xs text-slate-400">
              <span>Published in Harbor City, Kenya</span>
              <button type="button" id="roi-share-story"
                class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-white font-bold flex items-center gap-1.5">
                ${icon('share-2', 'w-3.5 h-3.5')}
                <span>Share Story</span>
              </button>
            </div>
          </div>
        </div>
      </div>`;

    readerRoot.querySelector('#roi-reader-close').addEventListener('click', () => (readerRoot.innerHTML = ''));
    readerRoot.querySelector('#roi-share-story').addEventListener('click', () => {
      safeClipboardCopy(window.location.href);
      window.alert('Article link copied!');
    });
  }

  paintCats();
  searchEl.addEventListener('input', () => {
    searchQuery = searchEl.value;
    paintBody();
  });

  getBlogs().then((data) => {
    if (data) posts = data;
    loading = false;
    paintBody();
  });
}
