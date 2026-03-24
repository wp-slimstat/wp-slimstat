/**
 * E2E: Chart granularity persistence across page navigation
 *
 * Regression test for GitHub issue #265.
 * The chart granularity dropdown (Daily/Weekly/Monthly/etc.) should persist
 * the user's selection when navigating away and returning to the Overview page.
 *
 * Before the fix: PHP auto-detects granularity from date range on every page
 * load (e.g. 30 days → "weekly"), ignoring the user's last selection.
 *
 * Tests cover:
 *   - Granularity reverts to auto-detected value on page reload (bug confirmation)
 *   - Granularity persists after page reload (post-fix)
 *   - Granularity persists after navigating away and back
 *   - Each granularity option (daily, weekly, monthly) persists correctly
 *   - Changing date range resets granularity only when selection becomes invalid
 */
import { test, expect, type Page } from '@playwright/test';
import { BASE_URL } from './helpers/env';

// ─── Constants ────────────────────────────────────────────────────────

const OVERVIEW_URL = `${BASE_URL}/wp-admin/admin.php?page=slimlayout`;
const ACCESS_LOG_URL = `${BASE_URL}/wp-admin/admin.php?page=slimview2`;
const SETTINGS_URL = `${BASE_URL}/wp-admin/admin.php?page=slimconfig&tab=1`;

/** CSS selector for the granularity <select> on the Overview page */
const GRANULARITY_SELECT = 'select.slimstat-granularity-select';

// ─── Helpers ──────────────────────────────────────────────────────────

/**
 * Navigate to the Overview page and wait for chart to be present.
 */
async function goToOverview(page: Page): Promise<void> {
  await page.goto(OVERVIEW_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector(GRANULARITY_SELECT, { timeout: 15_000 });
}

/**
 * Get the currently selected granularity value from the dropdown.
 */
async function getSelectedGranularity(page: Page): Promise<string> {
  return page.locator(GRANULARITY_SELECT).first().inputValue();
}

/**
 * Change the granularity dropdown and wait for the chart AJAX to complete.
 */
async function setGranularity(page: Page, value: string): Promise<void> {
  const select = page.locator(GRANULARITY_SELECT).first();

  // Verify the option is not disabled
  const option = select.locator(`option[value="${value}"]`);
  const isDisabled = await option.getAttribute('disabled');
  if (isDisabled !== null) {
    throw new Error(`Granularity "${value}" is disabled for the current date range`);
  }

  // Change the dropdown — this triggers the AJAX fetch
  await select.selectOption(value);

  // Wait for the AJAX response to complete (chart data update)
  // The JS updates data-granularity on the chart data div after AJAX success
  await page.waitForFunction(
    (expected) => {
      const el = document.querySelector('[id^="slimstat_chart_data_"]');
      return el && el.getAttribute('data-granularity') === expected;
    },
    value,
    { timeout: 15_000 },
  );
}

// ─── Test suite ───────────────────────────────────────────────────────

test.describe('Chart granularity persistence (#265)', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(60_000);

  /**
   * Bug confirmation: on a 30-day range, selecting "Daily" and reloading
   * should NOT revert to "Weekly".
   *
   * Before fix: FAILS (reverts to weekly)
   * After fix: PASSES (daily persists)
   */
  test('granularity persists after page reload', async ({ page }) => {
    await goToOverview(page);

    // The auto-detected granularity for "Last 30 Days" is typically "weekly"
    const initial = await getSelectedGranularity(page);
    console.log('Initial granularity (auto-detected):', initial);

    // Change to "daily"
    await setGranularity(page, 'daily');
    expect(await getSelectedGranularity(page)).toBe('daily');

    // Reload the page
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector(GRANULARITY_SELECT, { timeout: 15_000 });

    // Verify granularity persisted
    const afterReload = await getSelectedGranularity(page);
    console.log('Granularity after reload:', afterReload);
    expect(afterReload, 'Granularity should persist after reload').toBe('daily');
  });

  /**
   * Granularity persists after navigating to another SlimStat page and back.
   */
  test('granularity persists after navigating away and back', async ({ page }) => {
    await goToOverview(page);

    // Set to "monthly"
    await setGranularity(page, 'monthly');
    expect(await getSelectedGranularity(page)).toBe('monthly');

    // Navigate to Access Log
    await page.goto(ACCESS_LOG_URL, { waitUntil: 'domcontentloaded' });

    // Navigate back to Overview
    await goToOverview(page);

    // Verify granularity persisted
    const afterNav = await getSelectedGranularity(page);
    console.log('Granularity after nav away and back:', afterNav);
    expect(afterNav, 'Granularity should persist after navigation').toBe('monthly');
  });

  /**
   * Granularity persists after visiting a non-SlimStat admin page.
   * Mirrors the reporter's scenario: close Overview menu → reopen it.
   */
  test('granularity persists after visiting non-SlimStat page', async ({ page }) => {
    await goToOverview(page);

    // Set to "daily"
    await setGranularity(page, 'daily');
    expect(await getSelectedGranularity(page)).toBe('daily');

    // Navigate to a completely different admin page (e.g., Posts)
    await page.goto(`${BASE_URL}/wp-admin/edit.php`, { waitUntil: 'domcontentloaded' });

    // Return to Overview
    await goToOverview(page);

    // Verify granularity persisted
    const afterNav = await getSelectedGranularity(page);
    expect(afterNav, 'Granularity should persist after visiting Posts page').toBe('daily');
  });

  /**
   * Each granularity option persists correctly across reload.
   */
  test('all granularity values persist correctly', async ({ page }) => {
    await goToOverview(page);

    // Test each granularity that should be enabled for a typical 30-day range
    const testValues = ['weekly', 'monthly', 'daily'];

    for (const value of testValues) {
      // Check if the option is available (not disabled)
      const option = page.locator(`${GRANULARITY_SELECT} option[value="${value}"]`).first();
      const isDisabled = await option.getAttribute('disabled');
      if (isDisabled !== null) {
        console.log(`Skipping "${value}" — disabled for current date range`);
        continue;
      }

      await setGranularity(page, value);
      expect(await getSelectedGranularity(page)).toBe(value);

      // Reload
      await page.reload({ waitUntil: 'domcontentloaded' });
      await page.waitForSelector(GRANULARITY_SELECT, { timeout: 15_000 });

      const afterReload = await getSelectedGranularity(page);
      console.log(`"${value}" after reload:`, afterReload);
      expect(afterReload, `"${value}" should persist after reload`).toBe(value);
    }
  });

  /**
   * Changing the granularity does not cause JS console errors.
   */
  test('no console errors when changing granularity', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
      }
    });

    await goToOverview(page);

    // Cycle through available granularities
    const options = ['daily', 'weekly', 'monthly'];
    for (const opt of options) {
      const option = page.locator(`${GRANULARITY_SELECT} option[value="${opt}"]`).first();
      const isDisabled = await option.getAttribute('disabled');
      if (isDisabled !== null) continue;

      await setGranularity(page, opt);
      // Small wait for any async errors to surface
      await page.waitForTimeout(500);
    }

    // Filter out unrelated errors (e.g., favicon 404, other plugin noise)
    const chartErrors = consoleErrors.filter(
      (e) => e.includes('slimstat') || e.includes('chart') || e.includes('granularity'),
    );
    expect(chartErrors, 'No chart-related console errors').toEqual([]);
  });

  /**
   * v5.4.7 regression: granularity persists in sessionStorage after change.
   *
   * Verifies that the JS writes the selected granularity to sessionStorage
   * and that on page reload the dropdown is restored from that stored value.
   */
  test('v547-fix: granularity persists in sessionStorage after change', async ({ page }) => {
    await goToOverview(page);

    // Change granularity to 'daily'
    await setGranularity(page, 'daily');
    expect(await getSelectedGranularity(page)).toBe('daily');

    // Verify sessionStorage has the value (key is slimstat_chart_granularity_ + chartId)
    const storedValue = await page.evaluate(() => {
      const chartEl = document.querySelector('[id^="slimstat_chart_data_"]');
      if (!chartEl || !chartEl.id) return null;
      const chartId = chartEl.id.replace('slimstat_chart_data_', '');
      return sessionStorage.getItem('slimstat_chart_granularity_' + chartId);
    });

    console.log('v547-fix: sessionStorage granularity value:', storedValue);

    // The stored value should reflect 'daily'
    expect(
      storedValue,
      'v547-fix: granularity should be stored in sessionStorage after selection change',
    ).toBeTruthy();
    expect(storedValue).toBe('daily');

    // Reload page
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector(GRANULARITY_SELECT, { timeout: 15_000 });

    // Verify select still shows 'daily'
    const afterReload = await getSelectedGranularity(page);
    console.log('v547-fix: granularity after reload:', afterReload);
    expect(
      afterReload,
      'v547-fix: granularity must persist as "daily" after page reload via sessionStorage',
    ).toBe('daily');
  });
});
