import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { WP_ROOT, BASE_URL } from './helpers/env';
import { snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions,
  getPool, closeDb, installOptionMutator, uninstallOptionMutator } from './helpers/setup';
import { runWordPressFixture } from './helpers/chart';

test('head-loaded bundled tracker observes the root and starts one pageview', async ({ browser }) => {
  const context = await browser.newContext();
  const page = await context.newPage();
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  const bundle = fs.readFileSync(path.join(WP_ROOT, 'wp-content/plugins/wp-slimstat/wp-slimstat.min.js'), 'utf8');
  await page.route('**/*', route => route.request().isNavigationRequest()
    ? route.fulfill({ contentType: 'text/html', body: `<html><head><script>${bundle}</script><script>
      window.hostingPageviews=0;
      SlimStat._send_pageview=function(){ window.hostingPageviews++; };
      </script></head><body><p>Head fixture</p></body></html>` }) : route.abort());
  try {
    await page.goto(`${BASE_URL}/hosting-head-fixture`);
    expect(errors).toEqual([]);
    await expect.poll(() => page.evaluate(() => (window as any).hostingPageviews)).toBe(1);
    await page.evaluate(() => { document.head.appendChild(document.createElement('meta')); document.body.appendChild(document.createElement('div')); });
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => (window as any).hostingPageviews)).toBe(1);
  } finally { await context.close(); }
});

test('banner hidden states do not intercept form controls or keyboard focus', async ({ page }) => {
  const css = fs.readFileSync(path.join(WP_ROOT, 'wp-content/plugins/wp-slimstat/assets/css/gdpr-banner.css'), 'utf8');
  for (const width of [1280, 390]) {
    await page.setViewportSize({ width, height: 800 });
    await page.setContent(`<style>${css}</style><label>Donation<select id="donation"><option>10</option><option>20</option></select></label>
      <div id="slimstat-gdpr-banner"><button id="accept">Accept</button><button id="decline">Decline</button></div>`);
    const banner = page.locator('#slimstat-gdpr-banner');
    await expect(banner).toBeHidden();
    await page.locator('#donation').selectOption('20');
    await page.locator('#donation').focus();
    await page.keyboard.press('Tab');
    await expect(page.locator('#accept')).not.toBeFocused();
    await banner.evaluate(el => el.classList.add('show'));
    await expect(banner).toBeVisible();
    await expect(page.locator('#accept')).toBeEnabled();
    await page.locator('#accept').focus();
    await page.keyboard.press('Tab');
    await expect(page.locator('#decline')).toBeFocused();
    expect((await banner.boundingBox())!.width).toBeLessThanOrEqual(720);
    await page.locator('#donation').selectOption('10');
    await banner.evaluate(el => el.classList.add('hiding'));
    await expect(banner).toBeHidden();
    expect(await banner.evaluate(el => getComputedStyle(el).pointerEvents)).toBe('none');
  }
});

test.describe('hosting report and attribution contracts', () => {
  test.beforeAll(async () => { await snapshotSlimstatOptions(); await installOptionMutator(); });
  test.afterEach(async () => { await restoreSlimstatOptions(); });
  test.afterAll(async () => { await uninstallOptionMutator(); await closeDb(); });

  for (const mode of ['on', 'off']) {
    test(`resource normalization preserves acquisition in javascript_mode=${mode}`, async ({ page, browser }) => {
      await setSlimstatOptions(page, { javascript_mode: mode, gdpr_enabled: 'off', anonymous_tracking: 'off',
        ignore_resource_parameters: 'fbclid,utm_source', ignore_users: '', ignore_ips: '', is_tracking: 'on' });
      const marker = `normalized-${mode}-${Date.now()}`;
      const context = await browser.newContext({ javaScriptEnabled: mode === 'on', storageState: { cookies: [], origins: [] } });
      try {
        const visitor = await context.newPage();
        for (const click of ['a', 'b']) {
          await visitor.goto(`${BASE_URL}/?hosting=${marker}&fbclid=${click}&utm_source=newsletter&utm_medium=email&product=7`);
          await expect.poll(async () => {
            const [rows]: any = await getPool().query('SELECT COUNT(*) AS n FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]);
            return Number(rows[0].n);
          }).toBe(click === 'a' ? 1 : 2);
        }
        const [rows]: any = await getPool().query('SELECT resource,utm_source,traffic_channel FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]);
        expect(new Set(rows.map((r: any) => String(r.resource))).size).toBe(1);
        for (const row of rows) {
          expect(String(row.resource)).not.toMatch(/fbclid|utm_source/);
          expect(String(row.resource)).toContain('product=7');
          expect(String(row.utm_source)).toBe('newsletter');
          expect(String(row.traffic_channel)).toBe('email');
        }
      } finally { await context.close(); }
    });
  }

  test('granularity uses current form filters and comparison-off removes previous series', async ({ page }) => {
    await setSlimstatOptions(page, { comparison_chart: 'no', async_load: 'no' });
    const marker = `hosting-chart-${Date.now()}`;
    runWordPressFixture(`<?php
      foreach ([['Chrome', 1768046400], ['Chrome', 1768132800], ['Firefox', 1768046400], ['Chrome', 1767787200]] as $i => $row) {
        $GLOBALS['wpdb']->insert($GLOBALS['wpdb']->prefix . 'slim_stats', ['dt'=>$row[1], 'browser'=>$row[0], 'ip'=>'192.0.2.' . ($i+1), 'resource'=>'/${marker}', 'visit_id'=>700000+$i]);
      }
    `);
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    try {
      await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2&type=custom&from=2026-01-10&to=2026-01-12&fs[resource]=equals+%2F${marker}`);
      const element = page.locator('[id^="slimstat_chart_data_"]').first();
      await expect(element).toBeAttached();
      expect(JSON.parse((await element.getAttribute('data-prev-data'))!).datasets).toEqual([]);
      await page.evaluate(() => {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = 'fs[browser]'; input.value = 'equals Chrome'; input.className = 'slimstat-post-filter';
        document.querySelector('#slimstat-filters-form')!.appendChild(input);
      });
      const response = page.waitForResponse(r => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('slimstat_fetch_chart_data'));
      await page.locator('.slimstat-granularity-select').first().selectOption('hourly');
      const payload = await (await response).json();
      expect(payload.success).toBe(true);
      expect(payload.data.args.filters.browser).toEqual(['equals', 'Chrome']);
      expect(payload.data.totals.current.v1).toBe(2);
      expect(payload.data.totals.previous).toBeUndefined();
      expect(payload.data.prev_data.datasets).toEqual([]);
      await expect(page.locator('.slimstat-toggle-prev-datasets')).toHaveCount(0);
      await page.locator('.slimstat-postbox-chart--item').first().click();
      expect(errors).toEqual([]);
    } finally {
      await getPool().execute('DELETE FROM wp_slim_stats WHERE resource = ?', [`/${marker}`]);
    }
  });
});

test.describe('server-only visit identity', () => {
  test.beforeAll(() => installOptionMutator());
  test.beforeEach(async () => { await snapshotSlimstatOptions(); });
  test.afterEach(async () => { await restoreSlimstatOptions(); });
  test.afterAll(async () => { uninstallOptionMutator(); await closeDb(); });

  for (const cookies of ['on', 'off']) {
    test(`server pageviews allocate visits with cookies ${cookies}`, async ({ browser }) => {
      await setSlimstatOptions({ javascript_mode: 'off', gdpr_enabled: 'off', anonymous_tracking: 'off', set_tracker_cookie: cookies });
      const context = await browser.newContext({ javaScriptEnabled: false });
      const page = await context.newPage();
      const marker = `hosting-server-${cookies}-${Date.now()}`;
      try {
        for (const n of [1, 2]) await page.goto(`${BASE_URL}/?e2e=${marker}-${n}`);
        const [rows]: any = await getPool().execute('SELECT visit_id FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id', [`%${marker}%`]);
        expect(rows).toHaveLength(2);
        for (const row of rows) expect(Number(row.visit_id)).toBeGreaterThan(0);
        if (cookies === 'on') expect(rows[0].visit_id).toBe(rows[1].visit_id);
        const stored = (await context.cookies()).filter(cookie => cookie.name === 'slimstat_tracking_code');
        expect(stored.length).toBe(cookies === 'on' ? 1 : 0);
      } finally { await context.close(); }
    });
  }
});
