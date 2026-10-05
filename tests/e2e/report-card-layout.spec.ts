/**
 * QA D1: the Overview report cards.
 *
 * - A short card (an empty "Logged-in users online") sat centred in its row, not top-aligned and full height.
 * - A title that wrapped left its info icon alone on a line of its own.
 *
 * Read-only: the layout holds whatever the database has, so it runs on an empty install too.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

test('report cards fill their row and keep the info icon with the title', async ({ page }) => {
  // The audit's width: "Logged-in users online (last 5 min)" fills its line and the icon used to wrap alone.
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
  await expect(page.locator('#slim_p1_18')).toBeVisible({ timeout: 20_000 });

  const cards = await page.locator('.meta-box-sortables > .postbox').evaluateAll((boxes) => boxes.map((box) => {
    const r = box.getBoundingClientRect();
    const h3 = box.querySelector('h3');
    const icon = h3 && h3.querySelector('.header-tooltip');
    let iconBelowText = false;
    if (h3 && icon) {
      // The last character of the title, wherever it ends up.
      const walker = document.createTreeWalker(h3, NodeFilter.SHOW_TEXT, { acceptNode: (n) => (icon.contains(n) || !n.textContent!.trim() ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT) });
      let last: Text | null = null;
      for (let n = walker.nextNode(); n; n = walker.nextNode()) last = n as Text;
      if (last) {
        const end = last.textContent!.trimEnd().length;
        const range = document.createRange();
        range.setStart(last, end - 1);
        range.setEnd(last, end);
        const at = icon.getBoundingClientRect();
        iconBelowText = at.top + at.height / 2 > range.getBoundingClientRect().bottom;
      }
    }
    return { id: box.id, top: Math.round(r.top), height: Math.round(r.height), iconBelowText };
  }));

  for (const card of cards) {
    expect(card.iconBelowText, `${card.id}: the info icon wrapped onto a line of its own`).toBe(false);
    for (const other of cards.filter((c) => c.top === card.top)) {
      expect(card.height, `${card.id} is as tall as ${other.id} in its row`).toBe(other.height);
    }
  }
  // The short card shares its row's top edge instead of floating in the middle.
  const short = cards.find((c) => 'slim_p1_18' === c.id)!;
  expect(cards.filter((c) => c.top === short.top).length, 'slim_p1_18 lines up with the cards beside it').toBeGreaterThan(1);
});
