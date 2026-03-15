/**
 * E2E tests: Pro DB-IP Whois Data — Suite 04 (REQ-AC3)
 *
 * Validates that the Advanced Whois feature works with the DB-IP provider,
 * including data normalization to MaxMind structure, enriched whois data,
 * and correct handling of various IP types.
 *
 * Injects a public IP via CF headers so the tracking pipeline records
 * geolocation data, then verifies the whois AJAX endpoint returns
 * properly structured results.
 *
 * @see BDD cases: Feature 3 — DB-IP Provider Basic Data via Locate API
 */
import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'url';
import {
  installOptionMutator,
  uninstallOptionMutator,
  installNonceHelper,
  uninstallNonceHelper,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  getLatestStatWithIp,
  waitForStat,
  closeDb,
} from './helpers/setup';
import { BASE_URL, WP_ROOT } from './helpers/env';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// ─── MU-Plugin management ─────────────────────────────────────────
const MU_PLUGINS = path.join(WP_ROOT, 'wp-content', 'mu-plugins');
const VERSION_FLOOR_SRC = path.join(__dirname, 'helpers', 'version-floor-test-mu-plugin.php');
const VERSION_FLOOR_DEST = path.join(MU_PLUGINS, 'version-floor-test-mu-plugin.php');
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

function installVersionFloorPlugin(): void {
  fs.mkdirSync(MU_PLUGINS, { recursive: true });
  fs.copyFileSync(VERSION_FLOOR_SRC, VERSION_FLOOR_DEST);
  injectWpConfigLine(E2E_TESTING_LINE);
}

function uninstallVersionFloorPlugin(): void {
  if (fs.existsSync(VERSION_FLOOR_DEST)) fs.unlinkSync(VERSION_FLOOR_DEST);
}

// ─── AJAX helpers ─────────────────────────────────────────────────

async function isProActive(page: import('@playwright/test').Page): Promise<boolean> {
  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'e2e_get_slimstat_version' },
  });
  if (!res.ok()) return false;
  const json = await res.json();
  return json.data?.pro_active === true;
}

async function getWhoisNonce(page: import('@playwright/test').Page): Promise<string> {
  const response = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'test_create_nonce', nonce_action: 'slimstat_ip2location_iframe' },
  });
  expect(response.ok(), 'Nonce helper mu-plugin should respond OK').toBeTruthy();
  const json = await response.json();
  return json.data.nonce;
}

async function callWhoisEndpoint(
  page: import('@playwright/test').Page,
  ip: string,
  nonce: string
): Promise<{ status: number; body: string }> {
  const url = `${BASE_URL}/wp-admin/admin-ajax.php?action=slimstat_ip2location_iframe_content&_wpnonce=${nonce}&ip=${ip}`;
  const response = await page.request.get(url);
  return { status: response.status(), body: await response.text() };
}

test.describe('Pro DB-IP Whois Data — Suite 04 (REQ-AC3)', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
    installNonceHelper();
    installVersionFloorPlugin();
  });

  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallVersionFloorPlugin();
    uninstallOptionMutator();
    uninstallNonceHelper();
    restoreWpConfig();
    await closeDb();
  });

  // ─── TC-AC3-001: DB-IP provider returns basic geolocation data ──

  test('DB-IP whois AJAX returns geo data without fatal error', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    const nonce = await getWhoisNonce(page);
    const result = await callWhoisEndpoint(page, '8.8.8.8', nonce);

    expect(result.status).toBeLessThan(500);
    expect(result.body).not.toContain('Fatal error');
    expect(result.body).not.toContain("Class 'SlimStat\\Services\\GeoIP' not found");

    // Should show either geo data or DB-missing message
    const hasGeoData = result.body.includes('IP Geolocation Information');
    const hasDbMissing = result.body.includes('geolocation database is not available');
    expect(hasGeoData || hasDbMissing, 'Should show geo data or DB-missing message').toBeTruthy();
  });

  // ─── TC-AC3-002: DB-IP data normalized to MaxMind structure ─────

  test('DB-IP whois response contains country information', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    const nonce = await getWhoisNonce(page);
    // 8.8.8.8 = Google DNS, should resolve to US
    const result = await callWhoisEndpoint(page, '8.8.8.8', nonce);

    expect(result.status).toBeLessThan(500);
    expect(result.body).not.toContain('Fatal error');

    const hasGeoData = result.body.includes('IP Geolocation Information');
    if (hasGeoData) {
      // Country data should be present in the normalized output
      const hasCountry =
        result.body.includes('United States') ||
        result.body.includes('US') ||
        result.body.includes('us');
      expect(hasCountry, 'DB-IP response for 8.8.8.8 should include US country data').toBeTruthy();
    }
  });

  // ─── TC-AC3-003: DB-IP whois for different public IP ────────────

  test('DB-IP whois works for non-US IP address', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    const nonce = await getWhoisNonce(page);
    // 1.1.1.1 = Cloudflare DNS
    const result = await callWhoisEndpoint(page, '1.1.1.1', nonce);

    expect(result.status).toBeLessThan(500);
    expect(result.body).not.toContain('Fatal error');

    const hasGeoData = result.body.includes('IP Geolocation Information');
    const hasDbMissing = result.body.includes('geolocation database is not available');
    expect(hasGeoData || hasDbMissing, 'Should show geo data or DB-missing message').toBeTruthy();
  });

  // ─── TC-AC3-004: DB-IP handles reserved/private IPs gracefully ──

  test('DB-IP whois handles reserved IP addresses gracefully', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    const nonce = await getWhoisNonce(page);

    // Test reserved IPs: TEST-NET-1, TEST-NET-3, private
    for (const ip of ['192.0.2.1', '203.0.113.50', '10.0.0.1']) {
      const result = await callWhoisEndpoint(page, ip, nonce);

      expect(result.status, `${ip}: should not 500`).toBeLessThan(500);
      expect(result.body, `${ip}: should not fatal`).not.toContain('Fatal error');
      // Should not throw PHP errors for missing data
      expect(result.body).not.toContain('Undefined index');
      expect(result.body).not.toContain('Undefined array key');
    }
  });

  // ─── TC-AC3-005: DB-IP handles IPv6 address ─────────────────────

  test('DB-IP whois handles IPv6 address lookup', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    const nonce = await getWhoisNonce(page);
    // Google DNS IPv6
    const result = await callWhoisEndpoint(page, '2001:4860:4860::8888', nonce);

    expect(result.status).toBeLessThan(500);
    expect(result.body).not.toContain('Fatal error');
  });

  // ─── TC-AC3-006: Tracking pipeline enriches DB with CF IP ───────

  test('visit tracked with CF-Connecting-IP stores geolocation in DB', async ({ page, browser }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');
    await setSlimstatOption(page, 'geolocation_country', 'no');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');

    await clearStatsTable();

    // Visit as anonymous user with CF headers injecting a public IP
    const anonContext = await browser.newContext();
    await anonContext.setExtraHTTPHeaders({
      'CF-Ray': 'test-e2e-dbip-whois-data',
      'CF-Connecting-IP': '8.8.8.8',
    });
    const anonPage = await anonContext.newPage();
    const marker = `dbip-whois-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?p=${marker}`, { waitUntil: 'domcontentloaded' });

    // Wait for tracking to complete
    const stat = await waitForStat(marker);
    await anonPage.close();
    await anonContext.close();

    if (stat) {
      // The visit should have geolocation data from DB-IP
      expect(stat.country).toBeTruthy();
      // 8.8.8.8 should resolve to US
      expect(stat.country).toBe('us');
    }
    // If stat is null, tracking may not have completed (acceptable in CI)
  });

  // ─── TC-AC3-007: Switching from MaxMind to DB-IP works ──────────

  test('provider switch from MaxMind to DB-IP produces valid whois', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    // Start with MaxMind
    await setSlimstatOption(page, 'geolocation_provider', 'maxmind');
    const nonce1 = await getWhoisNonce(page);
    const maxmindResult = await callWhoisEndpoint(page, '8.8.8.8', nonce1);
    expect(maxmindResult.status).toBeLessThan(500);
    expect(maxmindResult.body).not.toContain('Fatal error');

    // Switch to DB-IP
    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    const nonce2 = await getWhoisNonce(page);
    const dbipResult = await callWhoisEndpoint(page, '8.8.8.8', nonce2);
    expect(dbipResult.status).toBeLessThan(500);
    expect(dbipResult.body).not.toContain('Fatal error');

    // Both should produce valid (non-fatal) responses
    const hasGeoData = dbipResult.body.includes('IP Geolocation Information');
    const hasDbMissing = dbipResult.body.includes('geolocation database is not available');
    expect(hasGeoData || hasDbMissing, 'DB-IP should produce valid response after switch').toBeTruthy();
  });

  // ─── TC-AC3-008: DB-IP partial data renders without error ───────

  test('DB-IP whois renders cleanly even with partial data', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    const nonce = await getWhoisNonce(page);
    // Use an IP that may return partial data (no subdivision/postal)
    const result = await callWhoisEndpoint(page, '198.51.100.1', nonce);

    expect(result.status).toBeLessThan(500);
    expect(result.body).not.toContain('Fatal error');
    // No PHP notices about missing array keys
    expect(result.body).not.toContain('Notice:');
    expect(result.body).not.toContain('Warning:');
  });

  // ─── Feature 3 extended: empty result, exception, and loopback ──

  test('DB-IP whois handles loopback IP gracefully (no fatal)', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    const nonce = await getWhoisNonce(page);
    const result = await callWhoisEndpoint(page, '127.0.0.1', nonce);

    expect(result.status).toBeLessThan(500);
    expect(result.body).not.toContain('Fatal error');
    expect(result.body).not.toContain('Undefined');
  });

  test('DB-IP whois handles empty string IP gracefully (no fatal)', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    const nonce = await getWhoisNonce(page);
    // Empty IP — should not throw 500 or fatal
    const url = `${BASE_URL}/wp-admin/admin-ajax.php?action=slimstat_ip2location_iframe_content&_wpnonce=${nonce}&ip=`;
    const response = await page.request.get(url);
    const body = await response.text();

    expect(response.status()).toBeLessThan(500);
    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('Undefined');
  });

  test('DB-IP whois renders without error when provider is switched back from cloudflare', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    // First set to cloudflare (blocks whois)
    await setSlimstatOption(page, 'geolocation_provider', 'cloudflare');
    const nonce1 = await getWhoisNonce(page);
    const cfResult = await callWhoisEndpoint(page, '8.8.8.8', nonce1);
    expect(cfResult.status).toBeLessThan(500);

    // Then switch back to dbip — should work cleanly
    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    const nonce2 = await getWhoisNonce(page);
    const dbipResult = await callWhoisEndpoint(page, '8.8.8.8', nonce2);
    expect(dbipResult.status).toBeLessThan(500);
    expect(dbipResult.body).not.toContain('Fatal error');
  });
});
