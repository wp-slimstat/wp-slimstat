/**
 * QA C3: "Your Pro reports are ready" is a one-time pointer, not a permanent panel.
 *
 * - Opening one of the reports it lists retires it on the next visit.
 * - Dismiss retires it at once, and it stays retired.
 * - The license note it carried survives it.
 *
 * Needs Pro, WooCommerce and orders; reads them, and restores the admin's user setting and the options it sets.
 */
import { test, expect } from '@playwright/test';
import { requireProBooted } from './helpers/pro-state';
import { closeDb, snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions } from './helpers/setup';

test('the Pro reports panel retires once opened or dismissed @woocommerce', async ({ page }) => {
  test.setTimeout(120_000);
  await requireProBooted(page);
  const reset = async () => {
    await page.goto('/wp-admin/');
    await page.evaluate(() => (window as any).deleteUserSetting('slimstat_ec_discover'));
  };
  const scope = new URLSearchParams({ page: 'slimview7', type: 'custom', from: '2020-01-01', to: new Date().toISOString().slice(0, 10) });
  const dashboard = page.locator('[data-ecommerce]');
  const ready = dashboard.getByRole('heading', { name: 'Your Pro reports are ready' });
  const load = async () => {
    await page.goto(`/wp-admin/admin.php?${scope}`);
    await expect(dashboard.locator('[data-metric=net]')).toBeVisible({ timeout: 60_000 });
  };
  await snapshotSlimstatOptions();
  try {
    await setSlimstatOptions({ slimstat_pro_license_status: false });
    await reset();
    await load();
    await expect(ready).toBeVisible();
    await dashboard.getByRole('button', { name: 'Compare campaigns' }).click();
    await load();
    await expect(ready).toHaveCount(0);

    await reset();
    await load();
    await dashboard.getByRole('tab', { name: 'Coupons', exact: true }).click();
    await load();
    await expect(ready).toHaveCount(0);

    await reset();
    await load();
    await dashboard.getByRole('button', { name: 'Dismiss' }).click();
    await expect(ready).toHaveCount(0);
    await load();
    await expect(ready).toHaveCount(0);
    await expect(dashboard.getByRole('link', { name: 'Activate your license' })).toBeVisible();
  } finally {
    // The setting syncs to user meta on the next admin load, so it would outlive this test.
    await reset();
    await page.goto('/wp-admin/');
    await restoreSlimstatOptions();
    await closeDb();
  }
});
