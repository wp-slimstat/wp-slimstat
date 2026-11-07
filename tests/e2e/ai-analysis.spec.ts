import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { seedPageviews, getPool, clearStatsCaches, closeDb } from './helpers/setup';

const prefix = '/e2e-native-ai-';
const report = '#slim_p1_03';

test.beforeAll(async () => {
  await getPool().execute('DELETE FROM wp_slim_stats WHERE resource LIKE ?', [`${prefix}%`]);
  await seedPageviews({ count: 12, resourcePrefix: prefix });
  await clearStatsCaches();
});
test.afterAll(async () => {
  await getPool().execute('DELETE FROM wp_slim_stats WHERE resource LIKE ?', [`${prefix}%`]);
  await clearStatsCaches();
  await closeDb();
});
test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => Object.defineProperty(navigator, 'clipboard', {
    configurable: true, value: { writeText: async (text: string) => { (window as any).__copied = text; } },
  }));
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
  await page.locator(report).scrollIntoViewIfNeeded();
  await expect(page.locator(`${report} .inside`)).not.toHaveText(/^\s*$/);
});

test('copy success has an accessible, non-repeating Free invitation @compat', async ({ page }, info) => {
  await page.evaluate(() => { (window as any).SlimStatAdminParams.ai.native = false; });
  const button = page.locator(`${report} .slimstat-ai-prompt`);
  await button.click();
  const toast = page.locator('#slimstat-ai-toast');
  await expect(toast.getByRole('status')).toContainText('AI Prompt Copied!');
  await expect(toast).toContainText('ChatGPT, Claude, Gemini, Grok');
  await expect(toast.getByRole('link', { name: 'Explore Pro AI' })).toBeVisible();
  await page.screenshot({ path: info.outputPath('free-ai-toast.png') });
  await toast.getByRole('button', { name: 'Dismiss' }).click();
  await expect(button).toBeEnabled();
  await button.click();
  await expect(toast.getByRole('status')).toContainText('AI Prompt Copied!');
  await expect(toast.getByRole('link')).toHaveCount(0);
});

test('clipboard failure never announces success @compat', async ({ page }) => {
  await page.evaluate(() => {
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async () => { throw new Error('denied'); } } });
    document.execCommand = () => false;
  });
  await page.locator(`${report} .slimstat-ai-prompt`).click();
  await expect(page.locator(`${report} .slimstat-ai-prompt`)).toContainText('Copy failed');
  await expect(page.locator('#slimstat-ai-toast')).toHaveCount(0);
});

test('disabled AI actions stay aligned with long text, RTL and a narrow viewport @compat', async ({ page }, info) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.evaluate(() => Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai', settings: '/wp-admin/admin.php?page=slimai&tab=settings' }));
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/status'), route => route.fulfill({ json: { enabled: false, saved: null } }));
  const trigger = page.locator(`${report} .slimstat-ai-analyze`);
  await trigger.click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toContainText('AI analysis is disabled');
  const actions = dialog.locator('.ss-ai-actions');
  await expect(actions.getByRole('link')).toHaveCount(1);
  await expect(actions.getByRole('button', { name: 'Copy Prompt Instead' })).toBeVisible();
  for (const dir of ['ltr', 'rtl']) {
    await dialog.evaluate((node, direction) => {
      node.setAttribute('dir', direction);
      node.querySelector('.ss-ai-actions a')!.textContent = 'AI Analysis Settings with a substantially longer translated explanation';
    }, dir);
    const bounds = await actions.evaluate(node => {
      const rect = node.getBoundingClientRect();
      return [...node.children].every(child => { const box = child.getBoundingClientRect(); return box.left >= rect.left - 1 && box.right <= rect.right + 1; });
    });
    expect(bounds).toBe(true);
    await page.screenshot({ path: info.outputPath(`disabled-ai-${dir}.png`) });
  }
  await dialog.getByRole('button', { name: 'Close', exact: true }).click();
  await expect(trigger).toBeFocused();
});

test('Free explanation supports keyboard close and restores focus @compat', async ({ page }) => {
  await page.evaluate(() => { (window as any).SlimStatAdminParams.ai.native = false; });
  const button = page.locator(`${report} .slimstat-ai-analyze`);
  await button.click();
  await expect(page.getByRole('dialog')).toContainText('one-click insights');
  await expect(page.getByRole('dialog').getByRole('link', { name: 'Explore Pro AI' })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(button).toBeFocused();
});

test('copy fallback survives replacement of its original report actions @compat', async ({ page }) => {
  await page.evaluate(() => {
    Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai' });
    document.addEventListener('click', event => {
      if ((event.target as Element).closest('.slimstat-ai-prompt')) (window as any).__fallbackConnected = true;
    });
  });
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/status'), route => route.fulfill({ json: { enabled: false, saved: null } }));
  for (const removeTrigger of [false, true]) {
    const trigger = page.locator(`${report} .slimstat-ai-analyze`);
    await trigger.click();
    await expect(page.getByRole('dialog')).toContainText('AI analysis is disabled');
    await expect(page.locator(`${report} .slimstat-ai-prompt`)).toBeEnabled();
    await trigger.evaluate((node, remove) => {
      (window as any).__fallbackConnected = false;
      const parent = node.parentElement!;
      parent.replaceWith(parent.cloneNode(true));
      if (remove) node.remove();
    }, removeTrigger);
    await page.getByRole('dialog').getByRole('button', { name: 'Copy Prompt Instead' }).click();
    await expect.poll(() => page.evaluate(() => (window as any).__fallbackConnected)).toBe(true);
  }
});

test('native consent, loading, safe results, copy and failed regeneration @compat', async ({ page }, info) => {
  let generated = 0;
  let release: (() => void) | undefined;
  const pending = new Promise<void>(resolve => { release = resolve; });
  await page.evaluate(() => Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai' }));
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/'), async route => {
    const url = decodeURIComponent(route.request().url());
    if (url.includes('/status')) return route.fulfill({ json: { enabled: true, saved: null, matches: false } });
    if (url.includes('/connection')) return route.fulfill({ json: { provider: 'test-provider', model: 'test-model', selection: 'opaque', consented: generated > 0 } });
    generated++;
    if (generated > 1) return route.fulfill({ status: 503, json: { code: 'failed', message: 'Provider unavailable. Your last saved result is unchanged.' } });
    expect(route.request().postDataJSON().consent).toBe(true);
    await pending;
    return route.fulfill({ json: { saved: true, analysis: {
      context: { title: 'At a Glance', from: '2026-01-01', to: '2026-01-31', timezone: 'Europe/Berlin', filters: ['Browser: selected value withheld'] },
      generated: 1793700000, provider: 'test-provider', model: 'test-model',
      content: '## Report Summary\n12 synthetic pageviews.\n## Key Insights\n- Small sample.\n<img src=x onerror="window.__unsafe=true">\n[Unsafe](javascript:alert(1))',
    } } });
  });
  await page.locator(`${report} .slimstat-ai-analyze`).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toContainText('Selected aggregate analytics will be sent');
  expect(generated).toBe(0);
  const run = dialog.getByRole('button', { name: 'Analyze Current Report' });
  await expect(run).toBeDisabled();
  await dialog.getByRole('checkbox').check();
  await run.click();
  await expect(dialog).toContainText('Analyzing your report…');
  await expect(run).toBeDisabled();
  expect(generated).toBe(1);
  release!();
  await expect(dialog).toContainText('12 synthetic pageviews');
  await expect(dialog.locator('.ss-ai-result img, .ss-ai-result script, .ss-ai-result a')).toHaveCount(0);
  expect(await page.evaluate(() => (window as any).__unsafe)).toBeUndefined();
  await dialog.getByRole('button', { name: 'Copy Result' }).click();
  expect(await page.evaluate(() => (window as any).__copied)).toContain('12 synthetic pageviews');
  await page.screenshot({ path: info.outputPath('native-ai-result.png') });
  await dialog.getByRole('button', { name: 'Regenerate Analysis' }).click();
  await expect(dialog.getByRole('alert')).toContainText('Provider unavailable');
  await expect(dialog).toContainText('12 synthetic pageviews');
  expect(generated).toBe(2);
});

test('cached mismatch is free to view and no-provider state offers a fallback @compat', async ({ page }) => {
  const paths: string[] = [];
  await page.evaluate(() => Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai', connectors: '/wp-admin/options-connectors.php' }));
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/'), async route => {
    const url = decodeURIComponent(route.request().url());
    paths.push(url);
    if (url.includes('/connection')) return route.fulfill({ json: { provider: null } });
    return route.fulfill({ json: { enabled: true, matches: false, saved: {
      context: { title: 'At a Glance', from: '2025-01-01', to: '2025-01-31', timezone: 'UTC', filters: [] },
      generated: 1738400000, provider: 'old-provider', model: 'old-model', content: 'An older saved result.',
    } } });
  });
  await page.locator(`${report} .slimstat-ai-analyze`).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toContainText('belongs to another date range');
  await expect(dialog).toContainText('2025-01-01');
  expect(paths).toHaveLength(1);
  await dialog.getByRole('button', { name: 'Analyze Current Report' }).click();
  await expect(dialog).toContainText('Connect AI to unlock instant insights');
  await expect(dialog.getByRole('link', { name: 'Set Up AI' })).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Copy Prompt Instead' })).toBeVisible();
  expect(paths.some(path => path.includes('/analyze'))).toBe(false);
});

test('a changed report selection discards a slower response @compat', async ({ page }) => {
  let release: () => void;
  const pending = new Promise<void>(resolve => { release = resolve; });
  let calls = 0;
  await page.evaluate(() => Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai' }));
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/'), async route => {
    const url = decodeURIComponent(route.request().url());
    if (url.includes('/status')) return route.fulfill({ json: { enabled: true, saved: null } });
    if (url.includes('/connection')) return route.fulfill({ json: { provider: 'test', model: 'test', selection: 'opaque', consented: true } });
    calls++;
    await pending;
    return route.fulfill({ json: { saved: true, analysis: { content: 'Obsolete result must not render' } } });
  });
  await page.locator(`${report} .slimstat-ai-analyze`).click();
  await expect(page.getByRole('dialog')).toContainText('Analyzing your report');
  await page.evaluate(() => { (document.querySelector('.slimstat-date-range-input') as HTMLElement).dataset.start = '2025-01-01'; });
  release!();
  await expect(page.getByRole('dialog').getByRole('alert')).toContainText('report selection changed');
  await expect(page.locator('.ss-ai-result')).toHaveCount(0);
  expect(calls).toBe(1);
});

test('closing during analysis never retries or renders the late response @compat', async ({ page }) => {
  let release: () => void;
  const pending = new Promise<void>(resolve => { release = resolve; });
  let calls = 0;
  await page.evaluate(() => Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai' }));
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/'), async route => {
    const url = decodeURIComponent(route.request().url());
    if (url.includes('/status')) return route.fulfill({ json: { enabled: true, saved: null } });
    if (url.includes('/connection')) return route.fulfill({ json: { provider: 'test', model: 'test', selection: 'opaque', consented: true } });
    calls++;
    await pending;
    return route.fulfill({ json: { saved: true, analysis: { content: 'Late response' } } });
  });
  await page.locator(`${report} .slimstat-ai-analyze`).click();
  await expect(page.getByRole('dialog')).toContainText('Analyzing your report');
  await page.getByRole('dialog').getByRole('button', { name: 'Close', exact: true }).click();
  release!();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(page.locator('.ss-ai-result')).toHaveCount(0);
  expect(calls).toBe(1);
});

test('saved analysis stays accessible on mobile and clipboard fallback works @compat', async ({ page }, info) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.evaluate(() => {
    Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai' });
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
    document.execCommand = command => { (window as any).__fallbackCopy = command; return true; };
  });
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/'), route => route.fulfill({ json: {
    enabled: true, matches: true, saved: {
      context: { title: 'At a Glance', from: '2026-10-01', to: '2026-10-31', timezone: 'Europe/Berlin', filters: [] },
      generated: 1793700000, provider: 'Test provider', model: 'Text model',
      content: '## Report Summary\nThis sample contains 12 pageviews.\n## Key Insights\n- The sample is small.\n## Recommended Actions\n- Review a longer date range.\n## Data Limitations\nThese figures do not establish a trend.',
    },
  } }));
  await page.locator(`${report} .slimstat-ai-analyze`).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toContainText('Report Summary');
  expect(await dialog.evaluate(node => node.scrollWidth <= node.clientWidth)).toBe(true);
  await dialog.getByRole('button', { name: 'Copy Result' }).click();
  expect(await page.evaluate(() => (window as any).__fallbackCopy)).toBe('copy');
  await expect(page.locator('#slimstat-ai-toast')).toContainText('Analysis copied');
  await page.screenshot({ path: info.outputPath('native-ai-mobile.png') });
  await page.setViewportSize({ width: 1440, height: 1100 });
  await page.locator('#slimstat-ai-toast').getByRole('button', { name: 'Dismiss' }).click();
  await page.screenshot({ path: info.outputPath('native-ai-desktop.png') });
});
