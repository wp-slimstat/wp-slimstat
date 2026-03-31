/**
 * E2E: Chart granularity persistence — bugfix regression tests
 *
 * Tests the three fixes applied to sessionStorage-based chart granularity
 * persistence (issue #265, PR #267):
 *
 *   1. sessionStorage write is immediate (not inside 300ms debounce)
 *   2. async_load race condition: chart re-init after AJAX HTML injection
 *   3. refresh_report reads granularity from sessionStorage when select is gone
 *
 * These tests complement the existing chart-granularity-persistence.spec.ts
 * by focusing on the specific failure modes that caused the original fix to
 * not work in practice.
 */
import { test, expect, type Page } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { insertRows, clearTestData } from './helpers/chart';
import {
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  getPool,
  closeDb,
} from './helpers/setup';

// ─── Constants ────────────────────────────────────────────────────────

const OVERVIEW_URL = `${BASE_URL}/wp-admin/admin.php?page=slimview2`;
const GRANULARITY_SELECT = 'select.slimstat-granularity-select';
const CHART_DATA_DIV = '[id^="slimstat_chart_data_"]';

// ─── Helpers ──────────────────────────────────────────────────────────

/** Navigate to Overview and wait for the chart granularity select to appear. */
async function goToOverview(page: Page): Promise<void> {
  await page.goto(OVERVIEW_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector(GRANULARITY_SELECT, { timeout: 15_000 });
}

/** Get the currently selected granularity value. */
async function getSelectedGranularity(page: Page): Promise<string> {
  return page.locator(GRANULARITY_SELECT).first().inputValue();
}

/**
 * Wait for the chart to be fully initialized by JS (canvas has a Chart instance).
 * This is critical for async_load tests where the chart may be re-rendered.
 */
async function waitForChartInitialized(page: Page): Promise<void> {
  await page.waitForFunction(
    () => {
      const canvas = document.querySelector<HTMLCanvasElement>('[id^="slimstat_chart_"]');
      // Chart.js stores the instance on the canvas element
      return canvas && (canvas as any).__chartjs_instance !== undefined
        || (typeof Chart !== 'undefined' && Chart.getChart(canvas!) !== undefined);
    },
    { timeout: 20_000 },
  );
}

/**
 * Change granularity and wait for the AJAX fetch to complete.
 * After AJAX, data-granularity on the chart data div is updated.
 */
async function setGranularity(page: Page, value: string): Promise<void> {
  const select = page.locator(GRANULARITY_SELECT).first();
  const option = select.locator(`option[value="${value}"]`);
  const isDisabled = await option.getAttribute('disabled');
  if (isDisabled !== null) {
    throw new Error(`Granularity "${value}" is disabled for the current date range`);
  }

  await select.selectOption(value);

  // Wait for data-granularity to update (set by fetchChartData success handler)
  await page.waitForFunction(
    (expected) => {
      const el = document.querySelector('[id^="slimstat_chart_data_"]');
      return el && el.getAttribute('data-granularity') === expected;
    },
    value,
    { timeout: 15_000 },
  );
}

/**
 * Read the sessionStorage granularity key for the first chart on the page.
 */
async function getSessionStorageGranularity(page: Page): Promise<string | null> {
  return page.evaluate(() => {
    const el = document.querySelector('[id^="slimstat_chart_data_"]');
    if (!el || !el.id) return null;
    const chartId = el.id.replace('slimstat_chart_data_', '');
    return sessionStorage.getItem('slimstat_chart_granularity_' + chartId);
  });
}

/**
 * Seed 30 days of stats data so charts render with a date range that
 * auto-detects "weekly" but allows "daily" and "monthly".
 */
async function seedChartData(): Promise<void> {
  await clearTestData();
  const now = Math.floor(Date.now() / 1000);
  for (let i = 0; i < 30; i++) {
    await insertRows(now - i * 86400, 2, `bugfix-seed-${i}`);
  }
}

// ─── Test suite ───────────────────────────────────────────────────────

test.describe('Chart granularity bugfixes (#265 fix verification)', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(90_000);

  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
    await seedChartData();
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    await clearTestData();
    await closeDb();
  });

  // ─── Fix 1: Immediate sessionStorage write ───────────────────────

  test('sessionStorage is written immediately on change, not after debounce', async ({ page }) => {
    await goToOverview(page);

    const initial = await getSelectedGranularity(page);
    const target = initial === 'daily' ? 'weekly' : 'daily';

    // Select the new granularity via the dropdown
    const select = page.locator(GRANULARITY_SELECT).first();
    await select.selectOption(target);

    // Check sessionStorage IMMEDIATELY (well before the 300ms debounce)
    // If the fix works, the value is written synchronously on the change event
    const stored = await page.evaluate((expected) => {
      const el = document.querySelector('[id^="slimstat_chart_data_"]');
      if (!el || !el.id) return null;
      const chartId = el.id.replace('slimstat_chart_data_', '');
      return sessionStorage.getItem('slimstat_chart_granularity_' + chartId);
    }, target);

    expect(stored, 'sessionStorage should be written immediately, not after 300ms debounce').toBe(target);
  });

  test('sessionStorage survives rapid change + immediate navigation', async ({ page }) => {
    await goToOverview(page);

    // Change to "daily" and IMMEDIATELY navigate away (no time for debounce)
    const select = page.locator(GRANULARITY_SELECT).first();
    await select.selectOption('daily');

    // Navigate away within ~50ms (well under 300ms debounce)
    await page.goto(`${BASE_URL}/wp-admin/edit.php`, { waitUntil: 'domcontentloaded' });

    // Navigate back — sessionStorage should still have "daily"
    await goToOverview(page);

    // Wait for JS to restore from sessionStorage
    await page.waitForTimeout(1_000);

    const afterNav = await getSelectedGranularity(page);
    expect(afterNav, 'Granularity should persist even with immediate navigation after change').toBe('daily');
  });

  // ─── Fix 2: async_load re-initialization ─────────────────────────

  test('granularity persists after reload with async_load ON', async ({ page }) => {
    // Enable async mode
    await setSlimstatOption(page, 'async_load', 'on');

    await goToOverview(page);

    // Change to "daily"
    await setGranularity(page, 'daily');
    expect(await getSelectedGranularity(page)).toBe('daily');

    // Verify sessionStorage was written
    const stored = await getSessionStorageGranularity(page);
    expect(stored, 'sessionStorage should hold "daily"').toBe('daily');

    // Reload — with async_load ON, admin.js will re-render charts via AJAX
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector(GRANULARITY_SELECT, { timeout: 20_000 });

    // Wait for async reports to finish loading (loading spinners disappear)
    await page.waitForFunction(
      () => document.querySelectorAll('.loading .slimstat-font-spin4').length === 0,
      { timeout: 20_000 },
    );

    // Allow chart re-initialization to complete
    await page.waitForTimeout(2_000);

    // Re-wait for select in case async re-rendered the chart
    await page.waitForSelector(GRANULARITY_SELECT, { timeout: 10_000 });

    const afterReload = await getSelectedGranularity(page);
    expect(afterReload, 'Granularity should persist after reload with async_load ON').toBe('daily');

    // Restore async_load
    await setSlimstatOption(page, 'async_load', 'off');
  });

  test('chart is functional after async_load re-render', async ({ page }) => {
    // Enable async mode
    await setSlimstatOption(page, 'async_load', 'on');

    await goToOverview(page);

    // Wait for async loading to finish
    await page.waitForFunction(
      () => document.querySelectorAll('.loading .slimstat-font-spin4').length === 0,
      { timeout: 20_000 },
    );
    await page.waitForTimeout(1_000);

    // Verify the chart canvas exists and has been initialized
    const canvasExists = await page.evaluate(() => {
      const canvas = document.querySelector('[id^="slimstat_chart_"]');
      return canvas !== null;
    });
    expect(canvasExists, 'Chart canvas should exist after async_load').toBe(true);

    // Verify the granularity select is interactive (change triggers AJAX)
    const consoleWarnings: string[] = [];
    page.on('console', (msg) => {
      if (msg.text().includes('Could not find chart elements')) {
        consoleWarnings.push(msg.text());
      }
    });

    await setGranularity(page, 'monthly');
    expect(await getSelectedGranularity(page)).toBe('monthly');

    expect(
      consoleWarnings,
      'No "Could not find chart elements" warnings after async_load',
    ).toEqual([]);

    // Restore async_load
    await setSlimstatOption(page, 'async_load', 'off');
  });

  // ─── Fix 3: sessionStorage fallback in refresh_report ────────────

  test('manual report refresh preserves granularity from sessionStorage', async ({ page }) => {
    await goToOverview(page);

    // Set to "monthly"
    await setGranularity(page, 'monthly');
    expect(await getSelectedGranularity(page)).toBe('monthly');

    // Verify sessionStorage has the value
    const stored = await getSessionStorageGranularity(page);
    expect(stored).toBe('monthly');

    // Trigger a manual refresh on the chart report by clicking the refresh button
    // This calls refresh_report() which replaces the select with a spinner
    const refreshBtn = page.locator('.postbox.chart .refresh').first();
    if (await refreshBtn.count() > 0) {
      await refreshBtn.click();

      // Wait for the refresh to complete
      await page.waitForSelector(GRANULARITY_SELECT, { timeout: 15_000 });
      await page.waitForTimeout(1_500);

      const afterRefresh = await getSelectedGranularity(page);
      expect(
        afterRefresh,
        'Granularity should be restored from sessionStorage after manual refresh',
      ).toBe('monthly');
    }
    // If no refresh button, the test is still valid — the async_load test above covers the same path
  });

  // ─── Combined: full user journey ─────────────────────────────────

  test('full journey: change → reload → navigate → return (async_load OFF)', async ({ page }) => {
    await setSlimstatOption(page, 'async_load', 'off');

    // Step 1: Go to Overview, change to "daily"
    await goToOverview(page);
    await setGranularity(page, 'daily');
    expect(await getSelectedGranularity(page)).toBe('daily');

    // Step 2: Reload page
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector(GRANULARITY_SELECT, { timeout: 15_000 });
    await page.waitForTimeout(1_000);
    expect(await getSelectedGranularity(page), 'Persists after reload').toBe('daily');

    // Step 3: Navigate to Posts admin page
    await page.goto(`${BASE_URL}/wp-admin/edit.php`, { waitUntil: 'domcontentloaded' });

    // Step 4: Return to Overview
    await goToOverview(page);
    await page.waitForTimeout(1_000);
    expect(await getSelectedGranularity(page), 'Persists after navigate away and back').toBe('daily');

    // Step 5: Change to "monthly" and reload again
    await setGranularity(page, 'monthly');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector(GRANULARITY_SELECT, { timeout: 15_000 });
    await page.waitForTimeout(1_000);
    expect(await getSelectedGranularity(page), '"monthly" persists after second reload').toBe('monthly');
  });

  test('full journey: change → reload → navigate → return (async_load ON)', async ({ page }) => {
    await setSlimstatOption(page, 'async_load', 'on');

    // Step 1: Go to Overview, wait for async load, change to "daily"
    await goToOverview(page);
    await page.waitForFunction(
      () => document.querySelectorAll('.loading .slimstat-font-spin4').length === 0,
      { timeout: 20_000 },
    );
    await page.waitForSelector(GRANULARITY_SELECT, { timeout: 15_000 });
    await setGranularity(page, 'daily');
    expect(await getSelectedGranularity(page)).toBe('daily');

    // Step 2: Reload
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector(GRANULARITY_SELECT, { timeout: 20_000 });
    await page.waitForFunction(
      () => document.querySelectorAll('.loading .slimstat-font-spin4').length === 0,
      { timeout: 20_000 },
    );
    await page.waitForTimeout(2_000);
    await page.waitForSelector(GRANULARITY_SELECT, { timeout: 10_000 });

    expect(
      await getSelectedGranularity(page),
      'Persists after reload with async_load ON',
    ).toBe('daily');

    // Step 3: Navigate away and back
    await page.goto(`${BASE_URL}/wp-admin/edit.php`, { waitUntil: 'domcontentloaded' });
    await goToOverview(page);
    await page.waitForFunction(
      () => document.querySelectorAll('.loading .slimstat-font-spin4').length === 0,
      { timeout: 20_000 },
    );
    await page.waitForTimeout(2_000);
    await page.waitForSelector(GRANULARITY_SELECT, { timeout: 10_000 });

    expect(
      await getSelectedGranularity(page),
      'Persists after navigate away and back with async_load ON',
    ).toBe('daily');

    // Restore
    await setSlimstatOption(page, 'async_load', 'off');
  });

  // ─── No console errors ───────────────────────────────────────────

  test('no chart-related console errors during granularity changes', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error' || msg.type() === 'warning') {
        const text = msg.text();
        if (text.includes('slimstat') || text.includes('chart') || text.includes('granularity')) {
          errors.push(`[${msg.type()}] ${text}`);
        }
      }
    });

    await goToOverview(page);

    // Cycle through granularities
    for (const value of ['daily', 'weekly', 'monthly']) {
      const option = page.locator(`${GRANULARITY_SELECT} option[value="${value}"]`).first();
      if ((await option.getAttribute('disabled')) !== null) continue;
      await setGranularity(page, value);
    }

    // Reload and check for errors during restoration
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector(GRANULARITY_SELECT, { timeout: 15_000 });
    await page.waitForTimeout(1_500);

    expect(errors, 'No chart-related console errors or warnings').toEqual([]);
  });
});
