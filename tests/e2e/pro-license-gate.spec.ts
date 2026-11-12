/**
 * Pro runs only with a verified license (task 4). Activating the Pro plugin is not entitlement:
 * every locked state detaches Pro and shows one banner that says why and links the fix, on every
 * SlimStat screen, the Dashboard and Plugins (task 5); a confirmed license survives a
 * license-server outage for 3 days, not longer; and locking never touches the site's Pro data
 * (goals stay as they were saved).
 *
 * The license server is answered by license-fixture-mu-plugin.php, so no run reaches the real one.
 */
import { fileURLToPath } from 'url';
import { test, expect } from '@playwright/test';
import type { Page, TestInfo } from '@playwright/test';
import { getProState, requireProBooted, setProLicense } from './helpers/pro-state';
import type { LicenseFixtureState } from './helpers/pro-state';
import { seedGoals } from './helpers/goals-funnels';
import { BASE_URL } from './helpers/env';
import { closeDb, getOptionValue, restoreOption, restoreSlimstatOptions, setOptionValue, setSlimstatSetting, snapshotOption, snapshotSlimstatOptions } from './helpers/setup';

const KEPT = ['slimstat_pro_license_verdict', 'slimstat_e2e_license_state', 'slimstat_goals', 'slimstat_goals_cache_ver'];
const SCREENS = ['/wp-admin/admin.php?page=slimview2', '/wp-admin/', '/wp-admin/plugins.php'];
const HREF: Record<string, RegExp> = {
  'Activate license': /\/wp-admin\/admin\.php\?page=slimconfig&tab=8$/,
  'Open License settings': /\/wp-admin\/admin\.php\?page=slimconfig&tab=8$/,
  'Find my license': /^https:\/\/my\.wp-slimstat\.com\/account\/\?utm_source=wp-slimstat&utm_medium=link&utm_campaign=license-activation&utm_content=license-banner-find-license$/,
  'View plans': /^https:\/\/wp-slimstat\.com\/pricing\/\?/,
};
const LOCKED: [LicenseFixtureState, string, string, string[]][] = [
  ['invalid', 'invalid', 'expired, revoked or not valid for this site', ['Open License settings', 'Find my license', 'View plans']],
  ['domain_not_activated', 'domain_not_activated', 'not activated for this site', ['Activate license', 'Find my license']],
  ['unreachable', 'unverified', 'could not verify your Pro license', ['Open License settings']],
  ['none', 'none', 'stay locked until you activate a license for this site', ['Activate license', 'Find my license', 'View plans']],
];

async function shot(page: Page, testInfo: TestInfo, name: string): Promise<void> {
  await page.screenshot({ path: process.env.E2E_SHOTS_DIR ? `${process.env.E2E_SHOTS_DIR}/${name}.png` : testInfo.outputPath(`${name}.png`) });
}

/** The SlimStat button roles (tokens.css), the same on SlimStat screens, the Dashboard and Plugins. */
const PRIMARY = { background: 'rgb(220, 50, 50)', border: 'rgb(220, 50, 50)', color: 'rgb(255, 255, 255)' };
const SECONDARY = { background: 'rgb(255, 255, 255)', border: 'rgb(140, 143, 148)', color: 'rgb(32, 34, 36)' };
const colors = (link: ReturnType<Page['locator']>) => link.evaluate(node => {
  const style = getComputedStyle(node);
  return { background: style.backgroundColor, border: style.borderTopColor, color: style.color };
});

/** Exactly one banner on the screen, with the state's text and exactly these actions, in order. */
async function expectBanner(page: Page, text: string, links: string[]): Promise<void> {
  const notice = page.locator('.slimstat-pro-license-notice:not(.slimstat-custom-db-notice)');
  await expect(notice).toHaveCount(1);
  await expect(notice).toContainText(text);
  await expect(notice).toContainText('Your Pro settings and data are kept.');
  // "SlimStat Pro" in the text opens the Pro overview in place; only the missing-key text bolds where to find the key.
  const message = notice.locator('p').first();
  const named = message.getByRole('link', { name: 'SlimStat Pro', exact: true });
  await expect(named).toHaveCount(text.includes('could not verify') ? 0 : 1);
  if (await named.count()) {
    await expect(named).toHaveAttribute('href', /\/wp-admin\/admin\.php\?page=slimpro$/);
    await expect(named).not.toHaveAttribute('target');
  }
  await expect(message.locator('strong')).toHaveText(text.includes('until you activate') ? ['find your license key'] : []);
  const actions = notice.locator('.slimstat-pro-license-notice__actions a');
  await expect(actions).toHaveCount(links.length);
  for (const [i, label] of links.entries()) {
    const link = actions.nth(i);
    expect(await colors(link), `${label} colours on ${page.url()}`).toEqual(i ? SECONDARY : PRIMARY);
    await expect(link).toContainText(label);
    await expect(link).toHaveAttribute('href', HREF[label]);
    // External destinations open in a new tab; License settings opens in place.
    if (label === 'Find my license' || label === 'View plans') await expect(link).toHaveAttribute('target', '_blank');
    else await expect(link).not.toHaveAttribute('target');
  }
  // Hover darkens the fill or the outline and keeps the text readable; keyboard focus shows WordPress's focus ring.
  await actions.first().hover();
  await expect.poll(() => colors(actions.first())).toMatchObject({ background: 'rgb(179, 16, 51)', color: 'rgb(255, 255, 255)' });
  if (links.length > 1) {
    await actions.nth(1).hover();
    await expect.poll(() => colors(actions.nth(1))).toMatchObject({ background: 'rgb(246, 247, 247)', color: 'rgb(17, 24, 39)' });
  }
  await page.mouse.move(0, 0);
  await actions.first().focus();
  expect(await actions.first().evaluate(node => getComputedStyle(node).boxShadow)).not.toBe('none');
  await actions.first().blur();
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

  for (const [fixture, state, text, links] of LOCKED) {
    test(`${fixture} license locks Pro and links the fix on every screen`, async ({ page }, testInfo) => {
      try {
        expect(await setProLicense(page, fixture)).toBe(state);
        expect((await getProState(page)).pro_booted, 'Pro features must be detached').toBe(false);
        for (const screen of SCREENS) {
          await page.goto(screen);
          await expectBanner(page, text, links);
          if (screen === SCREENS[0]) {
            await expect(page.locator('.slimstat-header__cta-label')).toHaveText(links[0]);
            await shot(page, testInfo, `license-${fixture}`);
          }
        }
        await shot(page, testInfo, `license-${fixture}-plugins`);
        // From a WordPress screen (Plugins, the last one visited), the fix opens the License section.
        await page.locator('.slimstat-pro-license-notice:not(.slimstat-custom-db-notice)').getByRole('link', { name: links[0] }).click();
        await expect(page).toHaveURL(/page=slimconfig&tab=8/);
        await expect(page.getByLabel('License key', { exact: true })).toBeVisible();
      } finally {
        await restoreSlimstatOptions().then(snapshotSlimstatOptions);
        expect(await setProLicense(page, 'valid')).toBe('valid');
      }
    });
  }

  test('valid license unlocks Pro with no banner on any screen', async ({ page }, testInfo) => {
    expect(await setProLicense(page, 'valid')).toBe('valid');
    expect((await getProState(page)).pro_booted).toBe(true);
    for (const screen of SCREENS) {
      await page.goto(screen);
      await expect(page.locator('.slimstat-pro-license-notice:not(.slimstat-custom-db-notice)')).toHaveCount(0);
    }
    await page.goto(SCREENS[0]);
    await shot(page, testInfo, 'license-valid');
  });

  test('users who cannot manage the license are told to ask an administrator, with no links', async ({ page, browser }) => {
    const author = await browser.newContext({ storageState: fileURLToPath(new URL('.auth/author.json', import.meta.url)) });
    try {
      expect(await setProLicense(page, 'invalid')).toBe('invalid');
      await setSlimstatSetting('capability_can_view', 'read');
      const view = await author.newPage();
      await view.goto(`${BASE_URL}${SCREENS[0]}`);
      const notice = view.locator('.slimstat-pro-license-notice:not(.slimstat-custom-db-notice)');
      await expect(notice).toHaveCount(1);
      await expect(notice).toContainText('until a site administrator activates the license');
      await expect(notice.locator('a')).toHaveCount(0);
      await view.goto(`${BASE_URL}/wp-admin/`);
      await expect(view.locator('.slimstat-pro-license-notice:not(.slimstat-custom-db-notice)')).toHaveCount(0);
    } finally {
      await author.close();
      await restoreSlimstatOptions().then(snapshotSlimstatOptions);
      expect(await setProLicense(page, 'valid')).toBe('valid');
    }
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
      const notice = page.locator('.slimstat-pro-license-notice:not(.slimstat-custom-db-notice)');
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
      // Activation clears every instance, not only the one on the screen that saved it.
      for (const screen of SCREENS) {
        await page.goto(screen);
        await expect(notice).toHaveCount(0);
      }
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
      await expect(page.locator('.slimstat-pro-license-notice:not(.slimstat-custom-db-notice)')).toBeVisible();
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
