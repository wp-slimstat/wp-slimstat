import { test, expect } from "@playwright/test";
import { runWordPressFixture } from "./helpers/chart";
import {
  clearStatsTable,
  getPool,
  closeDb,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
} from "./helpers/setup";

const BASE_URL = process.env.TEST_BASE_URL || "http://localhost:10003";

async function waitForStatRow(marker: string, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const [rows] = await getPool().execute(
      "SELECT * FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1",
      [`%${marker}%`]
    );
    // Server tracking inserts first; the browser enriches the row asynchronously.
    if ((rows as any[])[0]?.fingerprint) return (rows as any[])[0];
    await new Promise((r) => setTimeout(r, 500));
  }
  return null;
}

test.describe.serial("FingerprintJS v4 integration", () => {
  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
  });

  test.beforeEach(async ({ page }) => {
    await clearStatsTable();
    // Ensure GDPR off + standard tracking (matches user-reported config)
    await setSlimstatOption(page, "gdpr_enabled", "off");
    await setSlimstatOption(page, "anonymous_tracking", "off");
    await setSlimstatOption(page, "anonymize_ip", "off");
    await setSlimstatOption(page, "javascript_mode", "on");
    await setSlimstatOption(page, "is_tracking", "on");
    await setSlimstatOption(page, "ignore_wp_users", "off");
    await setSlimstatOption(page, "ignore_capabilities", "");
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    await closeDb();
  });

  test("tracking payload sends non-empty fh= parameter", async ({ page }) => {
    const marker = `fp-test-${Date.now()}`;
    let capturedFh: string | null = null;

    page.on("request", (req) => {
      const url = req.url();
      const postData = req.postData() || "";
      if (
        (url.includes("/wp-json/slimstat/v1/hit") ||
          url.includes("admin-ajax.php") ||
          postData.includes("action=slimtrack")) &&
        postData.includes("fh=")
      ) {
        const match = postData.match(/fh=([^&]+)/);
        if (match) capturedFh = decodeURIComponent(match[1]);
      }
    });

    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(5000);

    expect(capturedFh, "fh= parameter must be non-empty").toBeTruthy();
    expect(capturedFh!.length, "visitorId should be ~32 chars").toBeGreaterThanOrEqual(20);
    expect(capturedFh, "visitorId should be alphanumeric").toMatch(/^[a-zA-Z0-9]+$/);
  });

  test("fingerprint is stored in database", async ({ page }) => {
    const marker = `fp-db-${Date.now()}`;

    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(5000);

    const row = await waitForStatRow(marker);
    expect(row, "stat row must exist").toBeTruthy();
    expect(row.fingerprint, "fingerprint column must not be null").toBeTruthy();
    expect(row.fingerprint.length, "fingerprint should be ~32 chars").toBeGreaterThanOrEqual(20);
  });

  for (const mode of ["on", "off"]) {
    test(`same browser produces same fingerprint across pages (javascript_mode=${mode})`, async ({ page }) => {
      await setSlimstatOption(page, "javascript_mode", mode);
      const marker1 = `fp-stable-1-${Date.now()}`;
      const marker2 = `fp-stable-2-${Date.now()}`;

      const [pages] = await getPool().execute("SELECT ID FROM wp_posts WHERE post_type = 'page' AND post_status = 'publish' ORDER BY ID LIMIT 1") as any;
      expect(pages.length, 'a published page is required').toBeGreaterThan(0);
      await page.goto(`${BASE_URL}/?e2e=${marker1}`);
      const row1 = await waitForStatRow(marker1);
      // Use WordPress's canonical URL: a query-to-pretty redirect is a separate
      // server-tracked request and would obscure duplicate-pageview assertions.
      const pageUrl = new URL(runWordPressFixture(`<?php echo get_permalink(${Number(pages[0].ID)});`).trim());
      pageUrl.searchParams.set('e2e', marker2);
      const response = await page.goto(pageUrl.href);
      expect(response?.ok(), 'the second page must render successfully').toBe(true);
      const row2 = await waitForStatRow(marker2);

      expect(row1?.fingerprint).toBeTruthy();
      expect(row2?.fingerprint).toBeTruthy();
      expect(row1.fingerprint).toBe(row2.fingerprint);
      const [counts] = await getPool().execute("SELECT resource, COUNT(*) AS n FROM wp_slim_stats WHERE resource LIKE ? OR resource LIKE ? GROUP BY resource", [`%${marker1}%`, `%${marker2}%`]) as any;
      expect(counts).toHaveLength(2);
      expect(counts.map((row: any) => Number(row.n))).toEqual([1, 1]);
    });
  }

  test("anonymous mode skips fingerprint when GDPR is off", async ({ page }) => {
    // With GDPR off + anonymous_tracking on, JS returns mode "anonymous" → skips fingerprinting
    await setSlimstatOption(page, "gdpr_enabled", "off");
    await setSlimstatOption(page, "anonymous_tracking", "on");

    const marker = `fp-anon-${Date.now()}`;
    let sawSlimtrackRequest = false;
    let capturedFh: string | null = null;

    page.on("request", (req) => {
      const url = req.url();
      const postData = req.postData() || "";
      const isSlimtrack =
        url.includes("/wp-json/slimstat/v1/hit") ||
        url.includes("admin-ajax.php") ||
        postData.includes("action=slimtrack");
      if (isSlimtrack && postData.includes("fh=")) {
        sawSlimtrackRequest = true;
        const match = postData.match(/fh=([^&]*)/);
        capturedFh = match ? match[1] : "";
      }
    });

    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(5000);

    expect(sawSlimtrackRequest, "a slimtrack request with fh= must be observed").toBe(true);
    expect(capturedFh, "fh= should be empty in anonymous mode").toBe("");

    // Restore is handled by afterAll via restoreSlimstatOptions
  });
});
