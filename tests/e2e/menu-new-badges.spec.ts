/**
 * #20: sidebar items for new features carry a "New" pill for 15 days after the
 * feature first appears on the site, each on its own per-site anchor option, so
 * the windows run independently. Heatmaps also carries "Pro" while Pro is missing.
 *
 * Touches only the five anchor options and active_plugins, and restores them; no
 * stats rows are read or written.
 */
import { test, expect, Page } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';
import { closeDb, getPool, restoreOption, snapshotOption } from './helpers/setup';
import { requireProBooted } from './helpers/pro-state';

// Channels has no sidebar item of its own: it is the new report on Traffic Sources.
const FEATURES = {
  slimview5: 'slimstat_channels_since',
  slimview6: 'slimstat_goals_funnels_since',
  slimview7: 'slimstat_ecommerce_since',
  slimheatmap: 'slimstat_heatmaps_since',
  slimshortcodes: 'slimstat_shortcodes_since',
} as const;
type Screen = keyof typeof FEATURES;
const SCREENS = Object.keys(FEATURES) as Screen[];
const UNBADGED = ['slimview1', 'slimview2', 'slimview3', 'slimview4', 'slimemail', 'slimlayout', 'slimconfig'];
const DAY = 24 * 60 * 60;
// The SlimStat submenu is expanded only on SlimStat screens; Settings runs no report query.
const SETTINGS = '/wp-admin/admin.php?page=slimconfig';

const item = (page: Page, screen: string) => page.locator(`#adminmenu .wp-submenu a[href$="page=${screen}"]`);
const newPill = (page: Page, screen: string) => item(page, screen).locator('.slimstat-menu-new-badge');
const proPill = (page: Page, screen: string) => item(page, screen).locator('.slimstat-menu-pro-badge');

async function setAnchor(screen: Screen, daysAgo: number | null): Promise<void> {
  const option = FEATURES[screen];
  if (daysAgo === null) {
    await getPool().execute('DELETE FROM wp_options WHERE option_name = ?', [option]);
    return;
  }
  await getPool().execute(
    "INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, 'yes') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
    [option, String(Math.floor(Date.now() / 1000) - daysAgo * DAY)],
  );
}

async function anchorOf(screen: Screen): Promise<number | null> {
  const [rows] = await getPool().execute('SELECT option_value FROM wp_options WHERE option_name = ?', [FEATURES[screen]]) as any;
  return rows.length ? Number(rows[0].option_value) : null;
}

test.describe('Sidebar "New" badges (#20)', () => {
  test.beforeAll(async () => {
    await Promise.all(Object.values(FEATURES).map(snapshotOption));
  });

  test.afterAll(async () => {
    await Promise.all(Object.values(FEATURES).map(restoreOption));
    await closeDb();
  });

  test('fresh: all five items show "New" and each anchors its own window', async ({ page }) => {
    for (const screen of SCREENS) await setAnchor(screen, null);
    const before = Math.floor(Date.now() / 1000);

    await page.goto(SETTINGS);
    for (const screen of SCREENS) {
      await expect(newPill(page, screen), screen).toBeVisible();
      await expect(newPill(page, screen), screen).toHaveText('New');
      const anchor = await anchorOf(screen);
      expect(anchor, `${FEATURES[screen]} anchored on first build`).not.toBeNull();
      expect(anchor!).toBeGreaterThanOrEqual(before - 5);
    }
    for (const screen of UNBADGED) {
      await expect(item(page, screen), screen).toHaveCount(1);
      await expect(newPill(page, screen), screen).toHaveCount(0);
    }
    // The pill keeps the admin theme's colour.
    const style = await newPill(page, 'slimheatmap').evaluate((el) => {
      const s = getComputedStyle(el);
      return { bg: s.backgroundColor, color: s.color, transform: s.textTransform };
    });
    expect(style.bg).not.toBe('rgba(0, 0, 0, 0)');
    expect(style).toMatchObject({ color: 'rgb(255, 255, 255)', transform: 'uppercase' });
  });

  test('the badge style loads outside SlimStat screens too', async ({ page }) => {
    for (const screen of SCREENS) await setAnchor(screen, 1);
    await page.goto('/wp-admin/index.php');
    // Collapsed flyout: present and styled, though not on screen until hovered.
    for (const screen of SCREENS) await expect(newPill(page, screen), screen).toHaveCount(1);
    const display = await newPill(page, 'slimview7').evaluate((el) => getComputedStyle(el).display);
    expect(display).toBe('inline-block');
  });

  test('windows run independently: an elapsed window hides only its own badge', async ({ page }) => {
    await setAnchor('slimview6', 120); // Goals & Funnels shipped months earlier
    await setAnchor('slimheatmap', 16);
    await setAnchor('slimview7', 14);
    await setAnchor('slimview5', null);
    await setAnchor('slimshortcodes', null);
    const goalsAnchor = await anchorOf('slimview6');
    const ecommerceAnchor = await anchorOf('slimview7');

    await page.goto(SETTINGS);
    await expect(item(page, 'slimview6')).toHaveCount(1);
    await expect(newPill(page, 'slimview6')).toHaveCount(0);
    await expect(newPill(page, 'slimheatmap')).toHaveCount(0);
    for (const screen of ['slimview7', 'slimview5', 'slimshortcodes'] as Screen[]) {
      await expect(newPill(page, screen), screen).toBeVisible();
    }
    // Existing anchors are never moved.
    expect(await anchorOf('slimview6')).toBe(goalsAnchor);
    expect(await anchorOf('slimview7')).toBe(ecommerceAnchor);

    // Day 15 is the first day without the badge.
    await setAnchor('slimview7', 15);
    await page.goto(SETTINGS);
    await expect(newPill(page, 'slimview7')).toHaveCount(0);
  });

  test('page titles stay plain while the sidebar label carries the badge', async ({ page }) => {
    for (const screen of SCREENS) await setAnchor(screen, 1);
    for (const screen of SCREENS) {
      await page.goto(`/wp-admin/admin.php?page=${screen}`, { waitUntil: 'domcontentloaded' });
      await expect(newPill(page, screen), screen).toBeVisible();
      expect(await page.title(), screen).not.toMatch(/\bNew\b/i);
    }
  });

  test('without Pro, Heatmaps shows "New" then "Pro", and both fit the sidebar', async ({ page }, testInfo) => {
    await snapshotOption('active_plugins');
    try {
      runWordPressFixture("<?php deactivate_plugins('wp-slimstat-pro/wp-slimstat-pro.php', true);");
      await setAnchor('slimheatmap', 1);

      await page.goto(SETTINGS);
      const heatmaps = item(page, 'slimheatmap');
      await expect(newPill(page, 'slimheatmap')).toBeVisible();
      await expect(proPill(page, 'slimheatmap')).toBeVisible();
      await expect(heatmaps).toHaveText(/^Heatmaps\s*New\s*Pro$/);
      // Email Report keeps its "Pro" pill and gains no "New".
      await expect(proPill(page, 'slimemail')).toBeVisible();
      await expect(newPill(page, 'slimemail')).toHaveCount(0);

      // Both pills stay inside the sidebar: no horizontal overflow, nothing past its edge.
      const fit = await heatmaps.evaluate((a) => {
        const edge = document.getElementById('adminmenuwrap')!.getBoundingClientRect().right;
        const pills = Array.from(a.querySelectorAll('span')).map((s) => s.getBoundingClientRect().right);
        return { overflow: a.scrollWidth - a.clientWidth, past: pills.filter((r) => r > edge).length };
      });
      expect(fit).toEqual({ overflow: 0, past: 0 });
      await page.locator('#adminmenu li.wp-has-current-submenu').screenshot({ path: testInfo.outputPath('sidebar-no-pro.png') });

      // After its window, Heatmaps keeps only "Pro".
      await setAnchor('slimheatmap', 16);
      await page.goto(SETTINGS);
      await expect(newPill(page, 'slimheatmap')).toHaveCount(0);
      await expect(heatmaps).toHaveText(/^Heatmaps\s*Pro$/);
    } finally {
      await restoreOption('active_plugins');
    }
  });

  test('with Pro active, Heatmaps shows "New" and no "Pro"', async ({ page }, testInfo) => {
    await requireProBooted(page);
    await setAnchor('slimheatmap', 1);

    await page.goto(SETTINGS);
    await expect(newPill(page, 'slimheatmap')).toBeVisible();
    await expect(proPill(page, 'slimheatmap')).toHaveCount(0);
    await expect(proPill(page, 'slimemail')).toHaveCount(0);
    await page.locator('#adminmenu li.wp-has-current-submenu').screenshot({ path: testInfo.outputPath('sidebar-pro.png') });
  });
});
