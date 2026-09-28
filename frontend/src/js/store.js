// Global auth session store — vanilla port of frontend/src/context/AuthContext.jsx.
// The admin JWT is held IN MEMORY only (never localStorage) so it cannot be
// exfiltrated by XSS after a page reload, and an httpOnly cookie (set by the
// backend on login) provides additional protection on same-origin deployments.
import { postLogin, postLogout } from './api.js';

const state = {
  admin: null,
  loading: false
};

// In-memory token; intentionally NOT persisted to localStorage.
let token = null;

const listeners = new Set();

export function subscribe(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

function emit() {
  listeners.forEach((fn) => fn(state));
}

export async function login(email, password) {
  try {
    const res = await postLogin(email, password);
    if (!res.ok) {
      return {
        success: false,
        error: res.status === 429
          ? 'Too many failed attempts. Try again later.'
          : 'Authentication failed. Verify administrator credentials.'
      };
    }
    token = res.data.access_token;
    state.admin = { email, role: 'global_admin' };
    emit();
    return { success: true };
  } catch (err) {
    return { success: false, error: 'Authentication failed. Verify administrator credentials.' };
  }
}

export function logout() {
  const current = token;
  token = null;
  state.admin = null;
  emit();
  // Best-effort: clear the httpOnly session cookie server-side.
  if (current) {
    postLogout().catch(() => {});
  }
}

export function getAuthState() {
  return state;
}

export function getToken() {
  return token;
}
