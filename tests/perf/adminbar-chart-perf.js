/**
 * k6 performance test: Admin bar chart rendering after #221 fix.
 *
 * Verifies that instantiating LiveAnalyticsReport in the admin bar
 * does not regress admin page load times. The method uses a 60s
 * transient cache, so only the first request per minute should hit DB.
 *
 * Chart data comparisons are covered by adminbar-chart-consistency.spec.ts.
 *
 * Run: k6 run tests/perf/adminbar-chart-perf.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';
import { BASE_URL, credentials } from './lib/env.js';

const adminPageDuration = new Trend('admin_page_duration_ms', true);

export const options = {
  noCookiesReset: true, // Login is retained once per virtual user.
  scenarios: {
    admin_load: {
      executor: 'constant-vus',
      vus: 1,
      duration: '1m',
    },
  },
  thresholds: {
    checks: ['rate==1'],
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<5000'],
    admin_page_duration_ms: ['p(95)<4000'],
  },
};

const { user: WP_USER, pass: WP_PASS } = credentials();

const ADMIN_PAGES = [
  '/wp-admin/',
  '/wp-admin/admin.php?page=slimview1',
  '/wp-admin/plugins.php',
  '/wp-admin/edit.php',
  '/wp-admin/options-general.php',
];

let loggedIn = false;

/** Authenticate once per VU; noCookiesReset keeps the session across iterations. */
function ensureLogin() {
  if (loggedIn) return;
  http.get(`${BASE_URL}/wp-login.php`);
  http.post(`${BASE_URL}/wp-login.php`, {
    log: WP_USER,
    pwd: WP_PASS,
    'wp-submit': 'Log In',
    redirect_to: `${BASE_URL}/wp-admin/`,
    testcookie: '1',
  }, { redirects: 5 });
  loggedIn = true;
}

/** Measure authenticated reports and reject login-page redirects masquerading as HTTP 200. */
export default function () {
  ensureLogin();

  const pagePath = ADMIN_PAGES[Math.floor(Math.random() * ADMIN_PAGES.length)];
  const res = http.get(`${BASE_URL}${pagePath}`, {
    tags: { page_name: pagePath },
  });

  adminPageDuration.add(res.timings.duration);

  check(res, {
    'status 200': (r) => r.status === 200,
    'still authenticated': (r) => !r.url.includes('/wp-login.php'),
    'no Fatal error': (r) => !r.body.includes('Fatal error'),
    'no PHP warnings': (r) => !(/PHP Warning:.*\.php/).test(r.body),
    'response not empty': (r) => r.body.length > 500,
  });

  sleep(0.1);
}

export function teardown() {
  console.log('\n========================================');
  console.log('Admin Bar Chart Performance Test (#221)');
  console.log('========================================\n');
}
