/**
 * AC-CMP-001/002 + Suite 06 S01-S04, S10: Textdomain loading timing
 *
 * Installs the early-textdomain mu-plugin, visits admin pages, and
 * calls the AJAX endpoint e2e_get_textdomain_log to assert the
 * textdomain is loaded on the correct hook (init, not too early).
 *
 * Also verifies no _doing_it_wrong PHP notice for early textdomain loading.
 */
import { test, expect } from '@playwright/test';
import {
  closeDb,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  installOptionMutator,
  uninstallOptionMutator,
  installMuPluginByName,
  uninstallMuPluginByName,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG, WP_ROOT } from './helpers/env';
import * as mysql from 'mysql2/promise';
import * as fs from 'fs';
import * as path from 'path';

let db: mysql.Pool;
const DEBUG_LOG = path.join(WP_ROOT, 'wp-content', 'debug.log');
const E2E_TESTING_LINE = "define('SLIMSTAT_E2E_TESTING', true);";
const WP_CONFIG = path.join(WP_ROOT, 'wp-config.php');
let wpConfigBackup: string | null = null;

function injectWpConfigLine(line: string): void {
  const content = fs.readFileSync(WP_CONFIG, 'utf8');
  if (wpConfigBackup === null) wpConfigBackup = content;
  if (content.includes(line)) return;
  const marker = "/* That's all, stop editing!";
  const idx = content.indexOf(marker);
  if (idx === -1) throw new Error('Cannot find stop-editing marker in wp-config.php');
  fs.writeFileSync(WP_CONFIG, content.slice(0, idx) + line + '\n' + content.slice(idx), 'utf8');
}

function restoreWpConfig(): void {
  if (wpConfigBackup !== null) {
    fs.writeFileSync(WP_CONFIG, wpConfigBackup, 'utf8');
    wpConfigBackup = null;
  }
}

function clearDebugLog(): void {
  if (fs.existsSync(DEBUG_LOG)) {
    fs.writeFileSync(DEBUG_LOG, '', 'utf8');
  }
}

function readDebugLog(): string {
  if (!fs.existsSync(DEBUG_LOG)) return '';
  return fs.readFileSync(DEBUG_LOG, 'utf8');
}

test.beforeAll(async () => {
  db = mysql.createPool({ ...MYSQL_CONFIG, connectionLimit: 3 });
});

test.afterAll(async () => {
  if (db) await db.end();
  await closeDb();
});

test.describe('AC-CMP-001: Textdomain Loaded at init Hook', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
    installOptionMutator();

    // Install the early-textdomain logger mu-plugin
    injectWpConfigLine(E2E_TESTING_LINE);
    installMuPluginByName('early-textdomain-mu-plugin.php');
    clearDebugLog();
  });

  test.afterEach(async () => {
    uninstallMuPluginByName('early-textdomain-mu-plugin.php');
    restoreWpConfig();
    uninstallOptionMutator();
    await restoreSlimstatOptions();
  });

  // S01: Textdomain loads at init hook without notices
  test('S01: textdomain loaded on init hook, not earlier', async ({ page }) => {
    // Visit an admin page to trigger textdomain loading
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(2000);

    // Call the AJAX endpoint to get the textdomain log.
    // The mu-plugin must be installed for this endpoint to exist.
    const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: { action: 'e2e_get_textdomain_log' },
    });

    // If the mu-plugin didn't register the AJAX action, WordPress returns '0' with 200 status
    const bodyText = await res.text();
    if (bodyText === '0' || bodyText === '-1') {
      test.skip(true, 'early-textdomain mu-plugin AJAX endpoint not available — mu-plugin may not be installed');
      return;
    }

    expect(res.ok()).toBe(true);

    const json = JSON.parse(bodyText);
    expect(json.success).toBe(true);

    const log = json.data as Array<{
      domain: string;
      hook: string;
      current_action: string;
      time: number;
      mofile: string;
    }>;

    // Find the wp-slimstat textdomain entry
    const slimstatEntries = log.filter((e) => e.domain === 'wp-slimstat');

    // Textdomain should have been loaded at least once
    expect(slimstatEntries.length).toBeGreaterThan(0);

    // The load_textdomain filter fires during the hook where textdomain is loaded.
    // For SlimStat 5.4.2+, this should be 'init' (not plugins_loaded or earlier).
    for (const entry of slimstatEntries) {
      // current_action should be 'init' or a later hook, not 'plugins_loaded'
      expect(
        entry.current_action,
        `wp-slimstat textdomain loaded during "${entry.current_action}" instead of "init" or later`
      ).not.toBe('plugins_loaded');

      // Should definitely not be before plugins_loaded
      expect(entry.current_action).not.toBe('muplugins_loaded');
      expect(entry.current_action).not.toBe('setup_theme');
    }
  });

  // S02: Frontend page load produces no translation timing notice
  test('S02: frontend page load has no early textdomain notice', async ({ page }) => {
    clearDebugLog();

    await page.goto(BASE_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    const debugLog = readDebugLog();
    expect(debugLog).not.toContain(
      'Translation loading for the wp-slimstat domain was triggered too early'
    );
  });

  // S03: Admin dashboard produces no translation timing notice
  test('S03: admin dashboard has no early textdomain notice', async ({ page }) => {
    clearDebugLog();

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(2000);

    const debugLog = readDebugLog();
    expect(debugLog).not.toContain(
      'Translation loading for the wp-slimstat domain was triggered too early'
    );
  });

  // S04: Settings page renders translated strings without notice
  test('S04: settings page renders without textdomain timing notice', async ({ page }) => {
    clearDebugLog();

    const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimconfig`, {
      waitUntil: 'domcontentloaded',
    });
    expect(response).not.toBeNull();
    // Accept 200 or 302 (WP admin pages may redirect for various reasons)
    expect(response!.status()).toBeLessThan(400);

    await page.waitForTimeout(2000);

    // Settings page should render with recognizable content (case-insensitive check)
    const bodyText = await page.locator('body').textContent() || '';
    const lowerBody = bodyText.toLowerCase();
    expect(
      lowerBody.includes('tracker') || lowerBody.includes('slimstat') || lowerBody.includes('settings'),
      'Settings page should contain recognizable SlimStat content'
    ).toBe(true);

    const debugLog = readDebugLog();
    expect(debugLog).not.toContain(
      'Translation loading for the wp-slimstat domain was triggered too early'
    );
  });

  // S10: REST API tracking request produces no translation notice
  test('S10: REST tracking endpoint has no textdomain timing notice', async ({ page }) => {
    clearDebugLog();

    // Send a tracking request to the REST endpoint
    const res = await page.request.post(`${BASE_URL}/wp-json/slimstat/v1/hit`, {
      headers: { 'Content-Type': 'application/json' },
      data: JSON.stringify({ id: '0.0' }),
    });

    // The endpoint may return various codes but should not trigger early textdomain notice
    await page.waitForTimeout(1000);

    const debugLog = readDebugLog();
    expect(debugLog).not.toContain(
      'Translation loading for the wp-slimstat domain was triggered too early'
    );
  });

  // AC-CMP-001: Verify hook registration in source code
  test('load_plugin_textdomain registered on init hook in source', async () => {
    const { PLUGIN_DIR } = await import('./helpers/env');
    const mainFile = path.join(PLUGIN_DIR, 'wp-slimstat.php');
    const content = fs.readFileSync(mainFile, 'utf8');

    // Should have init hook registration for textdomain
    expect(content).toMatch(/add_action\s*\(\s*'init'\s*,.*load_textdomain/s);

    // Should NOT load textdomain on plugins_loaded
    // (allowing the line to exist only in comments)
    const lines = content.split('\n');
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i].trim();
      if (line.startsWith('//') || line.startsWith('*') || line.startsWith('/*')) continue;
      if (line.includes('plugins_loaded') && line.includes('load_textdomain') && !line.includes('//')) {
        // This would be a violation
        expect(line, `Line ${i + 1} loads textdomain on plugins_loaded`).not.toContain('load_textdomain');
      }
    }
  });
});
