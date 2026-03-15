/**
 * E2E tests: Server-side tracking with JavaScript disabled
 *
 * Validates that wp-slimstat's server-side tracking (javascript_mode=off)
 * works when the browser has JavaScript disabled, and that client-only
 * mode (javascript_mode=on) correctly produces no tracking when JS is off.
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  waitForPageviewRow,
  closeDb,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

test.describe('Server-Side Tracking with JS Disabled', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    // Ensure admin visits are tracked for these tests
    await setSlimstatOption(page, 'ignore_wp_users', 'no');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  // ─── Test 1: Server-side mode + JS disabled = hit tracked ──────

  test('server-side tracking records hit when browser JS is disabled', async ({ page, browser }) => {
    // Set server-side tracking mode (the default)
    await setSlimstatOption(page, 'javascript_mode', 'off');

    // Create a browser context with JavaScript disabled
    const noJsContext = await browser.newContext({ javaScriptEnabled: false });
    const noJsPage = await noJsContext.newPage();

    const marker = `js-disabled-servermode-${Date.now()}`;
    await noJsPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });

    // Server-side PHP hook (Tracker::slimtrack at wp action) should fire
    const stat = await waitForPageviewRow(marker, 10_000);
    expect(stat).not.toBeNull();
    expect(stat!.resource).toContain(marker);

    await noJsContext.close();
  });

  // ─── Test 2: Client-only mode + JS disabled = no hit ────────────

  test('client-only mode does NOT track when browser JS is disabled', async ({ page, browser }) => {
    // Set client-side tracking mode
    await setSlimstatOption(page, 'javascript_mode', 'on');

    const noJsContext = await browser.newContext({ javaScriptEnabled: false });
    const noJsPage = await noJsContext.newPage();

    const marker = `js-disabled-clientmode-${Date.now()}`;
    await noJsPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });

    // Wait a bit — with javascript_mode=on, server-side hook is skipped,
    // and JS can't fire, so no tracking should occur
    await noJsPage.waitForTimeout(5_000);

    const stat = await waitForPageviewRow(marker, 3_000);
    expect(stat).toBeNull();

    await noJsContext.close();
  });

  // ─── Test 3: Server-side mode + JS enabled (control) ────────────

  test('server-side tracking records hit with JS enabled (control)', async ({ page, browser }) => {
    await setSlimstatOption(page, 'javascript_mode', 'off');

    // Use a regular (JS-enabled) anonymous context
    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    const marker = `js-enabled-servermode-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });

    const stat = await waitForPageviewRow(marker, 10_000);
    expect(stat).not.toBeNull();
    expect(stat!.resource).toContain(marker);

    await ctx.close();
  });
});
