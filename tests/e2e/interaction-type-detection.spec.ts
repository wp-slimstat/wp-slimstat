/**
 * E2E: interaction type detection — tel:, mailto:, and form submit.
 *
 * These run against the BUILT tracker (`wp-slimstat.min.js`), which is what every
 * visitor executes, so they double as proof that the bundle is not stale. The
 * detection was added to `wp-slimstat.js` on 2026-04-12 and the bundle was last
 * built on 2026-03-31, so until the rebuild none of it had ever run in a browser.
 *
 * SlimStat records interactions in `wp_slim_events.notes` as JSON, and `notes.type`
 * is what separates a phone tap from a mail link from a form submission from an
 * ordinary click. An unclassified interaction is not a cosmetic loss — it is a
 * conversion the site owner cannot see.
 *
 * ── Why each test asserts on requests AND on rows ──
 *
 * Two different failures are possible and they need different instruments:
 *
 *   how many times the interaction is SENT   → count tracking requests in the browser
 *   what the interaction is CLASSIFIED as    → read notes.type from the database
 *
 * The send count is what catches the click/submit double-fire, and it is measured
 * client-side deliberately: it is exact, and it does not inherit the retry
 * behaviour a loaded or flaky server can introduce between the browser and the row.
 * Row assertions therefore check the *type* of every row rather than the *number*
 * of rows.
 */
import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOptions,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  injectTrackedLink,
  waitForPageviewRow,
  waitForTrackerId,
  waitForEventRows,
  captureAdminAjax,
  closeDb,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

/** A tracking request carrying an interaction note, as opposed to a pageview hit. */
const IS_INTERACTION = (body: string) => body.includes('&no=');

/** Parse the JSON note SlimStat stores; tolerate anything that is not JSON. */
function noteType(notes: string | null): string | undefined {
  if (!notes) return undefined;
  try {
    return JSON.parse(notes).type;
  } catch {
    return undefined;
  }
}

/** Land on a page, wait for the pageview to be recorded, return its stat id. */
async function startPageview(page: Page, marker: string): Promise<number> {
  await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
  await waitForTrackerId(page);
  const row = await waitForPageviewRow(marker);
  expect(row, `pageview row must exist for marker "${marker}"`).not.toBeNull();
  return row!.id;
}

/** Append a form whose submission is tracked but does not navigate. */
async function injectForm(page: Page, innerHtml: string): Promise<void> {
  await page.evaluate((html) => {
    const f = document.createElement('form');
    f.id = 'e2e-form';
    f.action = '/thanks/';
    f.innerHTML = html;
    document.body.appendChild(f);
    f.addEventListener('submit', (e) => e.preventDefault(), { capture: true });
  }, innerHtml);
}

/** Assert every recorded interaction carries the expected type. */
async function expectRecordedType(statId: number, expected: string): Promise<void> {
  const rows = await waitForEventRows(statId);
  expect(rows.length, 'the interaction must be recorded at least once').toBeGreaterThan(0);
  expect(
    rows.map((r) => noteType(r.notes)),
    `every recorded interaction must be classified as "${expected}"`,
  ).toEqual(new Array(rows.length).fill(expected));
}

test.describe('Interaction type detection (tel / mailto / submit)', () => {
  test.setTimeout(90_000);

  test.beforeAll(() => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    // Deliberately no clearStatsTable(). Every assertion here is scoped to the stat
    // id the test just created, so an empty table buys nothing — and truncating is
    // not free: on a populated install it destroys the dataset the report parity
    // oracle is measured against.
    await snapshotSlimstatOptions();
    await setSlimstatOptions(page, {
      is_tracking: 'on',
      ignore_wp_users: 'off',
      gdpr_enabled: 'off',
      // The SHIPPED defaults, pinned rather than inherited. Not arbitrary: under
      // javascript_mode=off + tracking_request_method=rest, an interaction on a
      // control inside a form is rejected by the server and then retried across
      // three transports three times over — nine requests for one click. That is a
      // real pre-existing defect (it reproduces identically on the previously
      // shipped bundle), but a spec running under it would measure the retry
      // cascade instead of the delegation it is here to pin.
      javascript_mode: 'on',
      tracking_request_method: 'ajax',
    });
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  test('a tel: link is recorded as type "tel", not "click"', async ({ page }) => {
    const statId = await startPageview(page, `e2e-tel-${Date.now()}`);

    await injectTrackedLink(page, { id: 'e2e-tel', href: 'tel:+15551234567' });
    await page.click('#e2e-tel');

    await expectRecordedType(statId, 'tel');
  });

  test('a mailto: link is recorded as type "mailto", not "click"', async ({ page }) => {
    const statId = await startPageview(page, `e2e-mailto-${Date.now()}`);

    await injectTrackedLink(page, { id: 'e2e-mailto', href: 'mailto:someone@example.com' });
    await page.click('#e2e-mailto');

    await expectRecordedType(statId, 'mailto');
  });

  test('an ordinary link is still recorded as type "click"', async ({ page }) => {
    // The counterweight. An override that fired for everything would satisfy the two
    // tests above while destroying the distinction they exist to draw.
    const statId = await startPageview(page, `e2e-plain-${Date.now()}`);

    await injectTrackedLink(page, { id: 'e2e-plain', href: `${BASE_URL}/some-page/` });
    await page.click('#e2e-plain');

    await expectRecordedType(statId, 'click');
  });

  test('submitting a form by keyboard is recorded as type "submit"', async ({ page }) => {
    // Enter-in-a-text-field submits with no click at all, so this is the path the
    // click delegation cannot see and the submit delegation exists for.
    const statId = await startPageview(page, `e2e-submit-key-${Date.now()}`);

    await injectForm(page, '<input type="text" name="q" id="e2e-q"><button type="submit">Go</button>');
    await page.fill('#e2e-q', 'hello');
    await page.press('#e2e-q', 'Enter');

    await expectRecordedType(statId, 'submit');
  });

  // ── One press, one record ──────────────────────────────────────────────────
  //
  // Clicking a submit control fires BOTH delegations: the click listener matches
  // `button`/`input`, and the resulting submission fires the submit listener. Both
  // classify it as "submit", so the duplicate is not a harmless extra row — it
  // doubles every form conversion completed the way most people complete one.
  // Measured at 2 sends before the click delegation learned to stand aside.
  //
  // The last two rows are the same defect wearing disguises that an
  // attribute-based check misses: `<button>` with no type attribute (its default
  // type IS submit) and `type="image"` (a submit control that never says
  // "submit"). Both would be filed as a click while the submission was filed as a
  // submit — one action, two rows, two different types.
  //
  // The final row is the counterweight: only submit controls may be skipped.
  // type="button" submits nothing, so no submit event will ever cover it.
  for (const [label, html, expected] of [
    ['<button type="submit">', '<button type="submit" id="e2e-go">Go</button>', 'submit'],
    ['<button> with no type attribute', '<button id="e2e-go">Go</button>', 'submit'],
    ['<input type="submit">', '<input type="submit" id="e2e-go" value="Go">', 'submit'],
    ['<input type="image">', '<input type="image" id="e2e-go" alt="Go" src="/favicon.ico">', 'submit'],
    ['<button type="button">', '<button type="button" id="e2e-go">Just a button</button>', 'click'],
  ] as const) {
    test(`clicking ${label} sends exactly one "${expected}" interaction`, async ({ page }) => {
      const statId = await startPageview(page, `e2e-once-${Date.now()}`);
      const sends = captureAdminAjax(page, IS_INTERACTION);

      await injectForm(page, html);
      await page.click('#e2e-go');
      // 2s, not less: the review suggested trimming this, but at 1s the first send
      // itself had not always landed and the test read 0 where it should read 1.
      await page.waitForTimeout(2_000);

      expect(
        sends.payloads.length,
        'one press must produce exactly one tracking request',
      ).toBe(1);

      await expectRecordedType(statId, expected);
    });
  }

  test('a submission blocked by validation records nothing', async ({ page }) => {
    // Pins a deliberate consequence of standing aside, so it is a decision rather
    // than an accident. A required field stops the submission, so no submit event
    // fires and the click is not recorded either. Nothing was submitted, so there
    // is no conversion — filing it would put a phantom in the funnel.
    const statId = await startPageview(page, `e2e-invalid-${Date.now()}`);
    const sends = captureAdminAjax(page, IS_INTERACTION);

    await injectForm(page, '<input type="text" name="q" required><button type="submit" id="e2e-go">Go</button>');
    await page.click('#e2e-go');
    await page.waitForTimeout(2_000);

    expect(
      sends.payloads.length,
      'a submission the browser refused is not a conversion and must not be tracked',
    ).toBe(0);

    const rows = await waitForEventRows(statId, 1, 2_000);
    expect(rows, 'no interaction row may be written for a blocked submission').toHaveLength(0);
  });
});
