import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';

const fixture = (body: string) => runWordPressFixture(`<?php
if (!preg_match('/^tests-/', DB_NAME)) throw new RuntimeException('Disposable DB required');
global $wpdb;
${body}
`);

test('Access Log keeps missing fingerprints in one visit and preserves identity boundaries', async ({ page }) => {
  test.setTimeout(90000);
  const marker = 'e2e-visit-grouping';
  try {
    fixture(`
      foreach (['/features/', '/missing-icon-192.png', '/missing-icon-32.png', '/blog/', '/contact/'] as $i => $resource) {
        if (false === $wpdb->insert($wpdb->prefix.'slim_stats', [
          'dt'=>time()-60+$i, 'visit_id'=>3100074, 'ip'=>'127.0.0.1', 'browser'=>'Safari',
          'browser_type'=>0, 'platform'=>'macos', 'resource'=>$resource, 'user_agent'=>'${marker}',
          'fingerprint'=>$i === 1 ? null : ($i === 2 ? '' : '91cc8a6e1234'),
          'content_type'=>in_array($i,[1,2]) ? '404' : 'page'
        ])) throw new RuntimeException($wpdb->last_error);
      }
    `);
    const url = '/wp-admin/admin.php?' + new URLSearchParams({ page: 'slimview1', 'fs[user_agent]': `equals ${marker}` });
    await page.goto(url);
    const log = page.locator('#slim_p7_02');
    await expect(log).toContainText('/contact/');
    await expect(log.locator('p.header')).toHaveCount(1);
    for (const resource of ['/features/', '/missing-icon-192.png', '/missing-icon-32.png', '/blog/', '/contact/']) {
      await expect(log).toContainText(resource);
    }
    for (const change of ["fingerprint='different'", 'visit_id=3100075', "ip='127.0.0.2'", "browser='Firefox'", "platform='win10'", "username='another visitor'"]) {
      fixture(`$wpdb->query("UPDATE {$wpdb->prefix}slim_stats SET fingerprint='91cc8a6e1234',visit_id=3100074,ip='127.0.0.1',browser='Safari',platform='macos',username='',${change} WHERE user_agent='${marker}' AND resource='/contact/'");`);
      await page.reload();
      await expect(log.locator('p.header')).toHaveCount(2);
    }
  } finally {
    fixture(`$wpdb->delete($wpdb->prefix.'slim_stats',['user_agent'=>'${marker}']);`);
  }
});
