// Hash-based router — replaces React Router's HashRouter.
// Routes live at #/path so deep links and the logo easter-egg keep working
// without any server-side rewrite rules (important for static hosting).

const routes = [];
let notFoundHandler = () => {};
let lastParams = {};

export function register(path, renderFn) {
  routes.push({ path, renderFn });
}

export function routeParams() {
  return lastParams;
}

export function setNotFound(renderFn) {
  notFoundHandler = renderFn;
}

export function currentPath() {
  const hash = window.location.hash || '#/';
  return hash.startsWith('#') ? hash.slice(1) || '/' : hash;
}

export function navigate(path) {
  window.location.hash = '#' + path;
}

function matchRoute(path) {
  for (const route of routes) {
    if (route.path === path) {
      return { route, params: {} };
    }
    const keys = [];
    const pattern = route.path.replace(/:([^/]+)/g, (_, key) => {
      keys.push(key);
      return '([^/]+)';
    });
    if (keys.length === 0) continue;
    const m = path.match(new RegExp(`^${pattern}$`));
    if (m) {
      const params = {};
      keys.forEach((key, i) => {
        params[key] = decodeURIComponent(m[i + 1]);
      });
      return { route, params };
    }
  }
  return null;
}

/** Renders the active route into #app. Returns matched path or null. */
export function render(mountEl) {
  const path = currentPath();
  const matched = matchRoute(path);
  if (matched) {
    lastParams = matched.params;
    mountEl.innerHTML = '';
    matched.route.renderFn(mountEl, matched.params);
    return path;
  }
  lastParams = {};
  mountEl.innerHTML = '';
  notFoundHandler(mountEl);
  return null;
}

export function startRouter(onChange) {
  window.addEventListener('hashchange', onChange);
  onChange();
}
