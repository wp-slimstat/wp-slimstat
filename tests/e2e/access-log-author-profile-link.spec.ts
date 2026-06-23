/**
 * E2E (Fix 1, #273 follow-up): the Access Log author cell renders a working
 * "edit user profile" pencil for administrators.
 *
 * Before the fix, get_edit_profile_link() was only wired into the standard
 * reports table (raw_results_to_html), never into the Access Log view
 * (right-now.php) — so the pencil never appeared and "clicking it" did nothing.
 *
 * This admin-project spec seeds a pageview attributed to a known WP user and
 * asserts the Access Log row exposes an <a.slimstat-author-profile-link> that
 * links to that user's profile-edit screen (admins can edit any user). The
 * negative case (a lower-privileged role sees no pencil) lives in
 * access-log-author-profile-link.author.spec.ts.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import {
  closeDb,
  clearStatsTable,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  seedAuthoredPageview,
} from './helpers/setup';

const KNOWN_LOGIN = process.env.WP_AUTHOR_USER ?? 'dordane';

test.describe('Access Log author profile pencil — #273', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
  });

  test.beforeEach(async () => {
    await clearStatsTable();
    await seedAuthoredPageview(KNOWN_LOGIN, '/e2e-author-pencil');
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    await closeDb();
  });

  test('admin sees a profile-edit pencil linking to the author user-edit screen', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#slim_p7_02')).toBeVisible({ timeout: 30_000 });

    const pencil = page.locator('#slim_p7_02 a.slimstat-author-profile-link').first();
    await expect(pencil).toBeVisible({ timeout: 30_000 });

    // Editing another user routes to user-edit.php?user_id=N (self would be profile.php).
    const href = await pencil.getAttribute('href');
    expect(href, 'pencil must link to a WP user-edit screen').toMatch(/(user-edit\.php\?user_id=\d+|profile\.php)/);

    // The glyph is rendered inside the anchor (clickable, not an empty element).
    await expect(pencil.locator('i.slimstat-font-edit')).toHaveCount(1);
  });
});
