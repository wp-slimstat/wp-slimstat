/**
 * E2E tests: Admin Settings Persistence
 *
 * Validates that the SlimStat settings page:
 *  1. Loads without PHP errors and renders expected form elements
 *  2. Persists a toggle change (javascript_mode) after save + reload — both UI and DB
 *  3. Saves multiple settings atomically (toggle + select + toggle in one submit)
 *  4. Retains settings after navigating away and back
 *
 * Settings corruption silently breaks tracking for every visitor, making this
 * the highest-impact admin surface to cover with E2E tests.
 */
import { test, expect } from '@playwright/test';
import { unserialize as phpUnserialize } from 'php-serialize';
import {
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  getPool,
  closeDb,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

// ─── Constants ────────────────────────────────────────────────────────

/** Settings page URL — tab 1 (General) */
const SETTINGS_URL = `${BASE_URL}/wp-admin/admin.php?page=slimconfig&tab=1`;

// ─── Helpers ──────────────────────────────────────────────────────────

/** Read the full slimstat_options from the DB and return as a parsed object. */
async function getSlimstatOptionsFromDb(): Promise<Record<string, any>> {
  const [rows] = (await getPool().execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_options'",
  )) as any;
  if (rows.length === 0) return {};
  const unserialized = phpUnserialize(rows[0].option_value);
  if (typeof unserialized !== 'object' || unserialized === null) {
    throw new Error(`Failed to unserialize slimstat_options: got ${typeof unserialized}`);
  }
  return unserialized as Record<string, any>;
}

// ─── Test suite ───────────────────────────────────────────────────────

test.describe('Admin Settings Persistence', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    await closeDb();
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 1: Settings page loads without PHP errors
  // ═══════════════════════════════════════════════════════════════════

  test('settings page loads without PHP errors and has expected form elements', async ({
    page,
  }) => {
    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });

    const bodyText = await page.locator('body').innerText();

    // No PHP fatal/warning/notice strings in the page content
    expect(bodyText).not.toContain('Fatal error');
    expect(bodyText).not.toContain('Warning:');
    expect(bodyText).not.toContain('Notice:');
    expect(bodyText).not.toContain('Parse error');
    expect(bodyText).not.toContain('Deprecated:');

    // The settings form should be present
    const form = page.locator('form#slimstat-options-1');
    await expect(form).toBeVisible();

    // Key form elements on tab 1 (General) should exist
    await expect(page.locator('#is_tracking')).toBeAttached();
    await expect(page.locator('#javascript_mode')).toBeAttached();
    await expect(page.locator('#tracking_request_method')).toBeAttached();
    await expect(page.locator('#add_dashboard_widgets')).toBeAttached();

    // Submit button should be present
    await expect(
      page.locator('input.slimstat-settings-button[type="submit"]'),
    ).toBeVisible();
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 2: Toggle tracking mode persists after save + reload
  //   Flip javascript_mode, submit, reload, verify both UI and DB.
  // ═══════════════════════════════════════════════════════════════════

  test('toggle javascript_mode persists after save and reload', async ({ page }) => {
    // Load the settings page and read the current checkbox state
    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });

    const checkbox = page.locator('#javascript_mode');
    const wasChecked = await checkbox.isChecked();

    // Flip the toggle: if checked, uncheck; if unchecked, check
    if (wasChecked) {
      await checkbox.uncheck();
    } else {
      await checkbox.check();
    }

    // Submit the form
    await page.locator('input.slimstat-settings-button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    // Verify save confirmation message appeared
    const notice = page.locator('.slimstat-notice.notice-info');
    await expect(notice).toBeVisible();
    const noticeText = await notice.innerText();
    expect(noticeText).toContain('saved');

    // Reload the page to verify the value sticks
    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });
    const isNowChecked = await page.locator('#javascript_mode').isChecked();
    expect(isNowChecked, 'UI toggle should reflect the saved state after reload').toBe(
      !wasChecked,
    );

    // Verify the DB has the correct value
    const opts = await getSlimstatOptionsFromDb();
    const expectedDbValue = !wasChecked ? 'on' : 'no';
    expect(
      opts['javascript_mode'],
      `DB should store "${expectedDbValue}" for javascript_mode`,
    ).toBe(expectedDbValue);
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 3: Multiple settings save atomically
  //   Change javascript_mode toggle, tracking_request_method select,
  //   and add_dashboard_widgets toggle in one form submit. Verify all
  //   three persisted in the DB — not just the last one.
  // ═══════════════════════════════════════════════════════════════════

  test('multiple settings save atomically in one form submit', async ({ page }) => {
    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });

    // Read current states
    const jsModeCheckbox = page.locator('#javascript_mode');
    const dashWidgetsCheckbox = page.locator('#add_dashboard_widgets');
    const requestMethodSelect = page.locator('#tracking_request_method');

    const jsWasChecked = await jsModeCheckbox.isChecked();
    const dashWasChecked = await dashWidgetsCheckbox.isChecked();
    const currentMethod = await requestMethodSelect.inputValue();

    // Pick a different tracking request method
    const newMethod = currentMethod === 'rest' ? 'ajax' : 'rest';

    // Flip both toggles and change the select
    if (jsWasChecked) {
      await jsModeCheckbox.uncheck();
    } else {
      await jsModeCheckbox.check();
    }

    if (dashWasChecked) {
      await dashWidgetsCheckbox.uncheck();
    } else {
      await dashWidgetsCheckbox.check();
    }

    await requestMethodSelect.selectOption(newMethod);

    // Submit
    await page.locator('input.slimstat-settings-button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    // Verify save confirmation
    await expect(page.locator('.slimstat-notice.notice-info')).toBeVisible();

    // Verify ALL three values in the DB
    const opts = await getSlimstatOptionsFromDb();

    const expectedJs = !jsWasChecked ? 'on' : 'no';
    const expectedDash = !dashWasChecked ? 'on' : 'no';

    expect(opts['javascript_mode'], 'javascript_mode should match the flipped value').toBe(
      expectedJs,
    );
    expect(
      opts['add_dashboard_widgets'],
      'add_dashboard_widgets should match the flipped value',
    ).toBe(expectedDash);
    expect(
      opts['tracking_request_method'],
      'tracking_request_method should match the new selection',
    ).toBe(newMethod);
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 4: Settings survive page navigation
  //   Save a setting, navigate to the WP dashboard, navigate back to
  //   settings, verify value is still correct in both UI and DB.
  // ═══════════════════════════════════════════════════════════════════

  test('settings survive navigation away and back', async ({ page }) => {
    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });

    // Flip the posts_column_pageviews toggle (Hits vs IPs — harmless setting)
    const postsColCheckbox = page.locator('#posts_column_pageviews');
    const wasChecked = await postsColCheckbox.isChecked();

    if (wasChecked) {
      await postsColCheckbox.uncheck();
    } else {
      await postsColCheckbox.check();
    }

    // Submit and wait for confirmation
    await page.locator('input.slimstat-settings-button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.slimstat-notice.notice-info')).toBeVisible();

    // Navigate away to the WP Dashboard
    await page.goto(`${BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' });
    // Verify we actually left the settings page
    expect(page.url()).not.toContain('slimconfig');

    // Navigate back to settings
    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });

    // Verify the toggle retained its value
    const isNowChecked = await page.locator('#posts_column_pageviews').isChecked();
    expect(
      isNowChecked,
      'posts_column_pageviews should retain its value after navigation round-trip',
    ).toBe(!wasChecked);

    // Double-check the DB agrees
    const opts = await getSlimstatOptionsFromDb();
    const expectedValue = !wasChecked ? 'on' : 'no';
    expect(opts['posts_column_pageviews'], 'DB value should match UI after navigation').toBe(
      expectedValue,
    );
  });
});
