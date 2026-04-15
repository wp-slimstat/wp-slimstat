/**
 * Centralized environment configuration for E2E tests.
 * All machine-specific values read from env vars with sensible defaults.
 */
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

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
        port: parseInt(process.env.MYSQL_PORT || '3306', 10),
      }),
  user: process.env.MYSQL_USER || 'root',
  password: process.env.MYSQL_PASSWORD || 'root',
  database: process.env.MYSQL_DATABASE || 'local',
  waitForConnections: true,
  connectionLimit: 5,
};
