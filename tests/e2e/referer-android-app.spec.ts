/**
 * E2E regression tests for #306 — android-app:// (Google Discover) referers.
 *
 * Before the fix, Ajax.php / Processor.php ran the referer through sanitize_url(),
 * which strips any scheme absent from wp_allowed_protocols() — emptying
 * `android-app://com.google.android.googlequicksearchbox/`. The fix swaps both
 * call sites to sanitize_text_field(); the scheme allowlist in Processor::process()
 * (http/https/android-app) remains the XSS boundary.
 *
 * Strategy: navigate directly (no prior page → empty document.referrer → empty JS
 * `ref`), so Processor falls back to $_SERVER['HTTP_REFERER'], which the
 * header-injector mu-plugin sets from e2e-header-overrides.json. This exercises the
 * Processor server-fallback path end-to-end.
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
  installHeaderInjector,
  uninstallHeaderInjector,
  setHeaderOverrides,
  clearHeaderOverrides,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';

let pool: mysql.Pool;

function getPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool(MYSQL_CONFIG);
  }
  return pool;
}

async function waitForStatRow(
  marker: string,
  timeoutMs = 15_000,
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

/**
 * Poll until a row for the marker has a non-empty referer. In REST/JS transport a
 * pageview row can be inserted before the server-side referer fallback populates it,
 * so asserting on the first row that appears is racy — wait for the column instead.
 */
async function waitForReferer(
  marker: string,
  timeoutMs = 15_000,
  intervalMs = 500,
): Promise<string | null> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const [rows] = (await getPool().execute(
      "SELECT referer FROM wp_slim_stats WHERE resource LIKE ? AND referer IS NOT NULL AND referer <> '' ORDER BY id DESC LIMIT 1",
      [`%${marker}%`],
    )) as any;
    if (rows.length > 0) return rows[0].referer;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return null;
}

const ANDROID_APP = 'android-app://com.google.android.googlequicksearchbox/';

test.describe('Issue #306 — android-app referers preserved through tracker', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
    // installHeaderInjector() writes SLIMSTAT_E2E_TESTING into wp-config.php. Under
    // LocalWP's opcache (validate_timestamps on, revalidate_freq=2s) that change is
    // not visible to PHP for up to ~2s, so the first tracked request could run before
    // the header-injector mu-plugin activates. Settle past the opcache window once,
    // here, so every test sees the injector. (In CI the constant is set at global
    // setup, well before any test, so this race does not occur.)
    installHeaderInjector();
    await new Promise((r) => setTimeout(r, 2500));
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOption(page, 'tracking_request_method', 'rest');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    clearHeaderOverrides();
  });

  test.afterEach(async () => {
    clearHeaderOverrides();
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallHeaderInjector();
    uninstallOptionMutator();
    if (pool) await pool.end();
    await closeDb();
  });

  test('android-app:// referer is stored verbatim (server fallback)', async ({ page }) => {
    // key "referer" → mu-plugin sets $_SERVER['HTTP_REFERER']
    setHeaderOverrides({ referer: ANDROID_APP });

    const marker = `android-app-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('networkidle');

    const referer = await waitForReferer(marker);
    expect(referer, 'android-app referer must be stored').toBe(ANDROID_APP);
  });

  test('javascript: scheme referer is rejected by the scheme allowlist', async ({ page }) => {
    setHeaderOverrides({ referer: 'javascript:alert(1)' });

    const marker = `xss-scheme-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('networkidle');

    const stat = await waitForStatRow(marker);
    expect(stat).toBeTruthy();
    // Processor::process() unsets a referer whose scheme is outside http/https/android-app.
    expect(stat!.referer === '' || stat!.referer === null).toBe(true);
  });

  test('http(s) referer baseline still stored (no regression)', async ({ page }) => {
    setHeaderOverrides({ referer: 'https://example.com/?utm_source=x' });

    const marker = `http-baseline-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('networkidle');

    const referer = await waitForReferer(marker);
    expect(referer).toBe('https://example.com/?utm_source=x');
  });
});
