import {test,expect} from '@playwright/test';
import {runWordPressFixture} from './helpers/chart';
import {snapshotSlimstatOptions,restoreSlimstatOptions,closeDb} from './helpers/setup';

test('Task 22: pending banner, failed request, retry, completion and optional-only state', async ({page},info)=>{
 test.setTimeout(90000); await snapshotSlimstatOptions();
 const settingsHash=()=>runWordPressFixture("<?php echo hash('sha256',serialize(get_option('slimstat_options')));").trim();
 const before=settingsHash();
 runWordPressFixture(`<?php
  if (DB_NAME !== 'tests-wordpress') throw new RuntimeException('Disposable DB required');
  global $wpdb;
  $wpdb->query('ALTER TABLE '.$wpdb->prefix.'slim_stats DROP INDEX idx_dt_out');
  delete_transient('slimstat_migration_probe'); delete_option('slimstat_migration_dismissed');
 `);
 try {
  await page.goto('/wp-admin/admin.php?page=slimview1');
  const banner=page.locator('.slimstat-migration-notice-show');
  await expect(banner).toBeVisible(); await expect(banner).toContainText(/back up/i);
  await banner.getByRole('link',{name:'Start Migration',exact:true}).click();
  await expect(page.locator('#slimstat-start-migration')).toBeEnabled();
  let fail=true;
  await page.route('**/admin-ajax.php',async route=>{
   if (!(route.request().postData()||'').includes('action=slimstat_run_migrations')) return route.continue();
   if (fail) { await new Promise(r=>setTimeout(r,300)); return route.fulfill({status:503,body:'Fixture unavailable'}); }
   return route.continue();
  });
  await page.locator('#slimstat-start-migration').click();
  await expect(page.locator('.slimstat-status-badge')).toContainText('Running');
  await expect(page.locator('#slimstat-status-note')).toContainText('request failed');
  await expect(page.locator('#slimstat-start-migration')).toBeEnabled();
  fail=false; await page.locator('#slimstat-start-migration').click();
  await expect(page.locator('#slimstat-status-note')).toContainText('Database is up to date');
  await expect(page.locator('#slimstat-status-note')).toHaveClass(/notice-success/);
  await expect(page.locator('#slimstat-start-migration')).toBeHidden();
  expect(settingsHash()).toBe(before);
  await page.screenshot({path:info.outputPath('migration-complete.png'),fullPage:true});
  await page.goto('/wp-admin/admin.php?page=slimview1'); await expect(banner).toHaveCount(0);
  // A freshly reconciled fixture may have no optional work. Offer a real registered
  // optional step through its normal cache, without converting tables during this UI test.
  runWordPressFixture("<?php set_transient('slimstat_migration_offered',['convert-tables-to-utf8mb4'],60);");
  await page.goto('/wp-admin/admin.php?page=slimstat_migration');
  await expect(page.locator('#slimstat-status-note')).toContainText('Database is up to date');
  await expect(page.getByText('Advanced (optional)',{exact:true})).toBeVisible();
 } finally {
  runWordPressFixture(`<?php global $wpdb; $m=new \\SlimStat\\Migration\\Migrations\\CreateDtOutIndex($wpdb,$wpdb); if ($m->shouldRun()) $m->run(); delete_transient('slimstat_migration_probe'); delete_transient('slimstat_migration_offered');`);
  await restoreSlimstatOptions(); await closeDb();
 }
});
