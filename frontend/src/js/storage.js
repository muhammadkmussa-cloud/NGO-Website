// Resilient storage utility immune to iframe sandbox SecurityErrors.
// Direct port of frontend/src/utils/storage.js (React version).
export const safeStorage = {
  getItem(key) {
    try {
      return window.localStorage.getItem(key);
    } catch (err) {
      return (window._roiMemoryStore && window._roiMemoryStore[key]) || null;
    }
  },
  setItem(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch (err) {
      window._roiMemoryStore = window._roiMemoryStore || {};
      window._roiMemoryStore[key] = value;
    }
  },
  removeItem(key) {
    try {
      window.localStorage.removeItem(key);
    } catch (err) {
      if (window._roiMemoryStore) {
        delete window._roiMemoryStore[key];
      }
    }
  }
};

export const safeClipboardCopy = (text) => {
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).catch(() => {});
    } else {
      // Fallback textarea execCommand
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    }
  } catch (err) { /* swallow */ }
};
