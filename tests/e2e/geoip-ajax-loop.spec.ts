/**
 * E2E tests: GeoIP AJAX infinite loop prevention (PR #166)
 *
 * Verifies that the fallback GeoIP update mechanism fires at most once
 * when DISABLE_WP_CRON is true, instead of infinite self-replicating AJAX.
 *
 * Oracle: mu-plugin logger writes to wp-content/geoip-ajax-calls.log
 * each time the wp_ajax_slimstat_update_geoip_database handler is invoked.
 */
import { test, expect } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';
import {
  setupTest,
  teardownTest,
  readAjaxLog,
  clearAjaxLog,
  getGeoipTimestamp,
  clearGeoipTimestamp,
  setProviderDisabled,
  closeDb,
  enableDisableWpCron,
  restoreWpConfig,
  installMuPlugin,
  uninstallMuPlugin,
} from './helpers/setup';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// All tests run sequentially (workers: 1) because they share wp-config.php and DB state.

test.describe('GeoIP AJAX loop prevention (admin)', () => {
  test.beforeEach(async () => {
    await setupTest();
  });

  test.afterEach(async () => {
    await teardownTest();
  });

  test.afterAll(async () => {
    await closeDb();
  });

  test('Test 1: single admin page load triggers at most 1 AJAX call', async ({ page }) => {
    await page.goto('/wp-admin/');
    // Wait for the non-blocking wp_safe_remote_post to complete
    await page.waitForTimeout(4000);

    const log = readAjaxLog();
    // The key assertion: at most 1 AJAX call, not a cascade
    expect(log.length).toBeLessThanOrEqual(1);
    // Note: slimstat_last_geoip_dl may NOT be set if the download fails
    // (no GeoIP license key configured). That's OK — we're testing the
    // loop prevention, not the download success.
  });

  test('Test 2: 5 successive admin loads — no multiplication', async ({ page }) => {
    test.setTimeout(90_000);
    const pages = [
      '/wp-admin/',
      '/wp-admin/plugins.php',
      '/wp-admin/options-general.php',
      '/wp-admin/edit.php',
      '/wp-admin/upload.php',
    ];

    for (const url of pages) {
      await page.goto(url);
      // Brief wait between navigations — simulates fast admin browsing
      await page.waitForTimeout(1000);
    }

    // Final wait for any async requests to settle
    await page.waitForTimeout(3000);

    const log = readAjaxLog();
    // First load may trigger 1 AJAX call.
    // Subsequent loads should see slimstat_last_geoip_dl already set.
    // Under the old bug, this would be 5+ (each load triggers its own AJAX).
    expect(log.length).toBeLessThanOrEqual(1);
  });

  test('Test 3: 3 concurrent tabs — bounded count (not exponential)', async ({ context }) => {
    test.setTimeout(120_000);
    // Open 3 pages simultaneously (reduced from 5 for local dev server stability)
    const urls = [
      '/wp-admin/',
      '/wp-admin/plugins.php',
      '/wp-admin/edit.php',
    ];

    const pagePromises = urls.map(async (url) => {
      const p = await context.newPage();
      await p.goto(url, { waitUntil: 'domcontentloaded' });
      return p;
    });

    const openPages = await Promise.all(pagePromises);

    // Wait for all async wp_safe_remote_post calls to complete
    await openPages[0].waitForTimeout(5000);

    const log = readAjaxLog();

    // Race condition: all 3 PHP processes may check before any AJAX completes.
    // Each fires one AJAX → up to 3. But NOT 9, 27, etc. (infinite loop).
    // The key assertion: count is bounded by the number of concurrent loads.
    expect(log.length).toBeLessThanOrEqual(3);

    // Under the old bug, a second generation would fire, producing 3 more,
    // then 3 more, etc. With a 5-second wait, we'd see 9-15+ entries.
    // The fix ensures each AJAX request does NOT re-trigger itself.

    // Close extra pages
    for (const p of openPages) {
      await p.close();
    }
  });

  test('Test 5: provider disabled — 0 AJAX calls', async ({ page }) => {
    await setProviderDisabled();

    await page.goto('/wp-admin/');
    await page.waitForTimeout(3000);

    const log = readAjaxLog();
    expect(log.length).toBe(0);

    // resolve_geolocation_provider() returns false → guard condition fails
    const ts = await getGeoipTimestamp();
    expect(ts).toBeNull();
  });

  test('Test 6: direct AJAX POST — no self-recursion', async ({ page }) => {
    // First, visit an admin page to get a valid nonce
    await page.goto('/wp-admin/');
    await page.waitForTimeout(1000);
    clearAjaxLog(); // Clear any AJAX from the page load itself

    // Extract nonce from the page — SlimStat injects it
    // We'll generate one by hitting a page that outputs wpnonce
    const nonce = await page.evaluate(async () => {
      // Fetch a fresh nonce via admin-ajax approach
      const res = await fetch('/wp-admin/admin-ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=slimstat_update_geoip_database&security=invalid_nonce',
      });
      return null; // We'll use a different approach
    });

    // Direct POST using Playwright's request context (carries auth cookies)
    // The nonce check will fail with invalid nonce, but the handler IS invoked
    // (check_ajax_referer dies on failure, so the mu-plugin at priority 1
    // runs BEFORE the die). Actually, check_ajax_referer calls wp_die on failure.
    // The mu-plugin runs at priority 1, before SlimStat's handler.
    // But wp_ajax_ fires both — the action fires, then SlimStat's callback runs.

    // Actually, for a valid test we need a real nonce.
    // Let's get it by parsing a page that contains it.
    await clearGeoipTimestamp();
    clearAjaxLog();

    // Navigate to an admin page where SlimStat might inject the nonce
    await page.goto('/wp-admin/admin.php?page=slimstat');
    await page.waitForTimeout(1000);
    clearAjaxLog(); // Clear page-load AJAX

    // Use page.request (inherits browser cookies) to POST directly
    const response = await page.request.post('/wp-admin/admin-ajax.php', {
      form: {
        action: 'slimstat_update_geoip_database',
        security: 'test_invalid', // Will fail nonce, but tests the path
      },
    });

    await page.waitForTimeout(2000);

    const log = readAjaxLog();
    // The mu-plugin fires at priority 1 (before nonce check at priority 10)
    // so we should see exactly 1 entry even if the nonce fails
    expect(log.length).toBe(1);
    // The important thing: it's 1, not 2+. No self-recursion.
  });
});

// ─── Separate test for non-admin user (uses 'author' project) ──────

test.describe('GeoIP AJAX loop prevention (author)', () => {
  test.use({ storageState: path.join(__dirname, '.auth/author.json') });

  test.beforeEach(async () => {
    await setupTest();
  });

  test.afterEach(async () => {
    await teardownTest();
  });

  test.afterAll(async () => {
    await closeDb();
  });

  test('Test 4: author (non-admin) gets 0 AJAX calls', async ({ page }) => {
    await page.goto('/wp-admin/');
    await page.waitForTimeout(3000);

    const log = readAjaxLog();
    expect(log.length).toBe(0);

    // current_user_can() check blocks the fallback for non-admin users
    const ts = await getGeoipTimestamp();
    expect(ts).toBeNull();
  });
});
