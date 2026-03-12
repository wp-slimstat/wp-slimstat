/**
 * E2E regression tests for issue #150:
 * Cloudflare IP geolocation uses proxy IP instead of visitor's real IP.
 *
 * Proves that when CF-Ray is present, getCfClientIp() returns the
 * CF-Connecting-IP for geolocation — and that without CF-Ray the
 * header is ignored (anti-spoofing).
 *
 * Uses DB-IP provider (IP-based lookup) to isolate the IP selection logic.
 * On local dev, REMOTE_ADDR is 127.0.0.1 (private) → no country.
 * With the fix, CF-Connecting-IP (public IP) is used → resolves country.
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
  closeDb,
} from './helpers/setup';

test.describe('Issue #150: Cloudflare IP geolocation regression', () => {
  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  // ─── Test 1: CF-Connecting-IP used for geolocation with CF-Ray ──

  test('dbip provider resolves country from CF-Connecting-IP when CF-Ray present', async ({ page, context }) => {
    test.setTimeout(60_000);

    // Use DB-IP (IP-based lookup) — NOT Cloudflare provider (header-based)
    // This isolates the getCfClientIp() → $originalIpForGeo path in Processor.php
    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'geolocation_country', 'no'); // city precision
    await setSlimstatOption(page, 'gdpr_enabled', 'off');       // allow PII

    // Inject CF headers: CF-Ray proves we're behind Cloudflare,
    // CF-Connecting-IP is the visitor's real IP (8.8.8.8 = US)
    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-e2e-issue-150-a',
      'CF-Connecting-IP': '8.8.8.8',
    });

    const marker = `cf-ip-fix-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(4000);

    const stat = await getLatestStat(marker);
    expect(stat).toBeTruthy();
    // Without the fix: REMOTE_ADDR is 127.0.0.1 → country is empty/unknown
    // With the fix: getCfClientIp() returns 8.8.8.8 → DB-IP resolves to 'us'
    expect(stat!.country).toBe('us');
  });

  // ─── Test 2: CF-Connecting-IP ignored without CF-Ray (anti-spoof) ─

  test('CF-Connecting-IP ignored without CF-Ray header (anti-spoofing)', async ({ page, context }) => {
    test.setTimeout(60_000);

    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'geolocation_country', 'no');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');

    // Spoof attempt: CF-Connecting-IP set WITHOUT CF-Ray
    // getCfClientIp() should return null → falls back to REMOTE_ADDR (127.0.0.1)
    await context.setExtraHTTPHeaders({
      'CF-Connecting-IP': '8.8.8.8',
    });

    const marker = `cf-spoof-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(4000);

    const stat = await getLatestStat(marker);
    // Without CF-Ray, the CF-Connecting-IP header is not trusted
    // REMOTE_ADDR (127.0.0.1) is a private IP → no geolocation match
    if (stat) {
      expect(stat.country).not.toBe('us');
    }
  });

  // ─── Test 3: Different public IP resolves to expected country ─────

  test('CF-Connecting-IP with German IP resolves to DE', async ({ page, context }) => {
    test.setTimeout(60_000);

    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'geolocation_country', 'no');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');

    // 5.9.49.12 is a Hetzner IP in Germany
    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-e2e-issue-150-de',
      'CF-Connecting-IP': '5.9.49.12',
    });

    const marker = `cf-ip-de-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(4000);

    const stat = await getLatestStat(marker);
    expect(stat).toBeTruthy();
    expect(stat!.country).toBe('de');
  });
});
