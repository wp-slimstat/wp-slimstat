/**
 * Settings redesign (2026-11-13): goal-based tabs must not change a single saved byte,
 * old links to moved settings still land on them, search never submits the form,
 * the save bar tracks unsaved changes, and completing the privacy review writes one key.
 */
import { test, expect, type Page } from '@playwright/test';
import { unserialize as phpUnserialize } from 'php-serialize';
import { setSettingsToggle } from './helpers/settings';
import { snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions, getPool, closeDb } from './helpers/setup';
import { BASE_URL } from './helpers/env';

const tabUrl = (tab: number, hash = '') => `${BASE_URL}/wp-admin/admin.php?page=slimconfig&tab=${tab}${hash}`;

async function rawOptions(): Promise<string> {
  const [rows]: any = await getPool().query("SELECT option_value FROM wp_options WHERE option_name='slimstat_options'");
  return String(rows[0].option_value);
}

async function save(page: Page): Promise<void> {
  await page.locator('input.slimstat-settings-button[type="submit"]').click();
  await page.waitForLoadState('load');
  // Tab 2 reports a scheduled geolocation download instead when the DB-IP file is missing (fresh sites).
  await expect(page.locator('.slimstat-notice').filter({ hasText: /Your new settings have been saved|geolocation database update has been scheduled/ })).toBeVisible();
}

test.describe('Settings redesign keeps every saved setting', () => {
  test.describe.configure({ mode: 'serial' });
  test.beforeEach(async () => { await snapshotSlimstatOptions(); });
  test.afterEach(async () => { await restoreSlimstatOptions(); });
  test.afterAll(async () => { await closeDb(); });

  test('saving any tab without changes leaves slimstat_options byte-identical', async ({ page }) => {
    // First save of each tab: every stored value survives. Normalisation may only add keys.
    for (const tab of [1, 9, 2, 3, 4, 5, 6, 10]) {
      const before = phpUnserialize(await rawOptions()) as Record<string, unknown>;
      await page.goto(tabUrl(tab));
      await save(page);
      const after = phpUnserialize(await rawOptions()) as Record<string, unknown>;
      for (const [key, value] of Object.entries(before)) {
        expect(after[key], `first save of tab ${tab} changed ${key}`).toEqual(value);
      }
    }
    // Then saving again changes nothing at all.
    for (const tab of [1, 9, 2, 3, 4, 5, 6, 10]) {
      const before = await rawOptions();
      await page.goto(tabUrl(tab));
      await save(page);
      expect(await rawOptions(), `tab ${tab} changed stored settings`).toBe(before);
    }
  });

  test('an old link to a moved setting opens it on its new tab', async ({ page }) => {
    // anonymize_ip lived on the Tracker tab (2) before the redesign.
    await page.goto(tabUrl(2, '#anonymize_ip'));
    await expect(page).toHaveURL(/tab=9#anonymize_ip$/);
    await expect(page.locator('#slimstat-setting-anonymize_ip')).toBeInViewport();
    // A setting that did not move stays put.
    await page.goto(tabUrl(2, '#session_duration'));
    await expect(page).toHaveURL(/tab=2#session_duration$/);
  });

  test('search finds settings by synonym, works by keyboard and never submits the form', async ({ page }) => {
    await page.goto(tabUrl(1));
    const before = await rawOptions();
    const input = page.getByRole('combobox', { name: 'Search settings' });
    await input.fill('returning visitors');
    const list = page.getByRole('listbox', { name: 'Matching settings' });
    await expect(list).toBeVisible();
    await expect(input).toHaveAttribute('aria-expanded', 'true');
    await expect(list.getByRole('option').first()).toContainText('Use cookies to recognize returning visitors');

    await input.press('Escape');
    await expect(list).toBeHidden();
    await input.fill('zzzz-no-such-setting');
    await expect(list).toContainText('No settings match');
    await input.press('Enter');
    await expect(page).toHaveURL(/tab=1$/);

    await input.fill('dnt');
    await input.press('ArrowDown');
    await expect(list.getByRole('option').first()).toHaveAttribute('aria-selected', 'true');
    await input.press('Enter');
    await expect(page).toHaveURL(/tab=9#slimstat-setting-do_not_track$/);
    expect(await rawOptions()).toBe(before);
  });

  test('save bar shows unsaved changes and clears when they are undone', async ({ page }) => {
    await page.goto(tabUrl(1));
    const status = page.locator('.slimstat-save-bar__status');
    const toggle = page.locator('#add_dashboard_widgets');
    const was = await toggle.isChecked();
    await expect(status).toHaveText('');

    await setSettingsToggle(toggle, !was);
    await expect(status).toHaveText('You have unsaved changes.');
    const warns = () => page.evaluate(() => { const e = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(e); return e.defaultPrevented; });
    expect(await warns()).toBe(true);

    await setSettingsToggle(toggle, was);
    await expect(status).toHaveText('');
    expect(await warns()).toBe(false);
  });

  test('edits in the Custom CSS code editor count as unsaved changes', async ({ page }) => {
    await page.goto(tabUrl(3));
    const status = page.locator('.slimstat-save-bar__status');
    await page.locator('#slimstat-setting-custom_css .CodeMirror').click();
    await page.keyboard.type('a');
    await expect(status).toHaveText('You have unsaved changes.');
    await page.evaluate(() => (document.querySelector('#slimstat-setting-custom_css .CodeMirror') as any).CodeMirror.undo());
    await expect(status).toHaveText('');
  });

  test('search for a setting hidden by another opens the one that shows it', async ({ page }) => {
    await setSlimstatOptions({ gdpr_enabled: 'off' });
    const before = await rawOptions();
    await page.goto(tabUrl(1));
    const input = page.getByRole('combobox', { name: 'Search settings' });
    await input.fill('accept button');
    await expect(page.getByRole('listbox', { name: 'Matching settings' }).getByRole('option').first()).toContainText('Accept button label');
    await input.press('Enter');
    await expect(page).toHaveURL(/tab=9#slimstat-setting-gdpr_enabled$/);
    await expect(page.locator('.slimstat-settings-search__hint')).toHaveText('“Accept button label” is hidden until you change “Require visitor consent”.');
    await expect(page.locator('#gdpr_enabled')).toBeFocused();
    expect(await rawOptions()).toBe(before);
  });

  test('completing the privacy review keeps a consent source whose plugin is inactive', async ({ page }) => {
    // With WP Consent API inactive, SlimStat runs its own banner in memory; the stored choice must stay.
    await setSlimstatOptions({ privacy_review_notice: 'yes', gdpr_enabled: 'on', consent_integration: 'wp_consent_api' });
    const before = phpUnserialize(await rawOptions()) as Record<string, unknown>;
    await page.goto(tabUrl(9));
    await expect(page.locator('#consent_integration'), 'the in-memory fallback must be active for this test to mean anything').toHaveValue('slimstat_banner');
    await page.getByRole('button', { name: 'Mark privacy review as complete' }).click();
    await page.waitForLoadState('load');
    await expect(page.getByText('Privacy review marked as complete. No settings were changed.')).toBeVisible();
    expect(phpUnserialize(await rawOptions())).toEqual({ ...before, privacy_review_notice: 'no' });
  });

  test('completing the privacy review writes only privacy_review_notice', async ({ page }) => {
    await setSlimstatOptions({ privacy_review_notice: 'yes', gdpr_enabled: 'off' });
    const before = phpUnserialize(await rawOptions()) as Record<string, unknown>;
    const [meta]: any = await getPool().query("SELECT umeta_id, meta_value FROM wp_usermeta WHERE user_id=1 AND meta_key='slimstat_notice_dismissals_1'");
    await getPool().query("DELETE FROM wp_usermeta WHERE user_id=1 AND meta_key='slimstat_notice_dismissals_1'");
    try {
      await page.goto(`${BASE_URL}/wp-admin/index.php`);
      await expect(page.getByText('Review SlimStat privacy settings for your visitors.')).toBeVisible();

      await page.goto(tabUrl(9));
      await expect(page.getByRole('heading', { name: 'What your privacy settings do' })).toBeVisible();
      await page.getByRole('button', { name: 'Mark privacy review as complete' }).click();
      await page.waitForLoadState('load');
      await expect(page.getByText('Privacy review marked as complete. No settings were changed.')).toBeVisible();
      await expect(page.getByText('Privacy review completed.')).toBeVisible();
      expect(phpUnserialize(await rawOptions())).toEqual({ ...before, privacy_review_notice: 'no' });

      await page.goto(`${BASE_URL}/wp-admin/index.php`);
      await expect(page.getByText('Review SlimStat privacy settings for your visitors.')).toHaveCount(0);
    } finally {
      await getPool().query("DELETE FROM wp_usermeta WHERE user_id=1 AND meta_key='slimstat_notice_dismissals_1'");
      if (meta.length) {
        await getPool().query("INSERT INTO wp_usermeta (umeta_id, user_id, meta_key, meta_value) VALUES (?, 1, 'slimstat_notice_dismissals_1', ?)", [meta[0].umeta_id, meta[0].meta_value]);
      }
    }
  });
});
