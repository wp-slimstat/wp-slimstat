import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { runWordPressFixture } from './helpers/chart';

for (const location of ['empty', 'live', 'archive', 'both']) {
  test(`WordPress personal-data download completes with ${location} SlimStat rows`, async ({ page }) => {
    test.setTimeout(90_000);
    const email = `privacy-export-${Date.now()}@example.test`;
    const fixture = JSON.parse(runWordPressFixture(`<?php
      if (DB_NAME !== 'tests-wordpress') { throw new RuntimeException('Disposable database required'); }
      $request = wp_create_user_request('${email}', 'export_personal_data', [], 'confirmed');
      if (is_wp_error($request)) { throw new RuntimeException($request->get_error_message()); }
      $rows = []; $db = wp_slimstat::$wpdb; $p = $GLOBALS['wpdb']->prefix;
      foreach (['live'=>'', 'archive'=>'_archive'] as $source=>$suffix) {
        if ('${location}' !== 'both' && '${location}' !== $source) { continue; }
        if ($suffix) {
          foreach (['slim_stats', 'slim_events'] as $table) {
            if (false === $db->query("CREATE TABLE IF NOT EXISTS {$p}{$table}{$suffix} LIKE {$p}{$table}")) { throw new RuntimeException($db->last_error); }
          }
        }
        if (!$db->insert($p.'slim_stats'.$suffix, ['email'=>'${email}','resource'=>'/privacy-export-'.$source,'dt'=>wp_slimstat::now()])) { throw new RuntimeException($db->last_error); }
        $stat=$db->insert_id;
        if (!$db->insert($p.'slim_events'.$suffix, ['id'=>$stat,'notes'=>'privacy-export-'.$source.'-event','dt'=>wp_slimstat::now()])) { throw new RuntimeException($db->last_error); }
        $rows[]=['suffix'=>$suffix,'stat'=>$stat,'event'=>$db->insert_id];
      }
      echo wp_json_encode(compact('request','rows'));
    `));
    try {
      await page.goto('/wp-admin/export-personal-data.php');
      const row = page.locator(`tr#request-${fixture.request}`);
      await row.hover();
      const downloadPromise = page.waitForEvent('download');
      await row.getByRole('button', { name: 'Download personal data', exact: true }).click();
      const download = await downloadPromise;
      expect(await download.failure()).toBeNull();
      const path = await download.path();
      expect(path).toBeTruthy();
      const report = execFileSync('unzip', ['-p', path!], { encoding: 'utf8' });
      expect(report).toContain(email);
      for (const source of ['live', 'archive']) {
        if (location === 'both' || location === source) {
          expect(report).toContain(`/privacy-export-${source}`);
          expect(report).toContain(`privacy-export-${source}-event`);
        }
      }
      await expect(row.locator('.export-personal-data-failed:visible')).toHaveCount(0);
    } finally {
      runWordPressFixture(`<?php
        $db = wp_slimstat::$wpdb; $p = $GLOBALS['wpdb']->prefix;
        foreach (json_decode('${JSON.stringify(fixture.rows)}', true) as $row) {
          $db->delete($p.'slim_events'.$row['suffix'], ['event_id'=>$row['event']]);
          $db->delete($p.'slim_stats'.$row['suffix'], ['id'=>$row['stat']]);
        }
        $file = get_post_meta(${fixture.request}, '_export_file_name', true);
        if ($file && basename($file) === $file) { wp_delete_file(wp_privacy_exports_dir().$file); }
        wp_delete_post(${fixture.request}, true);
      `);
    }
  });
}
