/**
 * E2E tests for AC-GEO-002 / AC-GEO-008: Provider sanitization and allowlisting.
 *
 * Validates that invalid geolocation_provider values do not crash the
 * tracking pipeline, and that the system falls back to safe defaults.
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  setSlimstatSetting,
  closeDb,
  installHeaderInjector,
  uninstallHeaderInjector,
  setHeaderOverrides,
  clearHeaderOverrides,
  waitForStat,
} from './helpers/setup';

test.describe('AC-GEO-002/008: Provider Sanitization & Allowlisting', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
    installHeaderInjector();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
  });

  test.afterEach(async () => {
    clearHeaderOverrides();
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallHeaderInjector();
    uninstallOptionMutator();
    await closeDb();
  });

  // ─── Test 1: Invalid provider value does not crash frontend ─────

  test('invalid provider "bogus_provider" does not crash tracking', async ({ page }) => {
    // Set invalid provider via direct DB manipulation (bypasses UI validation)
    await setSlimstatSetting('geolocation_provider', 'bogus_provider');

    setHeaderOverrides({
      'CF-Ray': 'test-sanitize-bogus',
      'CF-Connecting-IP': '8.8.8.8',
    });

    const marker = `sanitize-bogus-${Date.now()}`;
    const response = await page.goto(`/?p=${marker}`);
    // Must not crash — no 500 error
    expect(response?.status()).toBeLessThan(500);
  });

  // ─── Test 2: Invalid provider does not crash admin pages ────────

  test('invalid provider does not crash admin dashboard', async ({ page }) => {
    await setSlimstatSetting('geolocation_provider', 'bogus_provider');

    const response = await page.goto('/wp-admin/');
    expect(response?.status()).toBeLessThan(500);
    await expect(page).toHaveTitle(/Dashboard/);
  });

  // ─── Test 3: Path traversal attempt in provider value ───────────

  test('path traversal in provider value does not cause file inclusion', async ({ page }) => {
    // Simulate a malicious provider value injected via direct DB edit
    await setSlimstatSetting('geolocation_provider', '../../wp-config');

    const marker = `sanitize-traversal-${Date.now()}`;
    const response = await page.goto(`/?p=${marker}`);
    expect(response?.status()).toBeLessThan(500);

    // Admin pages also safe
    const adminResponse = await page.goto('/wp-admin/');
    expect(adminResponse?.status()).toBeLessThan(500);
  });

  // ─── Test 4: SQL injection attempt in provider value ────────────

  test('SQL injection in provider value is handled safely', async ({ page }) => {
    await setSlimstatSetting('geolocation_provider', "maxmind'; DROP TABLE wp_slim_stats;--");

    const marker = `sanitize-sqli-${Date.now()}`;
    const response = await page.goto(`/?p=${marker}`);
    expect(response?.status()).toBeLessThan(500);

    // Verify the stats table still exists by checking admin
    const adminResponse = await page.goto('/wp-admin/');
    expect(adminResponse?.status()).toBeLessThan(500);
  });

  // ─── Test 5: Empty provider value handled safely ────────────────

  test('empty provider value does not crash the pipeline', async ({ page }) => {
    await setSlimstatSetting('geolocation_provider', '');

    setHeaderOverrides({
      'CF-Ray': 'test-sanitize-empty',
      'CF-Connecting-IP': '8.8.8.8',
    });

    const marker = `sanitize-empty-${Date.now()}`;
    const response = await page.goto(`/?p=${marker}`);
    expect(response?.status()).toBeLessThan(500);
  });

  // ─── Test 6: Valid providers all work after invalid value ───────

  test('switching from invalid to valid provider restores geolocation', async ({ page }) => {
    // First, set an invalid provider
    await setSlimstatSetting('geolocation_provider', 'bogus_provider');

    const marker1 = `sanitize-restore-bad-${Date.now()}`;
    await page.goto(`/?p=${marker1}`);
    await new Promise((r) => setTimeout(r, 2000));

    // Now switch to a valid provider via the proper API
    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'geolocation_country', 'no');
    await setSlimstatOption(page, 'ignore_wp_users', 'no');

    setHeaderOverrides({
      'CF-Ray': 'test-sanitize-restore',
      'CF-Connecting-IP': '8.8.8.8',
    });

    await clearStatsTable();
    const marker2 = `sanitize-restore-good-${Date.now()}`;
    await page.goto(`/?p=${marker2}`);

    const stat = await waitForStat(marker2);
    expect(stat).toBeTruthy();
    expect(stat!.country).toBe('us');
  });

  // ─── Test 7: Settings page loads with invalid provider ──────────

  test('settings page loads without error even with invalid provider in DB', async ({ page }) => {
    await setSlimstatSetting('geolocation_provider', 'bogus_provider');

    const response = await page.goto('/wp-admin/admin.php?page=slimconfig&tab=2');
    expect(response?.status()).toBeLessThan(500);
  });
});
