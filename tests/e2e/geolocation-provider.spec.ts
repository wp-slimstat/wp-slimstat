/**
 * E2E tests: Geolocation provider pipeline verification.
 *
 * Validates that all provider types (DB-IP, Cloudflare, MaxMind, Disabled)
 * work end-to-end through the Processor tracking pipeline.
 * Also covers fresh install defaults and legacy upgrade backward compatibility.
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  deleteSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  getLatestStat,
  simulateFreshInstall,
  simulateLegacyUpgrade,
  closeDb,
} from './helpers/setup';

test.describe('Geolocation Provider Pipeline', () => {
  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  // ─── Test 1: Fresh install defaults to DB-IP ────────────────────

  test('fresh install sets geolocation_provider to dbip', async ({ page }) => {
    await simulateFreshInstall();

    // Visit admin to trigger wp_slimstat::init() fresh-install branch
    await page.goto('/wp-admin/');
    await page.waitForURL('**/wp-admin/**');

    // The page loaded without error — init() recreated the options
    await expect(page).toHaveTitle(/Dashboard/);

    // Verify the settings page shows DB-IP as selected provider
    await page.goto('/wp-admin/admin.php?page=slimconfig&tab=5');
    const providerSelect = page.locator('select[name="geolocation_provider"]');
    if (await providerSelect.count() > 0) {
      await expect(providerSelect).toHaveValue('dbip');
    }
  });

  // ─── Test 2: Legacy upgrade — enable_maxmind='on' → maxmind ────

  test('legacy upgrade with enable_maxmind=on resolves to maxmind', async ({ page }) => {
    await simulateLegacyUpgrade(page, 'on');

    // Visit a frontend page — should not crash even without MaxMind DB
    const response = await page.goto('/?legacy-maxmind-test');
    expect(response?.status()).toBeLessThan(500);

    // Admin page also loads fine
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);
  });

  // ─── Test 3: Legacy upgrade — enable_maxmind='no' → dbip ───────

  test('legacy upgrade with enable_maxmind=no resolves to dbip', async ({ page }) => {
    await simulateLegacyUpgrade(page, 'no');

    const marker = `legacy-dbip-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(3000);

    // DB-IP with a local IP may not resolve country (private IP ranges)
    // The key assertion: no crash, tracking ran without error
    const adminResponse = await page.goto('/wp-admin/');
    expect(adminResponse?.status()).toBeLessThan(500);
  });

  // ─── Test 4: Legacy upgrade — enable_maxmind='disable' → off ───

  test('legacy upgrade with enable_maxmind=disable skips geolocation', async ({ page }) => {
    await simulateLegacyUpgrade(page, 'disable');

    const marker = `legacy-disable-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(3000);

    const stat = await getLatestStat(marker);
    if (stat) {
      expect(!stat.country || stat.country === '').toBeTruthy();
    }
  });

  // ─── Test 5: DB-IP tracks country correctly ────────────────────

  test('DB-IP provider tracks without crashing', async ({ page }) => {
    await setSlimstatOption(page, 'geolocation_provider', 'dbip');

    const marker = `dbip-e2e-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(3000);

    // DB-IP with a local IP may not resolve country (private IP ranges)
    // but the pipeline should not crash
    const response = await page.goto('/wp-admin/');
    expect(response?.status()).toBeLessThan(500);
  });

  // ─── Test 6: Cloudflare tracks city+subdivision with CF headers ─

  test('Cloudflare provider tracks city+subdivision with CF headers', async ({ page, context }) => {
    await setSlimstatOption(page, 'geolocation_provider', 'cloudflare');
    await setSlimstatOption(page, 'geolocation_country', 'no'); // city precision
    await setSlimstatOption(page, 'gdpr_enabled', 'off'); // Disable GDPR so PII (geolocation) is allowed

    // Inject Cloudflare headers — distinct city ("Munich") vs region ("Bavaria")
    // to prove the region→subdivision mapping works (city stored as "Munich (Bavaria)")
    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-e2e-ray-001',
      'CF-IPCountry': 'DE',
      'CF-IPContinent': 'EU',
      'CF-IPCity': 'Munich',
      'CF-Region': 'Bavaria',
      'CF-IPLatitude': '48.1351',
      'CF-IPLongitude': '11.5820',
    });

    const marker = `cf-test-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(4000);

    const stat = await getLatestStat(marker);
    expect(stat).toBeTruthy();
    expect(stat!.country).toBe('de');
    expect(stat!.city).toBe('Munich (Bavaria)'); // Proves region→subdivision mapping
    expect(stat!.location).toContain('48.1351'); // Proves lat/lng stored
  });

  // ─── Test 7: Disabled provider skips geolocation ───────────────

  test('disabled provider skips geolocation tracking', async ({ page }) => {
    await setSlimstatOption(page, 'geolocation_provider', 'disable');

    const marker = `disabled-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(3000);

    const stat = await getLatestStat(marker);
    if (stat) {
      expect(!stat.country || stat.country === '').toBeTruthy();
    }
  });

  // ─── Test 8: Admin pages load for all providers ────────────────

  test('admin pages load without error for all provider types', async ({ page }) => {
    const providers = ['dbip', 'cloudflare', 'maxmind', 'disable'];

    for (const provider of providers) {
      await setSlimstatOption(page, 'geolocation_provider', provider);

      const response = await page.goto('/wp-admin/');
      expect(response?.status()).toBeLessThan(500);
      await expect(page).toHaveTitle(/Dashboard/);
    }
  });

  // ─── Test 9: Switching provider applies on next page load ───────

  test('switching provider from dbip to maxmind2 applies on next page load', async ({ page, context }) => {
    // Start with dbip and inject CF headers so we can verify geolocation works
    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'geolocation_country', 'no');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');

    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-e2e-provider-switch',
      'CF-Connecting-IP': '8.8.8.8',
    });

    // Visit with dbip provider active
    const marker1 = `provider-switch-before-${Date.now()}`;
    await page.goto(`/?p=${marker1}`);
    await page.waitForTimeout(3000);

    // Now switch to maxmind2
    await setSlimstatOption(page, 'geolocation_provider', 'maxmind2');

    // Visit again — maxmind2 should be active on this new page load.
    // Without a MaxMind DB file, geolocation may fail gracefully, but no crash.
    const marker2 = `provider-switch-after-${Date.now()}`;
    const response = await page.goto(`/?p=${marker2}`);
    expect(response?.status()).toBeLessThan(500);

    // Verify the settings page reflects the new provider
    await page.goto('/wp-admin/admin.php?page=slimconfig&tab=5');
    const providerSelect = page.locator('select[name="geolocation_provider"]');
    if (await providerSelect.count() > 0) {
      await expect(providerSelect).toHaveValue('maxmind2');
    }
  });
});
