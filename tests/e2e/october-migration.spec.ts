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
  // The button is enabled in markup before the footer script binds it; wait for the loaded page.
  await page.waitForURL(/page=slimstat_migration/,{waitUntil:'load'});
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
  await expect(page.locator('#slimstat-status-note')).toContainText('Required migrations are complete');
  await expect(page.locator('#slimstat-status-note')).toHaveClass(/notice-success/);
  await expect(page.locator('#slimstat-start-migration')).toBeHidden();
  expect(settingsHash()).toBe(before);
  await page.screenshot({path:info.outputPath('migration-complete.png'),fullPage:true});
  await page.goto('/wp-admin/admin.php?page=slimview1'); await expect(banner).toHaveCount(0);
  // The screen refreshes physical state rather than trusting a stale optional-step cache.
  await page.goto('/wp-admin/admin.php?page=slimstat_migration');
  await expect(page.locator('#slimstat-status-note')).toContainText('Database is up to date');

 } finally {
  runWordPressFixture(`<?php global $wpdb; $m=new \\SlimStat\\Migration\\Migrations\\CreateDtOutIndex($wpdb,$wpdb); if ($m->shouldRun()) $m->run(); delete_transient('slimstat_migration_probe'); delete_transient('slimstat_migration_offered');`);
  await restoreSlimstatOptions(); await closeDb();
 }
});

test('issue 360: legacy drift offers an explicit repair and clears only after verification', async ({page}, info) => {
 test.setTimeout(90000);
 const fixture = (body:string) => runWordPressFixture(`<?php
  if (DB_NAME !== 'tests-wordpress') throw new RuntimeException('Disposable DB required');
  global $wpdb;
  ${body}
 `).trim();
 const before = fixture(`
  if ((int)$wpdb->get_var('SELECT MAX(CHAR_LENGTH(email)) FROM '.$wpdb->prefix.'slim_stats') > 254) throw new RuntimeException('Fixture would truncate values');
  echo hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'slim_stats ORDER BY id',ARRAY_A)));
 `);
 try {
  fixture(`
   if (false === $wpdb->query('ALTER TABLE '.$wpdb->prefix.'slim_stats MODIFY email VARCHAR(254) DEFAULT NULL')) throw new RuntimeException($wpdb->last_error);
   set_transient('slimstat_migration_offered',[],3600);
  `);
  await page.goto('/wp-admin/admin.php?page=slimstat_migration');
  await expect(page.locator('#slimstat-status-note')).toContainText('Database columns still differ');
  await expect(page.locator('.slimstat-column-drift')).toContainText('slim_stats.email (254, declared 256)');
  await expect(page.locator('[data-migration="repair-legacy-column-widths"]')).toHaveCount(0);
  fixture(`if (false === $wpdb->query('ALTER TABLE '.$wpdb->prefix.'slim_stats MODIFY email VARCHAR(255) DEFAULT NULL')) throw new RuntimeException($wpdb->last_error);`);
  await page.reload();
  const repair = page.locator('[data-migration="repair-legacy-column-widths"]');
  await expect(repair).toBeVisible();
  await expect(page.locator('#slimstat-status-note')).not.toContainText('Database is up to date');
  await page.screenshot({path:info.outputPath('issue360-before-repair.png'),fullPage:true});
  await repair.click();
  await expect(repair.locator('..').locator('.status')).toContainText(/complete|done/i);
  await page.reload();
  await expect(page.locator('.slimstat-column-drift')).toHaveCount(0);
  await expect(page.locator('#slimstat-status-note')).toContainText('Database is up to date');
  expect(fixture(`echo hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'slim_stats ORDER BY id',ARRAY_A)));`)).toBe(before);
  await page.screenshot({path:info.outputPath('issue360-after-repair.png'),fullPage:true});
 } finally {
  fixture(`
   $wpdb->query('ALTER TABLE '.$wpdb->prefix.'slim_stats MODIFY email VARCHAR(256) DEFAULT NULL');
   delete_transient('slimstat_migration_probe'); delete_transient('slimstat_migration_offered'); delete_transient('slimstat_column_drift_checked');
  `);
  await closeDb();
 }
});
