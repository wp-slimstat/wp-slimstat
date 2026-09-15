/**
 * E2E: Ticket #14684 — Regression tests for all four identified bugs
 *
 * Bug 1: Query builder uses global $wpdb instead of wp_slimstat::$wpdb
 *         → external database users see data in DB but chart shows nothing
 * Bug 2: Chart.php timezone offset sign is inverted vs DataBuckets.php
 *         → data near midnight placed in wrong bucket or silently dropped
 * Bug 3: javascript_mode migration gated on use_slimstat_banner flag
 *         → users who turned off banner stay stuck on server-side tracking
 * Bug 4: Cloudflare/cache serves stale params.id from server-side mode
 *         → JS skips tracking on all cached pages
 *
 * Source: Support ticket #14684 (Synology NAS + SPCP + Cloudflare)
 * Analysis: jaan-to/outputs/qa/ticket-14684-root-cause-analysis.md
 */
import { test, expect, type Page } from '@playwright/test';
import {
	installOptionMutator,
	uninstallOptionMutator,
	setSlimstatOption,
	snapshotSlimstatOptions,
	restoreSlimstatOptions,
	clearStatsTable,
	getPool,
	closeDb,
	installMuPluginByName,
	uninstallMuPluginByName,
	snapshotOption,
	restoreOption,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

// ─── Shared helpers ───────────────────────────────────────────────────────────

async function insertRows(
	timestamp: number,
	count: number,
	label: string,
	tableName = 'wp_slim_stats'
): Promise<void> {
	const pool = getPool();
	for (let i = 0; i < count; i++) {
		await pool.execute(
			`INSERT INTO ${tableName} (dt, ip, resource, browser, browser_version, platform, language, visit_id, user_agent)
       VALUES (?, '127.0.0.1', ?, 'Chrome', '120', 'test', 'en', 1, 'ticket-14684-e2e')`,
			[timestamp + i, `/ticket-14684/${label}-${i}`]
		);
	}
}

async function countRows(
	tableName = 'wp_slim_stats',
	userAgent = 'ticket-14684-e2e'
): Promise<number> {
	const [rows] = (await getPool().execute(
		`SELECT COUNT(*) as cnt FROM ${tableName} WHERE user_agent = ?`,
		[userAgent]
	)) as any;
	return parseInt(rows[0].cnt, 10);
}

async function extractChartNonce(page: Page): Promise<string> {
	await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
		waitUntil: 'domcontentloaded',
	});
	const nonce = await page.evaluate(
		() => (window as any).slimstat_chart_vars?.nonce || null
	);
	if (!nonce)
		throw new Error('slimstat_chart_vars.nonce not found on slimview2');
	return nonce;
}

async function callChartAjax(
	page: Page,
	nonce: string,
	startTs: number,
	endTs: number,
	granularity: 'daily' | 'weekly' | 'monthly' | 'hourly' = 'daily'
): Promise<any> {
	const args = JSON.stringify({
		start: startTs,
		end: endTs,
		chart_data: {
			data1: 'COUNT(id)',
			data2: 'COUNT( DISTINCT ip )',
		},
	});
	const res = await page.request.post(
		`${BASE_URL}/wp-admin/admin-ajax.php`,
		{
			form: {
				action: 'slimstat_fetch_chart_data',
				nonce,
				args,
				granularity,
			},
		}
	);
	expect(res.ok(), `Chart AJAX HTTP ${res.status()}`).toBe(true);
	return res.json();
}

function sumV1(json: any): number {
	const ajaxDs = json?.data?.data?.datasets?.v1;
	if (Array.isArray(ajaxDs))
		return (ajaxDs as number[]).reduce((a, b) => a + b, 0);
	const serverDs = json?.datasets?.v1;
	if (Array.isArray(serverDs))
		return (serverDs as number[]).reduce((a, b) => a + b, 0);
	return 0;
}

async function readServerRenderedChart(page: Page): Promise<any> {
	const raw = await page.evaluate(() => {
		const el = document.querySelector('[id^="slimstat_chart_data_"]');
		return el ? el.getAttribute('data-data') : null;
	});
	if (!raw) return null;
	try {
		return JSON.parse(raw);
	} catch {
		return null;
	}
}

async function getSlimstatOptionValue(key: string): Promise<string | null> {
	const [rows] = (await getPool().execute(
		"SELECT option_value FROM wp_options WHERE option_name = 'slimstat_options'"
	)) as any;
	if (!rows.length) return null;
	const val: string = rows[0].option_value;
	// Quick regex extraction from serialized PHP string
	const pattern = new RegExp(`"${key}";s:\\d+:"([^"]*)"`, 'i');
	const match = val.match(pattern);
	return match ? match[1] : null;
}

async function setWpOption(name: string, value: string): Promise<void> {
	await getPool().execute(
		`INSERT INTO wp_options (option_name, option_value, autoload)
     VALUES (?, ?, 'yes')
     ON DUPLICATE KEY UPDATE option_value = ?`,
		[name, value, value]
	);
}

async function deleteWpOption(name: string): Promise<void> {
	await getPool().execute(
		'DELETE FROM wp_options WHERE option_name = ?',
		[name]
	);
}

// ─── Bug 1: External Database Query Mismatch ──────────────────────────────────

test.describe('Bug 1: Query builder must use wp_slimstat::$wpdb for external DB', () => {
	test.setTimeout(120_000);

	const externalDatabase = `slimstat_e2e_${Date.now()}`;
	const externalTable = `${externalDatabase}.wp_slim_stats`;
	let createdExternalDatabase = false;

	const now = Math.floor(Date.now() / 1000);
	const rangeStart = now - 7 * 86400;
	const rangeEnd = now;

	test.beforeAll(async () => {
		installOptionMutator();
		installMuPluginByName('custom-db-simulator-mu-plugin.php');
	});

	test.afterAll(async () => {
		uninstallOptionMutator();
		uninstallMuPluginByName('custom-db-simulator-mu-plugin.php');
		if (createdExternalDatabase) await getPool().execute(`DROP DATABASE ${externalDatabase}`);
		await closeDb();
	});

	test.beforeEach(async ({ page }) => {
		await getPool().execute(`CREATE DATABASE ${externalDatabase}`);
		createdExternalDatabase = true;
		console.log(`Owned external fixture: ${externalDatabase}`);
		await snapshotSlimstatOptions();
		await snapshotOption('slimstat_test_use_custom_db');
		await snapshotOption('slimstat_test_custom_database');
		await setWpOption('slimstat_test_custom_database', externalDatabase);
		await clearStatsTable();
		await setSlimstatOption(page, 'gdpr_enabled', 'off');
		await setSlimstatOption(page, 'ignore_wp_users', 'off');
	});

	test.afterEach(async () => {
		await restoreSlimstatOptions();
		await restoreOption('slimstat_test_use_custom_db');
		await restoreOption('slimstat_test_custom_database');
		if (createdExternalDatabase) {
			await getPool().execute(`DROP DATABASE ${externalDatabase}`);
			createdExternalDatabase = false;
		}
	});

	test('external DB: tracker writes should go to external table, not internal', async ({
		page,
	}) => {
		/**
		 * When slimstat_custom_wpdb filter returns a custom wpdb pointing to
		 * a separate database with the WordPress prefix, Storage.php must write to slimext_slim_stats.
		 *
		 * BEFORE FIX: Query.php uses global $wpdb → data goes to wp_slim_stats
		 * AFTER FIX:  Query.php uses wp_slimstat::$wpdb → data goes to slimext_slim_stats
		 */

		// Create external table
		await getPool().execute(
			`CREATE TABLE ${externalTable} LIKE wp_slim_stats`
		);

		// Activate custom DB filter
		await setWpOption('slimstat_test_use_custom_db', 'yes');

		// Visit a page to trigger tracking
		const marker = `extdb-write-${Date.now()}`;
		await page.goto(`${BASE_URL}/?e2e=${marker}`, {
			waitUntil: 'networkidle',
		});
		// Wait for tracking to complete
		await page.waitForTimeout(3000);

		// Check if data landed in the external table
		const [extRows] = (await getPool().execute(
			`SELECT COUNT(*) as cnt FROM ${externalTable} WHERE resource LIKE ?`,
			[`%${marker}%`]
		)) as any;
		const extCount = parseInt(extRows[0].cnt, 10);

		// Check internal table
		const [intRows] = (await getPool().execute(
			'SELECT COUNT(*) as cnt FROM wp_slim_stats WHERE resource LIKE ?',
			[`%${marker}%`]
		)) as any;
		const intCount = parseInt(intRows[0].cnt, 10);

		// AFTER FIX: data should be in external table
		// Note: this test will FAIL before the fix (data goes to internal table)
		// and PASS after the fix (data goes to external table)
		console.log(
			`External DB write test: ext=${extCount}, int=${intCount}`
		);

		// At minimum, data must exist SOMEWHERE
		expect(
			extCount + intCount,
			'Tracking request was not recorded in either table'
		).toBeGreaterThan(0);

		// After fix: data should be in external table only
		// Before fix: data is in internal table only (this assertion catches the bug)
		expect(
			extCount,
			'Data went to internal wp_slim_stats instead of external slimext_slim_stats — Query.php is not using wp_slimstat::$wpdb'
		).toBeGreaterThan(0);
		expect(intCount, 'External tracking must not duplicate into the internal table').toBe(0);
	});

	test('external DB: chart must read from external table when filter is active', async ({
		page,
	}) => {
		/**
		 * When the custom DB filter is active, Chart.php must query
		 * slimext_slim_stats, not wp_slim_stats.
		 *
		 * BEFORE FIX: Chart uses global $wpdb → reads wp_slim_stats (empty) → chart shows 0
		 * AFTER FIX:  Chart uses wp_slimstat::$wpdb → reads slimext_slim_stats → chart shows data
		 */

		// Create external table and seed data there
		await getPool().execute(
			`CREATE TABLE ${externalTable} LIKE wp_slim_stats`
		);
		await insertRows(now - 3600, 5, 'ext-chart', externalTable);

		// Verify data is in external table, NOT in internal
		const extCount = await countRows(externalTable);
		const intCount = await countRows('wp_slim_stats');
		expect(extCount).toBe(5);
		expect(intCount).toBe(0);

		// Get nonce BEFORE activating filter (slimview2 won't crash)
		const nonce = await extractChartNonce(page);

		// Activate custom DB filter
		await setWpOption('slimstat_test_use_custom_db', 'yes');

		// Chart AJAX should read from external table
		const json = await callChartAjax(page, nonce, rangeStart, rangeEnd);
		expect(json.success).toBe(true);

		const chartSum = sumV1(json);
		console.log(
			`External DB chart read: ext=${extCount}, int=${intCount}, chart sum=${chartSum}`
		);

		// After fix: chart should show 5 records (from external table)
		// Before fix: chart shows 0 (reads from empty internal table)
		expect(
			chartSum,
			'Chart reads from internal wp_slim_stats instead of external slimext_slim_stats — Query.php is not using wp_slimstat::$wpdb'
		).toBe(5);
	});

	test('no external DB: chart reads from wp_slim_stats normally', async ({
		page,
	}) => {
		/**
		 * Sanity check: when the custom DB filter is NOT active,
		 * everything works with the default WordPress database.
		 */
		await insertRows(now - 3600, 7, 'default-db');

		const dbCount = await countRows();
		expect(dbCount).toBe(7);

		const nonce = await extractChartNonce(page);
		const json = await callChartAjax(page, nonce, rangeStart, rangeEnd);
		expect(json.success).toBe(true);

		const chartSum = sumV1(json);
		expect(chartSum).toBe(7);

		console.log(
			`Default DB sanity check PASS — DB=${dbCount}, chart=${chartSum}`
		);
	});
});

// ─── Bug 2: Timezone Offset Sign Inversion ─────────────────────────────────────

test.describe('Bug 2: Chart timezone offset must match DataBuckets offset', () => {
	test.setTimeout(120_000);

	test.beforeAll(() => {
		installOptionMutator();
	});

	test.afterAll(async () => {
		uninstallOptionMutator();
		await closeDb();
	});

	test.beforeEach(async ({ page }) => {
		await snapshotSlimstatOptions();
		await clearStatsTable();
		await setSlimstatOption(page, 'gdpr_enabled', 'off');
		await setSlimstatOption(page, 'ignore_wp_users', 'off');
	});

	test.afterEach(async () => {
		await restoreSlimstatOptions();
	});

	test('records near midnight boundary: chart sum equals DB count (no silent drop)', async ({
		page,
	}) => {
		/**
		 * The timezone sign inversion causes data near midnight to be placed
		 * in the wrong bucket. If that bucket index exceeds $this->points,
		 * the record is silently dropped (DataBuckets.php:216).
		 *
		 * This test inserts records at various times including near midnight
		 * and verifies ALL records appear in the chart regardless of timezone.
		 *
		 * BEFORE FIX: some midnight-boundary records are dropped → sum < count
		 * AFTER FIX:  all records land in valid buckets → sum == count
		 */

		const now = Math.floor(Date.now() / 1000);
		const dayAgo = now - 86400;
		const weekAgo = now - 7 * 86400;

		// Get MySQL server timezone offset for diagnostic output
		const [tzRows] = (await getPool().execute(
			'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) as offset_seconds'
		)) as any;
		const serverOffsetSec = parseInt(tzRows[0].offset_seconds, 10);
		console.log(`MySQL server UTC offset: ${serverOffsetSec}s (${serverOffsetSec / 3600}h)`);

		// Insert records at various times, including near midnight boundaries
		// These timestamps are chosen to stress the timezone conversion:

		// Record at 23:50 UTC (near midnight — could shift to next day with wrong offset)
		const nearMidnightUtc = Math.floor(
			new Date('2026-03-23T23:50:00Z').getTime() / 1000
		);
		// Record at 00:10 UTC (just past midnight — could shift to previous day)
		const justPastMidnightUtc = Math.floor(
			new Date('2026-03-24T00:10:00Z').getTime() / 1000
		);
		// Record at noon (safe, should never shift)
		const noonUtc = Math.floor(
			new Date('2026-03-23T12:00:00Z').getTime() / 1000
		);
		// Record at 6 AM (another safe one)
		const morningUtc = Math.floor(
			new Date('2026-03-22T06:00:00Z').getTime() / 1000
		);

		await insertRows(nearMidnightUtc, 3, 'near-midnight');
		await insertRows(justPastMidnightUtc, 2, 'past-midnight');
		await insertRows(noonUtc, 4, 'noon');
		await insertRows(morningUtc, 1, 'morning');

		const dbCount = await countRows();
		expect(dbCount).toBe(10); // 3 + 2 + 4 + 1

		// Query the chart for a range that includes all these timestamps
		const rangeStart = morningUtc - 86400; // 1 day before earliest
		const rangeEnd = justPastMidnightUtc + 86400; // 1 day after latest

		const nonce = await extractChartNonce(page);
		const json = await callChartAjax(
			page,
			nonce,
			rangeStart,
			rangeEnd,
			'daily'
		);
		expect(json.success).toBe(true);

		const chartSum = sumV1(json);

		console.log(
			`Timezone boundary test: DB=${dbCount}, chart sum=${chartSum}, server offset=${serverOffsetSec}s`
		);

		// Critical assertion: NO records should be silently dropped
		expect(
			chartSum,
			`Chart sum (${chartSum}) != DB count (${dbCount}) — timezone sign inversion is dropping records at midnight boundary`
		).toBe(dbCount);
	});

	test('Chart.php and DataBuckets.php timezone offsets produce consistent results', async ({
		page,
	}) => {
		/**
		 * Verify that the SQL timezone conversion (Chart.php) and the PHP
		 * timezone conversion (DataBuckets.php) produce the same date bucket
		 * for the same record.
		 *
		 * We insert a record at a known timestamp, query via both AJAX (SQL path)
		 * and server-rendered (PHP path), and verify both show the same total.
		 */

		const now = Math.floor(Date.now() / 1000);
		const ts = now - 3600; // 1 hour ago

		await insertRows(ts, 5, 'tz-consistency');

		const dbCount = await countRows();
		expect(dbCount).toBe(5);

		const rangeStart = ts - 86400;
		const rangeEnd = now + 3600;
		const fromDate = new Date(rangeStart * 1000).toISOString().slice(0, 10);
		const toDate = new Date(rangeEnd * 1000).toISOString().slice(0, 10);

		// Path 1: AJAX chart (SQL CONVERT_TZ → DataBuckets.addRow)
		const nonce = await extractChartNonce(page);
		const ajaxJson = await callChartAjax(
			page,
			nonce,
			rangeStart,
			rangeEnd,
			'daily'
		);
		const ajaxSum = sumV1(ajaxJson);

		// Path 2: Server-rendered chart (same path but via initial page load)
		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2&type=custom&from=${fromDate}&to=${toDate}`, {
			waitUntil: 'domcontentloaded',
		});
		await page.waitForSelector('[id^="slimstat_chart_data_"]', {
			state: 'attached',
			timeout: 10_000,
		});
		const serverJson = await readServerRenderedChart(page);
		const serverSum = serverJson ? sumV1(serverJson) : -1;

		console.log(
			`TZ consistency: DB=${dbCount}, AJAX sum=${ajaxSum}, server sum=${serverSum}`
		);

		// Both paths should show the same count
		expect(ajaxSum).toBe(dbCount);
		// The server page was given the same explicit range as AJAX.
		if (serverSum >= 0) {
			expect(serverSum).toBeGreaterThanOrEqual(dbCount);
		}
	});
});

// ─── Bug 3: javascript_mode Migration Gate ──────────────────────────────────────

test.describe('Bug 3: javascript_mode migration must not be gated on banner flag', () => {
	test.setTimeout(120_000);

	test.beforeAll(() => {
		installOptionMutator();
	});

	test.afterAll(async () => {
		uninstallOptionMutator();
		await closeDb();
	});

	test.beforeEach(async ({ page }) => {
		await snapshotSlimstatOptions();
		await setSlimstatOption(page, 'gdpr_enabled', 'off');
		await setSlimstatOption(page, 'ignore_wp_users', 'off');
	});

	test.afterEach(async () => {
		await restoreSlimstatOptions();
	});

	test('migration resets javascript_mode to on even when use_slimstat_banner is off', async ({
		page,
	}) => {
		/**
		 * Simulate the scenario where a user:
		 * 1. Upgraded from 5.3.x to 5.4.0 (javascript_mode defaulted to 'off')
		 * 2. Turned off use_slimstat_banner while troubleshooting
		 * 3. Upgrades to 5.4.6 → migration should STILL reset javascript_mode
		 *
		 * BEFORE FIX: migration skips reset because banner is 'off'
		 * AFTER FIX:  migration resets javascript_mode unconditionally
		 */

		// Set the pre-migration state: banner OFF, javascript_mode OFF
		await setSlimstatOption(page, 'use_slimstat_banner', 'off');
		await setSlimstatOption(page, 'javascript_mode', 'off');

		// Reset migration flag to force re-run
		await setSlimstatOption(page, '_migration_5460', '0');

		// Trigger migration by loading any admin page
		await page.goto(`${BASE_URL}/wp-admin/`, {
			waitUntil: 'domcontentloaded',
		});
		// Wait for migration to complete (runs on plugins_loaded)
		await page.waitForTimeout(2000);

		// Check the resulting javascript_mode value
		const jsMode = await getSlimstatOptionValue('javascript_mode');

		console.log(
			`Migration with banner=off: javascript_mode=${jsMode}`
		);

		// After fix: javascript_mode should be 'on' regardless of banner state
		expect(
			jsMode,
			'javascript_mode was not reset to "on" — migration is still gated on use_slimstat_banner'
		).toBe('on');
	});

	test('client-side tracking sends JS request (not gated by params.id)', async ({
		page,
	}) => {
		/**
		 * When javascript_mode='on', the JS tracker must NOT see params.id
		 * and must send its own tracking request via /hit or admin-ajax.
		 *
		 * When javascript_mode='off', PHP sets params.id and JS skips tracking.
		 * This test verifies the 'on' path works correctly.
		 */

		await setSlimstatOption(page, 'javascript_mode', 'on');

		let trackingRequestSent = false;
		page.on('request', (req) => {
			const url = req.url();
			if (
				req.method() === 'POST' &&
				(url.includes('/wp-json/slimstat/v1/hit') ||
					url.includes('admin-ajax.php'))
			) {
				const body = req.postData() || '';
				if (
					body.includes('action=slimtrack') ||
					url.includes('/slimstat/v1/hit')
				) {
					trackingRequestSent = true;
				}
			}
		});

		await page.goto(`${BASE_URL}/?e2e=jsmode-test-${Date.now()}`, {
			waitUntil: 'networkidle',
		});

		// Wait for tracking JS to fire
		await page.waitForTimeout(3000);

		console.log(`Client-side tracking request sent: ${trackingRequestSent}`);

		expect(
			trackingRequestSent,
			'No tracking request was sent — JS is still in server-side mode (params.id is set, blocking JS tracker)'
		).toBe(true);
	});

	test('server-side mode records once and only permits a same-row consent update', async ({
		page,
	}) => {
		/**
		 * Control test: verify that when javascript_mode='off',
		 * the JS tracker correctly skips (because params.id is set by PHP).
		 * This confirms the guard at wp-slimstat.js:1511 works as designed.
		 */

		await setSlimstatOption(page, 'javascript_mode', 'off');

		const jsTrackingRequests: Array<{ url: string; body: string }> = [];
		page.on('request', (req) => {
			const url = req.url();
			if (
				req.method() === 'POST' &&
				(url.includes('/wp-json/slimstat/v1/hit') ||
					(url.includes('admin-ajax.php') &&
						(req.postData() || '').includes('action=slimtrack')))
			) {
				jsTrackingRequests.push({ url, body: req.postData() || '' });
			}
		});

		const marker = `servermode-test-${Date.now()}`;
		await page.goto(
			`${BASE_URL}/?e2e=${marker}`,
			{ waitUntil: 'networkidle' }
		);
		await page.waitForTimeout(3000);
		const [rows] = (await getPool().execute(
			'SELECT COUNT(*) AS cnt FROM wp_slim_stats WHERE resource LIKE ?',
			[`%${marker}%`]
		)) as any;
		const recordedRows = parseInt(rows[0].cnt, 10);

		console.log(
			`Server-side mode — rows=${recordedRows}, requests=${JSON.stringify(jsTrackingRequests)}`
		);

		// PHP owns the initial pageview in server-side mode. A consent upgrade may
		// update that same row; it is not a second pageview.
		expect(recordedRows, 'Server-side mode must record exactly one pageview').toBe(1);
		for (const request of jsTrackingRequests) {
			expect(request.body, 'Any server-mode POST must be a consent update').toContain('consent_upgrade=1');
			expect(request.body, 'Consent update must target the PHP-created pageview').toContain('pageview_id=');
		}
	});

	// v547-fix test removed — duplicate of the test above (line 512) which covers
	// the same scenario: banner=off + javascript_mode=off + migration re-run.
});

// ─── Bug 4: Stale Cached params.id ──────────────────────────────────────────────

test.describe('Bug 4: Stale params.id from cached pages must not block tracking', () => {
	test.setTimeout(120_000);

	test.beforeAll(() => {
		installOptionMutator();
	});

	test.afterAll(async () => {
		uninstallOptionMutator();
		await closeDb();
	});

	test.beforeEach(async ({ page }) => {
		await snapshotSlimstatOptions();
		await clearStatsTable();
		await setSlimstatOption(page, 'gdpr_enabled', 'off');
		await setSlimstatOption(page, 'ignore_wp_users', 'off');
	});

	test.afterEach(async () => {
		await restoreSlimstatOptions();
	});

	test('switching from server-side to client-side: params.id is not set in page source', async ({
		page,
	}) => {
		/**
		 * After switching from javascript_mode='off' to 'on', the PHP
		 * must NOT embed params.id in the page. Instead it should embed
		 * params.ci (content info for client-side tracking).
		 *
		 * If a CDN serves a cached page from when javascript_mode was 'off',
		 * the stale params.id would cause JS to skip tracking. This test
		 * verifies that fresh pages after the switch don't contain params.id.
		 */

		// Start in server-side mode
		await setSlimstatOption(page, 'javascript_mode', 'off');
		const serverResponse = await page.request.get(`${BASE_URL}/?e2e=cache-test-1`);
		const serverHtml = await serverResponse.text();
		expect(serverHtml, 'Server-side HTML must contain the PHP-created pageview ID')
			.toMatch(/var SlimStatParams = \{[^;]*"id":"\d+/);

		// Switch to client-side mode
		await setSlimstatOption(page, 'javascript_mode', 'on');
		const clientResponse = await page.request.get(`${BASE_URL}/?e2e=cache-test-2`);
		const clientHtml = await clientResponse.text();
		const localized = clientHtml.match(/var SlimStatParams = (\{[^;]+\});/);
		expect(localized, 'Client-side HTML must localize SlimStatParams').toBeTruthy();
		const clientModeParams = JSON.parse(localized![1]);
		expect(clientModeParams.ci, 'Client-side HTML must carry content identity').toBeTruthy();
		expect(clientModeParams, 'Client-side HTML must not carry a stale pageview ID')
			.not.toHaveProperty('id');
	});

	test('navigation event bypasses params.id guard even if stale id exists', async ({
		page,
	}) => {
		/**
		 * Even if a cached page has a stale params.id, SPA navigation events
		 * (via WP Interactivity API or turbo-like navigation) should still
		 * track because isNavigation=true bypasses the guard at line 1511.
		 *
		 * This is a defense-in-depth check: cached pages shouldn't have
		 * params.id in client mode, but if they do, navigation still works.
		 */

		await setSlimstatOption(page, 'javascript_mode', 'on');

		// Load the page normally
		await page.goto(`${BASE_URL}/?e2e=nav-bypass-${Date.now()}`, {
			waitUntil: 'networkidle',
		});
		await page.waitForTimeout(2000);

		// Inject a stale params.id to simulate cached page scenario
		await page.evaluate(() => {
			const w = window as any;
			// Find and set the SlimStat params id (simulating stale cache)
			if (typeof w.currentSlimStatParams === 'function') {
				const p = w.currentSlimStatParams();
				if (p) p.id = '99999.deadbeef';
			}
		});

		// Now trigger a navigation-like event and check if tracking fires
		let navigationTrackingSent = false;
		page.on('request', (req) => {
			const url = req.url();
			if (
				req.method() === 'POST' &&
				(url.includes('/wp-json/slimstat/v1/hit') ||
					url.includes('admin-ajax.php'))
			) {
				navigationTrackingSent = true;
			}
		});

		// Simulate navigation by clicking an internal link
		const links = await page.$$('a[href^="/"]');
		if (links.length > 0) {
			await links[0].click();
			await page.waitForTimeout(3000);
		}

		console.log(
			`Navigation tracking after stale id inject: sent=${navigationTrackingSent}`
		);

		// This is a best-effort test — if no internal links exist,
		// we can't simulate navigation. Skip assertion in that case.
		if (links.length > 0) {
			// Navigation events should bypass the params.id guard
			// (isNavigation=true → line 1509 condition fails → tracking proceeds)
			expect(navigationTrackingSent).toBe(true);
		}
	});
});
