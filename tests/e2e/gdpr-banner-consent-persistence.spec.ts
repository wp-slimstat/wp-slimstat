/**
 * GDPR Banner Consent Persistence — #240 #241
 *
 * Tests the GDPR consent banner behavior across cached pages, cookie domains,
 * stale nonces, and multi-page navigation persistence.
 *
 * Issue #240: Cached page bugs — banner reappears despite consent cookie
 * Issue #241: Cookie domain mismatch — consent cookie not readable across subdomains
 *
 * @see https://github.com/wp-slimstat/wp-slimstat/issues/240
 * @see https://github.com/wp-slimstat/wp-slimstat/issues/241
 */
import { test, expect, type Page } from '@playwright/test';
import {
  closeDb,
  clearStatsTable,
  waitForPageviewRow,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';
import * as mysql from 'mysql2/promise';

const COOKIE_DOMAIN = new URL(BASE_URL).hostname;

let pool: mysql.Pool | null = null;

function getPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool({ ...MYSQL_CONFIG, connectionLimit: 3 });
  }
  return pool;
}

/** Snapshot and restore slimstat_options directly via DB. */
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
 * Supports simple string keys and string values only.
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
      const newCount = oldCount + 1;
      serialized = serialized.replace(`a:${oldCount}:{`, `a:${newCount}:{`);
      const lastBrace = serialized.lastIndexOf('}');
      const entry = `s:${key.length}:"${key}";s:${value.length}:"${value}";`;
      serialized = serialized.substring(0, lastBrace) + entry + '}';
    }
  } else {
    const valueStart = keyIdx + keyPattern.length;
    const valueMatch = serialized.substring(valueStart).match(/^s:\d+:"[^"]*";/);
    if (valueMatch) {
      const oldValue = valueMatch[0];
      const newValue = `s:${value.length}:"${value}";`;
      serialized = serialized.substring(0, valueStart) + newValue + serialized.substring(valueStart + oldValue.length);
    }
  }

  await getPool().execute(
    "UPDATE wp_options SET option_value = ? WHERE option_name = 'slimstat_options'",
    [serialized],
  );
}

test.afterAll(async () => {
  if (pool) {
    await pool.end();
    pool = null;
  }
  await closeDb();
});

// ─── CookieYes dismissal cookies ─────────────────────────────────────
// The test site has cookie-law-info (CookieYes) active. Its overlay blocks
// the SlimStat banner buttons. Pre-set these cookies to dismiss CookieYes.
const COOKIEYES_DISMISS_COOKIES = [
  { name: 'viewed_cookie_policy', value: 'yes', domain: COOKIE_DOMAIN, path: '/' },
  { name: 'CookieLawInfoConsent', value: 'true', domain: COOKIE_DOMAIN, path: '/' },
  { name: 'cookielawinfo-checkbox-necessary', value: 'yes', domain: COOKIE_DOMAIN, path: '/' },
  { name: 'cookielawinfo-checkbox-analytics', value: 'yes', domain: COOKIE_DOMAIN, path: '/' },
];

// ─── Helper functions ────────────────────────────────────────────────

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

function isConsentChangeRequest(req: import('@playwright/test').Request): boolean {
  return (
    (req.url().includes('/slimstat/v1/consent-change') ||
      req.url().includes('/slimstat/v1/gdpr/consent')) &&
    req.method() === 'POST'
  );
}

// ─── Test suite ──────────────────────────────────────────────────────

test.describe('GDPR Banner Consent Persistence — #240 #241', () => {
  test.setTimeout(90_000);

  test.beforeEach(async () => {
    await snapshotOptions();
    await setOption('consent_integration', 'slimstat_banner');
    await setOption('use_slimstat_banner', 'on');
    await setOption('gdpr_enabled', 'on');
    await setOption('tracking_request_method', 'rest');
    await setOption('anonymous_tracking', 'off');
    await setOption('set_tracker_cookie', 'on');
    await setOption('ignore_capabilities', '');
    await clearStatsTable();
  });

  test.afterEach(async () => {
    await restoreOptions();
  });

  // ═══════════════════════════════════════════════════════════════
  // Test 1: Fresh visitor accepts — banner gone, tracking works,
  //         persists across pages
  // ═══════════════════════════════════════════════════════════════
  test('Fresh visitor accepts — banner gone, tracking works, persists across pages', async ({
    page,
  }) => {
    const browser = page.context().browser()!;
    const ctx = await browser.newContext();
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const newPage = await ctx.newPage();

    const ts = Date.now();

    // Listen for tracking and consent-change requests/responses
    const trackingRequests: string[] = [];
    const consentChangeResponses: { status: number; url: string }[] = [];

    newPage.on('request', (req) => {
      if (isSlimstatTrackingRequest(req)) {
        trackingRequests.push(req.url());
      }
    });
    newPage.on('response', (res) => {
      if (
        res.url().includes('/slimstat/v1/consent-change') ||
        res.url().includes('/slimstat/v1/gdpr/consent')
      ) {
        consentChangeResponses.push({ status: res.status(), url: res.url() });
      }
    });

    // Navigate to the first page
    await newPage.goto(`${BASE_URL}/?e2e_marker=accept-journey-${ts}`, {
      waitUntil: 'domcontentloaded',
    });
    await newPage.waitForTimeout(3000);

    // Banner should be visible
    await expect(newPage.locator('#slimstat-gdpr-banner')).toBeVisible();

    // Click Accept
    await newPage.click('[data-consent="accepted"]');
    await newPage.waitForTimeout(3000);

    // Banner should be removed from DOM
    await expect(newPage.locator('#slimstat-gdpr-banner')).not.toBeAttached();

    // At least one consent-change response should be 200 (not 403)
    const successfulConsent = consentChangeResponses.filter((r) => r.status === 200);
    expect(
      successfulConsent.length,
      `Expected at least one successful consent-change response (200), got: ${JSON.stringify(consentChangeResponses)}`,
    ).toBeGreaterThanOrEqual(1);

    // Navigate to a second page
    await newPage.goto(`${BASE_URL}/?e2e_marker=accept-page2-${ts}`, {
      waitUntil: 'domcontentloaded',
    });
    await newPage.waitForTimeout(3000);

    // Banner should NOT be visible on second page
    await expect(newPage.locator('#slimstat-gdpr-banner')).not.toBeVisible();

    // Verify consent cookie is set to 'accepted'
    const cookies = await ctx.cookies();
    const consentCookie = cookies.find((c) => c.name === 'slimstat_gdpr_consent');
    expect(consentCookie, 'slimstat_gdpr_consent cookie should exist').toBeTruthy();
    expect(consentCookie!.value).toBe('accepted');

    // Verify tracking data was recorded for both pages
    const row1 = await waitForPageviewRow(`accept-journey-${ts}`);
    expect(row1, 'First page should be tracked').toBeTruthy();

    const row2 = await waitForPageviewRow(`accept-page2-${ts}`);
    expect(row2, 'Second page should be tracked').toBeTruthy();

    await newPage.close();
    await ctx.close();
  });

  // ═══════════════════════════════════════════════════════════════
  // Test 2: Fresh visitor declines — banner gone, no tracking,
  //         persists across pages
  // ═══════════════════════════════════════════════════════════════
  test('Fresh visitor declines — banner gone, no tracking, persists across pages', async ({
    page,
  }) => {
    const browser = page.context().browser()!;
    const ctx = await browser.newContext();
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const newPage = await ctx.newPage();

    const ts = Date.now();

    const trackingRequests: string[] = [];

    newPage.on('request', (req) => {
      if (isSlimstatTrackingRequest(req)) {
        trackingRequests.push(req.url());
      }
    });

    // Navigate to the first page
    await newPage.goto(`${BASE_URL}/?e2e_marker=decline-journey-${ts}`, {
      waitUntil: 'domcontentloaded',
    });
    await newPage.waitForTimeout(3000);

    // Banner should be visible
    await expect(newPage.locator('#slimstat-gdpr-banner')).toBeVisible();

    // Click Decline
    await newPage.click('[data-consent="denied"]');
    await newPage.waitForTimeout(3000);

    // Banner should be removed from DOM
    await expect(newPage.locator('#slimstat-gdpr-banner')).not.toBeAttached();

    // Navigate to a second page
    await newPage.goto(`${BASE_URL}/?e2e_marker=decline-page2-${ts}`, {
      waitUntil: 'domcontentloaded',
    });
    await newPage.waitForTimeout(3000);

    // Banner should NOT be visible on second page
    await expect(newPage.locator('#slimstat-gdpr-banner')).not.toBeVisible();

    // Zero tracking requests should have been intercepted
    expect(
      trackingRequests.length,
      'No tracking requests should fire after declining consent',
    ).toBe(0);

    // Cookie should be 'denied'
    const cookies = await ctx.cookies();
    const consentCookie = cookies.find((c) => c.name === 'slimstat_gdpr_consent');
    expect(consentCookie, 'slimstat_gdpr_consent cookie should exist').toBeTruthy();
    expect(consentCookie!.value).toBe('denied');

    // DB: no rows for either marker
    await new Promise((r) => setTimeout(r, 2000));

    const [rows1] = (await db.execute(
      'SELECT id FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1',
      [`%decline-journey-${ts}%`],
    )) as any;
    expect(rows1.length, 'No DB rows for first declined page').toBe(0);

    const [rows2] = (await db.execute(
      'SELECT id FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1',
      [`%decline-page2-${ts}%`],
    )) as any;
    expect(rows2.length, 'No DB rows for second declined page').toBe(0);

    await newPage.close();
    await ctx.close();
  });

  // ═══════════════════════════════════════════════════════════════
  // Test 3: Cached page with accept cookie — banner hidden
  //         (real HTML capture)
  // ═══════════════════════════════════════════════════════════════
  test('Cached page with accept cookie — banner hidden (real HTML capture)', async ({
    page,
  }) => {
    const browser = page.context().browser()!;
    const ts = Date.now();

    // Step 1: Capture real WordPress HTML
    const captureCtx = await browser.newContext();
    const capturePage = await captureCtx.newPage();
    const response = await capturePage.request.get(
      `${BASE_URL}/?e2e_marker=cached-accept-${ts}`,
    );
    const cachedHtml = await response.text();
    await capturePage.close();
    await captureCtx.close();

    // Step 2: Serve captured HTML to a context with 'accepted' cookie
    const testCtx = await browser.newContext();
    await testCtx.addCookies([
      ...COOKIEYES_DISMISS_COOKIES,
      {
        name: 'slimstat_gdpr_consent',
        value: 'accepted',
        domain: COOKIE_DOMAIN,
        path: '/',
      },
    ]);
    const testPage = await testCtx.newPage();

    await testPage.route(`**/?e2e_marker=cached-accept*`, (route) =>
      route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: cachedHtml,
      }),
    );

    await testPage.goto(`${BASE_URL}/?e2e_marker=cached-accept-${ts}`, {
      waitUntil: 'domcontentloaded',
    });
    await testPage.waitForTimeout(2000);

    // Banner should NOT be visible when consent cookie is already set
    const banner = testPage.locator('#slimstat-gdpr-banner');
    await expect(banner).not.toBeVisible();

    await testPage.close();
    await testCtx.close();
  });

  // ═══════════════════════════════════════════════════════════════
  // Test 4: Cached page with deny cookie — banner hidden, no tracking
  // ═══════════════════════════════════════════════════════════════
  test('Cached page with deny cookie — banner hidden, no tracking', async ({
    page,
  }) => {
    const browser = page.context().browser()!;
    const ts = Date.now();

    // Step 1: Capture real WordPress HTML
    const captureCtx = await browser.newContext();
    const capturePage = await captureCtx.newPage();
    const response = await capturePage.request.get(
      `${BASE_URL}/?e2e_marker=cached-deny-${ts}`,
    );
    const cachedHtml = await response.text();
    await capturePage.close();
    await captureCtx.close();

    // Step 2: Serve captured HTML to a context with 'denied' cookie
    const testCtx = await browser.newContext();
    await testCtx.addCookies([
      ...COOKIEYES_DISMISS_COOKIES,
      {
        name: 'slimstat_gdpr_consent',
        value: 'denied',
        domain: COOKIE_DOMAIN,
        path: '/',
      },
    ]);
    const testPage = await testCtx.newPage();

    const trackingRequests: string[] = [];
    testPage.on('request', (req) => {
      if (isSlimstatTrackingRequest(req)) {
        trackingRequests.push(req.url());
      }
    });

    await testPage.route(`**/?e2e_marker=cached-deny*`, (route) =>
      route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: cachedHtml,
      }),
    );

    await testPage.goto(`${BASE_URL}/?e2e_marker=cached-deny-${ts}`, {
      waitUntil: 'domcontentloaded',
    });
    await testPage.waitForTimeout(2000);

    // Banner should NOT be visible (decision already made)
    const banner = testPage.locator('#slimstat-gdpr-banner');
    await expect(banner).not.toBeVisible();

    // No tracking requests should fire when consent is denied
    expect(
      trackingRequests.length,
      'No tracking requests should fire with denied consent cookie on cached page',
    ).toBe(0);

    await testPage.close();
    await testCtx.close();
  });

  // ═══════════════════════════════════════════════════════════════
  // Test 5: Stale nonce on cached page — consent still succeeds
  // ═══════════════════════════════════════════════════════════════
  test('Stale nonce on cached page — consent still succeeds', async ({
    page,
  }) => {
    const browser = page.context().browser()!;
    const ts = Date.now();

    // Step 1: Capture real WordPress HTML
    const captureCtx = await browser.newContext();
    const capturePage = await captureCtx.newPage();
    const response = await capturePage.request.get(
      `${BASE_URL}/?e2e_marker=stale-nonce-${ts}`,
    );
    let cachedHtml = await response.text();
    await capturePage.close();
    await captureCtx.close();

    // Step 2: Replace the wp_rest_nonce with a stale/expired value
    cachedHtml = cachedHtml.replace(
      /"wp_rest_nonce":"[^"]*"/,
      '"wp_rest_nonce":"stale_expired_nonce_12345"',
    );

    // Step 3: Serve modified HTML via page.route()
    const testCtx = await browser.newContext();
    await testCtx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const testPage = await testCtx.newPage();

    const consentChangeResponses: { status: number; url: string }[] = [];
    testPage.on('response', (res) => {
      if (
        res.url().includes('/slimstat/v1/consent-change') ||
        res.url().includes('/slimstat/v1/gdpr/consent')
      ) {
        consentChangeResponses.push({ status: res.status(), url: res.url() });
      }
    });

    await testPage.route(`**/?e2e_marker=stale-nonce*`, (route) =>
      route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: cachedHtml,
      }),
    );

    await testPage.goto(`${BASE_URL}/?e2e_marker=stale-nonce-${ts}`, {
      waitUntil: 'domcontentloaded',
    });
    await testPage.waitForTimeout(3000);

    // Banner should appear (no prior consent cookie)
    await expect(testPage.locator('#slimstat-gdpr-banner')).toBeVisible();

    // Step 4: Click Accept
    await testPage.click('[data-consent="accepted"]');
    await testPage.waitForTimeout(3000);

    // Step 5: Consent-change response should be 200 (not 403)
    // The consent endpoint should work even with a stale nonce
    const successfulConsent = consentChangeResponses.filter(
      (r) => r.status === 200,
    );
    expect(
      successfulConsent.length,
      `Consent should succeed even with stale nonce. Responses: ${JSON.stringify(consentChangeResponses)}`,
    ).toBeGreaterThanOrEqual(1);

    // Step 6: Consent cookie should be set
    const cookies = await testCtx.cookies();
    const consentCookie = cookies.find(
      (c) => c.name === 'slimstat_gdpr_consent',
    );
    expect(
      consentCookie,
      'slimstat_gdpr_consent cookie should be set after accepting on stale-nonce cached page',
    ).toBeTruthy();

    await testPage.close();
    await testCtx.close();
  });

  // ═══════════════════════════════════════════════════════════════
  // Test 6: Cookie domain attribute matches PHP COOKIE_DOMAIN
  // ═══════════════════════════════════════════════════════════════
  test('Cookie domain attribute matches PHP COOKIE_DOMAIN', async ({
    page,
  }) => {
    const browser = page.context().browser()!;
    const ctx = await browser.newContext();
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const newPage = await ctx.newPage();

    await newPage.goto(`${BASE_URL}/?e2e_marker=cookie-domain-${Date.now()}`, {
      waitUntil: 'domcontentloaded',
    });
    await newPage.waitForTimeout(3000);

    // Extract SlimStatParams from the page
    const params = await newPage.evaluate(
      () => (window as any).SlimStatParams || null,
    );
    expect(params, 'SlimStatParams must be present on the page').toBeTruthy();

    // gdpr_cookie_domain may be defined (could be empty string for some installs)
    const gdprCookieDomain: string | undefined = params.gdpr_cookie_domain;

    // Click Accept to trigger cookie creation
    await expect(newPage.locator('#slimstat-gdpr-banner')).toBeVisible();
    await newPage.click('[data-consent="accepted"]');
    await newPage.waitForTimeout(3000);

    // Read cookies
    const cookies = await ctx.cookies();
    const consentCookie = cookies.find(
      (c) => c.name === 'slimstat_gdpr_consent',
    );
    expect(consentCookie, 'slimstat_gdpr_consent cookie should exist').toBeTruthy();

    if (gdprCookieDomain && gdprCookieDomain.length > 0) {
      // If PHP sets a specific cookie domain, the browser cookie domain should include it
      expect(
        consentCookie!.domain,
        `Cookie domain "${consentCookie!.domain}" should include configured gdpr_cookie_domain "${gdprCookieDomain}"`,
      ).toContain(gdprCookieDomain);
    } else {
      // If no specific domain is configured, cookie domain should match the page hostname
      const pageHostname = new URL(BASE_URL).hostname;
      expect(
        consentCookie!.domain.replace(/^\./, ''),
        `Cookie domain should match page hostname "${pageHostname}"`,
      ).toBe(pageHostname);
    }

    await newPage.close();
    await ctx.close();
  });

  // ═══════════════════════════════════════════════════════════════
  // Test 7: Multi-page navigation after accept — banner never reappears
  // ═══════════════════════════════════════════════════════════════
  test('Multi-page navigation after accept — banner never reappears', async ({
    page,
  }) => {
    const browser = page.context().browser()!;
    const ctx = await browser.newContext();
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const newPage = await ctx.newPage();

    const ts = Date.now();

    const trackingRequests: string[] = [];
    newPage.on('request', (req) => {
      if (isSlimstatTrackingRequest(req)) {
        trackingRequests.push(req.url());
      }
    });

    // Navigate to first page and accept consent
    await newPage.goto(`${BASE_URL}/?e2e_marker=multi-nav-start-${ts}`, {
      waitUntil: 'domcontentloaded',
    });
    await newPage.waitForTimeout(3000);

    await expect(newPage.locator('#slimstat-gdpr-banner')).toBeVisible();
    await newPage.click('[data-consent="accepted"]');
    await newPage.waitForTimeout(3000);

    // Navigate across multiple pages and verify banner never reappears
    const pages = ['/', '/sample-page/', '/?p=1'];
    for (const slug of pages) {
      // Reset tracking count before each navigation (not needed for assertion,
      // but we track all requests to verify tracking fires)
      await newPage.goto(`${BASE_URL}${slug}`, {
        waitUntil: 'domcontentloaded',
      });
      await newPage.waitForTimeout(3000);

      // Banner must NOT be visible on any subsequent page
      await expect(
        newPage.locator('#slimstat-gdpr-banner'),
        `Banner should not be visible on ${slug}`,
      ).not.toBeVisible();
    }

    // Verify tracking requests fired (at least one per page including the accept page)
    // The accept page + 3 navigations = at least 3 tracking requests expected
    // (the accept page itself may or may not fire tracking depending on timing)
    expect(
      trackingRequests.length,
      'Tracking requests should fire after accepting consent across pages',
    ).toBeGreaterThanOrEqual(3);

    await newPage.close();
    await ctx.close();
  });

  // ═══════════════════════════════════════════════════════════════
  // Test 8: Fresh visit — banner IS shown, no tracking fires (sanity)
  // ═══════════════════════════════════════════════════════════════
  test('Fresh visit — banner IS shown, no tracking fires (sanity)', async ({
    page,
  }) => {
    const browser = page.context().browser()!;
    const ctx = await browser.newContext();
    await ctx.addCookies(COOKIEYES_DISMISS_COOKIES);
    const newPage = await ctx.newPage();

    const trackingRequests: string[] = [];
    newPage.on('request', (req) => {
      if (isSlimstatTrackingRequest(req)) {
        trackingRequests.push(req.url());
      }
    });

    await newPage.goto(
      `${BASE_URL}/?e2e_marker=sanity-banner-${Date.now()}`,
      { waitUntil: 'domcontentloaded' },
    );
    await newPage.waitForTimeout(3000);

    // Banner MUST be visible on a fresh visit with no prior consent
    await expect(newPage.locator('#slimstat-gdpr-banner')).toBeVisible();

    // Zero tracking requests should fire before consent decision
    expect(
      trackingRequests.length,
      'No tracking should fire before consent decision',
    ).toBe(0);

    await newPage.close();
    await ctx.close();
  });
});
