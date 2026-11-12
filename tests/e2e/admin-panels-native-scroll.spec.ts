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
 *
 * After the fix:
 *   - SlimScroll init + 167-line bundled library are deleted from admin.js
 *   - .inside uses overflow:auto + vertical scroll chaining natively
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

  test('.inside keeps native scrolling and permits vertical page handoff', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await expect(page.locator('#slim_p7_02 .inside')).toBeVisible({ timeout: 30_000 });

    const styles = await page.locator('#slim_p7_02 .inside').evaluate((el) => {
      const cs = window.getComputedStyle(el);
      return {
        overflowY: cs.overflowY,
        overscrollBehaviorY: cs.overscrollBehaviorY,
        overscrollBehaviorX: cs.overscrollBehaviorX,
      };
    });

    expect(styles.overflowY).toBe('auto');
    expect(styles.overscrollBehaviorY).toBe('auto');
    expect(styles.overscrollBehaviorX).toBe('contain');
  });

  test('all report panels permit vertical chaining without restoring SlimScroll', async ({ page }) => {
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
        `${panel.parentId} .inside must allow vertical scroll chaining`,
      ).toBe('auto');
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

  test('an empty report lets the page scroll in either direction', async ({page}) => {
    await clearStatsTable();
    await page.setViewportSize({width:390,height:900});
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`);
    const panel=page.locator('#slim_p7_02 .inside');
    for(const dir of ['ltr','rtl']) {
      await page.evaluate(dir=>{document.documentElement.dir=dir;document.body.classList.toggle('rtl',dir==='rtl');},dir);
      await panel.evaluate(el=>{
        // Empty Free reports make the page shorter. Leave room below the viewport to test handoff.
        const maxScroll=document.documentElement.scrollHeight-innerHeight;
        window.scrollTo(0,Math.min(el.getBoundingClientRect().top+scrollY-180,maxScroll-150));
      });
      expect(await panel.evaluate(el=>el.scrollHeight-el.clientHeight)).toBeLessThanOrEqual(1);
      const box=(await panel.boundingBox())!;
      await page.mouse.move(box.x+box.width/2,box.y+50);
      const start=await page.evaluate(()=>scrollY);
      await expect.poll(async()=>{await page.mouse.wheel(0,100);return page.evaluate(()=>scrollY);}).toBeGreaterThan(start+20);
      const down=await page.evaluate(()=>scrollY);
      await expect.poll(async()=>{await page.mouse.wheel(0,-100);return page.evaluate(()=>scrollY);}).toBeLessThan(down-20);
    }
  });

  for (const width of [1440, 390]) {
    for (const direction of ['ltr', 'rtl']) {
      test(`wheel scroll hands off at both panel boundaries, ${direction}, ${width}px`, async ({ page }) => {
        await page.setViewportSize({width, height:900});
        await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`);
        await page.evaluate(dir => { document.documentElement.dir=dir; document.body.classList.toggle('rtl',dir==='rtl'); }, direction);
        const panel=page.locator('#slim_p7_02 .inside');
        await panel.scrollIntoViewIfNeeded();
        await expect.poll(()=>panel.evaluate(el=>el.scrollHeight-el.clientHeight)).toBeGreaterThan(50);
        const position=async (bottom: boolean) => {
          await panel.evaluate((el,bottom)=>{
            window.scrollTo(0,el.getBoundingClientRect().top+window.scrollY-180);
            el.scrollTop=bottom ? el.scrollHeight : 0;
          },bottom);
          const box=(await panel.boundingBox())!;
          await page.mouse.move(box.x+box.width/2,box.y+Math.min(100,box.height/2));
        };
        await position(false);
        const initialY=await page.evaluate(()=>window.scrollY);
        await page.mouse.wheel(0,100);
        await expect.poll(()=>panel.evaluate(el=>el.scrollTop)).toBeGreaterThan(0);
        expect(await page.evaluate(()=>window.scrollY)).toBe(initialY);
        await position(true);
        const bottomY=await page.evaluate(()=>window.scrollY);
        // A fractional bottom offset can consume the first wheel event in Chromium.
        await expect.poll(async () => {
          await page.mouse.wheel(0,150);
          return page.evaluate(()=>window.scrollY);
        }).toBeGreaterThan(bottomY+20);
        await position(false);
        const topY=await page.evaluate(()=>window.scrollY);
        await expect.poll(async () => {
          await page.mouse.wheel(0,-100);
          return page.evaluate(()=>window.scrollY);
        }).toBeLessThan(topY-20);
      });
    }
  }
});
