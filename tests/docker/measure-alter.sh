#!/usr/bin/env bash
# tests/docker/measure-alter.sh [rows] [http_port] [db_port]
#
# What does adding a column to the FACT table actually cost?
#
# F10 needs `ua_id BINARY(8)` on slim_stats. H2's gate governs any fact-table ALTER, and the
# gate wants a number, not an estimate — the plan's own audit found "fourteen probes become
# four" asserted from reading code and never counted against a database, and this is the same
# shape of claim.
#
# THE ANSWER DEPENDS ON THE SERVER, which is why this measures rather than reasons:
#
#   MySQL 8.0.12+   ADD COLUMN at the END of the row is ALGORITHM=INSTANT — a metadata change,
#                   constant time, no rebuild, no lock beyond the metadata latch.
#   MySQL 5.6/5.7   No INSTANT. ADD COLUMN is INPLACE at best and COPY at worst, and a COPY
#                   rebuilds every row and blocks writes for the duration.
#
# ADR-2 declares MySQL 5.6 the floor, so the floor is what decides whether this ships as a
# migration step or needs a different mechanism entirely. C49 already added 5.6/5.7 cells to the
# matrix for exactly this class of question.
#
# Reports the elapsed time per algorithm and, crucially, whether the server ACCEPTED the
# requested algorithm — MySQL silently falls back when it cannot honour one, so an INSTANT that
# was really a COPY looks identical in the timing unless you ask.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
[ -f "$HARNESS_DIR/matrix.env" ] && source "$HARNESS_DIR/matrix.env"

ROWS="${1:-150000}"
HTTP_PORT="${2:-18995}"
DB_PORT="${3:-13995}"
PHP="${TOPOLOGY_PHP:-8.2}"
WP="${TOPOLOGY_WP:-6.7}"

CELL="alter-cost"
CELL_DIR="$WORK_ROOT/alter/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"

export COMPOSE_PROJECT_NAME="ssalter" PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"

cleanup() { dc down -v --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT

rm -rf "$WP_DIR"; mkdir -p "$WP_DIR" "$ART"

log "[$CELL] build + up on ${MYSQL_IMAGE}"
boot_stack "$ART" "$PHP" || { err "stack did not come up"; exit 1; }

wpc core download --version="$WP" --force > "$ART/install.log" 2>&1 || { err "core download failed"; exit 1; }
wp_config_debug "$ART/install.log"
wpc core install --url="http://127.0.0.1:${HTTP_PORT}" --title="SS alter" --admin_user=admin \
    --admin_password=admin --admin_email=qa@example.com --skip-email >>"$ART/install.log" 2>&1 \
    || { err "core install failed"; exit 1; }

sync_plugin_src "$WP_DIR"
wpc plugin activate wp-slimstat >>"$ART/install.log" 2>&1
wpc eval 'include_once(WP_PLUGIN_DIR."/wp-slimstat/admin/index.php"); wp_slimstat_admin::init_tables($GLOBALS["wpdb"]); echo "t";' >>"$ART/install.log" 2>&1

log "[$CELL] seeding $ROWS rows"
dc exec -T -u www-data wp wp --path=/var/www/html eval-file \
   wp-content/plugins/wp-slimstat/tests/bench/lib/seed.php "$ROWS" 180 seed-profile-i8.json \
   > "$ART/seed.log" 2>&1 || { err "seeding failed"; exit 1; }

server_version=$(wpc db query "SELECT VERSION()" --skip-column-names 2>/dev/null | head -1 | tr -d '\r')
actual_rows=$(wpc db query "SELECT COUNT(*) FROM wp_slim_stats" --skip-column-names 2>/dev/null | tr -dc '0-9')
data_len=$(wpc db query "SELECT ROUND((DATA_LENGTH+INDEX_LENGTH)/1048576) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_slim_stats'" --skip-column-names 2>/dev/null | tr -dc '0-9')

echo
echo "  fact table: wp_slim_stats"
printf '    server      %s\n' "$server_version"
printf '    rows        %s\n' "$actual_rows"
printf '    size        %s MB (data + index)\n' "${data_len:-?}"
echo

# ── one ALTER per algorithm, each on a fresh copy of the table ──────────────
# A fresh copy per attempt, because the first ALTER changes what the second measures — and a
# second ADD COLUMN on an already-widened table is a different statement.
measure() {
  local algo="$1" label="$2"

  wpc db query "DROP TABLE IF EXISTS alter_probe" >/dev/null 2>&1
  wpc db query "CREATE TABLE alter_probe LIKE wp_slim_stats" >/dev/null 2>&1
  wpc db query "INSERT INTO alter_probe SELECT * FROM wp_slim_stats" >/dev/null 2>&1

  local start end out
  start=$(python3 -c 'import time;print(int(time.time()*1000))')
  out=$(wpc db query "ALTER TABLE alter_probe ADD COLUMN ua_id BINARY(8) NULL, ALGORITHM=${algo}" 2>&1)
  local rc=$?
  end=$(python3 -c 'import time;print(int(time.time()*1000))')

  if [ $rc -ne 0 ]; then
    printf '    %-22s REFUSED — %s\n' "$label" "$(echo "$out" | head -1 | cut -c1-80)"
    return
  fi

  printf '    %-22s %6s ms\n' "$label" "$((end - start))"
}

echo "  ADD COLUMN ua_id BINARY(8), by algorithm"
echo "  (each on a fresh copy — the first ALTER would change what the second measures)"
measure INSTANT "ALGORITHM=INSTANT"
measure INPLACE "ALGORITHM=INPLACE"
measure COPY    "ALGORITHM=COPY"
echo

wpc db query "DROP TABLE IF EXISTS alter_probe" >/dev/null 2>&1

cat <<'NOTE'
  Reading this:

    INSTANT accepted  -> the column is a metadata change. Ships as an ordinary migration step.
    INSTANT REFUSED   -> this server cannot do it, and the COPY figure is the real cost. On the
                         443k reference table, scale by rows; on a customer's larger table,
                         scale again. That is the number H2's gate is asking for.

  The COPY figure is the one that matters for ADR-2's floor, because MySQL 5.6 has no INSTANT
  and will silently fall back — which is why each algorithm is requested EXPLICITLY here rather
  than left to the server to choose quietly.
NOTE
