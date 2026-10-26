import { test, expect } from '@playwright/test';
import { closeDb, getPool, snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions } from './helpers/setup';
import { runWordPressFixture } from './helpers/chart';

test('Search Terms keeps a previous-only 468 / 24 period visible and tooltips describe the correct series', async ({ page }) => {
  await page.addInitScript(() => {
    let config: any;
    Object.defineProperty(window, 'slimstat_chart_vars', {
      configurable: true, get: () => config,
      set: value => { config = value; if ('previous_series' in value) config.previous_series = 'Vorherige %1$s'; },
    });
  });
  const resource = '/october-search-reconciliation';
  const pool = getPool();
  await snapshotSlimstatOptions();
  try {
    await setSlimstatOptions({ comparison_chart: 'on' });
    await pool.execute('DELETE FROM wp_slim_stats WHERE resource=?', [resource]);
    for (let i=0;i<468;i++) await pool.execute(
      'INSERT INTO wp_slim_stats (dt,searchterms,resource,ip) VALUES (?,?,?,?)',
      [Date.UTC(2026,3,1)/1000+(i*19731)%(90*86400), `term-${i%24}`, resource,'127.0.0.1'],
    );
    runWordPressFixture("<?php global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_slimstat_%'\");");
    await page.goto('/wp-admin/admin.php?page=slimview2&type=custom&from=2026-06-30&to=2026-09-27&fs[resource]=equals%20'+encodeURIComponent(resource));
    const canvas = page.locator('#slimstat_chart_slim_p1_19_01');
    const select = page.locator('#slimstat_granularity_slim_p1_19_01');
    await expect(canvas).toBeVisible();
    for (const granularity of ['daily','weekly']) {
      await select.selectOption(granularity);
      await expect.poll(() => page.locator('#slimstat_chart_data_slim_p1_19_01').getAttribute('data-granularity')).toBe(granularity);
      await expect(page.locator('.postbox .loading')).toHaveCount(0);
      await canvas.scrollIntoViewIfNeeded();
      const evidence = await canvas.evaluate((element: HTMLCanvasElement) => {
        const chart = (window as any).Chart.getChart(element);
        chart.stop(); chart.update('none');
        const previous = chart.data.datasets.find((d: any) => d.isPrevious && d.key==='v1');
        const datasetIndex = chart.data.datasets.indexOf(previous);
        const index = previous.data.findIndex((value: number) => value>0);
        const point = chart.getDatasetMeta(datasetIndex).data[index];
        return { label:previous.label, sum:previous.data.reduce((a:number,b:number)=>a+Number(b),0), max:chart.scales.y.max,
          peak:Math.max(...previous.data), x:point.x,y:point.y,value:previous.data[index],color:previous.borderColor };
      });
      expect(evidence.sum).toBe(468);
      expect(evidence.label).toMatch(/^Vorherige /);
      expect(evidence.max).toBeGreaterThanOrEqual(evidence.peak);
      expect(evidence.max).toBeGreaterThan(1);
      const box = (await canvas.boundingBox())!;
      await page.mouse.move(box.x+evidence.x,box.y+evidence.y);
      const tooltip = page.locator('#slimstat-chartjs-tooltip');
      await expect(tooltip).toHaveCSS('opacity','1');
      await expect(tooltip).not.toContainText('undefined');
      const termRow = tooltip.locator('tr').filter({hasText:/Search Terms/}).first();
      await expect(termRow).toContainText(String(evidence.value));
      await expect(termRow).toContainText(/Apr|May|Jun/);
      const swatch = termRow.locator('.slimstat-postbox-chart--item--color').first();
      const expectedColor = await page.evaluate(color => { const el=document.createElement('span'); el.style.backgroundColor=color; return el.style.backgroundColor; },evidence.color);
      expect(await swatch.evaluate(el => (el as HTMLElement).style.backgroundColor)).toBe(expectedColor);
    }
    const comparison = page.locator('.slimstat-toggle-prev-datasets.slimstat-postbox-chart--item-slim_p1_19_01');
    await comparison.click();
    expect(await canvas.evaluate(element => {
      const chart = (window as any).Chart.getChart(element);
      return chart.data.datasets.every((dataset: any, index: number) => !dataset.isPrevious || chart.getDatasetMeta(index).hidden);
    })).toBe(true);
    await comparison.click();
    expect(await canvas.evaluate(element => {
      const chart = (window as any).Chart.getChart(element);
      return chart.data.datasets.some((dataset: any, index: number) => dataset.isPrevious && !chart.getDatasetMeta(index).hidden);
    })).toBe(true);
    const [reference] = await pool.execute('SELECT COUNT(*) AS total, COUNT(DISTINCT searchterms) AS distinct_terms FROM wp_slim_stats WHERE resource=?',[resource]) as any;
    expect(reference[0]).toEqual({total:468,distinct_terms:24});
  } finally { await pool.execute('DELETE FROM wp_slim_stats WHERE resource=?',[resource]); await restoreSlimstatOptions(); await closeDb(); }
});
