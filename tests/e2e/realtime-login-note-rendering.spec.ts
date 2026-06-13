/**
 * E2E: Real-Time view (slim_p7_02) — login/logout note rendering (C1).
 *
 * The Pro User Overview addon writes login notes bracket-delimited
 * ([loggedin:user][...]); the free Real-Time parser used to explode(';') and
 * left stray brackets ([john]) and leaked other note segments. This spec seeds
 * the stored formats directly (so it runs with the free plugin alone — and
 * doubles as the cross-plugin regression when Pro is also active) and asserts
 * the rendered login entry shows a clean username.
 *
 * Data-safety: getPool() refuses to run against the live "local" DB.
 */
import { test, expect } from '@playwright/test';
import {
  getPool,
  closeDb,
  clearStatsTable,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

async function seedLoginNote(username: string, notes: string, visitId: number): Promise<void> {
  const dt = Math.floor(Date.now() / 1000) - 30; // recent so it lands in Real-Time
  await getPool().execute(
    `INSERT INTO wp_slim_stats (resource, dt, ip, visit_id, browser, platform, content_type, username, notes)
     VALUES ('/e2e-login-note', ?, '127.0.0.1', ?, 'Chrome', 'Windows', 'post', ?, ?)`,
    [dt, visitId, username, notes]
  );
}

test.describe('Real-Time login-note rendering (C1)', () => {
  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    await closeDb();
  });

  test('bracket + multi-segment login notes render without brackets or leaked tags', async ({ page }) => {
    // Current storage format (single + multi-segment) and a legacy semicolon row.
    await seedLoginNote('gerlando', '[loggedin:gerlando]', 101);
    await seedLoginNote('dorian', '[results:5][loggedin:dorian]', 102);
    await seedLoginNote('legacyuser', 'loggedin:legacyuser', 103);

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await expect(page.locator('#slim_p7_02')).toBeVisible({ timeout: 30_000 });

    const inside = page.locator('#slim_p7_02 .inside');
    const text = (await inside.innerText()).toLowerCase();

    // Usernames render cleanly...
    expect(text).toContain('gerlando');
    expect(text).toContain('dorian');
    expect(text).toContain('legacyuser');

    // ...with no stray brackets, no raw tag, and no leaked sibling segment.
    expect(text).not.toContain('[loggedin');
    expect(text).not.toContain('loggedin:');
    expect(text).not.toContain('[gerlando]');
    expect(text).not.toContain('results:5');
  });
});
