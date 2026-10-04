/** Kenya (Africa/Nairobi) wall-time helpers for admin datetime inputs.
 *  The API stores datetimes as naive UTC (`Y-m-d\TH:i:s`), while admins type
 *  EAT (UTC+3, no DST year-round). Convert at the input boundary so what the
 *  admin picks is what the public site honours. */

const EAT_OFFSET_MS = 3 * 60 * 60 * 1000;

function parseWallTime(value) {
  const s = String(value || '').slice(0, 16);
  if (s.length !== 16) return NaN;
  return Date.parse(`${s}:00+03:00`);
}

/** '2026-12-01T09:00' (EAT, from datetime-local) → '2026-12-01T06:00' (UTC payload). */
export function eatToUtc(value) {
  const t = parseWallTime(value);
  if (Number.isNaN(t)) return null;
  return new Date(t).toISOString().slice(0, 16);
}

/** '2026-12-01T06:00' (UTC from API) → '2026-12-01T09:00' (EAT for datetime-local). */
export function utcToEat(value) {
  const s = String(value || '').slice(0, 16);
  if (s.length !== 16) return '';
  const t = Date.parse(`${s}:00Z`);
  if (Number.isNaN(t)) return '';
  return new Date(t + EAT_OFFSET_MS).toISOString().slice(0, 16);
}
