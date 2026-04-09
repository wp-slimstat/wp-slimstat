/**
 * E2E regression: report panels use native CSS scroll, no SlimScroll (#156).
 *
 * Before the fix, every report panel's `.inside` was wrapped by jQuery
 * SlimScroll v1.3.8 (bundled inside admin.js), which:
 *   - Forced overflow:hidden on .inside, replacing native scrolling with a
 *     5px / opacity 0.15 fake scrollbar
 *   - Used wheelStep:10 (~6px per wheel notch instead of 50+)
 *   - Only listened to legacy DOMMouseScroll/mousewheel events, breaking
 *     trackpad and Magic Mouse momentum scrolling
 *   - Allowed scroll chaining to the page when content reached the boundary
 *
 * After the fix:
 *   - SlimScroll init + 167-line bundled library are deleted from admin.js
 *   - .inside uses overflow:auto + overscroll-behavior:contain natively
 *   - A visible 10px scrollbar is styled via ::-webkit-scrollbar +
 *     scrollbar-color (Firefox)
 *
 * Source: customer support ticket #15082 (sanitized).
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { closeDb, clearStatsTable, seedPageviews } from './helpers/setup';

test.describe('Native scroll on report panels — #156', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await clearStatsTable();
    const now = Math.floor(Date.now() / 1000);
    await seedPageviews({
      count: 60,
      resourcePrefix: '/e2e-156-row-',
      baseDt: now - 59,
      stepSeconds: 1,
    });
  });

  test.afterAll(async () => {
    await closeDb();
  });

  test('SlimScroll library is gone', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await expect(page.locator('#slim_p7_02')).toBeVisible({ timeout: 30_000 });

    // jQuery plugin must not be defined.
    const slimScrollFn = await page.evaluate(
      () => typeof (window as any).jQuery?.fn?.slimScroll,
    );
    expect(slimScrollFn, 'jQuery.fn.slimScroll should be undefined').toBe('undefined');

    // No wrapper elements should be left behind.
    const wrapperCount = await page.locator('.slimScrollDiv').count();
    expect(wrapperCount, '.slimScrollDiv wrapper should not exist').toBe(0);

    const barCount = await page.locator('.slimScrollBar').count();
    expect(barCount, '.slimScrollBar element should not exist').toBe(0);
  });

  test('.inside has native overflow:auto and overscroll-behavior:contain', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await expect(page.locator('#slim_p7_02 .inside')).toBeVisible({ timeout: 30_000 });

    const styles = await page.locator('#slim_p7_02 .inside').evaluate((el) => {
      const cs = window.getComputedStyle(el);
      return {
        overflowY: cs.overflowY,
        overscrollBehavior: cs.overscrollBehavior,
      };
    });

    expect(styles.overflowY).toBe('auto');
    expect(styles.overscrollBehavior).toContain('contain');
  });

  test('overscroll-behavior:contain CSS is applied across all report panels', async ({ page }) => {
    // The chaining behavior of `overscroll-behavior: contain` is enforced by
    // the browser's compositor for real OS-driven wheel/touch events. We
    // verified manually on macOS with Magic Mouse + trackpad that the page
    // does NOT scroll along with .inside (see PR Human QA checklist § 5).
    //
    // Headless Chromium's synthetic `page.mouse.wheel()` does NOT respect
    // overscroll-behavior containment for chaining purposes — that path
    // bypasses the compositor's gesture pipeline. So we cannot meaningfully
    // assert window.scrollY behavior in CI; we instead assert the CSS
    // contract is in place across every report panel that ships a .inside
    // scroll container, since CSS is what the compositor reads at runtime.
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await expect(page.locator('#slim_p7_02 .inside')).toBeVisible({ timeout: 30_000 });

    // Sample every report panel that has a .inside scroll container.
    const panelStyles = await page.locator('[id^="slim_p"] .inside').evaluateAll((els) => {
      return els.map((el) => {
        const cs = window.getComputedStyle(el);
        return {
          parentId: el.parentElement?.id ?? '(orphan)',
          overflowY: cs.overflowY,
          overscrollBehaviorY: cs.overscrollBehaviorY,
        };
      });
    });

    expect(panelStyles.length, 'at least one [id^="slim_p"] .inside should be present').toBeGreaterThan(0);

    // Every scroll-bearing panel must use native overflow:auto (NOT
    // SlimScroll's forced overflow:hidden). Map containers and chart
    // containers use overflow:visible/hidden intentionally — exclude those
    // by checking only panels whose computed overflow is set.
    const scrollPanels = panelStyles.filter((p) => p.overflowY === 'auto' || p.overflowY === 'scroll');
    expect(scrollPanels.length, 'expected at least one auto-scrolling .inside').toBeGreaterThan(0);

    for (const panel of scrollPanels) {
      expect(
        panel.overscrollBehaviorY,
        `${panel.parentId} .inside must have overscroll-behavior-y:contain`,
      ).toBe('contain');
    }

    // Cross-check the boundary scrollTop API behavior: setting scrollTop
    // beyond scrollHeight on a `.inside` clamps cleanly without throwing
    // and without affecting window.scrollY (this part DOES work in headless).
    const inside = page.locator('#slim_p7_02 .inside');
    const beforeWindowY = await page.evaluate(() => window.scrollY);
    await inside.evaluate((el) => { (el as HTMLElement).scrollTop = el.scrollHeight + 999_999; });
    const afterWindowY = await page.evaluate(() => window.scrollY);
    expect(
      Math.abs(afterWindowY - beforeWindowY),
      'programmatic scrollTop overflow must NOT scroll the window',
    ).toBeLessThanOrEqual(2);
  });
});
