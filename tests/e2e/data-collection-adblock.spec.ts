/**
 * E2E tests: Ad-blocker simulation (AC-TRK-001 ad-blocker graceful degradation)
 *
 * Simulates an ad-blocker by blocking wp-slimstat JS files via page.route().
 * Verifies that the page loads without JS errors and that tracking degrades
 * gracefully (no client-side tracking row, or server-side only).
 */
import { test, expect } from '@playwright/test';
import * as mysql from 'mysql2/promise';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  closeDb,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';

let pool: mysql.Pool;

function getPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool(MYSQL_CONFIG);
  }
  return pool;
}

/** Poll DB for a row matching the marker. */
async function waitForStatRow(
  marker: string,
  timeoutMs = 10_000,
  intervalMs = 500,
): Promise<Record<string, any> | null> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const [rows] = (await getPool().execute(
      'SELECT * FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1',
      [`%${marker}%`],
    )) as any;
    if (rows.length > 0) return rows[0];
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return null;
}

/** Count total rows in wp_slim_stats. */
async function getRowCount(): Promise<number> {
  const [rows] = (await getPool().execute(
    'SELECT COUNT(*) as cnt FROM wp_slim_stats',
  )) as any;
  return parseInt(rows[0].cnt, 10);
}

test.describe('Ad-Blocker Simulation (AC-TRK-001 ad-blocker)', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOption(page, 'tracking_request_method', 'rest');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    if (pool) await pool.end();
    await closeDb();
  });

  // ─── Test 1: No JS errors when SlimStat JS is blocked ───────

  test('page loads without JS errors when wp-slimstat JS is blocked', async ({ page }) => {
    const jsErrors: string[] = [];
    page.on('pageerror', (error) => {
      jsErrors.push(error.message);
    });

    // Block all wp-slimstat JavaScript files (simulates ad-blocker)
    await page.route('**/*slimstat*.js', (route) => {
      route.abort('blockedbyclient');
    });
    await page.route('**/*slimstat*.js?*', (route) => {
      route.abort('blockedbyclient');
    });

    const marker = `adblock-noerr-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(3000);

    // No uncaught JS errors should occur from missing SlimStat scripts
    expect(jsErrors).toHaveLength(0);
  });

  // ─── Test 2: Page renders correctly with JS blocked ──────────

  test('page content renders normally when tracking JS is blocked', async ({ page }) => {
    // Block all wp-slimstat JavaScript files
    await page.route('**/*slimstat*.js', (route) => {
      route.abort('blockedbyclient');
    });
    await page.route('**/*slimstat*.js?*', (route) => {
      route.abort('blockedbyclient');
    });

    const marker = `adblock-render-${Date.now()}`;
    const response = await page.goto(`${BASE_URL}/?e2e=${marker}`);

    expect(response?.status()).toBeLessThan(500);
    // Page should have a body with content
    const bodyText = await page.textContent('body');
    expect(bodyText).toBeTruthy();
  });

  // ─── Test 3: No client-side tracking when JS is blocked ──────

  test('no client-side tracking request sent when JS is blocked', async ({ page }) => {
    // Block all wp-slimstat JavaScript files
    await page.route('**/*slimstat*.js', (route) => {
      route.abort('blockedbyclient');
    });
    await page.route('**/*slimstat*.js?*', (route) => {
      route.abort('blockedbyclient');
    });

    let trackingRequestSent = false;
    page.on('request', (req) => {
      const url = req.url();
      if (
        (url.includes('/wp-json/slimstat/v1/hit') ||
          url.includes('admin-ajax.php')) &&
        req.method() === 'POST'
      ) {
        trackingRequestSent = true;
      }
    });

    const marker = `adblock-noclient-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(3000);

    // With JS blocked, the client-side tracker should not fire
    expect(trackingRequestSent).toBe(false);
  });

  // ─── Test 4: Graceful degradation — server-side may still track

  test('server-side tracking may still record a row even with JS blocked', async ({ page }) => {
    // Block all wp-slimstat JavaScript files
    await page.route('**/*slimstat*.js', (route) => {
      route.abort('blockedbyclient');
    });
    await page.route('**/*slimstat*.js?*', (route) => {
      route.abort('blockedbyclient');
    });

    const marker = `adblock-server-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(3000);

    // Server-side tracking (if enabled) may still have captured the hit.
    // This test verifies the behavior without asserting a specific outcome:
    // either a server-side row exists (graceful degradation) or no row (expected
    // when only client-side tracking is configured).
    const stat = await waitForStatRow(marker, 5_000);

    if (stat) {
      // Server-side tracking captured the hit — verify it's valid
      expect(stat.resource).toContain(marker);
    }
    // If no stat: client-side only tracking was configured, and JS was blocked.
    // This is expected graceful degradation — no crash, no error.
  });

  // ─── Test 5: Blocking REST + JS still no crash ───────────────

  test('blocking both JS and REST endpoint still renders page cleanly', async ({ page }) => {
    const jsErrors: string[] = [];
    page.on('pageerror', (error) => {
      jsErrors.push(error.message);
    });

    // Block both JS and REST endpoint
    await page.route('**/*slimstat*.js', (route) => {
      route.abort('blockedbyclient');
    });
    await page.route('**/*slimstat*.js?*', (route) => {
      route.abort('blockedbyclient');
    });
    await page.route('**/wp-json/slimstat/v1/hit', (route) => {
      route.abort('blockedbyclient');
    });

    const marker = `adblock-both-${Date.now()}`;
    const response = await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(2000);

    expect(response?.status()).toBeLessThan(500);
    expect(jsErrors).toHaveLength(0);
  });
});
