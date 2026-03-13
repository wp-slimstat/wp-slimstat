/**
 * E2E tests: Issue #180 — DbIpProvider wp_tempnam() fatal in non-admin context
 *
 * Three independent regression tests:
 * 1. Include guard (fix A): DbIpProvider.updateDatabase() loads file.php in frontend context
 * 2. Cron callback \Throwable catch (fix B): cron wrapper catches \Error, not just \Exception
 * 3. Admin AJAX \Throwable catch (fix B): AJAX handler catches \Error, not just \Exception
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  installCronFrontendShim,
  uninstallCronFrontendShim,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  snapshotGeoipTimestamp,
  restoreGeoipTimestamp,
  clearGeoipTimestamp,
  setSlimstatOption,
  closeDb,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

test.describe('Issue #180: DbIpProvider wp_tempnam in non-admin context', () => {
  test.beforeEach(async ({ page }) => {
    installOptionMutator();
    installCronFrontendShim();
    await snapshotSlimstatOptions();
    await snapshotGeoipTimestamp();
    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await clearGeoipTimestamp();
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
    await restoreGeoipTimestamp();
    uninstallOptionMutator();
    uninstallCronFrontendShim();
  });

  test.afterAll(async () => {
    await closeDb();
  });

  test('Test 1: DbIpProvider.updateDatabase() includes file.php in non-admin context', async ({ page }) => {
    const res = await page.request.get(`${BASE_URL}/?test_dbip_cron=provider`);
    expect(res.ok()).toBeTruthy();

    const body = await res.json();

    // Precondition: wp_tempnam must NOT be pre-loaded — if it is, the test is
    // inconclusive because file.php was already loaded by another plugin/theme.
    test.skip(body.had_wp_tempnam_before === true,
      'wp_tempnam already defined by another plugin — non-admin precondition not met, test inconclusive');

    // The include guard should ensure wp_tempnam is available and the provider runs.
    // HTTP is stubbed so updateDatabase() returns false, but no fatal error occurs.
    expect(body.success, 'Provider call should succeed (HTTP stub returns WP_Error, updateDatabase returns false)').toBe(true);
    if (body.error) {
      expect(body.error).not.toContain('wp_tempnam');
      expect(body.error).not.toContain('undefined function');
    }
  });

  test('Test 2: Cron callback catches \\Throwable from provider', async ({ page }) => {
    const res = await page.request.get(`${BASE_URL}/?test_dbip_cron=callback_throwable`);
    expect(res.ok()).toBeTruthy();

    const body = await res.json();

    // The callback should catch the \Error internally — it should NOT escape
    // to the shim's outer catch block.
    expect(body.escaped_catch, 'Cron callback did not catch \\Throwable — \\Error escaped').not.toBe(true);
    expect(body.success).toBe(true);
  });

  test('Test 3: Admin AJAX handler catches \\Throwable from provider', async ({ page }) => {
    // Step 1: Get a valid nonce via the shim endpoint
    const nonceRes = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: { action: 'test_get_geoip_nonce' },
    });
    expect(nonceRes.ok()).toBeTruthy();
    const nonceBody = await nonceRes.json();
    expect(nonceBody.success).toBe(true);
    const nonce = nonceBody.data.nonce;

    // Step 2: Call the real AJAX handler with _test_throw_error to inject \Error
    const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: {
        action: 'slimstat_update_geoip_database',
        security: nonce,
        _test_throw_error: '1',
      },
    });

    // If catch(\Throwable) is in place, the handler catches the \Error and
    // returns a structured JSON error (200). If only catch(\Exception), PHP
    // fatals → 500 non-JSON response.
    expect(res.ok(), 'Handler should return 200 JSON, not 500 fatal').toBeTruthy();

    const body = await res.json();
    expect(body.success).toBe(false);
    // The handler logs the real error and returns a generic localized message.
    // Verify it's a proper string response, not raw exception data.
    expect(typeof body.data).toBe('string');
    expect(body.data).toContain('unexpected error');
  });
});
