# Contributing to WP Slimstat

Thanks for considering a contribution. This doc covers what's specific to
this repo — for the broader workspace (free + pro + jaan-to + tooling),
see `../CLAUDE.md` at the workspace root.

## Test pyramid

Every code change is expected to ship with the right layer of test
coverage. We organize tests in three tiers:

| Tier | Frequency | What runs | Where |
|------|-----------|-----------|-------|
| **Tier 1 · fast** | every push, every PR | PHPUnit + source-level + lint | `tests/Unit/`, `tests/*-test.php`, `php -l` |
| **Tier 2 · standard** | push or PR to `main` / `development` | Playwright E2E via wp-env | `tests/e2e/*.spec.ts` |
| **Tier 3 · nightly** | cron 02:00 UTC | Full PHP matrix + k6 perf | same files, broader matrix |

See [.github/workflows/ci.yml](.github/workflows/ci.yml) for the full
config.

## The source-level test contract

This repo's most-frequently-misunderstood pattern is the **source-level
test layer**. These are vanilla-PHP scripts (no PHPUnit dependency) that
run on every push, including on the PHP 7.4 lane where PHPUnit 10.5
cannot run.

Why this matters: the `Requires PHP: 7.4` claim in the plugin header is
only as strong as the CI lane that exercises it. Without source-level
tests, the 7.4 lane would be lint-only — and `php -l` does not verify
function existence, so a `str_contains()` call (PHP 8.0+) on PHP 7.4 ships
silently. That actually happened. See `tests/php74-no-php80-functions-test.php`.

### Three contract tests run on every push

| Script | Greps for | Enforces |
|--------|-----------|----------|
| `tests/php74-no-php80-functions-test.php` | `str_contains`, `str_starts_with`, `str_ends_with`, `fdiv`, `get_debug_type`, `preg_last_error_msg` in own code | No PHP 8.0+ stdlib calls without polyfill |
| `tests/php-implicit-nullable-test.php` | `function f(Type $x = null)` | No PHP 8.1+ E_DEPRECATED implicit-nullable signatures (fatal in PHP 9.0) |
| `tests/ci-matrix-coverage-test.php` | `Requires PHP:` header → matrix entries in ci.yml | Every supported PHP version (7.4–8.5) has at least one CI lane that runs PHPUnit or `composer test:*` (not just lint) |

Run locally:

```bash
composer test:source-level     # runs all three
composer test:php74-compat     # single test
```

### Allow-markers

Both grep tests support an allow-marker for legitimate exceptions:

```php
/* php-polyfill: ok */
echo str_contains($x, 'foo');  // intentional — polyfill bootstrap is loaded above
```

Add the marker on the comment line **immediately above** the call site.
Use sparingly — most violations should be fixed, not exempted.

### Adding a new source-level test

1. Write it as a vanilla PHP script in `tests/` (no PHPUnit dependency
   — must run on 7.4 lane).
2. Use `exit(1)` on failure, print a clear actionable error to stderr.
3. Mirror the file-walker pattern from `tests/php-implicit-nullable-test.php`
   (uses `RecursiveDirectoryIterator` + prunes `src/Dependencies/`).
4. Register the script in `composer.json` under `scripts` as
   `test:your-name`.
5. Add it to the `test:source-level` aggregator, and `test:all` will
   pick it up automatically.

## Symmetry with wp-slimstat-pro

The pro plugin has its **own** source-level test set:

| Pro test | Equivalent in free? |
|----------|---------------------|
| `tests/ci-matrix-coverage-test.php` | yes (same shape, derives floor from pro's plugin header) |
| `tests/pro-implicit-nullable-test.php` | similar, but scans `src/` + `addons/` (pro layout) |
| `tests/vendor-deprecation-allowlist-test.php` | **no** — pro bundles `illuminate/*` + `league/container` (which free does not); free's vendor has no implicit-nullable surface |

**The intentional divergence:** when you add a source-level test to free,
**don't mechanically add it to pro** — first check whether pro has the
same exposure. Both `CONTRIBUTING.md` files document their own test set.

## Before opening a PR

1. **Run `/simplify`** on your diff (per workspace `CLAUDE.md`). This
   is mandatory before every commit.
2. **Run `php -l`** on all changed PHP files.
3. **Run `composer test:source-level`** — all three should pass.
4. **Run `composer test:unit`** on PHP 8.1+.
5. **Run the E2E suite** if you touched admin, tracker, or settings UI:
   `npm run test:e2e`.

The Tier 1 fast CI gate runs all of the above except E2E in under 3
minutes — so you don't need to do all of this locally, but doing so
saves the round-trip.

## CHANGELOG, version bumps, and release

- Every user-visible change gets a `CHANGELOG.md` entry under the next
  patch version. Prepend at the top (most recent first).
- `readme.txt` has a separate, terser changelog block — keep it
  user-friendly.
- Version bump touches three places: `wp-slimstat.php` header,
  `wp-slimstat.php` `SLIMSTAT_ANALYTICS_VERSION` constant,
  `readme.txt` `Stable tag`. The
  `tests/ci-matrix-coverage-test.php` source-level test derives the PHP
  floor from the plugin header, so don't drift them.

For the full release workflow, run `/release-wp-slimstat`.
