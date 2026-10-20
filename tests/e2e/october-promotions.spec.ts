import { test, expect } from '@playwright/test';
import { snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions, closeDb, getPool, snapshotOption, restoreOption } from './helpers/setup';
import { getProState } from './helpers/pro-state';
import { ADMIN_USER } from './helpers/env';

test('promotion impressions are visible, session-deduplicated and local; clicks retain their origin', async ({ page }) => {
  const full = (await getProState(page)).pro_activated;
  await page.goto('/wp-admin/admin.php?page=slimview1');
  if (full) { await expect(page.locator('a[href*="utm_campaign=barchart"]')).toHaveCount(0); return; }
  await page.evaluate(() => {
    for (const key of Object.keys(sessionStorage)) if (key.startsWith('slimstat-promotions:')) sessionStorage.removeItem(key);
  });
  const external: string[] = [];
  page.on('request', r => { if (/wp-slimstat\.com/.test(r.url())) external.push(r.url()); });
  await page.reload();
  const counts = () => page.evaluate(() => {
    const key = Object.keys(sessionStorage).find(k => k.startsWith('slimstat-promotions:'));
    return key ? JSON.parse(sessionStorage.getItem(key)!) as Record<string, number> : {};
  });
  const preview = page.locator('a[href*="utm_campaign=barchart"][href*="utm_content=live-chart"]').first();
  await preview.scrollIntoViewIfNeeded();
  await expect(preview).toBeVisible();
  await expect.poll(async () => Object.keys(await counts()).filter(k => k.includes(':barchart:live-chart:') && k.endsWith(':impression')).length).toBe(1);
  const key = Object.keys(await counts()).find(k => k.includes(':barchart:live-chart:') && k.endsWith(':impression'))!;
  expect((await counts())[key]).toBe(1);
  await page.reload(); await preview.scrollIntoViewIfNeeded();
  await page.waitForTimeout(200);
  expect((await counts())[key]).toBe(1);
  await page.evaluate(() => document.addEventListener('click', event => {
    if ((event.target as Element).closest('a[href*="utm_campaign=barchart"]')) event.preventDefault();
  }, true));
  await preview.click();
  expect((await counts())[key.replace(/impression$/, 'click')]).toBe(1);
  expect(external).toEqual([]);
  await page.goto('/wp-admin/admin.php?page=slimpro&utm_campaign=export&utm_content=slim_p1_12#ss-pro-share');
  for (const link of await page.locator('.ss-pro-cta').all()) {
    const url = new URL((await link.getAttribute('href'))!);
    expect(url.hostname).toBe('my.wp-slimstat.com');
    expect(url.searchParams.get('utm_source')).toBe('wp-slimstat');
    expect(url.searchParams.get('utm_medium')).toBe('link');
    expect(url.searchParams.get('utm_campaign')).toBe('export');
    expect(url.searchParams.get('utm_content')).toBe('slim_p1_12');
  }
});

test('Free settings previews are non-editable and report previews fit narrow and RTL layouts', async ({ page }) => {
  const full = (await getProState(page)).pro_activated;
  if (full) {
    await page.goto('/wp-admin/admin.php?page=slimconfig&tab=8');
    await expect(page.getByText('Enter your license key', { exact:true })).toBeVisible();
    await expect(page.getByText('Your current Free settings stay unchanged.')).toHaveCount(0);
    return;
  }
  await snapshotSlimstatOptions();
  try {
    await setSlimstatOptions({ notice_latest_news: 'on' });
    for (const tab of [7, 8]) {
      await page.goto(`/wp-admin/admin.php?page=slimconfig&tab=${tab}`);
      await expect(page.locator('#slimstat-options-panel')).toHaveCount(0);
      await expect(page.locator('input[type=password], input[name*=license_key]')).toHaveCount(0);
    }
    for (const width of [1440, 768, 390]) {
      await page.setViewportSize({ width, height:900 });
      for (const id of ['slimview1','slimview5','slimheatmap']) {
        await page.goto(`/wp-admin/admin.php?page=${id}`);
        await expect(page.locator('.slimstat-release-bar')).toHaveCount(1);
        if (id === 'slimview1') {
          const preview = page.locator('.slimstat-preview');
          const bounds = (await preview.boundingBox())!;
          const panel = (await page.locator('.live-analytics-chart--locked').boundingBox())!;
          const action = (await preview.locator('.slimstat-upgrade-action').boundingBox())!;
          expect(bounds.width).toBeGreaterThan(panel.width * 0.8);
          expect(action.y).toBeGreaterThanOrEqual(bounds.y);
          expect(action.y + action.height).toBeLessThanOrEqual(bounds.y + bounds.height + 1);
        }
        for (const direction of ['ltr','rtl']) {
          await page.evaluate(dir => { document.documentElement.dir=dir; }, direction);
          expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth+1), `${id} ${width} ${direction}`).toBe(true);
        }
      }
    }
  } finally { await restoreSlimstatOptions(); await closeDb(); }
});

test('post and page heatmap shortcuts respect publication state and disabled columns', async ({ page }) => {
  const { runWordPressFixture } = await import('./helpers/chart');
  await snapshotSlimstatOptions();
  const ids = JSON.parse(runWordPressFixture(`<?php
    if (DB_NAME !== 'tests-wordpress') throw new RuntimeException('Disposable database required');
    $ids=[];
    foreach (['post','page'] as $type) foreach (['publish','draft','private'] as $status) {
      $ids[] = wp_insert_post(['post_title'=>'October heatmap shortcut '.$type.' '.$status,'post_type'=>$type,'post_status'=>$status]);
    }
    echo wp_json_encode($ids);
  `)) as number[];
  try {
    await setSlimstatOptions({ add_posts_column: 'on' });
    const full = (await getProState(page)).pro_activated;
    for (const [type,offset] of [['post',0],['page',3]] as const) {
      await page.goto(`/wp-admin/edit.php?post_type=${type}&s=October+heatmap+shortcut`);
      const link = page.locator(`#post-${ids[offset]}`).getByRole('link',{ name:'View heatmap',exact:true });
      await expect(link).toBeVisible();
      await expect(link).toHaveAttribute('title', /Recorded activity is required/);
      await expect(link).toHaveAttribute('href', full ? /page=slimheatmap.*heatmap=/ : /page=slimpro.*utm_campaign=heatmaps.*utm_content=post-list#ss-pro-converts$/);
      for (const delta of [1,2]) await expect(page.locator(`#post-${ids[offset+delta]}`).getByRole('link',{ name:'View heatmap' })).toHaveCount(0);
    }
    await setSlimstatOptions({ add_posts_column: 'no' });
    await page.reload();
    await expect(page.getByRole('link',{ name:'View heatmap',exact:true })).toHaveCount(0);
  } finally {
    runWordPressFixture(`<?php foreach (${JSON.stringify(ids)} as $id) wp_delete_post($id,true);`);
    await restoreSlimstatOptions(); await closeDb();
  }
});

test('the Free Dashboard preview preserves saved Access Log data and the widget preference', async ({ page }) => {
  const { runWordPressFixture } = await import('./helpers/chart');
  const pool=getPool();
  await snapshotSlimstatOptions(); await snapshotOption('active_plugins');
  const [users]=await pool.execute('SELECT ID FROM wp_users WHERE user_login=?',[ADMIN_USER]) as any;
  const user=Number(users[0].ID), pattern='%meta-box-order_%slimlayout%';
  const [saved]=await pool.execute('SELECT meta_key,meta_value FROM wp_usermeta WHERE user_id=? AND meta_key LIKE ?',[user,pattern]) as any;
  const resource='/october-dashboard-access-log';
  try {
    await pool.execute('DELETE FROM wp_usermeta WHERE user_id=? AND meta_key LIKE ?',[user,pattern]);
    runWordPressFixture(`<?php deactivate_plugins('wp-slimstat-pro/wp-slimstat-pro.php',true); update_user_option(${user},'meta-box-order_slimstat_page_slimlayout',['dashboard'=>'slim_p7_02'],true);`);
    await setSlimstatOptions({add_dashboard_widgets:'on',notice_latest_news:'no'});
    await pool.execute('INSERT INTO wp_slim_stats (dt,resource,ip,browser,platform) VALUES (?,?,?, ?,?)',[Math.floor(Date.now()/1000)-60,resource,'127.0.0.1','Firefox','linux']);
    await page.goto('/wp-admin/index.php');
    await expect(page.locator('#slim_p7_02 a[href*="october-dashboard-access-log"]').first()).toBeAttached();
    await expect(page.locator('#slim_p7_02 .slimstat-preview')).toBeAttached();
    await setSlimstatOptions({add_dashboard_widgets:'no'}); await page.reload();
    await expect(page.locator('#slim_p7_02')).toHaveCount(0);
  } finally {
    await pool.execute('DELETE FROM wp_slim_stats WHERE resource=?',[resource]);
    await pool.execute('DELETE FROM wp_usermeta WHERE user_id=? AND meta_key LIKE ?',[user,pattern]);
    for(const row of saved) await pool.execute('INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (?,?,?)',[user,row.meta_key,row.meta_value]);
    await restoreOption('active_plugins'); await restoreSlimstatOptions(); await closeDb();
  }
});
