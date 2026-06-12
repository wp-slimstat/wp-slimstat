/**
 * Issue #303 — tracker REST endpoint must NOT return HTTP 500 when the
 * PHP `fileinfo` extension is missing.
 *
 * The fileinfo-disabler mu-plugin shadows `extension_loaded('fileinfo')`
 * inside the SlimStat\Services namespace so Browscap behaves as if the
 * host's PHP was built without ext-fileinfo (common after PHP 7.4 → 8.x
 * rebuilds on managed hosts).
 *
 * Pre-fix behavior: Browscap path → Flysystem LocalFilesystemAdapter →
 *   FinfoMimeTypeDetector → `new finfo(...)` → Class "finfo" not found
 *   → uncaught \Error → 500 on every tracker hit.
 *
 * Post-fix behavior: gate at Browscap.php:63 short-circuits, fallback to
 *   UADetector populates `browser` field, REST returns 200, debug.log
 *   stays clean, admin notice surfaces in /wp-admin.
 */
import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import {
  installOptionMutator,
  uninstallOptionMutator,
  installMuPluginByName,
  uninstallMuPluginByName,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  waitForPageviewRow,
  waitForTrackerId,
  getPool,
  closeDb,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';
import { WP_ROOT } from './helpers/env';

const DEBUG_LOG = path.join(WP_ROOT, 'wp-content', 'debug.log');

function readDebugLog(): string {
  return fs.existsSync(DEBUG_LOG) ? fs.readFileSync(DEBUG_LOG, 'utf8') : '';
}

function truncateDebugLog(): void {
  if (fs.existsSync(DEBUG_LOG)) fs.writeFileSync(DEBUG_LOG, '', 'utf8');
}

test.describe('Issue #303 — Browscap fileinfo-missing tracker resilience', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
    installMuPluginByName('fileinfo-disabler-mu-plugin.php');
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    truncateDebugLog();
    await setSlimstatOption(page, 'is_tracking', 'on');
    await setSlimstatOption(page, 'ignore_wp_users', 'off');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'javascript_mode', 'off');
    await setSlimstatOption(page, 'tracking_request_method', 'rest');
    await setSlimstatOption(page, 'enable_browscap', 'on');
    await setSlimstatOption(page, 'notice_browscap_fileinfo', 'on');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallMuPluginByName('fileinfo-disabler-mu-plugin.php');
    uninstallOptionMutator();
    await closeDb();
  });

  test('e2e-tracker-rest-200-without-fileinfo: tracker hit returns 200 + writes row, no fatal logged', async ({ page }) => {
    const marker = `e2e-303-tracker-${Date.now()}`;

    const trackingResponses: { url: string; status: number }[] = [];
    page.on('response', (response) => {
      const url = response.url();
      if (url.includes('slimstat/v1/hit') || url.includes('admin-ajax.php')) {
        trackingResponses.push({ url, status: response.status() });
      }
    });

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);

    const row = await waitForPageviewRow(marker);
    expect(row, 'pageview row must exist — fileinfo gate must allow UADetector fallback').not.toBeNull();

    const serverErrors = trackingResponses.filter((r) => r.status >= 500);
    expect(
      serverErrors,
      `tracking endpoints must not return 500 errors when fileinfo is missing: ${JSON.stringify(serverErrors)}`,
    ).toHaveLength(0);

    const debugLog = readDebugLog();
    expect(
      debugLog,
      'debug.log must not contain `Class "finfo" not found` fatal',
    ).not.toMatch(/Class "finfo" not found/);
    expect(
      debugLog,
      'debug.log must not contain any `PHP Fatal error` from the tracker path',
    ).not.toMatch(/PHP Fatal error[\s\S]*?wp-slimstat/);
  });

  test('e2e-fallback-populates-browser-via-uadetector: tracker still records browser via UADetector fallback', async ({ page }) => {
    const marker = `e2e-303-uadetector-${Date.now()}`;

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);
    await waitForPageviewRow(marker);

    const [rows] = await getPool().execute(
      'SELECT browser, browser_version FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1',
      [`%${marker}%`],
    ) as any;

    expect(rows.length, 'stat row must exist').toBeGreaterThan(0);
    const stat = rows[0];
    expect(stat.browser, 'browser column must be populated by UADetector fallback').not.toBe('');
    expect(stat.browser, 'browser must not be the literal "Default Browser" sentinel').not.toBe('Default Browser');
  });

  test('e2e-admin-notice-visible-when-fileinfo-missing: warning notice surfaces in SlimStat admin', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle');

    const noticeBody = await page.locator('.notice-warning, .updated, .error, .notice')
      .filter({ hasText: /fileinfo/i })
      .count();

    expect(
      noticeBody,
      'a warning notice mentioning the fileinfo extension must be visible on the SlimStat admin page',
    ).toBeGreaterThan(0);
  });

  test('e2e-no-notice-when-browscap-disabled: notice does NOT render when enable_browscap=off', async ({ page }) => {
    await setSlimstatOption(page, 'enable_browscap', 'off');

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle');

    const noticeBody = await page.locator('.notice-warning, .updated, .error, .notice')
      .filter({ hasText: /fileinfo/i })
      .count();

    expect(
      noticeBody,
      'fileinfo notice must NOT render when Browscap is disabled — sites that opted out should not be nagged',
    ).toBe(0);
  });
});
