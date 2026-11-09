/**
 * Pro runs only with a verified license (task 4). Activating the Pro plugin is not entitlement:
 * every locked state detaches Pro, says why in the SlimStat header and links the fix; a confirmed
 * license survives a license-server outage for 3 days, not longer; and locking never touches the
 * site's Pro data (goals stay as they were saved).
 *
 * The license server is answered by license-fixture-mu-plugin.php, so no run reaches the real one.
 */
import { test, expect } from '@playwright/test';
import type { Page, TestInfo } from '@playwright/test';
import { getProState, requireProBooted, setProLicense } from './helpers/pro-state';
import type { LicenseFixtureState } from './helpers/pro-state';
import { seedGoals } from './helpers/goals-funnels';
import { closeDb, getOptionValue, restoreOption, restoreSlimstatOptions, setOptionValue, snapshotOption, snapshotSlimstatOptions } from './helpers/setup';

const KEPT = ['slimstat_pro_license_verdict', 'slimstat_e2e_license_state', 'slimstat_goals', 'slimstat_goals_cache_ver'];
const LOCKED: [LicenseFixtureState, string, string, string][] = [
  ['invalid', 'invalid', 'expired, revoked or not valid for this site', 'Open License settings'],
  ['domain_not_activated', 'domain_not_activated', 'not activated for this site', 'Activate license'],
  ['unreachable', 'unverified', 'could not verify your Pro license', 'Open License settings'],
  ['none', 'none', 'stay locked until you activate a license for this site', 'Activate license'],
];

async function shot(page: Page, testInfo: TestInfo, name: string): Promise<void> {
  await page.screenshot({ path: process.env.E2E_SHOTS_DIR ? `${process.env.E2E_SHOTS_DIR}/${name}.png` : testInfo.outputPath(`${name}.png`) });
}

test.describe.serial('Pro license gate', () => {
  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
    for (const name of KEPT) await snapshotOption(name);
  });

  test.afterAll(async () => {
    for (const name of KEPT) await restoreOption(name);
    await restoreSlimstatOptions();
    await closeDb();
  });

  test.beforeEach(async ({ page }) => {
    await requireProBooted(page);
  });

  for (const [fixture, state, text, label] of LOCKED) {
    test(`${fixture} license locks Pro and links the fix`, async ({ page }, testInfo) => {
      try {
        expect(await setProLicense(page, fixture)).toBe(state);
        expect((await getProState(page)).pro_booted, 'Pro features must be detached').toBe(false);
        await page.goto('/wp-admin/admin.php?page=slimview2');
        const notice = page.locator('.slimstat-pro-license-notice');
        await expect(notice).toContainText(text);
        await expect(notice).toContainText('Your Pro settings and data are kept.');
        await expect(page.locator('.slimstat-header__cta-label')).toHaveText(label);
        await shot(page, testInfo, `license-${fixture}`);
        await notice.getByRole('link', { name: label }).click();
        await expect(page).toHaveURL(/page=slimconfig&tab=8/);
        await expect(page.getByLabel('License key', { exact: true })).toBeVisible();
      } finally {
        await restoreSlimstatOptions().then(snapshotSlimstatOptions);
        expect(await setProLicense(page, 'valid')).toBe('valid');
      }
    });
  }

  test('valid license unlocks Pro with no notice', async ({ page }, testInfo) => {
    expect(await setProLicense(page, 'valid')).toBe('valid');
    expect((await getProState(page)).pro_booted).toBe(true);
    await page.goto('/wp-admin/admin.php?page=slimview2');
    await expect(page.locator('.slimstat-pro-license-notice')).toHaveCount(0);
    await shot(page, testInfo, 'license-valid');
  });

  test('an outage keeps a confirmed license for 3 days, not longer', async ({ page }) => {
    try {
      expect(await setProLicense(page, 'valid')).toBe('valid');
      // Confirmed 13 h ago, so the refresh is due, and the license server is down.
      expect(await setProLicense(page, 'unreachable', { keep: true, age: 13 * 3600 })).toBe('valid');
      expect((await getProState(page)).pro_booted).toBe(true);
      // Confirmed 73 h ago: past the grace period.
      expect(await setProLicense(page, 'unreachable', { keep: true, age: 60 * 3600 })).toBe('unverified');
      expect((await getProState(page)).pro_booted).toBe(false);
    } finally {
      expect(await setProLicense(page, 'valid')).toBe('valid');
    }
  });

  test('saving License settings unlocks at once once the license is renewed', async ({ page }, testInfo) => {
    // Pro 3.0.0 never reports its answers to SlimStat, so its save cannot unlock; the 12-hour refresh does.
    const reports = (await getProState(page)).pro_version !== '3.0.0';
    try {
      expect(await setProLicense(page, 'invalid')).toBe('invalid');
      // Renewed on the license server; nothing has asked it again yet.
      await setOptionValue('slimstat_e2e_license_state', 'valid');
      await page.goto('/wp-admin/admin.php?page=slimconfig&tab=8');
      const notice = page.locator('.slimstat-pro-license-notice');
      await expect(notice).toBeVisible();
      await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Save Changes' }).first().click()]);
      if (!reports) {
        await expect(notice).toBeVisible();
        expect(await setProLicense(page, 'valid', { keep: true, age: 13 * 3600 })).toBe('valid');
        await page.goto('/wp-admin/admin.php?page=slimconfig&tab=8');
      }
      await expect(notice).toHaveCount(0);
      expect((await getProState(page)).pro_license).toBe('valid');
      await shot(page, testInfo, 'license-saved-unlocked');
    } finally {
      expect(await setProLicense(page, 'valid')).toBe('valid');
    }
  });

  test('locking and relicensing never pauses saved goals', async ({ page }, testInfo) => {
    const goals = ['Pricing View', 'Signup Page', 'Docs Visit'];
    await seedGoals(goals.map((name, i) => ({ id: 101 + i, name, dimension: 'resource', operator: 'contains', value: `/${i}`, active: true })));
    const saved = await getOptionValue('slimstat_goals');
    try {
      expect(await setProLicense(page, 'invalid')).toBe('invalid');
      await page.goto('/wp-admin/admin.php?page=slimview6');
      await expect(page.locator('.slimstat-pro-license-notice')).toBeVisible();
      await expect(page.locator('.slimstat-gf-goal').first()).toBeVisible();
      await shot(page, testInfo, 'license-locked-goals');
      expect(await getOptionValue('slimstat_goals'), 'Viewing goals while locked must not rewrite them').toBe(saved);

      expect(await setProLicense(page, 'valid')).toBe('valid');
      await page.goto('/wp-admin/admin.php?page=slimview6');
      await expect(page.locator('.slimstat-gf-goal[data-active="true"]')).toHaveCount(goals.length);
      expect(await getOptionValue('slimstat_goals')).toBe(saved);
    } finally {
      expect(await setProLicense(page, 'valid')).toBe('valid');
    }
  });
});
