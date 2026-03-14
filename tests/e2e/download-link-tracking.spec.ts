/**
 * E2E tests: Download link tracking — DOM click delegation path.
 *
 * These tests exercise the real end-to-end flow: a real DOM click on an injected
 * download anchor bubbles up to SlimStat's setupClickDelegation() listener, which
 * fires the tracking request. The PHP handler in Ajax.php checks the file extension
 * against extensions_to_track and creates a new stat row with content_type='download'.
 *
 * Core assertions:
 *   - Clicking a link with a tracked extension creates a download row in the DB.
 *   - Non-tracked extensions are NOT recorded as downloads.
 *   - Custom extensions can be added/removed from the tracking list.
 *   - Top Downloads and Recent Downloads reports render the tracked files.
 *   - Edge cases: query params, anonymous users, multiple clicks.
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  waitForDownloadRow,
  getDownloadCount,
  waitForPageviewRow,
  waitForTrackerId,
  visitAsAnonymous,
  closeDb,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

// ─── DB helpers ──────────────────────────────────────────────────────────────

/** Check that no download row exists for a given resource marker. */
async function assertNoDownloadRow(resourceMarker: string): Promise<void> {
  const count = await getDownloadCount(resourceMarker);
  expect(count, `expected no download row for marker "${resourceMarker}"`).toBe(0);
}

// ─── Page helpers ────────────────────────────────────────────────────────────

/**
 * Inject an anchor tag pointing to a download URL. A capture-phase preventDefault
 * stops navigation but does NOT stop event bubbling — SlimStat's body-level click
 * delegation still fires trackInteraction().
 */
async function injectDownloadLink(
  page: import('@playwright/test').Page,
  href: string,
  attrs: { id: string; className?: string } = { id: 'e2e-dl-link' },
): Promise<void> {
  await page.evaluate(
    ({ href, id, className }) => {
      const a = document.createElement('a');
      a.href = href;
      a.id = id;
      if (className) a.className = className;
      a.textContent = 'Download Test File';
      document.body.appendChild(a);
      a.addEventListener('click', (e) => e.preventDefault(), { capture: true });
    },
    { href, id: attrs.id, className: attrs.className ?? '' },
  );
}

// ─── Test suite ──────────────────────────────────────────────────────────────

test.describe('Download Link Tracking — DOM click path', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOption(page, 'is_tracking', 'on');
    await setSlimstatOption(page, 'ignore_wp_users', 'off');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
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

  // ─── A1: PDF link click creates download record ─────────────────────────

  test('clicking a PDF link creates a download row with correct content_type and resource', async ({ page }) => {
    const marker = `e2e-pdf-${Date.now()}`;
    const downloadUrl = `https://example.com/files/${marker}.pdf`;

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);
    await waitForPageviewRow(marker);

    await injectDownloadLink(page, downloadUrl, { id: 'e2e-dl-pdf' });
    await page.click('#e2e-dl-pdf');

    const row = await waitForDownloadRow(marker);
    expect(row, 'download row must exist after clicking a PDF link').not.toBeNull();
    expect(row!.content_type).toBe('download');
    expect(row!.resource).toContain(`/files/${marker}.pdf`);
  });

  // ─── A2: Multiple tracked extensions ────────────────────────────────────

  test('doc, xls, and zip extensions are all tracked as downloads', async ({ page }) => {
    const extensions = ['doc', 'xls', 'zip'];

    for (const ext of extensions) {
      const marker = `e2e-${ext}-${Date.now()}`;
      const downloadUrl = `https://example.com/files/${marker}.${ext}`;

      await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
      await waitForTrackerId(page);
      await waitForPageviewRow(marker);

      await injectDownloadLink(page, downloadUrl, { id: `e2e-dl-${ext}` });
      await page.click(`#e2e-dl-${ext}`);

      const row = await waitForDownloadRow(marker);
      expect(row, `download row must exist for .${ext} link`).not.toBeNull();
      expect(row!.content_type).toBe('download');
      expect(row!.resource).toContain(`.${ext}`);
    }
  });

  // ─── A3: Non-tracked extension is NOT a download ────────────────────────

  test('clicking a non-tracked extension (.jpg) does not create a download row', async ({ page }) => {
    const marker = `e2e-notdl-${Date.now()}`;
    const nonDownloadUrl = `https://example.com/files/${marker}.jpg`;

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);
    await waitForPageviewRow(marker);

    await injectDownloadLink(page, nonDownloadUrl, { id: 'e2e-dl-jpg' });
    await page.click('#e2e-dl-jpg');

    // Wait for any tracking request to settle
    await page.waitForTimeout(2_000);
    await assertNoDownloadRow(marker);
  });

  // ─── B4: Custom extension added ────────────────────────────────────────

  test('adding mp3 to extensions_to_track makes .mp3 links tracked as downloads', async ({ page }) => {
    await setSlimstatOption(page, 'extensions_to_track', 'pdf,doc,xls,zip,mp3');

    const marker = `e2e-mp3-${Date.now()}`;
    const downloadUrl = `https://example.com/files/${marker}.mp3`;

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);
    await waitForPageviewRow(marker);

    await injectDownloadLink(page, downloadUrl, { id: 'e2e-dl-mp3' });
    await page.click('#e2e-dl-mp3');

    const row = await waitForDownloadRow(marker);
    expect(row, 'mp3 download row must exist after adding extension').not.toBeNull();
    expect(row!.content_type).toBe('download');
    expect(row!.resource).toContain('.mp3');
  });

  // ─── B5: Extension removed from list ───────────────────────────────────

  test('removing pdf from extensions_to_track stops tracking .pdf as download', async ({ page }) => {
    await setSlimstatOption(page, 'extensions_to_track', 'doc,xls,zip');

    const marker = `e2e-nopdf-${Date.now()}`;
    const downloadUrl = `https://example.com/files/${marker}.pdf`;

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);
    await waitForPageviewRow(marker);

    await injectDownloadLink(page, downloadUrl, { id: 'e2e-dl-nopdf' });
    await page.click('#e2e-dl-nopdf');

    await page.waitForTimeout(2_000);
    await assertNoDownloadRow(marker);
  });

  // ─── C6: Top Downloads report ──────────────────────────────────────────

  test('Top Downloads widget on Site Analysis page shows tracked files with hit counts', async ({ page }) => {
    const marker = `e2e-report-${Date.now()}`;

    // Create download events: file-a (2 clicks), file-b (1 click)
    for (let i = 0; i < 2; i++) {
      await page.goto(`${BASE_URL}/?e2e=${marker}-a${i}`, { waitUntil: 'domcontentloaded' });
      await waitForTrackerId(page);
      await waitForPageviewRow(`${marker}-a${i}`);
      await injectDownloadLink(page, `https://example.com/files/${marker}-file-a.pdf`, { id: `e2e-dl-a${i}` });
      await page.click(`#e2e-dl-a${i}`);
      await waitForDownloadRow(`${marker}-file-a`);
    }

    await page.goto(`${BASE_URL}/?e2e=${marker}-b0`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);
    await waitForPageviewRow(`${marker}-b0`);
    await injectDownloadLink(page, `https://example.com/files/${marker}-file-b.doc`, { id: 'e2e-dl-b0' });
    await page.click('#e2e-dl-b0');
    await waitForDownloadRow(`${marker}-file-b`);

    // Navigate to Site Analysis page
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview4`, { waitUntil: 'domcontentloaded' });

    // Find the Top Downloads widget
    const widget = page.locator('#slim_p4_09');
    await expect(widget).toBeVisible({ timeout: 10_000 });

    const widgetText = await widget.innerText();
    expect(widgetText).toContain(`${marker}-file-a.pdf`);
    expect(widgetText).toContain(`${marker}-file-b.doc`);
  });

  // ─── C7: Recent Downloads in DB and Access Log ─────────────────────────

  test('download events appear in the Access Log with content_type download', async ({ page }) => {
    const marker = `e2e-recent-${Date.now()}`;

    // Create two download events with unique file names
    const markerA = `${marker}-first`;
    const markerB = `${marker}-second`;

    await page.goto(`${BASE_URL}/?e2e=${markerA}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);
    await waitForPageviewRow(markerA);
    await injectDownloadLink(page, `https://example.com/files/${markerA}.zip`, { id: 'e2e-dl-first' });
    await page.click('#e2e-dl-first');
    const rowA = await waitForDownloadRow(markerA);
    expect(rowA, 'first download row must exist').not.toBeNull();

    await page.goto(`${BASE_URL}/?e2e=${markerB}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);
    await waitForPageviewRow(markerB);
    await injectDownloadLink(page, `https://example.com/files/${markerB}.pdf`, { id: 'e2e-dl-second' });
    await page.click('#e2e-dl-second');
    const rowB = await waitForDownloadRow(markerB);
    expect(rowB, 'second download row must exist').not.toBeNull();

    // Navigate to Access Log (slimview1) filtered by content_type=download
    await page.goto(
      `${BASE_URL}/wp-admin/admin.php?page=slimview1&fs%5Bcontent_type%5D=equals+download`,
      { waitUntil: 'domcontentloaded' },
    );

    // The page body should contain both download file paths
    const bodyText = await page.locator('#wpbody-content').innerText();
    expect(bodyText).toContain(`${markerB}.pdf`);
    expect(bodyText).toContain(`${markerA}.zip`);
  });

  // ─── D8: Download link with query parameters ──────────────────────────

  test('download link with query params includes path and query in resource', async ({ page }) => {
    const marker = `e2e-qp-${Date.now()}`;
    const downloadUrl = `https://example.com/files/${marker}.pdf?v=2&ref=home`;

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);
    await waitForPageviewRow(marker);

    await injectDownloadLink(page, downloadUrl, { id: 'e2e-dl-qp' });
    await page.click('#e2e-dl-qp');

    const row = await waitForDownloadRow(marker);
    expect(row, 'download row must exist for link with query params').not.toBeNull();
    expect(row!.resource).toContain(`/files/${marker}.pdf`);
    // Ajax.php line 313: appends query string to resource
    expect(row!.resource).toContain('v=2');
    expect(row!.resource).toContain('ref=home');
  });

  // ─── D9: Anonymous user download tracking ─────────────────────────────

  test('anonymous visitor download click is tracked', async ({ browser, page }) => {
    // Set options using the authenticated page context first
    const marker = `e2e-anon-dl-${Date.now()}`;
    const downloadUrl = `https://example.com/files/${marker}.pdf`;

    // Visit as anonymous (fresh browser context, no auth cookies)
    const anonPage = await visitAsAnonymous(browser, `${BASE_URL}/?e2e=${marker}`);

    try {
      await waitForTrackerId(anonPage);
      await waitForPageviewRow(marker);

      await injectDownloadLink(anonPage, downloadUrl, { id: 'e2e-dl-anon' });
      await anonPage.click('#e2e-dl-anon');

      const row = await waitForDownloadRow(marker);
      expect(row, 'anonymous download row must exist').not.toBeNull();
      expect(row!.content_type).toBe('download');
    } finally {
      await anonPage.context().close();
    }
  });

  // ─── D10: Multiple clicks create multiple download rows ───────────────

  test('different download files from separate pages each create their own row', async ({ page }) => {
    const marker = `e2e-multi-${Date.now()}`;

    // Click different download files from separate page loads
    const files = ['report.pdf', 'data.xls'];
    for (let i = 0; i < files.length; i++) {
      const pvMarker = `${marker}-p${i}`;
      await page.goto(`${BASE_URL}/?e2e=${pvMarker}`, { waitUntil: 'domcontentloaded' });
      await waitForTrackerId(page);
      await waitForPageviewRow(pvMarker);
      await injectDownloadLink(
        page,
        `https://example.com/files/${marker}-${files[i]}`,
        { id: `e2e-dl-multi-${i}` },
      );
      await page.click(`#e2e-dl-multi-${i}`);
      await waitForDownloadRow(`${marker}-${files[i]}`);
    }

    const count = await getDownloadCount(marker);
    expect(count, 'each distinct download should create its own row').toBe(2);
  });
});
