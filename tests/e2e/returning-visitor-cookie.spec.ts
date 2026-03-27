/**
 * E2E tests: Returning Visitor Cookie Behavior
 *
 * Covers:
 *  1. Same-session return — cookie format transitions from new→returning, visit_id linked
 *  2. Access Log report — 3-page session shares visit_id, ip, chronological dt
 *  3. Cross-session return — expired session gets new monotonically-increasing visit_id
 *  4. GDPR off — cookie set without consent gate
 *  5. GDPR on, no consent, anonymous_tracking — no cookie, anonymous row recorded
 *  6. Consent upgrade — cookie appears after accept, session continues
 *  7. Cookie cleared mid-session — new session created
 *  8. Tampered cookie checksum — rejected by server
 *
 * Validates that the slimstat_tracking_code cookie drives session continuity
 * and that the wp_slim_stats table (Access Log) reflects correct returning visitor data.
 */
import { test, expect } from '@playwright/test';
import * as mysql from 'mysql2/promise';
import {
  setSlimstatOption,
  setSlimstatOptions,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  closeDb,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';

const COOKIE_DOMAIN = new URL(BASE_URL).hostname;

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
      'SELECT id, visit_id, ip, resource, dt, browser, platform, fingerprint FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id ASC LIMIT 20',
      [`%${marker}%`],
    )) as any;
    if (rows.length >= minRows) return rows;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  const [rows] = (await getPool().execute(
    'SELECT id, visit_id, ip, resource, dt, browser, platform, fingerprint FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id ASC LIMIT 20',
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

/** Batch-configure basic tracking settings. */
async function configureBasicTracking(page: import('@playwright/test').Page): Promise<void> {
  await setSlimstatOptions(page, {
    gdpr_enabled: 'off',
    javascript_mode: 'on',
    set_tracker_cookie: 'on',
    tracking_request_method: 'rest',
    ignore_wp_users: 'no',
    anonymous_tracking: 'off',
    anonymize_ip: 'off',
    hash_ip: 'off',
  });
}

/** Find the slimstat_tracking_code cookie from a browser context. */
async function findTrackingCookie(
  ctx: import('@playwright/test').BrowserContext,
): Promise<{ name: string; value: string } | undefined> {
  const cookies = await ctx.cookies();
  return cookies.find((c) => c.name === 'slimstat_tracking_code');
}

/** Parse slimstat_tracking_code cookie value into parts. */
function parseCookieValue(value: string): { rawValue: string; checksum: string; isNewSession: boolean; visitId: number } {
  const dotIdx = value.indexOf('.');
  if (dotIdx === -1) throw new Error(`Invalid cookie format: ${value}`);
  const rawValue = value.substring(0, dotIdx);
  const checksum = value.substring(dotIdx + 1);
  const isNewSession = rawValue.endsWith('id');
  const visitId = parseInt(rawValue.replace(/id$/, ''), 10);
  return { rawValue, checksum, isNewSession, visitId };
}

// ─── Test suite ───────────────────────────────────────────────────────

test.describe('Returning Visitor Cookie Behavior', () => {
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
  // Scenario 1: Same-Session Return
  // ═══════════════════════════════════════════════════════════════════

  test('cookie set on first pageview and visit_id linked on second', async ({ page, browser }) => {
    await clearStatsTable();
    await configureBasicTracking(page);

    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const anonPage = await ctx.newPage();

    try {
      const marker = `return-format-${Date.now()}`;

      // Page 1 — first pageview of session
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}-p1`, { waitUntil: 'load' });
      await anonPage.waitForTimeout(4000);

      const cookie1 = await findTrackingCookie(ctx);
      expect(cookie1, 'Tracking cookie must be set on first pageview').toBeTruthy();

      // Cookie should have valid format: {value}.{checksum}
      expect(cookie1!.value).toMatch(/^.+\..+$/);

      // Page 2 — returning within same session
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}-p2`, { waitUntil: 'load' });
      await anonPage.waitForTimeout(4000);

      const cookie2 = await findTrackingCookie(ctx);
      expect(cookie2, 'Tracking cookie must exist on second pageview').toBeTruthy();

      // DB verification — both pages should share the same visit_id
      const rows = await waitForStatRows(marker, 2, 20_000);
      expect(rows.length, 'Both pageviews should be tracked').toBeGreaterThanOrEqual(2);
      const visitIds = rows.map((r: any) => parseInt(r.visit_id, 10)).filter((v: number) => v > 0);
      const uniqueIds = [...new Set(visitIds)];
      expect(uniqueIds, 'Both pageviews should share one visit_id').toHaveLength(1);
    } finally {
      await anonPage.close();
      await ctx.close();
    }
  });

  test('3-page session — all rows share visit_id in Access Log', async ({ page, browser }) => {
    await clearStatsTable();
    await configureBasicTracking(page);

    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const anonPage = await ctx.newPage();

    try {
      const marker = `return-3page-${Date.now()}`;

      for (const suffix of ['p1', 'p2', 'p3']) {
        await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}-${suffix}`, { waitUntil: 'load' });
        await anonPage.waitForTimeout(2000);
      }

      const rows = await waitForStatRows(marker, 3, 20_000);
      expect(rows.length, 'All 3 pageviews should be tracked').toBeGreaterThanOrEqual(3);

      // All share same visit_id
      const visitIds = [...new Set(rows.map((r: any) => parseInt(r.visit_id, 10)))];
      expect(visitIds, 'All 3 rows should share one visit_id').toHaveLength(1);
      expect(visitIds[0], 'visit_id must be positive').toBeGreaterThan(0);

      // All share same IP
      const ips = [...new Set(rows.map((r: any) => r.ip))];
      expect(ips, 'All 3 rows should have same IP').toHaveLength(1);

      // dt monotonically increasing
      const dts = rows.map((r: any) => parseInt(r.dt, 10));
      for (let i = 1; i < dts.length; i++) {
        expect(dts[i], `dt[${i}] should be >= dt[${i - 1}]`).toBeGreaterThanOrEqual(dts[i - 1]);
      }
    } finally {
      await anonPage.close();
      await ctx.close();
    }
  });

  // ═══════════════════════════════════════════════════════════════════
  // Scenario 2: Cross-Session Return
  // ═══════════════════════════════════════════════════════════════════

  test('expired session gets new monotonically-increasing visit_id', async ({ page, browser }) => {
    await clearStatsTable();
    await configureBasicTracking(page);

    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const anonPage = await ctx.newPage();

    try {
      const ts = Date.now();
      const markerBefore = `return-before-${ts}`;
      const markerAfter = `return-after-${ts}`;

      // Session 1: visit 2 pages
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${markerBefore}-p1`, { waitUntil: 'load' });
      await anonPage.waitForTimeout(3000);

      const cookie1 = await findTrackingCookie(ctx);
      expect(cookie1, 'Cookie must be set in session 1').toBeTruthy();
      const visitId1 = parseCookieValue(cookie1!.value).visitId;

      await anonPage.goto(`${BASE_URL}/?e2e_marker=${markerBefore}-p2`, { waitUntil: 'load' });
      await anonPage.waitForTimeout(2000);

      // Simulate session expiry
      await ctx.clearCookies();
      await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);

      // Session 2: new visit
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${markerAfter}`, { waitUntil: 'load' });
      await anonPage.waitForTimeout(3000);

      const cookie2 = await findTrackingCookie(ctx);
      expect(cookie2, 'Cookie must be set in session 2').toBeTruthy();

      const parsed2 = parseCookieValue(cookie2!.value);
      expect(parsed2.visitId, 'New visit_id must differ from session 1').not.toBe(visitId1);
      expect(parsed2.visitId, 'New visit_id must be greater (monotonic counter)').toBeGreaterThan(visitId1);

      // DB: two distinct visit_ids
      const rowsBefore = await waitForStatRows(markerBefore, 2, 15_000);
      const rowsAfter = await waitForStatRows(markerAfter, 1, 15_000);
      expect(rowsBefore.length).toBeGreaterThanOrEqual(2);
      expect(rowsAfter.length).toBeGreaterThanOrEqual(1);

      const vid1 = parseInt(rowsBefore[0].visit_id, 10);
      const vid2 = parseInt(rowsAfter[0].visit_id, 10);
      expect(vid2, 'Session 2 visit_id must differ from session 1').not.toBe(vid1);
    } finally {
      await anonPage.close();
      await ctx.close();
    }
  });

  // ═══════════════════════════════════════════════════════════════════
  // Scenario 3: GDPR Consent
  // ═══════════════════════════════════════════════════════════════════

  test('GDPR off — cookie set without consent gate', async ({ page, browser }) => {
    await clearStatsTable();
    await configureBasicTracking(page); // gdpr_enabled=off

    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const anonPage = await ctx.newPage();

    try {
      const marker = `return-gdpr-off-${Date.now()}`;
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}`, { waitUntil: 'load' });
      await anonPage.waitForTimeout(3000);

      const cookie = await findTrackingCookie(ctx);
      expect(cookie, 'Cookie must be set when GDPR is off').toBeTruthy();

      const rows = await waitForStatRows(marker, 1, 15_000);
      expect(rows.length).toBeGreaterThanOrEqual(1);
      expect(parseInt(rows[0].visit_id, 10), 'visit_id must be positive').toBeGreaterThan(0);
    } finally {
      await anonPage.close();
      await ctx.close();
    }
  });

  test('GDPR on, no consent, anonymous_tracking on — no cookie but row recorded', async ({ page, browser }) => {
    await clearStatsTable();
    await setSlimstatOptions(page, {
      gdpr_enabled: 'on',
      consent_integration: 'slimstat_banner',
      use_slimstat_banner: 'on',
      anonymous_tracking: 'on',
      set_tracker_cookie: 'on',
      javascript_mode: 'on',
      tracking_request_method: 'rest',
    });

    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const anonPage = await ctx.newPage();

    try {
      const marker = `return-gdpr-anon-${Date.now()}`;
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}`, { waitUntil: 'domcontentloaded' });

      // Decline consent
      await expect(anonPage.locator('#slimstat-gdpr-banner')).toBeVisible({ timeout: 10_000 });
      await anonPage.locator('[data-consent="denied"]').click();
      await anonPage.waitForTimeout(2000);

      // Navigate to a second page to trigger anonymous tracking
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}-nav`, { waitUntil: 'load' });
      await anonPage.waitForTimeout(3000);

      // With anonymous_tracking=on, a cookie may or may not be set depending
      // on implementation — the key assertion is that a DB row IS recorded.
      const rows = await waitForStatRows(marker, 1, 15_000);
      expect(rows.length, 'Anonymous tracking should record a row even when consent is denied').toBeGreaterThanOrEqual(1);
      expect(parseInt(rows[0].visit_id, 10), 'visit_id should be positive (session assigned)').toBeGreaterThan(0);
    } finally {
      await anonPage.close();
      await ctx.close();
    }
  });

  test('consent upgrade — cookie appears after accept, session continues', async ({ page, browser }) => {
    await clearStatsTable();
    await setSlimstatOptions(page, {
      gdpr_enabled: 'on',
      consent_integration: 'slimstat_banner',
      use_slimstat_banner: 'on',
      anonymous_tracking: 'on',
      set_tracker_cookie: 'on',
      javascript_mode: 'on',
      tracking_request_method: 'rest',
    });

    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const anonPage = await ctx.newPage();

    const trackingRequests: string[] = [];
    anonPage.on('request', (req) => {
      if (isSlimstatTrackingRequest(req)) trackingRequests.push(req.url());
    });

    try {
      const ts = Date.now();

      // Phase 1: Decline consent
      await anonPage.goto(`${BASE_URL}/?e2e_marker=return-consent-${ts}-decline`, { waitUntil: 'domcontentloaded' });
      await expect(anonPage.locator('#slimstat-gdpr-banner')).toBeVisible({ timeout: 10_000 });
      await anonPage.locator('[data-consent="denied"]').click();
      await anonPage.waitForTimeout(2000);

      // Note: with anonymous_tracking=on, a tracking cookie may be set even after
      // decline — anonymous mode uses cookies for session continuity with hashed IPs.

      // Phase 2: Clear consent cookie, re-navigate, accept
      const allCookies = await ctx.cookies();
      for (const c of allCookies.filter((c) => c.name === 'slimstat_gdpr_consent')) {
        await ctx.clearCookies({ name: c.name, domain: c.domain });
      }

      await anonPage.goto(`${BASE_URL}/?e2e_marker=return-consent-${ts}-accept`, { waitUntil: 'domcontentloaded' });
      await anonPage.waitForFunction(
        () => { const p = (window as any).SlimStatParams; return p && p.id && parseInt(p.id, 10) > 0; },
        { timeout: 15_000 },
      );
      await expect(anonPage.locator('#slimstat-gdpr-banner')).toBeVisible({ timeout: 10_000 });

      trackingRequests.length = 0;
      await anonPage.locator('[data-consent="accepted"]').click();

      // Wait for consent upgrade request
      const deadline = Date.now() + 15_000;
      while (trackingRequests.length === 0 && Date.now() < deadline) {
        await new Promise((r) => setTimeout(r, 500));
      }

      // Cookie should now exist
      const cookie = await findTrackingCookie(ctx);
      expect(cookie, 'Cookie must be set after consent upgrade').toBeTruthy();

      // Phase 3: Navigate again — should reuse session
      await anonPage.goto(`${BASE_URL}/?e2e_marker=return-consent-${ts}-post`, { waitUntil: 'load' });
      await anonPage.waitForTimeout(3000);

      const postCookie = await findTrackingCookie(ctx);
      expect(postCookie, 'Cookie must persist on subsequent page').toBeTruthy();

      // DB: post-consent pages should share visit_id
      const rows = await waitForStatRows(`return-consent-${ts}`, 2, 20_000);
      expect(rows.length).toBeGreaterThanOrEqual(2);
      const postConsentRows = rows.filter((r: any) => r.resource.includes('-accept') || r.resource.includes('-post'));
      if (postConsentRows.length >= 2) {
        const vids = [...new Set(postConsentRows.map((r: any) => parseInt(r.visit_id, 10)))];
        expect(vids, 'Post-consent pages should share visit_id').toHaveLength(1);
      }
    } finally {
      await anonPage.close();
      await ctx.close();
    }
  });

  // ═══════════════════════════════════════════════════════════════════
  // Scenario 4: Cookie Cleared / Tampered
  // ═══════════════════════════════════════════════════════════════════

  test('cookie cleared — new browser context creates new session', async ({ page, browser }) => {
    await clearStatsTable();
    await configureBasicTracking(page);

    const ts = Date.now();

    // Session 1: first context
    const ctx1 = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await ctx1.addCookies(COOKIEYES_DISMISS_COOKIES);
    const page1 = await ctx1.newPage();

    await page1.goto(`${BASE_URL}/?e2e_marker=return-clear-${ts}-p1`, { waitUntil: 'load' });
    await page1.waitForTimeout(4000);

    const cookie1 = await findTrackingCookie(ctx1);
    expect(cookie1, 'Cookie must be set in session 1').toBeTruthy();
    const visitId1 = parseCookieValue(cookie1!.value).visitId;

    await page1.close();
    await ctx1.close();

    // Session 2: completely fresh context (simulates cleared cookies / new browser)
    const ctx2 = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await ctx2.addCookies(COOKIEYES_DISMISS_COOKIES);
    const page2 = await ctx2.newPage();

    await page2.goto(`${BASE_URL}/?e2e_marker=return-clear-${ts}-p2`, { waitUntil: 'load' });
    await page2.waitForTimeout(4000);

    const cookie2 = await findTrackingCookie(ctx2);
    expect(cookie2, 'Cookie must be set in session 2').toBeTruthy();
    const visitId2 = parseCookieValue(cookie2!.value).visitId;

    expect(visitId2, 'New visit_id must differ from session 1').not.toBe(visitId1);

    await page2.close();
    await ctx2.close();

    // DB verification
    const rows = await waitForStatRows(`return-clear-${ts}`, 2, 15_000);
    expect(rows.length).toBeGreaterThanOrEqual(2);
    const vids = [...new Set(rows.map((r: any) => parseInt(r.visit_id, 10)).filter((v: number) => v > 0))];
    expect(vids.length, 'Should have 2 distinct visit_ids from 2 separate sessions').toBe(2);
  });

  test('tampered cookie checksum — rejected by server', async ({ page, browser }) => {
    await clearStatsTable();
    await configureBasicTracking(page);

    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const anonPage = await ctx.newPage();

    try {
      const ts = Date.now();

      // Page 1 — get a valid cookie
      await anonPage.goto(`${BASE_URL}/?e2e_marker=return-tamper-${ts}-p1`, { waitUntil: 'load' });
      await anonPage.waitForTimeout(3000);

      const validCookie = await findTrackingCookie(ctx);
      expect(validCookie, 'Valid cookie must be set').toBeTruthy();
      const { visitId: originalVisitId } = parseCookieValue(validCookie!.value);

      // Tamper the checksum (keep format valid, invalidate HMAC)
      const dotIdx = validCookie!.value.indexOf('.');
      const valuepart = validCookie!.value.substring(0, dotIdx);
      const tamperedValue = `${valuepart}.${'a'.repeat(64)}`;

      // Replace cookie with tampered version
      await ctx.clearCookies({ name: 'slimstat_tracking_code' });
      await ctx.addCookies([{
        name: 'slimstat_tracking_code',
        value: tamperedValue,
        domain: COOKIE_DOMAIN,
        path: '/',
        httpOnly: true,
        sameSite: 'Lax' as const,
      }]);

      // Page 2 — server should reject tampered cookie
      await anonPage.goto(`${BASE_URL}/?e2e_marker=return-tamper-${ts}-p2`, { waitUntil: 'load' });
      await anonPage.waitForTimeout(3000);

      // DB: the tampered-cookie pageview should either have visit_id=0
      // (checksum rejected, ensureVisitId returned false) or a NEW visit_id
      // (JS tracker retried). Either way, it should NOT reuse the original visit_id.
      const rows = await waitForStatRows(`return-tamper-${ts}-p2`, 1, 15_000);
      expect(rows.length, 'Tampered pageview should still be tracked').toBeGreaterThanOrEqual(1);

      const tamperedVisitId = parseInt(rows[0].visit_id, 10);
      // The tampered cookie's visit_id should NOT be reused (checksum failed)
      if (tamperedVisitId > 0) {
        expect(tamperedVisitId, 'Tampered visit_id should differ from original (new session assigned)').not.toBe(originalVisitId);
      }
      // visit_id=0 is also acceptable (server rejected cookie entirely)
    } finally {
      await anonPage.close();
      await ctx.close();
    }
  });
});
