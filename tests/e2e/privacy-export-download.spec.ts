import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { runWordPressFixture } from './helpers/chart';

for (const populated of [false, true]) {
  test(`WordPress personal-data download completes with ${populated ? 'SlimStat rows' : 'no matching rows'}`, async ({ page }) => {
    test.setTimeout(90_000);
    const email = `privacy-export-${Date.now()}@example.test`;
    const fixture = JSON.parse(runWordPressFixture(`<?php
      if (DB_NAME !== 'tests-wordpress') { throw new RuntimeException('Disposable database required'); }
      $request = wp_create_user_request('${email}', 'export_personal_data', [], 'confirmed');
      if (is_wp_error($request)) { throw new RuntimeException($request->get_error_message()); }
      $stat = 0; $event = 0;
      if (${populated ? 'true' : 'false'}) {
        $db = wp_slimstat::$wpdb; $p = $GLOBALS['wpdb']->prefix;
        $db->insert($p.'slim_stats', ['email'=>'${email}','resource'=>'/privacy-export-owned','dt'=>wp_slimstat::now()]); $stat=$db->insert_id;
        $db->insert($p.'slim_events', ['id'=>$stat,'notes'=>'privacy-export-owned-event','dt'=>wp_slimstat::now()]); $event=$db->insert_id;
      }
      echo wp_json_encode(compact('request','stat','event'));
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
      if (populated) {
        expect(report).toContain('/privacy-export-owned');
        expect(report).toContain('privacy-export-owned-event');
      }
      await expect(row.locator('.export-personal-data-failed:visible')).toHaveCount(0);
    } finally {
      runWordPressFixture(`<?php
        $db = wp_slimstat::$wpdb; $p = $GLOBALS['wpdb']->prefix;
        if (${fixture.event}) { $db->delete($p.'slim_events', ['event_id'=>${fixture.event}]); }
        if (${fixture.stat}) { $db->delete($p.'slim_stats', ['id'=>${fixture.stat}]); }
        $file = get_post_meta(${fixture.request}, '_export_file_name', true);
        if ($file && basename($file) === $file) { wp_delete_file(wp_privacy_exports_dir().$file); }
        wp_delete_post(${fixture.request}, true);
      `);
    }
  });
}
