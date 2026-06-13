/**
 * Shared chart test utilities — single source of truth for chart E2E specs.
 *
 * Provides WP-CLI AJAX simulation, DB seeding, data extractors, and
 * timestamp helpers used across all chart-related test files.
 */
import { execSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { getPool } from './setup';
import { WP_ROOT, BASE_URL } from './env';

// ─── HTTP-driven AJAX helpers (used by browser-context specs) ───────────────

/**
 * Fixed past UTC range used by chart AJAX specs. Picked so existing rows
 * (or their absence) don't influence allowlist/validation outcomes.
 *   start: 2026-02-01 00:00 UTC
 *   end:   2026-03-31 23:59 UTC
 */
export const CHART_TEST_RANGE = { start: 1738368000, end: 1743379199 } as const;

/**
 * Obtain a `slimstat_chart_nonce` via the nonce-helper MU plugin.
 * Caller must have installed `nonce-helper-mu-plugin.php` in beforeAll.
 * Auth context is the test runner's WP session (admin, per harness).
 */
export async function getChartNonce(page: import('@playwright/test').Page): Promise<string> {
  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'test_create_nonce', nonce_action: 'slimstat_chart_nonce' },
  });
  if (!res.ok()) throw new Error(`test_create_nonce failed: HTTP ${res.status()}`);
  const body = await res.json();
  if (!body?.success || !body?.data?.nonce) {
    throw new Error(`test_create_nonce returned unexpected body: ${JSON.stringify(body)}`);
  }
  return body.data.nonce;
}

// ─── WP-CLI chart AJAX simulation ───────────────────────────────────────────

/**
 * Simulate Chart::ajaxFetchChartData() server-side via WP-CLI eval-file.
 * Returns parsed JSON response with { success, data: { data: { datasets, labels, ... } } }.
 */
export function fetchChartData(startTs: number, endTs: number, granularity: string): any {
  const tmpFile = path.join('/tmp', `slimstat-chart-${Date.now()}.php`);
  const phpCode = `<?php
wp_set_current_user(get_users(['role' => 'administrator', 'number' => 1])[0]->ID);

\$_POST['args'] = json_encode([
    'start' => ${startTs},
    'end' => ${endTs},
    'chart_data' => [
        'data1' => 'COUNT(id)',
        'data2' => 'COUNT( DISTINCT ip )',
    ],
]);
\$_POST['granularity'] = '${granularity}';
\$_REQUEST['granularity'] = '${granularity}';
\$_POST['nonce'] = wp_create_nonce('slimstat_chart_nonce');
\$_REQUEST['_ajax_nonce'] = \$_POST['nonce'];

global \$wpdb;
\$wpdb->query("DELETE FROM {\$wpdb->options} WHERE option_name LIKE '_transient_wp_slimstat_%' OR option_name LIKE '_transient_timeout_wp_slimstat_%'");

ob_start();
try {
    \\SlimStat\\Modules\\Chart::ajaxFetchChartData();
} catch (\\Throwable \$e) {
    ob_end_clean();
    echo json_encode(['error' => \$e->getMessage()]);
    exit;
}
\$output = ob_get_clean();
echo \$output;
`;

  fs.writeFileSync(tmpFile, phpCode);

  try {
    const raw = execSync(`wp eval-file "${tmpFile}" --path="${WP_ROOT}" 2>/dev/null`, {
      encoding: 'utf8',
      timeout: 30_000,
    });
    const parsed = extractJson(raw);
    if (parsed) return parsed;
    throw new Error(`No JSON in output: ${raw.substring(0, 300)}`);
  } catch (e: any) {
    if (e.stdout) {
      const parsed = extractJson(e.stdout);
      if (parsed) return parsed;
    }
    throw new Error(`WP-CLI chart call failed: ${e.message}`);
  } finally {
    try { fs.unlinkSync(tmpFile); } catch {}
  }
}

/**
 * Extract JSON from WP-CLI output that may contain PHP deprecation warnings.
 */
function extractJson(raw: string): any {
  const start = raw.indexOf('{"success"');
  if (start === -1) return null;
  try {
    return JSON.parse(raw.substring(start));
  } catch {
    let depth = 0;
    for (let i = start; i < raw.length; i++) {
      if (raw[i] === '{') depth++;
      if (raw[i] === '}') depth--;
      if (depth === 0) {
        try { return JSON.parse(raw.substring(start, i + 1)); } catch { return null; }
      }
    }
  }
  return null;
}

// ─── DB helpers ──────────────────────────────────────────────────────────────

/**
 * Insert `count` rows at a specific UTC timestamp, each with a distinct resource.
 * Rows are spaced 1 second apart by default to avoid exact duplicates.
 */
export async function insertRows(
  timestamp: number,
  count: number,
  label: string,
  userAgent = 'chart-e2e'
): Promise<void> {
  const pool = getPool();
  for (let i = 0; i < count; i++) {
    await pool.execute(
      `INSERT INTO wp_slim_stats (dt, ip, resource, browser, browser_version, platform, language, visit_id, user_agent)
       VALUES (?, '127.0.0.1', ?, 'test', '0', 'test', 'en', 1, ?)`,
      [timestamp + i, `/chart-${label}-${i}`, userAgent]
    );
  }
}

/**
 * TRUNCATE wp_slim_stats and clear chart transients.
 *
 * Safety: This is safe only because playwright.config.ts enforces workers=1.
 * Parallel workers would cause data races — do NOT change workers setting.
 */
export async function clearTestData(): Promise<void> {
  await getPool().execute("SET FOREIGN_KEY_CHECKS = 0");
  await getPool().execute("TRUNCATE TABLE wp_slim_stats");
  await getPool().execute("TRUNCATE TABLE wp_slim_events");
  await getPool().execute("SET FOREIGN_KEY_CHECKS = 1");
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name LIKE '_transient_wp_slimstat_%' OR option_name LIKE '_transient_timeout_wp_slimstat_%'"
  );
}

// ─── Data extractors (response structure: data.data.datasets.v1) ─────────────

export function getV1(json: any): number[] {
  return json?.data?.data?.datasets?.v1 ?? [];
}

export function getV1Prev(json: any): number[] {
  return json?.data?.data?.datasets_prev?.v1 ?? [];
}

export function getLabels(json: any): string[] {
  return json?.data?.data?.labels ?? [];
}

export function getPrevLabels(json: any): string[] {
  return json?.data?.data?.prev_labels ?? [];
}

export function sumArr(arr: number[]): number {
  return arr.reduce((a, b) => a + b, 0);
}

/** Convenience: sum of v1 dataset from a chart JSON response */
export function sumV1(json: any): number {
  return sumArr(getV1(json));
}

// ─── Timestamp helpers ───────────────────────────────────────────────────────

/** Get UTC midnight timestamp for a date string: '2026-03-14' → unix timestamp */
export function utcMidnight(dateStr: string): number {
  return Math.floor(new Date(dateStr + 'T00:00:00Z').getTime() / 1000);
}

/** Get UTC timestamp for a datetime string: '2026-03-14 23:59:59' → unix timestamp */
export function utcTimestamp(dateTimeStr: string): number {
  return Math.floor(new Date(dateTimeStr.replace(' ', 'T') + 'Z').getTime() / 1000);
}

/** Get day-of-week name for a timestamp */
export function dayName(ts: number): string {
  return new Date(ts * 1000).toUTCString().split(',')[0];
}

/**
 * Find the most recent day-of-week relative to a timestamp.
 * dayOfWeek: 0=Sunday, 1=Monday, ..., 6=Saturday
 */
export function mostRecentDayOfWeek(dayOfWeek: number, refTs: number): number {
  const refDate = new Date(refTs * 1000);
  const currentDay = refDate.getUTCDay();
  const diff = (currentDay - dayOfWeek + 7) % 7;
  const targetDate = new Date(refDate);
  targetDate.setUTCDate(targetDate.getUTCDate() - diff);
  targetDate.setUTCHours(12, 0, 0, 0);
  return Math.floor(targetDate.getTime() / 1000);
}

// ─── WP option helpers ──────────────────────────────────────────────────────

export function setStartOfWeek(value: number): void {
  execSync(
    `wp option update start_of_week ${value} --path="${WP_ROOT}" 2>/dev/null`,
    { encoding: 'utf8', timeout: 10_000 }
  );
}

export function getStartOfWeek(): number {
  const raw = execSync(
    `wp option get start_of_week --path="${WP_ROOT}" 2>/dev/null`,
    { encoding: 'utf8', timeout: 10_000 }
  );
  return parseInt(raw.trim(), 10);
}
