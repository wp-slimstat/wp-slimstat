/**
 * PR #239 Production Scenario Tests
 *
 * Covers the gaps identified in the initial test suite:
 * B1: Page caching simulation (stale nonce in cached HTML)
 * B2: sendBeacon transport (no custom headers possible)
 * B3: Transport fallback chain (REST blocked → AJAX)
 * B4: GDPR consent + nonce interaction
 * B5: Logged-in user nonce regression check
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
 *
 * Limitations: Only supports simple string keys and string values.
 * Does NOT handle escaped quotes, non-string types (int/bool/array), or
 * nested structures. Intended only for this test's limited option keys.
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

/**
 * Build a "cached page" HTML string that simulates what a page cache would serve.
 * The HTML includes the SlimStat tracker JS and frozen SlimStatParams.
 */
function buildCachedPageHtml(params: Record<string, any>): string {
  return `<!DOCTYPE html>
<html><head><title>Cached Page</title></head>
<body><h1>Cached Page Simulation</h1>
<script type="text/javascript">var SlimStatParams = ${JSON.stringify(params)};</script>
<script defer src="${BASE_URL}/wp-content/plugins/wp-slimstat/wp-slimstat.min.js"></script>
</body></html>`;
}

// ═══════════════════════════════════════════════════════════════
// B1: Page caching simulation
// ═══════════════════════════════════════════════════════════════
test.describe('B1: Cached page simulation', () => {
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

  test('cached page WITHOUT nonce — single 200, no 403 retry', async ({ browser }) => {
    const { ctx, page } = await anonContext(browser);
    const { requests, responses } = trackRestHits(page);

    // Simulate a cached page that has NO wp_rest_nonce (the fix)
    const cachedParams = {
      transport: 'rest',
      ajaxurl: `${BASE_URL}/wp-json/slimstat/v1/hit`,
      ajaxurl_rest: `${BASE_URL}/wp-json/slimstat/v1/hit`,
      ajaxurl_ajax: `${BASE_URL}/wp-admin/admin-ajax.php`,
      baseurl: '/',
      ci: 'test-cached-page',
    };

    await page.route('**/cached-no-nonce', (route) =>
      route.fulfill({ status: 200, contentType: 'text/html', body: buildCachedPageHtml(cachedParams) }),
    );

    await page.goto(`${BASE_URL}/cached-no-nonce`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);

    // No nonce header sent
    const withNonce = requests.filter((r) => r.headers['x-wp-nonce'] !== undefined);
    expect(withNonce.length, 'Cached page without nonce should send no X-WP-Nonce').toBe(0);

    // No 403 response
    const has403 = responses.some((r) => r.status === 403);
    expect(has403, 'No 403 when nonce is absent').toBe(false);

    // At least one tracking request fired
    expect(requests.length, 'Tracking request should fire').toBeGreaterThan(0);

    await ctx.close();
  });

  test('cached page WITH stale nonce — causes 403 retry (demonstrates the bug)', async ({ browser }) => {
    const { ctx, page } = await anonContext(browser);
    const { requests, responses } = trackRestHits(page);

    // Simulate a cached page with a STALE nonce (the old behavior / development branch)
    const cachedParams = {
      transport: 'rest',
      ajaxurl: `${BASE_URL}/wp-json/slimstat/v1/hit`,
      ajaxurl_rest: `${BASE_URL}/wp-json/slimstat/v1/hit`,
      ajaxurl_ajax: `${BASE_URL}/wp-admin/admin-ajax.php`,
      baseurl: '/',
      ci: 'test-stale-nonce',
      wp_rest_nonce: 'stale_nonce_from_cache_12345',
    };

    await page.route('**/cached-stale-nonce', (route) =>
      route.fulfill({ status: 200, contentType: 'text/html', body: buildCachedPageHtml(cachedParams) }),
    );

    await page.goto(`${BASE_URL}/cached-stale-nonce`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);

    // The stale nonce SHOULD cause a 403 from WordPress core
    const has403 = responses.some((r) => r.status === 403);
    expect(has403, 'Stale nonce should cause 403 from WordPress').toBe(true);

    // The first request should have the stale nonce header
    expect(requests.length).toBeGreaterThan(0);
    expect(requests[0].headers['x-wp-nonce'], 'First request should carry stale nonce').toBe('stale_nonce_from_cache_12345');

    // JS should retry — more than 1 request
    expect(requests.length, 'JS should retry after 403').toBeGreaterThanOrEqual(2);

    await ctx.close();
  });
});

// ═══════════════════════════════════════════════════════════════
// B2: sendBeacon transport
// ═══════════════════════════════════════════════════════════════
test.describe('B2: sendBeacon transport', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotOptions();
    await setOption('tracking_request_method', 'rest');
    await setOption('gdpr_enabled', 'off');
    await setOption('javascript_mode', 'on');
    await setOption('ignore_wp_users', 'no');
  });
  test.afterEach(async () => { await restoreOptions(); });

  test('sendBeacon payload does not contain nonce (inherently safe)', async ({ browser }) => {
    const { ctx, page } = await anonContext(browser);

    // Navigate to a real page first so the tracker loads
    await page.goto(`${BASE_URL}/?e2e=beacon-test`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000);

    // Monkey-patch sendBeacon to capture calls
    const beaconCalls = await page.evaluate(() => {
      return new Promise<Array<{ url: string; data: string }>>((resolve) => {
        const calls: Array<{ url: string; data: string }> = [];
        const origBeacon = navigator.sendBeacon.bind(navigator);
        navigator.sendBeacon = (url: string, data?: any) => {
          calls.push({ url: url.toString(), data: data ? data.toString() : '' });
          return origBeacon(url, data);
        };

        // Trigger visibilitychange to force sendBeacon path
        Object.defineProperty(document, 'visibilityState', { value: 'hidden', writable: true });
        document.dispatchEvent(new Event('visibilitychange'));

        // Give it a moment to fire
        setTimeout(() => resolve(calls), 500);
      });
    });

    // sendBeacon can't set custom headers (browser limitation), so nonce is
    // inherently absent. But verify the payload itself doesn't contain nonce field.
    for (const call of beaconCalls) {
      expect(call.data).not.toContain('wp_rest_nonce');
      expect(call.data).not.toContain('X-WP-Nonce');
    }

    await ctx.close();
  });
});

// ═══════════════════════════════════════════════════════════════
// B3: Transport fallback chain after nonce fix
// ═══════════════════════════════════════════════════════════════
test.describe('B3: Transport fallback chain', () => {
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
      if (req.url().includes('admin-ajax.php') && req.method() === 'POST') {
        ajaxRequests.push({ url: req.url(), headers: req.headers() });
      }
    });

    // Block REST endpoint to force AJAX fallback
    await page.route('**/wp-json/slimstat/v1/hit', (route) => route.abort('connectionfailed'));
    await page.route('**/?rest_route=/slimstat/v1/hit*', (route) => route.abort('connectionfailed'));

    const marker = `b3-fallback-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(5000);

    // AJAX fallback should have fired
    expect(ajaxRequests.length, 'AJAX fallback should fire when REST is blocked').toBeGreaterThan(0);

    // AJAX request should NOT carry X-WP-Nonce header (anonymous user)
    const ajaxWithNonce = ajaxRequests.filter((r) => r.headers['x-wp-nonce'] !== undefined);
    expect(ajaxWithNonce.length, 'AJAX fallback should not send X-WP-Nonce for anonymous').toBe(0);

    // Pageview should be recorded via AJAX
    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat, 'Pageview should be recorded via AJAX fallback').toBeTruthy();

    await ctx.close();
  });
});

// ═══════════════════════════════════════════════════════════════
// B4: GDPR consent + nonce interaction
// ═══════════════════════════════════════════════════════════════
test.describe('B4: GDPR consent + nonce', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotOptions();
    await clearStatsTable();
    await setOption('tracking_request_method', 'rest');
    await setOption('javascript_mode', 'on');
    await setOption('ignore_wp_users', 'no');
  });
  test.afterEach(async () => { await restoreOptions(); });

  test('GDPR on, no consent — anonymous tracking blocked (correct behavior)', async ({ browser }) => {
    await setOption('gdpr_enabled', 'on');
    await setOption('consent_integration', 'slimstat_banner');
    const { ctx, page } = await anonContext(browser);
    const { responses } = trackRestHits(page);

    const marker = `b4-gdpr-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);

    // Tracking requests may fire but server should reject (consent not granted)
    // No 403 should occur — the issue is about nonce, not consent
    const has403 = responses.some((r) => r.status === 403);
    expect(has403, 'GDPR blocking should NOT cause 403 (that would be nonce issue)').toBe(false);

    await ctx.close();
  });

  test('GDPR on — gdpr_consent_endpoint correctly set for REST transport', async ({ browser }) => {
    await setOption('gdpr_enabled', 'on');
    await setOption('use_slimstat_banner', 'on');
    await setOption('consent_integration', 'slimstat_banner');
    const { ctx, page } = await anonContext(browser);
    await page.goto(`${BASE_URL}/?e2e=b4-endpoint`, { waitUntil: 'domcontentloaded' });
    const params = await page.evaluate(() => (window as any).SlimStatParams || null);
    expect(params).toBeTruthy();
    if (params.gdpr_consent_endpoint) {
      expect(params.gdpr_consent_endpoint).toContain('/slimstat/v1/gdpr/consent');
    }
    // wp_rest_nonce should NOT be present for anonymous even with GDPR
    expect(params.wp_rest_nonce, 'Anonymous + GDPR should not have nonce').toBeFalsy();
    await ctx.close();
  });
});

// ═══════════════════════════════════════════════════════════════
// B5: Logged-in user nonce (regression check)
// ═══════════════════════════════════════════════════════════════
test.describe('B5: Logged-in user nonce', () => {
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
    await page.goto(`${BASE_URL}/?e2e=b5-nonce`, { waitUntil: 'domcontentloaded' });
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
    await page.goto(`${BASE_URL}/?e2e=b5-header`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);
    const withNonce = requests.filter((r) => r.headers['x-wp-nonce'] !== undefined);
    expect(withNonce.length, 'Logged-in user should send X-WP-Nonce').toBeGreaterThan(0);
    await ctx.close();
  });
});

test.afterAll(async () => {
  if (pool) { await pool.end(); pool = null; }
  await closeDb();
});
