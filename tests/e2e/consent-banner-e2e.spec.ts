/**
 * AC-CNS-001/002 / AC-CON-001/002/004: WP Consent API accept/reject flows
 *
 * If WP Consent API is active, tests:
 * - Accept flow: tracking should work normally after consent granted
 * - Reject flow: no PII/tracking data written to wp_slim_stats
 *
 * Uses test.skip() when WP Consent API is not installed.
 */
import { test, expect } from '@playwright/test';
import {
  closeDb,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  installMuPluginByName,
  uninstallMuPluginByName,
  installCronFrontendShim,
  cleanupFixtureFiles,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';
import * as mysql from 'mysql2/promise';
import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

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

async function withAnonymousContext(
  page: import('@playwright/test').Page,
  url: string,
  cookies?: { name: string; value: string; domain: string; path: string }[],
): Promise<{ page: import('@playwright/test').Page; trackingRequests: string[]; cleanup: () => Promise<void> }> {
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

  await newPage.goto(url, { waitUntil: 'domcontentloaded' });
  await newPage.waitForTimeout(3000);

  return {
    page: newPage,
    trackingRequests,
    cleanup: async () => {
      await newPage.close();
      await ctx.close();
    },
  };
}

test.describe('AC-CON-001/002: WP Consent API Accept/Reject Flows', () => {
  test.setTimeout(60_000);

  test.beforeEach(async ({ page }) => {
    test.skip(!wpConsentApiActive, 'WP Consent API plugin is not installed — skipping consent banner tests');

    await snapshotSlimstatOptions();
    installOptionMutator();

    // Configure SlimStat to use WP Consent API
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

  test('accept flow: tracking fires after consent granted', async ({ page }) => {
    const marker = `e2e-consent-accept-${Date.now()}`;
    const testUrl = `${BASE_URL}/?e2e_marker=${marker}`;

    // Anonymous context with allow cookie set
    const { trackingRequests, cleanup } = await withAnonymousContext(page, testUrl, [
      { name: 'wp_consent_statistics', value: 'allow', domain: 'localhost', path: '/' },
    ]);

    try {
      expect(trackingRequests.length).toBeGreaterThan(0);
    } finally {
      await cleanup();
    }
  });

  test('reject flow: no tracking when consent denied', async ({ page }) => {
    const marker = `e2e-consent-reject-${Date.now()}`;
    const testUrl = `${BASE_URL}/?e2e_marker=${marker}`;

    // Anonymous context with deny cookie set
    const { trackingRequests, cleanup } = await withAnonymousContext(page, testUrl, [
      { name: 'wp_consent_statistics', value: 'deny', domain: 'localhost', path: '/' },
    ]);

    try {
      expect(trackingRequests).toHaveLength(0);

      // Also verify no row was inserted in DB for this marker
      await new Promise((r) => setTimeout(r, 2000));
      const [rows] = await db.execute(
        'SELECT id FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1',
        [`%${marker}%`]
      ) as any;
      expect(rows.length).toBe(0);
    } finally {
      await cleanup();
    }
  });

  test('reject flow: no PII in wp_slim_stats after rejection', async ({ page }) => {
    const marker = `e2e-consent-pii-${Date.now()}`;
    const testUrl = `${BASE_URL}/?e2e_marker=${marker}`;

    const { cleanup } = await withAnonymousContext(page, testUrl, [
      { name: 'wp_consent_statistics', value: 'deny', domain: 'localhost', path: '/' },
    ]);

    try {
      await new Promise((r) => setTimeout(r, 2000));

      // Verify no tracking data was recorded for this marker
      const [rows] = await db.execute(
        'SELECT ip, browser, username FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1',
        [`%${marker}%`]
      ) as any;

      // No rows should exist at all for a rejected consent visit
      expect(rows.length).toBe(0);
    } finally {
      await cleanup();
    }
  });

  test('undecided: no tracking before any consent decision', async ({ page }) => {
    const marker = `e2e-consent-undecided-${Date.now()}`;
    const testUrl = `${BASE_URL}/?e2e_marker=${marker}`;

    // No cookies at all — consent is undecided
    const { trackingRequests, cleanup } = await withAnonymousContext(page, testUrl);

    try {
      expect(trackingRequests).toHaveLength(0);
    } finally {
      await cleanup();
    }
  });

  test('consent denial persists across page navigations', async ({ page }) => {
    const browser = page.context().browser()!;
    const ctx = await browser.newContext();
    await ctx.addCookies([
      { name: 'wp_consent_statistics', value: 'deny', domain: 'localhost', path: '/' },
    ]);

    const newPage = await ctx.newPage();
    const trackingRequests: string[] = [];
    newPage.on('request', (req) => {
      if (isSlimstatTrackingRequest(req)) trackingRequests.push(req.url());
    });

    // Navigate across multiple pages
    for (const slug of ['', 'sample-page', '?p=1']) {
      await newPage.goto(`${BASE_URL}/${slug}`, { waitUntil: 'domcontentloaded' });
      await newPage.waitForTimeout(2000);
    }

    expect(trackingRequests).toHaveLength(0);

    await newPage.close();
    await ctx.close();
  });
});
