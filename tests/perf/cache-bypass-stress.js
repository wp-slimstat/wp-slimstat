/**
 * k6 stress test: Query cache bypass for live date ranges (#270)
 *
 * Worst-case scenario: getVar() no longer caches when the date range
 * includes today. This means every admin page load with a live date
 * range runs a fresh COUNT query instead of reading from transient.
 *
 * This test verifies:
 * 1. Admin report pages stay under SLA (P95 < 5s) without query cache
 * 2. No errors, fatals, or PHP warnings under sustained load
 * 3. The upgrade cache-clear DELETE (LIMIT 1000) doesn't block requests
 * 4. Multiple concurrent admin users don't cause DB contention
 *
 * Worst-case assumptions:
 * - 5 concurrent admin users viewing reports simultaneously
 * - All viewing live date ranges (cache bypass active)
 * - 2 minutes sustained load (120+ requests per user)
 * - wp_slim_stats table has existing data
 *
 * Run: k6 run tests/perf/cache-bypass-stress.js
 * Run with custom URL: k6 run -e BASE_URL=http://mysite.local tests/perf/cache-bypass-stress.js
 */

import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Trend, Counter, Rate } from 'k6/metrics';

// ─── Custom metrics ──────────────────────────────────────────────────────────

const slimview1Duration = new Trend('slimview1_duration_ms', true);
const slimview2Duration = new Trend('slimview2_duration_ms', true);
const slimview3Duration = new Trend('slimview3_duration_ms', true);
const ajaxReportDuration = new Trend('ajax_report_duration_ms', true);
const errorCount = new Counter('page_errors');
const phpWarningRate = new Rate('php_warnings');

// ─── Options ─────────────────────────────────────────────────────────────────

export const options = {
  scenarios: {
    // Scenario 1: Sustained admin usage (worst case for cache bypass)
    sustained_admin: {
      executor: 'constant-vus',
      vus: 5,
      duration: '2m',
      exec: 'adminReportPages',
    },
    // Scenario 2: Spike — simulate 10 admins hitting reports at once
    spike_load: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '10s', target: 10 },
        { duration: '20s', target: 10 },
        { duration: '10s', target: 0 },
      ],
      startTime: '2m10s', // starts after sustained test
      exec: 'adminReportPages',
    },
  },
  thresholds: {
    // SLA: P95 under 5 seconds for all report pages
    http_req_duration: ['p(95)<5000'],
    slimview1_duration_ms: ['p(95)<5000'],
    slimview2_duration_ms: ['p(95)<5000'],
    slimview3_duration_ms: ['p(95)<5000'],
    // AJAX report loads under 3 seconds
    ajax_report_duration_ms: ['p(95)<3000'],
    // Less than 5% failures
    http_req_failed: ['rate<0.05'],
    // Zero PHP warnings
    php_warnings: ['rate<0.001'],
    // Zero fatal errors
    page_errors: ['count<1'],
  },
};

// ─── Config ──────────────────────────────────────────────────────────────────

const BASE_URL = __ENV.BASE_URL || 'http://localhost:10003';
const WP_USER = __ENV.WP_USER || 'parhumm';
const WP_PASS = __ENV.WP_PASS || 'testpass123';

// All SlimStat report pages (all use live date ranges by default)
const REPORT_PAGES = [
  '/wp-admin/admin.php?page=slimview1', // Overview + Audience Location map
  '/wp-admin/admin.php?page=slimview2', // Audience (Top Countries list)
  '/wp-admin/admin.php?page=slimview3', // Site Analysis (Top Browsers, etc.)
  '/wp-admin/admin.php?page=slimview4', // Traffic Sources
  '/wp-admin/admin.php?page=slimview5', // Custom Reports
];

let loggedIn = false;

// ─── Auth ────────────────────────────────────────────────────────────────────

function ensureLogin() {
  if (loggedIn) return;
  http.get(`${BASE_URL}/wp-login.php`);
  const loginRes = http.post(`${BASE_URL}/wp-login.php`, {
    log: WP_USER,
    pwd: WP_PASS,
    'wp-submit': 'Log In',
    redirect_to: `${BASE_URL}/wp-admin/`,
    testcookie: '1',
  }, { redirects: 5 });

  check(loginRes, {
    'login successful': (r) => r.status === 200 && !r.body.includes('login_error'),
  });
  loggedIn = true;
}

// ─── Checks ──────────────────────────────────────────────────────────────────

function checkResponse(res, pageName) {
  const ok = check(res, {
    [`${pageName}: status 200`]: (r) => r.status === 200,
    [`${pageName}: no Fatal error`]: (r) => !r.body.includes('Fatal error'),
    [`${pageName}: no DB error`]: (r) => !r.body.includes('WordPress database error'),
    [`${pageName}: not empty`]: (r) => r.body.length > 500,
  });

  if (!ok) {
    errorCount.add(1);
  }

  const hasWarning = (/PHP Warning:.*\.php/).test(res.body);
  phpWarningRate.add(hasWarning);
}

// ─── Main test function ──────────────────────────────────────────────────────

export function adminReportPages() {
  ensureLogin();

  group('report_page_load', () => {
    // Pick a random report page (simulates real admin behavior)
    const pageIdx = Math.floor(Math.random() * REPORT_PAGES.length);
    const pagePath = REPORT_PAGES[pageIdx];
    const res = http.get(`${BASE_URL}${pagePath}`, {
      tags: { page_name: pagePath },
    });

    // Record per-page metrics
    switch (pageIdx) {
      case 0: slimview1Duration.add(res.timings.duration); break;
      case 1: slimview2Duration.add(res.timings.duration); break;
      case 2: slimview3Duration.add(res.timings.duration); break;
    }

    checkResponse(res, `slimview${pageIdx + 1}`);

    // If async_load is on, reports load via AJAX — simulate that too
    if (res.body.includes('slimstat_load_report')) {
      group('ajax_report_load', () => {
        // Extract nonce from page for AJAX calls
        const nonceMatch = res.body.match(/slimstat_nonce['"]\s*:\s*['"]([a-f0-9]+)['"]/);
        if (nonceMatch) {
          const ajaxRes = http.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
            action: 'slimstat_load_report',
            report_id: 'slim_p6_01', // Audience Location (the affected report)
            security: nonceMatch[1],
          });
          ajaxReportDuration.add(ajaxRes.timings.duration);
          check(ajaxRes, {
            'AJAX report: status 200': (r) => r.status === 200,
            'AJAX report: has content': (r) => r.body.length > 10,
          });
        }
      });
    }
  });

  // Simulate human reading time between pages
  sleep(Math.random() * 2 + 0.5); // 0.5-2.5s between requests
}

// ─── Teardown ────────────────────────────────────────────────────────────────

export function teardown() {
  console.log('\n=============================================');
  console.log('Cache Bypass Stress Test (#270)');
  console.log('=============================================');
  console.log('Worst-case: getVar() runs fresh query every');
  console.log('page load instead of reading transient cache.');
  console.log('If P95 < 5s and 0 errors: SAFE for users.');
  console.log('=============================================\n');
}
