/**
 * Shared test helpers for wp-config toggling, mu-plugin management,
 * DB access, and AJAX log reading.
 */
import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'url';
import * as mysql from 'mysql2/promise';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// ─── Path constants ────────────────────────────────────────────────

const WP_ROOT = '/Users/parhumm/Local Sites/test/app/public';
const WP_CONFIG = path.join(WP_ROOT, 'wp-config.php');
const WP_CONTENT = path.join(WP_ROOT, 'wp-content');
const MU_PLUGINS = path.join(WP_CONTENT, 'mu-plugins');
const AJAX_LOG = path.join(WP_CONTENT, 'geoip-ajax-calls.log');
const LOGGER_SRC = path.join(__dirname, 'ajax-logger-mu-plugin.php');
const LOGGER_DEST = path.join(MU_PLUGINS, 'geoip-ajax-logger.php');
const CRON_LINE = "define('DISABLE_WP_CRON', true);";

const MYSQL_SOCKET = '/Users/parhumm/Library/Application Support/Local/run/X-JdmZXIa/mysql/mysqld.sock';

// ─── wp-config.php toggler ─────────────────────────────────────────

let wpConfigBackup: string | null = null;

export function enableDisableWpCron(): void {
  const content = fs.readFileSync(WP_CONFIG, 'utf8');
  wpConfigBackup = content;
  if (content.includes('DISABLE_WP_CRON')) return; // already set
  const marker = "/* That's all, stop editing!";
  const idx = content.indexOf(marker);
  if (idx === -1) throw new Error('Cannot find stop-editing marker in wp-config.php');
  const updated = content.slice(0, idx) + CRON_LINE + '\n' + content.slice(idx);
  fs.writeFileSync(WP_CONFIG, updated, 'utf8');
}

export function restoreWpConfig(): void {
  if (wpConfigBackup !== null) {
    fs.writeFileSync(WP_CONFIG, wpConfigBackup, 'utf8');
    wpConfigBackup = null;
  }
}

// ─── MU-Plugin manager ─────────────────────────────────────────────

export function installMuPlugin(): void {
  fs.mkdirSync(MU_PLUGINS, { recursive: true });
  fs.copyFileSync(LOGGER_SRC, LOGGER_DEST);
}

export function uninstallMuPlugin(): void {
  if (fs.existsSync(LOGGER_DEST)) fs.unlinkSync(LOGGER_DEST);
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

function getPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool({
      socketPath: MYSQL_SOCKET,
      user: 'root',
      password: 'root',
      database: 'local',
      waitForConnections: true,
      connectionLimit: 5,
    });
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
  const keyPattern = new RegExp(
    `s:\\d+:"${key}";s:\\d+:"[^"]*"`,
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
