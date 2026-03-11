# WP Slimstat Testing Guide

## Overview

WP Slimstat uses three test layers:

| Layer | Tool | What it tests | Run command |
|-------|------|--------------|-------------|
| **Unit** | PHP standalone scripts | Logic correctness (resolver, migration, sync) | `composer test:all` |
| **E2E** | Playwright + TypeScript | Browser-level behavior with real WordPress | `npm run test:e2e` |
| **Load** | k6 | Performance under sustained/spike traffic | `npm run test:perf` |

A combined orchestration script runs E2E + load tests together:

```bash
bash tests/run-qa.sh              # full suite
bash tests/run-qa.sh --e2e-only   # Playwright only
bash tests/run-qa.sh --perf-only  # k6 only
```

---

## Prerequisites

### Local Environment

- **Local by Flywheel** running at `http://localhost:10003`
- PHP 8.x, MySQL accessible via socket
- WordPress users: `parhumm` (administrator), `dordane` (author) — both with password `testpass123`
- MySQL socket: found in Local's `conf/mysql/mysqld.sock` (currently `/Users/parhumm/Library/Application Support/Local/run/X-JdmZXIa/mysql/mysqld.sock`)

### Install Dependencies

```bash
# Node/Playwright (from plugin root)
npm install -D @playwright/test mysql2
npx playwright install chromium

# k6 (macOS)
brew install k6
```

---

## Layer 1: PHP Unit Tests

Self-contained PHP scripts in `tests/`. Each file defines test cases, stubs WordPress functions, and runs assertions without requiring a running WordPress instance.

### Files

| File | Tests |
|------|-------|
| `resolve-geolocation-provider-test.php` | Provider resolution logic (maxmind, dbip, ip2location, disable) |
| `geoservice-provider-resolution-test.php` | Geolocation service factory and provider instantiation |
| `legacy-sync-mapping-test.php` | Legacy field mapping during migration |
| `lazy-migration-test.php` | Lazy migration path validation |
| `license-tag-gating-test.php` | License tag gating for pro features |
| `tracking-rest-controller-test.php` | REST API tracking controller |

### Running

```bash
# All unit tests
composer test:all

# Individual test
composer test:geoip-resolver
php tests/resolve-geolocation-provider-test.php
```

### Writing New Unit Tests

1. Create `tests/<feature>-test.php`
2. Stub required WordPress functions at the top of the file (see existing tests for patterns)
3. Use `assert()` or simple pass/fail output — no PHPUnit dependency required
4. Add a composer script: `"test:<name>": "php tests/<feature>-test.php"`
5. Add it to the `test:all` array in `composer.json`

---

## Layer 2: Playwright E2E Tests

Browser-based tests that interact with a live WordPress instance. Uses a **mu-plugin logger** as oracle to observe server-side behavior that isn't visible in the browser.

### Architecture

```
tests/e2e/
├── playwright.config.ts          # Config: baseURL, auth projects, timeouts
├── global-setup.ts               # Authenticates admin + author, caches auth state
├── geoip-ajax-loop.spec.ts       # Test suite (6 test cases)
├── helpers/
│   ├── setup.ts                  # wp-config toggler, mu-plugin manager, DB helper
│   └── ajax-logger-mu-plugin.php # PHP mu-plugin that logs AJAX handler calls
└── .auth/                        # Cached browser auth state (gitignored)
```

### Key Concept: Server-Side AJAX Observation

SlimStat's GeoIP fallback uses `wp_safe_remote_post()` — a PHP-to-PHP call, **not** browser XHR. Playwright cannot intercept it via the network tab. The solution:

1. A **mu-plugin** (`ajax-logger-mu-plugin.php`) hooks `wp_ajax_slimstat_update_geoip_database` at priority 1
2. Each invocation is logged as a JSON line to `wp-content/geoip-ajax-calls.log`
3. Tests read this file to count actual server-side AJAX handler invocations
4. `setupTest()` installs the mu-plugin and clears the log before each test
5. `teardownTest()` removes the mu-plugin and restores wp-config

### Auth & Projects

Global setup (`global-setup.ts`) logs in as both users and saves browser storage state to `.auth/admin.json` and `.auth/author.json`. Auth files are cached for 30 minutes — delete `.auth/` to force re-login.

The Playwright config defines an `admin` project (default) and the spec file uses `test.use({ storageState })` to switch to author context within a describe block.

### Shared Helpers (`helpers/setup.ts`)

| Function | Purpose |
|----------|---------|
| `setupTest()` | Enables DISABLE_WP_CRON, installs mu-plugin, clears AJAX log, clears geoip timestamp |
| `teardownTest()` | Restores wp-config, removes mu-plugin, clears log, restores provider setting |
| `enableDisableWpCron()` | Adds `define('DISABLE_WP_CRON', true)` to wp-config.php |
| `restoreWpConfig()` | Restores the original wp-config.php from backup |
| `installMuPlugin()` / `uninstallMuPlugin()` | Copies/removes the AJAX logger mu-plugin |
| `readAjaxLog()` | Returns parsed JSON entries from the AJAX log |
| `clearAjaxLog()` | Deletes the AJAX log file |
| `clearGeoipTimestamp()` | DELETEs `slimstat_last_geoip_dl` from `wp_options` |
| `getGeoipTimestamp()` | SELECTs current timestamp value |
| `setSlimstatSetting(key, value)` | Modifies a key in the serialized `slimstat_options` |
| `setProviderDisabled()` | Sets `geolocation_provider` to `disable` (saves original for restore) |
| `closeDb()` | Closes the MySQL connection pool |

### Running

```bash
npm run test:e2e

# Or with Playwright CLI options
npx playwright test --config=tests/e2e/playwright.config.ts
npx playwright test --config=tests/e2e/playwright.config.ts -g "Test 3"  # single test
npx playwright show-report tests/e2e/playwright-report                    # HTML report
```

### Debugging Failures

1. **Trace files**: On failure, Playwright saves traces to `test-results/`. View with:
   ```bash
   npx playwright show-trace test-results/<folder>/trace.zip
   ```

2. **Screenshots**: Captured on failure in `test-results/`.

3. **AJAX log**: Check `wp-content/geoip-ajax-calls.log` for raw invocation data.

4. **Timeout issues**: Tests 2, 3, and 6 have extended timeouts (60–120s). If the local server is slow, increase `test.setTimeout()` in the spec or `timeout` in the config.

5. **Auth stale**: Delete `tests/e2e/.auth/` and re-run to force fresh login.

6. **MySQL socket changed**: If Local by Flywheel regenerates the socket path, update `MYSQL_SOCKET` in `helpers/setup.ts` and `run-qa.sh`.

### Writing New E2E Tests

1. Add test cases to an existing `.spec.ts` or create a new `tests/e2e/<feature>.spec.ts`
2. Use `setupTest()` / `teardownTest()` in beforeEach/afterEach for consistent state
3. For tests that need to observe server-side behavior, follow the mu-plugin logger pattern:
   - Create a PHP mu-plugin that hooks the relevant action at priority 1
   - Log to a file in `wp-content/`
   - Read the file in your test assertions
4. Tests sharing wp-config or DB state must run sequentially (`workers: 1`, `fullyParallel: false`)
5. Add appropriate `test.setTimeout()` for tests involving multiple page loads

### Important Gotchas

- **Global regex `lastIndex`**: When using a global regex with `.test()` then `.replace()`, reset `lastIndex = 0` between calls. The `.test()` method advances `lastIndex`, causing `.replace()` to miss the match.
- **Cross-test race conditions**: Server-side `wp_safe_remote_post` is async. A previous test's AJAX call may complete during the next test. Avoid asserting on DB state set by async operations in other tests.
- **DISABLE_WP_CRON and login**: WordPress login can be slow when DISABLE_WP_CRON is active. The global setup caches auth to avoid repeated logins.

---

## Layer 3: k6 Load Tests

Simulates high-volume admin traffic to verify fixes hold under sustained and spike loads.

### File: `tests/perf/geoip-load.js`

### Scenarios

| Scenario | Pattern | Duration |
|----------|---------|----------|
| `steady_state` | 20 RPS constant | 2 minutes |
| `spike` | 5 → 50 → 5 RPS ramp | 1 minute (starts at 2m30s) |

### Thresholds

| Metric | Threshold |
|--------|-----------|
| `http_req_failed` | < 5% error rate |
| `http_req_duration` p95 | < 5 seconds |
| `admin_page_duration` p99 | < 8 seconds |

### Pre-Requisites for k6

k6 doesn't share Playwright's test helpers. The environment must be pre-configured:

1. `DISABLE_WP_CRON` added to wp-config.php
2. mu-plugin logger installed to `wp-content/mu-plugins/`
3. `slimstat_last_geoip_dl` option deleted from DB

The `run-qa.sh` script handles this automatically in Phase 3 (before k6, after Playwright).

### Running

```bash
# Via orchestrator (handles setup)
bash tests/run-qa.sh --perf-only

# Standalone (requires manual setup)
k6 run tests/perf/geoip-load.js

# With custom parameters
k6 run tests/perf/geoip-load.js -e BASE_URL=http://localhost:10003 -e WP_USER=parhumm -e WP_PASS=testpass123
```

### Writing New k6 Tests

1. Create `tests/perf/<scenario>.js`
2. Use `setup()` to authenticate via `wp-login.php` POST
3. Define scenarios and thresholds in `options`
4. Use `teardown()` to read/report oracle log files
5. Add a composer or npm script, and include in `run-qa.sh` if needed

---

## Orchestration: `tests/run-qa.sh`

Runs the full QA pipeline with proper setup and cleanup:

| Phase | What it does |
|-------|-------------|
| 0 | Verify site is running (HTTP check) |
| 1 | Backup wp-config.php |
| 2 | Run Playwright E2E (tests manage their own setup/teardown) |
| 3 | Configure environment for k6 (DISABLE_WP_CRON, mu-plugin, clear DB) |
| 4 | Run k6 load test |
| 5 | Post-test DB verification (check timestamp and AJAX count) |
| 6 | Summary and exit code |

Cleanup trap restores wp-config, removes mu-plugin, and clears AJAX log on exit.

---

## Adding Tests for a New Feature

### Decision: Which layer?

| If you need to test... | Use |
|----------------------|-----|
| Pure logic (no WordPress runtime needed) | PHP unit test |
| Browser behavior, admin UI, user roles | Playwright E2E |
| Performance under load, race conditions at scale | k6 load test |
| Server-side behavior invisible to browser | Playwright E2E + mu-plugin oracle |

### Checklist for new test files

- [ ] Test file created in appropriate directory
- [ ] Run command added to `composer.json` or `package.json`
- [ ] `test:all` array updated (PHP) or orchestration script updated (E2E/perf)
- [ ] Test passes locally
- [ ] Any new helpers documented in this guide
- [ ] Cleanup/teardown handles all state changes (wp-config, DB, files)

---

## File Reference

```
wp-slimstat/
├── composer.json                          # PHP test scripts (test:all, test:*)
├── package.json                           # Node test scripts (test:e2e, test:perf, test:qa)
├── tests/
│   ├── resolve-geolocation-provider-test.php
│   ├── geoservice-provider-resolution-test.php
│   ├── legacy-sync-mapping-test.php
│   ├── lazy-migration-test.php
│   ├── license-tag-gating-test.php
│   ├── tracking-rest-controller-test.php
│   ├── run-qa.sh                          # Full QA orchestrator
│   ├── e2e/
│   │   ├── playwright.config.ts
│   │   ├── global-setup.ts
│   │   ├── geoip-ajax-loop.spec.ts        # GeoIP AJAX loop E2E tests
│   │   ├── helpers/
│   │   │   ├── setup.ts                   # Shared test utilities
│   │   │   └── ajax-logger-mu-plugin.php  # Server-side AJAX observer
│   │   └── .auth/                         # Cached auth state (gitignored)
│   └── perf/
│       └── geoip-load.js                  # k6 load test
└── docs/
    └── TESTING.md                         # This file
```
