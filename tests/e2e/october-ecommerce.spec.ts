import { requireProBooted } from './helpers/pro-state';
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { runWordPressFixture } from './helpers/chart';
import { snapshotSlimstatOptions, restoreSlimstatOptions, snapshotOption, restoreOption, setSlimstatOptions, closeDb } from './helpers/setup';
const fixtureCode = readFileSync(new URL('./helpers/ecommerce-data.php', import.meta.url), 'utf8').replace(/^<\?php\s*/, '');
const fixture = (mode: string) => JSON.parse(runWordPressFixture(`<?php $fixture_mode='${mode}';\n${fixtureCode}`));

test('Tasks 5–11, 26–27: currency, comparisons, full report, export and chart gaps @woocommerce', async ({ page }, info) => {
  test.setTimeout(180000);
  await requireProBooted(page);
  await snapshotSlimstatOptions(); await snapshotOption('slimstat_filters');
  fixture('cleanup');
  try {
    const seed = fixture('seed');
    expect(fixture('verify').result).toBe('pass');
    expect(fixture('chart').result).toBe('pass');
    const currencies=JSON.parse(runWordPressFixture(`<?php
      // Exercise the supported WooPayments API shape, including an active currency with no orders.
      function WC_Payments_Multi_Currency() { return new class { public function get_enabled_currencies() { return ['USD'=>(object)[],'GBP'=>(object)[]]; } }; }
      require_once WP_PLUGIN_DIR.'/wp-slimstat/admin/view/wp-slimstat-db.php'; wp_slimstat_db::init();
      $f=get_option('slimstat_ecommerce_test_fixture'); wp_slimstat_db::$filters_normalized['utime']=['start'=>$f['start'],'end'=>$f['end']];
      $d=(new \\SlimStat\\Ecommerce\\Report())->data(); echo wp_json_encode(array_column($d['currencies'],'currency'));
    `));
    expect(currencies).toEqual(expect.arrayContaining(['USD','EUR','GBP']));

    await setSlimstatOptions({ rows_to_show: 20, async_load: 'on', slimstat_pro_license_status: true });
    runWordPressFixture(`<?php
      $f = get_option('slimstat_ecommerce_test_fixture');
      add_filter('pre_wp_mail', '__return_true');
      for ($i=0; $i<24; $i++) {
        $o = new WC_Order(); $o->set_status('completed'); $o->set_currency('USD'); $o->set_total((string) ($i+1));
        $o->set_date_created($f['start']+600); $o->update_meta_data('_wc_order_attribution_source_type','utm');
        $o->update_meta_data('_wc_order_attribution_utm_source',sprintf('source-%02d',$i)); $o->update_meta_data('_wc_order_attribution_utm_medium','email');
        $o->update_meta_data('_wc_order_attribution_utm_campaign',sprintf('campaign-%02d',$i)); $o->save();
        $f['orders'][]=$o->get_id(); \\SlimStat\\Ecommerce\\Integration::sync($o->get_id());
      }
      update_option('slimstat_ecommerce_test_fixture',$f); \\SlimStat\\Ecommerce\\Integration::invalidate();
    `);
    const day = new Date(seed.start * 1000).toISOString().slice(0, 10);
    const params = new URLSearchParams({ page: 'slimview7', type: 'custom', from: day, to: day, 'fs[addon_ecommerce_currency]': 'equals USD' });
    await page.goto('/wp-admin/admin.php?' + params);
    const dash = page.locator('[data-ecommerce]');
    await expect(dash.locator('[data-metric=orders]')).toHaveText('28');
    for (const metric of ['net','orders','aov']) await expect(dash.locator(`[data-chart-metric=${metric}] small`)).toContainText('Not enough data in the previous period');
    await expect(dash).toContainText('Purchase rate (tracked visits)');
    await expect(dash).toContainText('Visits with a purchase ÷ eligible tracked visits.');
    await expect(dash).not.toContainText('Activate your license');
    await page.getByRole('tab', { name: 'Sources', exact: true }).click();
    const panel = page.locator('[data-dimension=source]');
    await expect(panel.locator('.ss-ec-rankings > li:visible')).toHaveCount(5);
    await panel.getByRole('button', { name: 'Full report', exact: true }).click();
    await expect(panel.locator('.ss-ec-rankings > li:visible')).toHaveCount(20);
    await expect(panel.locator('table')).toHaveCount(0);
    await panel.getByRole('button', { name: 'Next', exact: true }).click();
    await expect(panel.locator('[data-page-label]')).toContainText('21–');
    expect(await panel.locator('.ss-ec-rankings > li:visible').count()).toBeGreaterThan(0);
    await panel.getByRole('button', { name: 'Previous', exact: true }).click();
    await panel.locator('[data-rank-metric]').selectOption('orders');
    await panel.locator('[data-sort]').click();
    await page.getByRole('tab', { name: 'Campaigns', exact: true }).click();
    await page.getByRole('tab', { name: 'Sources', exact: true }).click();
    await expect(panel.locator('[data-rank-metric]')).toHaveValue('orders');
    await expect(panel.locator('[data-sort]')).toHaveAttribute('aria-pressed','true');
    await expect(panel.locator('[data-expand]')).toHaveAttribute('aria-expanded','true');
    const exportLink = panel.locator('[data-ec-export]');
    await expect(exportLink).toBeVisible();
    const csv = await page.request.get((await exportLink.getAttribute('href'))!);
    expect(csv.ok()).toBeTruthy(); expect(await csv.text()).toContain('source-23');
    await info.attach('source-export.csv', { body: await csv.body(), contentType: 'text/csv' });
    await page.screenshot({ path: info.outputPath('ecommerce-full-report.png'), fullPage: true });
    await dash.getByRole('button', { name: 'Explore products', exact: true }).click();
    await expect(page.locator('[data-dimension=product]')).toBeFocused();
    // The same reporting scope crosses the exact previous-period order threshold.
    for (const previousOrders of [4, 5, 0]) {
      runWordPressFixture(`<?php
        $f=get_option('slimstat_ecommerce_test_fixture');
        foreach ($f['orders'] as $id) {
          $o=wc_get_order($id);
          if ($o && $o->get_date_created()->getTimestamp() < $f['start']) { $o->delete(true); \\SlimStat\\Ecommerce\\Integration::sync($id); }
        }
        for ($i=0; $i<${previousOrders}; $i++) {
          $o=new WC_Order(); $o->set_status('completed'); $o->set_currency('USD'); $o->set_total('20'); $o->set_date_created($f['start']-1800); $o->save();
          $f['orders'][]=$o->get_id(); \\SlimStat\\Ecommerce\\Integration::sync($o->get_id());
        }
        update_option('slimstat_ecommerce_test_fixture',$f); \\SlimStat\\Ecommerce\\Integration::invalidate();
      `);
      await page.reload();
      for (const metric of ['net','orders','aov']) {
        const comparison=dash.locator(`[data-chart-metric=${metric}] small`);
        if (previousOrders<5) await expect(comparison).toContainText('Not enough data in the previous period');
        else await expect(comparison).toContainText('%');
      }
    }
    const trafficParams=new URLSearchParams(params); trafficParams.set('page','slimview5'); trafficParams.delete('fs[addon_ecommerce_currency]');
    await page.goto('/wp-admin/admin.php?'+trafficParams);
    for (const [report, tab] of [['slim_p3_03','channel'],['slim_p3_04','campaign']]) {
      const link=page.locator('#'+report).getByRole('link',{name:'View sales in Ecommerce',exact:true}).first();
      await expect(link).toBeVisible(); await link.click();
      await expect(page.locator('[data-dimension='+tab+']')).toBeVisible();
      expect(await page.evaluate(() => (window as any).jQuery('.slimstat-date-range-input').data('daterangepicker').startDate.format('YYYY-MM-DD'))).toBe(day);
      await page.goto('/wp-admin/admin.php?'+trafficParams);
    }
    params.set('fs[addon_ecommerce_currency]', 'equals EUR');
    await page.goto('/wp-admin/admin.php?' + params);
    await expect(dash.locator('[data-metric=orders]')).toHaveText('1');
    await expect(dash.locator('[data-metric=net]')).toContainText('200.00');
    params.set('fs[addon_ecommerce_currency]', 'equals GBP');
    await page.goto('/wp-admin/admin.php?' + params);
    await expect(dash).toContainText('No included orders for this currency');
    await dash.locator('[data-chart-metric=aov]').click();
    await expect(dash.locator('[data-chart-gaps]')).toBeVisible();
    await dash.locator('[data-chart-gaps] summary').click();
    await expect(dash.locator('[data-chart-gaps]')).toContainText('No orders');
    await dash.locator('[data-chart-metric=rate]').click();
    await expect(dash.locator('[data-chart-gaps]')).toContainText('Not enough visits');
    // A measured zero/positive point between null intervals must remain visible in both series.
    for (const metric of ['aov', 'rate']) {
      await dash.locator('[data-series]').evaluate((element, selected) => {
        const series = JSON.parse((element as HTMLElement).dataset.series!);
        for (const [period, values] of Object.entries({ current: [null, 0, null, null], previous: [null, null, 2, null] })) {
          const source = series[period][0];
          series[period] = values.map((value, index) => ({ ...source, partial: false,
            label: `Interval ${index}`, short: String(index), [selected]: value,
            formatted: { ...source.formatted, [selected]: value === null ? 'No data' : String(value) } }));
        }
        (element as HTMLElement).dataset.series = JSON.stringify(series);
      }, metric);
      await dash.locator(`[data-chart-metric=${metric}]`).click();
      const points = await dash.locator('canvas').evaluate(canvas => {
        const chart = (window as any).Chart.getChart(canvas);
        chart.update('none');
        return [0, 1].map(dataset => chart.getDatasetMeta(dataset).data.map((point: any) => ({
          skipped: point.skip, radius: point.options.radius, y: point.y,
        })));
      });
      for (const [dataset, index] of [[0, 1], [1, 2]]) {
        expect(points[dataset][index].skipped).toBe(false);
        expect(points[dataset][index].radius).toBeGreaterThan(0);
        expect(Number.isFinite(points[dataset][index].y)).toBe(true);
        expect(points[dataset][0].skipped).toBe(true);
      }
      // Hovering an unavailable interval explains it instead of jumping to the neighbouring point.
      const marker = await dash.locator('canvas').evaluate(canvas => {
        const chart = (window as any).Chart.getChart(canvas);
        const point = chart.getDatasetMeta(chart.data.datasets.findIndex((d: any) => d.gap)).data[0];
        const box = canvas.getBoundingClientRect();
        return { x: box.x + point.x, y: box.y + point.y };
      });
      await page.mouse.move(marker.x, marker.y);
      const tooltip = await dash.locator('canvas').evaluate(canvas => {
        const { tooltip } = (window as any).Chart.getChart(canvas);
        return [...tooltip.title, ...tooltip.body.flatMap((b: any) => b.lines), ...tooltip.afterBody];
      });
      expect(tooltip).toEqual(expect.arrayContaining(['Interval 0', 'Selected period: No data', 'Previous period: No data']));
      await page.mouse.move(0, 0);
    }
    // Expired history is unavailable, not evidence that there were no orders or visits.
    await setSlimstatOptions({ auto_purge: 1 });
    await page.reload();
    for (const metric of ['aov', 'rate']) {
      await dash.locator(`[data-chart-metric=${metric}]`).click();
      await expect(dash.locator('[data-chart-gaps]')).toContainText('No data');
      await expect(dash.locator('[data-chart-gaps]')).not.toContainText(/No orders|Not enough visits/);
    }
  } finally { fixture('cleanup'); await restoreOption('slimstat_filters'); await restoreSlimstatOptions(); await closeDb(); }
});
