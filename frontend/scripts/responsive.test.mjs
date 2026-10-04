import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import {
  FALLBACK_BLOGS,
  FALLBACK_EVENTS,
  FALLBACK_FAQS,
  FALLBACK_INDUSTRIES,
  FALLBACK_MEDIA,
  FALLBACK_METRICS,
  FALLBACK_PORTFOLIO,
  FALLBACK_SOLUTIONS,
  FALLBACK_TECH,
  FALLBACK_TICKET_CATALOG
} from '../src/js/data/fallbackData.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const distDir = path.resolve(__dirname, '../dist');
const indexPath = path.join(distDir, 'index.html');

const routes = [
  '/', '/about', '/events', '/solutions', '/solutions/portfolio', '/blog', '/media',
  '/contact', '/volunteer', '/status', '/admin', '/tickets/recover', '/tickets/101'
];
const viewports = [
  { width: 320, height: 640, name: 'small-phone' },
  { width: 360, height: 480, name: 'short-phone' },
  { width: 375, height: 667, name: 'phone' },
  { width: 768, height: 1024, name: 'tablet' },
  { width: 1024, height: 768, name: 'small-laptop' },
  { width: 1536, height: 900, name: 'desktop-nav' }
];

const apiFixtures = new Map([
  ['/api/public/blog', FALLBACK_BLOGS],
  ['/api/public/events', FALLBACK_EVENTS],
  ['/api/public/leaders', []],
  ['/api/public/media', FALLBACK_MEDIA],
  ['/api/public/media/latest', FALLBACK_MEDIA.slice(0, 3)],
  ['/api/public/metrics', FALLBACK_METRICS],
  ['/api/public/site', {
    hero: {
      eyebrow: 'Flagship Conference 2026',
      title: 'Youth Leadership Summit',
      description: 'Responsive fixture hero copy.',
      image_url: 'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=900&q=80'
    },
    metrics: { youth_mentored: 120, events_hosted: 2, individuals_supported: 95, active_volunteers: 45 }
  }],
  ['/api/public/solutions', FALLBACK_SOLUTIONS],
  ['/api/public/solution-faqs', FALLBACK_FAQS],
  ['/api/public/industries', FALLBACK_INDUSTRIES],
  ['/api/public/tech', FALLBACK_TECH],
  ['/api/public/portfolio', FALLBACK_PORTFOLIO],
  ['/api/public/events/101/tickets', FALLBACK_TICKET_CATALOG(101)],
  ['/api/health', { status: 'ok' }],
  ['/api/ready', { status: 'ok', checks: { database: 'ok' } }],
  ['/api/auth/login', { access_token: 'responsive-test-token', token_type: 'bearer' }],
  ['/api/admin/stats', {
    total_volunteers: 0,
    total_donations_kes: 0,
    total_events: 0,
    total_articles: 0,
    recent_inquiries_count: 0,
    system_health: 'Responsive fixture online'
  }],
  ['/api/admin/site', {
    hero: {
      eyebrow: 'Flagship Conference 2026',
      title: 'Youth Leadership Summit',
      description: 'Responsive fixture hero copy.',
      image_url: 'https://images.unsplash.com/photo-1511578314322-379afb476865?auto=format&fit=crop&w=900&q=80'
    },
    metrics: { youth_mentored: 120, events_hosted: 2, individuals_supported: 95, active_volunteers: 45 }
  }],
  ['/api/admin/volunteers', []],
  ['/api/admin/inquiries', []],
  ['/api/admin/ticket-types', []],
  ['/api/admin/ticket-orders', []],
  ['/api/admin/ticket-stats', {}],
  ['/api/admin/solutions', []],
  ['/api/admin/solution-inquiries', []],
  ['/api/admin/portfolio', []],
  ['/api/admin/solution-faqs', []],
  ['/api/admin/industries', []],
  ['/api/admin/tech', []],
  ['/api/payments/paybills', {
    enabled: false,
    message: 'Our secure contribution channels are being prepared. Please contact our team in the meantime.'
  }]
]);

const mimeTypes = new Map([
  ['.css', 'text/css; charset=utf-8'],
  ['.html', 'text/html; charset=utf-8'],
  ['.js', 'text/javascript; charset=utf-8'],
  ['.mjs', 'text/javascript; charset=utf-8'],
  ['.json', 'application/json; charset=utf-8'],
  ['.png', 'image/png'],
  ['.svg', 'image/svg+xml; charset=utf-8'],
  ['.webp', 'image/webp']
]);

let outsideClickCovered = false;

if (!existsSync(indexPath)) {
  throw new Error('dist/index.html is missing. Run `npm run build` before `npm run test:responsive`.');
}

function json(res, status, body) {
  res.writeHead(status, { 'content-type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(body));
}

function startServer() {
  const server = createServer(async (req, res) => {
    try {
      const url = new URL(req.url || '/', 'http://127.0.0.1');
      if (url.pathname.startsWith('/api/')) {
        if (apiFixtures.has(url.pathname)) return json(res, 200, apiFixtures.get(url.pathname));
        return json(res, 404, { detail: 'Responsive test fixture: endpoint not stubbed.' });
      }

      const requestedPath = path.normalize(decodeURIComponent(url.pathname));
      const filePath = requestedPath === '/' ? indexPath : path.resolve(distDir, `.${requestedPath}`);
      const relative = path.relative(distDir, filePath);
      if (relative.startsWith('..') || path.isAbsolute(relative)) {
        res.writeHead(403, { 'content-type': 'text/plain; charset=utf-8' });
        res.end('Forbidden');
        return;
      }

      const ext = path.extname(filePath);
      let safePath = filePath;
      if (!existsSync(safePath)) {
        if (ext) {
          res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
          res.end('Not found');
          return;
        }
        safePath = indexPath;
      }

      const body = await readFile(safePath);
      res.writeHead(200, { 'content-type': mimeTypes.get(path.extname(safePath)) || 'application/octet-stream' });
      res.end(body);
    } catch (err) {
      res.writeHead(500, { 'content-type': 'text/plain; charset=utf-8' });
      res.end(String(err.stack || err));
    }
  });

  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      resolve({ server, origin: `http://127.0.0.1:${address.port}` });
    });
  });
}

async function assertNoHorizontalOverflow(page, label) {
  const metrics = await page.evaluate(() => {
    const doc = document.documentElement;
    const body = document.body;
    const widest = [...document.querySelectorAll('body *')]
      .filter((el) => {
        const style = getComputedStyle(el);
        return style.display !== 'none' && style.visibility !== 'hidden';
      })
      .map((el) => {
        const rect = el.getBoundingClientRect();
        return {
          id: el.id,
          className: String(el.className || '').slice(0, 140),
          tag: el.tagName,
          left: rect.left,
          right: rect.right,
          width: rect.width
        };
      })
      .sort((a, b) => b.right - a.right)[0];
    return {
      bodyScrollWidth: body.scrollWidth,
      docClientWidth: doc.clientWidth,
      docScrollWidth: doc.scrollWidth,
      viewportWidth: window.innerWidth,
      widest
    };
  });

  assert.ok(
    metrics.docScrollWidth <= metrics.docClientWidth + 1 && metrics.bodyScrollWidth <= metrics.viewportWidth + 1,
    `${label} has horizontal overflow: ${JSON.stringify(metrics)}`
  );
}

async function assertDonateInViewport(page, label) {
  const cta = page.locator('#roi-mobile-donate-btn:visible, #roi-donate-btn:visible').first();
  await assert.doesNotReject(() => cta.waitFor({ state: 'visible', timeout: 2000 }), `${label} donate CTA is not visible`);
  const box = await cta.boundingBox();
  assert.ok(box, `${label} donate CTA has no bounding box`);
  const viewport = page.viewportSize();
  assert.ok(box.x >= -1 && box.x + box.width <= viewport.width + 1, `${label} donate CTA is outside viewport: ${JSON.stringify(box)}`);
  assert.ok(box.y >= -1 && box.y + box.height <= viewport.height + 1, `${label} donate CTA is vertically outside viewport: ${JSON.stringify(box)}`);
}

async function assertDonationModal(page, label) {
  const cta = page.locator('#roi-mobile-donate-btn:visible, #roi-donate-btn:visible').first();
  await cta.click();
  const dialog = page.locator('#roi-donation-modal [role="dialog"]');
  await dialog.waitFor({ state: 'visible', timeout: 2000 });
  await assertNoHorizontalOverflow(page, `${label} donation modal`);

  const box = await dialog.boundingBox();
  const viewport = page.viewportSize();
  assert.ok(box.height <= viewport.height + 1, `${label} donation dialog taller than viewport: ${JSON.stringify(box)}`);
  assert.ok(box.y >= -1 && box.y + box.height <= viewport.height + 1, `${label} donation dialog vertically outside viewport: ${JSON.stringify(box)}`);
  assert.ok(box.x >= -1 && box.x + box.width <= viewport.width + 1, `${label} donation dialog outside viewport: ${JSON.stringify(box)}`);

  await page.getByRole('heading', { name: 'Donations are temporarily unavailable.' }).waitFor({ state: 'visible', timeout: 2000 });
  await page.getByRole('link', { name: /contact our team/i }).waitFor({ state: 'visible', timeout: 2000 });
  assert.equal(await dialog.locator('#roi-donate-form').count(), 0, `${label} exposed a payment form while disabled`);
  assert.equal(await dialog.locator('[data-copy]').count(), 0, `${label} exposed paybill copy controls while disabled`);
  assert.equal(await dialog.getByText(/000001|000002|000003|000004|000005).count(), 0, `${label} exposed payment details while disabled`);

  await page.keyboard.press('Escape');
  await page.locator('#roi-donation-modal').waitFor({ state: 'detached', timeout: 2000 });
}

async function assertEnabledDonationModalSafety(page) {
  apiFixtures.set('/api/payments/paybills', {
    enabled: true,
    business_name: 'DEMO NGO',
    kcb_mpesa: { paybill: '000001', account: 'SADAQA' },
    equity_bank: { paybill: '000005', account: '<img src=x onerror="window.__roiPaybillXss = true">' }
  });
  await page.goto(`${origin}/#/`, { waitUntil: 'domcontentloaded' });
  await page.locator('#roi-mobile-donate-btn:visible, #roi-donate-btn:visible').first().click();
  await page.getByRole('button', { name: /kenya offline paybills/i }).waitFor({ state: 'visible', timeout: 2000 });
  await page.getByRole('button', { name: /kenya offline paybills/i }).click();
  await page.getByRole('button', { name: /copy/i }).first().waitFor({ state: 'visible', timeout: 2000 });
  assert.equal(await page.evaluate(() => window.__roiPaybillXss), undefined, 'enabled modal executed paybill markup from API data');
  await assertNoHorizontalOverflow(page, 'enabled donation modal');
}

async function assertHeaderDrawer(page, label) {
  const toggle = page.locator('#roi-mobile-toggle');
  if (!(await toggle.isVisible())) {
    await page.locator('#roi-desktop-nav:visible').waitFor({ state: 'visible', timeout: 2000 });
    return;
  }

  await toggle.click();
  const drawer = page.locator('#roi-mobile-drawer');
  await drawer.waitFor({ state: 'visible', timeout: 2000 });
  assert.equal(await toggle.getAttribute('aria-expanded'), 'true', `${label} drawer did not set aria-expanded=true`);
  await assertNoHorizontalOverflow(page, `${label} open drawer`);
  const drawerBox = await drawer.boundingBox();
  const viewport = page.viewportSize();
  assert.ok(drawerBox.x >= -1 && drawerBox.x + drawerBox.width <= viewport.width + 1, `${label} drawer outside viewport`);

  await page.keyboard.press('Escape');
  await drawer.waitFor({ state: 'hidden', timeout: 2000 });
  assert.equal(await toggle.getAttribute('aria-expanded'), 'false', `${label} Escape did not reset aria-expanded=false`);
  assert.equal(await page.evaluate(() => document.activeElement?.id), 'roi-mobile-toggle', `${label} Escape did not return focus to menu toggle`);

  await toggle.click();
  await drawer.waitFor({ state: 'visible', timeout: 2000 });
  const reopenedBox = await drawer.boundingBox();
  if (reopenedBox.y + reopenedBox.height + 4 < viewport.height) {
    await page.mouse.click(4, reopenedBox.y + reopenedBox.height + 4);
    await drawer.waitFor({ state: 'hidden', timeout: 2000 });
    outsideClickCovered = true;
  } else {
    await page.keyboard.press('Escape');
    await drawer.waitFor({ state: 'hidden', timeout: 2000 });
  }

  await toggle.click();
  await drawer.waitFor({ state: 'visible', timeout: 2000 });
  await drawer.locator('[data-nav-path="/about"]').click();
  await page.waitForFunction(() => window.location.hash === '#/about');
  await drawer.waitFor({ state: 'hidden', timeout: 2000 });
}

async function assertAdminDashboard(page, label) {
  await page.goto(`${origin}/#/admin`, { waitUntil: 'domcontentloaded' });
  await page.locator('#roi-login-email').fill('admin@example.com');
  await page.locator('#roi-login-password').fill('responsive-test-password');
  await page.locator('#roi-login-submit').click();
  await page.waitForFunction(() => window.location.hash === '#/admin/dashboard');
  await page.locator('#roi-tabs-nav').waitFor({ state: 'visible', timeout: 2000 });
  await assertNoHorizontalOverflow(page, `${label} admin dashboard`);
  await page.locator('#roi-logout').waitFor({ state: 'visible', timeout: 2000 });
}

async function launchBrowser() {
  const attempts = [];
  if (process.env.PLAYWRIGHT_EXECUTABLE_PATH) attempts.push({ executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH });
  if (process.env.PLAYWRIGHT_CHANNEL) attempts.push({ channel: process.env.PLAYWRIGHT_CHANNEL });
  attempts.push({});
  attempts.push({ channel: 'chrome' });
  attempts.push({ channel: 'chromium' });

  const errors = [];
  for (const attempt of attempts) {
    try {
      return await chromium.launch({ headless: true, ...attempt });
    } catch (err) {
      errors.push(`${JSON.stringify(attempt)}: ${err.message.split('\n')[0]}`);
    }
  }

  throw new Error(`Unable to launch a Playwright browser. Tried downloaded Chromium and system channels. ${errors.join(' | ')}`);
}

const { server, origin } = await startServer();
let browser;

try {
  browser = await launchBrowser();
  for (const viewport of viewports) {
    const page = await browser.newPage({ viewport });
    const pageErrors = [];
    const failedResponses = [];
    page.setDefaultTimeout(10000);
    page.setDefaultNavigationTimeout(15000);
    page.on('pageerror', (err) => {
      pageErrors.push(err);
    });
    page.on('response', (response) => {
      if (response.status() >= 400) {
        failedResponses.push(`${response.status()} ${response.url()}`);
      }
    });

    for (const route of routes) {
      const url = `${origin}/#${route}`;
      const label = `${viewport.name} ${route}`;
      await page.goto(url, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(150);
      assert.deepEqual(pageErrors.splice(0), [], `${label} has unexpected page errors`);
      assert.deepEqual(failedResponses.splice(0), [], `${label} has failed responses`);
      await assertNoHorizontalOverflow(page, label);
      if (!route.startsWith('/admin')) {
        await assertDonateInViewport(page, label);
      }
    }

    await page.goto(`${origin}/#/`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(150);
    await assertHeaderDrawer(page, `${viewport.name} header drawer`);
    assert.deepEqual(pageErrors.splice(0), [], `${viewport.name} header drawer has unexpected page errors`);
    assert.deepEqual(failedResponses.splice(0), [], `${viewport.name} header drawer has failed responses`);
    await page.goto(`${origin}/#/`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(150);
    await assertDonationModal(page, `${viewport.name} home`);
    assert.deepEqual(pageErrors.splice(0), [], `${viewport.name} donation modal has unexpected page errors`);
    assert.deepEqual(failedResponses.splice(0), [], `${viewport.name} donation modal has failed responses`);
    await assertAdminDashboard(page, viewport.name);
    assert.deepEqual(pageErrors.splice(0), [], `${viewport.name} admin dashboard has unexpected page errors`);
    assert.deepEqual(failedResponses.splice(0), [], `${viewport.name} admin dashboard has failed responses`);
    await page.close();
  }
  const enabledPage = await browser.newPage({ viewport: { width: 375, height: 667 } });
  await assertEnabledDonationModalSafety(enabledPage);
  await enabledPage.close();
  assert.equal(outsideClickCovered, true, 'responsive header drawer outside-click path was not exercised by any viewport');
} finally {
  if (browser) await browser.close();
  await new Promise((resolve) => server.close(resolve));
}
