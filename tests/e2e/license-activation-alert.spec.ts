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
const LICENSE_TAB = `${BASE_URL}/wp-admin/admin.php?page=slimconfig&tab=8`;
const DASHBOARD = `${BASE_URL}/wp-admin/index.php`;
const BANNER = '.slimstat-license-alert';
const OPTIONS_TABLE = `${process.env.WP_DB_PREFIX || 'wp_'}options`;

async function proIsActive(): Promise<boolean> {
  const [rows] = (await getPool().execute(
    `SELECT option_value FROM ${OPTIONS_TABLE} WHERE option_name = 'active_plugins'`,
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
    // Exposed as an accessible, labelled region with a heading.
    await expect(page.getByRole('region', { name: /SlimStat Pro license/i })).toBeVisible();
    await expect(banner.locator('h2.slimstat-license-alert__title')).toContainText('turned off');
    // Must carry the `slimstat-notice` class, or admin.css hides it on SlimStat pages.
    await expect(banner).toHaveClass(/\bslimstat-notice\b/);
    await expect(banner.locator('.slimstat-license-alert__coupon')).toHaveText('REACTIVATE');

    // Primary CTA opens pricing in a new tab with the license-alert UTM campaign.
    const cta = banner.locator('.slimstat-license-alert__cta');
    await expect(cta).toHaveAttribute('href', /pricing\/\?.*utm_medium=license-alert.*utm_content=state-b/);
    await expect(cta).toHaveAttribute('target', '_blank');
    await expect(cta).toHaveAttribute('rel', 'noopener noreferrer');

    // Retrieve-key (My Account) link and the direct License-tab link.
    await expect(banner.locator('a[href*="my-account"]')).toHaveAttribute('href', /utm_medium=license-alert/);
    await expect(banner.locator('a[href*="page=slimconfig"][href*="tab=8"]')).toBeVisible();

    // Support mailto (antispambot entity-encoding decodes to plain text in the DOM).
    await expect(banner.locator('a[href^="mailto:"]')).toHaveAttribute('href', 'mailto:support@wp-slimstat.com');

    // The primary action is keyboard-reachable.
    await cta.focus();
    await expect(cta).toBeFocused();
  });

  test('State A (no key): banner asks to add a license, with the same affordances', async ({ page }) => {
    await setLicense(page, '', false);
    await page.goto(SLIMSTAT_PAGE);

    const banner = page.locator(BANNER);
    await expect(banner).toBeVisible();
    await expect(banner.locator('h2.slimstat-license-alert__title')).toContainText('need a license');
    // Same CTA + License-tab affordances as State B, tagged with the state-a UTM.
    await expect(banner.locator('.slimstat-license-alert__cta'))
      .toHaveAttribute('href', /pricing\/\?.*utm_content=state-a/);
    await expect(banner.locator('a[href*="page=slimconfig"][href*="tab=8"]')).toBeVisible();
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

  test('Banner honors prefers-reduced-motion (no entrance animation)', async ({ page }) => {
    await setLicense(page, 'TESTKEY-E2E', false);
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto(SLIMSTAT_PAGE);
    const animationName = await page
      .locator(BANNER)
      .evaluate((el) => getComputedStyle(el).animationName);
    expect(animationName).toBe('none');
  });

  test('Banner is RTL-safe (no thick logical side-stripe)', async ({ page }) => {
    await setLicense(page, 'TESTKEY-E2E', false);
    await page.goto(SLIMSTAT_PAGE);
    await page.evaluate(() => {
      document.documentElement.dir = 'rtl';
    });
    const startWidth = await page
      .locator(BANNER)
      .evaluate((el) => getComputedStyle(el).borderInlineStartWidth);
    expect(startWidth).toBe('1px');
  });

  test('Banner can be minimized and expanded, with no dismiss action', async ({ page }) => {
    await setLicense(page, 'TESTKEY-E2E', false);
    await page.goto(SLIMSTAT_PAGE);
    const banner = page.locator(BANNER);
    await expect(banner).toBeVisible();

    // There is no close/dismiss control: the banner explains why Pro is off and
    // only clears when the license is fixed. Minimize is the sole affordance.
    await expect(banner.locator('[data-slimstat-alert-dismiss]')).toHaveCount(0);

    // Minimize → compact bar, body text hidden, persists across a reload.
    await banner.locator('[data-slimstat-alert-toggle]').click();
    await expect(banner).toHaveClass(/\bis-minimized\b/);
    await expect(banner.locator('.slimstat-license-alert__text')).toBeHidden();
    await page.reload();
    await expect(page.locator(BANNER)).toHaveClass(/\bis-minimized\b/);

    // Expand → restored, and the banner is never removed from the page.
    await banner.locator('[data-slimstat-alert-toggle]').click();
    await expect(banner).not.toHaveClass(/\bis-minimized\b/);
    await expect(banner.locator('.slimstat-license-alert__text')).toBeVisible();
  });

  test('License tab: clearing the key deactivates the badge on save, no refresh', async ({ page }) => {
    // Regression (two bugs in one flow):
    //  1. Clearing the key left the stored status true, so the badge stayed
    //     "Active" — on save AND after a reload — because an empty key was never
    //     written as deactivated and the badge keyed off status alone.
    //  2. The settings page built its fields before the save ran, so even once
    //     the status was corrected the badge lagged one save behind.
    // Start from a validated, Active license, clear the key, and save. An empty
    // key skips remote validation (deterministic, no live endpoint), so the
    // badge must resolve to the calm "Not activated" in the same response.
    const BADGE = '.slimstat-license-badge';
    await setLicense(page, 'VALID-LOOKING-KEY', true);
    await page.goto(LICENSE_TAB);
    await expect(page.locator(BADGE)).toHaveClass(/\bis-active\b/);
    // A valid license shows no activation banner, even on the License tab.
    await expect(page.locator(BANNER)).toHaveCount(0);

    await page.fill('input[name="options[slimstat_pro_license_key]"]', '');
    await page.locator('#slimstat-options-8').evaluate((form) => (form as HTMLFormElement).requestSubmit());

    // Same response, no reload: the badge has already flipped to neutral...
    await expect(page.getByText(/your new settings have been saved/i)).toBeVisible();
    await expect(page.locator(BADGE)).toHaveClass(/\bis-neutral\b/);
    await expect(page.locator(BADGE)).toContainText(/Not activated/i);
    // ...and the activation banner has appeared in the SAME response. The Pro
    // admin_init preflight resolves the cleared license before admin_notices
    // renders, so the banner no longer lags one save behind.
    await expect(page.locator(BANNER)).toBeVisible();

    // And it stays neutral after a real reload (the status was persisted false,
    // not just re-rendered).
    await page.reload();
    await expect(page.locator(BADGE)).toHaveClass(/\bis-neutral\b/);
    await expect(page.locator(BANNER)).toBeVisible();
  });
});
