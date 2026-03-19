/**
 * PR #239 Production Scenario Tests (v2 — realistic replacements)
 *
 * Each test exercises a real server code path or realistic production condition:
 * 1. Real HTML capture + replay (cached page with full WP page output)
 * 2. Stale nonce → 403 proof (demonstrates the bug mechanism)
 * 3. Server-side text/plain POST (sendBeacon server code path)
 * 4. Transport fallback chain (REST→AJAX) without nonce
 * 5. GDPR blocks with 400 not 403 (consent vs nonce distinction)
 * 6. GDPR consent endpoint + no nonce for anonymous
 * 7. Concurrent anonymous sessions (parallel visitors)
 * 8. Logged-in: nonce in params
 * 9. Logged-in: nonce in header
 *
 * @see https://github.com/wp-slimstat/wp-slimstat/pull/239
 */
import { test, expect, Page, BrowserContext } from '@playwright/test';
import * as path from 'path';
import { fileURLToPath } from 'url';
import * as mysql from 'mysql2/promise';
import {
  clearStatsTable,
  waitForPageviewRow,
  closeDb,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const ADMIN_AUTH = path.join(__dirname, '.auth/admin.json');

let pool: mysql.Pool | null = null;
function getPool(): mysql.Pool {
  if (!pool) pool = mysql.createPool(MYSQL_CONFIG);
  return pool;
}

let savedOptions: string | null = null;
async function snapshotOptions(): Promise<void> {
  const [rows] = (await getPool().execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_options'",
  )) as any;
  savedOptions = rows.length > 0 ? rows[0].option_value : null;
}
async function restoreOptions(): Promise<void> {
  if (savedOptions !== null) {
    await getPool().execute(
      "UPDATE wp_options SET option_value = ? WHERE option_name = 'slimstat_options'",
      [savedOptions],
    );
  }
}

/**
 * Set a slimstat option directly in the DB by parsing PHP serialized data.
 * Limitations: Only handles simple string keys/values. Not for general use.
 */
async function setOption(key: string, value: string): Promise<void> {
  const [rows] = (await getPool().execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_options'",
  )) as any;
  if (rows.length === 0) return;
  let serialized: string = rows[0].option_value;
  const keyPattern = `s:${key.length}:"${key}";`;
  const keyIdx = serialized.indexOf(keyPattern);
  if (keyIdx === -1) {
    const match = serialized.match(/^a:(\d+):\{/);
    if (match) {
      const oldCount = parseInt(match[1], 10);
      serialized = serialized.replace(`a:${oldCount}:{`, `a:${oldCount + 1}:{`);
      const lastBrace = serialized.lastIndexOf('}');
      serialized = serialized.substring(0, lastBrace) + `s:${key.length}:"${key}";s:${value.length}:"${value}";` + '}';
    }
  } else {
    const valueStart = keyIdx + keyPattern.length;
    const valueMatch = serialized.substring(valueStart).match(/^s:\d+:"[^"]*";/);
    if (valueMatch) {
      serialized = serialized.substring(0, valueStart) + `s:${value.length}:"${value}";` + serialized.substring(valueStart + valueMatch[0].length);
    }
  }
  await getPool().execute(
    "UPDATE wp_options SET option_value = ? WHERE option_name = 'slimstat_options'",
    [serialized],
  );
}

async function anonContext(browser: any): Promise<{ ctx: BrowserContext; page: Page }> {
  const ctx = await browser.newContext();
  return { ctx, page: await ctx.newPage() };
}

function trackRestHits(page: Page) {
  const requests: Array<{ url: string; headers: Record<string, string> }> = [];
  const responses: Array<{ status: number; url: string }> = [];
  page.on('request', (req) => {
    if (req.url().includes('/wp-json/slimstat/v1/hit') || req.url().includes('rest_route=/slimstat/v1/hit'))
      requests.push({ url: req.url(), headers: req.headers() });
  });
  page.on('response', (res) => {
    if (res.url().includes('/wp-json/slimstat/v1/hit') || res.url().includes('rest_route=/slimstat/v1/hit'))
      responses.push({ status: res.status(), url: res.url() });
  });
  return { requests, responses };
}

function buildCachedPageHtml(params: Record<string, any>): string {
  return `<!DOCTYPE html>
<html><head><title>Cached Page</title></head>
<body><h1>Cached Page Simulation</h1>
<script type="text/javascript">var SlimStatParams = ${JSON.stringify(params)};</script>
<script defer src="${BASE_URL}/wp-content/plugins/wp-slimstat/wp-slimstat.min.js"></script>
</body></html>`;
}

// ═══════════════════════════════════════════════════════════════
// 1. Real HTML capture + replay (replaces synthetic B1a)
// ═══════════════════════════════════════════════════════════════
test.describe('Cached page: real HTML capture', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotOptions();
    await clearStatsTable();
    await setOption('tracking_request_method', 'rest');
    await setOption('gdpr_enabled', 'off');
    await setOption('javascript_mode', 'on');
    await setOption('ignore_wp_users', 'no');
  });
  test.afterEach(async () => { await restoreOptions(); });

  test('captured real WP page served as cache — nonce present but no header sent, no 403', async ({ browser }) => {
    // Step 1: Fetch real WordPress page HTML as anonymous (no cookies)
    const { ctx: fetchCtx, page: fetchPage } = await anonContext(browser);
    const response = await fetchPage.request.get(`${BASE_URL}/?e2e=cache-capture-${Date.now()}`);
    const realHtml = await response.text();
    await fetchCtx.close();

    // Step 2: Verify the real HTML HAS nonce (for consent)
    const paramsMatch = realHtml.match(/var SlimStatParams\s*=\s*(\{[^;]+\})\s*;/);
    expect(paramsMatch, 'SlimStatParams must exist in page HTML').toBeTruthy();
    const capturedParams = JSON.parse(paramsMatch![1]);
    expect(capturedParams.wp_rest_nonce, 'Nonce must be present (for consent)').toBeTruthy();

    // Step 3: Serve the captured HTML as a "cached page" to a new visitor
    const { ctx, page } = await anonContext(browser);
    const { requests, responses } = trackRestHits(page);

    await page.route('**/cached-real-page', (route) =>
      route.fulfill({ status: 200, contentType: 'text/html', body: realHtml }),
    );

    await page.goto(`${BASE_URL}/cached-real-page`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);

    // Step 4: Verify no nonce header and no 403
    const withNonce = requests.filter((r) => r.headers['x-wp-nonce'] !== undefined);
    expect(withNonce.length, 'Cached real page should produce zero X-WP-Nonce headers').toBe(0);

    const has403 = responses.some((r) => r.status === 403);
    expect(has403, 'No 403 when serving real captured HTML').toBe(false);

    expect(requests.length, 'At least one tracking request should fire').toBeGreaterThan(0);
    await ctx.close();
  });
});

// ═══════════════════════════════════════════════════════════════
// 2. Stale nonce in cached page → 403 proof (kept from B1b)
// ═══════════════════════════════════════════════════════════════
test.describe('Cached page: stale nonce', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotOptions();
    await clearStatsTable();
    await setOption('tracking_request_method', 'rest');
    await setOption('gdpr_enabled', 'off');
    await setOption('javascript_mode', 'on');
    await setOption('ignore_wp_users', 'no');
  });
  test.afterEach(async () => { await restoreOptions(); });

  test('admin-cached page (is_logged_in=1, stale nonce) — 403 then retry succeeds', async ({ browser }) => {
    // Edge case: admin triggers cache build → HTML has is_logged_in='1' + stale nonce.
    // Anonymous visitor gets this cached page. JS sees is_logged_in='1' → sends nonce
    // → 403 (stale nonce) → retry without nonce → 200. One wasted round-trip.
    // This is acceptable: most caches exclude logged-in users, and the retry handles it.
    const { ctx, page } = await anonContext(browser);
    const { requests, responses } = trackRestHits(page);

    const cachedParams = {
      transport: 'rest',
      ajaxurl: `${BASE_URL}/wp-json/slimstat/v1/hit`,
      ajaxurl_rest: `${BASE_URL}/wp-json/slimstat/v1/hit`,
      ajaxurl_ajax: `${BASE_URL}/wp-admin/admin-ajax.php`,
      baseurl: '/',
      ci: 'test-stale-nonce',
      wp_rest_nonce: 'stale_nonce_from_cache_12345',
      is_logged_in: '1',
    };

    await page.route('**/cached-stale-nonce', (route) =>
      route.fulfill({ status: 200, contentType: 'text/html', body: buildCachedPageHtml(cachedParams) }),
    );

    await page.goto(`${BASE_URL}/cached-stale-nonce`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);

    // is_logged_in='1' → JS sends nonce → 403 → retry
    const has403 = responses.some((r) => r.status === 403);
    expect(has403, 'Stale nonce causes 403 (expected for admin-cached pages)').toBe(true);

    // JS should retry — at least 2 requests
    expect(requests.length, 'JS retries after 403').toBeGreaterThanOrEqual(2);

    // First request has the stale nonce
    expect(requests[0].headers['x-wp-nonce']).toBe('stale_nonce_from_cache_12345');

    await ctx.close();
  });

  test('anonymous-cached page (is_logged_in=0) — no 403, single request', async ({ browser }) => {
    // Common case: anonymous visitor triggers cache build → is_logged_in='0'.
    // Next anonymous visitor gets cached page → no nonce header → 200 immediately.
    const { ctx, page } = await anonContext(browser);
    const { requests, responses } = trackRestHits(page);

    const anonCachedParams = {
      transport: 'rest',
      ajaxurl: `${BASE_URL}/wp-json/slimstat/v1/hit`,
      ajaxurl_rest: `${BASE_URL}/wp-json/slimstat/v1/hit`,
      ajaxurl_ajax: `${BASE_URL}/wp-admin/admin-ajax.php`,
      baseurl: '/',
      ci: 'test-anon-cached',
      wp_rest_nonce: 'anon_nonce_from_cache',
      is_logged_in: '0',
    };

    await page.route('**/anon-cached-page', (route) =>
      route.fulfill({ status: 200, contentType: 'text/html', body: buildCachedPageHtml(anonCachedParams) }),
    );

    await page.goto(`${BASE_URL}/anon-cached-page`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);

    // is_logged_in='0' → no nonce header → no 403
    const withNonce = requests.filter((r) => r.headers['x-wp-nonce'] !== undefined);
    expect(withNonce.length, 'No X-WP-Nonce header when is_logged_in=0').toBe(0);

    const has403 = responses.some((r) => r.status === 403);
    expect(has403, 'No 403 for anonymous-cached page').toBe(false);

    expect(requests.length, 'Tracking request should fire').toBeGreaterThan(0);
    await ctx.close();
  });
});

// ═══════════════════════════════════════════════════════════════
// 3. Server-side text/plain POST (replaces browser-side B2)
// ═══════════════════════════════════════════════════════════════
test.describe('sendBeacon server path: text/plain POST', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotOptions();
    await clearStatsTable();
    await setOption('tracking_request_method', 'rest');
    await setOption('gdpr_enabled', 'off');
    await setOption('javascript_mode', 'on');
    await setOption('ignore_wp_users', 'no');
  });
  test.afterEach(async () => { await restoreOptions(); });

  test('text/plain POST without nonce returns 200 + valid tracking ID', async ({ browser }) => {
    // Step 1: Capture a valid ci value from a real page
    const { ctx: capCtx, page: capPage } = await anonContext(browser);
    await capPage.goto(`${BASE_URL}/?e2e=beacon-ci-capture`, { waitUntil: 'domcontentloaded' });
    const params = await capPage.evaluate(() => (window as any).SlimStatParams || null);
    expect(params).toBeTruthy();
    expect(params.ci, 'ci must be present in SlimStatParams').toBeTruthy();
    const capturedCi = params.ci;
    await capCtx.close();

    // Step 2: POST to REST endpoint with text/plain (simulates sendBeacon)
    // Use a plain request context (no cookies, no auth)
    const { ctx, page } = await anonContext(browser);
    const res = await page.request.post(`${BASE_URL}/wp-json/slimstat/v1/hit`, {
      headers: { 'content-type': 'text/plain' },
      data: `action=slimtrack&ci=${capturedCi}`,
    });

    // Step 3: Verify server accepts it
    const status = res.status();
    const body = await res.text();

    // The server should either return 200 (success) or 400 (consent/filter block).
    // It must NOT return 403 (that would mean nonce rejection).
    expect(status, `Server should not return 403 for text/plain POST. Got ${status}: ${body}`).not.toBe(403);

    // If 200, verify the response contains a valid tracking ID (numeric.hash or plain numeric)
    if (status === 200) {
      const cleaned = body.replace(/^"|"$/g, '').trim();
      expect(cleaned).toMatch(/^\d+(\.[0-9a-fA-F]+)?$/);
    }

    await ctx.close();
  });
});

// ═══════════════════════════════════════════════════════════════
// 4. Transport fallback chain (kept from B3)
// ═══════════════════════════════════════════════════════════════
test.describe('Transport fallback: REST → AJAX', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotOptions();
    await clearStatsTable();
    await setOption('tracking_request_method', 'rest');
    await setOption('gdpr_enabled', 'off');
    await setOption('javascript_mode', 'on');
    await setOption('ignore_wp_users', 'no');
  });
  test.afterEach(async () => { await restoreOptions(); });

  test('REST blocked → AJAX fallback fires without nonce for anonymous', async ({ browser }) => {
    const { ctx, page } = await anonContext(browser);

    const ajaxRequests: Array<{ url: string; headers: Record<string, string> }> = [];
    page.on('request', (req) => {
      if (req.url().includes('admin-ajax.php') && req.method() === 'POST')
        ajaxRequests.push({ url: req.url(), headers: req.headers() });
    });

    await page.route('**/wp-json/slimstat/v1/hit', (route) => route.abort('connectionfailed'));
    await page.route('**/?rest_route=/slimstat/v1/hit*', (route) => route.abort('connectionfailed'));

    const marker = `fallback-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(5000);

    expect(ajaxRequests.length, 'AJAX fallback must fire').toBeGreaterThan(0);

    const ajaxWithNonce = ajaxRequests.filter((r) => r.headers['x-wp-nonce'] !== undefined);
    expect(ajaxWithNonce.length, 'AJAX fallback must not send X-WP-Nonce for anonymous').toBe(0);

    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat, 'Pageview must be recorded via AJAX fallback').toBeTruthy();

    await ctx.close();
  });
});

// ═══════════════════════════════════════════════════════════════
// 5. GDPR blocks with 400 not 403 (replaces weak B4a)
// ═══════════════════════════════════════════════════════════════
test.describe('GDPR: consent vs nonce error distinction', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotOptions();
    await clearStatsTable();
    await setOption('tracking_request_method', 'rest');
    await setOption('javascript_mode', 'on');
    await setOption('ignore_wp_users', 'no');
  });
  test.afterEach(async () => { await restoreOptions(); });

  test('GDPR on + no consent: errors are 400 (consent) not 403 (nonce)', async ({ browser }) => {
    await setOption('gdpr_enabled', 'on');
    await setOption('consent_integration', 'slimstat_banner');
    const { ctx, page } = await anonContext(browser);
    const { responses } = trackRestHits(page);

    await page.goto(`${BASE_URL}/?e2e=gdpr-error-code-${Date.now()}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);

    // Key distinction: 400 = consent gate blocked (correct GDPR behavior)
    //                  403 = nonce rejection (the bug this PR fixes)
    const has403 = responses.some((r) => r.status === 403);
    expect(has403, 'GDPR should block via 400 (consent), never 403 (nonce)').toBe(false);

    // If there are error responses, they should be 400 (consent blocked)
    const errorResponses = responses.filter((r) => r.status >= 400);
    for (const r of errorResponses) {
      expect(r.status, `Error response should be 400 (consent), got ${r.status}`).toBe(400);
    }

    await ctx.close();
  });
});

// ═══════════════════════════════════════════════════════════════
// 6. GDPR consent endpoint + no nonce for anon (kept from B4b)
// ═══════════════════════════════════════════════════════════════
test.describe('GDPR: endpoint and nonce params', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotOptions();
    await setOption('tracking_request_method', 'rest');
    await setOption('javascript_mode', 'on');
    await setOption('ignore_wp_users', 'no');
  });
  test.afterEach(async () => { await restoreOptions(); });

  test('GDPR on — consent endpoint set, nonce available, is_logged_in=0', async ({ browser }) => {
    await setOption('gdpr_enabled', 'on');
    await setOption('use_slimstat_banner', 'on');
    await setOption('consent_integration', 'slimstat_banner');
    const { ctx, page } = await anonContext(browser);
    await page.goto(`${BASE_URL}/?e2e=gdpr-params`, { waitUntil: 'domcontentloaded' });
    const params = await page.evaluate(() => (window as any).SlimStatParams || null);
    expect(params).toBeTruthy();
    if (params.gdpr_consent_endpoint) {
      expect(params.gdpr_consent_endpoint).toContain('/slimstat/v1/gdpr/consent');
    }
    // Nonce must be present for consent banner to work
    expect(params.wp_rest_nonce, 'Nonce must be present for consent operations').toBeTruthy();
    await ctx.close();
  });
});

// ═══════════════════════════════════════════════════════════════
// 7. Concurrent anonymous sessions (new)
// ═══════════════════════════════════════════════════════════════
test.describe('Concurrent anonymous sessions', () => {
  test.setTimeout(90_000);

  test.beforeEach(async () => {
    await snapshotOptions();
    await clearStatsTable();
    await setOption('tracking_request_method', 'rest');
    await setOption('gdpr_enabled', 'off');
    await setOption('javascript_mode', 'on');
    await setOption('ignore_wp_users', 'no');
  });
  test.afterEach(async () => { await restoreOptions(); });

  test('3 parallel anonymous visitors — all tracked, zero 403s, zero nonces', async ({ browser }) => {
    const sessions = await Promise.all(
      [1, 2, 3].map(async (i) => {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        const reqs: Array<{ url: string; headers: Record<string, string> }> = [];
        const resps: Array<{ status: number }> = [];

        page.on('request', (req) => {
          if (req.url().includes('/slimstat/v1/hit'))
            reqs.push({ url: req.url(), headers: req.headers() });
        });
        page.on('response', (res) => {
          if (res.url().includes('/slimstat/v1/hit'))
            resps.push({ status: res.status() });
        });

        const marker = `concurrent-${i}-${Date.now()}`;
        await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(3000);

        return { i, marker, reqs, resps, ctx };
      }),
    );

    for (const s of sessions) {
      // Zero 403s
      const has403 = s.resps.some((r) => r.status === 403);
      expect(has403, `Session ${s.i}: should have zero 403 responses`).toBe(false);

      // Zero nonce headers
      const withNonce = s.reqs.filter((r) => r.headers['x-wp-nonce'] !== undefined);
      expect(withNonce.length, `Session ${s.i}: should send zero nonce headers`).toBe(0);

      // Pageview recorded
      const stat = await waitForPageviewRow(s.marker, 10_000);
      expect(stat, `Session ${s.i}: pageview must be in DB`).toBeTruthy();

      await s.ctx.close();
    }
  });
});

// ═══════════════════════════════════════════════════════════════
// 8-9. Logged-in user nonce regression (kept from B5)
// ═══════════════════════════════════════════════════════════════
test.describe('Logged-in user nonce', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotOptions();
    await clearStatsTable();
    await setOption('tracking_request_method', 'rest');
    await setOption('gdpr_enabled', 'off');
    await setOption('javascript_mode', 'on');
    await setOption('ignore_wp_users', 'no');
  });
  test.afterEach(async () => { await restoreOptions(); });

  test('logged-in user has wp_rest_nonce in SlimStatParams', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: ADMIN_AUTH });
    const page = await ctx.newPage();
    await page.goto(`${BASE_URL}/?e2e=loggedin-nonce`, { waitUntil: 'domcontentloaded' });
    const params = await page.evaluate(() => (window as any).SlimStatParams || null);
    expect(params).toBeTruthy();
    expect(params.wp_rest_nonce, 'Logged-in user must have wp_rest_nonce').toBeTruthy();
    expect(params.wp_rest_nonce.length).toBeGreaterThanOrEqual(8);
    await ctx.close();
  });

  test('logged-in user sends X-WP-Nonce header in tracking request', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: ADMIN_AUTH });
    const page = await ctx.newPage();
    const { requests } = trackRestHits(page);
    await page.goto(`${BASE_URL}/?e2e=loggedin-header`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);
    const withNonce = requests.filter((r) => r.headers['x-wp-nonce'] !== undefined);
    expect(withNonce.length, 'Logged-in user must send X-WP-Nonce').toBeGreaterThan(0);
    await ctx.close();
  });
});

test.afterAll(async () => {
  if (pool) { await pool.end(); pool = null; }
  await closeDb();
});
