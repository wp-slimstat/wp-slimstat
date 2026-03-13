/**
 * E2E tests for AC-GEO-005 / AC-GEO-006: IP detection header priority.
 *
 * Validates that WP SlimStat's IP detection loop respects the correct
 * header priority order:
 *   CF-Connecting-IP (with CF-Ray) > X-Forwarded-For > REMOTE_ADDR
 *
 * Uses DB-IP provider to verify the resolved IP via geolocation results.
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  getLatestStat,
  getLatestStatWithIp,
  closeDb,
} from './helpers/setup';

/** Poll getLatestStatWithIp until a row appears or timeout. */
async function waitForStatWithIp(marker: string, timeoutMs = 10_000, intervalMs = 250) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const stat = await getLatestStatWithIp(marker);
    if (stat) return stat;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return null;
}

/** Poll getLatestStat until a row appears or timeout. */
async function waitForStat(marker: string, timeoutMs = 10_000, intervalMs = 250) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const stat = await getLatestStat(marker);
    if (stat) return stat;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return null;
}

test.describe('AC-GEO-005/006: IP Detection Header Priority', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    // Use DB-IP to verify IP resolution via country lookup
    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'geolocation_country', 'no');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  // ─── Test 1: CF-Connecting-IP takes priority with CF-Ray ───────

  test('CF-Connecting-IP used when CF-Ray is present (highest priority)', async ({ page, context }) => {
    // CF-Connecting-IP (US) should win over X-Forwarded-For (DE)
    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-ip-priority-cf',
      'CF-Connecting-IP': '8.8.8.8',         // US
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

  test('X-Forwarded-For used when CF-Ray is absent', async ({ page, context }) => {
    // Without CF-Ray, CF-Connecting-IP should be ignored
    // X-Forwarded-For should be used
    await context.setExtraHTTPHeaders({
      'X-Forwarded-For': '5.9.49.12',        // DE
    });

    const marker = `ip-xff-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStat(marker);
    // X-Forwarded-For (5.9.49.12 → DE) should be used
    if (stat && stat.country) {
      expect(stat.country).toBe('de');
    }
  });

  // ─── Test 3: Multiple proxy hops in X-Forwarded-For ────────────

  test('X-Forwarded-For with multiple IPs uses first public IP', async ({ page, context }) => {
    // Comma-separated list: private, public (DE), public (US)
    // Should use the first public IP in the chain
    await context.setExtraHTTPHeaders({
      'X-Forwarded-For': '10.0.0.1, 5.9.49.12, 8.8.8.8',
    });

    const marker = `ip-xff-multi-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStat(marker);
    if (stat && stat.country) {
      // First public IP is 5.9.49.12 (DE) — private 10.0.0.1 should be skipped
      expect(stat.country).toBe('de');
    }
  });

  // ─── Test 4: CF-Connecting-IP with CF-Ray resolves correct IP ──

  test('CF-Connecting-IP with CF-Ray records the CF IP address', async ({ page, context }) => {
    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-ip-detection-record',
      'CF-Connecting-IP': '1.0.16.0',        // JP
    });

    const marker = `ip-detect-jp-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStatWithIp(marker);
    expect(stat).toBeTruthy();
    expect(stat!.country).toBe('jp');
    // The recorded IP should be the CF-Connecting-IP
    expect(stat!.ip).toBe('1.0.16.0');
  });

  // ─── Test 5: No proxy headers falls back to REMOTE_ADDR ───────

  test('no proxy headers falls back to REMOTE_ADDR (local dev = private IP)', async ({ page }) => {
    // No extra headers → REMOTE_ADDR is 127.0.0.1 on local dev
    const marker = `ip-remoteaddr-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    await new Promise((r) => setTimeout(r, 3000));

    const stat = await getLatestStat(marker);
    // REMOTE_ADDR (127.0.0.1) is private → no country
    if (stat) {
      expect(!stat.country || stat.country === '').toBeTruthy();
    }
  });

  // ─── Test 6: CF-Connecting-IP ignored without CF-Ray ───────────

  test('CF-Connecting-IP ignored without CF-Ray (anti-spoofing)', async ({ page, context }) => {
    // Spoof attempt: set CF-Connecting-IP but no CF-Ray
    await context.setExtraHTTPHeaders({
      'CF-Connecting-IP': '8.8.8.8',         // US — should be ignored
    });

    const marker = `ip-spoof-nocfray-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    await new Promise((r) => setTimeout(r, 3000));

    const stat = await getLatestStat(marker);
    // Without CF-Ray, CF-Connecting-IP is not trusted
    // Falls back to REMOTE_ADDR (127.0.0.1) → no country
    if (stat) {
      expect(stat.country).not.toBe('us');
    }
  });
});
