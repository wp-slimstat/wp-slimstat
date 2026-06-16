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

        // FN-3: the empty state owns the single primary CTA — the header
        // "+ Add Goal" is suppressed until a goal exists, so there is exactly
        // one primary CTA in the goals card (the centered "+ Add your first goal").
        await expect(page.locator('#slim_p9_01 .slimstat-gf-cta')).toHaveCount(1);
        await expect(page.locator('#slim_p9_01 .slimstat-gf-empty .slimstat-gf-cta')).toHaveCount(1);

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

    // ─── Paused goals: tier behavior (#11) ───────────────────────

    test('paused-free-visible: Free shows paused goals too, with their numbers hidden', async ({ page }) => {
        await forceLimits(1, 0, WP_CONTENT);
        await seedGoals([
            { id: 1, name: 'Active One', dimension: 'resource', operator: 'contains', value: '/a', active: true },
            { id: 2, name: 'Paused One', dimension: 'resource', operator: 'contains', value: '/p', active: false },
        ]);
        await gotoSlimview6(page);

        // Both goals are visible now — paused goals are no longer hidden on Free.
        await expect(page.locator('.slimstat-gf-goal')).toHaveCount(2);
        await expect(page.locator('body')).toContainText('Paused One');

        // The paused goal carries its badge + a "Paused" placeholder instead of numbers.
        const pausedGoal = page.locator('.slimstat-gf-goal[data-active="false"]');
        await expect(pausedGoal).toHaveCount(1);
        await expect(pausedGoal.locator('.slimstat-gf-pill--paused')).toBeVisible();
        await expect(pausedGoal.locator('.slimstat-gf-goal__nomatch')).toContainText('Paused');
        await expect(pausedGoal.locator('.slimstat-gf-goal__metrics')).toHaveCount(0);

        // Usage pill counts active goals only.
        await expect(page.locator('.slimstat-gf-goals [data-role="usage"]')).toContainText('1 of 1');
    });

    test('free-autopause-excess: Free auto-pauses all but the newest active goal', async ({ page }) => {
        await forceLimits(1, 0, WP_CONTENT);
        // Two active goals on Free (e.g. left over from a Pro→Free downgrade).
        await seedGoals([
            { id: 1, name: 'Older Active', dimension: 'resource', operator: 'contains', value: '/old', active: true },
            { id: 2, name: 'Newer Active', dimension: 'resource', operator: 'contains', value: '/new', active: true },
        ]);
        await gotoSlimview6(page);

        // Both stay listed, but exactly one is active — the newest (highest id).
        await expect(page.locator('.slimstat-gf-goal')).toHaveCount(2);
        await expect(page.locator('.slimstat-gf-goal[data-active="true"]')).toHaveCount(1);
        await expect(page.locator('.slimstat-gf-goal[data-active="false"]')).toHaveCount(1);
        await expect(page.locator('.slimstat-gf-goal[data-active="true"] .slimstat-gf-goal__name'))
            .toContainText('Newer Active');
        // Pill reflects the enforced single active goal.
        await expect(page.locator('.slimstat-gf-goals [data-role="usage"]')).toContainText('1 of 1');
    });

    test('paused-pro-placeholder: Pro keeps paused goals but shows a placeholder, not metrics', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await seedGoals([
            { name: 'Active Goal', dimension: 'resource', operator: 'contains', value: '/a', active: true },
            { name: 'Paused Goal', dimension: 'resource', operator: 'contains', value: '/p', active: false },
        ]);
        await gotoSlimview6(page);

        // Both goals visible on Pro.
        await expect(page.locator('.slimstat-gf-goal')).toHaveCount(2);
        const pausedGoal = page.locator('.slimstat-gf-goal[data-active="false"]');
        await expect(pausedGoal).toHaveCount(1);
        await expect(pausedGoal.locator('.slimstat-gf-pill--paused')).toBeVisible();
        // Paused goal shows the placeholder, not a live metrics block.
        await expect(pausedGoal.locator('.slimstat-gf-goal__nomatch')).toContainText('Paused');
        await expect(pausedGoal.locator('.slimstat-gf-goal__metrics')).toHaveCount(0);
    });

    // ─── State: Pro × empty ─────────────────────────────────────

    test('pro-empty: goals teach card + funnels template picker with 6 choices', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await expect(page.locator('[data-role="goals-empty"]')).toBeVisible();

        // Funnels empty → template picker visible. The partial renders 6 template
        // cards (5 prefab funnels + Blank); see the copy-prototype-templates test.
        await expect(page.locator('[data-role="funnels-empty"]')).toBeVisible();
        const templates = page.locator('.slimstat-gf-template-card');
        await expect(templates).toHaveCount(6);

        // FN-3: the template picker (incl. "Blank funnel") owns the create action,
        // so the header "+ Add Funnel" CTA is suppressed in the empty state.
        await expect(page.locator('#slim_p9_02 .slimstat-gf-cta')).toHaveCount(0);
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

    test('funnel-identical-configs-match: two funnels with the same steps show identical numbers (#19)', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        const steps = [
            { name: 'Home',    dimension: 'resource', operator: 'contains', value: '/' },
            { name: 'Pricing', dimension: 'resource', operator: 'contains', value: '/pricing' },
        ];
        // Identical step rules, different funnel + step names — they MUST agree.
        await seedFunnels([
            { name: 'Funnel A', steps },
            { name: 'Funnel B', steps: steps.map(s => ({ ...s, name: `${s.name} (B)` })) },
        ]);
        await gotoSlimview6(page);

        // Funnel A is the active, server-rendered panel.
        const panelA = page.locator('.slimstat-gf-funnel-panel[data-funnel-index="0"]');
        await expect(panelA).toBeVisible();
        await expect(panelA.locator('.slimstat-gf-step__count').first()).toBeVisible();
        const countsA = (await panelA.locator('.slimstat-gf-step__count').allInnerTexts())
            .map(s => s.replace(/\s+/g, ' ').trim());

        // Funnel B loads via AJAX on tab switch; wait for its bars to render.
        await page.locator('.slimstat-gf-tab[data-funnel-index="1"]').click();
        const panelB = page.locator('.slimstat-gf-funnel-panel[data-funnel-index="1"]');
        await expect(panelB).toBeVisible();
        await expect(panelB.locator('.slimstat-gf-step__count')).toHaveCount(countsA.length);
        const countsB = (await panelB.locator('.slimstat-gf-step__count').allInnerTexts())
            .map(s => s.replace(/\s+/g, ' ').trim());

        // The #19 contract: identical configs → identical per-step counts/percentages.
        expect(countsB).toEqual(countsA);
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

    // ─── Value-field display + custom values (#1, #4) ────────────

    test('value-display-template: template values render in the combobox, not the placeholder', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-template="woocommerce_purchase"]');
        await expect(page.locator('#slimstat-gf-funnel-builder.is-open')).toBeVisible();

        const rows = page.locator('.slimstat-gf-step-row');
        await expect(rows).toHaveCount(4);

        // The combobox display must show the prefilled value (it used to revert to
        // the placeholder once the suggestion AJAX resolved — the #4 regression).
        await expect(rows.nth(0).locator('.slimstat-select-text')).toHaveText('/product/');
        await expect(rows.nth(0).locator('.slimstat-select-display'))
            .not.toHaveClass(/slimstat-placeholder/);
        // The hidden input still carries the value for save.
        await expect(rows.nth(0).locator('[data-role="step-value"]')).toHaveValue('/product/');
        // Last step targets the WooCommerce order-received page (path refined in #5).
        await expect(rows.nth(3).locator('[data-role="step-value"]')).toHaveValue(/order-received/);
    });

    test('funnel-test-button-persists: Test stays on every step after the value combobox mounts (#16)', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-template="blank"]');
        await expect(page.locator('#slimstat-gf-funnel-builder.is-open')).toBeVisible();

        const rows = page.locator('.slimstat-gf-step-row');
        await expect(rows).toHaveCount(2);
        await rows.nth(0).locator('[data-role="step-value"]').fill('/');
        await rows.nth(1).locator('[data-role="step-value"]').fill('/pricing');

        // Wait for the value comboboxes to mount (autosuggest replaces the plain
        // input with .slimstat-searchable-select once the options AJAX resolves).
        await expect(rows.nth(0).locator('.slimstat-searchable-select')).toBeVisible();
        await expect(rows.nth(1).locator('.slimstat-searchable-select')).toBeVisible();

        // The Test control must stay visible on EVERY step after the combobox mounts
        // — it used to get clipped off the row until a reload. (#16)
        await expect(rows.nth(0).locator('[data-action="test-step"]')).toBeVisible();
        await expect(rows.nth(1).locator('[data-action="test-step"]')).toBeVisible();

        // Testing one step keeps the result and leaves every Test control in place.
        await rows.nth(0).locator('[data-action="test-step"]').click();
        await expect(rows.nth(0).locator('[data-role="test-result"]')).not.toBeEmpty();
        await expect(rows.nth(0).locator('[data-action="test-step"]')).toBeVisible();
        await expect(rows.nth(1).locator('[data-action="test-step"]')).toBeVisible();
    });

    test('value-display-goal-edit: editing a goal shows the saved value (not the placeholder)', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await seedGoals([{ name: 'Pricing', dimension: 'resource', operator: 'contains', value: '/pricing', active: true }]);
        await gotoSlimview6(page);

        await page.click('.slimstat-gf-goal [data-action="open-goal-drawer"][data-mode="edit"]');
        await expect(page.locator('#slimstat-gf-goal-drawer.is-open')).toBeVisible();

        await expect(page.locator('[data-role="goal-value"]')).toHaveValue('/pricing');
        // Display survives the async suggestion load (#4).
        await expect(page.locator('#slimstat-gf-goal-drawer .slimstat-select-text')).toHaveText('/pricing');
        await expect(page.locator('#slimstat-gf-goal-drawer .slimstat-select-display'))
            .not.toHaveClass(/slimstat-placeholder/);
    });

    test('value-custom-typed-saves: a custom value not in the suggestions is saved (#1.2)', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-role="goals-empty"] [data-action="open-goal-drawer"]');
        await expect(page.locator('#slimstat-gf-goal-drawer.is-open')).toBeVisible();
        await page.fill('[data-role="goal-name"]', 'Custom Value Goal');

        // Type a value through the combobox search; syncTypedValue commits it to
        // the hidden input even though it is not in the suggestion list.
        const wrap = page.locator('#slimstat-gf-goal-drawer .slimstat-searchable-select');
        await wrap.locator('.slimstat-select-display').click();
        await wrap.locator('.slimstat-select-search input').fill('/totally-custom-xyz');
        await page.locator('[data-role="goal-name"]').click(); // blur to commit
        await expect(page.locator('[data-role="goal-value"]')).toHaveValue('/totally-custom-xyz');

        await Promise.all([
            page.waitForURL(SLIMVIEW6, { timeout: 15_000 }),
            page.click('[data-action="save-goal"]'),
        ]);

        // The saved custom value round-trips into the rendered rule chip.
        await expect(page.locator('.slimstat-gf-rule-chip code')).toContainText('/totally-custom-xyz');
    });

    test('value-custom-typed-enter-commits: pressing Enter commits a custom value without click-out (#14)', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-role="goals-empty"] [data-action="open-goal-drawer"]');
        await expect(page.locator('#slimstat-gf-goal-drawer.is-open')).toBeVisible();
        await page.fill('[data-role="goal-name"]', 'Enter Commit Goal');

        const wrap = page.locator('#slimstat-gf-goal-drawer .slimstat-searchable-select');
        await wrap.locator('.slimstat-select-display').click();
        const search = wrap.locator('.slimstat-select-search input');
        await search.fill('https://metricet.com');
        // Commit via Enter only — no click-outside. (#14)
        await search.press('Enter');

        // Dropdown closes and the display reflects the typed value immediately,
        // and the page must NOT have navigated (Enter must not submit the form).
        await expect(wrap.locator('.slimstat-select-dropdown')).toBeHidden();
        await expect(wrap.locator('.slimstat-select-text')).toHaveText('https://metricet.com');
        await expect(page.locator('[data-role="goal-value"]')).toHaveValue('https://metricet.com');
        await expect(page.locator('#slimstat-gf-goal-drawer.is-open')).toBeVisible();

        // The committed value still round-trips on save.
        await Promise.all([
            page.waitForURL(SLIMVIEW6, { timeout: 15_000 }),
            page.click('[data-action="save-goal"]'),
        ]);
        await expect(page.locator('.slimstat-gf-rule-chip code')).toContainText('https://metricet.com');
    });

    test('value-hint-copy: drawer + builder explain date-range suggestions and custom values', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-role="goals-empty"] [data-action="open-goal-drawer"]');
        await expect(page.locator('#slimstat-gf-goal-drawer #slimstat-gf-goal-value-hint'))
            .toContainText('Type any value and save');
    });

    // ─── Value-required validation (#2) ──────────────────────────

    test('validation-goal-value-required: value-bearing operator + empty value is blocked', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-role="goals-empty"] [data-action="open-goal-drawer"]');
        await expect(page.locator('#slimstat-gf-goal-drawer.is-open')).toBeVisible();
        await page.fill('[data-role="goal-name"]', 'No Value Goal');
        await page.selectOption('[data-role="goal-operator"]', 'contains');

        await page.click('[data-action="save-goal"]');

        // Inline error and the drawer stays open (no navigation, nothing saved).
        await expect(page.locator('[data-role="drawer-error"]')).toContainText('Value is required');
        await expect(page.locator('#slimstat-gf-goal-drawer.is-open')).toBeVisible();
    });

    test('validation-goal-valueless-ok: is_empty operator saves with no value', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-role="goals-empty"] [data-action="open-goal-drawer"]');
        await expect(page.locator('#slimstat-gf-goal-drawer.is-open')).toBeVisible();
        await page.fill('[data-role="goal-name"]', 'Empty Ref Goal');
        await page.selectOption('[data-role="goal-operator"]', 'is_empty');

        await Promise.all([
            page.waitForURL(SLIMVIEW6, { timeout: 15_000 }),
            page.click('[data-action="save-goal"]'),
        ]);
        await expect(page.locator('.slimstat-gf-goal__name')).toContainText('Empty Ref Goal');
    });

    test('validation-funnel-step-value-required: empty step value names the offending step', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-template="blank"]');
        await expect(page.locator('#slimstat-gf-funnel-builder.is-open')).toBeVisible();
        await page.fill('[data-role="funnel-name"]', 'Bad Funnel');

        const rows = page.locator('.slimstat-gf-step-row');
        await rows.nth(0).locator('[data-role="step-value"]').fill('/');
        // Leave step 2's value empty, then save.
        await page.click('[data-action="save-funnel"]');

        await expect(page.locator('[data-role="builder-error"]')).toContainText('Step 2 needs a value');
        await expect(page.locator('#slimstat-gf-funnel-builder.is-open')).toBeVisible();
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

    // ─── AJAX date-range + multi-funnel data (#6, #8) ────────────

    test('funnel-test-step-sends-date-range: Test request carries the on-screen range (#6)', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-template="blank"]');
        await expect(page.locator('#slimstat-gf-funnel-builder.is-open')).toBeVisible();

        const firstRow = page.locator('.slimstat-gf-step-row').nth(0);
        await firstRow.locator('[data-role="step-value"]').fill('/');

        const req = page.waitForRequest(r =>
            r.url().includes('admin-ajax.php') &&
            (r.postData() || '').includes('action=slimstat_test_funnel_step')
        );
        await firstRow.locator('[data-action="test-step"]').click();
        const postData = (await req).postData() || '';
        // Without this the backend defaults the window and historically collapsed
        // it to dt BETWEEN 0 AND 0 → "0 matches" for pages that exist.
        expect(postData).toContain('time_range_type');
    });

    test('funnel-tab-lazy-load-populates: 2nd funnel tab fetches data for the selected range (#8)', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await seedFunnels([
            { name: 'First Funnel', steps: [
                { name: 'A', dimension: 'resource', operator: 'contains', value: '/' },
                { name: 'B', dimension: 'resource', operator: 'contains', value: '/' },
            ]},
            { name: 'Second Funnel', steps: [
                { name: 'A', dimension: 'resource', operator: 'contains', value: '/' },
                { name: 'B', dimension: 'resource', operator: 'contains', value: '/' },
            ]},
        ]);
        await gotoSlimview6(page);

        // The 2nd tab starts unloaded; clicking it must fetch via
        // slimstat_load_funnel_data WITH the date range, then mark itself loaded.
        const secondTab = page.locator('.slimstat-gf-tab').nth(1);
        const req = page.waitForRequest(r =>
            r.url().includes('admin-ajax.php') &&
            (r.postData() || '').includes('action=slimstat_load_funnel_data')
        );
        await secondTab.click();
        const postData = (await req).postData() || '';
        expect(postData).toContain('time_range_type');

        const secondPanel = page.locator('.slimstat-gf-funnel-panel').nth(1);
        await expect(secondPanel).toHaveAttribute('data-loaded', 'true');
    });

    // ─── See templates after a funnel exists (#7) ────────────────

    test('see-templates-reveal: prefab templates stay reachable after a funnel exists', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await seedFunnels([{ name: 'Existing', steps: [
            { name: 'A', dimension: 'resource', operator: 'contains', value: '/' },
            { name: 'B', dimension: 'resource', operator: 'contains', value: '/x' },
        ]}]);
        await gotoSlimview6(page);

        // The reveal is collapsed by default; clicking it shows the same 6 cards.
        const panel = page.locator('[data-role="funnels-templates-panel"]');
        await expect(panel).toBeHidden();
        const toggle = page.locator('[data-action="toggle-funnel-templates"]');
        await expect(toggle).toBeVisible();

        // The toggle now lives in the postbox header, right beside "+ Add funnel".
        await expect(
            page.locator('#slim_p9_02 .slimstat-header-buttons [data-action="toggle-funnel-templates"]')
        ).toBeVisible();
        await expect(
            page.locator('#slim_p9_02 .slimstat-header-buttons .slimstat-gf-cta')
        ).toBeVisible();

        await toggle.click();
        await expect(panel).toBeVisible();
        await expect(panel.locator('.slimstat-gf-template-card')).toHaveCount(6);
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');

        // A revealed card opens the builder, so users can build another funnel
        // from a template instead of always starting from scratch.
        await panel.locator('[data-template="woocommerce_purchase"]').click();
        await expect(page.locator('#slimstat-gf-funnel-builder.is-open')).toBeVisible();
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
        await expect(templates.nth(0)).toContainText('WooCommerce purchase');
        await expect(templates.nth(1)).toContainText('Checkout completion');
        await expect(templates.nth(2)).toContainText('Landing to contact');
        await expect(templates.nth(3)).toContainText('Homepage to pricing to checkout');
        await expect(templates.nth(4)).toContainText('Landing to thank-you');
        await expect(templates.nth(5)).toContainText('Blank funnel');
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

    // ─── Goal edit via drawer ───────────────────────────────────

    test('goal-edit: drawer opens prefilled, rename round-trips, count unchanged', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await seedGoals([{ name: 'Before Edit', dimension: 'resource', operator: 'contains', value: '/p', active: true }]);
        await gotoSlimview6(page);

        await page.click('.slimstat-gf-goal [data-action="open-goal-drawer"][data-mode="edit"]');
        await expect(page.locator('#slimstat-gf-goal-drawer.is-open')).toBeVisible();
        // Drawer is prefilled from the goal's data-goal JSON.
        await expect(page.locator('[data-role="goal-name"]')).toHaveValue('Before Edit');

        await page.fill('[data-role="goal-name"]', 'After Edit');
        await Promise.all([
            page.waitForURL(SLIMVIEW6, { timeout: 15_000 }),
            page.click('[data-action="save-goal"]'),
        ]);

        await expect(page.locator('.slimstat-gf-goal__name')).toContainText('After Edit');
        // Edit must not create a second goal.
        await expect(page.locator('.slimstat-gf-goal')).toHaveCount(1);
    });

    // ─── Funnel delete + edit (Pro) ─────────────────────────────

    test('funnel-delete: destructive action uses the confirm sheet and removes the funnel', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await seedFunnels([{ name: 'Doomed Funnel', steps: [
            { name: 'A', dimension: 'resource', operator: 'contains', value: '/a' },
            { name: 'B', dimension: 'resource', operator: 'contains', value: '/b' },
        ]}]);
        await gotoSlimview6(page);

        let nativeConfirmInvoked = false;
        page.on('dialog', async (dialog) => { nativeConfirmInvoked = true; await dialog.dismiss(); });

        await page.click('[data-action="delete-funnel"]');
        await expect(page.locator('#slimstat-gf-confirm-sheet.is-open')).toBeVisible();
        await expect(page.locator('[data-role="confirm-title"]')).toContainText('Delete funnel?');
        expect(nativeConfirmInvoked).toBe(false);

        await Promise.all([
            page.waitForURL(SLIMVIEW6, { timeout: 15_000 }),
            page.click('[data-action="confirm-destructive"]'),
        ]);
        await expect(page.locator('.slimstat-gf-funnel-panel')).toHaveCount(0);
    });

    test('funnel-edit: builder opens prefilled, rename round-trips, count unchanged', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await seedFunnels([{ name: 'Old Funnel Name', steps: [
            { name: 'A', dimension: 'resource', operator: 'contains', value: '/a' },
            { name: 'B', dimension: 'resource', operator: 'contains', value: '/b' },
        ]}]);
        await gotoSlimview6(page);

        await page.click('[data-action="open-funnel-builder"][data-mode="edit"]');
        await expect(page.locator('#slimstat-gf-funnel-builder.is-open')).toBeVisible();
        await expect(page.locator('[data-role="funnel-name"]')).toHaveValue('Old Funnel Name');
        await expect(page.locator('.slimstat-gf-step-row')).toHaveCount(2);

        await page.fill('[data-role="funnel-name"]', 'New Funnel Name');
        await Promise.all([
            page.waitForURL(SLIMVIEW6, { timeout: 15_000 }),
            page.click('[data-action="save-funnel"]'),
        ]);

        // Exactly one funnel remains (edit updated in place, didn't create a copy).
        await expect(page.locator('.slimstat-gf-funnel-panel__name')).toHaveCount(1);
        await expect(page.locator('.slimstat-gf-funnel-panel__name')).toContainText('New Funnel Name');
    });

    // ─── Modal accessibility: focus trap, Escape, focus restore ──

    test('a11y-modal: drawer is aria-modal, traps Tab, Escape closes and restores focus', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        const opener = page.locator('[data-role="goals-empty"] [data-action="open-goal-drawer"]');
        await opener.focus();
        await opener.press('Enter');
        await expect(page.locator('#slimstat-gf-goal-drawer.is-open')).toBeVisible();

        // Declared modal + initial focus moved into the dialog.
        await expect(page.locator('#slimstat-gf-goal-drawer')).toHaveAttribute('aria-modal', 'true');
        await expect(page.locator('[data-role="goal-name"]')).toBeFocused();

        // Tab cycling stays inside the dialog — after several Tabs the active
        // element is still a descendant of the drawer.
        for (let i = 0; i < 12; i++) {
            await page.keyboard.press('Tab');
            const insideDrawer = await page.evaluate(() => {
                const drawer = document.querySelector('#slimstat-gf-goal-drawer');
                return !!drawer && drawer.contains(document.activeElement);
            });
            expect(insideDrawer).toBe(true);
        }

        // Escape closes the dialog and returns focus to the opener.
        await page.keyboard.press('Escape');
        await expect(page.locator('#slimstat-gf-goal-drawer.is-open')).toHaveCount(0);
        await expect(opener).toBeFocused();
    });

    // ─── Keyboard step reorder (WCAG 2.1.1) ─────────────────────

    test('a11y-keyboard-reorder: ArrowDown on the drag handle moves a funnel step', async ({ page }) => {
        await forceLimits(5, 3, WP_CONTENT);
        await gotoSlimview6(page);

        await page.click('[data-template="blank"]');
        await expect(page.locator('#slimstat-gf-funnel-builder.is-open')).toBeVisible();

        const rows = page.locator('.slimstat-gf-step-row');
        await rows.nth(0).locator('[data-role="step-name"]').fill('First');
        await rows.nth(1).locator('[data-role="step-name"]').fill('Second');

        // Move row 1 down with the keyboard; the order must swap.
        const handle = rows.nth(0).locator('[data-action="drag-step"]');
        await handle.focus();
        await handle.press('ArrowDown');

        await expect(rows.nth(0).locator('[data-role="step-name"]')).toHaveValue('Second');
        await expect(rows.nth(1).locator('[data-role="step-name"]')).toHaveValue('First');
        // Step numbers are renumbered after the move.
        await expect(rows.nth(0).locator('[data-role="step-num"]')).toContainText('1');
    });

    // ─── Consent surface (GDPR) ─────────────────────────────────

    test('consent-notice: the goals card surfaces the consent notice (WP Consent API)', async ({ page }) => {
        // Requires the WP Consent API (wp-consent-api) active so wp_has_consent()
        // exists — it ships in this workspace and gates the notice text.
        await forceLimits(1, 0, WP_CONTENT);
        await seedGoals([{ name: 'Tracked Goal', dimension: 'resource', operator: 'contains', value: '/g', active: true }]);
        await gotoSlimview6(page);

        await expect(page.locator('.slimstat-gf-consent'))
            .toContainText('visitors who provided statistics consent');
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
