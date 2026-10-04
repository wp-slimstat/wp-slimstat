import { test, expect } from '@playwright/test';
import { readFileSync } from 'fs';
import { runWordPressFixture } from './helpers/chart';
import { getProState } from './helpers/pro-state';
import { closeDb, snapshotOption, restoreOption, snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions } from './helpers/setup';

const source = readFileSync(new URL('./helpers/ecommerce-data.php', import.meta.url), 'utf8').replace(/^<\?php\s*/, '');
const fixture = (mode: string) => JSON.parse(runWordPressFixture(`<?php\n$fixture_mode = '${mode}';\n${source}`));

test('Ecommerce discovery, checkout links and Pro first insights @woocommerce', async ({ page }, testInfo) => {
  test.setTimeout(120_000);
  const pro = await getProState(page);
  await snapshotSlimstatOptions();
  await snapshotOption('active_plugins');
  await snapshotOption('slimstat_ecommerce_state');
  fixture('cleanup');
  try {
    const seed = fixture('seed');
    const day = new Date(seed.start * 1000).toISOString().slice(0, 10);
    await setSlimstatOptions({ async_load: 'on', slimstat_pro_license_key: '', slimstat_pro_license_status: false });
    runWordPressFixture("<?php deactivate_plugins('wp-slimstat-pro/wp-slimstat-pro.php');");
    const scope = new URLSearchParams({ page: 'slimview7', type: 'custom', from: day, to: day });
    await page.goto(`/wp-admin/admin.php?${scope}`);
    const dashboard = page.locator('[data-ecommerce]');
    await expect(dashboard.locator('[data-metric=net]')).toContainText('115.00');
    await expect(dashboard.getByRole('heading', { name: 'Find your next revenue opportunity' })).toBeVisible();
    await dashboard.getByRole('link', { name: 'See what Pro adds to Ecommerce' }).click();
    await expect(page.getByRole('heading', { name: 'Turn store activity into your next decision' })).toBeVisible();
    const [checkout, footerCta] = [page.locator('.ss-pro-cta').first(), page.locator('.ss-pro-cta').last()];
    await expect(page.getByRole('link', { name: 'Upgrade to Pro' })).toHaveCount(2);
    expect(new URL(await checkout.getAttribute('href') || '').pathname).toBe('/checkout/wp-slimstat-pro');
    expect(new URL(await checkout.getAttribute('href') || '').searchParams.get('tier')).toBe('1-site');
    await expect(checkout).toHaveAttribute('rel', /noopener/);
    expect(new URL(await checkout.getAttribute('href') || '').searchParams.get('utm_content')).toBe('hero');
    const features = page.locator('.ss-pro-feature h3');
    await expect(features).toHaveCount(11);
    await expect(features.first()).toHaveText('Ecommerce Pro');
    await expect(page.locator('#ss-pro-live .ss-pro-lede')).toHaveText(/right now|next visitor arrives/);
    const footer = new URL(await footerCta.getAttribute('href') || '');
    expect(footer.pathname).toBe('/checkout/wp-slimstat-pro');
    expect(footer.searchParams.get('utm_content')).toBe('footer');
    await expect(page.getByText('Will I lose my existing reports or settings?')).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('pro-discovery-desktop.png'), fullPage: true, animations: 'disabled' });
    await page.getByText('Already purchased Pro?', { exact: true }).click();
    await expect(page.getByRole('link', { name: 'Download Pro from your account' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Upload Pro in WordPress' })).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.locator('.ss-pro').evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('pro-discovery-mobile.png'), fullPage: true });

    // The traffic-to-orders link must carry a complete scope, including literal tags.
    const campaign = 'discovery & café + 20%';
    runWordPressFixture(`<?php global $wpdb; $fixture=get_option('slimstat_ecommerce_test_fixture'); foreach ($fixture['stats'] as $id) { $wpdb->update($wpdb->prefix.'slim_stats', ['utm_campaign' => ${JSON.stringify(campaign)}], ['id' => $id]); }`);
    scope.set('page', 'slimview5');
    scope.set('fs[utm_source]', 'equals google');
    await page.goto(`/wp-admin/admin.php?${scope}`);
    const campaignReport = page.locator('#slim_p3_04');
    await campaignReport.locator('.slimstat-acquisition__group > summary').filter({ hasText: campaign }).click();
    await campaignReport.getByRole('link', { name: 'View linked orders' }).click();
    expect(new URL(page.url()).searchParams.get('fs[utm_campaign]')).toBe(`equals ${campaign}`);
    await expect(dashboard.locator('[data-metric=net]')).toContainText('65.00');
    await expect(page.locator('#slimstat-current-filters')).toContainText(campaign);
    await expect(dashboard).toContainText('Traffic filters are active');
    expect(new URL(page.url()).searchParams.get('fs[utm_source]')).toBe('equals google');

    await page.goto(`/wp-admin/admin.php?${scope}`);
    const channels = page.locator('#slim_p3_03');
    await channels.locator('.slimstat-acquisition__group > summary').filter({ hasText: 'Paid Search' }).click();
    await channels.getByRole('link', { name: 'View linked orders' }).click();
    await expect(dashboard.locator('[data-metric=net]')).toContainText('65.00');
    expect(new URL(page.url()).searchParams.get('fs[traffic_channel]')).toBe('equals paid_search');

    if (!pro.pro_installed) return; // Free CI deliberately omits the private Pro plugin.

    runWordPressFixture("<?php $result=activate_plugin('wp-slimstat-pro/wp-slimstat-pro.php'); if(is_wp_error($result)) throw new RuntimeException($result->get_error_message());");
    scope.set('page', 'slimview7'); scope.delete('fs[utm_source]');
    scope.set('fs[utm_campaign]', `equals ${campaign}`);
    await page.goto(`/wp-admin/admin.php?${scope}#ss-ec-panel-campaign`);
    await expect(dashboard.getByRole('tabpanel', { name: 'Campaigns', exact: true })).toBeVisible();
    await expect(dashboard.getByRole('heading', { name: 'Your Pro reports are ready' })).toBeVisible();
    await expect(dashboard.getByRole('link', { name: 'See what Pro adds to Ecommerce' })).toHaveCount(0);
    await dashboard.getByRole('button', { name: 'Compare campaigns' }).click();
    const exportUrl = new URL(await dashboard.getByRole('tabpanel', { name: 'Campaigns', exact: true }).getByRole('link', { name: 'Export CSV' }).getAttribute('href') || '');
    expect(exportUrl.searchParams.get('fs[utm_campaign]')).toBe(`equals ${campaign}`);
    await dashboard.getByRole('button', { name: 'Explore coupons' }).click();
    await expect(dashboard.getByRole('tabpanel', { name: 'Coupons', exact: true })).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('pro-first-insights-mobile.png'), fullPage: true });
    await dashboard.getByRole('link', { name: 'Activate your license' }).click();
    await expect(page).toHaveURL(/tab=8/);
    await expect(page.getByText('Enter your license key', { exact: true })).toBeVisible();
    await expect(page.getByText(/Save Changes to verify/)).toBeVisible();
    await page.goto('/wp-admin/admin.php?page=slimpro');
    await expect(page.getByRole('heading', { name: 'What Pro adds to your reports' })).toBeVisible();
    await expect(page.getByText('Pro is active on this site')).toBeVisible();
    await expect(page.locator('.ss-pro-feature h3')).toHaveCount(11);
    await expect(page.getByRole('link', { name: 'Upgrade to Pro' })).toHaveCount(0);
    await expect(page.getByText('Will I lose my existing reports or settings?')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Upload Pro in WordPress' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Set up email reports' })).toBeVisible();
    await page.getByRole('link', { name: 'Compare campaign revenue' }).click();
    await expect(dashboard.getByRole('tabpanel', { name: 'Campaigns', exact: true })).toBeVisible();
  } finally {
    fixture('cleanup');
    await restoreOption('active_plugins');
    await restoreOption('slimstat_ecommerce_state');
    await restoreSlimstatOptions();
    await closeDb();
  }
});
