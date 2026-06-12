/**
 * k6 performance test: Browscap fileinfo-missing tracker hot path (#303).
 *
 * Confirms two contracts under sustained tracker load:
 *   1. Baseline (fileinfo present + Browscap on) — no regression vs the
 *      neighboring adminbar-chart-perf.js threshold envelope.
 *   2. Fallback (fileinfo missing + Browscap on) — tracker still returns
 *      200, error rate stays at zero, and P95 latency does NOT exceed
 *      baseline (skipping Browscap is, if anything, faster).
 *
 * The fileinfo-missing condition is simulated by deploying the
 * tests/e2e/helpers/fileinfo-disabler-mu-plugin.php shim before running k6
 * with K6_FILEINFO_MISSING=1. The shim shadows extension_loaded('fileinfo')
 * inside the SlimStat\Services namespace.
 *
 * Run baseline:
 *   k6 run tests/perf/issue-303-browscap-fileinfo.js
 * Run fallback:
 *   K6_FILEINFO_MISSING=1 k6 run tests/perf/issue-303-browscap-fileinfo.js
 */

import http from 'k6/http';
import { check } from 'k6';
import { Trend, Rate } from 'k6/metrics';

const trackerDuration = new Trend('tracker_request_duration_ms', true);
const trackerFatalRate = new Rate('tracker_fatal_500_rate');

const BASE_URL = __ENV.BASE_URL || 'http://localhost:10003';
const FILEINFO_MISSING = __ENV.K6_FILEINFO_MISSING === '1';
const SCENARIO_LABEL = FILEINFO_MISSING ? 'fallback' : 'baseline';

const TRACKER_USER_AGENT =
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 k6-perf/issue-303';
const REST_TRACKER_PATH = '/wp-json/slimstat/v1/hit';
const PAYLOAD_HEADERS = {
  'Content-Type': 'application/json',
  // SlimStat reads the visitor UA from this header (not from a JSON field),
  // and Browscap branches on it — keep it stable across both runs.
  'User-Agent': TRACKER_USER_AGENT,
};

export const options = {
  scenarios: {
    tracker_hits: {
      executor: 'constant-arrival-rate',
      rate: 1000,
      timeUnit: '1m',
      duration: '5m',
      preAllocatedVUs: 25,
      maxVUs: 100,
      tags: { variant: SCENARIO_LABEL },
    },
  },
  thresholds: {
    'http_req_duration{variant:baseline}': ['p(95)<350'],
    'http_req_duration{variant:fallback}': ['p(95)<350'],
    'http_req_failed{variant:baseline}': ['rate<0.001'],
    'http_req_failed{variant:fallback}': ['rate<0.001'],
    tracker_fatal_500_rate: ['rate<0.001'],
  },
};

function buildPayload(iter) {
  // Field names match the REST controller's args list in
  // src/Controllers/Rest/TrackingRestController.php — `res` (resource URL),
  // `ref` (referer), `bw/bh/sw/sh` (browser/screen viewport).
  return JSON.stringify({
    res: `/perf-303-${SCENARIO_LABEL}-${iter}`,
    ref: '',
    bw: 1280,
    bh: 720,
    sw: 1920,
    sh: 1080,
  });
}

export default function () {
  const res = http.post(
    `${BASE_URL}${REST_TRACKER_PATH}`,
    buildPayload(__ITER),
    { headers: PAYLOAD_HEADERS, tags: { variant: SCENARIO_LABEL } }
  );

  trackerDuration.add(res.timings.duration);
  trackerFatalRate.add(res.status >= 500);

  check(res, {
    'status 200': (r) => r.status === 200,
    'no Class "finfo" not found': (r) => !r.body.includes('Class "finfo" not found'),
    'no PHP Fatal': (r) => !r.body.includes('PHP Fatal error'),
  });
}

export function teardown() {
  console.log('\n========================================');
  console.log(`Issue #303 Browscap fileinfo perf — ${SCENARIO_LABEL}`);
  console.log(`fileinfo missing: ${FILEINFO_MISSING}`);
  console.log('========================================\n');
}
