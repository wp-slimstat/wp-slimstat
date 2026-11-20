import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { WP_ROOT } from './helpers/env';
import { requireProBooted } from './helpers/pro-state';
import { snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions, closeDb } from './helpers/setup';

test.afterAll(closeDb);

test('heatmap page lists survive save and enable only matching pages', async ({ page, browser }) => {
  await requireProBooted(page);
  await snapshotSlimstatOptions();
  const fixture = path.join(WP_ROOT, 'wp-content/mu-plugins/issue420-pages.php');
  const visitor = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  try {
    fs.writeFileSync(fixture, `<?php
add_action('template_redirect', static function () {
    if (strpos($_SERVER['REQUEST_URI'], '/issue420-') !== 0) return;
    status_header(200);
    ?><!doctype html><html><head><?php wp_head(); ?></head><body>Heatmap page-list fixture<?php wp_footer(); ?></body></html><?php
    exit;
});`);
    await setSlimstatOptions({ is_tracking: 'on', javascript_mode: 'on', gdpr_enabled: 'off', heatmap_capture: 'full', heatmap_rate: '10000' });
    const paths = '/issue420-one/*\n/issue420-two/*';
    await page.goto('/wp-admin/admin.php?page=slimconfig&tab=7');
    await page.locator('#heatmap_pages').fill(paths);
    await page.locator('input.slimstat-settings-button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');
    await page.reload();
    await expect(page.locator('#heatmap_pages')).toHaveValue(paths);

    const publicPage = await visitor.newPage();
    for (const resource of ['/issue420-one/', '/issue420-two/item/?ref=nav', '/issue420-outside/']) {
      await publicPage.goto(resource);
      const params = await publicPage.evaluate(() => (window as any).SlimStatParams);
      expect(params, 'tracker must load even when heatmaps are excluded').toBeTruthy();
      expect(params.hm ?? null, resource).toEqual(resource === '/issue420-outside/' ? null : { l: 'full', r: 10000 });
    }
  } finally {
    await visitor.close();
    fs.rmSync(fixture, { force: true });
    await restoreSlimstatOptions();
  }
});
