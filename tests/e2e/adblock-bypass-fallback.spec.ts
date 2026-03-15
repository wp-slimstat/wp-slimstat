/**
 * E2E tests: Ad-blocker bypass fallback tracking
 *
 * Validates that wp-slimstat's adblock_bypass transport method actually
 * records hits through the obfuscated /request/{hash}/ URL, and that
 * blocking all transports results in graceful failure (no crash).
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  installRewriteFlush,
  uninstallRewriteFlush,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  waitForPageviewRow,
  closeDb,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

test.describe('Ad-Blocker Bypass Fallback Tracking', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
    installRewriteFlush();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOption(page, 'ignore_wp_users', 'no');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    uninstallRewriteFlush();
    await closeDb();
  });

  /** Flush rewrite rules via the mu-plugin AJAX endpoint. */
  async function flushRewrites(page: import('@playwright/test').Page): Promise<void> {
    const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: { action: 'e2e_flush_rewrite_rules' },
    });
    if (!res.ok()) {
      throw new Error(`flush_rewrite_rules failed: ${res.status()}`);
    }
  }

  // ─── Test 1: adblock_bypass transport records a hit ──────────────

  test('adblock_bypass transport records a pageview in the database', async ({ page, browser }) => {
    // Set tracking method to adblock_bypass so the rewrite rule is registered
    await setSlimstatOption(page, 'tracking_request_method', 'adblock_bypass');

    // Flush rewrites so the /request/{hash}/ URL becomes active
    await flushRewrites(page);

    // Visit as anonymous user to trigger JS-based tracking
    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    // Track which transport URLs are hit
    const postUrls: string[] = [];
    anonPage.on('request', (req) => {
      if (req.method() === 'POST') {
        postUrls.push(req.url());
      }
    });

    const marker = `adblock-bypass-direct-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });

    // Wait for the tracking request to complete
    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat).not.toBeNull();
    expect(stat!.resource).toContain(marker);

    // Verify the POST went to a /request/ URL (adblock bypass) or the obfuscated path
    const hasAdblockRequest = postUrls.some(
      (url) => url.includes('/request/') || url.match(/\/[a-f0-9]{32}/)
    );
    expect(hasAdblockRequest).toBeTruthy();

    await ctx.close();
  });

  // ─── Test 2: Blocking REST+AJAX still records via adblock bypass ─

  test('blocking REST and AJAX forces fallback to adblock bypass', async ({ page, browser }) => {
    await setSlimstatOption(page, 'tracking_request_method', 'adblock_bypass');
    await flushRewrites(page);

    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    // Block REST and Admin-AJAX endpoints — force the tracker to use adblock bypass
    await anonPage.route('**/wp-json/slimstat/**', (route) => {
      route.abort('blockedbyclient');
    });
    await anonPage.route('**/admin-ajax.php', (route) => {
      route.abort('blockedbyclient');
    });

    const marker = `adblock-bypass-fallback-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });

    // The tracker should fall back to the adblock bypass URL and record the hit
    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat).not.toBeNull();
    expect(stat!.resource).toContain(marker);

    await ctx.close();
  });

  // ─── Test 3: All transports blocked = graceful failure ───────────

  test('blocking all transports causes no JS crash and no DB row', async ({ page, browser }) => {
    await setSlimstatOption(page, 'tracking_request_method', 'adblock_bypass');
    // Must use client-only mode — server-side PHP tracking fires regardless of JS blocks
    await setSlimstatOption(page, 'javascript_mode', 'on');
    await flushRewrites(page);

    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    const jsErrors: string[] = [];
    anonPage.on('pageerror', (error) => {
      jsErrors.push(error.message);
    });

    // Block ALL tracking endpoints
    await anonPage.route('**/wp-json/slimstat/**', (route) => {
      route.abort('blockedbyclient');
    });
    await anonPage.route('**/admin-ajax.php', (route) => {
      route.abort('blockedbyclient');
    });
    // Block the adblock bypass hash URL pattern (32-char hex path segments)
    await anonPage.route(/\/[a-f0-9]{32}(?:\/|$)/, (route) => {
      route.abort('blockedbyclient');
    });
    // Block /request/ path
    await anonPage.route('**/request/**', (route) => {
      route.abort('blockedbyclient');
    });

    const marker = `adblock-bypass-allblocked-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
    await anonPage.waitForTimeout(5_000);

    // No JS crash should occur
    expect(jsErrors).toHaveLength(0);

    // No tracking row should be created
    const stat = await waitForPageviewRow(marker, 3_000);
    expect(stat).toBeNull();

    await ctx.close();
  });
});
