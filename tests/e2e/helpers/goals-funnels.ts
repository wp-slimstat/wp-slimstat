/**
 * Goals & Funnels E2E helpers.
 *
 * The feature uses dedicated wp_options rows (slimstat_goals, slimstat_funnels)
 * rather than the main slimstat_options blob, so these live alongside
 * setup.ts's setSlimstatOption but operate on different keys.
 */
import { serialize as phpSerialize } from 'php-serialize';
import { getPool } from './setup';

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
