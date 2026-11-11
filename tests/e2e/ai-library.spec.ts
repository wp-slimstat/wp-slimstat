import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';
import { getProState, requireProBooted, setProLicense } from './helpers/pro-state';
import { runWordPressFixture } from './helpers/chart';
import { snapshotOption, restoreOption, closeDb, snapshotSlimstatOptions, restoreSlimstatOptions } from './helpers/setup';

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
    await expect(page.getByRole('link', { name: 'Explore Pro features' })).toBeVisible();
    await expect(page.locator('.ss-ai-pro-owned a')).toHaveText(/^(Manage plugins|Activate license|Open License settings|Open Plugins)$/);
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

/**
 * The Pro panel on the guide tab: one tinted focal region flush to the card, the benefits, the real
 * requirements, and one primary action per state. Free and a Pro awaiting activation get Pro features
 * on wp-slimstat.com (attribution once, new tab) plus the existing customer's next step; a licensed
 * site gets its AI settings and no purchase link. Pro states run only where Pro is installed.
 */
const PANEL_CTA = 'https://wp-slimstat.com/pro-features/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=ai-analysis&utm_content=slimai-pro-panel';
const NEEDS = ['An active SlimStat Pro license', 'WordPress 7.0 or newer, with its AI features enabled', 'An AI provider connected in WordPress (provider API charges may apply)'];
const LICENSE_OPTIONS = ['slimstat_pro_license_verdict', 'slimstat_e2e_license_state'];

/** Lowest text contrast in the panel, against each element's nearest painted background (oklch included, via canvas). */
async function lowestContrast(page: Page): Promise<{ ratio: number; text: string }> {
  return page.locator('aside.ss-ai-pro-intro').evaluate(panel => {
    const ctx = document.createElement('canvas').getContext('2d', { willReadFrequently: true })!;
    const rgb = (color: string) => { ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = color; ctx.fillRect(0, 0, 1, 1); return Array.from(ctx.getImageData(0, 0, 1, 1).data); };
    const lum = (c: number[]) => c.slice(0, 3).map(v => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }).reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
    let lowest = { ratio: 21, text: '' };
    for (const node of Array.from(panel.querySelectorAll('p, h2, h3, li, strong, a')) as HTMLElement[]) {
      let bg: HTMLElement | null = node;
      while (bg && rgb(getComputedStyle(bg).backgroundColor)[3] === 0) bg = bg.parentElement;
      const [a, b] = [lum(rgb(getComputedStyle(node).color)), lum(rgb(getComputedStyle(bg ?? document.body).backgroundColor))];
      const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
      if (ratio < lowest.ratio) lowest = { ratio, text: node.textContent!.trim().slice(0, 40) };
    }
    return lowest;
  });
}

test.describe.serial('AI page Pro panel', () => {
  let pro = false;
  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
    for (const name of LICENSE_OPTIONS) await snapshotOption(name);
  });
  test.afterAll(async () => {
    for (const name of LICENSE_OPTIONS) await restoreOption(name);
    await restoreSlimstatOptions();
  });
  test.beforeEach(async ({ page }) => { pro = (await getProState(page)).pro_installed; });
  test.afterEach(async ({ page }) => { if (pro) expect(await setProLicense(page, 'valid')).toBe('valid'); });

  test('each state gets one primary action, the real requirements and no repeated attribution @compat', async ({ page }) => {
    for (const state of pro ? ['valid', 'none'] as const : [null]) {
      if (state) expect(await setProLicense(page, state)).toBe(state);
      await page.goto(url + '&tab=guide');
      const panel = page.locator('aside.ss-ai-pro-intro');
      await expect(panel.getByRole('heading', { level: 2 })).toHaveText('Get insights right here in WordPress');
      await expect(panel.locator('.ss-ai-benefits strong')).toHaveText(['Analyze in place', 'Come back anytime', 'Use your own AI provider']);
      await expect(panel.locator('.ss-ai-pro-needs li')).toHaveText(NEEDS);
      const cta = panel.locator('.ss-ai-pro-cta');
      await expect(cta).toHaveCount(1);
      await expect(cta).toHaveClass(/button-primary/);
      if (state === 'valid') {
        await expect(cta).toHaveText('AI Analysis settings');
        await expect(cta).toHaveAttribute('href', /\/wp-admin\/admin\.php\?page=slimai&tab=settings$/);
        await expect(panel.locator('a[href*="wp-slimstat.com"]')).toHaveCount(0);
        continue;
      }
      await expect(cta).toHaveAttribute('href', PANEL_CTA);
      const params = new URL((await cta.getAttribute('href'))!).searchParams;
      for (const key of ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content']) expect(params.getAll(key), key).toHaveLength(1);
      await expect(cta).toHaveAttribute('target', '_blank');
      await expect(cta).toHaveAttribute('rel', 'noopener noreferrer');
      await expect(cta).toHaveAccessibleName('Explore Pro features (opens in a new tab)');
      const owned = panel.locator('.ss-ai-pro-owned a');
      await expect(owned).toHaveCount(1);
      if (state === 'none') {
        await expect(owned).toHaveText('Activate license');
        await expect(owned).toHaveAttribute('href', /\/wp-admin\/admin\.php\?page=slimconfig&tab=8$/);
      } else {
        await expect(owned).toHaveText('Manage plugins');
        await expect(owned).toHaveAttribute('href', /\/wp-admin\/plugins\.php$/);
      }
    }
  });

  test('the panel stays flush, readable and contained at desktop and mobile, LTR and RTL, with long translations @compat', async ({ page }, info) => {
    if (pro) expect(await setProLicense(page, 'none')).toBe('none');
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(url + '&tab=guide');
      for (const direction of ['ltr', 'rtl']) {
        await page.evaluate(dir => { document.documentElement.dir = dir; }, direction);
        const box = await page.locator('.ss-ai-intro').evaluate((card, wide) => {
          const r = (node: Element) => node.getBoundingClientRect();
          const c = r(card), panel = r(card.querySelector('.ss-ai-pro-intro')!), cta = r(card.querySelector('.ss-ai-pro-cta')!);
          const near = (x: number, y: number) => Math.abs(x - y) <= 1;
          const rtl = document.documentElement.dir === 'rtl';
          return {
            // Inside the card's 1px border: the end column top to bottom when wide, the full width at the bottom when stacked.
            flush: near(panel.bottom, c.bottom - 1) && (wide
              ? near(panel.top, c.top + 1) && (rtl ? near(panel.left, c.left + 1) : near(panel.right, c.right - 1))
              : near(panel.left, c.left + 1) && near(panel.right, c.right - 1)),
            ctaInside: cta.left >= panel.left && cta.right <= panel.right,
            // The page content, not the document: WordPress's off-screen a11y-speak regions sit 1px past the edge in a flipped RTL.
            contained: c.left >= 0 && c.right <= window.innerWidth && card.closest('.ss-ai-page')!.scrollWidth <= card.closest('.ss-ai-page')!.clientWidth,
          };
        }, width >= 960);
        expect(box, `${width} ${direction}`).toEqual({ flush: true, ctaInside: true, contained: true });
        const contrast = await lowestContrast(page);
        expect(contrast.ratio, `${width} ${direction}: ${contrast.text}`).toBeGreaterThanOrEqual(4.5);
      }
      await page.evaluate(() => { document.documentElement.dir = 'ltr'; });
      await page.locator('.ss-ai-intro').screenshot({ path: info.outputPath(`pro-panel-${width}.png`) });
    }
    // A long translation wraps the button instead of overflowing the panel.
    await page.locator('.ss-ai-pro-cta').evaluate(a => { a.firstChild!.textContent = 'Entdecken Sie alle Funktionen von SlimStat Pro für Ihre Website'; });
    expect(await page.locator('.ss-ai-pro-intro').evaluate(panel => {
      const p = panel.getBoundingClientRect(), b = panel.querySelector('.ss-ai-pro-cta')!.getBoundingClientRect();
      return { inside: b.left >= p.left && b.right <= p.right, contained: panel.scrollWidth <= panel.clientWidth };
    })).toEqual({ inside: true, contained: true });
  });

  test('the CTA shows keyboard focus, and the sparkle and arrow keep still under reduced motion @compat', async ({ page }) => {
    if (pro) expect(await setProLicense(page, 'none')).toBe('none');
    const motion = () => page.evaluate(() => ({
      spark: getComputedStyle(document.querySelector('.ss-ai-pro-intro .slimstat-ai-spark')!).animationName,
      arrow: getComputedStyle(document.querySelector('.ss-ai-pro-arrow')!).transitionDuration,
    }));
    await page.goto(url + '&tab=guide');
    expect(await motion()).toEqual({ spark: 'slimstat-spark', arrow: '0.2s' });
    const cta = page.locator('.ss-ai-pro-cta');
    await page.locator('#ss-ai-pro-heading').click();
    for (let i = 0; i < 10 && !(await cta.evaluate(node => node === document.activeElement)); i++) await page.keyboard.press('Tab');
    const ring = await cta.evaluate(node => {
      const s = getComputedStyle(node);
      return { focused: node === document.activeElement && node.matches(':focus-visible'), indicator: (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) >= 2 && s.outlineColor !== 'rgba(0, 0, 0, 0)') || s.boxShadow !== 'none' };
    });
    expect(ring).toEqual({ focused: true, indicator: true });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.reload();
    expect(await motion()).toEqual({ spark: 'none', arrow: '0s' });
  });
});
