/**
 * PR #178 — Integration test: WP Consent API consent → tracking behavior
 *
 * Validates the bug fix: when a user rejects consent via WP Consent API,
 * the JS tracker must NOT send any tracking request.
 *
 * Strategy: With javascript_mode=on, ALL tracking happens via JS AJAX/REST.
 * We intercept network requests to verify the consent decision.
 *
 * Note: CookieYes is active as CMP. To isolate tests from CookieYes
 * interference, consent tests use anonymous browser contexts where
 * CookieYes hasn't loaded yet. This lets us test SlimStat's consent
 * guard logic directly against the WP Consent API cookies.
 */
import { test, expect } from '@playwright/test';
import {
  closeDb,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';
import * as mysql from 'mysql2/promise';

let db: mysql.Pool;
let wpConsentApiActive = false;

test.beforeAll(async () => {
  db = mysql.createPool({ ...MYSQL_CONFIG, connectionLimit: 3 });

  // Check if WP Consent API plugin is active
  const [rows] = await db.execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'"
  ) as any;
  if (rows.length > 0) {
    const raw: string = rows[0].option_value;
    wpConsentApiActive = raw.includes('wp-consent-api');
  }
});

test.afterAll(async () => {
  if (db) await db.end();
  await closeDb();
});

function isSlimstatTrackingRequest(req: import('@playwright/test').Request): boolean {
  const url = req.url();
  if (url.includes('/wp-json/slimstat/v1/hit')) return true;
  if (req.method() === 'POST' && url.includes('admin-ajax.php')) {
    const body = req.postData() || '';
    if (body.includes('action=slimtrack')) return true;
  }
  return false;
}

/**
 * Create a fresh anonymous browser context with specific cookies pre-set.
 * This avoids CookieYes CMP interference by loading pages in a clean context.
 */
async function withAnonymousContext(
  page: import('@playwright/test').Page,
  url: string,
  cookies?: { name: string; value: string; domain: string; path: string }[],
): Promise<{ trackingRequests: string[]; cleanup: () => Promise<void> }> {
  const browser = page.context().browser()!;
  const ctx = await browser.newContext();
  const newPage = await ctx.newPage();

  if (cookies) {
    await ctx.addCookies(cookies);
  }

  const trackingRequests: string[] = [];
  newPage.on('request', (req) => {
    if (isSlimstatTrackingRequest(req)) {
      trackingRequests.push(req.url());
    }
  });

  await newPage.goto(url, { waitUntil: 'networkidle' });
  await newPage.waitForTimeout(3000);

  return {
    trackingRequests,
    cleanup: async () => {
      await newPage.close();
      await ctx.close();
    },
  };
}

test.describe('WP Consent API — JS Tracker Consent Integration', () => {
  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    installOptionMutator();

    await setSlimstatOption(page, 'consent_integration', 'wp_consent_api');
    await setSlimstatOption(page, 'consent_level_integration', 'statistics');
    await setSlimstatOption(page, 'gdpr_enabled', 'on');
    await setSlimstatOption(page, 'anonymous_tracking', 'off');
    await setSlimstatOption(page, 'set_tracker_cookie', 'on');
    await setSlimstatOption(page, 'ignore_capabilities', '');
  });

  test.afterEach(async () => {
    uninstallOptionMutator();
    await restoreSlimstatOptions();
  });

  test('no tracking when user rejects consent (deny cookie)', async ({ page }) => {
    const testUrl = `${BASE_URL}/?e2e_test=consent-reject-${Date.now()}`;

    // Use anonymous context to avoid CookieYes JS overriding cookies.
    // With no CMP loaded, consent_type is empty → our optin guard activates
    // → wp_has_consent checks the deny cookie → returns false → tracking blocked.
    const { trackingRequests, cleanup } = await withAnonymousContext(page, testUrl, [
      { name: 'wp_consent_statistics', value: 'deny', domain: 'localhost', path: '/' },
    ]);

    try {
      expect(trackingRequests).toHaveLength(0);
    } finally {
      await cleanup();
    }
  });

  test('tracking fires when GDPR is off (baseline)', async ({ page }) => {
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    const testUrl = `${BASE_URL}/?e2e_test=consent-baseline-${Date.now()}`;

    // Use anonymous context for clean state
    const { trackingRequests, cleanup } = await withAnonymousContext(page, testUrl);

    try {
      expect(trackingRequests.length).toBeGreaterThan(0);
    } finally {
      await cleanup();
    }
  });

  test('tracking fires when user accepts consent (allow cookie)', async ({ page }) => {
    test.skip(!wpConsentApiActive, 'WP Consent API plugin is not installed — skipping accept consent test');

    const testUrl = `${BASE_URL}/?e2e_test=consent-accept-${Date.now()}`;

    // Anonymous context with allow cookie → optin guard activates
    // → wp_has_consent checks the allow cookie → returns true → tracking fires.
    const { trackingRequests, cleanup } = await withAnonymousContext(page, testUrl, [
      { name: 'wp_consent_statistics', value: 'allow', domain: 'localhost', path: '/' },
    ]);

    try {
      expect(trackingRequests.length).toBeGreaterThan(0);
    } finally {
      await cleanup();
    }
  });

  test('no tracking before any consent decision (no cookie)', async ({ page }) => {
    const testUrl = `${BASE_URL}/?e2e_test=consent-none-${Date.now()}`;

    // Anonymous context with no cookies at all → no consent → blocked
    const { trackingRequests, cleanup } = await withAnonymousContext(page, testUrl);

    try {
      expect(trackingRequests).toHaveLength(0);
    } finally {
      await cleanup();
    }
  });

  test('WP Consent API JS function is available', async ({ page }) => {
    test.skip(!wpConsentApiActive, 'WP Consent API plugin is not installed — skipping JS function test');

    await page.goto(BASE_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    const apiState = await page.evaluate(() => {
      const w = window as any;
      return {
        hasFunction: typeof w.wp_has_consent === 'function',
        hasSlimStat: typeof w.SlimStat !== 'undefined',
      };
    });

    // WP Consent API JS must be loaded
    expect(apiState.hasFunction).toBe(true);
    // SlimStat tracker must be available
    expect(apiState.hasSlimStat).toBe(true);
  });

  test('reject consent: no PII fields in wp_slim_stats row', async ({ page }) => {
    const testUrl = `${BASE_URL}/?e2e_test=consent-reject-pii-${Date.now()}`;

    // Use anonymous context with deny cookie — tracking should be blocked or PII-free
    const { trackingRequests, cleanup } = await withAnonymousContext(page, testUrl, [
      { name: 'wp_consent_statistics', value: 'deny', domain: 'localhost', path: '/' },
    ]);

    try {
      // After rejecting consent, any tracked row (if it exists) must have no PII
      const marker = `consent-reject-pii-${Date.now()}`;
      const [rows] = await db.execute(
        `SELECT ip, city, country FROM wp_slim_stats
         WHERE resource LIKE '%e2e_test=consent-reject-pii%'
         ORDER BY id DESC LIMIT 1`
      ) as any;

      if (rows.length > 0) {
        const row = rows[0];
        // If a row was tracked despite deny, PII fields must be empty/null
        expect(
          !row.ip || row.ip === '' || row.ip === '0.0.0.0',
          `IP should be empty or zeroed when consent is rejected, got "${row.ip}"`
        ).toBe(true);
        expect(
          !row.city || row.city === '',
          `City should be empty when consent is rejected, got "${row.city}"`
        ).toBe(true);
        expect(
          !row.country || row.country === '',
          `Country should be empty when consent is rejected, got "${row.country}"`
        ).toBe(true);
      }
      // If no row exists, that's also correct — consent rejection blocked tracking entirely
    } finally {
      await cleanup();
    }
  });
});
