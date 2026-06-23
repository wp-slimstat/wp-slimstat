/**
 * E2E (Fix 1, #273 follow-up) — negative case: a lower-privileged role does NOT
 * get the Access Log "edit user profile" pencil for a user it cannot edit.
 *
 * get_edit_profile_link() delegates to get_edit_user_link(), which returns ''
 * when the current user lacks edit_user on the target — so the pencil must be
 * absent. We seed a pageview attributed to the administrator and view the
 * Access Log as the author: an author cannot edit the admin, so no pencil.
 *
 * Runs under the Playwright `author` project (storage state = author.json).
 * Guarded with test.skip so it is a no-op if executed under the admin project.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL, ADMIN_USER } from './helpers/env';
import {
  closeDb,
  clearStatsTable,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  seedAuthoredPageview,
} from './helpers/setup';

test.describe('Access Log author pencil — negative (author role) — #273', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
  });

  test.beforeEach(async () => {
    await clearStatsTable();
    // Attribute the row to the administrator: an author cannot edit the admin.
    await seedAuthoredPageview(ADMIN_USER, '/e2e-author-pencil-neg');
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    await closeDb();
  });

  test('author sees no profile-edit pencil for a user it cannot edit', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'author', 'author-project only');

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, { waitUntil: 'domcontentloaded' });

    // If the author role can't reach the SlimStat report at all, that's also a
    // valid "no pencil" outcome — nothing to assert further.
    if (!(await page.locator('#slim_p7_02').isVisible())) {
      return;
    }

    // Report is visible to this role, but the admin row must carry no pencil.
    await expect(page.locator('#slim_p7_02 a.slimstat-author-profile-link')).toHaveCount(0);
  });
});
