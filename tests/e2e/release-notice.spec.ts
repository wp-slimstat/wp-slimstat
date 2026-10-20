/** One per-user release window shared by core and SlimStat screens. */
import { test, expect, Page, Request } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { runWordPressFixture } from './helpers/chart';
import { ADMIN_USER, AUTHOR_USER } from './helpers/env';
import {
  closeDb, getPool, restoreOption, setOptionValue, snapshotOption,
  restoreSlimstatOptions, snapshotSlimstatOptions, setSlimstatOption,
} from './helpers/setup';
import { getProState, requireProBooted } from './helpers/pro-state';

const here = path.dirname(fileURLToPath(import.meta.url));
const ANCHOR = 'slimstat_release_60_since';
const DISMISS = 'slimstat_release_60_dismissed';
const PROBE = '_transient_slimstat_migration_probe';
const OPTIONS = [PROBE, '_transient_timeout_slimstat_migration_probe', 'slimstat_migration_dismissed'];
const DAY = 86400;
const PAGES = ['/wp-admin/index.php', '/wp-admin/plugins.php', '/wp-admin/admin.php?page=slimview2', '/wp-admin/admin.php?page=slimview5'];
const notice = (page: Page) => page.locator('#slimstat-notice-latest-news');
const now = () => Math.floor(Date.now() / 1000);
let admin: number, author: number;
let saved: any[] = [];
async function meta(user: number, key: string, value?: string | null): Promise<string | null> {
  const db = getPool();
  if (value !== undefined) {
    await db.execute('DELETE FROM wp_usermeta WHERE user_id=? AND meta_key=?', [user, key]);
    if (value !== null) await db.execute('INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (?,?,?)', [user,key,value]);
  }
  const [rows] = await db.execute('SELECT meta_value FROM wp_usermeta WHERE user_id=? AND meta_key=?', [user,key]) as any;
  return rows[0]?.meta_value ?? null;
}
async function simulateUpdate(page: Page, days?: number, migrationOwed = false) {
  await setSlimstatOption(page, 'notice_latest_news', 'on');
  for (const user of [admin, author]) {
    await meta(user, ANCHOR, days === undefined ? null : String(now() - days * DAY));
    await meta(user, DISMISS, null);
  }
  await setOptionValue(PROBE, migrationOwed ? 'dirty' : 'clean');
  await setOptionValue(OPTIONS[1], String(now() + DAY));
  if (migrationOwed) await setOptionValue(OPTIONS[2], null);
}

test.describe('6.0 release notice', () => {
  test.describe.configure({ mode: 'serial' });
  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
    for (const option of OPTIONS) await snapshotOption(option);
    const [users] = await getPool().execute('SELECT ID,user_login FROM wp_users WHERE user_login IN (?,?)', [ADMIN_USER,AUTHOR_USER]) as any;
    admin = users.find((u: any) => u.user_login === ADMIN_USER).ID;
    author = users.find((u: any) => u.user_login === AUTHOR_USER).ID;
    [saved] = await getPool().execute('SELECT user_id,meta_key,meta_value FROM wp_usermeta WHERE user_id IN (?,?) AND meta_key IN (?,?)', [admin,author,ANCHOR,DISMISS]) as any;
  });
  test.afterAll(async () => {
    for (const user of [admin, author]) for (const key of [ANCHOR, DISMISS]) await meta(user,key,null);
    for (const row of saved) await getPool().execute('INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (?,?,?)', [row.user_id,row.meta_key,row.meta_value]);
    await restoreSlimstatOptions();
    for (const option of OPTIONS) await restoreOption(option);
    await closeDb();
  });

  test('one compact bar shares its first-display time across Dashboard, Plugins and report pages', async ({ page }, info) => {
    await simulateUpdate(page);
    const before = now();
    const pro = (await getProState(page)).pro_activated;
    for (const url of PAGES) {
      await page.goto(url);
      await expect(notice(page)).toHaveCount(1);
      await expect(notice(page)).toContainText('New in SlimStat 6');
      for (const slug of ['slimview5','slimheatmap','slimshortcodes']) await expect(notice(page).locator(`a[href$="page=${slug}"]`)).toBeVisible();
      await expect(notice(page).getByRole('link', { name: 'Explore Pro', exact: true })).toHaveCount(pro ? 0 : 1);
    }
    const anchored = Number(await meta(admin,ANCHOR));
    expect(anchored).toBeGreaterThanOrEqual(before - 5);
    await page.reload();
    expect(Number(await meta(admin,ANCHOR))).toBe(anchored);
    await notice(page).screenshot({ path: info.outputPath('shared-release-bar.png') });
    await page.goto('/wp-admin/edit.php');
    await expect(notice(page)).toHaveCount(0);
  });

  test('core-screen bar sits below the page title, clear of Screen Options and Help', async ({ page }) => {
    await simulateUpdate(page);
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 900 });
      for (const url of PAGES.slice(0,2)) {
        await page.goto(url);
        const bar = (await notice(page).boundingBox())!;
        const title = (await page.locator('.wrap h1').first().boundingBox())!;
        expect(bar.y, `${url} @${width}`).toBeGreaterThanOrEqual(title.y + title.height);
        const meta = await page.locator('#screen-meta-links').boundingBox();
        if (meta) expect(bar.y >= meta.y + meta.height || bar.x + bar.width <= meta.x, `${url} @${width} overlaps Screen Options`).toBe(true);
      }
    }
  });

  for (const at of PAGES.slice(0,3)) {
    test(`keyboard dismissal at ${at} is one request and persists across placements`, async ({ page }) => {
      await simulateUpdate(page);
      await page.goto(at);
      const isDismissal = (r: Request) => r.url().includes('admin-ajax.php') && (r.postData() ?? '').includes('action=slimstat_notice_latest_news');
      let posts = 0;
      page.on('request', r => { if (isDismissal(r)) posts++; });
      const response = page.waitForResponse(r => isDismissal(r.request()));
      await notice(page).getByRole('button', { name: 'Dismiss SlimStat 6 announcement' }).focus();
      await page.keyboard.press('Enter');
      expect((await response).ok()).toBe(true);
      await expect(notice(page)).toHaveCount(0);
      expect(await meta(admin,DISMISS)).toBe('1');
      expect(await meta(author,DISMISS)).toBeNull();
      expect(runWordPressFixture("<?php echo get_option('slimstat_options')['notice_latest_news'];").trim()).toBe('on');
      for (const url of PAGES) { await page.goto(url); await expect(notice(page)).toHaveCount(0); }
      expect(posts).toBe(1);
    });
  }

  test('day 13 is visible and day 14 expires across all placements', async ({ page }) => {
    await simulateUpdate(page,13);
    await page.goto(PAGES[2]); await expect(notice(page)).toBeVisible();
    await simulateUpdate(page,14);
    for (const url of PAGES) { await page.goto(url); await expect(notice(page)).toHaveCount(0); }
  });
  test('migration defers first exposure', async ({ page }) => {
    await simulateUpdate(page,undefined,true);
    await page.goto(PAGES[0]);
    await expect(page.locator('.slimstat-migration-notice')).toBeVisible();
    await expect(notice(page)).toHaveCount(0);
    expect(await meta(admin,ANCHOR)).toBeNull();
  });
  test('another user has an independent window and no purchase action', async ({ page, browser }) => {
    await simulateUpdate(page);
    await setSlimstatOption(page,'capability_can_view','read');
    await meta(admin,DISMISS,'1');
    const context = await browser.newContext({ storageState: path.join(here,'.auth/author.json') });
    try {
      const p = await context.newPage();
      await p.goto(PAGES[0]); await expect(notice(p)).toHaveCount(0);
      expect(await meta(author,ANCHOR)).toBeNull();
      await p.goto(PAGES[2]); await expect(notice(p)).toBeVisible();
      await expect(notice(p).getByRole('link',{ name:'Explore Pro' })).toHaveCount(0);
      expect(await meta(author,ANCHOR)).not.toBeNull();
    } finally { await context.close(); }
  });
  test('new installs opt out while upgrades retain their existing setting', () => {
    expect(runWordPressFixture("<?php echo wp_slimstat::get_fresh_defaults()['notice_latest_news'], ',', wp_slimstat::init_options()['notice_latest_news'];").trim()).toBe('no,on');
  });
  test('Pro opens available features without a purchase action', async ({ page }) => {
    await requireProBooted(page); await simulateUpdate(page); await page.goto(PAGES[2]);
    await expect(notice(page).getByRole('link',{ name:'Open Heatmaps' })).toBeVisible();
    await expect(notice(page).locator('a[href*="page=slimpro"]')).toHaveCount(0);
  });
});
