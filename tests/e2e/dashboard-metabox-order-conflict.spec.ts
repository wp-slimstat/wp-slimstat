/**
 * E2E tests: Dashboard meta-box-order conflict (#15036)
 *
 * Validates that Slimstat's sortable handler does NOT hijack the native
 * WordPress Dashboard widget drag-and-drop. The bug causes the AJAX
 * meta-box-order call to send page=admin_page_slimlayout instead of
 * page=dashboard, corrupting widget layout in wp_usermeta.
 *
 * Test groups:
 *  1. Bug reproduction (FAIL before fix, PASS after)
 *  2. Slimstat Customize page (PASS before AND after)
 *  3. Dashboard widget rendering (PASS before AND after)
 *  4. Report pages unaffected (PASS before AND after)
 *  5. No global sortable interference (PASS after fix)
 */
import { test, expect, type Page, type Request } from '@playwright/test';
import {
  getPool,
  closeDb,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  setSlimstatOption,
} from './helpers/setup';

// ─── Helpers ──────────────────────────────────────────────────────

/** Seed realistic pageview data so dashboard widgets have content to render. */
async function seedStats(count: number): Promise<number[]> {
  const pool = getPool();
  // Use a smaller base to stay within INT range (visit_id is INT, not BIGINT)
  const visitIdBase = Math.floor(Date.now() / 1000);
  const ids: number[] = [];
  const resources = ['/', '/sample-page/', '/hello-world/', '/about/', '/contact/'];
  const browsers = ['Chrome 120', 'Firefox 115', 'Safari 17'];
  const platforms = ['Windows', 'macOS', 'Linux'];

  for (let i = 0; i < count; i++) {
    const visitId = visitIdBase + i;
    ids.push(visitId);
    await pool.execute(
      'INSERT INTO wp_slim_stats (ip, resource, dt, browser, platform, content_type, visit_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
      [
        `192.168.1.${(i % 254) + 1}`,
        resources[i % resources.length],
        Math.floor(Date.now() / 1000) - i * 300,
        browsers[i % browsers.length],
        platforms[i % platforms.length],
        'text/html',
        visitId,
      ]
    );
  }
  return ids;
}

/** Clean up seeded stats rows. */
async function cleanupStats(ids: number[]): Promise<void> {
  if (ids.length === 0) return;
  const placeholders = ids.map(() => '?').join(',');
  await getPool().execute(
    `DELETE FROM wp_slim_stats WHERE visit_id IN (${placeholders})`,
    ids
  );
}

/** Parse URL-encoded form body from a POST request. */
function parseFormBody(body: string): Record<string, string> {
  const params = new URLSearchParams(body);
  const result: Record<string, string> = {};
  for (const [key, value] of params.entries()) {
    result[key] = value;
  }
  return result;
}

/**
 * Intercept the next meta-box-order AJAX POST and return its parsed body.
 * Resolves when the matching request is captured or rejects on timeout.
 */
function captureMetaBoxOrderRequest(page: Page): Promise<Record<string, string>> {
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => {
      page.removeListener('request', handler);
      reject(new Error('Timeout waiting for meta-box-order AJAX request'));
    }, 15_000);

    const handler = async (request: Request) => {
      if (
        request.method() === 'POST' &&
        request.url().includes('admin-ajax.php')
      ) {
        const postData = request.postData() || '';
        if (postData.includes('action=meta-box-order')) {
          clearTimeout(timeout);
          page.removeListener('request', handler);
          resolve(parseFormBody(postData));
        }
      }
    };
    page.on('request', handler);
  });
}

/**
 * Perform a manual mouse drag that reliably triggers jQuery UI sortable.
 * Playwright's built-in dragTo doesn't always fire the required mouse events.
 */
async function dragPostbox(page: Page, sourceHandle: string, targetContainer: string): Promise<void> {
  const sourceBbox = await page.locator(sourceHandle).boundingBox();
  const targetBbox = await page.locator(targetContainer).boundingBox();
  if (!sourceBbox || !targetBbox) throw new Error('Cannot find source or target bounding box');

  const srcX = sourceBbox.x + sourceBbox.width / 2;
  const srcY = sourceBbox.y + sourceBbox.height / 2;
  // Target: drop into the middle of the container
  const tgtX = targetBbox.x + targetBbox.width / 2;
  const tgtY = targetBbox.y + 30;

  await page.mouse.move(srcX, srcY);
  await page.mouse.down();
  // Move in steps to trigger sortable's mousemove detection
  const steps = 10;
  for (let i = 1; i <= steps; i++) {
    await page.mouse.move(
      srcX + (tgtX - srcX) * (i / steps),
      srcY + (tgtY - srcY) * (i / steps),
      { steps: 1 }
    );
  }
  await page.waitForTimeout(200); // let sortable process
  await page.mouse.up();
}

/**
 * Query wp_usermeta for a specific meta_key and user_id.
 * Returns the meta_value or null if not found.
 */
async function getUserMeta(userId: number, metaKey: string): Promise<string | null> {
  const [rows] = await getPool().execute(
    'SELECT meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key = ?',
    [userId, metaKey]
  ) as any;
  return rows.length > 0 ? rows[0].meta_value : null;
}

/** Delete a wp_usermeta row. */
async function deleteUserMeta(userId: number, metaKey: string): Promise<void> {
  await getPool().execute(
    'DELETE FROM wp_usermeta WHERE user_id = ? AND meta_key = ?',
    [userId, metaKey]
  );
}

// ─── Test Groups ──────────────────────────────────────────────────

test.describe('Meta-box-order conflict (#15036)', () => {
  let seededIds: number[] = [];

  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
    seededIds = await seedStats(15);
  });

  test.afterAll(async () => {
    await cleanupStats(seededIds);
    await restoreSlimstatOptions();
    await closeDb();
  });

  // ── GROUP 1: Bug Reproduction ───────────────────────────────────

  test.describe('Group 1: Dashboard drag sends correct page parameter', () => {

    test('1.1 — Dashboard widget drag sends page=dashboard, not page=admin_page_slimlayout', async ({ page }) => {
      await page.goto('/wp-admin/', { waitUntil: 'networkidle' });

      // Verify dashboard postboxes exist
      const normalSortables = page.locator('#normal-sortables');
      await expect(normalSortables).toBeAttached();

      const sourcePostbox = normalSortables.locator('.postbox:visible').first();
      await expect(sourcePostbox).toBeVisible({ timeout: 10_000 });

      // Start listening for the meta-box-order AJAX request
      const capturePromise = captureMetaBoxOrderRequest(page);

      // Perform manual drag from first postbox handle to side column
      await dragPostbox(
        page,
        '#normal-sortables .postbox:visible:first-child .postbox-header',
        '#side-sortables'
      );

      // Wait for the AJAX call
      const body = await capturePromise;

      // ASSERT: the page parameter must be 'dashboard'
      expect(body.page, 'meta-box-order AJAX should send page=dashboard').toBe('dashboard');
      expect(body.page, 'meta-box-order AJAX should NOT contain slimlayout').not.toContain('slimlayout');
    });

    test('1.2 — Dashboard drag does not create admin_page_slimlayout usermeta', async ({ page }) => {
      await page.goto('/wp-admin/', { waitUntil: 'networkidle' });

      // Get the current user_id from WP
      const userId = await page.evaluate(() => {
        return (window as any).userSettings?.uid ? parseInt((window as any).userSettings.uid, 10) : null;
      });
      expect(userId, 'Should detect current user ID').not.toBeNull();

      // Clean up any stale usermeta from previous bug occurrences
      await deleteUserMeta(userId!, 'meta-box-order_admin_page_slimlayout');

      // Snapshot current dashboard meta before drag
      const dashboardMetaBefore = await getUserMeta(userId!, 'meta-box-order_dashboard');

      const sourcePostbox = page.locator('#normal-sortables .postbox:visible').first();
      await expect(sourcePostbox).toBeVisible({ timeout: 10_000 });

      const capturePromise = captureMetaBoxOrderRequest(page);
      await dragPostbox(
        page,
        '#normal-sortables .postbox:visible:first-child .postbox-header',
        '#side-sortables'
      );
      const body = await capturePromise;

      // Wait for DB write
      await page.waitForTimeout(500);

      // ASSERT: wrong usermeta key was NOT created
      const wrongMeta = await getUserMeta(userId!, 'meta-box-order_admin_page_slimlayout');
      expect(wrongMeta, 'meta-box-order_admin_page_slimlayout should NOT exist').toBeNull();

      // ASSERT: the AJAX sent the right page param (double-check from Group 1.1)
      expect(body.page, 'AJAX page param should be dashboard').toBe('dashboard');
    });
  });

  // ── GROUP 2: Slimstat Customize Page ────────────────────────────

  test.describe('Group 2: Slimstat Customize page functionality', () => {

    test('2.1 — Customize page drag-and-drop reorders reports correctly', async ({ page }) => {
      await page.goto('/wp-admin/admin.php?page=slimlayout', { waitUntil: 'networkidle' });

      // Verify the .slimstat-layout wrapper exists
      await expect(page.locator('.slimstat-layout')).toBeVisible();

      // Get sortable containers
      const sortableContainers = page.locator('.slimstat-layout .meta-box-sortables');
      const containerCount = await sortableContainers.count();
      expect(containerCount, 'Should have at least 2 sortable containers').toBeGreaterThanOrEqual(2);

      // Verify first container has postboxes
      const firstContainer = sortableContainers.first();
      const postboxCount = await firstContainer.locator('.postbox').count();
      expect(postboxCount, 'First container should have postboxes').toBeGreaterThan(0);

      // Get the first postbox's handle selector (Slimstat uses h3.hndle)
      const firstContainerId = await firstContainer.getAttribute('id');
      const secondContainerId = await sortableContainers.nth(1).getAttribute('id');

      // Start listening for AJAX
      const capturePromise = captureMetaBoxOrderRequest(page);

      // Drag from first container's first postbox to second container
      await dragPostbox(
        page,
        `#${firstContainerId} .postbox:first-child .hndle`,
        `#${secondContainerId}`
      );

      const body = await capturePromise;

      // ASSERT: AJAX sends correct page parameter for Slimstat layout
      expect(body.page, 'Should contain _page_slimlayout').toContain('_page_slimlayout');

      // ASSERT: order data is included
      const hasOrderKeys = Object.keys(body).some(k => k.startsWith('order['));
      expect(hasOrderKeys, 'AJAX should include order[] data').toBeTruthy();
    });

    test('2.2 — Clone button creates duplicate report on Customize page', async ({ page }) => {
      await page.goto('/wp-admin/admin.php?page=slimlayout', { waitUntil: 'networkidle' });

      const firstPostbox = page.locator('.slimstat-layout .postbox').first();
      await expect(firstPostbox).toBeVisible();

      const container = firstPostbox.locator('..');
      const initialCount = await container.locator('.postbox').count();

      // Click the clone button (slimstat-font-docs)
      const cloneButton = firstPostbox.locator('.slimstat-font-docs');
      const cloneCount = await cloneButton.count();
      if (cloneCount === 0) {
        test.skip(true, 'Clone button (.slimstat-font-docs) not present on first postbox');
      }
      expect(cloneCount, 'Clone button should exist on first postbox').toBeGreaterThan(0);

      const capturePromise = captureMetaBoxOrderRequest(page);
      await cloneButton.click();

      const newCount = await container.locator('.postbox').count();
      expect(newCount, 'Clone should add one postbox').toBe(initialCount + 1);

      const body = await capturePromise;
      expect(body.page, 'Clone AJAX sends slimlayout page').toContain('_page_slimlayout');
    });

    test('2.3 — Move-to-inactive button relocates report on Customize page', async ({ page }) => {
      await page.goto('/wp-admin/admin.php?page=slimlayout', { waitUntil: 'networkidle' });

      // Find an active container postbox with the minus button
      const activePostbox = page.locator(
        '.slimstat-layout .postbox-container:not(#postbox-container-inactive) .postbox'
      ).first();

      const activeCount = await activePostbox.count();
      if (activeCount === 0) {
        test.skip(true, 'No active postboxes found outside inactive container');
      }
      expect(activeCount, 'Should have active postboxes').toBeGreaterThan(0);

      const minusButton = activePostbox.locator('.slimstat-font-minus-circled');
      const minusCount = await minusButton.count();
      if (minusCount === 0) {
        test.skip(true, 'Minus button (.slimstat-font-minus-circled) not present on active postbox');
      }
      expect(minusCount, 'Minus button should exist on active postbox').toBeGreaterThan(0);

      const inactiveBefore = await page.locator('#postbox-container-inactive .postbox').count();

      const capturePromise = captureMetaBoxOrderRequest(page);
      await minusButton.click();

      const inactiveAfter = await page.locator('#postbox-container-inactive .postbox').count();
      expect(inactiveAfter, 'Inactive container gains one postbox').toBe(inactiveBefore + 1);

      const body = await capturePromise;
      expect(body.page, 'Move AJAX sends slimlayout page').toContain('_page_slimlayout');
    });
  });

  // ── GROUP 3: Dashboard Widget Rendering ─────────────────────────

  test.describe('Group 3: Dashboard widgets render correctly', () => {

    test('3.1 — Dashboard widgets render with data in sync mode (default)', async ({ page }) => {
      await setSlimstatOption(page, 'async_load', 'no');

      await page.goto('/wp-admin/', { waitUntil: 'networkidle' });

      // At least one Slimstat widget postbox should be visible
      const slimWidgets = page.locator('.postbox[id^=slim_p]');
      const widgetCount = await slimWidgets.count();
      expect(widgetCount, 'At least one Slimstat dashboard widget').toBeGreaterThanOrEqual(1);

      const firstWidget = slimWidgets.first();
      await expect(firstWidget).toBeVisible();

      // Widget should have an .inside container with content
      const insideContent = firstWidget.locator('.inside');
      await expect(insideContent).toBeAttached();

      const hasContent = await insideContent.evaluate((el) => {
        const text = el.textContent?.trim() || '';
        return text.length > 0;
      });
      expect(hasContent, 'Widget .inside should have text content').toBeTruthy();
    });

    test('3.2 — Dashboard widgets load via AJAX in async mode', async ({ page }) => {
      await setSlimstatOption(page, 'async_load', 'on');

      await page.goto('/wp-admin/', { waitUntil: 'networkidle' });

      const slimWidgets = page.locator('.postbox[id^=slim_p]');
      const widgetCount = await slimWidgets.count();

      if (widgetCount > 0) {
        const firstWidget = slimWidgets.first();
        await expect(firstWidget).toBeVisible();

        // In async mode, wait for content to load (up to 20s for AJAX sequencing)
        await page.waitForFunction(
          () => {
            const widget = document.querySelector('.postbox[id^=slim_p]');
            if (!widget) return true; // no widgets, nothing to wait for
            const inside = widget.querySelector('.inside');
            if (!inside) return false;
            // Content loaded when there's no spinner AND there's actual content
            const hasSpinner = inside.querySelector('.loading, .slimstat-font-spin4') !== null;
            const hasContent = (inside.textContent?.trim() || '').length > 10;
            return !hasSpinner && hasContent;
          },
          { timeout: 20_000 }
        ).catch(() => {
          // May timeout if widgets genuinely have no data, that's ok
        });

        const insideContent = firstWidget.locator('.inside');
        const contentHeight = await insideContent.evaluate(
          (el) => parseFloat(window.getComputedStyle(el).height)
        );
        expect(contentHeight, 'Async widget .inside should have height > 0').toBeGreaterThan(0);
      }

      // Restore sync mode
      await setSlimstatOption(page, 'async_load', 'no');
    });

    test('3.3 — Slimstat CSS and JS properly loaded on WP Dashboard', async ({ page }) => {
      await page.goto('/wp-admin/', { waitUntil: 'networkidle' });

      const adminJsLoaded = await page.evaluate(() => {
        return typeof (window as any).SlimStatAdminParams !== 'undefined';
      });
      expect(adminJsLoaded, 'SlimStatAdminParams should be defined on dashboard').toBeTruthy();

      const cssLoaded = await page.evaluate(() => {
        const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'));
        return links.some(link => (link as HTMLLinkElement).href.includes('wp-slimstat'));
      });
      expect(cssLoaded, 'Slimstat CSS should be loaded on dashboard').toBeTruthy();
    });
  });

  // ── GROUP 4: Report Pages Unaffected ────────────────────────────

  test.describe('Group 4: Report pages unaffected', () => {

    test('4.1 — Report page loads without JS errors and renders content', async ({ page }) => {
      const consoleErrors: string[] = [];
      page.on('console', (msg) => {
        if (msg.type() === 'error') {
          consoleErrors.push(msg.text());
        }
      });

      await page.goto('/wp-admin/admin.php?page=slimview1', { waitUntil: 'networkidle' });

      const sortableErrors = consoleErrors.filter(
        (e) => e.includes('sortable') || e.includes('TypeError') || e.includes('is not a function')
      );
      expect(sortableErrors, 'No sortable-related JS errors on report page').toHaveLength(0);

      await expect(page.locator('.meta-box-sortables')).toBeAttached();

      const hasLayoutClass = await page.locator('.slimstat-layout').count();
      expect(hasLayoutClass, 'Report page should NOT have .slimstat-layout').toBe(0);

      const reportWidgets = page.locator('[id^=slim_p]');
      const count = await reportWidgets.count();
      expect(count, 'Report page should have at least one report widget').toBeGreaterThanOrEqual(1);
    });

    test('4.2 — Multiple report pages load correctly', async ({ page }) => {
      const reportPages = ['slimview1', 'slimview2', 'slimview3', 'slimview4', 'slimview5'];

      for (const reportPage of reportPages) {
        const response = await page.goto(`/wp-admin/admin.php?page=${reportPage}`, {
          waitUntil: 'networkidle',
        });

        expect(response?.status(), `${reportPage} should load without HTTP error`).toBeLessThan(500);

        await expect(page.locator('.wrap-slimstat')).toBeAttached();
        const layoutCount = await page.locator('.slimstat-layout').count();
        expect(layoutCount, `${reportPage} should NOT have .slimstat-layout`).toBe(0);
      }
    });
  });

  // ── GROUP 5: No Global Sortable Interference ────────────────────

  test.describe('Group 5: No global sortable interference', () => {

    test('5.1 — Slimstat admin.js is NOT loaded on Posts list page', async ({ page }) => {
      await page.goto('/wp-admin/edit.php', { waitUntil: 'networkidle' });

      const slimstatLoaded = await page.evaluate(() => {
        return typeof (window as any).SlimStatAdminParams !== 'undefined';
      });
      expect(slimstatLoaded, 'SlimStatAdminParams should NOT be defined on Posts page').toBeFalsy();
    });

    test('5.2 — Dashboard sortable uses WP core handler, not Slimstat handler', async ({ page }) => {
      await page.goto('/wp-admin/', { waitUntil: 'networkidle' });

      // Verify .slimstat-layout does NOT exist on the dashboard
      const layoutCount = await page.locator('.slimstat-layout').count();
      expect(layoutCount, 'Dashboard should NOT have .slimstat-layout').toBe(0);

      // Verify .meta-box-sortables exists (WP core uses them)
      const sortablesCount = await page.locator('.meta-box-sortables').count();
      expect(sortablesCount, 'Dashboard should have .meta-box-sortables').toBeGreaterThan(0);

      // Trigger a drag and verify the AJAX uses correct page param
      const sourcePostbox = page.locator('#normal-sortables .postbox:visible').first();

      if (await sourcePostbox.count() > 0) {
        const capturePromise = captureMetaBoxOrderRequest(page);
        await dragPostbox(
          page,
          '#normal-sortables .postbox:visible:first-child .postbox-header',
          '#side-sortables'
        );
        const body = await capturePromise;

        expect(body.page, 'Dashboard drag should send page=dashboard').toBe('dashboard');
      }
    });
  });
});
