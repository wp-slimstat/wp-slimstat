import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';

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
