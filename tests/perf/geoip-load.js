/**
 * k6 load test: GeoIP AJAX loop prevention under high admin traffic.
 *
 * Simulates many admin users hitting admin pages with DISABLE_WP_CRON=true.
 * Under the old bug, each page load would spawn a cascading AJAX storm.
 * Under the fix, AJAX fires at most once per monthly update window.
 *
 * Pre-requisites:
 *   - DISABLE_WP_CRON must be added to wp-config.php
 *   - mu-plugin logger must be installed
 *   - slimstat_last_geoip_dl option must be deleted
 *
 * Run: k6 run tests/perf/geoip-load.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

// Custom metrics
const ajaxErrors = new Counter('geoip_ajax_errors');
const adminDuration = new Trend('admin_page_duration', true);

export const options = {
  scenarios: {
    // Steady-state: 20 admin page loads per second for 2 minutes
    steady_state: {
      executor: 'constant-arrival-rate',
      rate: 20,
      timeUnit: '1s',
      duration: '2m',
      preAllocatedVUs: 30,
      maxVUs: 50,
    },
    // Spike: ramp from 5 to 50 RPS, hold, then back down
    spike: {
      executor: 'ramping-arrival-rate',
      startRate: 5,
      timeUnit: '1s',
      stages: [
        { duration: '15s', target: 50 },
        { duration: '30s', target: 50 },
        { duration: '15s', target: 5 },
      ],
      preAllocatedVUs: 60,
      maxVUs: 100,
      startTime: '2m30s', // starts after steady_state + 30s gap
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],          // < 5% error rate
    http_req_duration: ['p(95)<5000'],       // p95 < 5s
    admin_page_duration: ['p(99)<8000'],     // p99 < 8s
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:10003';
const WP_USER = __ENV.WP_USER || 'parhumm';
const WP_PASS = __ENV.WP_PASS || 'testpass123';

const ADMIN_PAGES = [
  '/wp-admin/',
  '/wp-admin/plugins.php',
  '/wp-admin/options-general.php',
  '/wp-admin/edit.php',
  '/wp-admin/upload.php',
  '/wp-admin/users.php',
  '/wp-admin/tools.php',
];

export function setup() {
  // Authenticate by POSTing to wp-login.php
  const loginPage = http.get(`${BASE_URL}/wp-login.php`);

  const loginRes = http.post(`${BASE_URL}/wp-login.php`, {
    log: WP_USER,
    pwd: WP_PASS,
    'wp-submit': 'Log In',
    redirect_to: `${BASE_URL}/wp-admin/`,
    testcookie: '1',
  }, {
    redirects: 0, // Don't follow redirect, just capture cookies
  });

  // Verify login succeeded (302 redirect to admin)
  const loginOk = loginRes.status === 302 || loginRes.status === 200;
  if (!loginOk) {
    console.error(`Login failed with status ${loginRes.status}`);
  }

  return { loginOk };
}

export default function (_data) {
  // Pick a random admin page
  const page = ADMIN_PAGES[Math.floor(Math.random() * ADMIN_PAGES.length)];

  const res = http.get(`${BASE_URL}${page}`, {
    tags: { page_type: 'admin', page_name: page },
  });

  const ok = check(res, {
    'status is 200': (r) => r.status === 200,
    'not 500 error': (r) => r.status < 500,
    'body is not empty': (r) => r.body && r.body.length > 100,
    'contains wp-admin content': (r) => r.body && r.body.includes('wp-admin'),
  });

  adminDuration.add(res.timings.duration);

  if (!ok || res.status >= 500) {
    ajaxErrors.add(1);
  }

  // Minimal think time — we want high load
  sleep(0.05);
}

export function teardown(_data) {
  // Try to read the AJAX log via direct HTTP (if accessible)
  const logRes = http.get(`${BASE_URL}/wp-content/geoip-ajax-calls.log`);
  if (logRes.status === 200 && logRes.body) {
    const lines = logRes.body.trim().split('\n').filter((l) => l.length > 0);
    console.log(`\n========================================`);
    console.log(`GeoIP AJAX handler invocations: ${lines.length}`);
    console.log(`Expected: 0-1 (fix working) or 0 (provider disabled)`);
    console.log(`Old bug would produce: hundreds to thousands`);
    console.log(`========================================\n`);

    if (lines.length > 10) {
      console.warn(`WARNING: ${lines.length} AJAX calls detected — possible regression!`);
    }
  } else {
    console.log('Could not read AJAX log (may not be HTTP-accessible). Check file directly.');
  }
}
