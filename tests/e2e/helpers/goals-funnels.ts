/**
 * Goals & Funnels E2E helpers.
 *
 * The feature uses dedicated wp_options rows (slimstat_goals, slimstat_funnels)
 * rather than the main slimstat_options blob, so these live alongside
 * setup.ts's setSlimstatOption but operate on different keys.
 */
import { serialize as phpSerialize } from 'php-serialize';
import { getPool } from './setup';
import { assertSafeTestDatabase } from './env';

export interface Goal {
    id?: number;
    name: string;
    dimension: string;
    operator: string;
    value: string;
    active?: boolean;
}

export interface FunnelStep {
    name: string;
    dimension: string;
    operator: string;
    value: string;
    active?: boolean;
}

export interface Funnel {
    id?: number;
    name: string;
    steps: FunnelStep[];
}

async function upsertOption(name: string, value: any): Promise<void> {
    const serialized = phpSerialize(value);
    const pool = getPool();
    await pool.execute(
        "INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, 'no') " +
        "ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
        [name, serialized]
    );
}

async function deleteOption(name: string): Promise<void> {
    const pool = getPool();
    await pool.execute('DELETE FROM wp_options WHERE option_name = ?', [name]);
}

export async function seedGoals(goals: Goal[]): Promise<void> {
    const normalized = goals.map((g, i) => ({
        id:        g.id ?? (Date.now() * 10 + i),
        name:      g.name,
        dimension: g.dimension,
        operator:  g.operator,
        value:     g.value,
        active:    g.active ?? true,
    }));
    await upsertOption('slimstat_goals', normalized);
    await upsertOption('slimstat_goals_cache_ver', String(Date.now()));
}

export async function seedFunnels(funnels: Funnel[]): Promise<void> {
    const normalized = funnels.map((f, i) => ({
        id:    f.id ?? (Date.now() * 10 + i),
        name:  f.name,
        steps: f.steps.map(s => ({
            name:      s.name,
            dimension: s.dimension,
            operator:  s.operator,
            value:     s.value,
            active:    s.active ?? true,
            id:        Math.floor(Math.random() * 1_000_000),
        })),
    }));
    await upsertOption('slimstat_funnels', normalized);
    await upsertOption('slimstat_goals_cache_ver', String(Date.now()));
}

export interface StatRow {
    resource: string;
    /** Distinct value → distinct unique visitor (COALESCE(fingerprint, v_visit_id, ip_ip)). */
    fingerprint?: string;
    ip?: string;
    country?: string;
    /** Unix seconds; defaults to now. Earlier steps need an earlier/equal dt. */
    dt?: number;
}

/**
 * Insert raw pageview rows into wp_slim_stats so funnel/goal counts are non-zero
 * (otherwise an "identical funnels match" assertion trivially passes on 0 == 0).
 * Guarded by assertSafeTestDatabase() — this writes to the stats table and must
 * never run against a real site DB.
 */
export async function seedStats(rows: StatRow[]): Promise<void> {
    assertSafeTestDatabase();
    if (rows.length === 0) {
        return;
    }
    const pool = getPool();
    const now = Math.floor(Date.now() / 1000);
    for (const r of rows) {
        await pool.execute(
            'INSERT INTO wp_slim_stats (resource, fingerprint, ip, country, dt, visit_id) VALUES (?, ?, ?, ?, ?, 0)',
            [r.resource, r.fingerprint ?? null, r.ip ?? '127.0.0.1', r.country ?? null, r.dt ?? now],
        );
    }
}

/**
 * Pin a SlimStat report box (e.g. 'slim_p9_01' goals, 'slim_p9_02' funnels) into
 * the admin user's WP-dashboard layout, so its widget renders on wp-admin/index.php.
 * Builds the PHP-serialized meta value from the id length to avoid hand-counted
 * `s:N:` mismatches.
 */
export async function pinReportToDashboard(
    boxId: string,
    login: string = process.env.WP_ADMIN_USER ?? 'parhumm',
): Promise<void> {
    const value = `a:1:{s:9:"dashboard";s:${boxId.length}:"${boxId}";}`;
    await getPool().execute(
        'INSERT INTO wp_usermeta (user_id, meta_key, meta_value) ' +
        "SELECT ID, 'meta-box-order_admin_page_slimlayout', ? FROM wp_users WHERE user_login = ? LIMIT 1 " +
        'ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)',
        [value, login],
    );
}

export async function clearGoals(): Promise<void> {
    await deleteOption('slimstat_goals');
    await deleteOption('slimstat_goals_cache_ver');
}

export async function clearFunnels(): Promise<void> {
    await deleteOption('slimstat_funnels');
}

export async function clearAll(): Promise<void> {
    const pool = getPool();
    await pool.execute(
        'DELETE FROM wp_options WHERE option_name IN (?, ?, ?)',
        ['slimstat_goals', 'slimstat_funnels', 'slimstat_goals_cache_ver']
    );
}

/**
 * Toggle Pro via a forced filter mu-plugin. Pass maxGoals=0/maxFunnels=0 to
 * simulate Free tier; maxGoals=5/maxFunnels=3 to simulate Pro.
 */
export async function forceLimits(maxGoals: number, maxFunnels: number, wpContentDir: string): Promise<void> {
    const fs = await import('fs');
    const path = await import('path');
    const muPlugin = path.join(wpContentDir, 'mu-plugins', 'slimstat-goals-funnels-e2e-limits.php');
    const contents = `<?php
/*
 * Plugin Name: SlimStat Goals & Funnels — E2E Limit Forcer (test harness)
 * Description: Forces slimstat_max_goals / slimstat_max_funnels for E2E tests.
 */
add_filter('slimstat_max_goals',   static fn() => ${maxGoals});
add_filter('slimstat_max_funnels', static fn() => ${maxFunnels});
`;
    fs.mkdirSync(path.dirname(muPlugin), { recursive: true });
    fs.writeFileSync(muPlugin, contents, 'utf8');
}

export async function restoreDefaultLimits(wpContentDir: string): Promise<void> {
    const fs = await import('fs');
    const path = await import('path');
    const muPlugin = path.join(wpContentDir, 'mu-plugins', 'slimstat-goals-funnels-e2e-limits.php');
    if (fs.existsSync(muPlugin)) {
        fs.unlinkSync(muPlugin);
    }
}
