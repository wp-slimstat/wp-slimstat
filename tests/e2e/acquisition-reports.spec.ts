import { test, expect } from '@playwright/test';
import mysql from 'mysql2/promise';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BASE_URL, MYSQL_CONFIG, assertSafeTestDatabase, AUTHOR_USER, ADMIN_USER } from './helpers/env';
import { snapshotSlimstatOptions, restoreSlimstatOptions, snapshotOption, restoreOption, setSlimstatOption, clearStatsTable, closeDb } from './helpers/setup';

const here = path.dirname(fileURLToPath(import.meta.url));
let db: mysql.Pool;

async function stat(marker: string): Promise<any> {
  const [rows] = await db.execute<mysql.RowDataPacket[]>('SELECT * FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1', [`%${marker}%`]);
  // mysql2 returns VARBINARY as Buffer; WordPress/mysqli returns the same UTF-8 bytes as strings.
  return rows[0] ? Object.fromEntries(Object.entries(rows[0]).map(([key, value]) => [key, Buffer.isBuffer(value) ? value.toString('utf8') : value])) : null;
}

async function fixture() {
  const [options] = await db.query<mysql.RowDataPacket[]>("SELECT option_value FROM wp_options WHERE option_name='gmt_offset'");
  const now = Math.floor(Date.now() / 1000) + Number(options[0]?.option_value ?? 0) * 3600;
  for (const [channel, source, campaign, author, agent] of [
    ['email', 'newsletter', 'Spring + 20%', AUTHOR_USER, 'Mozilla/5.0'],
    ['email', 'newsletter', 'Spring + 20%', AUTHOR_USER, 'Mozilla/5.0'],
    ['paid_search', 'google', 'Search', AUTHOR_USER, 'Mozilla/5.0'],
    ['ai_assistant', 'chatgpt.com', null, AUTHOR_USER, 'Mozilla/5.0'],
    ['ai_fetcher', 'chatgpt-user', 'Bot campaign', AUTHOR_USER, 'ChatGPT-User/1.0'],
    ['internal', null, null, AUTHOR_USER, 'Mozilla/5.0'],
    [null, null, null, AUTHOR_USER, 'Mozilla/5.0'],
    ['email', 'private', 'Private campaign', 'another_author', 'Mozilla/5.0'],
  ]) {
    await db.execute('INSERT INTO wp_slim_stats (dt, resource, traffic_channel, traffic_source, utm_campaign, utm_source, utm_medium, author, user_agent) VALUES (?,?,?,?,?,?,?,?,?)',
      [now, '/acquisition-fixture', channel, source, campaign, campaign ? source : null, campaign ? (channel === 'paid_search' ? 'cpc' : 'email') : null, author, agent]);
  }
}

test.describe('UTM and channel reports', () => {
  test.beforeAll(() => {
    assertSafeTestDatabase();
    db = mysql.createPool(MYSQL_CONFIG);
  });
  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'ignore_wp_users', 'off');
    await setSlimstatOption(page, 'ignore_bots', 'off');
    await setSlimstatOption(page, 'ignore_capabilities', '');
    await setSlimstatOption(page, 'ignore_users', '');
    await setSlimstatOption(page, 'rows_to_show', '20');
    await setSlimstatOption(page, 'async_load', 'on');
  });
  test.afterEach(async () => { await restoreSlimstatOptions(); });
  test.afterAll(async () => { await db.end(); await closeDb(); });

  test('builder links retain all six tags through real tracking and the UTM report', async ({ page, browser }) => {
    await setSlimstatOption(page, 'javascript_mode', 'on');
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview5#slimstat-utm-builder`, { waitUntil: 'domcontentloaded' });
    await page.locator('.slimstat-utm-builder__extra > summary').click();
    const marker = `builder-${Date.now()}`;
    const tags = { utm_source: 'Newsletter & café', utm_medium: 'email', utm_campaign: marker, utm_id: '0', utm_term: '東京 + 20%', utm_content: '&amp; \\ header' };
    await page.locator('[name="website"]').fill(`${BASE_URL}/?acq=${marker}&keep=a%20b#details`);
    for (const [key, value] of Object.entries(tags)) await page.locator(`[name="${key}"]`).fill(value);
    const url = await page.locator('#slimstat-utm-result').inputValue();
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      const visitor = await context.newPage();
      await visitor.goto(url);
      await expect.poll(async () => (await stat(marker))?.utm_campaign).toBe(marker);
      expect(await stat(marker)).toMatchObject({ ...tags, traffic_channel: 'email' });
      await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview5&fs[utm_campaign]=equals+${marker}`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('#slim_p3_04')).toContainText(marker);
      await expect(page.locator('#slim_p3_04 .slimstat-acquisition__intro')).toContainText('1 pageview');
    } finally { await context.close(); }
  });

  for (const transport of ['rest', 'ajax']) {
    test(`${transport} tracking preserves encoded campaign values and zero`, async ({ page, browser }) => {
      await setSlimstatOption(page, 'javascript_mode', 'on');
      await setSlimstatOption(page, 'tracking_request_method', transport);
      const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
      try {
        const visitor = await context.newPage();
        const marker = `acq-${transport}-${Date.now()}`;
        await visitor.goto(`${BASE_URL}/?acq=${marker}&utm_source=A%26B&utm_medium=Email&utm_campaign=Summer%2B2026&utm_content=0&utm_term=caf%C3%A9&utm_id=%2520`);
        await expect.poll(async () => (await stat(marker))?.utm_campaign).toBe('Summer+2026');
        const row = await stat(marker);
        expect(row).toMatchObject({ traffic_channel: 'email', utm_source: 'A&B', utm_medium: 'Email', utm_content: '0', utm_term: 'café', utm_id: '%20' });
        const zero = `${marker}-zero`;
        await visitor.goto(`${BASE_URL}/?acq=${zero}&utm_source=0&utm_medium=email`);
        await expect.poll(async () => (await stat(zero))?.traffic_source).toBe('0');
        const direct = `${marker}-direct`;
        const landing = await context.newPage();
        await landing.goto(`${BASE_URL}/?acq=${direct}`);
        await expect.poll(async () => (await stat(direct))?.traffic_channel).toBe('direct');
        const internal = `${marker}-internal`;
        await landing.evaluate(url => {
          const link = document.createElement('a');
          link.id = 'acquisition-internal-link';
          link.href = url;
          link.textContent = 'Continue';
          document.body.append(link);
        }, `${BASE_URL}/?acq=${internal}`);
        await landing.locator('#acquisition-internal-link').click();
        await expect.poll(async () => (await stat(internal))?.traffic_channel).toBe('internal');
      } finally { await context.close(); }
    });
  }

  test('server tracking separates AI referrals, fetches, internal navigation and bot exclusions', async ({ page, browser }) => {
    await setSlimstatOption(page, 'javascript_mode', 'off');
    for (const [agent, ref, channel] of [
      ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36', 'https://chatgpt.com/', 'ai_assistant'],
      ['ChatGPT-User/1.0', 'https://chatgpt.com/', 'ai_fetcher'],
      ['Claude-SearchBot/1.0', '', 'ai_crawler'],
      ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36', `${BASE_URL}/previous`, 'internal'],
    ]) {
      const context = await browser.newContext({ javaScriptEnabled: false, userAgent: agent, storageState: { cookies: [], origins: [] } });
      try {
        const visitor = await context.newPage();
        const marker = `acq-${channel}-${Date.now()}`;
        await visitor.goto(`${BASE_URL}/?acq=${marker}`, ref ? { referer: ref } : {});
        await expect.poll(async () => (await stat(marker))?.traffic_channel).toBe(channel);
      } finally { await context.close(); }
    }
    await setSlimstatOption(page, 'ignore_bots', 'on');
    const context = await browser.newContext({ javaScriptEnabled: false, userAgent: 'Claude-User/1.0', storageState: { cookies: [], origins: [] } });
    try {
      const marker = `acq-excluded-${Date.now()}`;
      await (await context.newPage()).goto(`${BASE_URL}/?acq=${marker}`);
      expect(await stat(marker)).toBeNull();
    } finally { await context.close(); }
  });

  test('reports render exact counts, preserve filters and export through Pro when installed', async ({ page }) => {
    await fixture();
    await db.execute("UPDATE wp_slim_stats SET utm_id='0', utm_content='%20' WHERE utm_campaign='Spring + 20%'");
    await page.goto('/wp-admin/admin.php?page=slimview5&type=today');
    const channels = page.locator('#slim_p3_03');
    const utm = page.locator('#slim_p3_04');
    await expect(channels.locator('.slimstat-acquisition__group')).toHaveCount(6);
    await expect(channels.locator('.slimstat-acquisition__group[open]')).toHaveCount(0);
    await expect(channels.locator('.slimstat-acquisition__intro strong')).toHaveText('8 pageviews');
    await expect(channels.locator('.slimstat-acquisition__label').filter({ hasText: /^Unassigned$/ })).toBeVisible();
    await expect(channels.locator('.slimstat-acquisition__label').filter({ hasText: /^AI User-requested Fetches$/ })).toBeVisible();
    await expect(utm.locator('tbody tr')).toHaveCount(3);
    await expect(utm.locator('.slimstat-acquisition__intro strong')).toHaveText('4 pageviews');
    const campaign = utm.locator('.slimstat-acquisition__group').filter({ hasText: 'Spring + 20%' });
    await expect(campaign.locator('table')).not.toBeVisible();
    await campaign.locator(':scope > summary').focus();
    await page.keyboard.press('Enter');
    await expect(campaign.locator('table')).toBeVisible();
    await expect(campaign.locator('thead th')).toHaveText(['Source', 'Medium', 'Pageviews', 'Share']);
    await expect(campaign).toContainText('50.0%');
    const tags = campaign.locator('.slimstat-acquisition__tags');
    await expect(tags.locator('dl')).not.toBeVisible();
    await tags.locator('summary').focus();
    await page.keyboard.press('Enter');
    await expect(tags.locator('dl')).toBeVisible();
    await expect(tags.getByRole('link', { name: '0', exact: true })).toBeVisible();
    await expect(tags.getByRole('link', { name: '%20', exact: true })).toBeVisible();
    await utm.scrollIntoViewIfNeeded();
    await expect(utm.locator('.inside')).toHaveCSS('opacity', '1');
    await page.screenshot({ path: 'tests/e2e/run-artifacts/acquisition-desktop.png', fullPage: true });
    await campaign.getByRole('link', { name: 'Filter by this campaign', exact: true }).click();
    await expect(utm.locator('tbody tr')).toHaveCount(1);
    await expect(utm.locator('.slimstat-acquisition__intro strong')).toHaveText('2 pageviews');
    await expect(utm.locator('.slimstat-acquisition__group > summary')).toContainText('100.0%');
    const probe = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, { form: { action: 'e2e_get_slimstat_version' } });
    const { data } = await probe.json();
    // Pro is optional; with it installed this same flow must export the displayed segment.
    if (data.pro_installed) {
      expect(data.pro_booted).toBeTruthy();
      const downloading = page.waitForEvent('download');
      await utm.locator('.button-export-to-xls').click();
      const download = await downloading;
      const stream = await download.createReadStream();
      const chunks = [];
      for await (const chunk of stream!) chunks.push(chunk);
      const csv = Buffer.concat(chunks).toString('utf8');
      expect(csv).toContain('UTM Campaign');
      expect(csv).toMatch(/"Spring \+ 20%",newsletter,email,%20,,0,2/);
      expect(csv).not.toContain('Private campaign');
      expect(csv).not.toContain('Bot campaign');
      expect(csv).not.toContain('Search');
    }
  });

  test('collapsed totals combine sources and remain exact when breakdowns reach the result limit', async ({ page }) => {
    await fixture();
    await db.execute("UPDATE wp_slim_stats SET utm_campaign='Spring + 20%' WHERE utm_campaign='Search'");
    await setSlimstatOption(page, 'limit_results', '1');
    await page.goto('/wp-admin/admin.php?page=slimview5&type=today');
    const utm = page.locator('#slim_p3_04');
    const group = utm.locator('.slimstat-acquisition__group');
    await expect(group).toHaveCount(1);
    await expect(group.locator(':scope > summary .slimstat-acquisition__number')).toHaveText('3 Pageviews');
    await expect(group.locator(':scope > summary')).toContainText('75.0%');
    await expect(group.locator('table')).not.toBeVisible();
    await group.locator(':scope > summary').click();
    await expect(group.locator('table')).toBeVisible();
    await expect(group).toContainText('Showing 2 of 3 pageviews');
    await group.locator(':scope > summary').click();
    await expect(group.locator('table')).not.toBeVisible();
    const email = page.locator('#slim_p3_03 .slimstat-acquisition__group');
    await expect(email.locator(':scope > summary .slimstat-acquisition__number')).toHaveText('3 Pageviews');
    await email.locator(':scope > summary').click();
    await expect(email).toContainText('Showing 2 of 3 pageviews');
  });

  test('small screens, RTL, keyboard help and pagination remain usable', async ({ page }) => {
    await fixture();
    await setSlimstatOption(page, 'rows_to_show', '2');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/wp-admin/admin.php?page=slimview5&type=today');
    const utm = page.locator('#slim_p3_04');
    await expect(utm.locator('tbody tr')).toHaveCount(2);
    await utm.getByRole('link', { name: 'Next', exact: true }).click();
    await expect(utm.locator('tbody tr')).toHaveCount(1);
    await expect(utm.locator('.slimstat-acquisition__groups')).toContainText('Search');
    const help = utm.locator('.slimstat-acquisition__help summary');
    await help.focus();
    await page.keyboard.press('Enter');
    await expect(utm.locator('.slimstat-acquisition__help')).toHaveAttribute('open', '');
    await page.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
    await utm.scrollIntoViewIfNeeded();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBeTruthy();
    await page.screenshot({ path: 'tests/e2e/run-artifacts/acquisition-mobile-rtl.png', fullPage: true });
  });

  test('date picker and saved segments retain exact campaign filters across ranges', async ({ page }) => {
    await fixture();
    await snapshotOption('slimstat_filters');
    const source = 'A&B + %20 = x &&& y "quoted" &amp; \\ literal';
    try {
      await db.execute("UPDATE wp_slim_stats SET utm_source=? WHERE utm_source='newsletter'", [source]);
      await db.execute("INSERT INTO wp_slim_stats (dt, resource, traffic_channel, traffic_source, utm_campaign, utm_source, utm_medium) SELECT MAX(dt)-86400, '/yesterday', 'email', 'archive', 'Yesterday only', ?, 'email' FROM wp_slim_stats", [source]);
      await page.goto('/wp-admin/admin.php?page=slimview5&type=today');
      const utm = page.locator('#slim_p3_04');
      await expect(utm.locator('.slimstat-acquisition__intro strong')).toHaveText('4 pageviews');
      await utm.locator('.slimstat-acquisition__group').filter({ hasText: 'Spring + 20%' }).locator(':scope > summary').click();
      await expect(utm.getByRole('link', { name: source, exact: true })).toBeVisible();
      await page.locator('#slimstat-filter-name').selectOption('utm_source', { force: true });
      await page.locator('#slimstat-filter-operator').selectOption('equals', { force: true });
      await page.locator('#slimstat-filter-value').fill(source);
      await page.locator('#slimstat-filters input[type=submit]').click();
      await expect(utm.locator('.slimstat-acquisition__intro strong')).toHaveText('2 pageviews');
      await page.locator('#slimstat-save-filter').click();
      await expect(page.locator('#slimstat-save-filter')).toHaveText(/Saved|Already saved/);

      await page.locator('.slimstat-date-range-btn').click();
      await page.locator('.daterangepicker:visible .ranges li').filter({ hasText: /^Yesterday$/ }).click();
      await expect(utm.locator('.slimstat-acquisition__groups')).toContainText('Yesterday only');
      await expect(utm.locator('.slimstat-acquisition__intro strong')).toHaveText('1 pageview');
      await expect(page.locator('#slimstat-current-filters')).toContainText(source);

      // Clear dimensions but keep yesterday, then load the saved segment through its dialog.
      await page.goto('/wp-admin/admin.php?page=slimview5&type=yesterday');
      await page.locator('#slimstat-load-saved-filters').click();
      await page.locator('#slim_filters_overlay a.slimstat-filter-link').filter({ hasText: source }).click();
      await expect(utm.locator('.slimstat-acquisition__groups')).toContainText('Yesterday only');
      await expect(utm.locator('.slimstat-acquisition__intro strong')).toHaveText('1 pageview');
      await expect(page.locator('#slimstat-current-filters')).toContainText(source);

      // A custom date range uses the real picker's Apply event and retains the POST segment.
      await page.locator('.slimstat-date-range-btn').click();
      await page.evaluate(() => {
        const picker = (window as any).jQuery('.slimstat-date-range-input').data('daterangepicker');
        picker.setEndDate((window as any).moment());
        picker.chosenLabel = 'Custom Range';
        picker.clickApply();
      });
      await expect(utm.locator('.slimstat-acquisition__intro strong')).toHaveText('3 pageviews');
      await expect(page.locator('#slimstat-current-filters')).toContainText(source);
    } finally { await restoreOption('slimstat_filters'); }
  });

  test('empty state explains tagging and stored markup remains inert', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=slimview5&type=today');
    const utm = page.locator('#slim_p3_04');
    await expect(utm).toContainText('No tagged pageviews');
    await fixture();
    await db.execute("UPDATE wp_slim_stats SET utm_campaign=? WHERE utm_campaign='Search'", ['<img src=x onerror=window.acqXss=1>']);
    await page.reload();
    await expect(utm).toContainText('<img src=x onerror=window.acqXss=1>');
    expect(await utm.locator('tbody img').count()).toBe(0);
    expect(await page.evaluate(() => (window as any).acqXss)).toBeUndefined();
    // Drilldowns and saved segments must set input values as text, never parse them as HTML.
    for (const replace of [false, true]) {
      const value = 'equals "><img src=x onerror=window.acqXss=1>';
      await page.evaluate(({ value, replace }) => {
        const url = new URL(window.location.href);
        url.searchParams.set('fs[utm_campaign]', value);
        (window as any).SlimStatAdmin.add_url_filters_to_form(url.href, replace);
      }, { value, replace });
      await expect(page.locator('#slimstat-filters-form input[name="fs[utm_campaign]"]')).toHaveValue(value);
      expect(await page.locator('#slimstat-filters-form img').count()).toBe(0);
      expect(await page.evaluate(() => (window as any).acqXss)).toBeUndefined();
    }
  });

  test('Customizer can move the reports to Overview and the WordPress Dashboard', async ({ page }) => {
    await fixture();
    const [users] = await db.execute<mysql.RowDataPacket[]>('SELECT ID FROM wp_users WHERE user_login=?', [ADMIN_USER]);
    const id = users[0].ID;
    const pattern = '%meta-box-order_%slimlayout%';
    const [saved] = await db.execute<mysql.RowDataPacket[]>('SELECT meta_key, meta_value FROM wp_usermeta WHERE user_id=? AND meta_key LIKE ?', [id, pattern]);
    try {
      await page.goto('/wp-admin/admin.php?page=slimlayout');
      await expect(page.locator('#slim_p3_03')).toBeVisible();
      await expect(page.locator('#slim_p3_04')).toBeVisible();
      const savedResponse = page.waitForResponse(r => (r.request().postData() ?? '').includes('action=meta-box-order'));
      await page.evaluate(() => {
        const $ = (window as any).jQuery;
        $('#slim_p3_03').appendTo('#slimview1-sortables');
        $('#slim_p3_04').appendTo('#dashboard-sortables');
        // Invoke the real sortable stop handler; persistence is the standard Customizer AJAX.
        const target = $('#slimview1-sortables');
        target.sortable('option', 'stop').call(target[0]);
      });
      expect((await savedResponse).ok()).toBeTruthy();
      await page.goto('/wp-admin/admin.php?page=slimview1&type=today');
      await expect(page.locator('#slim_p3_03 .slimstat-acquisition__intro strong')).toHaveText('8 pageviews');
      await page.goto('/wp-admin/index.php');
      await expect(page.locator('#slim_p3_04 tbody tr')).toHaveCount(3);
      await expect(page.locator('#wp-slimstat-acquisition-css')).toBeAttached();
      await page.locator('#slim_p3_04').evaluate(element => { element.style.width = '300px'; });
      expect(await page.locator('#slim_p3_04 .slimstat-acquisition__groups').evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBeTruthy();
      expect(await page.locator('#slim_p3_04 .inside').evaluate(element => element.scrollHeight <= element.clientHeight + 1)).toBeTruthy();
    } finally {
      await db.execute('DELETE FROM wp_usermeta WHERE user_id=? AND meta_key LIKE ?', [id, pattern]);
      for (const row of saved) await db.execute('INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (?, ?, ?)', [id, row.meta_key, row.meta_value]);
    }
  });


  test('author scope applies and report AJAX rejects missing authentication or nonce', async ({ page, browser }) => {
    await fixture();
    await setSlimstatOption(page, 'restrict_authors_view', 'on');
    await setSlimstatOption(page, 'capability_can_view', 'read');
    const author = await browser.newContext({ storageState: path.join(here, '.auth/author.json') });
    try {
      const authorPage = await author.newPage();
      await authorPage.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview5&type=today`);
      const utm = authorPage.locator('#slim_p3_04');
      await expect(utm.locator('.slimstat-acquisition__intro strong')).toHaveText('3 pageviews');
      await expect(utm).not.toContainText('Private campaign');
      await expect(utm.getByRole('link', { name: 'View linked orders' })).toHaveCount(0);
    } finally { await author.close(); }
    const badNonce = await page.request.post('/wp-admin/admin-ajax.php', { form: { action: 'slimstat_load_report', report_id: 'slim_p3_04', security: 'invalid' } });
    expect(badNonce.status()).toBe(403);
    const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      const response = await anonymous.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, { form: { action: 'slimstat_load_report', report_id: 'slim_p3_03' } });
      expect(await response.text()).not.toContain('Private campaign');
      expect(response.status()).toBe(400);
    } finally { await anonymous.close(); }
  });
});
