/**
 * E2E QA — Access Log colour legend (#impeccable: legend clarity).
 *
 * The legend's 5 colour swatches used to strand on the far side of the row from
 * their labels (the global .little-color-box float:left detached them). Each
 * swatch is now grouped with its label inside .slimstat-legend-item, with a
 * hover title, laid out as a wrapping flex row.
 *
 * The "legend" describe is READ-ONLY (it never seeds/clears the DB), so it is
 * safe to run against any site. The "row colours" describe seeds rows and must
 * run on a throwaway DB (wp-env / playground) or with ALLOW_LIVE_DB=1.
 */
import { test, expect, Page } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { getPool, closeDb, clearStatsTable, seedAuthoredPageview } from './helpers/setup';

const SLIMVIEW1 = `${BASE_URL}/wp-admin/admin.php?page=slimview1`;

// label → expected swatch colour (admin.scss is-* rules; gray $grayFive=#f8f8f8,
// bot has no class so it uses the base #eee).
const LEGEND = [
  { label: 'From search result page', rgb: 'rgb(226, 219, 255)' },
  { label: 'Has Left Comments',       rgb: 'rgb(255, 233, 200)' },
  { label: 'WP User',                 rgb: 'rgb(221, 240, 255)' },
  { label: 'Other Human',             rgb: 'rgb(248, 248, 248)' },
  { label: 'Bot or Crawler',          rgb: 'rgb(238, 238, 238)' },
];

async function openAccessLog(page: Page): Promise<void> {
  await page.goto(SLIMVIEW1, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#slim_p7_02')).toBeVisible({ timeout: 30_000 });
}

test.describe('Access Log colour legend — clarity (read-only)', () => {
  test.setTimeout(60_000);

  test('each swatch is paired with its label, correctly coloured, and titled', async ({ page }) => {
    await openAccessLog(page);
    const legend = page.locator('#slim_p7_02 .slimstat-access-log-legend');
    await expect(legend).toBeVisible();
    await expect(legend.locator('.slimstat-legend-item')).toHaveCount(5);

    for (const { label, rgb } of LEGEND) {
      const item = legend.locator('.slimstat-legend-item', { hasText: label });
      await expect(item).toHaveCount(1);
      const swatch = item.locator('.little-color-box');

      // Colour shown correctly + readable (has a border).
      await expect(swatch).toHaveCSS('background-color', rgb);
      await expect(swatch).toHaveCSS('border-style', 'solid');
      // Tooltip text accurate.
      await expect(swatch).toHaveAttribute('title', label);

      // Adjacency (the fix): swatch sits at the item's left, same row, label follows.
      const sb = await swatch.boundingBox();
      const ib = await item.boundingBox();
      expect(sb).not.toBeNull();
      expect(ib).not.toBeNull();
      expect(sb!.x).toBeLessThan(ib!.x + 16); // swatch is the item's left edge
      expect(ib!.width).toBeGreaterThan(sb!.width + 10); // the label text follows it
      const sMid = sb!.y + sb!.height / 2;
      expect(sMid).toBeGreaterThan(ib!.y - 2);
      expect(sMid).toBeLessThan(ib!.y + ib!.height + 2); // swatch and label share the row
    }
  });

  test('legend wraps cleanly with no overlap at a narrow width', async ({ page }) => {
    await page.setViewportSize({ width: 700, height: 900 });
    await openAccessLog(page);
    const legend = page.locator('#slim_p7_02 .slimstat-access-log-legend');
    const items = legend.locator('.slimstat-legend-item');
    await expect(items).toHaveCount(5);

    // Every item stays intact (swatch + label on one row) and within the legend box.
    const legendBox = await legend.boundingBox();
    const count = await items.count();
    for (let i = 0; i < count; i++) {
      const box = await items.nth(i).boundingBox();
      expect(box).not.toBeNull();
      expect(box!.x).toBeGreaterThanOrEqual(legendBox!.x - 1);
      expect(box!.x + box!.width).toBeLessThanOrEqual(legendBox!.x + legendBox!.width + 1);
    }
  });
});

test.describe('Access Log row colours — per visitor type (seeds; throwaway DB only)', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await clearStatsTable();
    // A logged-in WP user → is-known-user; a commenter (username, no `user:` note)
    // → is-known-visitor; a bot (browser_type=1) and a direct human (browser_type=0).
    await seedAuthoredPageview('e2e_wp_user', '/legend-wp-user'); // username + [user:..] note
    const now = Math.floor(Date.now() / 1000);
    const insert = (cols: Record<string, unknown>) => {
      const keys = Object.keys(cols);
      return getPool().query(
        `INSERT INTO wp_slim_stats (${keys.join(', ')}) VALUES (${keys.map(() => '?').join(', ')})`,
        Object.values(cols),
      );
    };
    await insert({ resource: '/legend-commenter', dt: now - 50, ip: '10.0.0.2', visit_id: 0, browser: 'Chrome', platform: 'Windows', username: 'e2e_commenter', notes: '[ip:10.0.0.2]' });
    await insert({ resource: '/legend-bot', dt: now - 40, ip: '10.0.0.3', visit_id: 0, browser: 'Googlebot', platform: 'Linux', browser_type: 1 });
    await insert({ resource: '/legend-direct', dt: now - 30, ip: '10.0.0.4', visit_id: 0, browser: 'Chrome', platform: 'Windows', browser_type: 0 });
  });

  test.afterAll(async () => {
    await closeDb();
  });

  test('each visitor type gets its matching header highlight colour', async ({ page }) => {
    await openAccessLog(page);
    await expect(page.locator('#slim_p7_02 p.header.is-known-user')).toHaveCount(1);   // WP user
    await expect(page.locator('#slim_p7_02 p.header.is-known-visitor')).toHaveCount(1); // commenter
    await expect(page.locator('#slim_p7_02 p.header.is-direct')).toHaveCount(1);        // direct human
    // The bot row's header carries none of the human/user highlight classes.
    const botRow = page.locator('#slim_p7_02 p.header', { hasText: '/legend-bot' });
    await expect(botRow).not.toHaveClass(/is-known-user|is-known-visitor|is-search-engine|is-direct/);
  });
});
