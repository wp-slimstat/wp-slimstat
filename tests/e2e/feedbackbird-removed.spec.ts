/**
 * E2E regression: FeedbackBird widget is gone
 *
 * SlimStat used to load a third-party "Feedback" widget from a public CDN
 * (cdn.jsdelivr.net/gh/feedbackbird/...) on every report page, shipping the
 * admin's email, PHP version, and active-plugin list off-site with no opt-out.
 *
 * That loader has been removed. This test fails if it ever comes back:
 *  1. No network request to a feedbackbird URL fires while a report page loads.
 *  2. The inline `window.feedbackBirdObject` global is not defined.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

/** A SlimStat report page — the screen `initFeedback()` used to target (id contains "slimview"). */
const REPORT_URL = `${BASE_URL}/wp-admin/admin.php?page=slimview1`;

test.describe('FeedbackBird widget removed', () => {
  test.setTimeout(60_000);

  test('no feedbackbird request fires and no feedbackBirdObject global on a report page', async ({
    page,
  }) => {
    let feedbackbirdRequested = false;
    page.on('request', (req) => {
      if (req.url().toLowerCase().includes('feedbackbird')) {
        feedbackbirdRequested = true;
      }
    });

    await page.goto(REPORT_URL, { waitUntil: 'networkidle' });

    // Sanity: the report page actually rendered (not a redirect/fatal).
    await expect(page.locator('#wpbody-content')).toBeVisible();

    // 1. The CDN script must never load.
    expect(feedbackbirdRequested).toBe(false);

    // 2. The inline-injected global must be absent.
    const feedbackBirdObject = await page.evaluate(
      () => (window as unknown as { feedbackBirdObject?: unknown }).feedbackBirdObject,
    );
    expect(feedbackBirdObject).toBeUndefined();
  });
});
