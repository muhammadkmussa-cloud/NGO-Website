export function portalPath(token) {
  return `/tickets/portal/${encodeURIComponent(token || '')}`;
}

export function isLikelyPortalToken(token) {
  return typeof token === 'string' && token.includes('.') && token.length > 20;
}
