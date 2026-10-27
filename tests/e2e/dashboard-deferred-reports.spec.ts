import { test, expect } from '@playwright/test';
import { ADMIN_USER } from './helpers/env';
import { runWordPressFixture } from './helpers/chart';
import { closeDb, snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions } from './helpers/setup';

// Attribute real analytics SQL to report dispatch, excluding unrelated admin-bar queries.
const ledger = `<?php
$GLOBALS['issue361_queries'] = 0;
add_filter('query', function ($sql) {
    if (stripos($sql, 'slim_stats') !== false) {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? '') === 'wp_slimstat_reports' && ($frame['function'] ?? '') === 'callback_wrapper') {
                $GLOBALS['issue361_queries']++;
                break;
            }
        }
    }
    return $sql;
});
add_action('shutdown', function () {
    if (is_admin() && !wp_doing_ajax()) {
        echo '<!-- issue361-ledger:' . wp_json_encode(['queries' => $GLOBALS['issue361_queries']]) . ' -->';
    }
});
`;
function historyChecksum(): string {
  return runWordPressFixture(`<?php
$tables = [$GLOBALS['wpdb']->prefix . 'slim_stats', $GLOBALS['wpdb']->prefix . 'slim_events'];
$checksums = [];
foreach ($tables as $table) {
    $row = $GLOBALS['wpdb']->get_row('CHECKSUM TABLE ' . $table, ARRAY_A);
    if (!isset($row['Checksum'])) { throw new RuntimeException('History checksum unavailable'); }
    $checksums[$table] = $row['Checksum'];
}
echo wp_json_encode($checksums);
`).trim();
}
let savedLayout: string;
test.beforeAll(async () => {
  await snapshotSlimstatOptions();
  await setSlimstatOptions({ use_separate_menu: 'no' });
  savedLayout = runWordPressFixture(`<?php
if (DB_NAME === 'local') { throw new RuntimeException('Refusing Local database'); }
$user = get_user_by('login', '${ADMIN_USER}');
$saved = [];
foreach (['metaboxhidden_dashboard', 'closedpostboxes_dashboard', 'meta-box-order_slimstat_page_slimlayout'] as $key) {
    $saved[$key] = ['exists' => metadata_exists('user', $user->ID, $key), 'value' => get_user_meta($user->ID, $key, true)];
    update_user_meta($user->ID, $key, []);
}
update_user_option($user->ID, 'meta-box-order_slimstat_page_slimlayout', ['dashboard' => 'slim_p9_01,slim_p9_02,slim_p3_03,slim_p3_04'], true);
$path = WP_CONTENT_DIR . '/mu-plugins/issue361-query-ledger.php';
if (file_exists($path)) { throw new RuntimeException('Ledger already exists'); }
wp_mkdir_p(dirname($path));
file_put_contents($path, base64_decode('${Buffer.from(ledger).toString('base64')}'));
echo wp_json_encode($saved);
`);
  await setSlimstatOptions({ add_dashboard_widgets: 'on' });
  await setSlimstatOptions({ refresh_interval: '0' });
});
test.afterAll(async () => {
  if (savedLayout) runWordPressFixture(`<?php
$user = get_user_by('login', '${ADMIN_USER}');
$saved = json_decode(base64_decode('${Buffer.from(savedLayout).toString('base64')}'), true);
foreach ($saved as $key => $value) {
    if ($value['exists']) { update_user_meta($user->ID, $key, $value['value']); }
    else { delete_user_meta($user->ID, $key); }
}
unlink(WP_CONTENT_DIR . '/mu-plugins/issue361-query-ledger.php');
`);
  await restoreSlimstatOptions();
  await closeDb();
});
for (const stored of ['no', 'on']) {
  test(`Dashboard defers every widget with stored async=${stored} and populates through AJAX`, async ({ page }) => {
    test.setTimeout(120_000);
    await setSlimstatOptions({ async_load: stored });
    const beforeHistory = historyChecksum();
    const errors: string[] = [];
    const responses = new Map<string, string>();
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', async response => {
      const params = new URLSearchParams(response.request().postData() || '');
      if (params.get('action') === 'slimstat_load_report') {
        responses.set(params.get('report_id')!, await response.text());
      }
    });
    const response = await page.goto('/wp-admin/index.php');
    expect(await response!.text()).toContain('issue361-ledger:{"queries":0}');
    expect(await page.evaluate(() => (window as any).SlimStatAdminParams.async_load)).toBe('on');
    const ids = await page.locator('.postbox[id^="slim_p"]').evaluateAll(nodes => nodes.map(node => node.id));
    expect(ids.length).toBeGreaterThan(4);
    for (const id of ids) {
      await expect.poll(() => responses.get(id), { timeout: 60_000, message: `AJAX content for ${id}` }).toBeTruthy();
      expect(responses.get(id)).not.toMatch(/Fatal error|Warning:|nonce_failure/);
      await expect(page.locator(`#${id} .inside .loading`)).toHaveCount(0);
      await expect(page.locator(`#${id} .inside`)).not.toBeEmpty();
    }
    const canvases = await page.locator('.postbox[id^="slim_p"] canvas').all();
    expect(canvases.length).toBeGreaterThan(0);
    for (const canvas of canvases) {
      await expect.poll(() => canvas.evaluate(el => {
        const chart = (window as any).Chart?.getChart(el);
        return chart?.data?.datasets?.length || 0;
      })).toBeGreaterThan(0);
    }
    for (const id of ['slim_p9_01', 'slim_p9_02']) {
      expect(ids).toContain(id);
      await expect(page.locator(`#${id} .inside .nodata, #${id} .inside table`)).not.toHaveCount(0);
    }
    console.log(JSON.stringify({ storedAsync: stored, dashboardReportQueries: 0, loadedWidgets: ids, initializedCharts: await page.locator('.postbox[id^="slim_p"] canvas').count() }));
    expect(errors).toEqual([]);
    expect(historyChecksum()).toBe(beforeHistory);
    expect(runWordPressFixture('<?php echo wp_slimstat::$settings["async_load"];').trim()).toBe(stored);
  });
}
test('SlimStat screens preserve synchronous report rendering when Async is off', async ({ page }) => {
  await setSlimstatOptions({ async_load: 'no' });
  const response = await page.goto('/wp-admin/admin.php?page=slimview2');
  const match = (await response!.text()).match(/issue361-ledger:(\{"queries":\d+\})/);
  expect(match).not.toBeNull();
  expect(JSON.parse(match![1]).queries).toBeGreaterThan(0);
  expect(await page.evaluate(() => (window as any).SlimStatAdminParams.async_load)).toBe('no');
});
