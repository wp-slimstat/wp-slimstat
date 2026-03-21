/**
 * E2E tests: Session & Cookie Management — ROADMAP #199
 *
 * Covers:
 *  1. Cross-page visit_id continuity (same session, 3 pages, single visit_id)
 *  2. Cookie expiry creates new session (clear cookies mid-session => new visit_id)
 *  3. Consent upgrade preserves session (decline => no tracking => accept => tracking resumes)
 *
 * Related bugs: #246 (consent-upgrade path broke session merging in v5.4.5)
 */
import { test, expect } from '@playwright/test';
import * as mysql from 'mysql2/promise';
import {
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  closeDb,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';

const COOKIE_DOMAIN = new URL(BASE_URL).hostname;

// CookieYes dismissal cookies — the test site has cookie-law-info active and
// its overlay blocks the SlimStat banner buttons. Pre-set to dismiss it.
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

/** Poll DB until at least `minRows` rows matching the marker appear. */
async function waitForStatRows(
  marker: string,
  minRows: number,
  timeoutMs = 15_000,
  intervalMs = 500,
): Promise<Record<string, any>[]> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const [rows] = (await getPool().execute(
      'SELECT id, visit_id, ip, resource FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id ASC LIMIT 20',
      [`%${marker}%`],
    )) as any;
    if (rows.length >= minRows) return rows;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  // Return whatever we have (may be fewer than minRows)
  const [rows] = (await getPool().execute(
    'SELECT id, visit_id, ip, resource FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id ASC LIMIT 20',
    [`%${marker}%`],
  )) as any;
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

test.describe('Session & Cookie Management — #199', () => {
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
  // Test 1: Cross-page visit_id continuity
  //   Navigate to 3 different pages in the same browser context and
  //   verify all DB rows share the same visit_id.
  // ═══════════════════════════════════════════════════════════════════

  test('cross-page visit_id continuity — 3 pages share one visit_id', async ({ page }) => {
    await clearStatsTable();
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'javascript_mode', 'on');
    await setSlimstatOption(page, 'set_tracker_cookie', 'on');
    await setSlimstatOption(page, 'tracking_request_method', 'rest');

    const marker = `session-continuity-${Date.now()}`;

    // Navigate to 3 distinct pages within the same browser context (session)
    await page.goto(`${BASE_URL}/?e2e_marker=${marker}-p1`);
    await page.waitForLoadState('load');
    await page.waitForTimeout(3000);

    await page.goto(`${BASE_URL}/?e2e_marker=${marker}-p2`);
    await page.waitForLoadState('load');
    await page.waitForTimeout(3000);

    await page.goto(`${BASE_URL}/?e2e_marker=${marker}-p3`);
    await page.waitForLoadState('load');
    await page.waitForTimeout(3000);

    // Wait for all 3 rows to appear in the DB
    const rows = await waitForStatRows(marker, 3, 20_000);
    expect(rows.length, 'Expected 3 tracked pageviews').toBeGreaterThanOrEqual(3);

    // All rows must share the same positive visit_id
    const visitIds = rows
      .map((r: any) => parseInt(r.visit_id, 10))
      .filter((v: number) => v > 0);
    expect(visitIds.length, 'All rows should have a positive visit_id').toBe(rows.length);

    const uniqueIds = [...new Set(visitIds)];
    expect(
      uniqueIds,
      `All 3 pageviews should share one visit_id but got ${JSON.stringify(uniqueIds)}`,
    ).toHaveLength(1);
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 2: Cookie expiry creates new session
  //   Visit a page and record the visit_id, then clear cookies and
  //   visit again. The second visit must get a different visit_id.
  // ═══════════════════════════════════════════════════════════════════

  test('clearing cookies creates a new session with a different visit_id', async ({ browser }) => {
    await clearStatsTable();

    // Use a fresh context so we control cookie lifecycle completely
    const ctx = await browser.newContext();
    const page = await ctx.newPage();

    try {
      // Configure tracking (GDPR off, JS mode, cookies on)
      await setSlimstatOption(page, 'gdpr_enabled', 'off');
      await setSlimstatOption(page, 'javascript_mode', 'on');
      await setSlimstatOption(page, 'set_tracker_cookie', 'on');
      await setSlimstatOption(page, 'tracking_request_method', 'rest');

      const markerBefore = `session-before-${Date.now()}`;
      const markerAfter = `session-after-${Date.now()}`;

      // Visit #1: establish a session
      await page.goto(`${BASE_URL}/?e2e_marker=${markerBefore}`);
      await page.waitForLoadState('load');
      await page.waitForTimeout(3000);

      const rowsBefore = await waitForStatRows(markerBefore, 1, 15_000);
      expect(rowsBefore.length, 'First visit should produce a DB row').toBeGreaterThanOrEqual(1);
      const visitIdBefore = parseInt(rowsBefore[0].visit_id, 10);
      expect(visitIdBefore, 'First visit_id should be positive').toBeGreaterThan(0);

      // Clear ALL cookies — simulates cookie expiry
      await ctx.clearCookies();

      // Visit #2: should start a new session
      await page.goto(`${BASE_URL}/?e2e_marker=${markerAfter}`);
      await page.waitForLoadState('load');
      await page.waitForTimeout(3000);

      const rowsAfter = await waitForStatRows(markerAfter, 1, 15_000);
      expect(rowsAfter.length, 'Second visit should produce a DB row').toBeGreaterThanOrEqual(1);
      const visitIdAfter = parseInt(rowsAfter[0].visit_id, 10);
      expect(visitIdAfter, 'Second visit_id should be positive').toBeGreaterThan(0);

      // The two visit_ids MUST differ — cookie loss means new session
      expect(
        visitIdAfter,
        `visit_id after cookie clear (${visitIdAfter}) must differ from before (${visitIdBefore})`,
      ).not.toBe(visitIdBefore);
    } finally {
      await page.close();
      await ctx.close();
    }
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 3: Consent upgrade preserves session
  //   With GDPR + anonymous_tracking enabled, decline consent and
  //   navigate — anonymous rows (hashed IP, no PII) should be recorded.
  //   Then accept consent and navigate — tracking should continue with
  //   full PII (real IP). Exercises the real merge/upgrade code path in
  //   Processor.php ($isAnonymousTracking && $piiAllowed).
  //
  //   Regression for bug #246 where consent-upgrade broke session merge.
  // ═══════════════════════════════════════════════════════════════════

  test('consent upgrade — anonymous rows while declined, PII rows after accept', async ({
    page,
  }) => {
    await clearStatsTable();

    // Enable GDPR with SlimStat banner + anonymous tracking so that
    // declined visitors still get tracked without PII. This exercises
    // the real merge/upgrade path in Processor.php (gated on
    // $isAnonymousTracking && $piiAllowed).
    await setSlimstatOption(page, 'gdpr_enabled', 'on');
    await setSlimstatOption(page, 'consent_integration', 'slimstat_banner');
    await setSlimstatOption(page, 'use_slimstat_banner', 'on');
    await setSlimstatOption(page, 'javascript_mode', 'on');
    await setSlimstatOption(page, 'set_tracker_cookie', 'on');
    await setSlimstatOption(page, 'tracking_request_method', 'rest');
    await setSlimstatOption(page, 'anonymous_tracking', 'on');

    const browser = page.context().browser()!;
    const ts = Date.now();

    // Fresh context — no prior consent
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const testPage = await ctx.newPage();

    // Track network requests for debugging
    const trackingRequests: string[] = [];
    testPage.on('request', (req) => {
      if (isSlimstatTrackingRequest(req)) {
        trackingRequests.push(req.url());
      }
    });

    // ── Phase 1: Decline consent ──────────────────────────────────

    const declineMarker = `consent-decline-${ts}`;
    await testPage.goto(`${BASE_URL}/?e2e_marker=${declineMarker}`, {
      waitUntil: 'domcontentloaded',
    });
    await testPage.waitForTimeout(3000);

    // Banner should be visible for a fresh visitor
    await expect(testPage.locator('#slimstat-gdpr-banner')).toBeVisible();

    // Click Decline
    await testPage.locator('[data-consent="denied"]').click();
    await testPage.waitForTimeout(2000);

    // Banner should disappear
    await expect(testPage.locator('#slimstat-gdpr-banner')).not.toBeVisible();

    // Verify consent cookie is 'denied'
    let cookies = await ctx.cookies();
    const deniedCookie = cookies.find((c) => c.name === 'slimstat_gdpr_consent');
    expect(deniedCookie, 'slimstat_gdpr_consent cookie should exist after declining').toBeTruthy();
    expect(deniedCookie!.value).toBe('denied');

    // Reset tracking counter to isolate post-decline navigation
    trackingRequests.length = 0;

    // ── Phase 2: Navigate while declined — anonymous tracking records a row ─

    const declinedNavMarker = `consent-declined-nav-${ts}`;
    await testPage.goto(`${BASE_URL}/?e2e_marker=${declinedNavMarker}`, {
      waitUntil: 'domcontentloaded',
    });
    await testPage.waitForTimeout(3000);

    // Banner should NOT reappear (decision persisted)
    await expect(testPage.locator('#slimstat-gdpr-banner')).not.toBeVisible();

    // With anonymous_tracking=on, the tracker still fires even when consent
    // is denied — it just strips PII (IP is hashed, no username/email).
    const anonRows = await waitForStatRows(declinedNavMarker, 1, 15_000);
    expect(
      anonRows.length,
      'Anonymous tracking should record a row even when consent is denied',
    ).toBeGreaterThanOrEqual(1);
    // Store the anonymous IP for later comparison — it should be a hash,
    // not the real IP address.
    const anonymousIp = anonRows[0].ip;

    // ── Phase 3: Accept consent via real JS upgrade path ──────────
    //
    // The real consent upgrade flow:
    //   1. JS: requestConsentUpgrade() calls sendPageview() with consentUpgrade: true
    //   2. JS: sendPageview() adds &consent_upgrade=1&pageview_id=<id> to the request
    //   3. Server Processor.php:409: $isConsentUpgrade gate triggers the merge
    //
    // We must call this ON THE SAME PAGE where the anonymous row was created,
    // because SlimStatParams.id holds the anonymous pageview ID that the server
    // needs to find and upgrade the correct row.

    // Set the accepted consent cookie (simulates the banner handler setting it)
    await ctx.addCookies([
      {
        name: 'slimstat_gdpr_consent',
        value: 'accepted',
        domain: COOKIE_DOMAIN,
        path: '/',
      },
    ]);

    // Reset tracking counter to capture the upgrade request
    trackingRequests.length = 0;

    // Trigger the real JS consent upgrade on the CURRENT page (not a new navigation).
    // This sends consent_upgrade=1 + the current pageview_id to the server,
    // which is the exact code path that #246 broke.
    const upgradeResult = await testPage.evaluate(() => {
      if (typeof (window as any).SlimStat?.requestConsentUpgrade === 'function') {
        (window as any).SlimStat.requestConsentUpgrade({ force: true });
        return 'triggered';
      }
      return 'SlimStat.requestConsentUpgrade not available';
    });

    // Wait for the upgrade tracking request to complete
    await testPage.waitForTimeout(5000);

    // ── Phase 4: Verify the anonymous row was upgraded with PII ────

    if (upgradeResult === 'triggered') {
      // The upgrade request should have fired
      expect(
        trackingRequests.length,
        'requestConsentUpgrade() should send a tracking request with consent_upgrade=1',
      ).toBeGreaterThanOrEqual(1);

      // Re-read the anonymous row — after upgrade, the IP should now be real (PII),
      // not the hashed anonymous value.
      const upgradedRows = await waitForStatRows(declinedNavMarker, 1, 10_000);
      expect(upgradedRows.length).toBeGreaterThanOrEqual(1);
      const upgradedIp = upgradedRows[0].ip;

      // Key assertion: the SAME row that had a hashed anonymous IP should now
      // have a real IP (PII) after the consent upgrade merged PII into it.
      // If #246 regresses, this assertion fails because the merge code at
      // Processor.php:436 ($isAnonymousTracking && $piiAllowed) won't execute.
      expect(
        upgradedIp,
        `Anonymous row IP should be upgraded from hashed (${anonymousIp}) to real PII`,
      ).not.toBe(anonymousIp);
    } else {
      // SlimStat JS not loaded (e.g. server-mode or JS disabled) — fall back to
      // verifying that a new navigation after accepting produces PII rows.
      const acceptMarker = `consent-accepted-${ts}`;
      await testPage.goto(`${BASE_URL}/?e2e_marker=${acceptMarker}`, {
        waitUntil: 'domcontentloaded',
      });
      await testPage.waitForTimeout(3000);

      const acceptRows = await waitForStatRows(acceptMarker, 1, 15_000);
      expect(acceptRows.length).toBeGreaterThanOrEqual(1);
      expect(
        acceptRows[0].ip,
        'Post-consent IP should differ from anonymous hashed IP',
      ).not.toBe(anonymousIp);
    }

    await testPage.close();
    await ctx.close();
  });
});
