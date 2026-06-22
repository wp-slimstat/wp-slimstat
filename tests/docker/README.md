# Docker PHP × WP matrix test harness

Reusable harness that spins a **real WordPress + MySQL** stack per
**PHP version × WordPress version** cell, installs **Free (this checkout)** +
the **built Pro zip**, and runs a full live check set on each:

1. **Activation** — Free + Pro activate; tables created (`init_tables`).
2. **Admin smoke** — Access Log / Audience / Goals&Funnels / Settings load (HTTP 200, no fatal).
3. **Tracking** — a hit records a `wp_slim_stats` row.
4. **PHP suites** — the standalone source-level + functional tests run *on that PHP version*.
5. **Playwright E2E** — the full suite runs against the cell (optional, on by default).
6. **debug.log scan** — no wp-slimstat fatals/deprecations.

It does **not** use the official `wordpress:` images — it builds `php:<ver>-apache`
itself, so any PHP from 7.4 through 8.4 works uniformly (and new versions are a
one-line edit).

## Prerequisites
- Docker (Desktop) running.
- Node + the plugin's `npm install` done (for the Playwright E2E step).
- A `wp-slimstat-pro` sibling checkout (only needed to build the Pro zip).

## Usage

```bash
cd tests/docker

# Run the whole matrix. Builds the Pro php-scoper zip automatically on first run
# (needs the wp-slimstat-pro sibling checkout). Edit matrix.env to change versions.
./run-matrix.sh

# …or run a single cell end-to-end:
./run-cell.sh 8.3 7.0 18000 13000

# …or (re)build just the Pro artifact:
./build-pro.sh
```

## Configuration — `matrix.env`
The only file you edit for future runs:

```bash
PHPS=(7.4 8.0 8.1 8.2 8.3 8.4)   # PHP versions (need a php:<ver>-apache image)
WPS=(5.6 7.0)                    # WordPress versions per PHP
CONCURRENCY=2                    # parallel cells (host Playwright is the limiter)
STRICT_DEPRECATIONS=0            # 1 = fail a cell on a wp-slimstat deprecation
RUN_E2E=1                        # 0 = skip Playwright for a fast smoke
CORE_SPECS=( … )                 # which E2E specs gate the verdict (full suite still runs, informational)
```

> The full Playwright suite runs against each cell, but only **CORE_SPECS** failures
> gate PASS/FAIL — the rest (GeoIP-DB / consent-CMP / seeded-fixture dependent) are
> reported informationally, since a bare Free+Pro container intentionally lacks those.

## Results
- Live grid + per-cell logs under `/tmp/php-matrix/`:
  - `matrix-summary.md` / `.json` — the PASS/FAIL/BLOCKED grid.
  - `cells/<php>-<wp>/artifacts/` — `cell.json` verdict, install/activate logs,
    admin smoke HTML, PHP-suite logs, `playwright/` report, `debug.log`.
- A timestamped copy of the summary is mirrored to
  `jaan-to/outputs/dev/php-matrix/<run-id>/`.

## Verdicts
- **PASS** — WordPress booted on that PHP and every plugin check passed.
- **FAIL** — a plugin check failed (real cross-version bug → triage with the
  cell's `debug.log` + `playwright/` report).
- **BLOCKED-BY-WP-CORE** — WordPress *core itself* can't boot on that PHP
  (e.g. WP 5.6 on PHP 8.1+). Not a plugin failure; the captured WP error is in
  the cell's `wp-install.log`. These cells are excluded from the FAIL count.

## How it works (notes for maintainers)
- The WP install is **bind-mounted to a host dir** per cell so the host-side
  Playwright `setup.ts` can write `wp-config.php` + `wp-content/mu-plugins`
  (`WP_ROOT` points there). Free is rsynced in from this checkout; Pro is
  installed from the built zip.
- MySQL is exposed on a host port; the E2E suite connects over TCP
  (`MYSQL_SOCKET=""`) and uses DB name `wordpress` (passes its data-safety guard).
- WP-core incompat is detected at `wp core install` time (and a home-page 500
  probe) *before* asserting any plugin health.
- Each cell uses a unique compose project + ports (bound to `127.0.0.1`) so
  cells run in parallel without colliding, and is torn down (`down -v`) after.
