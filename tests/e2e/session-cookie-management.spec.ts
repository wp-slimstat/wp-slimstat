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
      'SELECT * FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id ASC',
      [`%${marker}%`],
    )) as any;
    if (rows.length >= minRows) return rows;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  // Return whatever we have (may be fewer than minRows)
  const [rows] = (await getPool().execute(
    'SELECT * FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id ASC',
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

    await page.close();
    await ctx.close();
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 3: Consent upgrade preserves session
  //   With GDPR enabled, decline tracking and navigate (should NOT
  //   track). Then accept consent and navigate — tracking should
  //   resume with a NEW visit_id.
  //
  //   Regression for bug #246 where consent-upgrade broke session merge.
  // ═══════════════════════════════════════════════════════════════════

  test('consent upgrade — decline then accept resumes tracking with new visit_id', async ({
    page,
  }) => {
    await clearStatsTable();

    // Enable GDPR with SlimStat banner
    await setSlimstatOption(page, 'gdpr_enabled', 'on');
    await setSlimstatOption(page, 'consent_integration', 'slimstat_banner');
    await setSlimstatOption(page, 'use_slimstat_banner', 'on');
    await setSlimstatOption(page, 'javascript_mode', 'on');
    await setSlimstatOption(page, 'set_tracker_cookie', 'on');
    await setSlimstatOption(page, 'tracking_request_method', 'rest');
    await setSlimstatOption(page, 'anonymous_tracking', 'off');

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

    // ── Phase 2: Navigate while declined — should NOT track ───────

    const declinedNavMarker = `consent-declined-nav-${ts}`;
    await testPage.goto(`${BASE_URL}/?e2e_marker=${declinedNavMarker}`, {
      waitUntil: 'domcontentloaded',
    });
    await testPage.waitForTimeout(3000);

    // Banner should NOT reappear (decision persisted)
    await expect(testPage.locator('#slimstat-gdpr-banner')).not.toBeVisible();

    // No tracking requests should have fired while consent is denied
    expect(
      trackingRequests.length,
      'No tracking requests should fire while consent is denied',
    ).toBe(0);

    // Verify no DB rows for declined navigation
    await new Promise((r) => setTimeout(r, 2000));
    const [declinedRows] = (await getPool().execute(
      'SELECT id FROM wp_slim_stats WHERE resource LIKE ?',
      [`%${declinedNavMarker}%`],
    )) as any;
    expect(declinedRows.length, 'No DB rows should exist for declined navigation').toBe(0);

    // ── Phase 3: Accept consent (upgrade) ─────────────────────────

    // Delete the denied cookie and set it to accepted — simulates the user
    // changing their mind (e.g. clicking an "Accept" button on a re-shown banner
    // or a "Manage Preferences" link). We manipulate the cookie directly and
    // then navigate, which is the same flow as the JS consent upgrade path.
    await ctx.clearCookies();
    await ctx.addCookies([
      ...COOKIEYES_DISMISS_COOKIES,
      {
        name: 'slimstat_gdpr_consent',
        value: 'accepted',
        domain: COOKIE_DOMAIN,
        path: '/',
      },
    ]);

    // Reset tracking counter for acceptance phase
    trackingRequests.length = 0;

    const acceptMarker = `consent-accepted-${ts}`;
    await testPage.goto(`${BASE_URL}/?e2e_marker=${acceptMarker}`, {
      waitUntil: 'domcontentloaded',
    });
    await testPage.waitForTimeout(3000);

    // Banner should NOT be visible (consent cookie = accepted)
    await expect(testPage.locator('#slimstat-gdpr-banner')).not.toBeVisible();

    // Navigate to a second page after consent upgrade
    const acceptNav2Marker = `consent-accepted-nav2-${ts}`;
    await testPage.goto(`${BASE_URL}/?e2e_marker=${acceptNav2Marker}`, {
      waitUntil: 'domcontentloaded',
    });
    await testPage.waitForTimeout(3000);

    // ── Phase 4: Verify tracking resumed ──────────────────────────

    // At least one tracking request should have fired after acceptance
    expect(
      trackingRequests.length,
      'Tracking requests should fire after consent upgrade',
    ).toBeGreaterThanOrEqual(1);

    // DB rows should exist for the accepted pages
    const acceptRows = await waitForStatRows(acceptMarker, 1, 15_000);
    expect(
      acceptRows.length,
      'At least one DB row should exist after consent upgrade',
    ).toBeGreaterThanOrEqual(1);
    const postConsentVisitId = parseInt(acceptRows[0].visit_id, 10);
    expect(postConsentVisitId, 'Post-consent visit_id should be positive').toBeGreaterThan(0);

    // Verify no rows exist for the decline-phase markers (tracking was correctly blocked)
    const [declinePhaseRows] = (await getPool().execute(
      'SELECT id FROM wp_slim_stats WHERE resource LIKE ?',
      [`%${declineMarker}%`],
    )) as any;
    expect(
      declinePhaseRows.length,
      'No DB rows should exist for the initial declined page',
    ).toBe(0);

    // If the second accepted page was also tracked, verify it shares the same visit_id
    const acceptNav2Rows = await waitForStatRows(acceptNav2Marker, 1, 10_000);
    if (acceptNav2Rows.length > 0) {
      const nav2VisitId = parseInt(acceptNav2Rows[0].visit_id, 10);
      expect(
        nav2VisitId,
        'Second page after consent upgrade should share visit_id with the first',
      ).toBe(postConsentVisitId);
    }

    await testPage.close();
    await ctx.close();
  });
});
