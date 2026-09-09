/**
 * E2E: User Overview (slim_p8_01) — Login Tracking & Display
 *
 * Tests that the User Overview panel correctly:
 * 1. Records login notes via cookie or username fallback
 * 2. Displays Last Login, Login Count, and handles zero registration dates
 * 3. Shows all users regardless of date filter
 */
import { test, expect } from '@playwright/test';
import {
  getPool,
  closeDb,
  clearStatsTable,
  setSlimstatSetting,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  enableDisableWpCron,
  restoreWpConfig,
  ensureWpUser,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';
import { requireProBooted } from './helpers/pro-state';

// ─── DB helpers ──────────────────────────────────────────────────

async function seedPageviewWithNotes(
  username: string,
  notes: string | null,
  dt: number,
  visitId: number = 1
): Promise<number> {
  const [result] = (await getPool().execute(
    `INSERT INTO wp_slim_stats (resource, dt, ip, visit_id, browser, platform, content_type, username, notes)
     VALUES ('/e2e-user-overview', ?, '127.0.0.1', ?, 'Chrome', 'Windows', 'post', ?, ?)`,
    [dt, visitId, username, notes]
  )) as any;
  return result.insertId;
}

async function getLoginNotes(): Promise<any[]> {
  const [rows] = (await getPool().execute(
    "SELECT id, notes, username, dt FROM wp_slim_stats WHERE notes LIKE '%loggedin:%' ORDER BY id DESC"
  )) as any;
  return rows;
}

async function deleteTransientCaches(): Promise<void> {
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name LIKE '%slimstat_query_%'"
  );
}

// The subject of this spec is a second, non-admin-named WordPress user: it seeds login notes
// for that user and reads them back out of the User Overview panel. `gerlando` existed on one
// developer's install and nowhere else, so on any other machine the login below never reached
// wp-admin and all ten tests died on the 45 s navigation wait rather than on their subject
// (H-LOGIN, uncapped census Run 65). The spec provisions the user in beforeAll now, and its
// ID -- which the seeded `[user:N]` notes carry -- comes back from that call instead of being
// the 6 it happened to be on that machine.
const TEST_USER = 'gerlando';
const TEST_USER_PASS = 'gerlando';
let testUserId = 0;

/** The notes string the plugin writes for this user; `[user:N]` must name the real row. */
function userNote(prefix: string = ''): string {
  return `${prefix}[user:${testUserId}]`;
}

async function loginAsGerlando(
  browser: import('@playwright/test').Browser
): Promise<{
  context: import('@playwright/test').BrowserContext;
  page: import('@playwright/test').Page;
}> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const page = await context.newPage();
  await page.goto(`${BASE_URL}/wp-login.php`, {
    waitUntil: 'domcontentloaded',
  });
  await page.fill('#user_login', TEST_USER);
  await page.fill('#user_pass', TEST_USER_PASS);
  await page.click('#wp-submit');
  await page.waitForURL('**/wp-admin/**', {
    timeout: 45_000,
    waitUntil: 'domcontentloaded',
  });
  return { context, page };
}

async function getUserOverviewData(page: import('@playwright/test').Page) {
  await expect(page.locator('#slim_p8_01 table')).toBeVisible();
  return page.evaluate(() => {
    const panel = document.getElementById('slim_p8_01');
    if (!panel) return null;
    const table = panel.querySelector('table');
    if (!table) return null;
    const rows = table.querySelectorAll('tbody tr');
    const users: Record<string, Record<string, string>> = {};
    const headers = [
      'username',
      'fullName',
      'email',
      'registered',
      'lastLogin',
      'pageviews',
      'loginCount',
      'timeOnSite',
    ];
    rows.forEach((row) => {
      const cells = row.querySelectorAll('td');
      const data: Record<string, string> = {};
      cells.forEach((c, i) => (data[headers[i] || String(i)] = c.textContent!.trim()));
      if (data.username) users[data.username] = data;
    });
    return { totalRows: rows.length, users };
  });
}

// ─── Shared setup/teardown ───────────────────────────────────────

test.describe('User Overview (slim_p8_01)', () => {
  test.setTimeout(90_000);

  // Every assertion below belongs to Pro's UserOverviewAddon (report slim_p8_01 and the
  // [loggedin:…] note it writes). Six of these failed for a whole census against a Pro
  // that was switched on and dead, and the spec had no way to say so. Now it does: this
  // skips only where Pro is absent from the machine, and FAILS where Pro is installed
  // but never booted.
  test.beforeAll(async ({ browser }) => {
    const probe = await browser.newPage();
    try {
      await requireProBooted(probe);
    } finally {
      await probe.close();
    }
    enableDisableWpCron();
    testUserId = await ensureWpUser(TEST_USER, TEST_USER_PASS);
    await snapshotSlimstatOptions();
    await setSlimstatSetting('gdpr_enabled', 'off');
    await setSlimstatSetting('is_tracking', 'on');
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    restoreWpConfig();
    await closeDb();
  });

  // ─── Login Tracking ──────────────────────────────────────────

  test.describe('Login Tracking', () => {
    test.beforeEach(async () => {
      await clearStatsTable();
      await deleteTransientCaches();
    });

    test('login note is written via username fallback when cookie is absent', async ({
      browser,
    }) => {
      const now = Math.floor(Date.now() / 1000);
      await seedPageviewWithNotes('gerlando', userNote(), now);

      const { context, page } = await loginAsGerlando(browser);
      await page.waitForTimeout(2000);

      const loginNotes = await getLoginNotes();
      expect(loginNotes.length).toBeGreaterThanOrEqual(1);

      const note = loginNotes.find(
        (row: any) => row.notes && row.notes.includes('loggedin:gerlando')
      );
      expect(note).toBeDefined();
      expect(note.notes).toContain('[loggedin:gerlando]');
      expect(note.notes).not.toContain(';loggedin:gerlando');

      await context.close();
    });

    test('login note is NOT written when no recent pageview exists', async ({
      browser,
    }) => {
      const { context, page } = await loginAsGerlando(browser);
      await page.waitForTimeout(2000);

      const loginNotes = await getLoginNotes();
      const staleNotes = loginNotes.filter(
        (row: any) =>
          row.notes &&
          row.notes.includes('[loggedin:gerlando]') &&
          row.resource === '/e2e-user-overview'
      );
      expect(staleNotes.length).toBe(0);

      await context.close();
    });

    test('login note is NOT written to stale pageview (>1 hour old)', async ({
      browser,
    }) => {
      const twoHoursAgo = Math.floor(Date.now() / 1000) - 7200;
      const rowId = await seedPageviewWithNotes('gerlando', userNote(), twoHoursAgo);

      const { context, page } = await loginAsGerlando(browser);
      await page.waitForTimeout(2000);

      const [rows] = (await getPool().execute(
        'SELECT notes FROM wp_slim_stats WHERE id = ?',
        [rowId]
      )) as any;
      expect(rows[0].notes).toBe(userNote());
      expect(rows[0].notes).not.toContain('loggedin:');

      await context.close();
    });

    test('duplicate login notes are prevented', async ({ browser }) => {
      const now = Math.floor(Date.now() / 1000);
      await seedPageviewWithNotes('gerlando', '[loggedin:gerlando]', now);

      const { context, page } = await loginAsGerlando(browser);
      await page.waitForTimeout(2000);

      const [rows] = (await getPool().execute(
        "SELECT notes FROM wp_slim_stats WHERE notes LIKE '%loggedin:gerlando%' AND resource = '/e2e-user-overview'"
      )) as any;

      for (const row of rows) {
        const count = (row.notes.match(/\[loggedin:gerlando\]/g) || []).length;
        expect(count).toBeLessThanOrEqual(1);
      }

      await context.close();
    });
  });

  // ─── Panel Display ─────────────────────────────────────────────

  test.describe('Panel Display', () => {
    test('Audience page renders User Overview with correct columns', async ({
      browser,
    }) => {
      const { context, page } = await loginAsGerlando(browser);
      await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview3`, {
        waitUntil: 'domcontentloaded',
      });

      await expect(page.locator('#slim_p8_01 table')).toBeVisible();
      const columns = await page.evaluate(() => {
        const panel = document.getElementById('slim_p8_01');
        if (!panel) return [];
        const ths = panel.querySelectorAll('thead th');
        return Array.from(ths).map((th) => th.textContent!.trim());
      });

      expect(columns).toContain('Username');
      expect(columns).toContain('Full Name');
      expect(columns).toContain('Email');
      expect(columns).toContain('Registered');
      expect(columns).toContain('Last Login');
      expect(columns).toContain('Pageviews');
      expect(columns).toContain('Login Count');
      expect(columns).toContain('Time on Site');

      await context.close();
    });

    test('Last Login and Login Count show data when login notes exist', async ({
      browser,
    }) => {
      await clearStatsTable();
      await deleteTransientCaches();

      const now = Math.floor(Date.now() / 1000);
      await seedPageviewWithNotes('gerlando', userNote('[loggedin:gerlando]'), now, 1);
      await seedPageviewWithNotes('gerlando', userNote('[loggedin:gerlando]'), now - 3600, 2);
      await seedPageviewWithNotes('gerlando', userNote('[loggedin:gerlando]'), now - 7200, 3);

      const { context, page } = await loginAsGerlando(browser);
      await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview3`, {
        waitUntil: 'domcontentloaded',
      });

      const data = await getUserOverviewData(page);
      expect(data).not.toBeNull();
      expect(data!.users['gerlando']).toBeDefined();

      const gerlando = data!.users['gerlando'];
      expect(gerlando.lastLogin).not.toBe('Never');
      expect(gerlando.loginCount).not.toBe('-');
      expect(parseInt(gerlando.loginCount)).toBeGreaterThan(0);

      await context.close();
    });

    test('zero registration date displays as dash', async ({ browser }) => {
      const { context, page } = await loginAsGerlando(browser);
      await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview3`, {
        waitUntil: 'domcontentloaded',
      });

      const hasZeroDate = await page.evaluate(() => {
        const panel = document.getElementById('slim_p8_01');
        if (!panel) return false;
        const cells = panel.querySelectorAll('tbody td');
        return Array.from(cells).some(
          (c) => c.textContent!.trim() === '0000-00-00 00:00:00'
        );
      });

      expect(hasZeroDate).toBe(false);
      await context.close();
    });
  });

  // ─── Date Filter Regression ────────────────────────────────────

  test.describe('Date Filter Regression', () => {
    test('user with pageviews appears with type=today filter', async ({
      browser,
    }) => {
      await clearStatsTable();
      await deleteTransientCaches();

      const now = Math.floor(Date.now() / 1000);
      for (let i = 0; i < 5; i++) {
        await seedPageviewWithNotes('gerlando', userNote(), now - i * 60, i + 1);
      }

      const { context, page } = await loginAsGerlando(browser);
      await page.goto(
        `${BASE_URL}/wp-admin/admin.php?page=slimview3&type=today`,
        { waitUntil: 'domcontentloaded' }
      );

      const data = await getUserOverviewData(page);
      expect(data).not.toBeNull();
      expect(data!.users['gerlando']).toBeDefined();
      expect(parseInt(data!.users['gerlando'].pageviews)).toBeGreaterThanOrEqual(5);

      await context.close();
    });

    test('user appears even when filtered to a different date (zero-pageviews set)', async ({
      browser,
    }) => {
      await clearStatsTable();
      await deleteTransientCaches();

      const yesterday = Math.floor(Date.now() / 1000) - 86400;
      await seedPageviewWithNotes('gerlando', userNote(), yesterday);

      const { context, page } = await loginAsGerlando(browser);
      await page.goto(
        `${BASE_URL}/wp-admin/admin.php?page=slimview3&type=today`,
        { waitUntil: 'domcontentloaded' }
      );

      const data = await getUserOverviewData(page);
      expect(data).not.toBeNull();
      expect(data!.users['gerlando']).toBeDefined();

      await context.close();
    });

    test('all site users appear with empty stats table', async ({
      browser,
    }) => {
      await clearStatsTable();
      await deleteTransientCaches();

      const { context, page } = await loginAsGerlando(browser);
      await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview3`, {
        waitUntil: 'domcontentloaded',
      });

      const data = await getUserOverviewData(page);
      expect(data).not.toBeNull();
      expect(data!.totalRows).toBeGreaterThan(0);
      expect(data!.users['gerlando']).toBeDefined();

      await context.close();
    });
  });
});
