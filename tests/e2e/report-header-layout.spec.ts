import { test, expect } from '@playwright/test';

/**
 * Report cards have two header rows. The first holds the title (with its info icon) on one side
 * and Export then Refresh on the other; the second holds Copy Prompt then AI Analysis with its
 * Pro badge, on the title's start edge. Nothing overlaps, at any width or direction.
 */
for (const width of [1440, 390]) {
  test(`report headers keep the title row and the AI row apart at ${width}px @compat`, async ({ page }, info) => {
    await page.setViewportSize({ width, height: 900 });
    for (const view of ['slimview2', 'slimview5']) {
      await page.goto(`/wp-admin/admin.php?page=${view}`);
      const heads = page.locator('.wrap-slimstat .postbox > .slimstat-report-head:visible');
      await expect(heads.first()).toBeVisible();
      await expect(page.locator('.wrap-slimstat .postbox > .slimstat-ai-actions').first()).toBeVisible();
      for (const direction of ['ltr', 'rtl']) {
        await page.evaluate(dir => { document.documentElement.dir = dir; }, direction);
        const cards = await heads.evaluateAll((nodes, rtl) => nodes.map(head => {
          const rect = (node: Element | null) => node?.getBoundingClientRect() ?? null;
          const card = rect(head.parentElement)!;
          const title = head.querySelector(':scope > h3')!;
          const buttons = head.querySelector(':scope > .slimstat-header-buttons')!;
          const heading = rect(title)!;
          const actions = rect(buttons)!;
          const exportLink = rect(buttons.querySelector('.slimstat-font-download, .button-export-to-xls'));
          const refresh = rect(buttons.querySelector('.refresh'));
          const ai = head.parentElement!.querySelector(':scope > .slimstat-ai-actions');
          const copy = ai?.querySelector('.slimstat-ai-prompt');
          const analyze = ai?.querySelector('.slimstat-ai-analyze');
          const badge = rect(analyze?.querySelector('.slimstat-ai-pro-badge') ?? null);
          const range = document.createRange();
          range.selectNodeContents(title);
          const label = range.getBoundingClientRect();
          const overlap = !(actions.top >= heading.bottom - 1 || actions.left >= heading.right - 1 || actions.right <= heading.left + 1);
          return {
            id: head.parentElement!.id,
            titleFirst: Boolean(title.compareDocumentPosition(buttons) & Node.DOCUMENT_POSITION_FOLLOWING),
            overlap: actions.width > 0 && overlap,
            contained: actions.left >= card.left - 1 && actions.right <= card.right + 1,
            float: getComputedStyle(buttons).cssFloat,
            // Refresh sits right after Export (left of it in RTL), with a gap.
            refreshAfterExport: !exportLink || !refresh || (rtl ? exportLink.left - refresh.right : refresh.left - exportLink.right) >= 4,
            aiBelowTitle: !ai || rect(ai)!.top >= heading.bottom - 12,
            copyThenAnalyze: !copy || !analyze || Boolean(copy.compareDocumentPosition(analyze) & Node.DOCUMENT_POSITION_FOLLOWING),
            badgeAttached: !badge || Math.abs(badge.top + badge.height / 2 - (rect(analyze!)!.top + rect(analyze!)!.height / 2)) < 6,
            aiOnStartEdge: !copy || (rtl ? Math.abs(rect(copy)!.right - label.right) : Math.abs(rect(copy)!.left - label.left)) <= 4,
          };
        }), direction === 'rtl');
        for (const card of cards) {
          expect(card, `${view} ${card.id} ${direction}`).toEqual({
            id: card.id, titleFirst: true, overlap: false, contained: true, float: 'none',
            refreshAfterExport: true, aiBelowTitle: true, copyThenAnalyze: true, badgeAttached: true, aiOnStartEdge: true,
          });
        }
      }
      await page.evaluate(() => { document.documentElement.dir = 'ltr'; });
      await heads.first().locator('..').screenshot({ path: info.outputPath(`${view}-header-${width}.png`) });
    }
  });
}

test('a long title wraps beside or above the controls, never under them @compat', async ({ page }) => {
  await page.setViewportSize({ width: 700, height: 900 });
  await page.goto('/wp-admin/admin.php?page=slimview2');
  const card = page.locator('#slim_p1_10');
  await card.locator('h3').evaluate(h3 => { h3.firstChild!.textContent = 'Top Referring Domains for every campaign, channel and landing page in this very long localized '; });
  const box = await card.evaluate(node => {
    const r = (s: string) => node.querySelector(s)!.getBoundingClientRect();
    const h3 = r('.slimstat-report-head h3');
    const buttons = r('.slimstat-header-buttons');
    return { overlap: !(buttons.top >= h3.bottom - 1 || buttons.left >= h3.right - 1 || buttons.right <= h3.left + 1), scroll: node.scrollWidth - node.clientWidth };
  });
  expect(box).toEqual({ overlap: false, scroll: 0 });
});
