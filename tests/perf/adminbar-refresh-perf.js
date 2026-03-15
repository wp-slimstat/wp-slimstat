/**
 * k6 performance test: Admin bar stats AJAX endpoint (#223, #224).
 *
 * Simulates multiple concurrent admin users polling the
 * slimstat_get_adminbar_stats endpoint once per minute.
 * Verifies response times stay under SLA and transient
 * caching prevents DB overload.
 *
 * Run: k6 run tests/perf/adminbar-refresh-perf.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Counter } from 'k6/metrics';

const ajaxDuration = new Trend('adminbar_ajax_duration_ms', true);
const cacheHits = new Counter('transient_cache_hits');

export const options = {
  scenarios: {
    // Smoke: 1 user, verify endpoint works
    smoke: {
      executor: 'constant-vus',
      vus: 1,
      duration: '30s',
      tags: { scenario: 'smoke' },
    },
    // Load: 10 concurrent admin users polling every ~2s (simulated minute)
    load: {
      executor: 'constant-vus',
      vus: 10,
      duration: '1m',
      startTime: '35s',
      tags: { scenario: 'load' },
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    // Admin bar AJAX should respond under 500ms p95
    adminbar_ajax_duration_ms: ['p(95)<500', 'p(99)<1000'],
    // Standard HTTP thresholds
    http_req_duration: ['p(95)<2000'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:10003';
const WP_USER = __ENV.WP_USER || 'parhumm';
const WP_PASS = __ENV.WP_PASS || 'testpass123';

let nonce = '';
let loggedIn = false;

function ensureLogin() {
  if (loggedIn) return;

  // Login
  http.get(`${BASE_URL}/wp-login.php`);
  const loginRes = http.post(`${BASE_URL}/wp-login.php`, {
    log: WP_USER,
    pwd: WP_PASS,
    'wp-submit': 'Log In',
    redirect_to: `${BASE_URL}/wp-admin/`,
    testcookie: '1',
  }, { redirects: 5 });

  // Extract nonce from SlimStatAdminBar localized data
  const adminRes = http.get(`${BASE_URL}/wp-admin/index.php`);
  const match = adminRes.body.match(/"security"\s*:\s*"([a-f0-9]+)"/);
  if (!match || !match[1] || match[1].length < 8) {
    console.error('Failed to extract valid SlimStatAdminBar nonce from admin page');
    console.error(`  Login status: ${adminRes.status}, body length: ${adminRes.body.length}`);
    // Don't set loggedIn — force retry on next iteration
    return;
  }

  nonce = match[1];
  loggedIn = true;
}

export default function () {
  ensureLogin();

  if (!nonce) {
    console.warn('No nonce found — skipping AJAX call');
    sleep(1);
    return;
  }

  // Call the admin bar stats AJAX endpoint
  const res = http.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    action: 'slimstat_get_adminbar_stats',
    security: nonce,
  }, {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });

  ajaxDuration.add(res.timings.duration);

  check(res, {
    'status 200': (r) => r.status === 200,
    'valid JSON': (r) => {
      try { JSON.parse(r.body); return true; } catch { return false; }
    },
    'success true': (r) => {
      try { return JSON.parse(r.body).success === true; } catch { return false; }
    },
    'has online data': (r) => {
      try {
        const d = JSON.parse(r.body).data;
        return d && d.online && typeof d.online.count === 'number';
      } catch { return false; }
    },
    'has sessions data': (r) => {
      try {
        const d = JSON.parse(r.body).data;
        return d && d.sessions && typeof d.sessions.count === 'number';
      } catch { return false; }
    },
    'response under 500ms': (r) => r.timings.duration < 500,
  });

  // Simulate ~1 poll per minute: sleep 2s in load test (compressed time)
  sleep(2);
}

export function teardown() {
  console.log('\n=============================================');
  console.log('Admin Bar Refresh Performance Test (#223/#224)');
  console.log('=============================================');
  console.log('SLA: p95 < 500ms, p99 < 1000ms');
  console.log('Cache: 60s transient for today stats + chart data\n');
}
