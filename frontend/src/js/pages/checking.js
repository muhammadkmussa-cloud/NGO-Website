import { request } from '../api.js';
import { escapeHtml, showToast } from '../ui.js';
import { normalizeTicketCode, resultTone} from '../payments/ticketCheckIn.js';

// Open gate station. Reached at the real path /checking (served by the
// Laravel SPA shell). No admin auth — anyone at the door can scan tickets.
export function renderChecking(root) {
  const state = {
    code: '',
    last: null,
    log: [],
    scanning: false,
    error: null
  };
  let stream = null;
  let scanTimer = null;
  let fallbackCanvas = null;
  let fallbackCtx = null;

  root.innerHTML = `
    <div class="min-h-screen bg-slate-950 text-slate-100 p-6 sm:p-10">
      <div class="max-w-3xl mx-auto space-y-6">
        <div class="flex items-center justify-between gap-3">
          <div>
            <p class="text-[10px] uppercase tracking-wider text-amber-400 font-bold">Demo NGO · Gate</p>
            <h1 class="text-3xl font-black text-white">QR check-in</h1>
          </div>
          <a href="/" class="text-xs font-bold text-sky-400">Exit</a>
        </div>

        <form id="roi-gate-form" class="bg-slate-900 border border-slate-800 rounded-3xl p-5 flex flex-col sm:flex-row gap-3">
          <input id="roi-gate-code" autofocus placeholder="Scan QR or type DEMO-XXXX-XXXX" class="flex-1 px-4 py-3 rounded-xl bg-slate-950 border border-slate-700 text-white font-mono text-sm">
          <button type="submit" class="px-5 py-3 rounded-xl bg-amber-400 text-slate-950 font-black text-xs">Admit</button>
          <button type="button" id="roi-gate-scan" class="px-5 py-3 rounded-xl bg-sky-600 text-white font-black text-xs">Camera</button>
        </form>
        <video id="roi-gate-video" class="hidden w-full rounded-2xl bg-black max-h-64 object-cover" playsinline></video>
        <div id="roi-gate-result"></div>
        <div>
          <h2 class="text-sm font-bold text-white mb-2">Recent scans</h2>
          <div id="roi-gate-log" class="space-y-2"></div>
        </div>
      </div>
    </div>`;

  const resultEl = root.querySelector('#roi-gate-result');
  const logEl = root.querySelector('#roi-gate-log');
  const video = root.querySelector('#roi-gate-video');

  function paintResult() {
    if (!state.last) {
      resultEl.innerHTML = state.error
        ? `<div class="p-4 rounded-2xl bg-red-500/10 border border-red-500 text-red-200 text-sm">${escapeHtml(state.error)}</div>`
        : '';
      return;
    }
    const tone = resultTone(state.last.result);
    const cls = tone === 'ok' ? 'bg-emerald-500/10 border-emerald-500 text-emerald-100'
      : tone === 'warn' ? 'bg-amber-500/10 border-amber-500 text-amber-100'
        : tone === 'bad' ? 'bg-red-500/10 border-red-500 text-red-100'
          : 'bg-slate-800 border-slate-700 text-slate-200';
    resultEl.innerHTML = `
      <div class="p-6 rounded-3xl border ${cls} space-y-2">
        <div class="text-[10px] uppercase font-black">${escapeHtml(state.last.result || '')}</div>
        <div class="text-2xl font-black">${escapeHtml(state.last.attendee_name || '—')}</div>
        <div class="font-mono text-lg">${escapeHtml(state.last.code || '')}</div>
        <p class="text-sm">${escapeHtml(state.last.event_title || '')}</p>
        <p class="text-xs opacity-80">${escapeHtml(state.last.ticket_type_name || '')} · ${escapeHtml(state.last.detail || '')}</p>
      </div>`;
    logEl.innerHTML = state.log.map((row) => `
      <div class="text-xs bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 flex justify-between">
        <span class="font-mono text-amber-300">${escapeHtml(row.code)}</span>
        <span>${escapeHtml(row.result)}</span>
      </div>`).join('');
  }

  async function admit(raw) {
    const code = normalizeTicketCode(raw);
    if (!code) return;
    state.error = null;
    try {
      const res = await request('POST', `/gate/tickets/${encodeURIComponent(code)}/check-in`);
      const data = res.data || {};
      if (!res.ok && !data.result) {
        state.last = { result: 'not_found', detail: data.detail || 'Ticket not found', code };
      } else {
        state.last = { ...data, code: data.code || code };
      }
      state.log = [{ code: state.last.code, result: state.last.result }, ...state.log].slice(0, 12);
      if (state.last.result === 'admitted') showToast(`Admitted ${state.last.attendee_name || code}`);
    } catch (err) {
      state.error = err.message || 'Check-in failed';
      state.last = null;
    }
    paintResult();
  }

  async function toggleCamera() {
    if (state.scanning) {
      stopCamera();
      return;
    }
    if (!navigator.mediaDevices?.getUserMedia) {
      showToast('Camera is not available. Type the code instead.');
      return;
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      video.srcObject = stream;
      video.classList.remove('hidden');
      await video.play();
      state.scanning = true;
      root.querySelector('#roi-gate-scan').textContent = 'Stop camera';

      const Detector = window.BarcodeDetector;
      const useBarcodeDetector = !!Detector;
      let detector = null;
      let jsqr = null;
      if (useBarcodeDetector) {
        detector = new Detector({ formats: ['qr_code'] });
      } else {
        const mod = await import('../vendor/jsqr.mjs');
        jsqr = mod.default || mod.jsQR;
        fallbackCanvas = document.createElement('canvas');
        fallbackCtx = fallbackCanvas.getContext('2d');
      }

      scanTimer = setInterval(async () => {
        try {
          let raw = null;
          if (useBarcodeDetector) {
            const codes = await detector.detect(video);
            raw = codes[0]?.rawValue;
          } else {
            if (video.readyState === HTMLMediaElement.HAVE_ENOUGH_DATA) {
              fallbackCanvas.width = video.videoWidth;
              fallbackCanvas.height = video.videoHeight;
              fallbackCtx.drawImage(video, 0, 0, fallbackCanvas.width, fallbackCanvas.height);
              const imageData = fallbackCtx.getImageData(0, 0, fallbackCanvas.width, fallbackCanvas.height);
              const result = jsqr(imageData.data, fallbackCanvas.width, fallbackCanvas.height);
              raw = result?.data;
            }
          }
          if (raw) {
            const code = normalizeTicketCode(raw);
            if (code) {
              stopCamera();
              root.querySelector('#roi-gate-code').value = code;
              admit(code);
            }
          }
        } catch { /* keep scanning */ }
      }, 400);
    } catch {
      showToast('Could not open camera.');
    }
  }

  function stopCamera() {
    state.scanning = false;
    if (scanTimer) clearInterval(scanTimer);
    scanTimer = null;
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
    fallbackCanvas = null;
    fallbackCtx = null;
    video.classList.add('hidden');
    const btn = root.querySelector('#roi-gate-scan');
    if (btn) btn.textContent = 'Camera';
  }

  root.querySelector('#roi-gate-form').addEventListener('submit', (e) => {
    e.preventDefault();
    admit(root.querySelector('#roi-gate-code').value);
  });
  root.querySelector('#roi-gate-scan').addEventListener('click', toggleCamera);
}