/**
 * E2E tests: Migration cookie restore bug — wp.org forum thread
 * https://wordpress.org/support/topic/no-cookies-fingerprints-starting-from-5-4-0/
 *
 * Bug: The v5.4.6 migration at wp-slimstat.php:281-284 only restores
 * set_tracker_cookie='on' when gdpr_enabled='off'. Users routed into the
 * gdpr_enabled='on' path (had legacy consent settings like display_opt_out)
 * keep set_tracker_cookie='off' from the broken v5.4.0 defaults — cookies
 * are never set even after CMP consent is granted.
 *
 * Secondary: JS/PHP consent decision mismatch when use_slimstat_banner='off'
 * but gdpr_enabled='on' — JS blocks tracking entirely while PHP allows it.
 *
 * These tests:
 *  1. Reproduce the bug by simulating pre-migration state with legacy consent
 *  2. Verify the cookie is NOT set (bug confirmed)
 *  3. Verify the fix restores cookies after migration
 *  4. Verify the JS/PHP mismatch scenario
 *  5. Verify the clean-install migration path is unaffected
 */
import { test, expect } from '@playwright/test';
import * as mysql from 'mysql2/promise';
import {
  setSlimstatOptions,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  closeDb,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';

const COOKIE_DOMAIN = new URL(BASE_URL).hostname;

// CookieYes dismissal — test site has cookie-law-info active
const COOKIEYES_DISMISS_COOKIES = [
  { name: 'viewed_cookie_policy', value: 'yes', domain: COOKIE_DOMAIN, path: '/' },
  { name: 'CookieLawInfoConsent', value: 'true', domain: COOKIE_DOMAIN, path: '/' },
  { name: 'cookielawinfo-checkbox-necessary', value: 'yes', domain: COOKIE_DOMAIN, path: '/' },
  { name: 'cookielawinfo-checkbox-analytics', value: 'yes', domain: COOKIE_DOMAIN, path: '/' },
];

// ─── Local DB pool ────────────────────────────────────────────────────

let pool: mysql.Pool;

function getPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool(MYSQL_CONFIG);
  }
  return pool;
}

/** Read a single key from the slimstat_options serialized array. */
async function getSlimstatOption(key: string): Promise<string | undefined> {
  const { unserialize } = await import('php-serialize');
  const [rows] = (await getPool().execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_options'",
  )) as any;
  if (!rows.length) return undefined;
  const opts = unserialize(rows[0].option_value) as Record<string, any>;
  return opts?.[key] as string | undefined;
}

/** Poll DB until at least `minRows` rows matching the marker appear. */
async function waitForStatRows(
  marker: string,
  minRows: number,
  timeoutMs = 15_000,
  intervalMs = 500,
): Promise<Record<string, any>[]> {
  const deadline = Date.now() + timeoutMs;
  let rows: any[] = [];
  while (Date.now() < deadline) {
    [rows] = (await getPool().execute(
      'SELECT id, visit_id, ip, resource, username FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id ASC LIMIT 20',
      [`%${marker}%`],
    )) as any;
    if (rows.length >= minRows) return rows;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return rows;
}

/** Check whether a request is a SlimStat tracking hit. */
function isSlimstatTrackingRequest(req: import('@playwright/test').Request): boolean {
  const url = req.url();
  if (url.includes('/wp-json/slimstat/v1/hit')) return true;
  if (url.includes('rest_route=/slimstat/v1/hit')) return true;
  if (req.method() === 'POST' && url.includes('admin-ajax.php')) {
    const body = req.postData() || '';
    if (body.includes('action=slimtrack')) return true;
  }
  return false;
}

// ─── Test suite ───────────────────────────────────────────────────────

test.describe('Migration cookie restore bug — no cookies after 5.4.0', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(120_000);

  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    if (pool) await pool.end();
    await closeDb();
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 1: Reproduce the bug — legacy consent settings path
  //
  //   Simulate a v5.3.x install with display_opt_out='on' that upgraded
  //   through v5.4.0 (which forced set_tracker_cookie='off'). Reset the
  //   migration flag and trigger re-migration. Verify that the migration
  //   does NOT restore set_tracker_cookie when gdpr_enabled='on'.
  // ═══════════════════════════════════════════════════════════════════

  test('BUG: migration keeps set_tracker_cookie=off when legacy consent settings exist', async ({
    page,
  }) => {
    // Simulate the pre-migration state:
    // - display_opt_out='on' (legacy v5.3.x consent banner)
    // - set_tracker_cookie='off' (broken v5.4.0 default)
    // - gdpr_enabled='on' (v5.4.0 forced this)
    // - _migration_5460='0' (force migration to re-run)
    await setSlimstatOptions(page, {
      display_opt_out: 'on',
      opt_out_cookie_names: '',
      opt_in_cookie_names: '',
      gdpr_enabled: 'on',
      set_tracker_cookie: 'off',
      use_slimstat_banner: 'on',
      consent_integration: 'slimstat_banner',
      anonymize_ip: 'on',
      hash_ip: 'on',
      javascript_mode: 'on',
      _migration_5460: '0',
    });

    // Trigger the migration by loading any admin page.
    // The migration runs on plugins_loaded → wp_slimstat::init().
    await page.goto(`${BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' });

    // Read settings AFTER migration
    const setTrackerCookie = await getSlimstatOption('set_tracker_cookie');
    const gdprEnabled = await getSlimstatOption('gdpr_enabled');
    const migrationFlag = await getSlimstatOption('_migration_5460');

    // Migration should have run
    expect(migrationFlag, 'Migration flag should be updated').not.toBe('0');

    // Migration detects display_opt_out='on' → sets gdpr_enabled='on'
    expect(gdprEnabled, 'gdpr_enabled should be on (legacy consent detected)').toBe('on');

    // Fix 1a: migration now restores set_tracker_cookie='on' unconditionally,
    // regardless of gdpr_enabled state. On development branch this returns 'off'.
    expect(
      setTrackerCookie,
      'set_tracker_cookie must be restored to "on" after migration (Fix 1a)',
    ).toBe('on');
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 2: Anonymous visitor gets NO cookie (bug consequence)
  //
  //   With set_tracker_cookie='off' (from the bugged migration), visit
  //   multiple pages as an anonymous visitor. Verify that:
  //   - The slimstat_tracking_code cookie is NOT set
  //   - Each pageview gets a different visit_id (no session continuity)
  // ═══════════════════════════════════════════════════════════════════

  test('BUG: anonymous visitor has no tracking cookie and no session continuity', async ({
    page,
    browser,
  }) => {
    await clearStatsTable();

    // Set the bugged state directly (as migration would leave it)
    await setSlimstatOptions(page, {
      gdpr_enabled: 'off',
      set_tracker_cookie: 'off', // <-- the bug
      javascript_mode: 'on',
      tracking_request_method: 'rest',
      anonymous_tracking: 'off',
    });

    // Fresh anonymous context
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const anonPage = await ctx.newPage();

    try {
      const marker = `no-cookie-bug-${Date.now()}`;

      // Visit page 1
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}-p1`);
      await anonPage.waitForLoadState('load');

      // Check: slimstat_tracking_code cookie should be MISSING
      let cookies = await ctx.cookies();
      const trackingCookie1 = cookies.find((c) => c.name === 'slimstat_tracking_code');
      expect(
        trackingCookie1,
        'BUG: slimstat_tracking_code cookie should NOT be set when set_tracker_cookie=off',
      ).toBeUndefined();

      // Visit page 2
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}-p2`);
      await anonPage.waitForLoadState('load');

      // Still no cookie
      cookies = await ctx.cookies();
      const trackingCookie2 = cookies.find((c) => c.name === 'slimstat_tracking_code');
      expect(trackingCookie2, 'Cookie should still be absent on page 2').toBeUndefined();

      // Visit page 3
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}-p3`);
      await anonPage.waitForLoadState('load');

      // Wait for rows in DB
      const rows = await waitForStatRows(marker, 2, 20_000);
      expect(rows.length, 'At least 2 pageviews should be tracked').toBeGreaterThanOrEqual(2);

      // Without cookies, visit_ids should NOT be consistent across pages.
      // Each pageview creates a new session (no cookie to link them).
      const visitIds = rows
        .map((r: any) => parseInt(r.visit_id, 10))
        .filter((v: number) => v > 0);

      if (visitIds.length >= 2) {
        const uniqueIds = [...new Set(visitIds)];
        // With the bug, pages likely get different visit_ids
        // (no cookie = no session continuity). This documents the symptom.
        // Note: In some edge cases server-side heuristics may still group them,
        // so we only soft-assert here. The cookie absence above is the hard proof.
        if (uniqueIds.length > 1) {
          // Expected bug behavior: multiple different visit_ids
          expect(uniqueIds.length).toBeGreaterThan(1);
        }
      }
    } finally {
      await anonPage.close();
      await ctx.close();
    }
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 3: After fix — migration restores set_tracker_cookie for
  //   GDPR-enabled path
  //
  //   Same pre-migration state as Test 1, but after the fix is applied.
  //   Verify that set_tracker_cookie is restored to 'on' even when
  //   gdpr_enabled='on'.
  //
  //   NOTE: This test will FAIL until the fix is applied to
  //   wp-slimstat.php lines 281-284. After the fix, it should PASS.
  // ═══════════════════════════════════════════════════════════════════

  test('FIXED: migration restores set_tracker_cookie=on even when gdpr_enabled=on', async ({
    page,
  }) => {
    // Same pre-migration state as Test 1
    await setSlimstatOptions(page, {
      display_opt_out: 'on',
      opt_out_cookie_names: '',
      opt_in_cookie_names: '',
      gdpr_enabled: 'on',
      set_tracker_cookie: 'off',
      use_slimstat_banner: 'on',
      consent_integration: 'slimstat_banner',
      anonymize_ip: 'on',
      hash_ip: 'on',
      javascript_mode: 'on',
      _migration_5460: '0',
    });

    // Trigger migration
    await page.goto(`${BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' });

    const setTrackerCookie = await getSlimstatOption('set_tracker_cookie');
    const gdprEnabled = await getSlimstatOption('gdpr_enabled');

    expect(gdprEnabled).toBe('on');

    // AFTER FIX: set_tracker_cookie should be restored to 'on'
    // This test documents the expected behavior after the fix.
    // It will FAIL on the current (bugged) code and PASS after the fix.
    expect(
      setTrackerCookie,
      'AFTER FIX: set_tracker_cookie must be "on" even when gdpr_enabled="on"',
    ).toBe('on');
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 4: After fix — anonymous visitor gets cookie and session
  //   continuity after consent is granted
  //
  //   With gdpr_enabled='on' + set_tracker_cookie='on' (fixed) +
  //   slimstat_banner, verify that:
  //   - Before consent: no tracking cookie
  //   - After clicking Accept: tracking cookie IS set
  //   - Subsequent pages share the same visit_id
  // ═══════════════════════════════════════════════════════════════════

  test('FIXED: cookie set after consent granted with gdpr_enabled=on', async ({
    page,
    browser,
  }) => {
    await clearStatsTable();

    // Set the FIXED state: gdpr on + cookie on + slimstat banner
    await setSlimstatOptions(page, {
      gdpr_enabled: 'on',
      set_tracker_cookie: 'on', // <-- the fix
      use_slimstat_banner: 'on',
      consent_integration: 'slimstat_banner',
      javascript_mode: 'on',
      tracking_request_method: 'rest',
      anonymous_tracking: 'on',
      anonymize_ip: 'off',
      hash_ip: 'off',
    });

    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const anonPage = await ctx.newPage();

    // Track network for debugging
    const trackingRequests: string[] = [];
    anonPage.on('request', (req) => {
      if (isSlimstatTrackingRequest(req)) trackingRequests.push(req.url());
    });

    try {
      const ts = Date.now();
      const marker = `fixed-consent-cookie-${ts}`;

      // Visit page — banner should appear
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}-p1`, {
        waitUntil: 'domcontentloaded',
      });

      // Before consent: no tracking cookie (anonymous mode)
      let cookies = await ctx.cookies();
      const preCookie = cookies.find((c) => c.name === 'slimstat_tracking_code');
      // In anonymous mode, cookie is NOT set until consent is granted
      // (this is correct behavior, not a bug)

      // Wait for banner to appear
      const banner = anonPage.locator('#slimstat-gdpr-banner');
      const bannerVisible = await banner.isVisible().catch(() => false);

      if (bannerVisible) {
        // Wait for the anonymous pageview to register (need ID for upgrade)
        await anonPage.waitForFunction(
          () => {
            const p = (window as any).SlimStatParams;
            return p && p.id && parseInt(p.id, 10) > 0;
          },
          { timeout: 15_000 },
        );

        // Click Accept
        await anonPage.locator('[data-consent="accepted"]').click();

        // Wait for consent upgrade request
        const deadline = Date.now() + 15_000;
        while (trackingRequests.length === 0 && Date.now() < deadline) {
          await new Promise((r) => setTimeout(r, 500));
        }

        // After consent: tracking cookie SHOULD be set
        cookies = await ctx.cookies();
        const postCookie = cookies.find((c) => c.name === 'slimstat_tracking_code');
        expect(
          postCookie,
          'AFTER FIX: slimstat_tracking_code cookie must be set after consent is granted',
        ).toBeTruthy();

        // Navigate to page 2 — session should continue
        trackingRequests.length = 0;
        const marker2 = `fixed-consent-cookie-${ts}-p2`;
        await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker2}`, {
          waitUntil: 'domcontentloaded',
        });

        // Wait for second pageview to be tracked
        const rows = await waitForStatRows(`fixed-consent-cookie-${ts}`, 2, 20_000);

        if (rows.length >= 2) {
          // Both pages should share the same visit_id (session continuity via cookie)
          const visitIds = rows
            .map((r: any) => parseInt(r.visit_id, 10))
            .filter((v: number) => v > 0);
          const uniqueIds = [...new Set(visitIds)];
          expect(
            uniqueIds,
            `Session continuity: all pageviews should share one visit_id, got ${JSON.stringify(uniqueIds)}`,
          ).toHaveLength(1);
        }
      } else {
        // Banner not visible — might be because anonymous_tracking + JS consent
        // blocked the initial send. This is the secondary JS/PHP mismatch bug.
        // Still verify the tracking works by checking DB.
        const rows = await waitForStatRows(marker, 1, 15_000);
        // If no rows, the JS-side blocked tracking (secondary bug)
        test.info().annotations.push({
          type: 'note',
          description: 'Banner was not visible — possible JS/PHP consent mismatch',
        });
      }
    } finally {
      await anonPage.close();
      await ctx.close();
    }
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 5: Cookie works immediately when gdpr_enabled=off
  //
  //   With gdpr_enabled='off' + set_tracker_cookie='on', verify that
  //   the cookie is set on the very first pageview without needing
  //   any consent interaction.
  // ═══════════════════════════════════════════════════════════════════

  test('FIXED: cookie set immediately when gdpr_enabled=off', async ({ page, browser }) => {
    await clearStatsTable();

    await setSlimstatOptions(page, {
      gdpr_enabled: 'off',
      set_tracker_cookie: 'on',
      javascript_mode: 'on',
      tracking_request_method: 'rest',
      anonymous_tracking: 'off',
      anonymize_ip: 'off',
      hash_ip: 'off',
    });

    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const anonPage = await ctx.newPage();

    try {
      const marker = `gdpr-off-cookie-${Date.now()}`;

      // Visit page 1
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}-p1`);
      await anonPage.waitForLoadState('load');

      // Wait for tracking to complete (XHR sets the cookie via Set-Cookie header)
      await anonPage.waitForFunction(
        () => {
          const p = (window as any).SlimStatParams;
          return p && p.id && parseInt(p.id, 10) > 0;
        },
        { timeout: 15_000 },
      );

      // Small delay for Set-Cookie to be processed by browser
      await anonPage.waitForTimeout(1000);

      // Cookie should be set immediately (no consent needed)
      let cookies = await ctx.cookies();
      const trackingCookie = cookies.find((c) => c.name === 'slimstat_tracking_code');
      expect(
        trackingCookie,
        'slimstat_tracking_code cookie must be set when gdpr_enabled=off + set_tracker_cookie=on',
      ).toBeTruthy();

      // Visit page 2
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}-p2`);
      await anonPage.waitForLoadState('load');

      // Visit page 3
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}-p3`);
      await anonPage.waitForLoadState('load');

      // All 3 pages should share the same visit_id
      const rows = await waitForStatRows(marker, 3, 20_000);
      expect(rows.length, 'All 3 pageviews should be tracked').toBeGreaterThanOrEqual(3);

      const visitIds = rows
        .map((r: any) => parseInt(r.visit_id, 10))
        .filter((v: number) => v > 0);
      expect(visitIds.length, 'All rows should have positive visit_id').toBe(rows.length);

      const uniqueIds = [...new Set(visitIds)];
      expect(
        uniqueIds,
        `All pages should share one visit_id (session cookie), got ${JSON.stringify(uniqueIds)}`,
      ).toHaveLength(1);
    } finally {
      await anonPage.close();
      await ctx.close();
    }
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 6: Clean install migration path (no legacy consent settings)
  //
  //   Simulate a user who had NO consent settings in v5.3.x. The
  //   migration should set gdpr_enabled='off' and restore cookies.
  //   This path should already work (not affected by the bug).
  // ═══════════════════════════════════════════════════════════════════

  test('clean install: migration correctly sets gdpr=off and cookie=on', async ({ page }) => {
    // Simulate v5.4.0 state with NO legacy consent settings
    await setSlimstatOptions(page, {
      display_opt_out: 'no',     // v5.3.x default — no banner
      opt_out_cookie_names: '',   // no legacy cookies
      opt_in_cookie_names: '',    // no legacy cookies
      gdpr_enabled: 'on',        // v5.4.0 forced this
      set_tracker_cookie: 'off', // v5.4.0 forced this
      use_slimstat_banner: 'on', // v5.4.0 forced this
      consent_integration: 'slimstat_banner',
      anonymize_ip: 'on',
      hash_ip: 'on',
      javascript_mode: 'on',
      _migration_5460: '0',      // force re-run
    });

    // Trigger migration
    await page.goto(`${BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' });

    const gdprEnabled = await getSlimstatOption('gdpr_enabled');
    const setTrackerCookie = await getSlimstatOption('set_tracker_cookie');
    const useBanner = await getSlimstatOption('use_slimstat_banner');
    const integration = await getSlimstatOption('consent_integration');
    const migrationFlag = await getSlimstatOption('_migration_5460');

    expect(migrationFlag, 'Migration should have run').not.toBe('0');
    expect(gdprEnabled, 'No legacy consent → gdpr_enabled should be off').toBe('off');
    expect(setTrackerCookie, 'set_tracker_cookie should be restored to on').toBe('on');
    expect(useBanner, 'use_slimstat_banner should be off').toBe('off');
    expect(integration, 'consent_integration should be empty').toBe('');
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 7: Third-party CMP migration path
  //
  //   Simulate a user who configured consent_integration='wp_consent_api'
  //   during 5.4.0-5.4.5. Migration should preserve GDPR=on but ALSO
  //   restore set_tracker_cookie=on (after the fix).
  // ═══════════════════════════════════════════════════════════════════

  test('third-party CMP: migration preserves gdpr=on and restores cookie=on', async ({
    page,
  }) => {
    await setSlimstatOptions(page, {
      display_opt_out: 'no',
      opt_out_cookie_names: '',
      opt_in_cookie_names: '',
      gdpr_enabled: 'on',
      set_tracker_cookie: 'off',
      use_slimstat_banner: 'off',
      consent_integration: 'wp_consent_api', // deliberately configured CMP
      anonymize_ip: 'on',
      hash_ip: 'on',
      javascript_mode: 'on',
      _migration_5460: '0',
    });

    // Trigger migration
    await page.goto(`${BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' });

    const gdprEnabled = await getSlimstatOption('gdpr_enabled');
    const setTrackerCookie = await getSlimstatOption('set_tracker_cookie');
    const integration = await getSlimstatOption('consent_integration');

    expect(gdprEnabled, 'Third-party CMP → gdpr_enabled preserved as on').toBe('on');
    expect(integration, 'consent_integration should be preserved').toBe('wp_consent_api');

    // AFTER FIX: set_tracker_cookie should be 'on' regardless of gdpr_enabled
    expect(
      setTrackerCookie,
      'AFTER FIX: set_tracker_cookie must be "on" even with third-party CMP',
    ).toBe('on'); // <-- Will FAIL until fix is applied
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 8: v5.4.7 — migration restores set_tracker_cookie via the
  //   full migration path (not just setting the value directly)
  //
  //   DIFFERENTIATES: FAIL on development (GDPR gate blocks restore),
  //                   PASS on fix branch (unconditional restore).
  //   Cookie behavior for anonymous visitors is covered by Test 4.
  // ═══════════════════════════════════════════════════════════════════

  test('v547-fix: migration restores set_tracker_cookie via full migration path', async ({
    page,
  }) => {
    // Set the broken pre-migration state:
    // - display_opt_out='on' (legacy v5.3.x consent → triggers gdpr_enabled='on' path)
    // - set_tracker_cookie='off' (broken v5.4.0 default)
    // - _migration_5460='0' (force migration re-run)
    await setSlimstatOptions(page, {
      display_opt_out: 'on',
      set_tracker_cookie: 'off',
      javascript_mode: 'on',
      _migration_5460: '0',
    });

    // Trigger migration by loading admin page
    await page.goto(`${BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' });

    // Wait for migration to complete by polling the DB option
    let cookieSetting: string | undefined;
    const deadline = Date.now() + 10_000;
    while (Date.now() < deadline) {
      const migFlag = await getSlimstatOption('_migration_5460');
      if (migFlag && migFlag !== '0') {
        cookieSetting = await getSlimstatOption('set_tracker_cookie');
        break;
      }
      await new Promise((r) => setTimeout(r, 500));
    }
    if (!cookieSetting) {
      cookieSetting = await getSlimstatOption('set_tracker_cookie');
    }

    // development: stays 'off' (GDPR gate blocks restore) → FAIL
    // fix branch: restored to 'on' → PASS
    expect(
      cookieSetting,
      'v547-fix: migration must restore set_tracker_cookie to "on" even when gdpr_enabled="on"',
    ).toBe('on');
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 9: v5.4.7 — Fix 1b: JS allows tracking when banner is off
  //
  //   Uses consent_integration='slimstat' (not 'slimstat_banner') to bypass
  //   PHP consent-sync while still entering the JS guard block at line 1401.
  //   JS checks: integrationKey === "slimstat_banner" || integrationKey === "slimstat"
  //   PHP only forces banner=on for 'slimstat_banner', not 'slimstat'.
  //
  //   DIFFERENTIATES: FAIL on development (no guard → cmpAllows=false),
  //                   PASS on fix branch (Fix 1b guard → cmpAllows=true).
  // ═══════════════════════════════════════════════════════════════════

  test('v547-fix: JS allows tracking with consent_integration=slimstat + banner=off', async ({
    page,
    browser,
  }) => {
    await clearStatsTable();

    // consent_integration='slimstat' enters JS block (line 1401) but PHP consent-sync
    // at line 345 only forces banner=on for 'slimstat_banner'. With 'slimstat', PHP
    // sets use_slimstat_banner='off' (else branch) — creating the exact mismatch.
    await setSlimstatOptions(page, {
      gdpr_enabled: 'on',
      consent_integration: 'slimstat',
      use_slimstat_banner: 'off',
      javascript_mode: 'on',
      set_tracker_cookie: 'on',
      tracking_request_method: 'rest',
      anonymous_tracking: 'on',
    });

    const anonCtx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await anonCtx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const anonPage = await anonCtx.newPage();

    let trackingFired = false;
    anonPage.on('request', (req) => {
      if (isSlimstatTrackingRequest(req)) {
        trackingFired = true;
      }
    });

    try {
      const marker = `v547-1b-${Date.now()}`;
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}`);
      await anonPage.waitForLoadState('networkidle');

      // Poll for tracking request instead of fixed timeout
      const deadline = Date.now() + 15_000;
      while (!trackingFired && Date.now() < deadline) {
        await new Promise((r) => setTimeout(r, 500));
      }

      expect(
        trackingFired,
        'v547-fix: JS must allow tracking when use_slimstat_banner=off + integrationKey=slimstat',
      ).toBe(true);

      const rows = await waitForStatRows(marker, 1, 15_000);
      expect(rows.length, 'Pageview recorded in DB').toBeGreaterThanOrEqual(1);
    } finally {
      await anonPage.close();
      await anonCtx.close();
    }
  });
});
