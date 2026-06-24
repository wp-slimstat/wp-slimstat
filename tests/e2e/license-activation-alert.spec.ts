/**
 * E2E tests: Pro license activation alert
 *
 * When wp-slimstat-pro is installed but the license is missing/inactive/expired,
 * the free plugin shows a branded activation banner on SlimStat admin screens and
 * disables the Pro UI. Validates, against the real admin DOM:
 *   - State B (inactive): banner "turned off" with coupon + UTM links
 *   - State A (no key):   banner "need a license"
 *   - Valid license:      no banner
 *   - The banner carries the `slimstat-notice` class (so admin.css does not hide
 *     it on SlimStat pages) and does NOT appear on non-SlimStat admin pages
 *
 * Requires wp-slimstat-pro to be active; the suite skips itself otherwise.
 */
import { test, expect } from '@playwright/test';
import { unserialize as phpUnserialize } from 'php-serialize';
import {
  setSlimstatOptions,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  getPool,
  closeDb,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

const SLIMSTAT_PAGE = `${BASE_URL}/wp-admin/admin.php?page=slimview1`;
const DASHBOARD = `${BASE_URL}/wp-admin/index.php`;
const BANNER = '.slimstat-license-notice';

async function proIsActive(): Promise<boolean> {
  const [rows] = (await getPool().execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'active_plugins'",
  )) as any;
  if (!rows.length) return false;
  const active = phpUnserialize(rows[0].option_value);
  return Array.isArray(active) && active.includes('wp-slimstat-pro/wp-slimstat-pro.php');
}

/**
 * Set the stored license. `valid` maps to the `(bool)`-cast string the plugin
 * reads: '1' => valid, '' => inactive (empty string casts to false in PHP).
 */
async function setLicense(
  page: import('@playwright/test').Page,
  key: string,
  valid: boolean,
): Promise<void> {
  await setSlimstatOptions(page, {
    slimstat_pro_license_key: key,
    slimstat_pro_license_status: valid ? '1' : '',
  });
}

test.describe('Pro license activation alert', () => {
  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
    if (!(await proIsActive())) {
      test.skip(true, 'wp-slimstat-pro is not active; the activation alert cannot render.');
    }
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    await closeDb();
  });

  test('State B (inactive license): banner explains Pro is off, with coupon + UTM', async ({ page }) => {
    await setLicense(page, 'TESTKEY-E2E', false);
    await page.goto(SLIMSTAT_PAGE);

    const banner = page.locator(BANNER);
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('turned off');
    await expect(banner.locator('.slimstat-license-notice__coupon')).toHaveText('REACTIVATE');

    // Primary CTA opens pricing with the license-alert UTM campaign.
    const cta = banner.locator('.slimstat-license-notice__cta');
    await expect(cta).toHaveAttribute('href', /pricing\/\?.*utm_medium=license-alert/);
    await expect(cta).toHaveAttribute('rel', 'noopener noreferrer');

    // A direct path to enter the key in the License settings tab.
    await expect(
      banner.locator('a[href*="page=slimconfig"][href*="tab=8"]'),
    ).toBeVisible();
  });

  test('State A (no key): banner asks to add a license', async ({ page }) => {
    await setLicense(page, '', false);
    await page.goto(SLIMSTAT_PAGE);

    const banner = page.locator(BANNER);
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('need a license');
  });

  test('Valid license: no banner', async ({ page }) => {
    await setLicense(page, 'TESTKEY-E2E', true);
    await page.goto(SLIMSTAT_PAGE);
    await expect(page.locator(BANNER)).toHaveCount(0);
  });

  test('Banner does not leak onto non-SlimStat admin pages', async ({ page }) => {
    await setLicense(page, 'TESTKEY-E2E', false);
    await page.goto(DASHBOARD);
    await expect(page.locator(BANNER)).toHaveCount(0);
  });
});
