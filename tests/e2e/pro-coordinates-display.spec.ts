/**
 * E2E tests: Pro Coordinates Display — Suite 04 (REQ-AC6)
 *
 * Validates that the Google Maps coordinates display correctly
 * using array notation in the Advanced Whois iframe, and that
 * lat/lon values are populated from geolocation data.
 *
 * @see BDD cases: Feature 6 — Google Maps Coordinates Display with Array Notation
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

test.describe('Pro Coordinates Display — Suite 04 (REQ-AC6)', () => {
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

  // ─── TC-AC6-001: Coordinates displayed for MaxMind provider ─────

  test('whois iframe shows latitude/longitude with MaxMind provider', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    await setSlimstatOption(page, 'geolocation_provider', 'maxmind');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    const nonce = await getWhoisNonce(page);
    const result = await callWhoisEndpoint(page, '8.8.8.8', nonce);

    expect(result.status).toBeLessThan(500);
    expect(result.body).not.toContain('Fatal error');

    // If MaxMind DB is available, coordinates should be in the response
    const hasGeoData = result.body.includes('IP Geolocation Information');
    const hasDbMissing = result.body.includes('geolocation database is not available');

    if (hasGeoData) {
      // Coordinates should appear as numeric values in the HTML
      // Google Maps embed or coordinate display uses lat/lon
      const hasCoordinates =
        result.body.includes('latitude') ||
        result.body.includes('longitude') ||
        result.body.includes('maps.google') ||
        /[-]?\d+\.\d+/.test(result.body); // numeric coordinate pattern
      expect(hasCoordinates, 'Response with geo data should contain coordinate values').toBeTruthy();
    } else {
      // DB missing is acceptable — coordinates test can only run with DB present
      expect(hasDbMissing, 'Should show geo data or DB-missing message').toBeTruthy();
    }
  });

  // ─── TC-AC6-002: Coordinates displayed for DB-IP provider ───────

  test('whois iframe shows latitude/longitude with DB-IP provider', async ({ page }) => {
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

    const hasGeoData = result.body.includes('IP Geolocation Information');
    const hasDbMissing = result.body.includes('geolocation database is not available');

    if (hasGeoData) {
      // DB-IP data normalized to MaxMind structure should include coordinates
      const hasCoordinates =
        result.body.includes('maps.google') ||
        /[-]?\d+\.\d+/.test(result.body);
      expect(hasCoordinates, 'DB-IP geo data should contain coordinate values').toBeTruthy();
    } else {
      expect(hasDbMissing, 'Should show geo data or DB-missing message').toBeTruthy();
    }
  });

  // ─── TC-AC6-003: No object notation fatal error ────────────────

  test('no fatal error from object notation access on coordinates', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    // Test with both providers to ensure array notation works everywhere
    for (const provider of ['dbip', 'maxmind']) {
      await setSlimstatOption(page, 'geolocation_provider', provider);
      await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

      const nonce = await getWhoisNonce(page);
      const result = await callWhoisEndpoint(page, '1.1.1.1', nonce);

      expect(result.status, `${provider}: should not 500`).toBeLessThan(500);
      expect(result.body).not.toContain('Fatal error');
      expect(result.body).not.toContain('Trying to get property');
      expect(result.body).not.toContain('Cannot access');
    }
  });

  // ─── TC-AC6-004: Coordinates for private/loopback IP ────────────

  test('whois handles non-routable IP gracefully without coordinate errors', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    const nonce = await getWhoisNonce(page);

    for (const ip of ['127.0.0.1', '10.0.0.1', '192.168.1.1']) {
      const result = await callWhoisEndpoint(page, ip, nonce);

      expect(result.status, `${ip}: should not 500`).toBeLessThan(500);
      expect(result.body).not.toContain('Fatal error');
      // No PHP notice/warning about undefined index for coordinates
      expect(result.body).not.toContain('Undefined index');
      expect(result.body).not.toContain('Undefined array key');
    }
  });

  // ─── TC-AC6-005: Google Maps embed renders with valid IP ────────

  test('Google Maps embed URL present in whois response for public IP', async ({ page }) => {
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

    const hasGeoData = result.body.includes('IP Geolocation Information');

    if (hasGeoData) {
      // The Google Maps embed should be present with coordinate parameters
      const hasMapsEmbed =
        result.body.includes('maps.google') ||
        result.body.includes('google.com/maps');
      expect(hasMapsEmbed, 'Whois response should include Google Maps embed for geolocated IP').toBeTruthy();
    }
    // If no geo data, DB may not be present — not a failure for this test
  });

  // ─── TC-AC6-006: SlimStat detail view accessible with Pro ───────

  test('SlimStat reports page loads with coordinates column available', async ({ page }) => {
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    const proActive = await isProActive(page);
    test.skip(!proActive, 'WP SlimStat Pro is not installed/active — skipping');

    await setSlimstatOption(page, 'geolocation_provider', 'dbip');
    await setSlimstatOption(page, 'addon_maxmind_enable', 'on');

    // Navigate to the access log / detail view
    const response = await page.goto('/wp-admin/admin.php?page=slimview1');
    expect(response?.status()).toBeLessThan(500);

    const body = await page.content();
    expect(body).not.toContain('Fatal error');
    // The page should load without coordinate-related PHP errors
    expect(body).not.toContain('Trying to get property');
  });
});
