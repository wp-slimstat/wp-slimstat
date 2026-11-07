/**
 * E2E tests for AC-GEO-005 / AC-GEO-006: IP detection header priority.
 *
 * Validates that WP SlimStat's IP detection loop respects the correct
 * header priority order:
 *   Verified Cloudflare client > explicitly trusted proxy > connecting peer
 *
 * Uses the header-injector mu-plugin to set headers at the PHP level
 * (Playwright's setExtraHTTPHeaders doesn't reach PHP through Local by Flywheel nginx).
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  closeDb,
  installHeaderInjector,
  uninstallHeaderInjector,
  setHeaderOverrides,
  clearHeaderOverrides,
  waitForStat,
  waitForStatWithIp,
} from './helpers/setup';

test.describe('AC-GEO-005/006: IP Detection Header Priority', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
    installHeaderInjector();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    // Use DB-IP to verify IP resolution via country lookup, track WP users
    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'geolocation_country', 'no');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'ignore_wp_users', 'no');
  });

  test.afterEach(async () => {
    clearHeaderOverrides();
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallHeaderInjector();
    uninstallOptionMutator();
    await closeDb();
  });

  // ─── Test 1: CF-Connecting-IP takes priority with CF-Ray ───────

  test('CF-Connecting-IP used when CF-Ray is present (highest priority)', async ({ page }) => {
    // CF-Connecting-IP (US) should win over X-Forwarded-For (DE)
    setHeaderOverrides({
      'REMOTE_ADDR': '173.245.48.1',
      'CF-Ray': 'test-ip-priority-cf',
      'CF-Connecting-IP': '8.8.8.8',         // US
      'REMOTE_ADDR': '127.0.0.1',
      'X-Forwarded-For': '5.9.49.12',        // DE
    });

    const marker = `ip-cf-priority-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStat(marker);
    expect(stat).toBeTruthy();
    // CF-Connecting-IP (8.8.8.8 → US) should win
    expect(stat!.country).toBe('us');
  });

  // ─── Test 2: X-Forwarded-For used when no CF-Ray ──────────────

  test('X-Forwarded-For is ignored on a direct connection', async ({ page }) => {
    // Neither client-supplied header establishes a trusted proxy.
    setHeaderOverrides({
      'REMOTE_ADDR': '127.0.0.1',
      'X-Forwarded-For': '5.9.49.12',        // DE
    });

    const marker = `ip-xff-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStat(marker);
    expect(stat).toBeTruthy();
    expect(stat!.country).toBe('');
  });

  // ─── Test 3: Multiple proxy hops in X-Forwarded-For ────────────

  test('a forged multi-hop chain is ignored on a direct connection', async ({ page }) => {
    // The entire chain is untrusted when the connecting peer is untrusted.
    setHeaderOverrides({
      'REMOTE_ADDR': '127.0.0.1',
      'X-Forwarded-For': '10.0.0.1, 5.9.49.12, 8.8.8.8',
    });

    const marker = `ip-xff-multi-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStat(marker);
    expect(stat).toBeTruthy();
    expect(stat!.country).toBe('');
  });

  // ─── Test 4: CF-Connecting-IP with CF-Ray resolves correct IP ──

  test('CF-Connecting-IP controls geolocation while storage retains the connection IP', async ({ page }) => {
    setHeaderOverrides({ REMOTE_ADDR: '173.245.48.1' });
    const baselineMarker = `ip-origin-${Date.now()}`;
    await page.goto(`/?p=${baselineMarker}`);
    const baseline = await waitForStatWithIp(baselineMarker);
    expect(baseline).toBeTruthy();
    setHeaderOverrides({
      'REMOTE_ADDR': '173.245.48.1',
      'CF-Ray': 'test-ip-detection-record',
      'CF-Connecting-IP': '1.0.16.0',        // JP
    });

    const marker = `ip-detect-jp-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStatWithIp(marker);
    expect(stat).toBeTruthy();
    expect(stat!.country).toBe('jp');
    // Geolocation uses the validated CF header; getRemoteIp stores the connection
    // address separately; both visits use the same edge peer.
    expect(stat!.ip).toBe(baseline!.ip);
  });

  // ─── Test 5: No proxy headers falls back to REMOTE_ADDR ───────

  test('no proxy headers falls back to REMOTE_ADDR (local dev = private IP)', async ({ page }) => {
    // No header overrides → REMOTE_ADDR is 127.0.0.1 on local dev
    const marker = `ip-remoteaddr-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStat(marker);
    expect(stat).toBeTruthy();
    // REMOTE_ADDR (127.0.0.1) is private → no country
    expect(stat!.country).toBe('');
  });

  // ─── Test 6: CF-Connecting-IP ignored without CF-Ray ───────────

  test('CF-Connecting-IP ignored without CF-Ray (anti-spoofing)', async ({ page }) => {
    // Spoof attempt: set CF-Connecting-IP but no CF-Ray
    setHeaderOverrides({
      'CF-Connecting-IP': '8.8.8.8',         // US — should be ignored
    });

    const marker = `ip-spoof-nocfray-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStat(marker);
    expect(stat).toBeTruthy();
    // Without CF-Ray, CF-Connecting-IP is not trusted
    // Falls back to REMOTE_ADDR (127.0.0.1) → no country
    expect(stat!.country).not.toBe('us');
  });
});
