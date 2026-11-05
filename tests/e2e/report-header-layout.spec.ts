import { test, expect } from '@playwright/test';

for (const width of [1440, 390]) {
  test(`report titles precede wrapping actions at ${width}px @compat`, async ({ page }, info) => {
    await page.setViewportSize({ width, height: 900 });
    for (const view of ['slimview2', 'slimview5']) {
      await page.goto(`/wp-admin/admin.php?page=${view}`);
      const headers = page.locator('.wrap-slimstat .postbox > h3 ~ .slimstat-header-buttons:visible');
      await expect(headers.first()).toBeVisible();
      for (const direction of ['ltr', 'rtl']) {
        await page.evaluate(dir => { document.documentElement.dir = dir; }, direction);
        const positions = await headers.evaluateAll(nodes => nodes.map(node => {
          const title = node.parentElement!.querySelector(':scope > h3')!;
          const heading = title.getBoundingClientRect();
          const actions = node.getBoundingClientRect();
          const card = node.parentElement!.getBoundingClientRect();
          return {
            titleFirst: Boolean(title.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_FOLLOWING),
            below: actions.top >= heading.bottom - 1,
            contained: actions.left >= card.left && actions.right <= card.right + 1,
            float: getComputedStyle(node).cssFloat,
          };
        }));
        for (const position of positions) {
          expect(position).toEqual({ titleFirst: true, below: true, contained: true, float: 'none' });
        }
      }
      await page.evaluate(() => { document.documentElement.dir = 'ltr'; });
      await headers.first().locator('..').screenshot({ path: info.outputPath(`${view}-header-${width}.png`) });
    }
  });
}
