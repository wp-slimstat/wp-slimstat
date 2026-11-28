import { test, expect } from '@playwright/test';
import { ADMIN_USER } from './helpers/env';
import { runWordPressFixture } from './helpers/chart';
import { closeDb, getPool, snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions } from './helpers/setup';

const marker = `dashboard-links-${Date.now()}`;
const visitors = [
  { column: 'ip', value: '192.0.2.18', resource: `/${marker}-anonymous` },
  { column: 'username', value: 'Dashboard Visitor', resource: `/${marker}-member` },
];
let savedLayout: string;

test.beforeAll(async () => {
  await snapshotSlimstatOptions();
  await setSlimstatOptions({ add_dashboard_widgets: 'on', use_separate_menu: 'no', async_load: 'no', refresh_interval: '0', convert_ip_addresses: 'no' });
  savedLayout = runWordPressFixture(`<?php
$user = get_user_by('login', '${ADMIN_USER}');
$saved = [];
foreach (['metaboxhidden_dashboard', 'closedpostboxes_dashboard', 'meta-box-order_slimstat_page_slimlayout'] as $key) {
    $saved[$key] = ['exists' => metadata_exists('user', $user->ID, $key), 'value' => get_user_meta($user->ID, $key, true)];
    update_user_meta($user->ID, $key, []);
}
update_user_option($user->ID, 'meta-box-order_slimstat_page_slimlayout', ['dashboard' => 'slim_p7_02', 'slimview1' => 'slim_p7_02'], true);
echo wp_json_encode($saved);
`);
  for (const [i, visitor] of visitors.entries()) {
    await getPool().execute(
      'INSERT INTO wp_slim_stats (ip, username, resource, dt, visit_id, browser, platform, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
      [i === 0 ? visitor.value : '192.0.2.19', i === 1 ? visitor.value : '', visitor.resource,
        Math.floor(Date.now() / 1000) - i, 418000 + i, 'Firefox', 'linux', marker],
    );
  }
});

test.afterAll(async () => {
  await getPool().execute('DELETE FROM wp_slim_stats WHERE user_agent = ?', [marker]);
  if (savedLayout) runWordPressFixture(`<?php
$user = get_user_by('login', '${ADMIN_USER}');
$saved = json_decode(base64_decode('${Buffer.from(savedLayout).toString('base64')}'), true);
foreach ($saved as $key => $value) {
    if ($value['exists']) { update_user_meta($user->ID, $key, $value['value']); }
    else { delete_user_meta($user->ID, $key); }
}
`);
  await restoreSlimstatOptions();
  await closeDb();
});

for (const visitor of visitors) {
  test(`@compat Dashboard ${visitor.column} link opens only that visitor's Real-time activity (#418)`, async ({ page }) => {
    await page.goto('/wp-admin/index.php');
    await page.locator('#slim_p7_02').scrollIntoViewIfNeeded();
    const link = page.locator('#slim_p7_02 a.slimstat-filter-link').filter({ hasText: visitor.value }).first();
    await expect(link).toBeVisible();
    await link.click();
    await expect(page).toHaveURL(/admin\.php\?page=slimview1(?:&|$)/);
    expect(new URL(page.url()).searchParams.get(`fs[${visitor.column}]`)).toBe(`equals ${visitor.value}`);
    const report = page.locator('#slim_p7_02');
    await expect(report).toContainText(visitor.resource);
    await expect(report).not.toContainText(visitors.find(other => other !== visitor)!.resource);
    await expect(page.locator('body')).not.toContainText('Sorry, you are not allowed');
  });
}

test('@compat Real-time visitor links keep the report and filter (#418)', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=slimview1');
  const visitor = visitors[0];
  await page.locator('#slim_p7_02 a.slimstat-filter-link').filter({ hasText: visitor.value }).first().click();
  await expect(page).toHaveURL(/admin\.php\?page=slimview1(?:&|$)/);
  await expect(page.locator('#slim_p7_02')).toContainText(visitor.resource);
  await expect(page.locator('#slim_p7_02')).not.toContainText(visitors[1].resource);
});
