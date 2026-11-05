import { test, expect, Request } from '@playwright/test';

test('Dashboard loads nearby reports in three slots and preserves report HTML', async ({ page }) => {
  test.setTimeout(120_000);
  const inFlight = new Set<Request>();
  const requests = new Map<string, string>();
  let maximum = 0;
  page.on('request', request => {
    const body = request.postData() || '';
    if (!body.includes('action=slimstat_load_report')) return;
    inFlight.add(request);
    maximum = Math.max(maximum, inFlight.size);
    requests.set(new URLSearchParams(body).get('report_id')!, body);
  });
  page.on('requestfinished', request => inFlight.delete(request));
  page.on('requestfailed', request => inFlight.delete(request));
  // Keep the first requests open long enough to distinguish three slots from the old serial chain.
  await page.route('**/admin-ajax.php', async route => {
    if ((route.request().postData() || '').includes('action=slimstat_load_report')) {
      await new Promise(resolve => setTimeout(resolve, 150));
    }
    await route.continue();
  });
  await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
  const widgets = await page.evaluate(() => Array.from(document.querySelectorAll<HTMLDivElement>('div[id^="slim_p"]'))
    .filter(el => !el.querySelector('.chart-placeholder, .refresh-timer') && el.getClientRects().length > 0)
    .map(el => el.id));
  expect(widgets.length, 'fixture must expose enough widgets to exercise the queue').toBeGreaterThan(3);
  for (const id of widgets) {
    await page.locator('#' + id).scrollIntoViewIfNeeded();
    await expect.poll(() => requests.has(id), { timeout: 30_000 }).toBe(true);
    await expect.poll(() => page.evaluate(id => {
      const body = (window as any).SlimStatReportBody(id);
      return body.html().trim().length > 0 && body.find('.loading').length === 0;
    }, id), { timeout: 30_000 }).toBe(true);
  }
  await expect.poll(() => inFlight.size).toBe(0);
  expect(maximum, 'nearby reports must overlap instead of loading serially').toBeGreaterThan(1);
  expect(maximum, 'initial report requests must stay within three slots').toBeLessThanOrEqual(3);

  // Static list widgets avoid live counters and charts that deliberately mutate their DOM.
  const samples = widgets.filter(id => /^slim_p[2345]_/.test(id) && requests.has(id)).slice(0, 3);
  expect(samples.length, 'three real list reports must be compared').toBe(3);
  for (const id of samples) {
    const direct = await page.request.post('/wp-admin/admin-ajax.php', {
      headers: { 'content-type': 'application/x-www-form-urlencoded' },
      data: requests.get(id)!,
    });
    expect(direct.ok()).toBe(true);
    const comparison = await page.evaluate(({ id, html }) => {
      const $ = (window as any).jQuery;
      const expected = $('<div>').html(html);
      expected.find('.pagination').remove();
      const actual = $('<div>').html((window as any).SlimStatReportBody(id).html());
      return { expected: expected.html(), actual: actual.html() };
    }, { id, html: await direct.text() });
    expect(comparison.actual, id).toBe(comparison.expected);
  }
});
