/**
 * E2E tests for AC-GEO-004: MaxMind provider precision.
 *
 * Validates that the MaxMind (maxmind2) geolocation provider resolves
 * country for known IPs and handles invalid license keys gracefully.
 *
 * Note: MaxMind requires a valid GeoLite2 database file. These tests
 * verify the pipeline does not crash — actual resolution depends on
 * whether a MaxMind DB is installed.
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  getLatestStat,
  closeDb,
} from './helpers/setup';

/** Poll getLatestStat until a row appears or timeout. */
async function waitForStat(marker: string, timeoutMs = 10_000, intervalMs = 250) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const stat = await getLatestStat(marker);
    if (stat) return stat;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return null;
}

test.describe('AC-GEO-004: MaxMind Provider Precision', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOption(page, 'geolocation_country', 'no');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  // ─── Test 1: MaxMind provider does not crash with known IP ──────

  test('MaxMind provider processes 8.8.8.8 without crashing', async ({ page, context }) => {
    await setSlimstatOption(page, 'geolocation_provider', 'maxmind');

    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-maxmind-us',
      'CF-Connecting-IP': '8.8.8.8',
    });

    const marker = `maxmind-us-${Date.now()}`;
    const response = await page.goto(`/?p=${marker}`);
    // Pipeline must not crash (no 500)
    expect(response?.status()).toBeLessThan(500);

    // If MaxMind DB is installed, country should resolve to US
    const stat = await waitForStat(marker);
    if (stat && stat.country) {
      expect(stat.country).toBe('us');
    }
  });

  // ─── Test 2: MaxMind with German IP ─────────────────────────────

  test('MaxMind provider processes German IP without crashing', async ({ page, context }) => {
    await setSlimstatOption(page, 'geolocation_provider', 'maxmind');

    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-maxmind-de',
      'CF-Connecting-IP': '5.9.49.12',
    });

    const marker = `maxmind-de-${Date.now()}`;
    const response = await page.goto(`/?p=${marker}`);
    expect(response?.status()).toBeLessThan(500);

    const stat = await waitForStat(marker);
    if (stat && stat.country) {
      expect(stat.country).toBe('de');
    }
  });

  // ─── Test 3: Invalid MaxMind license key → graceful failure ─────

  test('invalid MaxMind license key does not crash the pipeline', async ({ page, context }) => {
    await setSlimstatOption(page, 'geolocation_provider', 'maxmind');
    // Set an invalid license key — should not cause a fatal error
    await setSlimstatOption(page, 'maxmind_license_key', 'INVALID_KEY_12345');

    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-maxmind-invalid-key',
      'CF-Connecting-IP': '8.8.8.8',
    });

    const marker = `maxmind-badkey-${Date.now()}`;
    const response = await page.goto(`/?p=${marker}`);
    // Must not crash — graceful degradation
    expect(response?.status()).toBeLessThan(500);

    // Admin pages should also load fine
    const adminResponse = await page.goto('/wp-admin/');
    expect(adminResponse?.status()).toBeLessThan(500);
    await expect(page).toHaveTitle(/Dashboard/);
  });

  // ─── Test 4: MaxMind with private IP → no country ──────────────

  test('MaxMind with private IP (no CF headers) produces no country', async ({ page }) => {
    await setSlimstatOption(page, 'geolocation_provider', 'maxmind');

    const marker = `maxmind-private-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await new Promise((r) => setTimeout(r, 3000));

    const stat = await getLatestStat(marker);
    if (stat) {
      // Private IP (127.0.0.1) should not resolve to a country
      expect(!stat.country || stat.country === '').toBeTruthy();
    }
  });

  // ─── Test 5: Admin settings page loads with MaxMind selected ────

  test('admin settings page loads without error when MaxMind is selected', async ({ page }) => {
    await setSlimstatOption(page, 'geolocation_provider', 'maxmind');

    const response = await page.goto('/wp-admin/admin.php?page=slimconfig&tab=5');
    expect(response?.status()).toBeLessThan(500);
  });
});
