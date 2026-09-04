/**
 * Shared environment resolution for every k6 script in tests/perf/.
 *
 * Why this exists: each script used to carry
 *
 *     const BASE_URL = __ENV.BASE_URL || 'http://localhost:10003';
 *     const WP_USER  = __ENV.WP_USER  || 'parhumm';
 *
 * so a CI run that forgot to export BASE_URL (which is exactly what happened —
 * ci.yml exported K6_BASE_URL, a name k6 never reads) still "succeeded": it
 * targeted a developer's Local WP install, got connection errors, and reported
 * timings for nothing. A perf gate that cannot fail is worse than no gate.
 *
 * There are deliberately no defaults. Resolution throws during k6's init phase,
 * which aborts the whole run rather than producing numbers for the wrong host.
 * Local runs pass the values explicitly:
 *
 *     BASE_URL=http://localhost:10003 WP_USER=admin WP_PASS=admin \
 *       k6 run tests/perf/geoip-load.js
 *
 * Enforced by tests/perf-gate-integrity-test.php.
 */

function resolve(name) {
  const value = __ENV[name];
  if (value === undefined || value === '') {
    throw new Error(
      `[perf] ${name} is not set. Measuring against the wrong host silently ` +
        `produces meaningless numbers, so this run is aborted.\n` +
        `  Example: BASE_URL=http://localhost:8889 WP_USER=admin WP_PASS=password k6 run <script>`
    );
  }
  return value;
}

/** Target site root, no trailing slash. */
export const BASE_URL = resolve('BASE_URL').replace(/\/+$/, '');

/**
 * WordPress admin credentials.
 *
 * A function, not top-level constants, so the two scripts that never
 * authenticate aren't forced to supply WP_USER/WP_PASS.
 */
export function credentials() {
  return {
    user: resolve('WP_USER'),
    pass: resolve('WP_PASS'),
  };
}
