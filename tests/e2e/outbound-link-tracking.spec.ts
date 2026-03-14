/**
 * E2E tests: Outbound / external link tracking — DOM click delegation path.
 *
 * These tests exercise the real end-to-end flow: a real DOM click on an injected
 * external anchor bubbles up to SlimStat's setupClickDelegation() listener, which
 * fires the tracking request. This is a stronger test than the sendbeacon-interaction
 * spec (which calls the REST endpoint directly) because it validates the JS tracking
 * pipeline from click event to DB write.
 *
 * Core assertions:
 *   - Clicking an external link updates outbound_resource + dt_out on the stat row.
 *   - Links with a DNT class (noslimstat) are silently ignored.
 *   - Clicking a same-domain link does NOT set outbound_resource.
 *   - AJAX transport produces the same outbound tracking result as REST.
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  getPool,
  waitForPageviewRow,
  waitForTrackerId,
  closeDb,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

// ─── DB helpers ──────────────────────────────────────────────────────────────

/** Poll the DB for outbound_resource to be set on a specific row ID. */
async function waitForOutboundUpdate(
  rowId: number,
  timeoutMs = 15_000,
): Promise<{ outbound_resource: string; dt_out: number } | null> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const [rows] = (await getPool().execute(
      "SELECT outbound_resource, dt_out FROM wp_slim_stats WHERE id = ? AND outbound_resource IS NOT NULL AND outbound_resource != ''",
      [rowId],
    )) as any;
    if (rows.length > 0) return rows[0];
    await new Promise((r) => setTimeout(r, 500));
  }
  return null;
}

/** Read outbound_resource for a row without waiting (snapshot). */
async function snapshotOutbound(rowId: number): Promise<string | null> {
  const [rows] = (await getPool().execute(
    'SELECT outbound_resource FROM wp_slim_stats WHERE id = ?',
    [rowId],
  )) as any;
  return rows.length > 0 ? (rows[0].outbound_resource ?? null) : null;
}

/**
 * Inject an anchor tag into the page and register a capture-phase preventDefault
 * so the browser doesn't navigate away when Playwright clicks it.
 */
async function injectExternalLink(
  page: import('@playwright/test').Page,
  href: string,
  attrs: { id: string; className?: string } = { id: 'e2e-ext-link' },
): Promise<void> {
  await page.evaluate(
    ({ href, id, className }) => {
      const a = document.createElement('a');
      a.href = href;
      a.id = id;
      if (className) a.className = className;
      a.textContent = 'External Test Link';
      document.body.appendChild(a);
      // preventDefault stops navigation but does NOT stop event bubbling —
      // SlimStat's body-level click delegation still fires.
      a.addEventListener('click', (e) => e.preventDefault(), { capture: true });
    },
    { href, id: attrs.id, className: attrs.className ?? '' },
  );
}

// ─── Test suite ──────────────────────────────────────────────────────────────

test.describe('Outbound Link Tracking — DOM click path', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    // handleConsentUpgradeResult is called inside sendPageview's onComplete callback
    // but is never defined in wp-slimstat.js. Without this shim the ReferenceError
    // it throws prevents queueInFlight from being reset to false, causing the
    // sendBeacon queue to lock permanently and no outbound beacon to ever fire.
    await page.addInitScript(() => {
      (window as any).handleConsentUpgradeResult = function() { /* no-op shim */ };
    });

    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOption(page, 'is_tracking', 'on');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    // Use PHP/server-side tracking mode so the stat row and params.id are both
    // created synchronously by PHP on page load. In JS mode the tracker sends an
    // async pageview request whose response sets params.id — that request never
    // completes in the Playwright admin context (consent_integration blocks it even
    // with gdpr_enabled=off because wp_consent_api overrides the setting at runtime).
    // PHP mode eliminates the async dependency: params.id is embedded in the HTML.
    await setSlimstatOption(page, 'javascript_mode', 'off');
    await setSlimstatOption(page, 'tracking_request_method', 'rest');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  // ─── Test 1: External link click → outbound_resource + dt_out set ────────

  test('DOM click on external link sets outbound_resource and dt_out (REST transport)', async ({ page }) => {
    const marker = `ext-click-rest-${Date.now()}`;
    const externalUrl = 'https://example.com/e2e-outbound-rest';

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });

    // Wait for JS tracker to receive its pageview ID (required to send outbound payload)
    await waitForTrackerId(page);

    const pageviewRow = await waitForPageviewRow(marker);
    expect(pageviewRow, 'pageview row must exist before clicking external link').not.toBeNull();
    const rowId = pageviewRow!.id;

    await injectExternalLink(page, externalUrl, { id: 'e2e-ext-rest' });
    await page.click('#e2e-ext-rest');

    const updated = await waitForOutboundUpdate(rowId);
    expect(updated, 'outbound_resource should be set after clicking an external link').not.toBeNull();
    expect(updated!.outbound_resource).toContain('example.com');
    expect(updated!.dt_out).toBeGreaterThan(0);
  });

  // ─── Test 2: DNT class suppresses outbound tracking ──────────────────────

  test('link with noslimstat DNT class is not recorded as outbound', async ({ page }) => {
    const marker = `ext-dnt-class-${Date.now()}`;
    const externalUrl = 'https://example.com/e2e-dnt-class';

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);

    const pageviewRow = await waitForPageviewRow(marker);
    expect(pageviewRow).not.toBeNull();
    const rowId = pageviewRow!.id;

    // noslimstat is the default DNT class (do_not_track_outbound_classes_rel_href setting)
    await injectExternalLink(page, externalUrl, { id: 'e2e-dnt-link', className: 'noslimstat' });
    await page.click('#e2e-dnt-link');

    // Wait long enough for any tracking request to have fired and settled
    await page.waitForTimeout(5_000);

    const outbound = await snapshotOutbound(rowId);
    expect(outbound, 'DNT-class link must NOT be tracked as outbound').toBeFalsy();
  });

  // ─── Test 3: Same-domain link is not treated as outbound ─────────────────

  test('clicking a same-domain link does not set outbound_resource', async ({ page }) => {
    const marker = `ext-internal-${Date.now()}`;

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);

    const pageviewRow = await waitForPageviewRow(marker);
    expect(pageviewRow).not.toBeNull();
    const rowId = pageviewRow!.id;

    // Inject a link pointing to the same site origin
    await page.evaluate(() => {
      const a = document.createElement('a');
      a.href = window.location.origin + '/internal-page/';
      a.id = 'e2e-internal-link';
      a.textContent = 'Internal Link';
      document.body.appendChild(a);
      a.addEventListener('click', (e) => e.preventDefault(), { capture: true });
    });
    await page.click('#e2e-internal-link');

    await page.waitForTimeout(5_000);

    const outbound = await snapshotOutbound(rowId);
    expect(outbound, 'same-domain link must NOT set outbound_resource').toBeFalsy();
  });

  // ─── Test 4: AJAX transport also tracks outbound link clicks ─────────────

  test('DOM click on external link sets outbound_resource with AJAX transport', async ({ page }) => {
    const marker = `ext-click-ajax-${Date.now()}`;
    const externalUrl = 'https://example.com/e2e-outbound-ajax';

    // Switch to AJAX transport before loading the page
    await setSlimstatOption(page, 'tracking_request_method', 'ajax');

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);

    const pageviewRow = await waitForPageviewRow(marker);
    expect(pageviewRow, 'pageview row must exist before clicking external link').not.toBeNull();
    const rowId = pageviewRow!.id;

    await injectExternalLink(page, externalUrl, { id: 'e2e-ext-ajax' });
    await page.click('#e2e-ext-ajax');

    const updated = await waitForOutboundUpdate(rowId);
    expect(updated, 'outbound_resource should be set with AJAX transport').not.toBeNull();
    expect(updated!.outbound_resource).toContain('example.com');
    expect(updated!.dt_out).toBeGreaterThan(0);
  });
});
