# Hosting qualification

These are local qualification instructions, not provider approval. Use disposable
databases only. Never point load, retention or E2E tests at the shared Local site.

## Required release comparisons

The primary comparisons are **Free 5.5.0 versus the Free 6+ candidate** and
**Pro 2.0.0 versus the Pro 3+ candidate**. Run Free alone and each matching
Free+Pro pair separately. The current development build is an additional
regression control, not a substitute for the released-version baseline.

Free 5.5.0's WordPress.org ZIP is pinned in `tests/docker/arms.sha256`:
`36d146b212046da027498ea745b1eb70935c9d64f378b4145697c695ba9f73b9`.
The available Pro 2.0.0 reconstruction is from tag commit
`8750d781009a95817809109430ea7b37f45e9039`, ZIP SHA256
`7d97d8dd75c52466aceb9bf434640c302a1d762533e8cc687fc929bc18594ed8`.
It is a tagged-source reconstruction, not verified customer-download bytes.
Retain this limitation in every report that uses it.

Use the vintage installer's native tables. `prepare-vintage.php` copies the
shared columns of the first million synthetic pageviews and their events from
the disposable `wordpress` corpus into an empty `tests-v550` installation.
Back up that fixture before benchmarking, then restore the identical data for
each arm and upgrade through the candidate's normal migration path. Record
schema/index differences and distinguish corrected metrics from regressions.

Before either PR, require exact-artifact Plugin Check static and runtime scans
and i18n verification. Keep raw findings visible; exclusions, policy exceptions
or unverified dependency findings are not a zero-warning pass.

## Proposed pilot (requires provider acceptance)

* Free client tracking (`javascript_mode=on`), REST transport; record AJAX fallback
  separately. Leave bypass transport disabled for this pilot.
* Keep each existing site's consent, exclusions and identity settings. Record them
  in the private manifest; do not export secrets, license keys or personal data.
* Proposed live retention: 30 days, **only after an operator reviews and explicitly
  saves that value**. The existing 420-day default and stored upgrade choices remain.
* Keep archive mode enabled unless the operator explicitly chooses irreversible
  expiry after backing up/exporting history. Archive mode moves history; it does
  not bound total storage. Existing archives are never removed by this pilot.
* Disable heatmap capture for the initial pilot. Previously retained heatmaps still
  need cleanup; disabling capture does not erase or exempt them. The selector
  dictionary has a separate lifetime.
* Automatic table optimization is off unless the operator enables it with the
  provider's agreement. Deletion normally makes pages reusable inside the database;
  it does not guarantee an immediate reduction in the filesystem allocation.
* Reverting retention or archive settings affects subsequent cleanup only. It
  cannot recover data that was explicitly deleted.

There is no automatic preset or settings migration. A retention interval cannot
promise a byte limit under arbitrary traffic. Provider workload and size limits
remain an external acceptance requirement.

## Early caches

Exclude POST requests to `/wp-json/slimstat/v1/hit` and
`/wp-admin/admin-ajax.php` from page/CDN caching. Verify the actual REST URL and
namespace in the rendered tracker parameters. If bypass is enabled separately,
exclude requests containing its configured tracking query parameter **at the
cache/CDN itself**, before WordPress starts. Do not cache rejected requests either.
The handler's no-store headers cannot undo a response already served by a cache.

Warm the frontend cache, submit a valid signed request, verify a tracking response
instead of HTML and exactly one new analytics row, then repeat with invalid input.
Record the cache product, version, rule and hit/miss headers. No untested CDN is
qualified by a plugin-level header test.

## Reproducible local tools

Use `tests/docker/build-free.sh <commit>` and `tests/docker/build-pro.sh` to build
committed source. Record both `.ref` and `.sha256` files. `run-cell.sh` requires
`QUALIFICATION_FREE_ZIP`, `QUALIFICATION_FREE_SHA256`, `QUALIFICATION_PRO_ZIP` and
`QUALIFICATION_PRO_SHA256`; see `tests/docker/README.md` for the isolated stack.
Run the source tests on the frozen build tree with its production autoloader;
run PHPUnit on the development checkout with development dependencies.

For host-side Playwright against the Docker stack, set `WP_CLI_DOCKER_CONTAINER`
as well as `TEST_BASE_URL`, `WP_ROOT`, `MYSQL_SOCKET=''`, `MYSQL_HOST`, `MYSQL_PORT`,
`MYSQL_DATABASE`, `WP_ADMIN_USER` and `WP_ADMIN_PASS`. Otherwise chart fixtures try
to run host PHP against Docker's internal database hostname.

Use `tests/bench/lib/seed.php` with `seed-profile-verify.json` for synthetic history,
and `tests/docker/report-answers.php` for paired report answers. Save a database
snapshot before any destructive tests. Restore the identical fixture for each arm;
record unsupported/empty/error surfaces separately, never as matching zeroes.

## Open safety gate: bounded archive cleanup

HC-F3 remains open. Adding LIMIT to the parent delete alone is unsafe: copying a
different set, cascading unarchived children, mutable live rows and archive-key
collisions can destroy history. Parent batching must also bound event and heatmap
work. An advisory lock excludes other cleanup jobs but does not exclude concurrent
tracking writes. InnoDB row locks alone do not protect child insertion on a no-FK
installation; table locks introduce privilege requirements and block tracking.

Do not ship an unverified replacement of this path. Require real-database proof
for matching copy/delete sets, every interruption point, conflicting archive IDs,
many children per parent, independent event expiry, FK/no-FK, external storage,
concurrent tracking, convergence and a stopped continuation when empty. The
existing unbounded implementation is **not** declared hosting-ready.

## Evidence boundaries

Keep insert-write time, HTTP endpoint latency, HTML TTFB, full-page load and
migration duration separate. Preserve #207's four budgets and #213's 50 RPS /
15 ms write P95 and 1M-row / 2 s admin P95. Local Docker timings are not host-led
approval. Record CPU, memory, runtime versions, cache state, settings, dataset,
errors, correctness, raw distributions, lock waits and the exact artifact hashes.
