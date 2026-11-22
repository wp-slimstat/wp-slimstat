import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';

const fixture = (body: string) => runWordPressFixture(`<?php
if (DB_NAME !== 'tests-wordpress') throw new RuntimeException('Disposable DB required');
global $wpdb;
${body}
`).trim();

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
    await page.reload();
    const recovery = page.locator('#slimstat-manual-column-repair');
    await expect(recovery).toBeVisible();
    await expect(recovery).toContainText('pause tracking writes');
    await expect(recovery).toContainText('database host');
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
  } finally {
    fixture(`
      $wpdb->query('ALTER TABLE '.$wpdb->prefix.'slim_stats MODIFY city VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL');
      delete_transient('slimstat_column_drift_checked');
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
      const recovery = page.locator('#slimstat-manual-column-repair');
      await expect(recovery).toContainText('Column city');
      await expect(recovery).toContainText(definition.includes('254') ? 'unsupported type' : 'custom default, comment or extra attribute');
      await expect(recovery.locator('textarea')).toHaveValue('SHOW CREATE TABLE `wp_slim_stats`;');
      expect(await page.evaluate(() => (window as any).issue429Injected)).toBeUndefined();
      expect(fixture(`echo hash('sha256',serialize($wpdb->get_results('SHOW FULL COLUMNS FROM '.$wpdb->prefix.'slim_stats',ARRAY_A)));`)).toBe(before);
    }
  } finally {
    fixture(`$wpdb->query("ALTER TABLE {$wpdb->prefix}slim_stats MODIFY city VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT ''");`);
  }
});
