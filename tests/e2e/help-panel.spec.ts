/**
 * The help panel behind the header "?" button: the tab for the current screen opens first,
 * the sidebar links carry utm_medium=help, and the Pro tab sells Pro only to Free sites.
 * Read-only: no options or stats rows are touched.
 */
import { test, expect, Page } from '@playwright/test';
import { getProState } from './helpers/pro-state';

async function openHelp(page: Page, slug: string): Promise<void> {
  await page.goto(`/wp-admin/admin.php?page=${slug}`);
  await page.locator('[data-slimstat-help-trigger]').click();
  await expect(page.locator('#contextual-help-wrap')).toBeVisible();
}

const activeTab = (page: Page) => page.locator('#contextual-help-columns .contextual-help-tabs li.active');

test.describe('Help panel', () => {
  test('Heatmaps opens on the Heatmaps tab', async ({ page }) => {
    await openHelp(page, 'slimheatmap');
    await expect(activeTab(page)).toHaveText('Heatmaps');
    await expect(page.locator('#tab-panel-wp-slimstat-help-heatmaps')).toBeVisible();
  });

  test('a report screen opens on Key terms, with four tagged sidebar links', async ({ page }) => {
    await openHelp(page, 'slimview2');
    await expect(activeTab(page)).toHaveText('Key terms');
    await expect(page.locator('#tab-panel-wp-slimstat-help-terms')).toContainText('A visit ends after 30 minutes without activity');

    const links = page.locator('#contextual-help-columns .contextual-help-sidebar a');
    await expect(links).toHaveCount(4);
    for (const href of await links.evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).href))) {
      expect(href).toMatch(/^https:\/\/wp-slimstat\.com\/.+utm_medium=help/);
    }
  });

  test('filters are explained under the labels of the filter list', async ({ page }) => {
    await openHelp(page, 'slimview2');
    await page.locator('#tab-link-wp-slimstat-help-filters a').click();
    await expect(page.locator('#tab-panel-wp-slimstat-help-filters')).toContainText("IP Address: The visitor's internet address.");
  });

  test('the Pro tab offers an upgrade only without Pro', async ({ page }) => {
    const { pro_activated } = await getProState(page);
    await openHelp(page, 'slimview2');
    await page.locator('#tab-link-wp-slimstat-help-pro a').click();
    const panel = page.locator('#tab-panel-wp-slimstat-help-pro');

    if (pro_activated) {
      await expect(panel).toContainText('Your Pro plan includes:');
      await expect(panel.locator('a[href*="/pricing/"]')).toHaveCount(0);
    } else {
      await expect(panel).toContainText('SlimStat Pro adds:');
      await expect(panel.getByRole('link', { name: 'Upgrade to Pro' })).toHaveAttribute('href', /\/pricing\/\?.*utm_medium=help/);
    }
  });
});
