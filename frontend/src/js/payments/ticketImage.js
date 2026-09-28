// Client-side ticket artwork: generates a QR code (no external service) and
// builds a downloadable PNG ticket. Premium white-first look: deep-blue primary,
// orange accent, ROI logo, QR as focal point, two-column details, branded footer.
import qrcode from '../vendor/qrcode.mjs';
import { ROI_LOGO_DATA_URI } from '../assets/logoDataUri.js';

// --- Design tokens ---------------------------------------------------------
const WHITE = '#FFFFFF';
const INK = '#16263F';        // body text
const BLUE = '#1E5AA8';       // section headings / accents
const DEEP_BLUE = '#0E3A73';  // brand wordmark, code pill, footer
const LIGHT_BLUE = '#E9F1FB'; // QR panel
const BORDER = '#D8E6F5';     // subtle borders
const ORANGE = '#F5870C';     // accent highlights
const FOOTER_BLUE = '#0E2C57';
const STATUS = {
  valid: '#16A34A',
  checked_in: '#1E5AA8',
  void: '#B91C1C',
};

/**
 * Encode text into a QR data URL (GIF). Tries increasing QR versions until
 * the payload fits, so any code length works without caller tuning.
 */
export function qrDataUrl(text) {
  const safe = String(text || '');
  for (let t = 1; t <= 40; t++) {
    try {
      const qr = qrcode(t, 'M');
      qr.addData(safe);
      qr.make();
      return qr.createDataURL(8, 4);
    } catch {
      // payload too long for this version — try the next
    }
  }
  // Fallback: largest version, lowest error correction.
  const qr = qrcode(40, 'L');
  qr.addData(safe);
  qr.make();
  return qr.createDataURL(8, 4);
}

function loadImage(src) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = reject;
    img.src = src;
  });
}

function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

// Minimal professional line icons (orange/blue), drawn at (x, y) at size s.
function drawIcon(ctx, name, x, y, s, color) {
  ctx.save();
  ctx.strokeStyle = color;
  ctx.fillStyle = color;
  ctx.lineWidth = 2;
  ctx.lineJoin = 'round';
  ctx.lineCap = 'round';
  const u = s / 16;
  if (name === 'info') {
    ctx.beginPath(); ctx.arc(x + 8 * u, y + 8 * u, 6.5 * u, 0, Math.PI * 2); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(x + 8 * u, y + 5.5 * u); ctx.lineTo(x + 8 * u, y + 9.5 * u); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(x + 8 * u, y + 11.5 * u); ctx.lineTo(x + 8 * u, y + 11.7 * u); ctx.stroke();
  } else if (name === 'calendar') {
    roundRect(ctx, x + 1.5 * u, y + 3 * u, 13 * u, 11 * u, 2 * u); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(x + 4.5 * u, y + 3 * u); ctx.lineTo(x + 4.5 * u, y + 1 * u);
    ctx.moveTo(x + 11.5 * u, y + 3 * u); ctx.lineTo(x + 11.5 * u, y + 1 * u); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(x + 2.5 * u, y + 7.5 * u); ctx.lineTo(x + 13.5 * u, y + 7.5 * u); ctx.stroke();
  } else if (name === 'user') {
    ctx.beginPath(); ctx.arc(x + 8 * u, y + 5.5 * u, 3 * u, 0, Math.PI * 2); ctx.stroke();
    ctx.beginPath(); ctx.arc(x + 8 * u, y + 14 * u, 5 * u, Math.PI * 1.05, Math.PI * 1.95, true); ctx.stroke();
  } else if (name === 'ticket') {
    roundRect(ctx, x + 1.5 * u, y + 4 * u, 13 * u, 8 * u, 2 * u); ctx.stroke();
    ctx.setLineDash([1.6 * u, 1.6 * u]);
    ctx.beginPath(); ctx.moveTo(x + 9.5 * u, y + 4 * u); ctx.lineTo(x + 9.5 * u, y + 12 * u); ctx.stroke();
    ctx.setLineDash([]);
  } else if (name === 'hash') {
    ctx.beginPath();
    ctx.moveTo(x + 5.5 * u, y + 2 * u); ctx.lineTo(x + 4 * u, y + 14 * u);
    ctx.moveTo(x + 11.5 * u, y + 2 * u); ctx.lineTo(x + 10 * u, y + 14 * u);
    ctx.moveTo(x + 3 * u, y + 5.5 * u); ctx.lineTo(x + 13 * u, y + 5.5 * u);
    ctx.moveTo(x + 2 * u, y + 10.5 * u); ctx.lineTo(x + 12 * u, y + 10.5 * u);
    ctx.stroke();
  }
  ctx.restore();
}

/**
 * Compose and download a PNG ticket for a single issued ticket.
 * White, premium layout: logo header, QR focal block, two-column details,
 * deep-blue branded footer.
 */
export async function downloadTicketPng(ticket, order) {
  const ev = order?.event || {};
  const qrImg = await loadImage(qrDataUrl(ticket.code));
  let logoImg = null;
  try { logoImg = await loadImage(ROI_LOGO_DATA_URI); } catch { /* logo optional */ }

  const scale = 2;
  const W = 640;
  const H = 860;
  const canvas = document.createElement('canvas');
  canvas.width = W * scale;
  canvas.height = H * scale;
  const ctx = canvas.getContext('2d');
  ctx.scale(scale, scale);
  ctx.textBaseline = 'alphabetic';

  // Page + card
  ctx.fillStyle = WHITE;
  ctx.fillRect(0, 0, W, H);
  roundRect(ctx, 16, 26, W - 32, H - 48, 22);
  ctx.fillStyle = 'rgba(15,42,87,0.10)';
  ctx.fill();
  roundRect(ctx, 16, 16, W - 32, H - 48, 22);
  ctx.fillStyle = WHITE;
  ctx.fill();
  ctx.lineWidth = 2;
  ctx.strokeStyle = BORDER;
  ctx.stroke();

  const L = 40; // left content margin
  const R = W - 40; // right edge

  // Header: logo + wordmark
  let hx = L;
  if (logoImg) {
    const lh = 44;
    const lw = Math.min(lh * (logoImg.width / logoImg.height), 120);
    ctx.drawImage(logoImg, L, 38, lw, lh);
    hx = L + lw + 14;
  }
  ctx.textAlign = 'left';
  ctx.fillStyle = DEEP_BLUE;
  ctx.font = '800 16px Inter, Arial, sans-serif';
  ctx.fillText('REACHING OUT INITIATIVE', hx, 60);
  ctx.fillStyle = ORANGE;
  ctx.font = '700 10px Inter, Arial, sans-serif';
  ctx.fillText('GATE TICKET', hx, 76);

  // Divider
  ctx.strokeStyle = BORDER;
  ctx.lineWidth = 1.5;
  ctx.beginPath();
  ctx.moveTo(L, 96);
  ctx.lineTo(R, 96);
  ctx.stroke();

  // QR focal block
  const panelY = 116;
  const panelH = 372;
  roundRect(ctx, L, panelY, R - L, panelH, 20);
  ctx.fillStyle = LIGHT_BLUE;
  ctx.fill();

  const qrSize = 232;
  const qrX = (W - qrSize) / 2;
  const qrY = panelY + 34;
  // white mount behind QR for max contrast / quiet zone
  roundRect(ctx, qrX - 14, qrY - 14, qrSize + 28, qrSize + 28, 12);
  ctx.fillStyle = WHITE;
  ctx.fill();
  ctx.strokeStyle = BORDER;
  ctx.lineWidth = 1;
  ctx.stroke();
  ctx.drawImage(qrImg, qrX, qrY, qrSize, qrSize);

  // Code pill (sized to the code so long codes never overflow)
  ctx.font = '700 18px ui-monospace, SFMono-Regular, Menlo, monospace';
  const codeW = Math.max(300, ctx.measureText(ticket.code).width + 60);
  const pillH = 38;
  const pillX = (W - codeW) / 2;
  const pillY = panelY + panelH - 56;
  roundRect(ctx, pillX, pillY, codeW, pillH, 19);
  ctx.fillStyle = DEEP_BLUE;
  ctx.fill();
  ctx.fillStyle = WHITE;
  ctx.textAlign = 'center';
  ctx.fillText(ticket.code, W / 2, pillY + 25);
  ctx.textAlign = 'left';

  // Details: two-column grid
  const colX = [L, 340];
  const cellW = 260;
  const startY = 528;
  const rowH = 72;
  const fields = [
    { icon: 'info', label: 'Event', value: ev.title || 'ROI Event', col: 0 },
    { icon: 'calendar', label: 'Date & Venue', value: `${ev.date || ''} · ${ev.location || ''}`.trim() || '—', col: 1 },
    { icon: 'user', label: 'Attendee', value: ticket.attendee_name || order?.buyer_name || '—', col: 0 },
    { icon: 'ticket', label: 'Ticket Type', value: ticket.ticket_type_name || '—', col: 1 },
    { icon: 'hash', label: 'Order Ref', value: order?.reference || '—', col: 0 },
    { icon: null, label: 'Status', value: String(ticket.status || 'valid').toUpperCase(), col: 1, status: true },
  ];
  fields.forEach((f, i) => {
    const cx = colX[f.col];
    const cy = startY + Math.floor(i / 2) * rowH;
    if (f.icon) drawIcon(ctx, f.icon, cx, cy, 16, ORANGE);
    ctx.fillStyle = BLUE;
    ctx.font = '700 10px Inter, Arial, sans-serif';
    ctx.fillText(f.label.toUpperCase(), cx + (f.icon ? 24 : 0), cy + 12);
    if (f.status) {
      const sc = STATUS[(ticket.status || 'valid')] || STATUS.valid;
      ctx.font = '700 13px Inter, Arial, sans-serif';
      const tw = ctx.measureText(f.value).width + 22;
      roundRect(ctx, cx, cy + 20, Math.max(tw, 70), 26, 13);
      ctx.fillStyle = sc;
      ctx.fill();
      ctx.fillStyle = WHITE;
      ctx.fillText(f.value, cx + 11, cy + 38);
    } else {
      ctx.fillStyle = INK;
      ctx.font = '600 15px Inter, Arial, sans-serif';
      ctx.fillText(String(f.value), cx, cy + 38);
    }
  });

  // Footer: deep-blue band, orange geometric accents, white text
  // Anchored inside the card (card bottom = 16 + (H-48) = 828).
  const footY = 744;
  roundRect(ctx, 17, footY, W - 34, 76, 18);
  ctx.fillStyle = FOOTER_BLUE;
  ctx.fill();
  ctx.fillStyle = ORANGE;
  ctx.beginPath();
  ctx.moveTo(34, footY + 14); ctx.lineTo(46, footY + 14); ctx.lineTo(34, footY + 26); ctx.closePath(); ctx.fill();
  ctx.beginPath();
  ctx.moveTo(W - 46, footY + 14); ctx.lineTo(W - 34, footY + 14); ctx.lineTo(W - 46, footY + 26); ctx.closePath(); ctx.fill();
  ctx.beginPath(); ctx.arc(W / 2, footY + 16, 4, 0, Math.PI * 2); ctx.fill();
  ctx.textAlign = 'center';
  ctx.fillStyle = WHITE;
  ctx.font = '600 14px Inter, Arial, sans-serif';
  ctx.fillText('Present this QR at the gate.', W / 2, footY + 44);
  ctx.fillStyle = '#CBD5E1';
  ctx.font = '400 10px Inter, Arial, sans-serif';
  ctx.fillText('© 2026 Reaching Out Initiative', W / 2, footY + 62);
  ctx.textAlign = 'left';

  const blob = await new Promise((res) => canvas.toBlob(res, 'image/png'));
  if (!blob) throw new Error('Could not render ticket image.');
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `ROI-${ticket.code}.png`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
