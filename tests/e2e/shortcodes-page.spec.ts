import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';
import { closeDb, getPool, snapshotOption, restoreOption } from './helpers/setup';
import { BASE_URL } from './helpers/env';

const response = (page: any) => page.waitForResponse((r: any) => /shortcode(%2F|\/)preview/.test(r.url()));

test('Playground builds, previews, copies and handles errors without stale responses', async ({ page, context }, testInfo) => {
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  const pool = getPool();
  const marker = `shortcode-${Date.now()}`;
  const [stat] = await pool.execute('INSERT INTO wp_slim_stats (dt, resource, utm_campaign, utm_source, traffic_channel) VALUES (UNIX_TIMESTAMP(), ?, ?, ?, ?)', ['/shortcode-fixture', marker, marker, 'email']) as any;
  try {
    await page.goto('/wp-admin/admin.php?page=slimshortcodes');
    await expect(page.getByRole('link', { name: 'Shortcodes', exact: true })).toBeVisible();
    await expect(page.locator('#ss-sc-title')).toHaveText('Visitors online now');
    await expect(page.locator('#ss-sc-preview')).toHaveText(/\d/);
    await page.locator('#ss-sc-search').fill('utm_source');
    await expect(page.locator('#ss-sc-rail button')).toHaveCount(1);
    let pending = response(page);
    await page.locator('#ss-sc-rail button').click(); await pending;
    // Filter to the fixture: a populated site ranks one row below its top 10.
    await page.locator('#ss-sc-add-filter').click();
    await page.getByLabel('Filter column').selectOption('utm_source');
    await page.getByLabel('Filter operator').selectOption('equals');
    pending = response(page);
    await page.getByLabel('Filter value').fill(marker); await pending;
    await expect(page.locator('#ss-sc-code')).toHaveValue(new RegExp(`utm_source equals ${marker}`));
    await expect(page.locator('#ss-sc-preview')).toContainText(marker);
    pending = response(page);
    await page.locator('#ss-sc-display').selectOption('count'); await pending;
    await expect(page.locator('#ss-sc-code')).toHaveValue(/f="count"/);
    await expect(page.locator('#ss-sc-code')).not.toHaveValue(/limit_results/);
    pending = response(page);
    await page.locator('#ss-sc-period').selectOption('-7'); await pending;
    await expect(page.locator('#ss-sc-code')).toHaveValue(/interval equals -7/);
    if (await page.evaluate(() => !!navigator.clipboard)) {
      await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    } else {
      await page.locator('#ss-sc-copy').click();
      await expect(page.locator('#ss-sc-code-message')).toContainText('selected');
      expect(await page.locator('#ss-sc-code').evaluate((el: HTMLTextAreaElement) => el.selectionEnd - el.selectionStart)).toBe((await page.locator('#ss-sc-code').inputValue()).length);
      // Exercise the success feedback on HTTP-only LocalWP too; secure CI uses the native clipboard.
      await page.evaluate(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async (value: string) => { (window as any).shortcodeClipboard = value; } } }));
    }
    await page.locator('#ss-sc-copy').click();
    await expect(page.locator('#ss-sc-copy')).toHaveText('Copied');
    pending = response(page);
    await page.locator('#ss-sc-code').fill(`[slimstat f="recent" w="utm_campaign"]utm_source equals ${marker}[/slimstat]`); await pending;
    await expect(page.locator('#ss-sc-manual')).toBeVisible();
    await expect(page.locator('#ss-sc-preview')).toContainText(marker);
    // Let an older, real response arrive after a newer one. No sleeps or invented data.
    let release!: () => void;
    let arrived!: () => void;
    const held = new Promise<void>(resolve => { release = resolve; });
    const firstArrived = new Promise<void>(resolve => { arrived = resolve; });
    let first = true;
    await page.route(/shortcode(%2F|\/)preview/, async route => {
      const result = await route.fetch();
      if (first) { first = false; arrived(); await held; }
      await route.fulfill({ response: result });
    });
    await page.locator('#ss-sc-code').fill(`[slimstat f="recent" w="utm_source"]utm_source equals ${marker}[/slimstat]`);
    await firstArrived;
    pending = response(page);
    await page.locator('#ss-sc-code').fill(`[slimstat f="count" w="utm_source"]utm_source equals ${marker}[/slimstat]`); await pending;
    await expect(page.locator('#ss-sc-preview')).toHaveText('1');
    pending = response(page); release(); await (await pending).finished();
    await page.evaluate(() => new Promise(requestAnimationFrame));
    await expect(page.locator('#ss-sc-preview')).toHaveText('1');
    await page.unroute(/shortcode(%2F|\/)preview/);
    pending = response(page);
    await page.locator('#ss-sc-code').fill('[slimstat f="oops" w="ip"]'); await pending;
    await expect(page.locator('#ss-sc-code-message')).toContainText('attribute: f');
    await expect(page.locator('#ss-sc-preview')).toBeEmpty();
    await page.locator('#ss-sc-reset').click();
    await page.locator('#ss-sc-search').fill('no-such-shortcode');
    await expect(page.locator('#ss-sc-no-results')).toContainText('No shortcode matches');
    await page.locator('#ss-sc-search').press('Escape');
    await page.locator('#ss-sc-rail button').first().focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.locator('#ss-sc-rail button').nth(1)).toBeFocused();
    pending = response(page); await page.keyboard.press('Enter'); await pending;
    await page.locator('#ss-sc-code').focus(); await page.keyboard.press('Tab');
    await expect(page.locator('#ss-sc-run')).toBeFocused();
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: testInfo.outputPath('desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 800, height: 1000 });
    await expect(page.locator('#ss-sc-select')).toBeVisible();
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: testInfo.outputPath('tablet.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await expect(page.locator('#ss-sc-rail')).toBeHidden();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await page.screenshot({ path: testInfo.outputPath('mobile.png'), fullPage: true });
    expect(errors).toEqual([]);
  } finally { await pool.execute('DELETE FROM wp_slim_stats WHERE id = ?', [stat.insertId]); await closeDb(); }
});

test('Free samples, persistent hints and frontend privacy', async ({ page, browser }, testInfo) => {
  await snapshotOption('active_plugins');
  let post = 0;
  try {
    post = Number(runWordPressFixture(`<?php
      deactivate_plugins('wp-slimstat-pro/wp-slimstat-pro.php');
      echo wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Shortcode privacy fixture','post_content'=>'[slimstat f="recent" w="ip"] [slimstat f="widget" w="slim_heatmap_01"]']);`));
    expect(post).toBeGreaterThan(0);
    await page.goto('/wp-admin/admin.php?page=slimshortcodes');
    await page.locator('#ss-sc-search').fill('slim_heatmap_01');
    let pending = response(page); await page.locator('#ss-sc-rail button').click(); await pending;
    await expect(page.locator('#ss-sc-sample')).toHaveText('Sample data');
    await expect(page.locator('#ss-sc-preview table')).toBeVisible();
    await expect(page.locator('#ss-sc-unlock')).toContainText('Pro adds heatmaps');
    await expect(page.locator('#ss-sc-unlock a.button-primary')).toHaveAttribute('href', /utm_content=panel/);
    await expect(page.locator('#ss-sc-unlock').getByRole('link', { name: 'Compare Free and Pro' })).toHaveAttribute('href', /page=slimpro/);
    await expect(page.locator('dialog[open]')).toHaveCount(0);
    await page.screenshot({ path: testInfo.outputPath('free-sample.png'), fullPage: true });
    await page.evaluate(() => (window as any).deleteUserSetting('slimstat_sc_hint_slim_p9_01'));
    await page.reload();
    await page.locator('#ss-sc-search').fill('slim_p9_01');
    pending = response(page); await page.locator('#ss-sc-rail button').click(); await pending;
    await expect(page.locator('#ss-sc-hint')).toContainText('Pro adds funnels');
    await page.getByRole('button', { name: 'Dismiss Pro hint' }).click();
    await page.reload(); await page.locator('#ss-sc-search').fill('slim_p9_01');
    pending = response(page); await page.locator('#ss-sc-rail button').click(); await pending;
    await expect(page.locator('#ss-sc-hint')).toBeHidden();
    await page.goto(`${BASE_URL}/?page_id=${post}`);
    await expect(page.locator('.slimstat-shortcode-notice')).toContainText('needs SlimStat Pro');
    const guest = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      const visitor = await guest.newPage();
      await visitor.goto(`${BASE_URL}/?page_id=${post}`);
      await expect(visitor.locator('.col-ip, .slimstat-shortcode-notice')).toHaveCount(0);
      const denied = await visitor.request.post(`${BASE_URL}/?rest_route=/slimstat/v1/shortcode/preview`, { data: { shortcode: '[slimstat f="recent" w="ip"]' } });
      expect([401, 403]).toContain(denied.status());
    } finally { await guest.close(); }
  } finally {
    if (post) runWordPressFixture(`<?php wp_delete_post(${post}, true);`);
    await restoreOption('active_plugins'); await closeDb();
  }
});

test('RTL layout keeps native controls and code usable', async ({ page }, testInfo) => {
  const pool = getPool();
  const marker = `shortcode-rtl-${Date.now()}`;
  const [stat] = await pool.execute('INSERT INTO wp_slim_stats (dt, resource, utm_campaign) VALUES (UNIX_TIMESTAMP(), ?, ?)', ['/shortcode-rtl', marker]) as any;
  try {
    // Use WordPress's shipped RTL styles, just as a Persian locale does.
    await page.route(/\/wp-admin\/(css\/[^/]+\.css|load-styles\.php)/, async route => {
      const url = new URL(route.request().url());
      if (url.pathname.endsWith('load-styles.php')) url.searchParams.set('dir', 'rtl');
      else url.pathname = url.pathname.replace(/(?<!-rtl)(\.min)?\.css$/, '-rtl$1.css');
      await route.continue({ url: url.href });
    });
    await page.goto('/wp-admin/admin.php?page=slimshortcodes');
    await page.evaluate(() => { document.documentElement.dir = 'rtl'; document.documentElement.lang = 'fa-IR'; document.body.classList.add('rtl'); });
    await page.locator('#ss-sc-search').fill('utm_campaign');
    let pending = response(page); await page.locator('#ss-sc-rail button').click(); await pending;
    pending = response(page);
    await page.locator('#ss-sc-code').fill(`[slimstat f="recent" w="utm_campaign"]utm_campaign equals ${marker}[/slimstat]`); await pending;
    await expect(page.locator('#ss-sc-preview')).toContainText(marker);
    await expect(page.locator('#ss-sc-code')).toHaveAttribute('dir', 'ltr');
    expect(await page.locator('.ss-sc').evaluate(el => getComputedStyle(el).direction)).toBe('rtl');
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: testInfo.outputPath('rtl.png'), fullPage: true });
  } finally { await pool.execute('DELETE FROM wp_slim_stats WHERE id = ?', [stat.insertId]); await closeDb(); }
});
