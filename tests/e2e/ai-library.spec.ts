import { test, expect } from '@playwright/test';
import { requireProBooted } from './helpers/pro-state';
import { runWordPressFixture } from './helpers/chart';
import { snapshotOption, restoreOption, closeDb } from './helpers/setup';

const url = '/wp-admin/admin.php?page=slimai';
const reports = ['slim_p1_01', 'slim_p1_03'];
let keys: string[] = [];

async function seedLibrary() {
  const user = Number(runWordPressFixture('<?php echo get_user_by("login", "admin")->ID;').trim());
  keys = reports.map(report => `slimstat_ai_result_${user}_${report}`);
  for (const key of keys) await snapshotOption(key);
  runWordPressFixture(`<?php
    require_once dirname(SLIMSTAT_FILE) . '/admin/index.php';
    wp_set_current_user(${user});
    foreach (['slim_p1_01', 'slim_p1_03'] as $i => $report) {
      $context = \\SlimStat\\Reports\\AiReport::context(['report' => $report, 'from' => '2026-01-01', 'to' => '2026-01-31']);
      if (is_wp_error($context)) { throw new RuntimeException($context->get_error_message()); }
      $analysis = ['schema' => 1, 'generated' => 1793700000 + $i, 'authorization' => $context['authorization'], 'fingerprint' => $context['fingerprint'],
        'provider' => 'fixture-provider', 'model' => 'fixture-model', 'context' => $context['public'],
        'content' => "## Report Summary\\n12 synthetic pageviews.\\n## Key Insights\\n- Small sample.\\n<img src=x onerror=alert(1)>\\n## Recommended Actions\\n- Review the next period.\\n## Data Limitations\\nSynthetic test data."];
      $analysis['context']['filters'] = ['Browser: selected value withheld'];
      update_option('slimstat_ai_result_${user}_' . $report, wp_json_encode(['analysis' => $analysis]), false);
    }
  `);
}

test.afterEach(async () => {
  for (const key of keys) await restoreOption(key);
  keys = [];
});
test.afterAll(async () => { await closeDb(); });

test('AI page introduces copy and native analysis with the existing New badge @compat', async ({ page }) => {
  const calls: string[] = [];
  page.on('request', request => { if (decodeURIComponent(request.url()).includes('/slimstat/v1/ai/')) calls.push(request.url()); });
  await page.goto(url + '&tab=guide');
  await expect(page.getByRole('heading', { name: 'AI Analysis', exact: true })).toBeVisible();
  await expect(page.locator('.ss-ai-steps')).toContainText('ChatGPT, Claude, Gemini, Grok');
  await expect(page.locator('#adminmenu a[href="admin.php?page=slimai"] .slimstat-menu-new-badge')).toBeVisible();
  const native = await page.evaluate(() => Boolean((window as any).SlimStatAdminParams.ai.native));
  if (!native) {
    await expect(page.getByRole('link', { name: 'Explore Pro AI' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Manage plugins' })).toBeVisible();
    await expect(page.locator('.ss-ai-library')).toHaveCount(0);
  } else {
    await expect(page.getByRole('link', { name: 'AI Analysis settings', exact: true })).toBeVisible();
  }
  expect(calls).toHaveLength(0);
});

test('saved library searches, opens safely, copies and never generates @compat', async ({ page }) => {
  await requireProBooted(page);
  await seedLibrary();
  await page.addInitScript(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async (text: string) => { (window as any).__copied = text; } } }));
  const calls: string[] = [];
  page.on('request', request => { if (decodeURIComponent(request.url()).includes('/slimstat/v1/ai/')) calls.push(decodeURIComponent(request.url())); });
  await page.goto(url);
  const entries = page.locator('.ss-ai-entry');
  await expect(entries).toHaveCount(2);
  await expect(entries.first()).toHaveAttribute('data-report', 'slim_p1_03');
  expect(calls).toHaveLength(0);
  await page.getByLabel('Find a saved report').fill('no matching report');
  await expect(page.locator('#ss-ai-search-empty')).toBeVisible();
  await expect(page.locator('.ss-ai-entry:visible')).toHaveCount(0);
  await page.getByLabel('Find a saved report').fill('At a Glance');
  const entry = page.locator('.ss-ai-entry:visible');
  await expect(entry).toHaveCount(1);
  await entry.locator('summary').focus();
  await page.keyboard.press('Enter');
  await expect(entry.locator('.ss-ai-result')).toContainText('12 synthetic pageviews.');
  await expect(entry).toContainText('Browser: selected value withheld');
  await expect(entry).toContainText('2026-01-01 – 2026-01-31');
  await expect(entry.locator('.ss-ai-result img, .ss-ai-result script')).toHaveCount(0);
  await entry.getByRole('button', { name: 'Copy Result' }).click();
  expect(await page.evaluate(() => (window as any).__copied)).toContain('12 synthetic pageviews.');
  await entry.locator('summary').click();
  await entry.locator('summary').click();
  expect(calls).toHaveLength(1);
  expect(calls[0]).toContain('/status');
});

test('library recovers after a failed read and fits mobile, tablet and RTL @compat', async ({ page }, info) => {
  await requireProBooted(page);
  await seedLibrary();
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/status'), route => route.fulfill({ status: 503, json: { code: 'unavailable', message: 'Saved result could not load.' } }));
  await page.goto(url);
  const first = page.locator('.ss-ai-entry').first();
  await first.locator('summary').click();
  await expect(first.getByRole('alert')).toContainText('Saved result could not load');
  await page.unrouteAll();
  await first.locator('summary').click();
  await first.locator('summary').click();
  await expect(first.locator('.ss-ai-result')).toBeVisible();
  for (const width of [390, 768, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    for (const direction of ['ltr', 'rtl']) {
      await page.evaluate(dir => { document.documentElement.dir = dir; }, direction);
      expect(await page.locator('.ss-ai-page').evaluate(element => {
        const bounds = element.getBoundingClientRect();
        return bounds.left >= 0 && bounds.right <= window.innerWidth && element.scrollWidth <= element.clientWidth;
      })).toBe(true);
    }
    await page.evaluate(() => { document.documentElement.dir = 'ltr'; });
    await page.screenshot({ path: info.outputPath(`library-${width}.png`), fullPage: true });
  }
});
