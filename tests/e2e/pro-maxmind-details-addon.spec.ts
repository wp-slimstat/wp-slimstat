/**
 * E2E tests: Pro MaxMindDetailsAddon — Issue #182 fix verification.
 *
 * Validates that the Advanced Whois AJAX endpoint works correctly after
 * migrating from deleted SlimStat\Services\GeoIP to new Geolocation API.
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  installNonceHelper,
  uninstallNonceHelper,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  closeDb,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

test.describe('Pro MaxMindDetailsAddon — Advanced Whois (#182)', () => {
  test.beforeAll(async () => {
    installOptionMutator();
    installNonceHelper();
  });

  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    uninstallNonceHelper();
    await closeDb();
  });

  /**
   * Get a nonce for the whois AJAX endpoint via the nonce-helper mu-plugin.
   */
  async function getWhoisNonce(page: import('@playwright/test').Page): Promise<string> {
    const response = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: { action: 'test_create_nonce', nonce_action: 'slimstat_ip2location_iframe' },
    });
    expect(response.ok(), 'Nonce helper mu-plugin should respond OK').toBeTruthy();
    const json = await response.json();
    return json.data.nonce;
  }

  /**
   * Call the whois AJAX endpoint and return status + body.
   */
  async function callWhoisEndpoint(
    page: import('@playwright/test').Page,
    ip: string,
    nonce: string
  ): Promise<{ status: number; body: string }> {
    const url = `${BASE_URL}/wp-admin/admin-ajax.php?action=slimstat_ip2location_iframe_content&_wpnonce=${nonce}&ip=${ip}`;
    const response = await page.request.get(url);
    return { status: response.status(), body: await response.text() };
  }

  // ─── Test 1: Admin pages load without fatal (both plugins active) ──

  test('admin pages load without PHP fatal errors', async ({ page }) => {
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    const adminPages = [
      '/wp-admin/',
      '/wp-admin/admin.php?page=slimview1',
      '/wp-admin/admin.php?page=slimconfig',
    ];

    for (const adminPage of adminPages) {
      const response = await page.goto(adminPage);
      expect(response?.status(), `${adminPage} should not 500`).toBeLessThan(500);

      const body = await page.content();
      expect(body).not.toContain('Fatal error');
      expect(body).not.toContain("Class 'SlimStat\\Services\\GeoIP' not found");
    }
  });

  // ─── Test 2: Whois AJAX works with DB-backed provider ──────────────

  test('whois AJAX responds without fatal error', async ({ page }) => {
    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    // Auth context
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const nonce = await getWhoisNonce(page);
    const result = await callWhoisEndpoint(page, '8.8.8.8', nonce);

    expect(result.status).toBeLessThan(500);
    expect(result.body).not.toContain('Fatal error');
    expect(result.body).not.toContain("Class 'SlimStat\\Services\\GeoIP' not found");

    // Should show either geo data HTML or actionable DB-missing message
    const hasGeoData = result.body.includes('IP Geolocation Information');
    const hasDbMissing = result.body.includes('geolocation database is not available');
    expect(hasGeoData || hasDbMissing, 'Should show geo data or DB-missing message').toBeTruthy();
  });

  // ─── Test 3: Cloudflare provider → explicit unsupported message ────

  test('cloudflare provider blocks whois with explicit message', async ({ page }) => {
    await setSlimstatOption(page, 'geolocation_provider', 'cloudflare');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    // Auth context
    await page.goto('/wp-admin/');

    // Direct AJAX call should return cloudflare-specific message
    const nonce = await getWhoisNonce(page);
    const result = await callWhoisEndpoint(page, '8.8.8.8', nonce);

    expect(result.body).toContain('Cloudflare');
    expect(result.body).toContain('not available');
    expect(result.body).not.toContain('Fatal error');

    // Whois URL should NOT be injected into the reports page
    await page.goto('/wp-admin/admin.php?page=slimview1');
    const pageContent = await page.content();
    expect(pageContent).not.toContain('slimstat_ip2location_iframe_content');
  });

  // ─── Test 4: Geolocation disabled → actionable settings message ────

  test('disabled geolocation shows settings message on AJAX call', async ({ page }) => {
    await setSlimstatOption(page, 'geolocation_provider', 'disable');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    await page.goto('/wp-admin/');

    const nonce = await getWhoisNonce(page);
    const result = await callWhoisEndpoint(page, '8.8.8.8', nonce);

    expect(result.body).toContain('GeoIP collection is not enabled');
    expect(result.body).toContain('setting page');
    expect(result.body).not.toContain('Fatal error');

    // Whois URL should NOT be injected
    await page.goto('/wp-admin/admin.php?page=slimview1');
    const pageContent = await page.content();
    expect(pageContent).not.toContain('slimstat_ip2location_iframe_content');
  });

  // ─── Test 5: Admin pages stable across all provider switches ───────

  test('admin pages stable when switching providers with addon enabled', async ({ page }) => {
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    for (const provider of ['dbip', 'maxmind', 'cloudflare', 'disable']) {
      await setSlimstatOption(page, 'geolocation_provider', provider);

      const dashResponse = await page.goto('/wp-admin/');
      expect(dashResponse?.status(), `Dashboard with ${provider}`).toBeLessThan(500);

      const reportsResponse = await page.goto('/wp-admin/admin.php?page=slimview1');
      expect(reportsResponse?.status(), `Reports with ${provider}`).toBeLessThan(500);

      const body = await page.content();
      expect(body).not.toContain('Fatal error');
    }
  });

  // ─── Test 6: MaxMind Details: city and coordinates populated for known IP ──

  test('MaxMind Details: city and coordinates populated for known IP', async ({ page }) => {
    // Skip if Pro plugin is not active
    await page.goto('/wp-admin/plugins.php');
    const pluginsBody = await page.content();
    const proActive = pluginsBody.includes('wp-slimstat-pro') && pluginsBody.includes('Deactivate');
    test.skip(!proActive, 'Pro plugin is not active — skipping MaxMind Details test');

    await setSlimstatOption(page, 'geolocation_provider', 'maxmind');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    // Auth context
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const nonce = await getWhoisNonce(page);
    const result = await callWhoisEndpoint(page, '8.8.8.8', nonce);

    expect(result.status).toBeLessThan(500);
    expect(result.body).not.toContain('Fatal error');

    // If the MaxMind database is available, verify city/lat/lon are populated
    const hasGeoData = result.body.includes('IP Geolocation Information');
    if (hasGeoData) {
      // The response HTML should contain city, latitude, and longitude data
      // for Google's public DNS IP (8.8.8.8) — typically resolves to a US location
      const hasCity = /city/i.test(result.body) && !result.body.includes('N/A');
      const hasCoords = /latitude|longitude|lat|lon/i.test(result.body);

      expect(
        hasCity || hasCoords,
        'Geo data for 8.8.8.8 should include city or coordinates'
      ).toBe(true);
    }
    // If DB is missing, the test still passes — it verifies no crash
  });

  // ─── Test 7: Addon disabled → no whois URL injected ────────────────

  test('addon disabled does not inject whois URL', async ({ page }) => {
    await setSlimstatOption(page, 'addon_maxmind_enable', 'off');
    await setSlimstatOption(page, 'geolocation_provider', 'dbip');

    await page.goto('/wp-admin/admin.php?page=slimview1');
    await page.waitForLoadState('domcontentloaded');

    const pageContent = await page.content();
    expect(pageContent).not.toContain('slimstat_ip2location_iframe_content');
  });
});
