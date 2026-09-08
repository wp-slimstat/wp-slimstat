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
  installMuPluginByName,
  uninstallMuPluginByName,
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

  test('cross-page visit_id continuity — 3 pages share one visit_id', async ({ page, browser }) => {
    await clearStatsTable();
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'javascript_mode', 'on');
    await setSlimstatOption(page, 'set_tracker_cookie', 'on');
    await setSlimstatOption(page, 'tracking_request_method', 'rest');
    await setSlimstatOption(page, 'ignore_wp_users', 'no');

    // Use a fresh ANONYMOUS browser context — not the admin page fixture.
    // Real visitors are not logged into WordPress. The admin storageState
    // carries auth cookies that can trigger isUserExcluded() and silently
    // skip tracking.
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const anonPage = await ctx.newPage();

    try {
      const marker = `session-continuity-${Date.now()}`;

      // Navigate to 3 distinct pages within the same anonymous session
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}-p1`);
      await anonPage.waitForLoadState('load');

      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}-p2`);
      await anonPage.waitForLoadState('load');

      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}-p3`);
      await anonPage.waitForLoadState('load');

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
    } finally {
      await anonPage.close();
      await ctx.close();
    }
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 2: Cookie expiry creates new session
  //   Visit a page and record the visit_id, then clear cookies and
  //   visit again. The second visit must get a different visit_id.
  // ═══════════════════════════════════════════════════════════════════

  test('session survives rapid navigation while the first tracking response is delayed', async ({ page, browser }, testInfo) => {
    await clearStatsTable();
    for (const [name, value] of Object.entries({ gdpr_enabled: 'off', javascript_mode: 'on', set_tracker_cookie: 'on', tracking_request_method: 'rest', ignore_wp_users: 'no' })) {
      await setSlimstatOption(page, name, value);
    }
    await getPool().execute("DELETE FROM wp_options WHERE option_name = 'slimstat_e2e_response_delay'");
    installMuPluginByName('delayed-tracker-response-mu-plugin.php');
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      const visitor = await context.newPage();
      const marker = `session-delayed-${Date.now()}`;
      const firstRequest = visitor.waitForRequest(isSlimstatTrackingRequest);
      await visitor.goto(`${BASE_URL}/?e2e_marker=${marker}-p1`);
      await firstRequest; // Confirm in-flight; deliberately do not await its response.
      const beforeSecond = await context.cookies();
      await visitor.goto(`${BASE_URL}/?e2e_marker=${marker}-p2`);
      const beforeThird = await context.cookies();
      await visitor.goto(`${BASE_URL}/?e2e_marker=${marker}-p3`);
      let delayProof: any;
      await expect.poll(async () => {
        const [proofRows] = await getPool().execute("SELECT option_value FROM wp_options WHERE option_name = 'slimstat_e2e_response_delay'") as any;
        delayProof = proofRows.length ? JSON.parse(proofRows[0].option_value) : {};
        return delayProof.finished ? delayProof.finished - delayProof.started : 0;
      }).toBeGreaterThanOrEqual(0.9);
      const rows = await waitForStatRows(marker, 3, 20_000);
      await testInfo.attach('delayed-session-evidence', { body: JSON.stringify({ beforeSecond, beforeThird, delayProof, cookiesAfter: await context.cookies(), rows }, null, 2), contentType: 'application/json' });
      expect(rows).toHaveLength(3);
      expect(new Set(rows.map(row => Number(row.visit_id))).size).toBe(1);
      expect(Number(rows[0].visit_id)).toBeGreaterThan(0);
    } finally {
      await context.close();
      uninstallMuPluginByName('delayed-tracker-response-mu-plugin.php');
      await getPool().execute("DELETE FROM wp_options WHERE option_name = 'slimstat_e2e_response_delay'");
    }
  });

  test('pending session identity is cleared after consent revocation and in cookieless modes', async ({ page, browser }) => {
    const cases = [
      { gdpr_enabled: 'on', anonymous_tracking: 'off', use_slimstat_banner: 'on', consent_integration: 'slimstat_banner', set_tracker_cookie: 'on', denied: true },
      { gdpr_enabled: 'on', anonymous_tracking: 'on', use_slimstat_banner: 'on', consent_integration: 'slimstat_banner', set_tracker_cookie: 'on' },
      { gdpr_enabled: 'off', anonymous_tracking: 'off', use_slimstat_banner: 'off', consent_integration: '', set_tracker_cookie: 'off' },
    ];

    for (const { denied, ...settings } of cases) {
      for (const [name, value] of Object.entries({ ...settings, javascript_mode: 'on', ignore_wp_users: 'no' })) {
        await setSlimstatOption(page, name, value);
      }
      const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
      try {
        await context.addInitScript(() => sessionStorage.setItem('slimstat_pending_session', 'a'.repeat(32)));
        if (denied) {
          await context.addCookies([{ name: 'slimstat_gdpr_consent', value: 'denied', url: BASE_URL }]);
        }
        const visitor = await context.newPage();
        await visitor.goto(`${BASE_URL}/?e2e_marker=no-pending-session-${Date.now()}`, { waitUntil: 'networkidle' });
        expect(await visitor.evaluate(() => sessionStorage.getItem('slimstat_pending_session'))).toBeNull();
      } finally {
        await context.close();
      }
    }
  });

  test('clearing cookies creates a new session with a different visit_id', async ({ browser }) => {
    await clearStatsTable();

    // Use a fresh context so we control cookie lifecycle completely
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
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

      const rowsBefore = await waitForStatRows(markerBefore, 1, 15_000);
      expect(rowsBefore.length, 'First visit should produce a DB row').toBeGreaterThanOrEqual(1);
      const visitIdBefore = parseInt(rowsBefore[0].visit_id, 10);
      expect(visitIdBefore, 'First visit_id should be positive').toBeGreaterThan(0);

      // Clear ALL cookies — simulates cookie expiry
      await ctx.clearCookies();

      // Visit #2: should start a new session
      await page.goto(`${BASE_URL}/?e2e_marker=${markerAfter}`);
      await page.waitForLoadState('load');

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

    // Banner should NOT reappear (decision persisted)
    await expect(testPage.locator('#slimstat-gdpr-banner')).not.toBeVisible();

    // With anonymous_tracking=on, the tracker still fires even when consent
    // is denied — it just strips PII (IP is hashed, no username/email).
    const anonRows = await waitForStatRows(declinedNavMarker, 1, 15_000);
    expect(
      anonRows.length,
      'Anonymous tracking should record a row even when consent is denied',
    ).toBeGreaterThanOrEqual(1);
    // Capture the anonymous row's IP and visit_id BEFORE any upgrade runs.
    // The IP should be hashed (anonymous tracking strips PII).
    // The visit_id is the original session identifier — we'll verify it
    // survives the consent upgrade unchanged (not rewritten to a new value).
    const anonymousIp = anonRows[0].ip;
    const preUpgradeVisitId = parseInt(anonRows[0].visit_id, 10);
    expect(preUpgradeVisitId, 'Phase 2 visit_id should be positive before upgrade').toBeGreaterThan(0);

    // ── Phase 3: Click Accept on the real banner ──────────────────
    //
    // Production flow (wp-slimstat.js:2232-2244):
    //   1. User clicks [data-consent="accepted"] on the banner
    //   2. JS sets slimstat_gdpr_consent=accepted cookie
    //   3. JS calls sendConsentChangeToServer("slimstat_banner", consent, pageviewId)
    //   4. JS calls requestConsentUpgrade({ consent, consentNonce })
    //   5. Server Processor.php:409 receives consent_upgrade=1 + pageview_id
    //   6. Server merges PII into the anonymous row
    //
    // We trigger this by navigating to a new page (which creates a fresh
    // anonymous row + shows the banner again after we clear the consent cookie),
    // then clicking Accept. No manual cookie setting, no direct JS calls,
    // no force:true bypass.

    // Clear ONLY the consent cookie so the banner re-appears on next navigation.
    // CRITICAL: Do NOT clear all cookies — slimstat_tracking_code must survive
    // so the server preserves session continuity across the consent upgrade.
    // If cleared, Processor.php:382 forces a new visit_id (fallback branch)
    // instead of upgrading the existing anonymous session rows (line 646-650).
    const allCookies = await ctx.cookies();
    const cookiesToRemove = allCookies.filter(
      (c) => c.name === 'slimstat_gdpr_consent',
    );
    for (const cookie of cookiesToRemove) {
      await ctx.clearCookies({ name: cookie.name, domain: cookie.domain });
    }

    // Navigate to a new page — banner should re-appear, anonymous row created
    const upgradeMarker = `consent-upgrade-${ts}`;
    await testPage.goto(`${BASE_URL}/?e2e_marker=${upgradeMarker}`, {
      waitUntil: 'domcontentloaded',
    });

    // Wait for the anonymous pageview to be tracked (SlimStatParams.id populated).
    // This MUST succeed — the consent upgrade needs the pageview ID to target
    // the anonymous row for merging. If this times out, the test should fail
    // because the upgrade assertion would be meaningless without it.
    await testPage.waitForFunction(
      () => {
        const p = (window as any).SlimStatParams;
        return p && p.id && parseInt(p.id, 10) > 0;
      },
      { timeout: 15_000 },
    );

    // Banner should be visible again (consent cookie was cleared)
    await expect(testPage.locator('#slimstat-gdpr-banner')).toBeVisible({
      timeout: 10_000,
    });

    // Record the anonymous row's IP before upgrade
    const preUpgradeRows = await waitForStatRows(upgradeMarker, 1, 15_000);
    expect(
      preUpgradeRows.length,
      'Anonymous row should exist before clicking Accept',
    ).toBeGreaterThanOrEqual(1);
    const preUpgradeIp = preUpgradeRows[0].ip;

    // Reset tracking counter to isolate the upgrade request
    trackingRequests.length = 0;

    // Click the REAL Accept button — this triggers the full production
    // banner handler: cookie set → sendConsentChangeToServer → requestConsentUpgrade
    // No manual cookie injection, no force:true, no direct JS calls.
    const upgradeResponse = testPage.waitForResponse(response =>
      response.ok() && isSlimstatTrackingRequest(response.request()) &&
      (response.request().postData() || '').includes('consent_upgrade=1'));
    await testPage.locator('[data-consent="accepted"]').click();
    const completedUpgrade = await upgradeResponse;
    expect(completedUpgrade.ok(), 'Consent upgrade response must succeed').toBe(true);
    await completedUpgrade.finished();

    // Verify the consent cookie is now 'accepted'
    const postAcceptCookies = await ctx.cookies();
    const acceptedCookie = postAcceptCookies.find(
      (c) => c.name === 'slimstat_gdpr_consent',
    );
    expect(acceptedCookie, 'Consent cookie should be set after clicking Accept').toBeTruthy();
    expect(acceptedCookie!.value).toBe('accepted');

    // ── Phase 4: Verify the anonymous row was upgraded with PII ────

    // At least one upgrade tracking request should have fired
    expect(
      trackingRequests.length,
      'Clicking Accept should trigger a consent upgrade tracking request',
    ).toBeGreaterThanOrEqual(1);

    // ── Verify the upgrade row itself has PII ──
    const upgradedRows = await waitForStatRows(upgradeMarker, 1, 10_000);
    expect(upgradedRows.length).toBeGreaterThanOrEqual(1);
    const upgradedIp = upgradedRows[0].ip;
    const upgradeVisitId = parseInt(upgradedRows[0].visit_id, 10);

    expect(
      upgradedIp,
      `Upgrade row IP should be real PII, not hashed (${preUpgradeIp})`,
    ).not.toBe(preUpgradeIp);

    // ── Verify the EARLIER anonymous rows from Phase 2 were ALSO upgraded ──
    // The production merge at Processor.php:646-650 upgrades ALL rows with
    // matching visit_id + anonymous IP within the session window. If this
    // doesn't happen, only the upgrade-trigger row gets PII while earlier
    // browsing during the declined phase keeps hashed IPs.
    const earlierRows = await waitForStatRows(declinedNavMarker, 1, 10_000);
    expect(earlierRows.length, 'Phase 2 anonymous row should still exist').toBeGreaterThanOrEqual(1);
    const earlierIp = earlierRows[0].ip;
    const earlierVisitId = parseInt(earlierRows[0].visit_id, 10);

    // Key assertion 1: Earlier anonymous row's IP should now be PII
    // (upgraded by the merge at Processor.php:646-650).
    // If the merge didn't run (e.g. tracking cookie was cleared, forcing
    // fallback branch), the earlier row keeps its hashed IP.
    expect(
      earlierIp,
      `Phase 2 anonymous row IP should be upgraded from hashed (${anonymousIp}) to PII`,
    ).not.toBe(anonymousIp);

    // Key assertion 2: The Phase 2 visit_id must be UNCHANGED after upgrade.
    // We captured preUpgradeVisitId BEFORE clicking Accept. If the merge code
    // at Processor.php:615 rewrites all rows to a fresh visit_id, this fails.
    // This proves the ORIGINAL session identifier was preserved, not just that
    // rows were rewritten together.
    expect(
      earlierVisitId,
      `Phase 2 visit_id (${earlierVisitId}) must match pre-upgrade value (${preUpgradeVisitId}) — session identity preserved`,
    ).toBe(preUpgradeVisitId);

    // Key assertion 3: Both rows should share the same visit_id,
    // proving session continuity across the consent upgrade.
    expect(
      upgradeVisitId,
      `Upgrade row visit_id (${upgradeVisitId}) should match Phase 2 row (${earlierVisitId})`,
    ).toBe(earlierVisitId);
    const visitCookie = (await ctx.cookies()).find(cookie => cookie.name === 'slimstat_tracking_code');
    expect(visitCookie?.value.split('.')[0], 'Consent cookie must retain the original visit').toBe(String(preUpgradeVisitId));
    expect(visitCookie?.httpOnly).toBe(true);
    expect(upgradedRows).toHaveLength(1);

    const subsequentMarker = `consent-subsequent-${ts}`;
    await testPage.goto(`${BASE_URL}/?e2e_marker=${subsequentMarker}`, { waitUntil: 'networkidle' });
    const subsequentRows = await waitForStatRows(subsequentMarker, 1, 10_000);
    expect(subsequentRows).toHaveLength(1);
    expect(Number(subsequentRows[0].visit_id)).toBe(preUpgradeVisitId);
    expect(subsequentRows[0].ip).toBe(upgradedIp);

    await testPage.close();
    await ctx.close();
  });
});
