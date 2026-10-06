/**
 * QA C2: without Pro, the locked "View heatmap" opened a modal that showed nothing of the page.
 * It now opens the page's own most clicked links and buttons under its row, with what Pro adds.
 *
 * Seeds one pageview and four clicks on a page of its own, and removes them afterwards.
 */
import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';
import { closeDb, getPool, restoreOption, snapshotOption } from './helpers/setup';

const PAGE = '/e2e-heatmap-preview';

test('Free: View heatmap lists the page\'s most clicked links and buttons inline', async ({ page }, testInfo) => {
  const pool = getPool();
  const now = Math.floor(Date.now() / 1000);
  await snapshotOption('active_plugins');
  const [stat] = await pool.query(
    "INSERT INTO wp_slim_stats (resource, dt, ip, visit_id, browser, platform, content_type, resolution) VALUES (?, ?, '127.0.0.1', 1, 'Chrome', 'Windows', 'page', '1280x720')",
    [PAGE, now],
  ) as any;
  try {
    // One label at three spots counts once, as three clicks.
    const clicks = [['Start free trial', '120,340'], ['Start free trial', '122,338'], ['Start free trial', '640,90'], ['Pricing', '900,20']];
    await pool.query('INSERT INTO wp_slim_events (id, type, notes, position, dt) VALUES ?', [clicks.map(([text, position]) => [stat.insertId, 0, JSON.stringify({ text, type: 'click' }), position, now])]);
    runWordPressFixture("<?php deactivate_plugins('wp-slimstat-pro/wp-slimstat-pro.php');");

    await page.goto('/wp-admin/admin.php?page=slimheatmap&type=last_7_days');
    // A list that lands after the click re-renders the rows, and the preview with them.
    const refreshed = page.waitForResponse((r) => /heatmap(%2F|\/)pages/.test(r.url()) && r.url().includes('refresh=1'));
    await page.locator('.ss-hm-refresh').click();
    await refreshed;
    await page.getByRole('searchbox', { name: 'Search pages' }).fill(PAGE);
    const row = page.locator('.ss-hm-table tbody tr').filter({ hasText: PAGE });
    const view = row.getByRole('button', { name: 'View heatmap' });
    await expect(view).toHaveAttribute('aria-expanded', 'false');
    await view.click();

    const preview = page.getByRole('region', { name: `Most clicked links and buttons on ${PAGE}` });
    await expect(preview.getByRole('listitem')).toHaveText([/Start free trial\s*3 clicks/, /Pricing\s*1 click/]);
    await expect(preview).toContainText('how far visitors scroll');
    await expect(preview.getByRole('link', { name: 'Get SlimStat Pro' })).toBeVisible();
    await expect(view).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('dialog[open]')).toHaveCount(0);
    await page.locator('.ss-hm-table-wrap').screenshot({ path: testInfo.outputPath('free-preview.png') });

    await view.click();
    await expect(preview).toHaveCount(0);
    await expect(view).toHaveAttribute('aria-expanded', 'false');
  } finally {
    await pool.execute('DELETE FROM wp_slim_events WHERE id = ?', [stat.insertId]);
    await pool.execute('DELETE FROM wp_slim_stats WHERE id = ?', [stat.insertId]);
    // The list caches its rows per range; drop the copy that still holds this page.
    await pool.execute("DELETE FROM wp_options WHERE option_name REGEXP '^_transient_(timeout_)?slimstat_hm_pages_'");
    await restoreOption('active_plugins');
    await closeDb();
  }
});
