import { test, expect } from '@playwright/test';
import { readFileSync } from 'fs';
import { runWordPressFixture } from './helpers/chart';
import { closeDb, snapshotOption, restoreOption, snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions } from './helpers/setup';

const source = readFileSync(new URL('./helpers/ecommerce-data.php', import.meta.url), 'utf8').replace(/^<\?php\s*/, '');
function fixture(mode: string): any {
  return JSON.parse(runWordPressFixture(`<?php\n$fixture_mode = '${mode}';\n${source}`));
}

test('Ecommerce reconciles known WC data, shared filters, refresh and responsive reports @woocommerce', async ({ page }, testInfo) => {
  test.setTimeout(120_000);
  await snapshotSlimstatOptions();
  await snapshotOption('slimstat_filters');
  fixture('cleanup');
  try {
    const seed = fixture('seed');
    const evidence = fixture('verify');
    await testInfo.attach('commerce-reconciliation', { body: JSON.stringify(evidence), contentType: 'application/json' });
    expect(evidence.result).toBe('pass');
    await setSlimstatOptions({ async_load: 'on' });
    const day = new Date(seed.start * 1000);
    const params = new URLSearchParams({ page: 'slimview7', type: 'custom',
      from: day.toISOString().slice(0, 10), to: day.toISOString().slice(0, 10),
      'fs[addon_ecommerce_currency]': 'equals USD' });
    await page.goto(`/wp-admin/admin.php?${params}`);
    const dashboard = page.locator('[data-ecommerce]');
    await expect(dashboard.locator('[data-metric=net]')).toContainText('115.00', { timeout: 30_000 });
    await expect(dashboard.locator('[data-metric=orders]')).toHaveText('4');
    await expect(dashboard.locator('[data-metric=aov]')).toContainText('28.75');
    await expect(dashboard.locator('[data-metric=rate]')).toHaveText('0.83%');
    await expect(dashboard).toContainText('2 of 4 orders linked');
    await expect(dashboard.locator('[data-dimension=channel]')).toContainText('Paid Search');
    await expect(dashboard.locator('[data-dimension=source]')).not.toHaveAttribute('open');
    await dashboard.locator('[data-dimension=source] > summary').focus();
    await page.keyboard.press('Enter');
    await expect(dashboard.locator('[data-dimension=source]')).toHaveAttribute('open');
    await dashboard.getByRole('link', { name: 'Refresh Ecommerce reports' }).click();
    await expect.poll(() => page.evaluate(() => !(window as any).jQuery('#slim_p10_01 .inside').is(':animated'))).toBe(true);
    await expect(dashboard.locator('[data-metric=net]')).toContainText('115.00');
    await page.screenshot({ path: testInfo.outputPath('ecommerce-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(dashboard.locator('[data-metric=net]')).toBeVisible();
    expect(await dashboard.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('ecommerce-mobile.png'), fullPage: true });
    params.set('fs[utm_source]', 'equals google');
    await page.goto(`/wp-admin/admin.php?${params}`);
    await expect(dashboard.locator('[data-metric=net]')).toContainText('65.00');
    await expect(dashboard.locator('[data-metric=orders]')).toHaveText('2');
    await page.locator('#slimstat-save-filter').click();
    await expect(page.locator('#slimstat-save-filter')).toHaveText(/Saved|Already saved/);
    const unfiltered = new URLSearchParams(params); unfiltered.delete('fs[utm_source]');
    await page.goto(`/wp-admin/admin.php?${unfiltered}`);
    await page.locator('#slimstat-load-saved-filters').click();
    await page.locator('#slim_filters_overlay a.slimstat-filter-link').filter({ hasText: 'google' }).click();
    await expect(dashboard.locator('[data-metric=net]')).toContainText('65.00');
    await page.locator('.slimstat-date-range-btn').click();
    await page.evaluate(() => {
      const picker = (window as any).jQuery('.slimstat-date-range-input').data('daterangepicker');
      picker.setEndDate(picker.endDate.clone().add(1, 'day')); picker.chosenLabel = 'Custom Range'; picker.clickApply();
    });
    await expect(dashboard.locator('[data-metric=net]')).toContainText('65.00');
    await expect(page.locator('#slimstat-current-filters')).toContainText('google');
    await expect(dashboard).toContainText('Traffic filters are active');
    params.delete('fs[utm_source]');
    params.set('fs[addon_ecommerce_currency]', 'equals EUR');
    await page.goto(`/wp-admin/admin.php?${params}`);
    await expect(dashboard.locator('[data-metric=net]')).toContainText('200.00');
    await expect(dashboard.locator('[data-metric=orders]')).toHaveText('1');
    params.set('fs[addon_unsupported]', 'equals test');
    await page.goto(`/wp-admin/admin.php?${params}`);
    await expect(dashboard.getByRole('alert')).toContainText('cannot be applied to Ecommerce');
    expect(fixture('safety').checks).toBeGreaterThanOrEqual(53);
    expect(fixture('edges').checks).toBeGreaterThanOrEqual(35);
  } finally {
    fixture('cleanup');
    await restoreOption('slimstat_filters');
    await restoreSlimstatOptions();
    await closeDb();
  }
});

test('Ecommerce Free is useful alone and WooCommerce deactivation is safe @woocommerce', async ({ page }) => {
  test.setTimeout(90_000);
  const proWasActive = runWordPressFixture("<?php echo is_plugin_active('wp-slimstat-pro/wp-slimstat-pro.php') ? 'yes' : 'no';") === 'yes';
  try {
    runWordPressFixture("<?php deactivate_plugins('wp-slimstat-pro/wp-slimstat-pro.php');");
    await page.goto('/wp-admin/admin.php?page=slimview7');
    await expect(page.locator('.ss-ec-report')).toHaveCount(3, { timeout: 30_000 });
    await expect(page.locator('[data-ecommerce]').getByRole('link', { name: 'Export CSV', exact: true })).toHaveCount(0);
    runWordPressFixture("<?php deactivate_plugins('woocommerce/woocommerce.php');");
    await page.goto('/wp-admin/admin.php?page=slimview7');
    await expect(page.locator('[data-ecommerce]')).toContainText('Activate WooCommerce', { timeout: 30_000 });
    await page.goto('/wp-admin/admin.php?page=slimview3');
    await expect(page.locator('[data-ecommerce]')).toHaveCount(0);
    expect(await page.locator('link[href*="ecommerce.css"]').count()).toBe(0);
    const privacy = runWordPressFixture("<?php echo isset(apply_filters('wp_privacy_personal_data_erasers', [])['slimstat-ecommerce']) ? 'registered' : 'missing';");
    expect(privacy).toBe('registered');
  } finally {
    runWordPressFixture("<?php activate_plugin('woocommerce/woocommerce.php');");
    if (proWasActive) runWordPressFixture("<?php activate_plugin('wp-slimstat-pro/wp-slimstat-pro.php');");
  }
});


test('Ecommerce setup is visible, protected and repeatable in the native report @woocommerce', async ({ page }) => {
  test.setTimeout(60_000);
  runWordPressFixture("<?php update_option('slimstat_ec_setup_test_backup', get_option('slimstat_ecommerce_state', []), false); delete_option('slimstat_ecommerce_state');");
  try {
    await page.goto('/wp-admin/admin.php?page=slimview7');
    await expect(page.getByRole('button', { name: 'Set up Ecommerce', exact: true })).toBeVisible();
    const denied = await page.request.post('/wp-admin/admin-post.php', { form: { action: 'slimstat_ecommerce_setup' } });
    expect(denied.status()).toBe(403);
    await page.getByRole('button', { name: 'Set up Ecommerce', exact: true }).click();
    await expect(page.locator('[data-metric=net]')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('[data-ecommerce] [role=status]')).toContainText('Importing your orders');
    await expect(page.locator('[data-ecommerce] [role=status]')).toBeVisible();
    await page.locator('#ss-ec-definitions > summary').click();
    await expect(page.getByRole('button', { name: 'Rebuild reports', exact: true })).toBeVisible();
  } finally {
    runWordPressFixture("<?php update_option('slimstat_ecommerce_state', get_option('slimstat_ec_setup_test_backup', []), false); delete_option('slimstat_ec_setup_test_backup');");
  }
});
