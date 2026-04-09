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
import { test, expect, Page } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { closeDb, clearStatsTable, getPool } from './helpers/setup';

async function ensureLoggedIn(page: Page): Promise<void> {
  if (page.url().includes('wp-login.php')) {
    await page.fill('#user_login', 'parhumm');
    await page.fill('#user_pass', 'testpass123');
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**', { timeout: 30_000 });
  }
}

async function seedManyRows(count: number): Promise<void> {
  const now = Math.floor(Date.now() / 1000);
  for (let i = 0; i < count; i++) {
    await getPool().execute(
      `INSERT INTO wp_slim_stats
         (resource, dt, ip, visit_id, browser, platform, content_type)
       VALUES (?, ?, '127.0.0.1', 1, 'Chrome', 'Windows', 'post')`,
      [`/e2e-156-row-${i}/`, now - i],
    );
  }
}

test.describe('Native scroll on report panels — #156', () => {
  test.setTimeout(90_000);

  test.beforeEach(async () => {
    await clearStatsTable();
    await seedManyRows(60);
  });

  test.afterAll(async () => {
    await closeDb();
  });

  test('SlimScroll library is gone', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);
    await expect(page.locator('#slim_p7_02')).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(6_000);

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
    await ensureLoggedIn(page);
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

  test('scroll chaining is contained inside .inside', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);
    await expect(page.locator('#slim_p7_02 .inside')).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(6_000);

    const inside = page.locator('#slim_p7_02 .inside');

    // The native-scroll fix REQUIRES .inside to use overflow:auto (so the
    // browser owns the scroll). The pre-fix code wraps .inside with the
    // SlimScroll plugin, which forces overflow:hidden. Catch that here.
    const overflow = await inside.evaluate((el) => window.getComputedStyle(el).overflowY);
    expect(overflow, '.inside must use native overflow:auto, not SlimScroll wrapper').toBe('auto');

    const overflowsEnough = await inside.evaluate(
      (el) => el.scrollHeight - el.clientHeight >= 100,
    );
    test.skip(!overflowsEnough, 'panel does not overflow enough to test chaining');

    // Scroll the panel to its bottom.
    await inside.evaluate((el) => { el.scrollTop = el.scrollHeight; });
    const initialWindowScroll = await page.evaluate(() => window.scrollY);

    // Position the mouse over the panel before wheeling so the wheel events
    // dispatch on .inside. overscroll-behavior:contain should prevent the
    // wheel from chaining to the page when .inside is at its scroll boundary.
    const box = await inside.boundingBox();
    if (!box) test.skip(true, 'no bounding box');
    await page.mouse.move(box!.x + box!.width / 2, box!.y + box!.height / 2);
    for (let i = 0; i < 5; i++) {
      await page.mouse.wheel(0, 100);
      await page.waitForTimeout(50);
    }
    await page.waitForTimeout(500);

    // The decisive proof of `overscroll-behavior: contain` is that wheel
    // events dispatched on a scroll-saturated child container do NOT
    // propagate up to the document root.
    //
    // Note: in Playwright headless Chromium, `page.mouse.wheel()` may still
    // bubble at the document level even with overscroll-behavior:contain
    // depending on rendering — assert via element-level dispatch instead.
    const eventReachedDocument = await inside.evaluate((el) => {
      return new Promise<boolean>((resolve) => {
        let reached = false;
        const onDocWheel = () => { reached = true; };
        document.addEventListener('wheel', onDocWheel, { passive: true });
        // Pin the element to its scroll bottom and dispatch a wheel event
        (el as HTMLElement).scrollTop = el.scrollHeight;
        const ev = new WheelEvent('wheel', { deltaY: 100, bubbles: true, cancelable: true });
        el.dispatchEvent(ev);
        setTimeout(() => {
          document.removeEventListener('wheel', onDocWheel);
          // The event will bubble to document by spec; the meaningful test
          // is whether the page actually scrolled.
          resolve(reached);
        }, 100);
      });
    });
    // Whether the wheel event bubbled is fine — what matters is that the
    // window did not scroll.
    void eventReachedDocument;

    const finalWindowScroll = await page.evaluate(() => window.scrollY);
    expect(
      Math.abs(finalWindowScroll - initialWindowScroll),
      'window must not scroll when chaining is contained',
    ).toBeLessThanOrEqual(5);
  });
});
