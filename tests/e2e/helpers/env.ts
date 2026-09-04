/**
 * Centralized environment configuration for E2E tests.
 * All machine-specific values read from env vars with sensible defaults.
 */
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

/**
 * Integer from an env var. Unset OR empty means the fallback; a non-integer is an error.
 *
 * One rule for every numeric env read: before this, `MYSQL_PORT` used `||` (empty → default)
 * while the Playwright overrides used `!== undefined ? Number(...)` (empty → 0, `'abc'` → NaN).
 */
export function envInt(name: string, fallback: number): number {
  const raw = process.env[name];
  if (!raw) return fallback;
  const n = Number(raw);
  if (!Number.isInteger(n)) throw new Error(`${name} must be an integer, got "${raw}"`);
  return n;
}

/** WordPress site base URL */
export const BASE_URL = process.env.TEST_BASE_URL || 'http://localhost:10003';

/** wp-slimstat plugin directory (derived from this file's location) */
export const PLUGIN_DIR = path.resolve(__dirname, '..', '..', '..');

/** WordPress installation root.
 *  Override via WP_ROOT env var (required in CI where wp-env runs inside Docker
 *  and the checkout path is not a real WordPress installation).
 *  Local default: 3 levels up from the plugin dir (wp-content/plugins/wp-slimstat → WP root).
 */
export const WP_ROOT = process.env.WP_ROOT || path.resolve(PLUGIN_DIR, '..', '..', '..');

/** MySQL unix socket path. Set to empty string in CI to use TCP instead. */
const _mysqlSocket = process.env.MYSQL_SOCKET ?? '/tmp/mysql.sock';

/** MySQL connection config.
 *  Uses Unix socket when MYSQL_SOCKET is set (local dev default: /tmp/mysql.sock).
 *  Falls back to TCP (MYSQL_HOST / MYSQL_PORT) when MYSQL_SOCKET is empty string.
 */
/** WordPress admin credentials. CI default: admin / password (wp-env). */
export const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'parhumm';
export const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'testpass123';

export const MYSQL_CONFIG = {
  ...(_mysqlSocket
    ? { socketPath: _mysqlSocket }
    : {
        host: process.env.MYSQL_HOST || '127.0.0.1',
        port: envInt('MYSQL_PORT', 3306),
      }),
  user: process.env.MYSQL_USER || 'root',
  password: process.env.MYSQL_PASSWORD || 'root',
  database: process.env.MYSQL_DATABASE || 'local',
  waitForConnections: true,
  connectionLimit: 5,
};

/**
 * Data-safety guard. The E2E suite runs destructive operations
 * (TRUNCATE wp_slim_stats / wp_slim_events, option mutation), so it must never
 * connect to a real site's database. The Local by Flywheel dev site here uses
 * the database named "local"; CI / wp-env / Playground use throwaway DBs
 * (e.g. "wordpress"). Call this before opening any test DB pool.
 *
 * The gate (unless overridden) requires BOTH a local host AND a disposable
 * database name. Configure via:
 *   - ALLOW_LIVE_DB=1            — bypass entirely (use only against a copy you own)
 *   - E2E_ALLOWED_DB_HOSTS       — comma-separated safe hosts (default localhost,127.0.0.1)
 *   - E2E_ALLOWED_DB_PATTERN     — regex of disposable DB names (default below)
 */
export function assertSafeTestDatabase(): void {
  if (process.env.ALLOW_LIVE_DB === '1') return;
  // CI runs against an ephemeral wp-env/Docker database destroyed after the job.
  if (process.env.CI === 'true') return;

  const cfg = MYSQL_CONFIG as { database: string; host?: string; socketPath?: string };
  const db = cfg.database;
  // A unix socket is local by definition; otherwise the TCP host must be local.
  const host = cfg.socketPath ? 'socket' : String(cfg.host ?? '');
  const allowedHosts = (process.env.E2E_ALLOWED_DB_HOSTS ?? 'localhost,127.0.0.1')
    .split(',')
    .map((h) => h.trim());
  const hostSafe = host === 'socket' || allowedHosts.includes(host);

  // wp-env / Playground default the DB name to "wordpress"; the rest are common
  // throwaway prefixes. The live Local site's "local" is intentionally excluded.
  const dbPattern = process.env.E2E_ALLOWED_DB_PATTERN
    ? new RegExp(process.env.E2E_ALLOWED_DB_PATTERN)
    : /^(wordpress|tests?[-_]|test$|playground|wp[-_]?env|sandbox)/i;

  if (!hostSafe || !dbPattern.test(db)) {
    throw new Error(
      `E2E data-safety guard: refusing to run against database "${db}" on host "${host}" — ` +
        'these tests TRUNCATE wp_slim_stats / wp_slim_events and would wipe real analytics. ' +
        'Run against wp-env or `npm run test:e2e:playground` (throwaway DBs), or set ' +
        'ALLOW_LIVE_DB=1 if this is a disposable copy you control.'
    );
  }
}
