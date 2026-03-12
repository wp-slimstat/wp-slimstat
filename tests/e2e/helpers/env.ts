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

/** MySQL unix socket path */
export const MYSQL_SOCKET = process.env.MYSQL_SOCKET || '/tmp/mysql.sock';

/** WordPress installation root */
export const WP_ROOT = process.env.WP_ROOT || '/tmp/wordpress';

/** wp-slimstat plugin directory (derived from this file's location) */
export const PLUGIN_DIR = path.resolve(__dirname, '..', '..', '..');

/** MySQL connection config */
export const MYSQL_CONFIG = {
  socketPath: MYSQL_SOCKET,
  user: process.env.MYSQL_USER || 'root',
  password: process.env.MYSQL_PASSWORD || 'root',
  database: process.env.MYSQL_DATABASE || 'local',
  waitForConnections: true,
  connectionLimit: 5,
};
