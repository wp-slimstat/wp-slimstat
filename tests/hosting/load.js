// Disposable pilot: BASE_URL, RUN_ID, MODE=tracking|concurrency|admin|control.
import http from 'k6/http';
import encoding from 'k6/encoding';
import { check, fail, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { BASE_URL, credentials } from '../perf/lib/env.js';

if (__ENV.SLIMSTAT_HOSTING_DISPOSABLE !== '1' || !/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(BASE_URL)) {
  throw new Error('This fixture requires an explicitly armed disposable localhost stack.');
}
const mode = __ENV.MODE;
if (!['tracking', 'concurrency', 'admin', 'control'].includes(mode) || !/^[a-zA-Z0-9_-]+$/.test(__ENV.RUN_ID || '')) {
  throw new Error('Set MODE and an alphanumeric RUN_ID.');
}
if (mode === 'admin' && (!/^\d{4}-\d{2}-\d{2}$/.test(__ENV.REPORT_FROM || '') || !/^\d{4}-\d{2}-\d{2}$/.test(__ENV.REPORT_TO || ''))) {
  throw new Error('Admin measurements require explicit historical report dates.');
}
const elapsed = new Trend('operation_ms', true);
const rejected = new Rate('operation_failed');
const unavailable = new Rate('http_503');
const arrival = ['tracking', 'control'].includes(mode);
export const options = {
  noCookiesReset: true,
  scenarios: { pilot: arrival
    ? { executor: 'constant-arrival-rate', rate: 50, timeUnit: '1s', duration: '60s', preAllocatedVUs: 100, maxVUs: 100 }
    : { executor: 'constant-vus', vus: mode === 'admin' ? 10 : 50, duration: mode === 'admin' ? '60s' : '30s' } },
  thresholds: {
    operation_ms: [`p(95)<${mode === 'admin' ? 500 : 200}`],
    operation_failed: ['rate<0.001'],
    http_503: ['rate==0'],
    ...(arrival ? { dropped_iterations: ['count==0'] } : {}),
  },
};
const headers = {
  'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36',
  'X-Slimstat-Bench': __ENV.RUN_ID,
};
if (__ENV.SQL_TIMING === '1') headers['X-Slimstat-Bench-Timing'] = '1';
let loggedIn = false;
export default function () {
  let response;
  let valid;
  if (mode === 'admin') {
    if (!loggedIn) {
      const { user, pass } = credentials();
      http.get(`${BASE_URL}/wp-login.php`);
      const login = http.post(`${BASE_URL}/wp-login.php`, { log: user, pwd: pass, testcookie: '1', redirect_to: `${BASE_URL}/wp-admin/` });
      if (login.status !== 200 || login.body.includes('id="loginform"')) fail('Admin login failed');
      loggedIn = true;
    }
    const reports = ['slimview1', 'slimview2', 'slimview3', 'slimview4', 'slimview5', 'slimview6'];
    response = http.get(`${BASE_URL}/wp-admin/admin.php?page=${reports[__ITER % reports.length]}&type=custom&from=${__ENV.REPORT_FROM}&to=${__ENV.REPORT_TO}`, { headers });
    valid = response.status === 200 && /id=["']slimstat-(?:filters-form|gf-)/.test(response.body)
      && !/Fatal error|critical error|id="loginform"/.test(response.body);
  } else if (mode === 'control') {
    response = http.get(`${BASE_URL}/`, { headers });
    valid = response.status === 200;
  } else {
    http.cookieJar().clear(BASE_URL);
    const resource = `${BASE_URL}/?hosting=${__ENV.RUN_ID}-${__VU}-${__ITER}`;
    response = http.post(`${BASE_URL}/wp-json/slimstat/v1/hit`, {
      action: 'slimtrack', res: encoding.b64encode(resource, 'rawurl'),
    }, { headers });
    valid = response.status === 200 && /^[1-9][0-9]*\.[a-f0-9]{64}$/.test(response.body.trim());
  }
  elapsed.add(response.timings.duration);
  rejected.add(!valid);
  unavailable.add(response.status === 503);
  check(response, { 'valid operation response': () => valid });
  if (!arrival) sleep(0.1);
}
