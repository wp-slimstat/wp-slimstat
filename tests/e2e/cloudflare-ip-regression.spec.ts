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

/** Poll getLatestStat until a row appears or timeout (avoids fixed waitForTimeout). */
async function waitForStat(marker: string, timeoutMs = 10_000, intervalMs = 250) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const stat = await getLatestStat(marker);
    if (stat) return stat;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return null;
}

test.describe('Issue #150: Cloudflare IP geolocation regression', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    // All tests use DB-IP (IP-based lookup) with GDPR off to isolate getCfClientIp()
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

  // ─── Test 1: CF-Connecting-IP used for geolocation with CF-Ray ──

  test('dbip provider resolves country from CF-Connecting-IP when CF-Ray present', async ({ page, context }) => {
    // Inject CF headers: CF-Ray proves we're behind Cloudflare,
    // CF-Connecting-IP is the visitor's real IP (8.8.8.8 = US)
    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-e2e-issue-150-a',
      'CF-Connecting-IP': '8.8.8.8',
    });

    const marker = `cf-ip-fix-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStat(marker);
    expect(stat).toBeTruthy();
    // Without the fix: REMOTE_ADDR is 127.0.0.1 → country is empty/unknown
    // With the fix: getCfClientIp() returns 8.8.8.8 → DB-IP resolves to 'us'
    expect(stat!.country).toBe('us');
  });

  // ─── Test 2: CF-Connecting-IP ignored without CF-Ray (anti-spoof) ─

  test('CF-Connecting-IP ignored without CF-Ray header (anti-spoofing)', async ({ page, context }) => {
    // Spoof attempt: CF-Connecting-IP set WITHOUT CF-Ray
    // getCfClientIp() should return null → falls back to REMOTE_ADDR (127.0.0.1)
    await context.setExtraHTTPHeaders({
      'CF-Connecting-IP': '8.8.8.8',
    });

    const marker = `cf-spoof-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    // Give tracking time to complete — stat may or may not exist for private IPs
    await new Promise((r) => setTimeout(r, 3000));

    const stat = await getLatestStat(marker);
    // Without CF-Ray, the CF-Connecting-IP header is not trusted
    // REMOTE_ADDR (127.0.0.1) is a private IP → no geolocation match
    if (stat) {
      expect(stat.country).not.toBe('us');
    }
  });

  // ─── Test 3: Different public IP resolves to expected country ─────

  test('CF-Connecting-IP with German IP resolves to DE', async ({ page, context }) => {
    // 5.9.49.12 is a Hetzner IP in Germany
    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-e2e-issue-150-de',
      'CF-Connecting-IP': '5.9.49.12',
    });

    const marker = `cf-ip-de-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStat(marker);
    expect(stat).toBeTruthy();
    expect(stat!.country).toBe('de');
  });

  // ─── Test 4: IPv6 CF-Connecting-IP resolves country ──────────────

  test('IPv6 CF-Connecting-IP resolves country when CF-Ray present', async ({ page, context }) => {
    // 2001:4860:4860::8888 is Google Public DNS (US)
    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-e2e-issue-150-ipv6',
      'CF-Connecting-IP': '2001:4860:4860::8888',
    });

    const marker = `cf-ipv6-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStat(marker);
    expect(stat).toBeTruthy();
    // Google Public DNS IPv6 resolves to US in DB-IP
    expect(stat!.country).toBe('us');
  });

  // ─── Test 5: Multiple proxy hops — first IP in X-Forwarded-For ──

  test('multiple proxy hops: first IP in X-Forwarded-For used', async ({ page, context }) => {
    // When behind Cloudflare with multiple proxy hops, X-Forwarded-For
    // contains a chain: original client, proxy1, proxy2, ...
    // The first public IP (8.8.8.8) should be used for geolocation.
    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-e2e-issue-150-xff',
      'X-Forwarded-For': '8.8.8.8, 10.0.0.1',
    });

    const marker = `cf-xff-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStat(marker);
    expect(stat).toBeTruthy();
    expect(stat!.country).toBe('us');
  });
});
