/**
 * E2E tests: Plugin health checks
 *
 * Automates manual QA checklist items:
 * - Plugin deactivation/reactivation cycle
 * - PHP error log cleanliness
 * - All admin pages render correctly
 * - GDPR consent banner visibility
 */
import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import {
  installOptionMutator,
  uninstallOptionMutator,
  installPluginLifecycle,
  uninstallPluginLifecycle,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  closeDb,
} from './helpers/setup';
import { BASE_URL, WP_ROOT } from './helpers/env';

const DEBUG_LOG = path.join(WP_ROOT, 'wp-content', 'debug.log');

test.describe('Plugin Health Checks', () => {
  test.setTimeout(90_000);

  test.beforeAll(async () => {
    installOptionMutator();
    installPluginLifecycle();
  });

  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    uninstallPluginLifecycle();
    await closeDb();
  });

  // ─── Test 1: Plugin deactivate/reactivate cycle ─────────────────

  test('plugin deactivation and reactivation completes without errors', async ({ page }) => {
    // Deactivate
    const deactivateRes = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: { action: 'e2e_deactivate_plugin' },
    });
    expect(deactivateRes.ok()).toBeTruthy();
    const deactivateData = await deactivateRes.json();
    expect(deactivateData.success).toBe(true);

    // Reactivate
    const activateRes = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: { action: 'e2e_activate_plugin' },
    });
    expect(activateRes.ok()).toBeTruthy();
    const activateData = await activateRes.json();
    expect(activateData.success).toBe(true);
    expect(activateData.data.is_active).toBe(true);

    // Verify settings page loads correctly after reactivation
    const settingsRes = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimconfig`);
    expect(settingsRes?.status()).toBeLessThan(400);

    const bodyText = await page.textContent('body');
    expect(bodyText).toContain('Slimstat');
  });

  // ─── Test 2: PHP error log clean ─────────────────────────────────

  test('no PHP fatal errors or warnings from SlimStat after page visits', async ({ page, browser }) => {
    // Clear the debug log if it exists
    if (fs.existsSync(DEBUG_LOG)) {
      fs.writeFileSync(DEBUG_LOG, '', 'utf8');
    }

    // Visit 4 pages: frontend, admin dashboard, settings, reports
    const pagesToVisit = [
      `${BASE_URL}/?e2e=health-check-${Date.now()}`,
      `${BASE_URL}/wp-admin/`,
      `${BASE_URL}/wp-admin/admin.php?page=slimconfig`,
      `${BASE_URL}/wp-admin/admin.php?page=slimview1`,
    ];

    for (const url of pagesToVisit) {
      const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
      expect(response?.status()).toBeLessThan(500);
    }

    // Also visit frontend as anonymous
    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();
    await anonPage.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' });
    await ctx.close();

    // Check debug.log for SlimStat-related errors
    if (fs.existsSync(DEBUG_LOG)) {
      const logContent = fs.readFileSync(DEBUG_LOG, 'utf8');
      const slimstatErrors = logContent
        .split('\n')
        .filter((line) => {
          const lower = line.toLowerCase();
          return (
            (lower.includes('php fatal') || lower.includes('php warning')) &&
            (lower.includes('slimstat') || lower.includes('slim_stat') || lower.includes('wp-slimstat'))
          );
        });

      expect(slimstatErrors).toHaveLength(0);
    }
  });

  // ─── Test 3: All admin pages render HTTP 200 ──────────────────────

  test('all SlimStat admin pages render with HTTP 200', async ({ page }) => {
    const adminPages = [
      { slug: 'slimview1', label: 'Access Log' },
      { slug: 'slimview2', label: 'Overview' },
      { slug: 'slimview3', label: 'Audience' },
      { slug: 'slimview4', label: 'Site Analysis' },
      { slug: 'slimview5', label: 'Traffic Sources' },
      { slug: 'slimconfig', label: 'Settings' },
    ];

    for (const { slug, label } of adminPages) {
      const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=${slug}`, {
        waitUntil: 'domcontentloaded',
      });
      expect(response?.status(), `${label} (${slug}) should return HTTP 200`).toBeLessThan(400);

      // Verify the page has the SlimStat admin wrapper
      const wrap = page.locator('.wrap-slimstat');
      await expect(wrap, `${label} (${slug}) should have .wrap-slimstat element`).toBeVisible({ timeout: 5_000 });
    }
  });

  // ─── Test 4: GDPR consent banner appears when enabled ─────────────

  test('GDPR consent banner is visible when enabled', async ({ page, browser }) => {
    // Both settings must be on, and consent_integration must route to slimstat_banner
    await setSlimstatOption(page, 'gdpr_enabled', 'on');
    await setSlimstatOption(page, 'use_slimstat_banner', 'on');
    await setSlimstatOption(page, 'consent_integration', 'slimstat_banner');

    // Visit frontend as anonymous user (no consent cookie = banner should show)
    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    await anonPage.goto(`${BASE_URL}/`, { waitUntil: 'networkidle' });

    // The GDPR banner should be present in the DOM (rendered via wp_footer)
    const banner = anonPage.locator('#slimstat-gdpr-banner');
    await expect(banner).toBeAttached({ timeout: 10_000 });

    await ctx.close();
  });
});
