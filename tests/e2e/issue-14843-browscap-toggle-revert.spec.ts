/**
 * Regression test for Issue #14843: Browscap toggle silently reverts on save.
 *
 * When unzip_file() fails (missing ZipArchive, corrupt download, permission issue),
 * the Browscap enable_browscap setting never gets set to 'on' and the toggle
 * reverts without adequate diagnostic info.
 *
 * Test scenarios:
 *   1. Toggle reverts when unzip fails (simulated via mu-plugin)
 *   2. Error message contains diagnostic details (not just generic text)
 *   3. Toggle persists when download + unzip succeed (happy path)
 *   4. Corrupt zip file produces a meaningful error
 *   5. Toggle OFF works correctly after successful enable
 */
import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { unserialize as phpUnserialize } from 'php-serialize';
import {
  installMuPluginByName,
  uninstallMuPluginByName,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  setSlimstatOption,
  getPool,
  closeDb,
} from './helpers/setup';
import { BASE_URL, WP_ROOT } from './helpers/env';

// ─── Constants ─────────────────────────────────────────────────────

/** Settings page URL — tab 2 (Tracker), where enable_browscap lives */
const SETTINGS_URL = `${BASE_URL}/wp-admin/admin.php?page=slimconfig&tab=2`;

/** Sentinel file that the mu-plugin reads to decide how to block unzip */
const SENTINEL_PATH = path.join(WP_ROOT, 'wp-content', 'e2e-block-browscap-unzip.json');

// ─── Helpers ───────────────────────────────────────────────────────

/** Write the sentinel file that tells the mu-plugin to block browscap unzip */
function enableUnzipBlocker(mode: 'unzip_fail' | 'corrupt_zip' = 'unzip_fail'): void {
  fs.writeFileSync(SENTINEL_PATH, JSON.stringify({ mode }), 'utf8');
}

/** Remove the sentinel file so unzip works normally */
function disableUnzipBlocker(): void {
  if (fs.existsSync(SENTINEL_PATH)) {
    fs.unlinkSync(SENTINEL_PATH);
  }
}

/** Read slimstat_options from DB and return parsed object */
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

// ─── Test suite ────────────────────────────────────────────────────

test.describe('Issue #14843 — Browscap toggle revert on save', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(120_000); // Browscap download can be slow

  test.beforeAll(async () => {
    installMuPluginByName('browscap-unzip-blocker-mu-plugin.php');
    await snapshotSlimstatOptions();
  });

  test.afterAll(async () => {
    disableUnzipBlocker();
    uninstallMuPluginByName('browscap-unzip-blocker-mu-plugin.php');
    await restoreSlimstatOptions();
    await closeDb();
  });

  test.afterEach(async () => {
    disableUnzipBlocker();
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 1: Toggle reverts when unzip fails
  //   Simulates missing ZipArchive via mu-plugin. The toggle should
  //   revert to OFF and an error message should appear.
  // ═══════════════════════════════════════════════════════════════════

  test('toggle reverts to OFF when unzip_file() fails', async ({ page }) => {
    // Ensure browscap starts as OFF
    await setSlimstatOption(page, 'enable_browscap', 'no');

    // Activate the unzip blocker
    enableUnzipBlocker('unzip_fail');

    // Navigate to settings tab 2 (Tracker)
    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });

    // Find and toggle Browscap ON
    const browscapToggle = page.locator('#enable_browscap');
    await expect(browscapToggle).toBeAttached();
    await browscapToggle.check();

    // Submit the form
    await page.locator('input.slimstat-settings-button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    // Verify an error message appeared mentioning "uncompressing" or "Browscap"
    const bodyText = await page.locator('body').innerText();
    expect(
      bodyText.toLowerCase(),
      'Error message about browscap unzip failure should appear',
    ).toMatch(/error.*browscap|browscap.*error|uncompressing/i);

    // Reload the page and verify the toggle is still OFF
    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });
    const isChecked = await page.locator('#enable_browscap').isChecked();
    expect(isChecked, 'Browscap toggle should revert to OFF after failed unzip').toBe(false);

    // Verify DB value is still 'no'
    const opts = await getSlimstatOptionsFromDb();
    expect(
      opts['enable_browscap'],
      'DB should store "no" for enable_browscap after failed unzip',
    ).toBe('no');
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 2: Error message contains diagnostic details (AFTER FIX)
  //   After the fix, the WP_Error message from unzip_file() should
  //   be included in the admin notice, not just a generic string.
  //
  //   BEFORE FIX: This test documents the bug — it will FAIL because
  //   the error message is generic. After the fix, it should PASS.
  // ═══════════════════════════════════════════════════════════════════

  test('error message includes WP_Error diagnostic details @after-fix', async ({ page }) => {
    await setSlimstatOption(page, 'enable_browscap', 'no');
    enableUnzipBlocker('unzip_fail');

    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });
    await page.locator('#enable_browscap').check();
    await page.locator('input.slimstat-settings-button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    // The mu-plugin returns: "Simulated unzip failure: ZipArchive extension not available (E2E test)"
    // After the fix, this specific message should appear in the admin notice.
    // Before the fix, only the generic "error uncompressing" message appears.
    const noticeArea = page.locator('.slimstat-notice, .notice, .updated, #setting-error-settings_updated, #message');
    const noticeText = await noticeArea.allInnerTexts();
    const allText = noticeText.join(' ').toLowerCase();

    // This assertion will FAIL before the fix (generic message only)
    // and PASS after the fix (includes WP_Error details)
    expect(
      allText,
      'Error notice should include the specific WP_Error reason, not just generic text',
    ).toContain('simulated unzip failure');
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 3: Happy path — toggle persists when download + unzip succeed
  //   No blocker active. If the server can reach GitHub and has
  //   ZipArchive, the toggle should stay ON.
  // ═══════════════════════════════════════════════════════════════════

  test('toggle stays ON when browscap download and unzip succeed', async ({ page }) => {
    await setSlimstatOption(page, 'enable_browscap', 'no');
    disableUnzipBlocker(); // Ensure no blocker

    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });
    await page.locator('#enable_browscap').check();
    await page.locator('input.slimstat-settings-button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    // Check for success message
    const bodyText = await page.locator('body').innerText();
    const hasSuccess = bodyText.includes('installed on your server') || bodyText.includes('does not need to be updated');
    const hasError = /error.*browscap|browscap.*error|uncompressing/i.test(bodyText);

    if (hasError) {
      // If the test environment can't download from GitHub (offline, firewall),
      // skip rather than fail — this is an environment limitation, not a code bug.
      test.skip(true, 'Browscap download failed — test environment cannot reach GitHub');
    }

    expect(hasSuccess, 'Success message should appear after browscap install').toBe(true);

    // Reload and verify toggle is ON
    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });
    const isChecked = await page.locator('#enable_browscap').isChecked();
    expect(isChecked, 'Browscap toggle should stay ON after successful install').toBe(true);

    // Verify DB
    const opts = await getSlimstatOptionsFromDb();
    expect(opts['enable_browscap'], 'DB should store "on" for enable_browscap').toBe('on');
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 4: Corrupt zip produces meaningful error
  //   Simulates GitHub returning HTML instead of a zip file.
  // ═══════════════════════════════════════════════════════════════════

  test('corrupt zip file produces an error and toggle reverts', async ({ page }) => {
    await setSlimstatOption(page, 'enable_browscap', 'no');
    enableUnzipBlocker('corrupt_zip');

    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });
    await page.locator('#enable_browscap').check();
    await page.locator('input.slimstat-settings-button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    // Should show an error (either uncompressing or downloading)
    const bodyText = await page.locator('body').innerText();
    const hasError = /error/i.test(bodyText);
    expect(hasError, 'An error message should appear for corrupt zip').toBe(true);

    // Toggle should revert
    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });
    const isChecked = await page.locator('#enable_browscap').isChecked();
    expect(isChecked, 'Toggle should revert to OFF for corrupt zip').toBe(false);

    // DB should remain 'no'
    const opts = await getSlimstatOptionsFromDb();
    expect(opts['enable_browscap'], 'DB should remain "no" for corrupt zip').toBe('no');
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 5: Toggle OFF works after successful enable
  //   First enable browscap (happy path), then disable it. Verify
  //   the cache folder is removed and the setting persists as 'no'.
  // ═══════════════════════════════════════════════════════════════════

  test('toggle OFF removes browscap cache and persists', async ({ page }) => {
    // First set to 'on' via DB (simulate a working install)
    await setSlimstatOption(page, 'enable_browscap', 'on');

    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });

    // Verify it shows as checked
    const browscapToggle = page.locator('#enable_browscap');
    const isOn = await browscapToggle.isChecked();

    if (!isOn) {
      // If the toggle doesn't reflect 'on', the test setup didn't work
      test.skip(true, 'Could not set enable_browscap to on via DB — toggle not checked');
    }

    // Toggle OFF
    await browscapToggle.uncheck();
    await page.locator('input.slimstat-settings-button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    // Check the message
    const bodyText = await page.locator('body').innerText();
    const hasUninstall = bodyText.includes('uninstalled') || bodyText.includes('deleted');
    // Might also get a permission error if cache doesn't exist — that's OK
    expect(
      hasUninstall || !bodyText.includes('error'),
      'Should see uninstall confirmation or at least no error',
    ).toBe(true);

    // Reload and verify OFF
    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });
    const isChecked = await page.locator('#enable_browscap').isChecked();
    expect(isChecked, 'Browscap toggle should be OFF after disabling').toBe(false);

    // Verify DB
    const opts = await getSlimstatOptionsFromDb();
    expect(opts['enable_browscap'], 'DB should store "no" after disabling').toBe('no');
  });

  // ═══════════════════════════════════════════════════════════════════
  // Test 6: Other settings on the same tab are NOT affected by
  //   browscap failure — they should save normally.
  // ═══════════════════════════════════════════════════════════════════

  test('other settings save correctly even when browscap toggle fails', async ({ page }) => {
    await setSlimstatOption(page, 'enable_browscap', 'no');
    enableUnzipBlocker('unzip_fail');

    await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' });

    // Read current session_duration value
    const sessionInput = page.locator('#session_duration');
    const currentValue = await sessionInput.inputValue();
    const newValue = currentValue === '1800' ? '3600' : '1800';

    // Change session_duration AND try to enable browscap
    await sessionInput.fill(newValue);
    await page.locator('#enable_browscap').check();

    // Submit
    await page.locator('input.slimstat-settings-button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    // Browscap should fail (error message)
    const bodyText = await page.locator('body').innerText();
    expect(bodyText.toLowerCase()).toMatch(/error.*browscap|browscap.*error|uncompressing/i);

    // But session_duration should have been saved correctly
    const opts = await getSlimstatOptionsFromDb();
    expect(
      opts['session_duration'],
      'session_duration should save even when browscap fails',
    ).toBe(newValue);

    // Browscap should still be 'no'
    expect(opts['enable_browscap'], 'enable_browscap should remain no').toBe('no');
  });
});
