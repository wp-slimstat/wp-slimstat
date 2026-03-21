/**
 * Shared test helpers for wp-config toggling, mu-plugin management,
 * DB access, and AJAX log reading.
 */
import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'url';
import * as mysql from 'mysql2/promise';
import { WP_ROOT, MYSQL_CONFIG, BASE_URL as ENV_BASE_URL } from './env';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// ─── Path constants ────────────────────────────────────────────────

const WP_CONFIG = path.join(WP_ROOT, 'wp-config.php');
const WP_CONTENT = path.join(WP_ROOT, 'wp-content');
const MU_PLUGINS = path.join(WP_CONTENT, 'mu-plugins');
const AJAX_LOG = path.join(WP_CONTENT, 'geoip-ajax-calls.log');
const LOGGER_SRC = path.join(__dirname, 'ajax-logger-mu-plugin.php');
const LOGGER_DEST = path.join(MU_PLUGINS, 'geoip-ajax-logger.php');
const NONCE_HELPER_SRC = path.join(__dirname, 'nonce-helper-mu-plugin.php');
const NONCE_HELPER_DEST = path.join(MU_PLUGINS, 'nonce-helper-mu-plugin.php');
const CRON_LINE = "define('DISABLE_WP_CRON', true);";

// ─── wp-config.php toggler ─────────────────────────────────────────

let wpConfigBackup: string | null = null;

function injectWpConfigLine(line: string): void {
  const content = fs.readFileSync(WP_CONFIG, 'utf8');
  if (wpConfigBackup === null) wpConfigBackup = content;
  if (content.includes(line)) return; // already set
  const marker = "/* That's all, stop editing!";
  const idx = content.indexOf(marker);
  if (idx === -1) throw new Error('Cannot find stop-editing marker in wp-config.php');
  fs.writeFileSync(WP_CONFIG, content.slice(0, idx) + line + '\n' + content.slice(idx), 'utf8');
}

export function enableDisableWpCron(): void {
  injectWpConfigLine(CRON_LINE);
}

export function restoreWpConfig(): void {
  if (wpConfigBackup !== null) {
    fs.writeFileSync(WP_CONFIG, wpConfigBackup, 'utf8');
    wpConfigBackup = null;
  }
}

// ─── MU-Plugin manifest ───────────────────────────────────────────

interface MuPluginEntry { sourceFile: string; deployedFile: string; }

const MU_PLUGIN_MANIFEST: MuPluginEntry[] = [
  { sourceFile: 'ajax-logger-mu-plugin.php', deployedFile: 'geoip-ajax-logger.php' },
  { sourceFile: 'cron-frontend-shim-mu-plugin.php', deployedFile: 'cron-frontend-shim-mu-plugin.php' },
  { sourceFile: 'nonce-helper-mu-plugin.php', deployedFile: 'nonce-helper-mu-plugin.php' },
  { sourceFile: 'option-mutator-mu-plugin.php', deployedFile: 'option-mutator-mu-plugin.php' },
  { sourceFile: 'server-tracking-mu-plugin.php', deployedFile: 'server-tracking-mu-plugin.php' },
  { sourceFile: 'header-injector-mu-plugin.php', deployedFile: 'header-injector-mu-plugin.php' },
  { sourceFile: 'consent-simulator-mu-plugin.php', deployedFile: 'consent-simulator-mu-plugin.php' },
  { sourceFile: 'version-floor-test-mu-plugin.php', deployedFile: 'version-floor-test-mu-plugin.php' },
  { sourceFile: 'early-textdomain-mu-plugin.php', deployedFile: 'early-textdomain-mu-plugin.php' },
  { sourceFile: 'mail-sink-mu-plugin.php', deployedFile: 'mail-sink-mu-plugin.php' },
  { sourceFile: 'rewrite-flush-mu-plugin.php', deployedFile: 'rewrite-flush-mu-plugin.php' },
  { sourceFile: 'plugin-lifecycle-mu-plugin.php', deployedFile: 'plugin-lifecycle-mu-plugin.php' },
  { sourceFile: 'custom-db-simulator-mu-plugin.php', deployedFile: 'custom-db-simulator-mu-plugin.php' },
  { sourceFile: 'calendar-ext-simulator-mu-plugin.php', deployedFile: 'calendar-ext-simulator-mu-plugin.php' },
];

// ─── Generic MU-Plugin install/uninstall by name ──────────────────

export function installMuPluginByName(name: string): void {
  const entry = MU_PLUGIN_MANIFEST.find((e) => e.sourceFile === name);
  if (!entry) throw new Error(`MU-Plugin "${name}" not found in manifest`);
  fs.mkdirSync(MU_PLUGINS, { recursive: true });
  fs.copyFileSync(path.join(__dirname, entry.sourceFile), path.join(MU_PLUGINS, entry.deployedFile));
}

export function uninstallMuPluginByName(name: string): void {
  if (isGlobalMuPluginsManaged()) return;
  const entry = MU_PLUGIN_MANIFEST.find((e) => e.deployedFile === name || e.sourceFile === name);
  if (!entry) throw new Error(`MU-Plugin "${name}" not found in manifest`);
  const dest = path.join(MU_PLUGINS, entry.deployedFile);
  if (fs.existsSync(dest)) fs.unlinkSync(dest);
}

/**
 * Sentinel file placed by installAllTestMuPlugins() to signal that
 * per-spec afterAll uninstall calls should be no-ops.
 * This persists across the separate globalSetup worker and the test workers.
 */
const GLOBAL_MU_SENTINEL = path.join(MU_PLUGINS, '.e2e-global-managed');

function isGlobalMuPluginsManaged(): boolean {
  return fs.existsSync(GLOBAL_MU_SENTINEL);
}

export function installAllTestMuPlugins(): void {
  fs.mkdirSync(MU_PLUGINS, { recursive: true });
  for (const entry of MU_PLUGIN_MANIFEST) {
    fs.copyFileSync(path.join(__dirname, entry.sourceFile), path.join(MU_PLUGINS, entry.deployedFile));
  }
  fs.writeFileSync(GLOBAL_MU_SENTINEL, '', 'utf8');
}

export function uninstallAllTestMuPlugins(): void {
  if (fs.existsSync(GLOBAL_MU_SENTINEL)) fs.unlinkSync(GLOBAL_MU_SENTINEL);
  for (const entry of MU_PLUGIN_MANIFEST) {
    const dest = path.join(MU_PLUGINS, entry.deployedFile);
    if (fs.existsSync(dest)) fs.unlinkSync(dest);
  }
}

// ─── MU-Plugin manager (legacy) ───────────────────────────────────

export function installMuPlugin(): void {
  fs.mkdirSync(MU_PLUGINS, { recursive: true });
  fs.copyFileSync(LOGGER_SRC, LOGGER_DEST);
}

export function uninstallMuPlugin(): void {
  if (isGlobalMuPluginsManaged()) return;
  if (fs.existsSync(LOGGER_DEST)) fs.unlinkSync(LOGGER_DEST);
}

// ─── Nonce helper MU-Plugin (legacy) ─────────────────────────────

export function installNonceHelper(): void {
  fs.mkdirSync(MU_PLUGINS, { recursive: true });
  fs.copyFileSync(NONCE_HELPER_SRC, NONCE_HELPER_DEST);
}

export function uninstallNonceHelper(): void {
  if (isGlobalMuPluginsManaged()) return;
  if (fs.existsSync(NONCE_HELPER_DEST)) fs.unlinkSync(NONCE_HELPER_DEST);
}

// ─── AJAX log reader ───────────────────────────────────────────────

export function clearAjaxLog(): void {
  if (fs.existsSync(AJAX_LOG)) fs.unlinkSync(AJAX_LOG);
}

export interface AjaxLogEntry {
  time: number;
  user: number;
  referer: string;
  ip: string;
}

export function readAjaxLog(): AjaxLogEntry[] {
  if (!fs.existsSync(AJAX_LOG)) return [];
  const raw = fs.readFileSync(AJAX_LOG, 'utf8').trim();
  if (!raw) return [];
  return raw.split('\n').filter(Boolean).map((line) => JSON.parse(line));
}

// ─── MySQL helper ──────────────────────────────────────────────────

let pool: mysql.Pool | null = null;

export function getPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool(MYSQL_CONFIG);
  }
  return pool;
}

export async function closeDb(): Promise<void> {
  if (pool) {
    await pool.end();
    pool = null;
  }
}

export async function clearGeoipTimestamp(): Promise<void> {
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name = 'slimstat_last_geoip_dl'"
  );
}

export async function getGeoipTimestamp(): Promise<number | null> {
  const [rows] = await getPool().execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_last_geoip_dl'"
  ) as any;
  if (rows.length === 0) return null;
  return parseInt(rows[0].option_value, 10);
}

export async function getSlimstatSettings(): Promise<Record<string, any>> {
  const [rows] = await getPool().execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_options'"
  ) as any;
  if (rows.length === 0) return {};
  // PHP serialized — we'll use a simple regex for the key we need
  return { _raw: rows[0].option_value };
}

export async function setSlimstatSetting(key: string, value: string): Promise<void> {
  // Read current serialized settings, do a targeted string replacement
  const [rows] = await getPool().execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_options'"
  ) as any;
  if (rows.length === 0) return;
  let raw: string = rows[0].option_value;

  // PHP serialized format: s:<len>:"key";s:<len>:"value";
  const escapedKey = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const keyPattern = new RegExp(
    `s:\\d+:"${escapedKey}";s:\\d+:"[^"]*"`,
    'g'
  );
  const replacement = `s:${key.length}:"${key}";s:${value.length}:"${value}"`;

  if (keyPattern.test(raw)) {
    keyPattern.lastIndex = 0; // Reset after test() advanced it
    raw = raw.replace(keyPattern, replacement);
  } else {
    // Key not present — not safe to inject into PHP serialized without full parser.
    // For testing, we only modify existing keys.
    console.warn(`Key "${key}" not found in slimstat_options, skipping`);
    return;
  }

  // Fix the total count in the serialized array header (a:<count>:{...})
  // The count doesn't change since we're replacing, not adding
  await getPool().execute(
    "UPDATE wp_options SET option_value = ? WHERE option_name = 'slimstat_options'",
    [raw]
  );
}

// ─── Combined setup/teardown ───────────────────────────────────────

let savedProviderValue: string | null = null;

export async function setupTest(): Promise<void> {
  enableDisableWpCron();
  installMuPlugin();
  clearAjaxLog();
  await clearGeoipTimestamp();
}

export async function teardownTest(): Promise<void> {
  restoreWpConfig();
  uninstallMuPlugin();
  clearAjaxLog();
  if (savedProviderValue !== null) {
    await setSlimstatSetting('geolocation_provider', savedProviderValue);
    savedProviderValue = null;
  }
}

export async function setProviderDisabled(): Promise<void> {
  // Save current value for restore
  const [rows] = await getPool().execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_options'"
  ) as any;
  if (rows.length > 0) {
    const raw: string = rows[0].option_value;
    const match = raw.match(/s:\d+:"geolocation_provider";s:\d+:"([^"]*)"/);
    savedProviderValue = match ? match[1] : null;
  }
  await setSlimstatSetting('geolocation_provider', 'disable');
}

// ─── Cron frontend shim mu-plugin ────────────────────────────────

const CRON_SHIM_SRC = path.join(__dirname, 'cron-frontend-shim-mu-plugin.php');
const CRON_SHIM_DEST = path.join(MU_PLUGINS, 'cron-frontend-shim-mu-plugin.php');
const E2E_TESTING_LINE = "define('SLIMSTAT_E2E_TESTING', true);";

export function installCronFrontendShim(): void {
  fs.mkdirSync(MU_PLUGINS, { recursive: true });
  fs.copyFileSync(CRON_SHIM_SRC, CRON_SHIM_DEST);
  injectWpConfigLine(E2E_TESTING_LINE);
}

export function uninstallCronFrontendShim(): void {
  if (isGlobalMuPluginsManaged()) return;
  if (fs.existsSync(CRON_SHIM_DEST)) fs.unlinkSync(CRON_SHIM_DEST);
  // E2E_TESTING_LINE is removed when restoreWpConfig() runs in teardown
}

// ─── GeoIP timestamp snapshot/restore ────────────────────────────

let savedGeoipRow: { value: string; autoload: string } | null | undefined = undefined;

export async function snapshotGeoipTimestamp(): Promise<void> {
  const [rows] = await getPool().execute(
    "SELECT option_value, autoload FROM wp_options WHERE option_name = 'slimstat_last_geoip_dl'"
  ) as any;
  savedGeoipRow = rows.length > 0 ? { value: rows[0].option_value, autoload: rows[0].autoload } : null;
}

export async function restoreGeoipTimestamp(): Promise<void> {
  if (savedGeoipRow === undefined) return;
  if (savedGeoipRow === null) {
    await clearGeoipTimestamp();
  } else {
    await getPool().execute(
      "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('slimstat_last_geoip_dl', ?, ?) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
      [savedGeoipRow.value, savedGeoipRow.autoload]
    );
  }
  savedGeoipRow = undefined;
}

// ─── Option mutator mu-plugin (safe WP-native serialization) ────

const OPTION_MUTATOR_SRC = path.join(__dirname, 'option-mutator-mu-plugin.php');
const OPTION_MUTATOR_DEST = path.join(MU_PLUGINS, 'option-mutator-mu-plugin.php');
const BASE_URL = ENV_BASE_URL;

export function installOptionMutator(): void {
  fs.mkdirSync(MU_PLUGINS, { recursive: true });
  fs.copyFileSync(OPTION_MUTATOR_SRC, OPTION_MUTATOR_DEST);
}

export function uninstallOptionMutator(): void {
  if (isGlobalMuPluginsManaged()) return;
  if (fs.existsSync(OPTION_MUTATOR_DEST)) fs.unlinkSync(OPTION_MUTATOR_DEST);
}

/**
 * Set a slimstat option using WordPress's native serialization.
 * Requires the option-mutator mu-plugin to be installed and an authenticated page context.
 */
export async function setSlimstatOption(page: import('@playwright/test').Page, key: string, value: string): Promise<void> {
  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'test_set_slimstat_option', key, value },
  });
  if (!res.ok()) {
    throw new Error(`setSlimstatOption(${key}, ${value}) failed: ${res.status()}`);
  }
}

/**
 * Delete a slimstat option key using WordPress's native serialization.
 */
export async function deleteSlimstatOption(page: import('@playwright/test').Page, key: string): Promise<void> {
  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'test_set_slimstat_option', key, delete: '1' },
  });
  if (!res.ok()) {
    throw new Error(`deleteSlimstatOption(${key}) failed: ${res.status()}`);
  }
}

// ─── Full settings snapshot/restore ──────────────────────────────

let savedOptionsSnapshot: string | null = null;

export async function snapshotSlimstatOptions(): Promise<void> {
  const [rows] = await getPool().execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_options'"
  ) as any;
  savedOptionsSnapshot = rows.length > 0 ? rows[0].option_value : null;
}

export async function restoreSlimstatOptions(): Promise<void> {
  if (savedOptionsSnapshot === null) return;
  // Upsert: works whether the row exists, was deleted by simulateFreshInstall(),
  // or the test failed before WordPress could recreate it
  await getPool().execute(
    "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('slimstat_options', ?, 'yes') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
    [savedOptionsSnapshot]
  );
  savedOptionsSnapshot = null;
}

// ─── Existence-aware option snapshot/restore ─────────────────────

interface OptionSnapshot { exists: boolean; value: string | null; }
const optionSnapshots = new Map<string, OptionSnapshot>();

export async function snapshotOption(optionName: string): Promise<void> {
  const [rows] = await getPool().execute(
    'SELECT option_value FROM wp_options WHERE option_name = ?', [optionName]
  ) as any;
  optionSnapshots.set(optionName, rows.length > 0 ? { exists: true, value: rows[0].option_value } : { exists: false, value: null });
}

export async function restoreOption(optionName: string): Promise<void> {
  const snap = optionSnapshots.get(optionName);
  if (!snap) return;
  if (!snap.exists) {
    await getPool().execute('DELETE FROM wp_options WHERE option_name = ?', [optionName]);
  } else {
    await getPool().execute(
      "INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, 'yes') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
      [optionName, snap.value]
    );
  }
  optionSnapshots.delete(optionName);
}

export async function restoreAllOptions(): Promise<void> {
  for (const name of optionSnapshots.keys()) {
    await restoreOption(name);
  }
}

// ─── Stats table helpers ─────────────────────────────────────────

export async function clearStatsTable(): Promise<void> {
  await getPool().execute("TRUNCATE TABLE wp_slim_stats");
}

export async function getLatestStat(testMarker: string): Promise<{ country: string; city: string; location: string } | null> {
  const [rows] = await getPool().execute(
    "SELECT country, city, location FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1",
    [`%${testMarker}%`]
  ) as any;
  return rows.length > 0 ? rows[0] : null;
}

export async function getLatestStatFull(testMarker: string): Promise<{ id: number; resource: string; outbound_resource: string | null; dt_out: number; country: string; city: string } | null> {
  const [rows] = await getPool().execute(
    "SELECT id, resource, outbound_resource, dt_out, country, city FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1",
    [`%${testMarker}%`]
  ) as any;
  return rows.length > 0 ? rows[0] : null;
}

export async function getLatestStatWithIp(testMarker: string): Promise<{ ip: string; country: string; city: string; location: string } | null> {
  const [rows] = await getPool().execute(
    "SELECT ip, country, city, location FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1",
    [`%${testMarker}%`]
  ) as any;
  return rows.length > 0 ? rows[0] : null;
}

export async function getLatestStatByIp(): Promise<{ country: string; city: string; location: string } | null> {
  const [rows] = await getPool().execute(
    "SELECT country, city, location FROM wp_slim_stats ORDER BY id DESC LIMIT 1"
  ) as any;
  return rows.length > 0 ? rows[0] : null;
}

// ─── Download tracking helpers ────────────────────────────────────

export async function waitForDownloadRow(
  resourceMarker: string,
  timeoutMs = 20_000,
): Promise<{ id: number; resource: string; content_type: string } | null> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const [rows] = await getPool().execute(
      "SELECT id, resource, content_type FROM wp_slim_stats WHERE content_type = 'download' AND resource LIKE ? ORDER BY id DESC LIMIT 1",
      [`%${resourceMarker}%`],
    ) as any;
    if (rows.length > 0) return rows[0];
    await new Promise((r) => setTimeout(r, 500));
  }
  return null;
}

export async function getDownloadCount(resourceMarker: string): Promise<number> {
  const [rows] = await getPool().execute(
    "SELECT COUNT(*) as cnt FROM wp_slim_stats WHERE content_type = 'download' AND resource LIKE ?",
    [`%${resourceMarker}%`],
  ) as any;
  return rows[0].cnt;
}

// ─── Shared pageview/tracker pollers ──────────────────────────────

export async function waitForPageviewRow(
  marker: string,
  timeoutMs = 20_000,
): Promise<{ id: number; resource: string; outbound_resource: string | null; dt_out: number; country: string; city: string } | null> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const row = await getLatestStatFull(marker);
    if (row) return row;
    await new Promise((r) => setTimeout(r, 500));
  }
  return null;
}

export async function waitForTrackerId(page: import('@playwright/test').Page): Promise<string> {
  await page.waitForFunction(
    () => {
      const p = (window as any).SlimStatParams;
      return p && p.id && parseInt(p.id, 10) > 0;
    },
    { timeout: 10_000 },
  );
  return page.evaluate(() => (window as any).SlimStatParams?.id ?? '');
}

// ─── Scenario helpers ────────────────────────────────────────────

export async function simulateFreshInstall(): Promise<void> {
  await getPool().execute("DELETE FROM wp_options WHERE option_name = 'slimstat_options'");
}

export async function simulateLegacyUpgrade(page: import('@playwright/test').Page, enableMaxmind: string): Promise<void> {
  await setSlimstatOption(page, 'enable_maxmind', enableMaxmind);
  await deleteSlimstatOption(page, 'geolocation_provider');
}

// ─── Shared waitForStat poller ───────────────────────────────────

export async function waitForStat(marker: string, timeoutMs = 10_000, intervalMs = 250): Promise<{ country: string; city: string; location: string } | null> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const stat = await getLatestStat(marker);
    if (stat) return stat;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return null;
}

export async function waitForStatWithIp(marker: string, timeoutMs = 10_000, intervalMs = 250): Promise<{ ip: string; country: string; city: string; location: string } | null> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const stat = await getLatestStatWithIp(marker);
    if (stat) return stat;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return null;
}

// ─── Anonymous visit helper ──────────────────────────────────────

export async function visitAsAnonymous(browser: import('@playwright/test').Browser, url: string): Promise<import('@playwright/test').Page> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  return page;
}

// ─── Header override helpers (for header-injector mu-plugin) ─────

const HEADER_OVERRIDES_FILE = path.join(WP_CONTENT, 'e2e-header-overrides.json');

/**
 * Write header overrides that the header-injector mu-plugin will read.
 * Requires SLIMSTAT_E2E_TESTING constant and the mu-plugin to be installed.
 */
export function setHeaderOverrides(headers: Record<string, string>): void {
  fs.writeFileSync(HEADER_OVERRIDES_FILE, JSON.stringify(headers), 'utf8');
}

/** Remove the header overrides file. */
export function clearHeaderOverrides(): void {
  fs.rmSync(HEADER_OVERRIDES_FILE, { force: true });
}

/**
 * Install the header-injector mu-plugin and inject SLIMSTAT_E2E_TESTING into wp-config.
 * Call once in beforeAll/beforeEach for tests that need server-side header injection.
 */
export function installHeaderInjector(): void {
  installMuPluginByName('header-injector-mu-plugin.php');
  injectWpConfigLine(E2E_TESTING_LINE);
}

/** Uninstall the header-injector mu-plugin, clear overrides, and restore wp-config. */
export function uninstallHeaderInjector(): void {
  uninstallMuPluginByName('header-injector-mu-plugin.php');
  clearHeaderOverrides();
  restoreWpConfig();
}

// ─── Rewrite-flush mu-plugin (for adblock bypass tests) ─────────

export function installRewriteFlush(): void {
  installMuPluginByName('rewrite-flush-mu-plugin.php');
  injectWpConfigLine(E2E_TESTING_LINE);
}

export function uninstallRewriteFlush(): void {
  uninstallMuPluginByName('rewrite-flush-mu-plugin.php');
  restoreWpConfig();
}

// ─── Plugin-lifecycle mu-plugin (for health-check tests) ────────

export function installPluginLifecycle(): void {
  installMuPluginByName('plugin-lifecycle-mu-plugin.php');
  injectWpConfigLine(E2E_TESTING_LINE);
}

export function uninstallPluginLifecycle(): void {
  uninstallMuPluginByName('plugin-lifecycle-mu-plugin.php');
  restoreWpConfig();
}

// ─── Fixture file cleanup ────────────────────────────────────────

export function cleanupFixtureFiles(): void {
  const fixtures = ['e2e-header-overrides.json', 'e2e-consent-state.json'];
  for (const f of fixtures) {
    const p = path.join(WP_CONTENT, f);
    if (fs.existsSync(p)) fs.unlinkSync(p);
  }
}

// ─── Shared CPT MU-plugin for E2E tests ──────────────────────────

const CPT_MU_PLUGIN_PATH = path.join(MU_PLUGINS, 'e2e-test-product-cpt.php');

export const CPT_MU_PLUGIN_CONTENT = `<?php
/**
 * E2E Test: Register 'product' CPT for testing.
 */
if (!defined('ABSPATH')) exit;
add_action('init', function() {
    register_post_type('product', [
        'public'       => true,
        'label'        => 'Products',
        'has_archive'  => true,
        'rewrite'      => ['slug' => 'product'],
        'supports'     => ['title', 'editor'],
        'show_in_rest' => true,
    ]);
    // Flush rewrite rules once after CPT registration (not on every request)
    if (!get_transient('e2e_product_cpt_flushed')) {
        flush_rewrite_rules();
        set_transient('e2e_product_cpt_flushed', 1, HOUR_IN_SECONDS);
    }
});
`;

export function installCptMuPlugin(): void {
  fs.mkdirSync(MU_PLUGINS, { recursive: true });
  fs.writeFileSync(CPT_MU_PLUGIN_PATH, CPT_MU_PLUGIN_CONTENT, 'utf8');
}

export function uninstallCptMuPlugin(): void {
  if (isGlobalMuPluginsManaged()) return;
  if (fs.existsSync(CPT_MU_PLUGIN_PATH)) fs.unlinkSync(CPT_MU_PLUGIN_PATH);
}
