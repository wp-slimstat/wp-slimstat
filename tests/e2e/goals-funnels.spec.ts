/**
 * Goals & Funnels (slimview6) — redesign E2E coverage.
 *
 * Covers the four marquee states (Free × empty/has-data, Pro × empty/has-data)
 * plus the critical flows the redesign introduces:
 *   - goal drawer create + delete via confirm sheet (not window.confirm)
 *   - funnel builder create with 2 steps
 *   - pill-segmented funnel tab present when >1 funnel
 *   - locked preview + single "Upgrade to Pro" label for Free users
 *   - legacy alias CSS vars preserved (visual regression guard)
 *   - dashboard widget renders without drawer/builder/confirm-sheet DOM
 *
 */
import { test, expect, Page } from '@playwright/test';
import * as path from 'path';
import { fileURLToPath } from 'url';
import { BASE_URL, WP_ROOT } from './helpers/env';
import { closeDb } from './helpers/setup';
import {
    seedGoals,
    seedFunnels,
    clearAll,
    forceLimits,
    restoreDefaultLimits,
} from './helpers/goals-funnels';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const WP_CONTENT = path.join(WP_ROOT, 'wp-content');

const SLIMVIEW6 = `${BASE_URL}/wp-admin/admin.php?page=slimview6`;

async function gotoSlimview6(page: Page): Promise<void> {
    await page.goto(SLIMVIEW6, { waitUntil: 'domcontentloaded' });
}

test.describe('Goals & Funnels redesign (slimview6)', () => {
    test.beforeEach(async () => {
        await clearAll();
    });

    test.afterAll(async () => {
        await restoreDefaultLimits(WP_CONTENT);
        await clearAll();
        await closeDb();
    });

    // ─── State: Free × empty ─────────────────────────────────────

    test('free-empty: hero + 0 of 1 pill + locked funnel with single "Upgrade to Pro" CTA', async ({ page }) => {
        await forceLimits(1, 0, WP_CONTENT);
        await gotoSlimview6(page);

        // Goals hero + empty teach card. Title is the postbox <h3>; usage pill
        // and CTA were moved into the postbox header (.slimstat-header-buttons).
        await expect(page.locator('#slim_p9_01 > h3')).toContainText('Goals');
        await expect(page.locator('#slim_p9_01 [data-role="usage"]')).toContainText('0 of 1');
        await expect(page.locator('[data-role="goals-empty"]')).toBeVisible();

        // Funnels: locked preview, brand CTA, no Add button.
        await expect(page.locator('.slimstat-gf-funnels--locked')).toBeVisible();
        const funnelCtas = page.locator('.slimstat-gf-funnels .slimstat-gf-cta');
        await expect(funnelCtas).toHaveCount(1);
        await expect(funnelCtas.first()).toHaveText(/Upgrade to Pro/);

        // No deprecated Pro labels on this view.
        await expect(page.locator('body')).not.toContainText(/Unlock SlimStat Pro/);
    });

    // ─── State: Free × has-data ─────────────────────────────────

    test('free-has-data: goal card + yellow upsell + paused pill + locked funnel preview', async ({ page }) => {
        await forceLimits(1, 0, WP_CONTENT);
        await seedGoals([{ name: 'Pricing View', dimension: 'resource', operator: 'contains', value: '/pricing', active: true }]);
        await gotoSlimview6(page);

        // Goal row rendered.
        const goalCard = page.locator('.slimstat-gf-goal').first();
        await expect(goalCard).toBeVisible();
        await expect(goalCard.locator('.slimstat-gf-goal__name')).toContainText('Pricing View');
        await expect(goalCard.locator('.slimstat-gf-rule-chip code')).toContainText('/pricing');

        // Usage pill at cap.
        await expect(page.locator('.slimstat-gf-goals [data-role="usage"]')).toContainText('1 of 1');

        // Yellow upsell strip visible.
        await expect(page.locator('.slimstat-gf-upsell')).toBeVisible();

        // Locked funnel preview still present.
        await expect(page.locator('.slimstat-gf-funnels--locked')).toBeVisible();
    });

    // ─── State: Pro × empty ─────────────────────────────────────

    test('pro-empty: goals teach card + funnels template picker with 4 choices', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await expect(page.locator('[data-role="goals-empty"]')).toBeVisible();

        // Funnels empty → template picker visible.
        await expect(page.locator('[data-role="funnels-empty"]')).toBeVisible();
        const templates = page.locator('.slimstat-gf-template-card');
        await expect(templates).toHaveCount(4);
    });

    // ─── State: Pro × has-data ─────────────────────────────────

    test('pro-has-data: 2 goals + 2 funnels render with pill tabs and usage counts', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await seedGoals([
            { name: 'Signup',  dimension: 'resource', operator: 'contains', value: '/signup',  active: true },
            { name: 'Trial',   dimension: 'resource', operator: 'contains', value: '/trial',   active: true },
        ]);
        await seedFunnels([
            { name: 'Home to pricing', steps: [
                { name: 'Home',    dimension: 'resource', operator: 'contains', value: '/' },
                { name: 'Pricing', dimension: 'resource', operator: 'contains', value: '/pricing' },
            ]},
            { name: 'Checkout', steps: [
                { name: 'Cart',    dimension: 'resource', operator: 'contains', value: '/cart' },
                { name: 'Thanks',  dimension: 'resource', operator: 'contains', value: '/thank-you' },
            ]},
        ]);
        await gotoSlimview6(page);

        // Goal usage pill shows 2 of 5.
        await expect(page.locator('.slimstat-gf-goals [data-role="usage"]')).toContainText('2 of 5');

        // Funnel usage pill shows 2 of 3.
        await expect(page.locator('.slimstat-gf-funnels [data-role="usage"]')).toContainText('2 of 3');

        // Pill-segmented tab bar appears with 2 tabs.
        await expect(page.locator('.slimstat-gf-tabs')).toBeVisible();
        await expect(page.locator('.slimstat-gf-tab')).toHaveCount(2);
        await expect(page.locator('.slimstat-gf-tab.is-active')).toHaveCount(1);
    });

    // ─── Goal create via drawer ─────────────────────────────────

    test('goal-create: drawer opens, form submits, new goal renders', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-role="goals-empty"] [data-action="open-goal-drawer"]');
        await expect(page.locator('#slimstat-gf-goal-drawer.is-open')).toBeVisible();

        await page.fill('[data-role="goal-name"]', 'E2E Test Goal');
        await page.fill('[data-role="goal-value"]', '/e2e');
        await Promise.all([
            page.waitForURL(SLIMVIEW6, { timeout: 15_000 }),
            page.click('[data-action="save-goal"]'),
        ]);

        await expect(page.locator('.slimstat-gf-goal__name')).toContainText('E2E Test Goal');
    });

    // ─── Goal delete via confirm sheet (NOT window.confirm) ─────

    test('goal-delete: destructive action uses the confirm sheet, not window.confirm', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await seedGoals([{ name: 'To Delete', dimension: 'resource', operator: 'equals', value: '/x', active: true }]);
        await gotoSlimview6(page);

        let nativeConfirmInvoked = false;
        page.on('dialog', async (dialog) => {
            nativeConfirmInvoked = true;
            await dialog.dismiss();
        });

        await page.click('[data-action="delete-goal"]');

        // Confirm sheet — not window.confirm — must be visible.
        await expect(page.locator('#slimstat-gf-confirm-sheet.is-open')).toBeVisible();
        expect(nativeConfirmInvoked).toBe(false);

        await Promise.all([
            page.waitForURL(SLIMVIEW6, { timeout: 15_000 }),
            page.click('[data-action="confirm-destructive"]'),
        ]);
        await expect(page.locator('.slimstat-gf-goal')).toHaveCount(0);
    });

    // ─── Funnel create: 2-step, via "Start from scratch" template ──

    test('funnel-create: builder opens, saves 2 steps, renders funnel card', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-template="blank"]');
        await expect(page.locator('#slimstat-gf-funnel-builder.is-open')).toBeVisible();

        await page.fill('[data-role="funnel-name"]', 'E2E Funnel');
        const rows = page.locator('.slimstat-gf-step-row');
        await expect(rows).toHaveCount(2);
        await rows.nth(0).locator('[data-role="step-name"]').fill('Landing');
        await rows.nth(0).locator('[data-role="step-value"]').fill('/');
        await rows.nth(1).locator('[data-role="step-name"]').fill('Pricing');
        await rows.nth(1).locator('[data-role="step-value"]').fill('/pricing');

        await Promise.all([
            page.waitForURL(SLIMVIEW6, { timeout: 15_000 }),
            page.click('[data-action="save-funnel"]'),
        ]);

        await expect(page.locator('.slimstat-gf-funnel-panel__name')).toContainText('E2E Funnel');
        await expect(page.locator('.slimstat-gf-funnels [data-role="usage"]')).toContainText('1 of 3');
    });

    // ─── Downstream: dashboard widget renders no drawer/builder/confirm-sheet ──

    test('downstream-widget: WP dashboard does not mount the drawer/builder/confirm-sheet DOM', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await seedGoals([{ name: 'Pinned', dimension: 'resource', operator: 'contains', value: '/x', active: true }]);

        // Force the goals widget into the dashboard layout via user meta.
        const { getPool } = await import('./helpers/setup');
        await getPool().execute(
            "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) " +
            "SELECT ID, 'meta-box-order_admin_page_slimlayout', ? FROM wp_users WHERE user_login = ? LIMIT 1 " +
            "ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            [
                'a:1:{s:9:"dashboard";s:10:"slim_p9_01";}',
                process.env.WP_ADMIN_USER ?? 'parhumm',
            ],
        );

        await page.goto(`${BASE_URL}/wp-admin/index.php`, { waitUntil: 'domcontentloaded' });

        // Legacy compact markup is expected for the widget branch.
        await expect(page.locator('body')).not.toContainText(/Add goal drawer|Funnel builder|Delete goal\?/);
        await expect(page.locator('#slimstat-gf-goal-drawer')).toHaveCount(0);
        await expect(page.locator('#slimstat-gf-funnel-builder')).toHaveCount(0);
        await expect(page.locator('#slimstat-gf-confirm-sheet')).toHaveCount(0);
    });

    // ─── Round 2: auto-suggest on value field ────────────────────

    test('auto-suggest-goal-drawer: changing dimension fires slimstat_get_filter_options', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-role="goals-empty"] [data-action="open-goal-drawer"]');
        await expect(page.locator('#slimstat-gf-goal-drawer.is-open')).toBeVisible();

        const req = page.waitForRequest(r =>
            r.url().includes('admin-ajax.php') &&
            r.method() === 'POST' &&
            (r.postData() || '').includes('action=slimstat_get_filter_options')
        );
        await page.selectOption('[data-role="goal-dimension"]', 'country');
        const request = await req;
        expect(request.postData()).toContain('dimension=country');
    });

    test('auto-suggest-event-notes-maps-to-notes: dimension=event_notes posts dimension=notes', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-role="goals-empty"] [data-action="open-goal-drawer"]');
        await expect(page.locator('#slimstat-gf-goal-drawer.is-open')).toBeVisible();

        const req = page.waitForRequest(r =>
            r.url().includes('admin-ajax.php') &&
            r.method() === 'POST' &&
            (r.postData() || '').includes('action=slimstat_get_filter_options')
        );
        await page.selectOption('[data-role="goal-dimension"]', 'event_notes');
        const request = await req;
        expect(request.postData()).toContain('dimension=notes');
    });

    test('auto-suggest-is-empty-disables-value: operator is_empty disables the value input', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-role="goals-empty"] [data-action="open-goal-drawer"]');
        await expect(page.locator('#slimstat-gf-goal-drawer.is-open')).toBeVisible();

        await page.selectOption('[data-role="goal-operator"]', 'is_empty');
        await expect(page.locator('[data-role="goal-value"]')).toBeDisabled();

        await page.selectOption('[data-role="goal-operator"]', 'contains');
        await expect(page.locator('[data-role="goal-value"]')).toBeEnabled();
    });

    // ─── Round 2: funnel builder affordances ─────────────────────

    test('funnel-test-step-populates-count: Test button fires slimstat_test_funnel_step', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-template="blank"]');
        await expect(page.locator('#slimstat-gf-funnel-builder.is-open')).toBeVisible();

        const firstRow = page.locator('.slimstat-gf-step-row').nth(0);
        await firstRow.locator('[data-role="step-name"]').fill('Home');
        await firstRow.locator('[data-role="step-value"]').fill('/');

        const req = page.waitForRequest(r =>
            r.url().includes('admin-ajax.php') &&
            r.method() === 'POST' &&
            (r.postData() || '').includes('action=slimstat_test_funnel_step')
        );
        await firstRow.locator('[data-action="test-step"]').click();
        await req;
        // Result span should render something (either a count or "—").
        await expect(firstRow.locator('[data-role="test-result"]')).not.toHaveText('');
    });

    // ─── Round 2: prototype copy regression ──────────────────────

    test('copy-prototype-strings: marquee empty-state copy matches the prototype', async ({ page }) => {
        await forceLimits(1, 0, WP_CONTENT);
        await gotoSlimview6(page);

        // Goals hero subtitle (now under postbox <h3>, not inside the card).
        await expect(page.locator('#slim_p9_01 .slimstat-gf-postbox-subtitle'))
            .toContainText('A Goal is one question you ask of your traffic');

        // Goals empty state.
        await expect(page.locator('[data-role="goals-empty"] .slimstat-gf-empty__title'))
            .toHaveText('Measure what matters');
        await expect(page.locator('[data-role="goals-empty"] .slimstat-gf-cta'))
            .toContainText('Add your first goal');

        // Funnels locked preview headline.
        await expect(page.locator('.slimstat-gf-funnel-lock__overlay h3'))
            .toContainText('See where visitors drop off, step by step');

        // Funnels subtitle (now under postbox <h3>, not inside the card).
        await expect(page.locator('#slim_p9_02 .slimstat-gf-postbox-subtitle'))
            .toContainText('String 2–5 goals into a journey');
    });

    test('copy-prototype-templates: template cards use prototype labels', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        const templates = page.locator('.slimstat-gf-template-card__title');
        await expect(templates.nth(0)).toContainText('E-commerce checkout');
        await expect(templates.nth(1)).toContainText('SaaS signup');
        await expect(templates.nth(2)).toContainText('Blog engagement');
        await expect(templates.nth(3)).toContainText('Blank funnel');
    });

    test('copy-confirm-sheet-keep-labels: confirm sheet uses Keep + Delete wording', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await seedGoals([{ name: 'To Delete', dimension: 'resource', operator: 'equals', value: '/x', active: true }]);
        await gotoSlimview6(page);

        await page.click('[data-action="delete-goal"]');
        await expect(page.locator('#slimstat-gf-confirm-sheet.is-open')).toBeVisible();
        await expect(page.locator('[data-role="confirm-title"]')).toContainText('Delete goal?');
        await expect(page.locator('[data-role="confirm-cancel"]')).toHaveText('Keep goal');
        await expect(page.locator('[data-role="confirm-destructive"]')).toHaveText('Delete goal');
    });

    // ─── Legacy CSS var preservation (visual regression guard) ───

    test('legacy-css-vars: datepicker --slimstat-* tokens keep their original values', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        // tokens.css is enqueued on slimview6; assert the six legacy aliases resolve.
        const values = await page.evaluate(() => {
            const cs = getComputedStyle(document.documentElement);
            return {
                primary:      cs.getPropertyValue('--slimstat-primary').trim(),
                primaryHover: cs.getPropertyValue('--slimstat-primary-hover').trim(),
                border:       cs.getPropertyValue('--slimstat-border').trim(),
                background:   cs.getPropertyValue('--slimstat-background').trim(),
                text:         cs.getPropertyValue('--slimstat-text').trim(),
                lightBg:      cs.getPropertyValue('--slimstat-light-bg').trim(),
            };
        });
        expect(values.primary).toBe('#dc3232');
        expect(values.primaryHover).toBe('#b32d2e');
        expect(values.border).toBe('#ddd');
        expect(values.background).toBe('#fff');
        expect(values.text).toBe('#333');
        expect(values.lightBg).toBe('#f8f8f8');
    });
});
