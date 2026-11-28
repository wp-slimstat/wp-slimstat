import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';

test('long translated live metric labels fit above the phone breakpoint', async ({page}) => {
  await page.goto('/wp-admin/admin.php?page=slimview1');
  const live=page.locator('#slim_live_analytics');
  for(const width of [601,768,1024,1440]) {
    await page.setViewportSize({width,height:900});
    for(const dir of ['ltr','rtl']) {
      await page.evaluate(dir=>{
        document.documentElement.dir=dir;document.body.classList.toggle('rtl',dir==='rtl');
        for(const label of document.querySelectorAll('.metric-label')) {
          for(const node of [...label.childNodes]) if(node.nodeType===Node.TEXT_NODE) node.textContent='';
          label.append(document.createTextNode(dir==='rtl'?'بازدیدکنندگان آنلاین در همه صفحه‌های سایت':'Visitors currently online across all site pages'));
        }
      },dir);
      const measurements=await live.evaluate(el=>{
        const container=el.querySelector('.live-analytics-container')!.getBoundingClientRect();
        const metrics=el.querySelector('.live-analytics-metrics')!.getBoundingClientRect();
        return {overflow:el.scrollHeight-el.clientHeight,rows:[...el.querySelectorAll('.live-metric-item')].map(row=>{
          const r=row.getBoundingClientRect(),value=row.querySelector('.metric-value')!.getBoundingClientRect();
          return {overflow:row.scrollHeight-row.clientHeight,bottom:r.bottom,contentBottom:value.bottom,containerBottom:container.bottom,panelBottom:metrics.bottom};
        })};
      });
      expect(measurements.overflow,`${dir} ${width}px outer panel`).toBeLessThanOrEqual(2);
      for(const row of measurements.rows) {
        expect(row.overflow,`${dir} ${width}px metric row`).toBeLessThanOrEqual(1);
        expect(row.contentBottom).toBeLessThanOrEqual(row.bottom);
        expect(row.bottom).toBeLessThanOrEqual(row.containerBottom);
        expect(row.bottom).toBeLessThanOrEqual(row.panelBottom);
      }
    }
  }
});

for (const direction of ['ltr', 'rtl']) {
  for (const width of [1440, 390, 320]) {
    test(`shared report controls align in ${direction} at ${width}px`, async ({ page }) => {
      await page.setViewportSize({width, height:900});
      await page.goto('/wp-admin/admin.php?page=slimview1');
      await page.evaluate(dir => {
        document.documentElement.dir = dir;
        document.body.classList.toggle('rtl', dir === 'rtl');
      }, direction);
      const picker = page.locator('.slimstat-date-range-btn');
      await expect(picker).toBeVisible();
      const geometry = await picker.evaluate(el => {
        const rect = (selector: string) => el.querySelector(selector)!.getBoundingClientRect();
        const icon = rect('.calendar-icon'), text = rect('.date-label'), caret = rect('.caret');
        return {iconCenter:icon.y+icon.height/2,textCenter:text.y+text.height/2,caretCenter:caret.y+caret.height/2,overflow:el.scrollWidth-el.clientWidth};
      });
      expect(Math.abs(geometry.iconCenter-geometry.textCenter)).toBeLessThan(2);
      expect(Math.abs(geometry.iconCenter-geometry.caretCenter)).toBeLessThan(2);
      expect(geometry.overflow).toBeLessThanOrEqual(1);
      const arrow=page.locator('.slimstat-header__cta-arrow svg').first();
      if(await arrow.isVisible()) {
        expect(await arrow.evaluate(el=>getComputedStyle(el).transform)).toBe(direction==='rtl'?'matrix(-1, 0, 0, 1, 0, 0)':'none');
      }
      const liveBox=page.locator('#slim_live_analytics');
      expect(await liveBox.evaluate(el=>el.scrollHeight-el.clientHeight)).toBeLessThanOrEqual(2);
      const metrics=page.locator('.live-metric-item');
      await expect(metrics).toHaveCount(3);
      for(const metric of await metrics.all()) {
        const box=await metric.evaluate(el=>{
          const r=el.getBoundingClientRect(),c=getComputedStyle(el);
          return {width:r.width,height:r.height,start:parseFloat(c.paddingInlineStart),end:parseFloat(c.paddingInlineEnd),overflow:el.scrollWidth-el.clientWidth};
        });
        expect(box.height).toBeGreaterThanOrEqual(44);
        expect(box.start).toBe(box.end);
        expect(box.start).toBeGreaterThanOrEqual(8);
        expect(box.overflow).toBeLessThanOrEqual(1);
      }
      if(width===320) {
        await metrics.first().locator('.metric-value').evaluate(el=>el.textContent='123,456,789');
        expect(await metrics.first().evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
      }
      const pages=page.locator('.live-metric-item[data-metric="pages"]');
      if(await pages.isEnabled()) {
        await pages.focus();
        await page.keyboard.press('Enter');
        await expect(pages).toHaveAttribute('aria-pressed','true');
        await expect(page.locator('.live-metric-item[aria-pressed="true"]')).toHaveCount(1);
      }
      const range=page.locator('.slimstat-pagination-range').first();
      if(await range.count()) {
        await expect(range).toHaveAttribute('dir','auto');
        expect(await range.evaluate(el=>getComputedStyle(el).float)).toBe('none');
      }
      await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
    });
  }
}

// Same markup as admin/view/right-now.php, so the layout tests do not depend on tracked rows.
const accessLogProbeRow = `<a class="slimstat-font-logout slimstat-tooltip-trigger" title="Open this URL in a new window" href="#"></a> <a class="slimstat-filter-link" href="#"><span dir="auto">Contact Us</span></a> <span class="details"><i class="slimstat-font-stopwatch spaced slimstat-tooltip-trigger" title="Time spent on this page"></i> 00:01 <i class="spaced slimstat-font-doc slimstat-tooltip-trigger" title="Content Type"></i> <a class="slimstat-filter-link" href="#"><span dir="auto">page</span></a> <i class="slimstat-font-gauge spaced slimstat-tooltip-trigger" title="Server Latency and Page Speed in milliseconds"></i> Server 330 ms · Page 155 ms <i class="spaced slimstat-font-clock slimstat-tooltip-trigger" title="Date and Time"></i> Nov 27, 2026 12:06 pm <i class="slimstat-font-edit slimstat-tooltip-trigger"><b class="slimstat-tooltip-content">user: 1</b></i> <a class="slimstat-delete-entry slimstat-font-cancel slimstat-tooltip-trigger" data-pageview-id="0" title="Delete this entry from the database" href="#"></a></span>`;

// 6.0 (16ad233f) put every pageview's details on a second line, flush with the start edge; 5.5 kept them on the resource's line at the far edge.
test('access log details share the resource line and end at the far edge, like 5.5', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=slimview1');
  // A long title leaves no room beside it, so its details wrap below; the row must grow to hold them.
  await page.locator('#slim_p7_02 .inside').evaluate((el, html) => {
    for (const [probe, title] of [['long', 'A much longer page title that someone wrote for their blog post about analytics and privacy'], ['true', 'Contact Us']]) {
      const row = document.createElement('p');
      row.dataset.accessLogProbe = probe;
      row.innerHTML = html.replace('Contact Us', title);
      el.prepend(row);
    }
  }, accessLogProbeRow);
  for (const width of [1920, 1440, 1024, 390]) {
    await page.setViewportSize({ width, height: 900 });
    for (const dir of ['ltr', 'rtl']) {
      const row = await page.locator('[data-access-log-probe="true"]').evaluate((p, d) => {
        document.documentElement.dir = d;
        document.body.classList.toggle('rtl', d === 'rtl');
        const r = p.getBoundingClientRect(), cs = getComputedStyle(p);
        const link = p.querySelector(':scope > .slimstat-filter-link')!.getBoundingClientRect();
        const detailsEl = p.querySelector('.details')!, details = detailsEl.getBoundingClientRect();
        const resource = document.createRange();
        resource.setStartBefore(p.firstChild!);
        resource.setEndBefore(detailsEl);
        return {
          fits: resource.getBoundingClientRect().width + details.width <= r.width - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight),
          sameLine: details.top < link.bottom,
          endGap: Math.round(r.right - parseFloat(cs.paddingRight) - details.right),
          startGap: Math.round(details.left - r.left - parseFloat(cs.paddingLeft)),
          // Scoped to the probe rows (page-wide overflow also measures WordPress chrome, which differs by core version and font); text inside the link is clipped by its phone ellipsis.
          outside: [...document.querySelectorAll('[data-access-log-probe], [data-access-log-probe] > *, [data-access-log-probe] .details *')].filter(e => { const b = e.getBoundingClientRect(), box = p.parentElement!.getBoundingClientRect(); return b.width > 0 && (b.left < box.left - 1 || b.right > box.right + 1); }).map(e => e.outerHTML.slice(0, 80)),
          spill: [...document.querySelectorAll('[data-access-log-probe]')].map(q => Math.round(q.querySelector('.details')!.getBoundingClientRect().bottom - q.getBoundingClientRect().bottom)),
        };
      }, dir);
      if (width >= 1440) expect(row.fits, `${dir} ${width}px has room for one line`).toBe(true);
      if (width > 800) {
        // Too narrow for one line, the details drop below the resource, still at the far edge.
        if (row.fits) expect(row.sameLine, `${dir} ${width}px details on the resource line`).toBe(true);
        expect(dir === 'rtl' ? row.startGap : row.endGap, `${dir} ${width}px details reach the far edge`).toBeLessThanOrEqual(2);
      }
      expect(row.outside, `${dir} ${width}px nothing crosses the Access Log edges`).toEqual([]);
      expect(Math.max(...row.spill), `${dir} ${width}px details stay inside their row`).toBeLessThanOrEqual(0);
    }
  }
});

// GitHub #295: a tall panel that ends the page grows to the viewport instead of stopping at 465px.
test('access log fills the viewport when it is the last panel and keeps 465px otherwise', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/wp-admin/admin.php?page=slimview1');
  const box = page.locator('#slim_p7_02');
  const insideHeight = () => box.locator('.inside').evaluate(el => Math.round(el.getBoundingClientRect().height));
  // A row, not the empty state: an empty .inside is height:auto (audit E1), so neither height rule would apply.
  await box.locator('.inside').evaluate((el, html) => { const p = document.createElement('p'); p.innerHTML = html; el.replaceChildren(p); }, accessLogProbeRow);
  // Unticking the two reports after it in Screen Options hides them with jQuery's .hide() (core postboxes.js); they stay in the page.
  await box.evaluate(el => { const after = [...el.parentElement!.querySelectorAll(':scope > .postbox')].filter(p => p !== el).slice(-2); after[0].before(el); after.forEach(p => (window as any).jQuery(p).hide()); });
  expect(await insideHeight()).toBeGreaterThan(465);
  expect(await box.evaluate(el => el.getBoundingClientRect().height)).toBeLessThanOrEqual(900 - 32);
  await page.setViewportSize({ width: 1440, height: 500 });
  expect(await insideHeight(), 'short windows keep the old minimum').toBe(465);
  await page.setViewportSize({ width: 390, height: 900 });
  expect(await insideHeight(), 'phones keep a fixed scroll box').toBe(465);
  await page.setViewportSize({ width: 1440, height: 900 });
  await box.evaluate(el => el.after(Object.assign(document.createElement('div'), { className: 'postbox' })));
  expect(await insideHeight(), 'a shown panel below keeps the Access Log at 465px').toBe(465);
});

// GitHub #295: normal-width reports fill the viewport when only one, or a few sharing one row, are left;
// otherwise they keep 240px, so no card in a row is stretched past its content.
test('a few normal reports fill the viewport when they share one row and keep 240px otherwise', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 }); // three normal reports per row
  await page.goto('/wp-admin/admin.php?page=slimview2');
  await page.waitForLoadState('networkidle');
  // A list report: charts and acquisition boxes size themselves and are meant to keep their height.
  const id = await page.locator('.meta-box-sortables > [id^="slim_"].postbox:not(.full-width, .large, .extralarge, .slimstat-acquisition-box)').filter({ hasNot: page.locator('canvas, .slimstat-postbox-chart--canvas') }).first().getAttribute('id');
  const box = page.locator(`#${id}`); // pinned: a locator re-resolves, and the copies added below would match a positional one
  await box.locator('.inside').evaluate((el, html) => { const p = document.createElement('p'); p.innerHTML = html; el.replaceChildren(p); }, accessLogProbeRow);
  // Untick every other report, as Screen Options does: jQuery's .hide() leaves them in the page, charts included.
  await box.evaluate(el => el.parentElement!.querySelectorAll(':scope > .postbox').forEach(p => p !== el && (window as any).jQuery(p).hide()));
  const layout = () => box.evaluate(el => [...el.parentElement!.querySelectorAll<HTMLElement>(':scope > .postbox')].filter(p => p.style.display !== 'none').map(p => ({
    inside: Math.round(p.querySelector('.inside')!.getBoundingClientRect().height), top: Math.round(p.getBoundingClientRect().top), box: p.getBoundingClientRect().height,
  })));
  const copies = (n: number) => box.evaluate((el, n) => {
    el.parentElement!.querySelectorAll(':scope > [id^="slim_copy"]').forEach(p => p.remove());
    for (let i = 0; i < n; i++) el.after(Object.assign(el.cloneNode(true) as HTMLElement, { id: `slim_copy${i}` }));
  }, n);

  let rows = await layout();
  expect(rows[0].inside, 'the only report left fills the window').toBeGreaterThan(465);
  expect(rows[0].box).toBeLessThanOrEqual(900 - 32);

  await copies(2);
  rows = await layout();
  expect(new Set(rows.map(r => r.top)).size, 'three reports share one row').toBe(1);
  for (const r of rows) { expect(r.inside, 'a few reports on one row fill the window together').toBeGreaterThan(465); expect(r.box).toBeLessThanOrEqual(900 - 32); }

  await copies(3);
  rows = await layout();
  expect(new Set(rows.map(r => r.top)).size, 'four reports wrap onto two rows').toBe(2);
  expect(rows.map(r => r.inside), 'reports on two rows keep 240px').toEqual([240, 240, 240, 240]);

  await copies(1);
  await page.setViewportSize({ width: 790, height: 900 });
  rows = await layout();
  expect(rows.map(r => r.inside), 'at 800px and below reports go full width, so two wrap and keep 240px').toEqual([240, 240]);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.locator('#slim_copy0 .inside').evaluate(el => { el.innerHTML = '<div class="slimstat-empty"><p class="nodata">No data</p></div>'; });
  rows = await layout();
  expect(rows[0].inside, 'beside an empty state, which keeps its own height, the report keeps 240px').toBe(240);
});

test('long alert copy and actions stay visible in both directions and on phones', async ({ page }) => {
  const html=runWordPressFixture(`<?php
  require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/index.php';
  wp_slimstat_admin::show_dismissible_notice('layout-review', hash('sha256','layout-review'), '<strong>Database update required.</strong><br>Tracking and reports kept working.<ul class="ul-disc"><li><code>slim_stats.traffic_channel, slim_stats.traffic_source, slim_stats.utm_campaign, slim_stats.utm_content</code></li></ul>', [['Review migration', admin_url('admin.php?page=slimstat_migration'), false]], 'warning');
  `);
  for(const width of [1440,320]) {
    for(const dir of ['ltr','rtl']) {
      await page.setViewportSize({width,height:900});
      await page.goto('/wp-admin/admin.php?page=slimview1');
      await page.evaluate(({html,dir})=>{
        document.documentElement.dir=dir;document.body.classList.toggle('rtl',dir==='rtl');
        document.querySelector('#wpbody-content')!.insertAdjacentHTML('afterbegin',html);
      },{html,dir});
      const notice=page.locator('[data-notice-key="layout-review"]');
      await expect(notice.locator('.slimstat-alert-content')).toBeVisible();
      await expect(notice.getByText('Database update required.',{exact:true})).toBeVisible();
      await expect(notice.locator('code')).toBeVisible();
      await expect(notice.getByRole('link',{name:'Review migration'})).toBeVisible();
      expect(await notice.evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
    }
  }
});

test('report icons keep their spacing beside English and Persian labels in either page direction', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=slimview2');
  await page.locator('.postbox .inside').first().evaluate(el => {
    for (const icon of ['slimstat-flag-icon', 'slimstat-browser-icon']) {
      for (const label of ['Germany', 'ایران']) {
        const row = document.createElement('p');
        row.className = 'slimstat-tooltip-trigger';
        row.innerHTML = `<a class="slimstat-filter-link"><span dir="auto"><img class="${icon}" src="../wp-content/plugins/wp-slimstat/admin/assets/images/flags/de.svg" alt="">${label}</span></a>`;
        row.dataset.spacingProbe = 'true';
        el.append(row);
      }
    }
  });
  for (const width of [1440, 320]) {
    await page.setViewportSize({ width, height: 900 });
    for (const direction of ['ltr', 'rtl']) {
      await page.evaluate(dir => {
        document.documentElement.dir = dir;
        document.body.classList.toggle('rtl', dir === 'rtl');
      }, direction);
      const gaps = await page.locator('[data-spacing-probe] span[dir="auto"]').evaluateAll(labels => labels.map(label => {
        const image = label.querySelector('img')!.getBoundingClientRect();
        const range = document.createRange();
        range.selectNodeContents(label.lastChild!);
        const text = range.getBoundingClientRect();
        return getComputedStyle(label).direction === 'rtl' ? image.left - text.right : text.left - image.right;
      }));
      for (const gap of gaps) {
        expect(gap, `${direction} at ${width}px`).toBeGreaterThanOrEqual(6);
        expect(gap, `${direction} at ${width}px`).toBeLessThanOrEqual(12);
      }
    }
  }
});
