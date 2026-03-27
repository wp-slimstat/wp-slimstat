/**
 * k6 load test: Returning visitor cookie performance.
 *
 * Simulates realistic traffic with a mix of returning visitors (reuse cookies)
 * and new visitors (fresh cookie jar). Verifies that cookie-based session
 * tracking performs well under load.
 *
 * Pre-requisites:
 *   - SlimStat must be configured with javascript_mode=off (server-side tracking)
 *     because k6 cannot execute the JS tracker.
 *   - set_tracker_cookie=on, gdpr_enabled=off
 *
 * Run:
 *   # Configure for server-side tracking first:
 *   wp eval '$o=get_option("slimstat_options"); $o["javascript_mode"]="off"; $o["set_tracker_cookie"]="on"; $o["gdpr_enabled"]="off"; update_option("slimstat_options",$o);'
 *
 *   k6 run tests/perf/returning-visitor-load.js
 *
 *   # Restore JS mode after:
 *   wp eval '$o=get_option("slimstat_options"); $o["javascript_mode"]="on"; update_option("slimstat_options",$o);'
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend, Rate } from 'k6/metrics';

// Custom metrics
const newSessionRate = new Rate('new_session_rate');
const returningSessionRate = new Rate('returning_session_rate');
const pageLoadDuration = new Trend('page_load_duration', true);
const cookieSetRate = new Rate('cookie_set_rate');
const sessionErrors = new Counter('session_errors');

export const options = {
  scenarios: {
    // Scenario 1: Steady-state returning visitor traffic
    returning_visitors: {
      executor: 'constant-arrival-rate',
      rate: 10,
      timeUnit: '1s',
      duration: '2m',
      preAllocatedVUs: 20,
      maxVUs: 40,
    },
    // Scenario 2: Mixed traffic spike (returning + new visitors)
    mixed_traffic: {
      executor: 'ramping-arrival-rate',
      startRate: 5,
      timeUnit: '1s',
      stages: [
        { duration: '15s', target: 30 },
        { duration: '1m', target: 30 },
        { duration: '15s', target: 5 },
      ],
      preAllocatedVUs: 40,
      maxVUs: 80,
      startTime: '2m30s', // starts after scenario 1 + 30s gap
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],            // < 5% error rate
    http_req_duration: ['p(95)<3000'],         // p95 < 3s
    page_load_duration: ['p(95)<2000'],        // p95 < 2s
    cookie_set_rate: ['rate>0.80'],            // 80%+ requests should get cookies
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:10003';

// Frontend pages to simulate real browsing
const PAGES = [
  '/',
  '/?p=1',
  '/sample-page/',
  '/?page_id=2',
];

export function setup() {
  // Verify the site is reachable
  const res = http.get(`${BASE_URL}/`);
  const ok = check(res, {
    'site is reachable': (r) => r.status === 200,
    'site has content': (r) => r.body && r.body.length > 500,
  });

  if (!ok) {
    console.error(`Site at ${BASE_URL} is not reachable or returned an error.`);
  }

  // Verify SlimStat tracking is active (server-side mode)
  const bodyHasSlimstat = res.body && (
    res.body.includes('slimstat') ||
    res.body.includes('slim_stats') ||
    res.body.includes('SlimStat')
  );

  console.log(`\n========================================`);
  console.log(`Returning Visitor Cookie Load Test`);
  console.log(`Base URL: ${BASE_URL}`);
  console.log(`SlimStat detected in page: ${!!bodyHasSlimstat}`);
  console.log(`========================================\n`);

  return { siteOk: ok };
}

export default function (_data) {
  // 70% returning visitors (reuse cookies), 30% new (clear cookies)
  const isReturning = Math.random() > 0.3;

  if (!isReturning) {
    // Simulate new visitor — clear the cookie jar
    const jar = http.cookieJar();
    jar.clear(BASE_URL);
  }

  // Each visitor browses 2-4 pages
  const numPages = Math.floor(Math.random() * 3) + 2;
  const marker = `k6-${__VU}-${__ITER}-${Date.now()}`;
  let firstPageHadCookie = false;
  let sessionConsistent = true;
  let firstCookieValue = null;

  for (let i = 0; i < numPages; i++) {
    const pagePath = PAGES[Math.floor(Math.random() * PAGES.length)];
    const url = `${BASE_URL}${pagePath}${pagePath.includes('?') ? '&' : '?'}k6_marker=${marker}-p${i}`;

    const res = http.get(url, {
      tags: { type: 'pageview', page_num: String(i) },
    });

    pageLoadDuration.add(res.timings.duration);

    const pageOk = check(res, {
      'status 200': (r) => r.status === 200,
      'body not empty': (r) => r.body && r.body.length > 500,
    });

    if (!pageOk) {
      sessionErrors.add(1);
      continue;
    }

    // Check cookie state after each page
    const jar = http.cookieJar();
    const cookies = jar.cookiesForURL(BASE_URL);
    const hasCookie = cookies['slimstat_tracking_code'] !== undefined &&
                      cookies['slimstat_tracking_code'].length > 0;

    cookieSetRate.add(hasCookie ? 1 : 0);

    if (i === 0) {
      firstPageHadCookie = hasCookie;
      if (hasCookie) {
        firstCookieValue = cookies['slimstat_tracking_code'][0];
      }

      // Track session type
      newSessionRate.add(!isReturning ? 1 : 0);
      returningSessionRate.add(isReturning ? 1 : 0);
    } else if (hasCookie && firstCookieValue) {
      // Verify cookie consistency across pages (visit_id should be stable)
      const currentValue = cookies['slimstat_tracking_code'][0];
      // Extract numeric visit_id from both (strip 'id' suffix and checksum)
      const extractId = (v) => parseInt(v.split('.')[0].replace(/id$/, ''), 10);
      if (extractId(currentValue) !== extractId(firstCookieValue)) {
        sessionConsistent = false;
      }
    }

    // Simulate think time between pages (0.5-2.5s)
    sleep(0.5 + Math.random() * 2);
  }

  // Track session consistency
  if (!sessionConsistent) {
    sessionErrors.add(1);
  }
}

export function teardown(_data) {
  console.log(`\n========================================`);
  console.log(`Returning Visitor Cookie Load Test Complete`);
  console.log(`========================================\n`);
  console.log(`Check wp_slim_stats for visit_id distribution:`);
  console.log(`  SELECT visit_id, COUNT(*) as pages`);
  console.log(`  FROM wp_slim_stats`);
  console.log(`  WHERE resource LIKE '%k6_marker%'`);
  console.log(`  GROUP BY visit_id`);
  console.log(`  ORDER BY pages DESC LIMIT 20;`);
  console.log(``);
  console.log(`Healthy results:`);
  console.log(`  - Returning visitors: multiple pages per visit_id`);
  console.log(`  - New visitors: 2-4 pages per visit_id`);
  console.log(`  - No visit_id=0 rows (would indicate cookie/consent failure)`);
  console.log(``);
  console.log(`Don't forget to restore javascript_mode=on after testing.`);
}
