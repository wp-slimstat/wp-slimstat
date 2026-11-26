import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';

const fixture = (body: string) => runWordPressFixture(`<?php
if (!preg_match('/^tests-/', DB_NAME)) throw new RuntimeException('Disposable DB required');
global $wpdb;
${body}
`).trim();

test('issue 429: unavailable column inspection shows host guidance until inspection recovers', async ({ page }) => {
  const fault = Buffer.from(`<?php
    add_filter('query', static function($sql) {
      if (($_GET['page'] ?? '') === 'slimstat_migration'
          && preg_match('/^SHOW (?:FULL )?COLUMNS FROM /i', $sql)) {
        return 'SHOW COLUMNS FROM nonexistent_issue429_probe';
      }
      return $sql;
    });
  `).toString('base64');
  fixture(`
    $file=WP_CONTENT_DIR.'/mu-plugins/slimstat-issue429-query-fixture.php';
    if (file_exists($file)) throw new RuntimeException('Fault fixture already exists');
    wp_mkdir_p(dirname($file)); file_put_contents($file,base64_decode('${fault}'));
  `);
  try {
    await page.goto('/wp-admin/admin.php?page=slimstat_migration');
    await expect(page.locator('#slimstat-status-note')).not.toContainText('Database is up to date');
    const recovery = page.locator('#slimstat-manual-column-repair');
    await expect(recovery).toContainText('SlimStat could not read this table');
    await expect(recovery.locator('textarea')).toHaveCount(2);
    await expect(recovery.locator('textarea').nth(0)).toHaveValue('SHOW CREATE TABLE `wp_slim_stats`;');
    await expect(recovery.locator('textarea').nth(1)).toHaveValue('SHOW CREATE TABLE `wp_slim_stats_archive`;');
    await expect(page.locator('[data-migration="repair-legacy-column-widths"]')).toHaveCount(0);
  } finally {
    fixture(`unlink(WP_CONTENT_DIR.'/mu-plugins/slimstat-issue429-query-fixture.php');`);
  }
  await page.reload();
  await expect(page.locator('#slimstat-status-note')).toContainText('Database is up to date');
  await expect(page.locator('#slimstat-manual-column-repair')).toHaveCount(0);
});

test('issue 429: latin1 online refusal has a usable manual repair and clears the warning', async ({ page }, info) => {
  test.setTimeout(90000);
  const before = fixture(`
    if ((int)$wpdb->get_var('SELECT MAX(CHAR_LENGTH(city)) FROM '.$wpdb->prefix.'slim_stats') > 255) throw new RuntimeException('Fixture would truncate values');
    echo hash('sha256',serialize($wpdb->get_results('SELECT id,city FROM '.$wpdb->prefix.'slim_stats ORDER BY id',ARRAY_A)));
    if (false === $wpdb->query('ALTER TABLE '.$wpdb->prefix.'slim_stats MODIFY city VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL')) throw new RuntimeException($wpdb->last_error);
    update_option('slimstat_schema_column_drift',['slim_stats.city (255, declared 256)'],false);
    delete_transient('slimstat_column_drift_checked');
  `);
  try {
    await page.goto('/wp-admin/admin.php?page=slimstat_migration');
    const repair = page.locator('[data-migration="repair-legacy-column-widths"]');
    await expect(repair).toBeVisible();
    await repair.click();
    await expect(repair.locator('..').locator('.status')).toContainText(/failed/i);
    await expect(page.locator('#slimstat-step-repair-legacy-column-widths .slimstat-step-message')).toContainText('server refused the online');
    await expect(page.locator('#slimstat-step-repair-legacy-column-widths a[href="#slimstat-manual-column-repair"]')).toBeVisible();
    await page.reload();
    const recovery = page.locator('#slimstat-manual-column-repair');
    await expect(recovery).toBeVisible();
    await expect(recovery).toContainText('pause tracking writes');
    await expect(recovery).toContainText('database host');
    await expect(recovery).toContainText('server refused the online');
    await expect(page.locator('#slimstat-charset-repair-guidance')).toContainText('Convert analytics tables to utf8mb4');
    const sql = await recovery.locator('textarea').inputValue();
    expect(sql).toContain('ALGORITHM=COPY');
    expect(sql).toContain('COLLATE `latin1_swedish_ci`');
    expect(fixture(`echo $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}slim_stats LIKE 'city'",1);`)).toBe('varchar(255)');
    await page.screenshot({ path: info.outputPath('manual-repair.png'), fullPage: true });
    fixture(`
      $sql=base64_decode('${Buffer.from(sql).toString('base64')}');
      if (false === $wpdb->query($sql)) throw new RuntimeException($wpdb->last_error);
    `);
    await page.reload();
    await expect(page.locator('.slimstat-column-drift')).toHaveCount(0);
    await expect(page.locator('#slimstat-status-note')).toContainText('Database is up to date');
    expect(fixture(`echo hash('sha256',serialize($wpdb->get_results('SELECT id,city FROM '.$wpdb->prefix.'slim_stats ORDER BY id',ARRAY_A)));`)).toBe(before);
    await page.goto('/wp-admin/admin.php?page=slimview1');
    await expect(page.getByText('Database columns differ from this version', { exact: true })).toHaveCount(0);
    fixture(`
      delete_option('slimstat_schema_column_drift');
      wp_slimstat::record_degradation('legacy_column_width_repair','Old failed repair',wp_slimstat::DEGRADATION_OPERATIONAL);
      wp_slimstat::record_degradation('issue429 unrelated','Keep this warning',wp_slimstat::DEGRADATION_OPERATIONAL);
    `);
    await page.goto('/wp-admin/admin.php?page=slimstat_migration');
    expect(fixture(`$d=get_option('slimstat_degradations',[]); echo !isset($d['legacy_column_width_repair']) && isset($d['issue429 unrelated']) ? 'recovered' : 'wrong';`)).toBe('recovered');
  } finally {
    fixture(`
      $wpdb->query('ALTER TABLE '.$wpdb->prefix.'slim_stats MODIFY city VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL');
      delete_transient('slimstat_column_drift_checked');
      wp_slimstat::clear_degradation('issue429 unrelated');
    `);
  }
});

test('issue 429: hidden repairs explain custom attributes and unsupported widths safely', async ({ page }) => {
  test.setTimeout(90000);
  try {
    for (const definition of ["VARCHAR(255) DEFAULT ''", "VARCHAR(255) DEFAULT NULL COMMENT '</textarea><script>window.issue429Injected=1</script>'", 'VARCHAR(254) DEFAULT NULL']) {
      fixture(`
        if ((int)$wpdb->get_var('SELECT MAX(CHAR_LENGTH(city)) FROM '.$wpdb->prefix.'slim_stats') > 254) throw new RuntimeException('Fixture would truncate values');
        if (false === $wpdb->query('ALTER TABLE '.$wpdb->prefix.'slim_stats MODIFY city '.base64_decode('${Buffer.from(definition).toString('base64')}'))) throw new RuntimeException($wpdb->last_error);
      `);
      const before = fixture(`echo hash('sha256',serialize($wpdb->get_results('SHOW FULL COLUMNS FROM '.$wpdb->prefix.'slim_stats',ARRAY_A)));`);
      await page.goto('/wp-admin/admin.php?page=slimstat_migration');
      await expect(page.locator('[data-migration="repair-legacy-column-widths"]')).toHaveCount(0);
      await expect(page.locator('#slimstat-manual-column-repair')).toContainText('online repair is unavailable');
      await expect(page.locator('#slimstat-charset-repair-guidance')).toHaveCount(0);
      const recovery = page.locator('#slimstat-manual-column-repair');
      await expect(recovery).toContainText('Column city');
      await expect(recovery).toContainText(definition.includes('254') ? 'unsupported type' : 'custom attributes');
      if (!definition.includes('254')) {
        await expect(recovery).toContainText(definition.includes('COMMENT') ? 'COMMENT' : 'DEFAULT');
      }
      await expect(recovery.locator('textarea')).toHaveValue('SHOW CREATE TABLE `wp_slim_stats`;');
      expect(await page.evaluate(() => (window as any).issue429Injected)).toBeUndefined();
      expect(fixture(`echo hash('sha256',serialize($wpdb->get_results('SHOW FULL COLUMNS FROM '.$wpdb->prefix.'slim_stats',ARRAY_A)));`)).toBe(before);
    }
  } finally {
    fixture(`$wpdb->query("ALTER TABLE {$wpdb->prefix}slim_stats MODIFY city VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''");`);
  }
});

test('legacy latin1 columns recover through the two optional buttons without SQL', async ({ page }, info) => {
  test.setTimeout(90000);
  const before = fixture(`
    if ((int)$wpdb->get_var('SELECT MAX(CHAR_LENGTH(city)) FROM '.$wpdb->prefix.'slim_stats') > 255) throw new RuntimeException('Fixture would truncate values');
    echo hash('sha256',serialize($wpdb->get_results('SELECT id,city FROM '.$wpdb->prefix.'slim_stats ORDER BY id',ARRAY_A)));
    if (false === $wpdb->query('ALTER TABLE '.$wpdb->prefix.'slim_stats MODIFY city VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL')) throw new RuntimeException($wpdb->last_error);
  `);
  try {
    await page.goto('/wp-admin/admin.php?page=slimstat_migration');
    await expect(page.locator('#slimstat-charset-repair-guidance')).toBeVisible();
    await page.locator('[data-migration="convert-tables-to-utf8mb4"]').click();
    await expect(page.locator('#slimstat-step-convert-tables-to-utf8mb4 .slimstat-step-heading .status')).toHaveText('Done');
    await expect(page.locator('#slimstat-step-repair-legacy-column-widths .status')).toBeEmpty();
    await page.reload();
    await expect(page.locator('#slimstat-charset-repair-guidance')).toHaveCount(0);
    await page.locator('[data-migration="repair-legacy-column-widths"]').click();
    await expect(page.locator('#slimstat-step-repair-legacy-column-widths .slimstat-step-heading .status')).toHaveText('Done');
    await page.screenshot({ path: info.outputPath('two-step-recovery-done.png'), fullPage: true });
    await page.reload();
    await expect(page.locator('#slimstat-status-note')).toContainText('Database is up to date');
    await expect(page.locator('#slimstat-manual-column-repair')).toHaveCount(0);
    expect(fixture(`echo $wpdb->get_var("SHOW FULL COLUMNS FROM {$wpdb->prefix}slim_stats LIKE 'city'",1);`)).toBe('varchar(256)');
    expect(fixture(`echo hash('sha256',serialize($wpdb->get_results('SELECT id,city FROM '.$wpdb->prefix.'slim_stats ORDER BY id',ARRAY_A)));`)).toBe(before);
  } finally {
    fixture(`$wpdb->query('ALTER TABLE '.$wpdb->prefix.'slim_stats MODIFY city VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL');`);
  }
});

test('migration failures render response text safely, including permission and transport failures', async ({ page }) => {
  fixture(`$wpdb->query('ALTER TABLE '.$wpdb->prefix.'slim_stats MODIFY city VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL');`);
  try {
    await page.goto('/wp-admin/admin.php?page=slimstat_migration');
    const row = page.locator('#slimstat-step-repair-legacy-column-widths');
    const button = row.locator('button');
    for (const data of [{ message: '<img src=x onerror="window.repairInjected=1">' }, 'Permission denied', {}]) {
      await page.route('**/admin-ajax.php', async route => {
        if (route.request().postData()?.includes('slimstat_run_migrations')) {
          await route.fulfill({ json: { success: false, data } });
        } else await route.continue();
      });
      await button.click();
      await expect(row.locator('.status')).toHaveText('Failed');
      await expect(row.locator('.slimstat-step-message')).toContainText(typeof data === 'string' ? data : data.message || 'The migration did not complete');
      await expect(row.locator('.slimstat-step-message img')).toHaveCount(0);
      await expect(button).toBeEnabled();
      expect(await page.evaluate(() => (window as any).repairInjected)).toBeUndefined();
      await page.unroute('**/admin-ajax.php');
    }
    await page.route('**/admin-ajax.php', route => route.fulfill({ status: 503, body: 'Unavailable' }));
    await button.click();
    await expect(row.locator('.slimstat-step-message')).toContainText('request failed');
    await expect(button).toBeEnabled();
  } finally {
    fixture(`$wpdb->query('ALTER TABLE '.$wpdb->prefix.'slim_stats MODIFY city VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL');`);
  }
});
