#!/usr/bin/env bash
# tests/docker/measure-arms.sh <before-ref> <after-ref> [topology] [http_port] [db_port]
#
# Counts the SQL a named workload issues, BEFORE and AFTER a change, on the same container and
# the same database — then prints the difference.
#
# WHY THIS EXISTS. Every query-count claim on this branch so far came from reading code, or from
# a unit test counting calls against a mock. "14 probes become 4" was never counted against a
# database. This counts them.
#
# WHAT IT IS NOT. It reports COUNTS, and wall-clock only as a secondary column. The containers
# hold the 40-row golden fixture, so a millisecond figure here is noise — seam I8 rebuilds the
# fixture with a real time axis and cardinality, and until it lands no timing taken anywhere in
# this programme is a claim. Reporting ms without that caveat beside it is how a number becomes
# a fact it never earned.
#
# HOW THE ARMS ARE FAIR. One container, one database, one workload script; only the plugin
# source is swapped between arms, from a git worktree at each ref. Arms are run twice in
# A-B-B-A order and the LAST pair reported, so that first-run effects (cold caches, autoloader
# warm-up, a lazily-created option row) fall on the discarded pair rather than on one arm.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
[ -f "$HARNESS_DIR/matrix.env" ] && source "$HARNESS_DIR/matrix.env"

BEFORE="${1:?before ref}"
AFTER="${2:?after ref}"
TOPOLOGY="${3:-C-subdir}"
HTTP_PORT="${4:-18950}"
DB_PORT="${5:-13950}"
PHP="${TOPOLOGY_PHP:-8.2}"
WP="${TOPOLOGY_WP:-6.7}"

CELL="measure-${TOPOLOGY}"
CELL_DIR="$WORK_ROOT/measure/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
BENCH_DIR="$CELL_DIR/bench"
WORKTREES="$CELL_DIR/arms"
PROJECT="ssmeasure_$(echo "$TOPOLOGY" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]')"

export COMPOSE_PROJECT_NAME="$PROJECT" PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"

rm -rf "$WP_DIR" "$BENCH_DIR"
mkdir -p "$WP_DIR" "$ART" "$BENCH_DIR" "$WORKTREES"

cleanup() { dc down -v --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT

# ── one worktree per arm, so neither arm is "the working tree" ───────────────
for ref in "$BEFORE" "$AFTER"; do
  dir="$WORKTREES/$ref"
  if [ ! -d "$dir" ]; then
    git -C "$PLUGIN_SRC" worktree add --detach "$dir" "$ref" >/dev/null 2>&1 \
      || { err "cannot create a worktree at $ref"; exit 1; }
  fi
  # Normalisation runs on EVERY invocation, not only on creation: the first attempt built these
  # worktrees before this step existed, and the second silently reused the unbootable ones.

  # Bring the vendor tree over, then regenerate the SHIPPED autoloader for this arm's own
  # src/. Normalisation, declared: the change under test is the schema code, not the
  # autoloader, and both arms must at least boot.
  #
  # It is not optional. At 52ffe631 the COMMITTED autoloader is the dev one — its `$files`
  # loop eagerly requires Mockery/PHPUnit bootstraps that exist in no shipped artifact — so
  # that arm fatals in autoload_real.php before any workload runs. That is PITFALLS #2, and
  # measuring it here showed it is worse than recorded: the commit could not have booted from
  # its own ZIP.
  rsync -a "$PLUGIN_SRC/vendor/" "$dir/vendor/" >/dev/null 2>&1
  ( cd "$dir" && composer run build:autoload >/dev/null 2>&1 ) \
    || warn "could not regenerate the autoloader at $ref — that arm may not boot"
done

log "[$CELL] build + up (PHP $PHP, WP $WP)"
boot_stack "$ART" "$PHP" || { err "stack did not come up"; exit 1; }

wpc core download --version="$WP" --force > "$ART/install.log" 2>&1 || { err "core download failed"; exit 1; }
wp_config_debug "$ART/install.log"

# Arm the ledger. SLIMSTAT_BENCH is the authorization and lives in wp-config; the label is
# passed per-invocation as an env var.
#
# The ledger directory is created as root and chowned first: it lives OUTSIDE the docroot by
# design, so www-data cannot mkdir it, and the first run failed with "Permission denied" on
# exactly that — an empty ledger that the runner reported as "measured nothing" rather than
# silently printing zeros.
dc exec -T -u root wp mkdir -p /var/www/bench >/dev/null 2>&1
dc exec -T -u root wp chown www-data:www-data /var/www/bench >/dev/null 2>&1

wpc config set SLIMSTAT_BENCH true --raw --type=constant >>"$ART/install.log" 2>&1
wpc config set SLIMSTAT_BENCH_DIR /var/www/bench --type=constant >>"$ART/install.log" 2>&1

wpc core multisite-install --url="http://127.0.0.1:${HTTP_PORT}" --title="SS measure" \
    --admin_user=admin --admin_password=admin --admin_email=qa@example.com --skip-email \
    >>"$ART/install.log" 2>&1 || { err "multisite-install failed"; exit 1; }

for slug in two three four; do
  wpc site create --slug="$slug" >>"$ART/install.log" 2>&1
done
wpc site archive 4 >>"$ART/install.log" 2>&1

mkdir -p "$WP_DIR/wp-content/mu-plugins"

# ── swap one arm in ─────────────────────────────────────────────────────────
use_arm() {
  local ref="$1"
  rm -rf "$WP_DIR/wp-content/plugins/wp-slimstat"
  rsync -a --delete --exclude '.git' --exclude 'node_modules' --exclude 'tests/e2e/node_modules' \
        "$WORKTREES/$ref/" "$WP_DIR/wp-content/plugins/wp-slimstat/" >/dev/null 2>&1
  # The ledger always comes from the CURRENT tree, never from the arm — an instrument that
  # changes between arms measures itself as well as the change.
  cp "$PLUGIN_SRC/tests/bench/mu/slimstat-bench-qlog.php" "$WP_DIR/wp-content/mu-plugins/" 2>/dev/null
  chmod -R a+rwX "$WP_DIR/wp-content" 2>/dev/null || true
}

# ── the workload ────────────────────────────────────────────────────────────
# Schema reconcile on a HEALTHY install: every table and index already present. This is the
# common case and the one the "14 probes become 4" claim is about.
run_workload() {
  local ref="$1" label="$2"
  dc exec -T -u www-data -e SLIMSTAT_BENCH_LABEL="$label" -e SLIMSTAT_BENCH_SQL=all wp \
     wp --path=/var/www/html eval '
       include_once(WP_PLUGIN_DIR . "/wp-slimstat/admin/index.php");
       wp_slimstat_admin::init_tables($GLOBALS["wpdb"]);
       echo "ok";
     ' >>"$ART/workload.log" 2>&1
}

# The DEGRADED workload: drop one index, then reconcile.
#
# The healthy workload above cannot distinguish an arm that skipped creation because nothing was
# missing from an arm that can no longer create at all — both produce an identical ledger when
# everything is present. A blind adjudicator refused to call the arms equivalent without this,
# and was right to: "nothing happened" and "nothing can happen" are the same observation until
# something needs to happen.
run_degraded() {
  local label="$1"
  dc exec -T -u www-data wp wp --path=/var/www/html db query \
     "DROP INDEX idx_dt_platform ON wp_slim_stats" >>"$ART/workload.log" 2>&1 || true

  dc exec -T -u www-data -e SLIMSTAT_BENCH_LABEL="$label" -e SLIMSTAT_BENCH_SQL=all wp \
     wp --path=/var/www/html eval '
       include_once(WP_PLUGIN_DIR . "/wp-slimstat/admin/index.php");
       wp_slimstat_admin::init_tables($GLOBALS["wpdb"]);
       echo "ok";
     ' >>"$ART/workload.log" 2>&1

  # Did the index come back? This is the answer the healthy workload cannot give.
  #
  # COUNT(DISTINCT INDEX_NAME), not COUNT(*): information_schema.STATISTICS holds one row per
  # index COLUMN, and idx_dt_platform is (dt, platform) — so COUNT(*) answered 2 for "present",
  # which reads as a quantity when it is a boolean.
  # Raw output kept verbatim beside the parsed value. `tr -dc '0-9'` over a stream that may
  # carry a client warning turns any stray digit into the answer, and a capability check that
  # silently reports the wrong integer is worse than one that errors.
  dc exec -T -u www-data wp wp --path=/var/www/html db query \
     "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_slim_stats'
         AND INDEX_NAME = 'idx_dt_platform'" --skip-column-names > "$ART/cap-${label}.raw" 2>&1

  head -1 "$ART/cap-${label}.raw" | tr -dc '0-9'
}

# The MISSING-TABLE workload: drop a whole table, then reconcile.
#
# Added because a blind adjudicator, having confirmed both arms rebuild a dropped INDEX, refused
# to call them equivalent: an arm that issues no DDL on a healthy install has not been shown to
# create a missing TABLE, only shown not to need to. "Untested, not disproven" was the phrase,
# and it is the sharpest question the index workload leaves open — dropping ten statements per
# invocation is only free if nothing was lost with them.
run_missing_table() {
  local label="$1"

  dc exec -T -u www-data wp wp --path=/var/www/html db query \
     "DROP TABLE IF EXISTS wp_slim_events_archive" >>"$ART/workload.log" 2>&1 || true

  dc exec -T -u www-data -e SLIMSTAT_BENCH_LABEL="$label" -e SLIMSTAT_BENCH_SQL=all wp \
     wp --path=/var/www/html eval '
       include_once(WP_PLUGIN_DIR . "/wp-slimstat/admin/index.php");
       wp_slimstat_admin::init_tables($GLOBALS["wpdb"]);
       echo "ok";
     ' >>"$ART/workload.log" 2>&1

  dc exec -T -u www-data wp wp --path=/var/www/html db query \
     "SELECT COUNT(*) FROM information_schema.TABLES
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_slim_events_archive'" \
     --skip-column-names > "$ART/cap-${label}.raw" 2>&1

  head -1 "$ART/cap-${label}.raw" | tr -dc '0-9'
}

# Activate once per arm before measuring, so activation's own queries are not in the sample.
prime() {
  dc exec -T -u www-data wp wp --path=/var/www/html eval '
    include_once(WP_PLUGIN_DIR . "/wp-slimstat/admin/index.php");
    foreach (get_sites(["number" => 0, "archived" => null, "deleted" => null, "spam" => null]) as $s) {
      switch_to_blog($s->blog_id);
      activate_plugin("wp-slimstat/wp-slimstat.php");
      wp_slimstat_admin::init_tables($GLOBALS["wpdb"]);
      restore_current_blog();
    }
    echo "primed";
  ' >>"$ART/workload.log" 2>&1
}

# ── seal the arms ───────────────────────────────────────────────────────────
# The refs are replaced by opaque labels `arm-1` / `arm-2`, assigned in an order decided here
# and written to seal.json. Everything downstream — the ledger, the per-arm extracts, the
# adjudicating reader — sees only the labels.
#
# This is not decoration (PITFALLS #15 says to say so when it is). The adjudication is done by
# agents that receive one arm each and are told nothing about what changed or which direction
# would be welcome. An agent told "this is the after arm" has a direction to find, and query
# counts are exactly the kind of number where a motivated reader finds one.
if [ $((RANDOM % 2)) -eq 0 ]; then
  ARM1="$BEFORE"; ARM2="$AFTER"
else
  ARM1="$AFTER";  ARM2="$BEFORE"
fi
printf '{"arm-1":"%s","arm-2":"%s"}\n' "$ARM1" "$ARM2" > "$ART/seal.json"

# A-B-B-A: the reported pair is the second, so cold-cache and first-touch effects land on the
# discarded first pair rather than on whichever arm happened to run first.
for round in warm measured; do
  for arm in arm-1 arm-2; do
    ref="$ARM1"; [ "$arm" = "arm-2" ] && ref="$ARM2"
    use_arm "$ref"
    prime
    run_workload "$ref" "${round}.${arm}"
  done
done

# Capability check, after the cost measurement so it cannot perturb it. Recorded per arm as a
# plain 1/0: did the dropped index exist again afterwards?
: > "$ART/capability.txt"
for arm in arm-1 arm-2; do
  ref="$ARM1"; [ "$arm" = "arm-2" ] && ref="$ARM2"
  use_arm "$ref"
  prime
  restored=$(run_degraded "degraded.${arm}")
  printf '%s rebuilt_dropped_index=%s\n' "$arm" "${restored:-0}" >> "$ART/capability.txt"

  recreated=$(run_missing_table "missing-table.${arm}")
  printf '%s recreated_dropped_table=%s\n' "$arm" "${recreated:-0}" >> "$ART/capability.txt"
done

# ── collect ─────────────────────────────────────────────────────────────────
dc exec -T wp cat /var/www/bench/qlog.jsonl > "$ART/qlog.jsonl" 2>/dev/null || true

if [ ! -s "$ART/qlog.jsonl" ]; then
  err "the ledger is empty — the workload ran but nothing was counted, so this run measured nothing"
  exit 1
fi

python3 - "$ART" <<'PY'
import json, os, sys

art = sys.argv[1]
rows = [json.loads(l) for l in open(os.path.join(art, 'qlog.jsonl')) if l.strip()]

# One file per arm, labelled ONLY arm-1 / arm-2. These are what the adjudicating agents read;
# nothing in them names a ref, a direction, or a change.
missing = []
for arm in ('arm-1', 'arm-2'):
    hits = [r for r in rows if r.get('label') == 'measured.' + arm]
    if not hits:
        missing.append(arm)
        continue
    with open(os.path.join(art, arm + '.json'), 'w') as fh:
        json.dump(hits[-1], fh, indent=2)

if missing:
    print('FAIL: no measured sample for ' + ', '.join(missing))
    sys.exit(1)

# The degraded-arm ledgers too, so the adjudicator can see what each arm DID about the gap.
for arm in ('arm-1', 'arm-2'):
    for kind in ('degraded', 'missing-table'):
        hits = [r for r in rows if r.get('label') == kind + '.' + arm]
        if hits:
            with open(os.path.join(art, '%s-%s.json' % (arm, kind)), 'w') as fh:
                json.dump(hits[-1], fh, indent=2)

print()
print('  sealed. two arm extracts written, labelled arm-1 / arm-2:')
for arm in ('arm-1', 'arm-2'):
    print('    %s' % os.path.join(art, arm + '.json'))
print()
print('  the arm -> ref mapping is in seal.json and is NOT printed here.')
print('  adjudicate the two extracts first, then unseal.')
PY

log "[$CELL] ledger: $ART/qlog.jsonl   arms: $ART/arm-1.json $ART/arm-2.json   seal: $ART/seal.json"
