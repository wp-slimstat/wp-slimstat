import { test, expect } from "@playwright/test";
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
    if ((rows as any[]).length > 0) return (rows as any[])[0];
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

  test("same browser produces same fingerprint across pages", async ({ page }) => {
    const marker1 = `fp-stable-1-${Date.now()}`;
    const marker2 = `fp-stable-2-${Date.now()}`;

    await page.goto(`${BASE_URL}/?e2e=${marker1}`);
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(5000);

    await page.goto(`${BASE_URL}/sample-page/?e2e=${marker2}`);
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(5000);

    const row1 = await waitForStatRow(marker1);
    const row2 = await waitForStatRow(marker2);

    expect(row1?.fingerprint).toBeTruthy();
    expect(row2?.fingerprint).toBeTruthy();
    expect(row1.fingerprint).toBe(row2.fingerprint);
  });

  test("anonymous mode skips fingerprint when GDPR is off", async ({ page }) => {
    // With GDPR off + anonymous_tracking on, JS returns mode "anonymous" → skips fingerprinting
    await setSlimstatOption(page, "gdpr_enabled", "off");
    await setSlimstatOption(page, "anonymous_tracking", "on");

    const marker = `fp-anon-${Date.now()}`;
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
        const match = postData.match(/fh=([^&]*)/);
        if (match) capturedFh = match[1];
      }
    });

    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(5000);

    expect(capturedFh ?? "", "fh= should be empty in anonymous mode").toBe("");

    // Restore is handled by afterAll via restoreSlimstatOptions
  });
});
