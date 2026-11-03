import { test, expect } from '@playwright/test';
import { requireProBooted } from './helpers/pro-state';
import { runWordPressFixture } from './helpers/chart';
import { snapshotOption, restoreOption, closeDb } from './helpers/setup';

test.afterAll(async () => { await closeDb(); });

test('native AI settings use WordPress forms without storing provider keys @compat', async ({ page }) => {
  await requireProBooted(page);
  const version = runWordPressFixture('<?php echo get_bloginfo("version");').trim();
  await snapshotOption('slimstat_ai_settings');
  try {
    await page.goto('/wp-admin/options-general.php?page=slimstat-ai');
    await expect(page.getByRole('heading', { name: 'AI Analysis', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Manage AI Connections' })).toHaveAttribute('href', /options-connectors\.php$/);
    await expect(page.locator('input[type="password"]')).toHaveCount(0);
    await page.getByLabel('Allow authorized report viewers to request AI analysis').check();
    await page.getByLabel('Save one latest successful result per user and report').uncheck();
    await page.getByLabel('Provider', { exact: true }).selectOption('');
    await page.getByLabel('Model (advanced)', { exact: true }).selectOption('');
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Save Changes' }).click()]);
    await expect(page.getByLabel('Allow authorized report viewers to request AI analysis')).toBeChecked();
    await expect(page.getByLabel('Save one latest successful result per user and report')).not.toBeChecked();
    const state = JSON.parse(runWordPressFixture(`<?php
      global $wpdb;
      echo wp_json_encode(['settings' => get_option('slimstat_ai_settings'), 'autoload' => $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'slimstat_ai_settings'))]);
    `));
    expect(state.settings).toEqual({ enabled: true, save: false, provider: '', model: '' });
    // Core added the default-autoload filter in 6.6; native analysis requires 7.0.
    if (Number.parseFloat(version) >= 6.6) expect(['off', 'no', 'auto-off']).toContain(state.autoload);
    if (Number.parseFloat(version) < 7) {
      expect(runWordPressFixture('<?php echo wp_json_encode(\\WpSlimstatPro\\AI\\Provider::supported());').trim()).toBe('false');
    }
  } finally {
    await restoreOption('slimstat_ai_settings');
  }
});
