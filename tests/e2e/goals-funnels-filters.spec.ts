/**
 * Goals & Funnels — funnels must respect the active global report filters (#22).
 *
 * The bug: a funnel kept showing data even when a global filter (e.g.
 * "browser equals X") matched nothing, because (A) the funnel cache key omitted
 * the column-filter signature — so toggling a filter served the stale unfiltered
 * transient — and (B) the lazily-loaded funnel tab never POSTed the active
 * fs[...] filters. Goals already respected filters; funnels now do too.
 *
 * Strategy: seed Firefox + Chrome visitors through a 2-step funnel, then drive
 * slimview6 with fs[browser]=... in the URL ($_REQUEST['fs'], honored by init()
 * on both the SSR render and the AJAX tab load).
 *
 *   1. Keystone three-phase: unfiltered → filtered (counts CHANGE, not stale) →
 *      unfiltered (full counts restored). This is the exact stale-cache failure.
 *   2. No-match filter: the first/SSR funnel AND a 2nd/AJAX-loaded funnel both
 *      show the "no visitors matched" state.
 *   3. Positive control: a real filter narrows the funnel to exactly the matching
 *      segment (Firefox), strictly fewer than the unfiltered total.
 */
import { test, expect, Page, Locator } from '@playwright/test';
import * as path from 'path';
import { fileURLToPath } from 'url';
import { BASE_URL, WP_ROOT } from './helpers/env';
import { closeDb, clearStatsTable, getPool } from './helpers/setup';
import {
    seedFunnels,
    seedStats,
    clearAll,
    forceLimits,
    restoreDefaultLimits,
} from './helpers/goals-funnels';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const WP_CONTENT = path.join(WP_ROOT, 'wp-content');

const SLIMVIEW6 = `${BASE_URL}/wp-admin/admin.php?page=slimview6`;
const ago = (s: number) => Math.floor(Date.now() / 1000) - s;

const STEPS = [
    { name: 'Home',    dimension: 'resource', operator: 'contains', value: '/' },
    { name: 'Pricing', dimension: 'resource', operator: 'contains', value: '/pricing' },
];

/**
 * Firefox: ff1 + ff2 complete (/ → /pricing), ff3 stops at '/'.  → FF step1=3, step2=2
 * Chrome:  ch1 completes.                                        → Chrome step1=1, step2=1
 * Unfiltered totals: step1 = 4, step2 = 3.
 */
async function seedBrowserFunnelData(): Promise<void> {
    await clearStatsTable();
    await seedStats([
        { resource: '/',        fingerprint: 'ff-1', browser: 'Firefox', dt: ago(300) },
        { resource: '/pricing', fingerprint: 'ff-1', browser: 'Firefox', dt: ago(240) },
        { resource: '/',        fingerprint: 'ff-2', browser: 'Firefox', dt: ago(220) },
        { resource: '/pricing', fingerprint: 'ff-2', browser: 'Firefox', dt: ago(180) },
        { resource: '/',        fingerprint: 'ff-3', browser: 'Firefox', dt: ago(160) },
        { resource: '/',        fingerprint: 'ch-1', browser: 'Chrome',  dt: ago(140) },
        { resource: '/pricing', fingerprint: 'ch-1', browser: 'Chrome',  dt: ago(100) },
    ]);
}

async function gotoSlimview6(page: Page, query = ''): Promise<void> {
    await page.goto(SLIMVIEW6 + query, { waitUntil: 'domcontentloaded' });
}

/** First step's unique-visitor count from a funnel panel (text is "N (P%)"). */
async function firstStepCount(panel: Locator): Promise<number> {
    const txt = await panel.locator('.slimstat-gf-step__count').first().innerText();
    const m = txt.replace(/\s+/g, ' ').match(/\d[\d,]*/);
    expect(m, `could not parse a step count from "${txt}"`).not.toBeNull();
    return parseInt((m as RegExpMatchArray)[0].replace(/,/g, ''), 10);
}

test.describe('Funnels respect global report filters (#22)', () => {
    test.setTimeout(60_000);

    test.beforeEach(async () => {
        await clearAll();
        await forceLimits(5, 3, WP_CONTENT);
    });

    test.afterAll(async () => {
        await restoreDefaultLimits(WP_CONTENT);
        await clearStatsTable();
        await clearAll();
        await closeDb();
    });

    test('keystone: apply filter changes counts (not stale), removing it restores them', async ({ page }) => {
        await seedBrowserFunnelData();
        await seedFunnels([{ name: 'Home → Pricing', steps: STEPS }]);

        const panel = () => page.locator('.slimstat-gf-funnel-panel[data-funnel-index="0"]');

        // Phase 1 — unfiltered: all 4 visitors reach step 1.
        await gotoSlimview6(page);
        await expect(panel().locator('.slimstat-gf-step__count').first()).toBeVisible();
        const unfiltered = await firstStepCount(panel());
        expect(unfiltered).toBe(4);

        // Phase 2 — browser=Firefox: only the 3 Firefox visitors remain. Same window,
        // same steps — before the fix this returned the stale unfiltered 4.
        await gotoSlimview6(page, '&fs%5Bbrowser%5D=equals+Firefox');
        await expect(panel().locator('.slimstat-gf-step__count').first()).toBeVisible();
        const filtered = await firstStepCount(panel());
        expect(filtered).toBe(3);
        expect(filtered).toBeLessThan(unfiltered);

        // Phase 3 — filter removed: full counts restored (not stuck on the filtered value).
        await gotoSlimview6(page);
        await expect(panel().locator('.slimstat-gf-step__count').first()).toBeVisible();
        expect(await firstStepCount(panel())).toBe(unfiltered);
    });

    test('no-match filter empties both the SSR funnel and an AJAX-loaded funnel', async ({ page }) => {
        await seedBrowserFunnelData();
        // Two funnels → a 2nd tab that lazy-loads via AJAX.
        await seedFunnels([
            { name: 'Funnel A', steps: STEPS },
            { name: 'Funnel B', steps: STEPS.map(s => ({ ...s, name: `${s.name} (B)` })) },
        ]);

        await gotoSlimview6(page, '&fs%5Bbrowser%5D=equals+ZzNoSuchBrowser');

        // Funnel A (server-rendered) honors the filter via the filter-aware cache key.
        const panelA = page.locator('.slimstat-gf-funnel-panel[data-funnel-index="0"]');
        await expect(panelA.locator('.slimstat-gf-summary--empty')).toBeVisible();

        // Funnel B loads via AJAX on tab switch and must honor the same filter.
        await page.locator('.slimstat-gf-tab[data-funnel-index="1"]').click();
        const panelB = page.locator('.slimstat-gf-funnel-panel[data-funnel-index="1"]');
        await expect(panelB).toBeVisible();
        await expect(panelB.locator('.slimstat-gf-summary--empty')).toBeVisible({ timeout: 10_000 });
    });

    test('positive control: a matching filter narrows the funnel to that segment', async ({ page }) => {
        await seedBrowserFunnelData();
        await seedFunnels([{ name: 'Home → Pricing', steps: STEPS }]);

        await gotoSlimview6(page, '&fs%5Bbrowser%5D=equals+Firefox');
        const panel = page.locator('.slimstat-gf-funnel-panel[data-funnel-index="0"]');
        await expect(panel.locator('.slimstat-gf-step__count').first()).toBeVisible();

        // Exactly the 3 Firefox visitors at step 1 (Chrome's visitor is filtered out).
        const counts = (await panel.locator('.slimstat-gf-step__count').allInnerTexts())
            .map(t => parseInt(t.replace(/\s+/g, ' ').replace(/[, ].*$/, ''), 10));
        expect(counts[0]).toBe(3);
        expect(counts[1]).toBe(2);
    });
});
