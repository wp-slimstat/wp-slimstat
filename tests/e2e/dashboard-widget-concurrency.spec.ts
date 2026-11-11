import { test, expect, Request } from '@playwright/test';

// Free list reports load in one slimstat_load_reports request (report_ids[]); the rest load one by one (report_id).
function reportIds(body: string): string[] {
  const params = new URLSearchParams(body);
  if (params.get('action') === 'slimstat_load_reports') return params.getAll('report_ids[]');
  if (params.get('action') === 'slimstat_load_report') return [params.get('report_id')!];
  return [];
}

// The single-route request a batched report would have sent on its own.
function singleBody(body: string, id: string): string {
  const params = new URLSearchParams(body);
  if (params.get('action') === 'slimstat_load_report') return body;
  const single = new URLSearchParams();
  for (const [key, value] of params) {
    if (key !== 'action' && key !== 'report_ids[]' && !key.startsWith('granularities[')) single.append(key, value);
  }
  single.set('action', 'slimstat_load_report');
  single.set('report_id', id);
  single.set('granularity', params.get(`granularities[${id}]`) || '');
  return single.toString();
}

test('Dashboard loads nearby reports in three slots and preserves report HTML', async ({ page }) => {
  test.setTimeout(120_000);
  const inFlight = new Set<Request>();
  const requests = new Map<string, string>();
  const batches: string[][] = [];
  let maximum = 0;
  page.on('request', request => {
    const body = request.postData() || '';
    const ids = reportIds(body);
    if (!ids.length) return;
    inFlight.add(request);
    maximum = Math.max(maximum, inFlight.size);
    if (new URLSearchParams(body).get('action') === 'slimstat_load_reports') batches.push(ids);
    for (const id of ids) requests.set(id, singleBody(body, id));
  });
  page.on('requestfinished', request => inFlight.delete(request));
  page.on('requestfailed', request => inFlight.delete(request));
  // Keep the first requests open long enough to tell bounded slots from an unbounded burst.
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
  expect(batches.some(ids => ids.length > 1), 'nearby Dashboard widgets must share a request (#408)').toBe(true);
  expect(maximum, 'initial report requests must stay within three slots').toBeLessThanOrEqual(3);

  // The batch keeps page=dashboard, so Goals and Funnels stay compact instead of rendering the admin card.
  for (const id of ['slim_p9_01', 'slim_p9_02'].filter(id => widgets.includes(id))) {
    await expect(page.locator(`#${id} .slimstat-gf-card`)).toHaveCount(0);
  }

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

test('Dashboard widgets show their data without a fade (#408)', async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 1200 });
  // Hold report responses so the probe below is watching before any data lands.
  await page.route('**/admin-ajax.php', async route => {
    if ((route.request().postData() || '').includes('action=slimstat_load_report')) {
      await new Promise(resolve => setTimeout(resolve, 300));
    }
    await route.continue();
  });
  // Per report: when its response completed, then the first frame its body shows content at full opacity.
  await page.addInitScript(() => {
    const w = window as any;
    const seen: Record<string, { data?: number; shown?: number }> = (w.__swap = {});
    const tick = () => {
      if (w.jQuery && !w.__swapHooked) {
        w.__swapHooked = true;
        w.jQuery(document).ajaxComplete((_e: unknown, _xhr: unknown, settings: { data?: unknown }) => {
          for (const m of String(settings.data || '').matchAll(/report_ids?(?:%5B%5D|\[\])?=([^&]+)/g)) {
            seen[m[1]] = seen[m[1]] || {};
            if (seen[m[1]].data == null) seen[m[1]].data = performance.now();
          }
        });
      }
      for (const [id, entry] of Object.entries(seen)) {
        if (entry.data == null || entry.shown != null) continue;
        const body = w.SlimStatReportBody && w.SlimStatReportBody(id)[0];
        if (body && !body.querySelector('.loading') && body.offsetParent !== null && getComputedStyle(body).opacity === '1') {
          entry.shown = performance.now();
        }
      }
      requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  });
  await page.goto('/wp-admin/index.php', { waitUntil: 'domcontentloaded' });
  // Ecommerce refreshes through its own module, so it is outside this swap.
  const ids = await page.evaluate(() => Array.from(document.querySelectorAll<HTMLElement>('div[id^="slim_p"]'))
    .filter(el => el.id !== 'slim_p10_01' && !el.querySelector('.chart-placeholder, .refresh-timer') && el.getClientRects().length > 0)
    .map(el => el.id));
  expect(ids.length, 'fixture must expose at least two list widgets').toBeGreaterThan(1);
  // Widgets load when they scroll into view; a fresh install's core widgets push them below the fold.
  for (const id of ids) {
    await page.locator('#' + id).scrollIntoViewIfNeeded();
    await expect.poll(() => page.evaluate(id => (window as any).__swap[id]?.shown != null, id), { timeout: 30_000 }).toBe(true);
  }
  const delays: Record<string, number> = await page.evaluate(ids => Object.fromEntries(ids.map(id => {
    const entry = (window as any).__swap[id];
    return [id, Math.round(entry.shown - entry.data)];
  })), ids);
  console.log(JSON.stringify({ dataToVisibleMs: delays }));
  for (const [id, ms] of Object.entries(delays)) expect(ms, `${id}: data to visible`).toBeLessThan(300);
});

test('CodeMirror loads on the Settings screen only', async ({ page }) => {
  // load-scripts.php concatenates wp-codemirror when SCRIPT_DEBUG is off, so test the globals, not a script id.
  for (const [path, expected] of [
    ['/wp-admin/index.php', false],
    ['/wp-admin/admin.php?page=slimview2', false],
    ['/wp-admin/admin.php?page=slimconfig', true],
  ] as const) {
    await page.goto(path);
    const loaded = await page.evaluate(() => { const wp = (window as any).wp; return !!(wp && (wp.CodeMirror || wp.codeEditor)); });
    expect(loaded, path).toBe(expected);
  }
});
