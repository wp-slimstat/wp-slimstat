import { getProState } from './helpers/pro-state';
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
    expect(fixture('chart').result).toBe('pass');
    await setSlimstatOptions({ async_load: 'on' });
    const day = new Date(seed.start * 1000);
    const params = new URLSearchParams({ page: 'slimview7', type: 'custom',
      from: day.toISOString().slice(0, 10), to: day.toISOString().slice(0, 10),
      'fs[addon_ecommerce_currency]': 'equals USD' });
    await page.goto(`/wp-admin/admin.php?${params}`);
    const dashboard = page.locator('[data-ecommerce]');
    const full = (await getProState(page)).pro_activated;
    await expect(dashboard.locator('[data-metric=net]')).toContainText('115.00', { timeout: 30_000 });
    if (full) {
    await expect(dashboard.locator('[data-metric=orders]')).toHaveText('4');
    await expect(dashboard.locator('[data-metric=aov]')).toContainText('28.75');
    await expect(dashboard.locator('[data-metric=rate]')).toHaveText('0.83%');
    await expect(dashboard).toContainText('2 of 4 orders in this report linked');
    } else {
      for (const metric of ['orders','aov','rate']) {
        await expect(dashboard.locator(`[data-metric=${metric}]`)).toHaveText('••••Available with Pro');
      }
      const series = JSON.parse(await dashboard.locator('[data-series]').getAttribute('data-series') || '{}');
      for (const period of ['current','previous']) for (const point of series[period]) {
        expect(point).not.toHaveProperty('orders'); expect(point).not.toHaveProperty('buyers'); expect(point).not.toHaveProperty('visits');
      }
      await expect(dashboard).not.toContainText('2 of 4 orders');
    }
    await expect(dashboard.locator('[data-dimension=channel]')).toContainText('Paid Search');
    await expect(dashboard.locator('[data-dimension=source]')).not.toBeVisible();
    await dashboard.getByRole('tab', { name: 'Channels', exact: true }).focus();
    await page.keyboard.press('ArrowRight');
    await expect(dashboard.getByRole('tab', { name: 'Sources', exact: true })).toBeFocused();
    await expect(dashboard.locator('[data-dimension=source]')).toBeVisible();
    const sourcePanel = dashboard.getByRole('tabpanel', { name: 'Sources', exact: true });
    if (full) {
    await sourcePanel.getByRole('button', { name: 'Full report' }).click();
    await expect(sourcePanel.locator('[data-expand]')).toHaveAttribute('aria-expanded', 'true');
    await expect(sourcePanel.getByRole('table')).toHaveCount(0);
    await sourcePanel.getByRole('button', { name: 'Reverse sort order' }).click();
    const rankedSources = await sourcePanel.locator('.ss-ec-rank-name').allTextContents();
    expect(rankedSources.length).toBeGreaterThan(0);
    await sourcePanel.getByLabel('Sort these results').selectOption('orders');
    await expect(sourcePanel.locator('[data-rank-metric]')).toHaveValue('orders');
    await page.keyboard.press('Escape');
    await expect(sourcePanel.getByRole('button', { name: 'Full report' })).toBeFocused();
    await expect(sourcePanel.getByRole('table')).not.toBeVisible();
    await dashboard.locator('[data-chart-metric=orders]').click();
    await expect(dashboard.locator('[data-chart-metric=orders]')).toHaveAttribute('aria-pressed', 'true');
    await expect(dashboard.locator('[data-chart-title]')).toHaveText(full ? 'Orders' : 'Net sales');
    await dashboard.getByText('View exact values', { exact: true }).click();
    await expect(dashboard.locator('[data-chart-table] tr').first()).toContainText('4');
    await expect(dashboard.locator('[data-chart-table] tr').first()).toContainText('1');
    } else {
      await expect(sourcePanel.locator('.ss-ec-rankings > li')).toHaveCount(2);
      await expect(sourcePanel.getByRole('button', { name: 'Full report' })).toHaveCount(0);
      await sourcePanel.getByRole('button', { name: 'Reverse sort order' }).click();
      await dashboard.locator('[data-chart-metric=orders]').click();
      await expect(dashboard.locator('[data-chart-metric=net]')).toHaveAttribute('aria-pressed','true');
      await expect(dashboard.getByRole('link',{ name:'Unlock Ecommerce insights with Pro' })).toBeFocused();
      await dashboard.getByText('View exact values', { exact: true }).click();
      await expect(dashboard.locator('[data-chart-table] tr').first()).toContainText('115.00');
      await expect(dashboard.locator('[data-chart-table] tr').first()).toContainText('20.00');
    }
    await page.getByLabel('Compare previous period').uncheck();
    await expect(dashboard.locator('[data-chart-table] tr').first().locator('td')).toHaveCount(1);
    await page.getByLabel('Compare previous period').check();
    await dashboard.getByLabel('Chart interval').selectOption('monthly');
    await expect(dashboard.getByLabel('Chart interval')).toHaveValue('monthly');
    await expect(dashboard.locator('[data-series]')).toHaveAttribute('data-series', /"interval":"monthly"/);
    await expect(dashboard.locator('[data-chart-title]')).toHaveText(full ? 'Orders' : 'Net sales');
    await dashboard.locator('[data-chart-metric=net]').click();
    const previousDashboard = await dashboard.elementHandle();
    await dashboard.getByRole('link', { name: 'Refresh Ecommerce reports' }).click();
    await expect.poll(() => previousDashboard!.evaluate(el => !el.isConnected)).toBe(true);
    await previousDashboard!.dispose();
    await expect.poll(() => page.evaluate(() => !(window as any).jQuery('#slim_p10_01 .inside').is(':animated'))).toBe(true);
    await expect(dashboard.locator('[data-metric=net]')).toContainText('115.00');
    await page.screenshot({ path: testInfo.outputPath('ecommerce-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(dashboard.locator('[data-metric=net]')).toBeVisible();
    await expect(dashboard.locator('.ss-ec-chart-description')).toHaveCSS('word-break', 'normal');
    expect(await dashboard.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('ecommerce-mobile.png'), fullPage: true });
    params.set('fs[utm_source]', 'equals google');
    await page.goto(`/wp-admin/admin.php?${params}`);
    await expect(dashboard.locator('[data-metric=net]')).toContainText('65.00');
    await expect(dashboard.locator('[data-metric=orders]')).toHaveText(full ? '2' : '••••Available with Pro');
    await page.locator('#slimstat-save-filter').click();
    await expect(page.locator('#slimstat-save-filter')).toHaveText(/Saved|Already saved/);
    const unfiltered = new URLSearchParams(params); unfiltered.delete('fs[utm_source]');
    await page.goto(`/wp-admin/admin.php?${unfiltered}`);
    await page.locator('#slimstat-load-saved-filters').click();
    await page.locator('#slim_filters_overlay a.slimstat-filter-link').filter({ hasText: 'google' }).click();
    await expect(dashboard.locator('[data-metric=net]')).toContainText('65.00');
    const savedDates = await page.evaluate(() => {
      const picker = (window as any).jQuery('.slimstat-date-range-input').data('daterangepicker');
      return [picker.startDate.format('YYYY-MM-DD'), picker.endDate.format('YYYY-MM-DD')];
    });
    expect(savedDates).toEqual([day.toISOString().slice(0, 10), day.toISOString().slice(0, 10)]);
    await page.locator('.slimstat-date-range-btn').click();
    await page.evaluate(() => {
      const picker = (window as any).jQuery('.slimstat-date-range-input').data('daterangepicker');
      picker.setEndDate(picker.endDate.clone().add(1, 'day')); picker.chosenLabel = 'Custom range'; picker.clickApply();
    });
    await expect(dashboard.locator('[data-metric=net]')).toContainText('65.00');
    await expect(page.locator('#slimstat-current-filters')).toContainText('google');
    await expect(dashboard).toContainText('Traffic filters are active');
    params.delete('fs[utm_source]');
    params.set('fs[addon_ecommerce_currency]', 'equals EUR');
    await page.goto(`/wp-admin/admin.php?${params}`);
    await expect(dashboard.locator('[data-metric=net]')).toContainText('200.00');
    await expect(dashboard.locator('[data-metric=orders]')).toHaveText(full ? '1' : '••••Available with Pro');
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

test('Ecommerce explains incomplete, empty and loading states and keeps the newest chart request @woocommerce', async ({ page }) => {
  test.setTimeout(120_000);
  await snapshotOption('slimstat_ecommerce_state');
  fixture('cleanup');
  try {
    const seed = fixture('seed');
    const day = new Date(seed.start * 1000).toISOString().slice(0, 10);
    const params = new URLSearchParams({ page: 'slimview7', type: 'custom', from: day, to: day, 'fs[addon_ecommerce_currency]': 'equals USD' });
    await page.goto(`/wp-admin/admin.php?${params}`);
    const dashboard = page.locator('[data-ecommerce]');
    const full = (await getProState(page)).pro_activated;
    await expect(dashboard.locator('[data-metric=net]')).toContainText('115.00');
    await expect(page.getByLabel('Filter dimension')).toBeVisible();
    if (full) {
      await dashboard.getByRole('tabpanel', { name: 'Channels', exact: true }).getByLabel('Sort these results').selectOption('orders');
      await expect(dashboard.locator('[data-dimension=channel] .ss-ec-rank-both').first()).toContainText('Orders: 2');
    }
    await dashboard.locator('[data-dimension=channel] [data-sort]').click();
    await expect(dashboard.locator('[data-dimension=channel] .ss-ec-rank-value').first()).toContainText('Net sales:');
    let blocked = false, weeklyRequests = 0;
    await page.route('**/admin-ajax.php', async route => {
      const body = route.request().postData() || '';
      if (body.includes('report_id=slim_p10_01') && body.includes('ecommerce_interval=weekly')) {
        blocked = true;
        weeklyRequests++;
        await new Promise(resolve => setTimeout(resolve, 1000));
      }
      await route.continue().catch(() => {});
    });
    await dashboard.getByLabel('Chart interval').selectOption('weekly');
    await expect.poll(() => blocked).toBe(true);
    await page.evaluate(() => { (window as any).SlimStatEcommerce.refresh(); });
    await expect(dashboard).toHaveAttribute('aria-busy', 'true');
    await expect(dashboard.getByRole('tabpanel', { name: 'Channels', exact: true })).toBeVisible();
    await dashboard.getByLabel('Chart interval').selectOption('monthly');
    await expect(dashboard.locator('[data-series]')).toHaveAttribute('data-series', /"interval":"monthly"/);
    await page.waitForTimeout(1200); // Deliberately delayed obsolete request must never replace the new response.
    await expect(dashboard.getByLabel('Chart interval')).toHaveValue('monthly');
    expect(weeklyRequests).toBe(1);
    await page.unroute('**/admin-ajax.php');
    await page.route('**/admin-ajax.php', route => route.request().postData()?.includes('report_id=slim_p10_01') ? route.abort() : route.continue());
    await dashboard.getByRole('link', { name: 'Refresh Ecommerce reports' }).click();
    await expect(dashboard.locator('.ss-ec-feedback')).toBeVisible();
    await expect(dashboard.locator('.ss-ec-feedback')).toContainText('Could not refresh');
    await expect(dashboard.locator('[data-metric=net]')).toContainText('115.00');
    expect(await page.evaluate(async () => {
      try { await (window as any).SlimStatEcommerce.refresh(); return true; } catch { return false; }
    })).toBe(true); // A failed Ecommerce request must not stop the native queue of other reports.
    await page.unroute('**/admin-ajax.php');
    // Import finished, one order failed: say so and offer Retry, but the totals stand.
    runWordPressFixture("<?php $state=get_option('slimstat_ecommerce_state'); $state['complete']=true; $state['error']=true; $state['failed_order']=4242; update_option('slimstat_ecommerce_state',$state,false);");
    await dashboard.getByRole('link', { name: 'Refresh Ecommerce reports' }).click();
    await expect(page.locator('#ss-ec-quality > summary')).toContainText(full ? 'Order 4242 could not be imported' : 'Synchronization needs attention');
    await expect(page.locator('#ss-ec-quality > summary')).not.toContainText('provisional');
    await expect(dashboard.getByText('Complete synchronization first', { exact: true })).toHaveCount(0);
    await page.locator('#ss-ec-quality > summary').click();
    await expect(page.locator('#ss-ec-quality')).toContainText(full ? 'Order 4242 could not be synchronized' : 'Net sales is provisional');
    await expect(dashboard.getByRole('button', { name: 'Retry import' })).toBeVisible();
    await page.keyboard.press('Escape');
    // Import still running (and failing): figures are provisional until it completes.
    runWordPressFixture("<?php $state=get_option('slimstat_ecommerce_state'); $state['complete']=false; $state['imported']=17; update_option('slimstat_ecommerce_state',$state,false);");
    await dashboard.getByRole('link', { name: 'Refresh Ecommerce reports' }).click();
    await expect(page.locator('#ss-ec-quality > summary')).toContainText('figures are provisional');
    if (full) await expect(dashboard.getByText('Complete synchronization first', { exact: true })).toBeVisible();
    if (full) await dashboard.getByRole('link', { name: 'Review synchronization' }).click();
    else await page.locator('#ss-ec-quality > summary').click();
    await expect(dashboard.getByRole('button', { name: 'Retry import' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#ss-ec-quality')).not.toHaveAttribute('open');
    runWordPressFixture("<?php $state=get_option('slimstat_ecommerce_state'); unset($state['error'], $state['failed_order']); $state['complete']=true; update_option('slimstat_ecommerce_state',$state,false);");
    const emptyDay = new Date((seed.start + 3 * 86400) * 1000).toISOString().slice(0, 10);
    params.set('from', emptyDay); params.set('to', emptyDay);
    await page.goto(`/wp-admin/admin.php?${params}`);
    await expect(dashboard.locator('[data-metric=net]')).toContainText('0.00');
    await expect(dashboard.getByText('Journey data is not available yet', { exact: true })).toHaveCount(full ? 1 : 0);
    await dashboard.locator('[data-chart-metric=rate]').click();
    // A retained day without visits plots at zero; only expired history leaves the chart empty.
    await expect(dashboard.locator('[data-chart-empty]')).toBeHidden();
    expect(await dashboard.locator('canvas').evaluate(canvas => (window as any).Chart.getChart(canvas).data.datasets[0].data.every((v: unknown) => v === 0))).toBe(true);
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  } finally {
    await page.unroute('**/admin-ajax.php'); fixture('cleanup');
    await restoreOption('slimstat_ecommerce_state'); await closeDb();
  }
});

test('Ecommerce Free is useful alone and WooCommerce deactivation is safe @woocommerce', async ({ page }) => {
  test.setTimeout(90_000);
  const proWasActive = runWordPressFixture("<?php echo is_plugin_active('wp-slimstat-pro/wp-slimstat-pro.php') ? 'yes' : 'no';") === 'yes';
  fixture('cleanup');
  try {
    // A range without orders is one line, not empty report cards (audit E2), so open the seeded day.
    const day = new Date(fixture('seed').start * 1000).toISOString().slice(0, 10);
    runWordPressFixture("<?php deactivate_plugins('wp-slimstat-pro/wp-slimstat-pro.php');");
    await page.goto(`/wp-admin/admin.php?${new URLSearchParams({ page: 'slimview7', type: 'custom', from: day, to: day })}`);
    await expect(page.locator('.ss-ec-report')).toHaveCount(3, { timeout: 30_000 });
    await expect(page.locator('[data-ecommerce]').getByRole('link', { name: 'Export CSV', exact: true })).toHaveCount(0);
    // Each tab still shows Export, and it opens the Pro page instead of downloading.
    const upsells = page.locator('[data-ecommerce] [data-ec-export]');
    await expect(upsells).toHaveCount(3);
    for (const upsell of await upsells.all()) await expect(upsell).toHaveAttribute('href', /admin\.php\?page=slimpro&.*utm_campaign=export.*#ss-pro-share$/);
    runWordPressFixture("<?php deactivate_plugins('woocommerce/woocommerce.php');");
    await page.goto('/wp-admin/admin.php?page=slimview7');
    await expect(page.locator('[data-ecommerce]')).toContainText('Activate WooCommerce', { timeout: 30_000 });
    await page.goto('/wp-admin/admin.php?page=slimview3');
    await expect(page.locator('[data-ecommerce]')).toHaveCount(0);
    expect(await page.locator('link[href*="ecommerce.css"]').count()).toBe(0);
    expect(await page.locator('script[src*="ecommerce.js"]').count()).toBe(0);
    const privacy = runWordPressFixture("<?php echo isset(apply_filters('wp_privacy_personal_data_erasers', [])['slimstat-ecommerce']) ? 'registered' : 'missing';");
    expect(privacy).toBe('registered');
    expect(runWordPressFixture("<?php SlimStat\\Ecommerce\\Integration::sync(0); SlimStat\\Ecommerce\\Integration::import(); do_action('slimstat_ecommerce_sync', 0); do_action('slimstat_ecommerce_import'); echo 'safe';")).toBe('safe');
    // Remove the plugin entry point, not its directory: CI mounts that directory. Disposable wp-env only.
    runWordPressFixture("<?php if (DB_NAME !== 'tests-wordpress') throw new RuntimeException('Disposable database required'); update_option('slimstat_ec_absent_backup',get_option('slimstat_ecommerce_state')); delete_option('slimstat_ecommerce_state'); if (!rename(WP_PLUGIN_DIR.'/woocommerce/woocommerce.php', WP_PLUGIN_DIR.'/woocommerce/woocommerce.php.audit-absent')) throw new RuntimeException('Cannot isolate WooCommerce');");
    await page.goto('/wp-admin/admin.php?page=slimview7');
    await expect(page.locator('[data-ecommerce]')).toContainText('Activate WooCommerce');
    expect(runWordPressFixture("<?php echo function_exists('wc_get_orders') ? 'loaded' : 'absent';")).toBe('absent');
    expect((await page.request.get('/')).status()).toBe(200);
  } finally {
    runWordPressFixture("<?php if (is_file(WP_PLUGIN_DIR.'/woocommerce/woocommerce.php.audit-absent') && !rename(WP_PLUGIN_DIR.'/woocommerce/woocommerce.php.audit-absent',WP_PLUGIN_DIR.'/woocommerce/woocommerce.php')) throw new RuntimeException('Cannot restore WooCommerce'); $s=get_option('slimstat_ec_absent_backup',null); if(null!==$s){update_option('slimstat_ecommerce_state',$s,false);delete_option('slimstat_ec_absent_backup');}");
    runWordPressFixture("<?php activate_plugin('woocommerce/woocommerce.php');");
    if (proWasActive) runWordPressFixture("<?php activate_plugin('wp-slimstat-pro/wp-slimstat-pro.php');");
    fixture('cleanup');
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
    await expect(page.locator('#ss-ec-quality > summary')).toContainText('Importing your orders');
    await expect(page.locator('#ss-ec-quality > summary')).toBeVisible();
    await page.locator('#ss-ec-definitions > summary').click();
    await expect(page.getByRole('button', { name: 'Rebuild reports', exact: true })).toBeVisible();
  } finally {
    runWordPressFixture("<?php update_option('slimstat_ecommerce_state', get_option('slimstat_ec_setup_test_backup', []), false); delete_option('slimstat_ec_setup_test_backup');");
  }
});

// B1 (release review 2026-10-13): a failed setup used to send the admin back to the same button.
test('Ecommerce setup on a MyISAM analytics table says why it stopped and offers a retry @woocommerce', async ({ page }) => {
  test.setTimeout(90_000);
  // MySQL will not change the engine of a foreign-key parent, even with checks off, and a real
  // MyISAM install has no foreign keys at all. So the children's constraints are dropped first and
  // re-added, from their own SHOW CREATE TABLE text, on the way back.
  const engine = (to: string) => runWordPressFixture(`<?php if (DB_NAME !== 'tests-wordpress') throw new RuntimeException('Disposable database required');
global $wpdb; $t = $wpdb->prefix . 'slim_stats'; $q = chr(96);
$wpdb->query('SET FOREIGN_KEY_CHECKS=0');
if ('MyISAM' === '${to}') {
  $fks = [];
  foreach ($wpdb->get_results($wpdb->prepare('SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = %s', $t)) as $fk) {
    if (preg_match('/CONSTRAINT ' . $q . preg_quote($fk->CONSTRAINT_NAME, '/') . $q . ' FOREIGN KEY [^\\n]*?(?=,?\\n)/', (string) $wpdb->get_var("SHOW CREATE TABLE {$fk->TABLE_NAME}", 1), $m)) {
      $fks[$fk->TABLE_NAME] = $m[0];
      $wpdb->query("ALTER TABLE {$fk->TABLE_NAME} DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}");
    }
  }
  update_option('slimstat_ec_myisam_test_fks', $fks, false);
  $wpdb->query("ALTER TABLE {$t} ENGINE=MyISAM");
} else {
  $wpdb->query("ALTER TABLE {$t} ENGINE=InnoDB");
  foreach ((array) get_option('slimstat_ec_myisam_test_fks', []) as $child => $clause) { $wpdb->query("ALTER TABLE {$child} ADD {$clause}"); }
  delete_option('slimstat_ec_myisam_test_fks');
}
$wpdb->query('SET FOREIGN_KEY_CHECKS=1');
echo $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $t));`);
  const foreignKeys = () => runWordPressFixture(`<?php global $wpdb; echo $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = %s', $wpdb->prefix . 'slim_stats'));`);
  const keysBefore = foreignKeys();
  runWordPressFixture("<?php update_option('slimstat_ec_myisam_test_backup', get_option('slimstat_ecommerce_state', []), false); delete_option('slimstat_ecommerce_state');");
  try {
    // A conversion that silently did nothing would make this test pass for the wrong reason.
    expect(engine('MyISAM')).toBe('MyISAM');
    await page.goto('/wp-admin/admin.php?page=slimview7');
    await page.getByRole('button', { name: 'Set up Ecommerce', exact: true }).click();
    const dashboard = page.locator('[data-ecommerce]');
    const full = (await getProState(page)).pro_activated;
    await expect(dashboard.getByRole('alert')).toContainText('Setup did not finish', { timeout: 30_000 });
    await expect(dashboard.getByRole('alert')).toContainText('InnoDB');
    await expect(dashboard.getByRole('button', { name: 'Try setup again', exact: true })).toBeVisible();
    await expect(dashboard.getByRole('button', { name: 'Set up Ecommerce', exact: true })).toHaveCount(0);

    expect(engine('InnoDB')).toBe('InnoDB');
    expect(foreignKeys()).toBe(keysBefore);
    await dashboard.getByRole('button', { name: 'Try setup again', exact: true }).click();
    await expect(page.locator('[data-metric=net]')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('[data-ecommerce]')).not.toContainText('Setup did not finish');
  } finally {
    engine('InnoDB');
    runWordPressFixture("<?php update_option('slimstat_ecommerce_state', get_option('slimstat_ec_myisam_test_backup', []), false); delete_option('slimstat_ec_myisam_test_backup');");
  }
});

// B1 follow-up (CodeRabbit, PR #349): a failed rebuild leaves the store ready, so its reason was
// never read and the page blamed an order import instead.
test('Ecommerce rebuild that cannot start says why on a ready store @woocommerce', async ({ page }) => {
  test.setTimeout(90_000);
  runWordPressFixture("<?php update_option('slimstat_ec_rebuild_test_backup', get_option('slimstat_ecommerce_state', []), false); delete_option('slimstat_ecommerce_state');");
  try {
    await page.goto('/wp-admin/admin.php?page=slimview7');
    await page.getByRole('button', { name: 'Set up Ecommerce', exact: true }).click();
    await expect(page.locator('[data-metric=net]')).toBeVisible({ timeout: 30_000 });

    // Another import holds the claim, so setup() refuses before touching the projection.
    runWordPressFixture("<?php update_option('slimstat_ec_import', (string) (time() + 300), false);");
    await page.locator('#ss-ec-definitions > summary').click();
    await page.getByRole('button', { name: 'Rebuild reports', exact: true }).click();
    const alert = page.locator('[data-ecommerce]').getByRole('alert');
    await expect(alert).toContainText('This report needs attention', { timeout: 30_000 });
    await expect(alert).toContainText('already running');

    runWordPressFixture("<?php delete_option('slimstat_ec_import');");
    await alert.getByRole('button', { name: 'Rebuild reports', exact: true }).click();
    await expect(page.locator('[data-metric=net]')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('[data-ecommerce]')).not.toContainText('already running');
  } finally {
    runWordPressFixture("<?php delete_option('slimstat_ec_import'); update_option('slimstat_ecommerce_state', get_option('slimstat_ec_rebuild_test_backup', []), false); delete_option('slimstat_ec_rebuild_test_backup');");
  }
});

test('Ecommerce uses the native date control and preserves filters through keyboard presets @woocommerce', async ({ page }, testInfo) => {
  const style = async () => page.locator('.slimstat-date-range-btn').evaluate(el => {
    const s = getComputedStyle(el); return {background:s.backgroundColor,color:s.color,font:s.fontSize,radius:s.borderRadius,height:el.getBoundingClientRect().height};
  });
  await page.goto('/wp-admin/admin.php?page=slimview3');
  await expect(page.locator('.slimstat-date-range-btn')).toBeVisible();
  const native = await style();
  await page.goto('/wp-admin/admin.php?page=slimview7&fs[utm_source]=equals%20google');
  await expect(page.locator('[data-ecommerce] [data-metric=net]')).toBeVisible();
  expect(await style()).toEqual(native);
  await page.locator('.slimstat-date-range-btn').focus(); await page.keyboard.press('Enter');
  const picker = page.locator('.daterangepicker:visible'); await expect(picker).toBeVisible();
  const yesterday = await page.evaluate(() => (window as any).SlimStatDatePicker.strings.yesterday);
  await picker.locator('.ranges li').filter({hasText:yesterday}).click();
  await expect(page.locator('.slimstat-date-range-btn')).toContainText(yesterday);
  await expect(page.locator('#slimstat-current-filters')).toContainText('google');
  await expect(page.locator('[data-ecommerce]')).toContainText('Traffic filters are active');
  for (const width of [1440,390]) {
    await page.setViewportSize({width,height:900}); await page.locator('.slimstat-date-range-btn').click();
    await expect(picker).toBeVisible();
    const box = await picker.boundingBox(); expect(box!.x).toBeGreaterThanOrEqual(0); expect(box!.x+box!.width).toBeLessThanOrEqual(width+1);
    await page.screenshot({path:testInfo.outputPath(`native-datepicker-${width}.png`),fullPage:true});
    await page.locator('[data-ecommerce] h2').first().click();
  }
});
