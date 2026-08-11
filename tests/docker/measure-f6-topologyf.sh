#!/usr/bin/env bash
# tests/docker/measure-f6-topologyf.sh <free-ref|-> <pro-ref|-> [http_port] [db_port] [db2_port]
#
# P5's measurement: what a site does when its external analytics database BELONGS TO
# ANOTHER INSTALL — topology F, one Pro/free arm pair per invocation.
#
# The foreign claim is injected as a FIXTURE, identically in both arms: slim_meta is
# created on db2 by hand (the same DDL the manifest emits) and owner_site_url is set to
# another site's URL. That is byte-for-byte what this site sees when a second install
# claimed the database first, or when someone restored another install's dump into it —
# the identity primitive cannot tell those apart, which is the point of C48 (a dump
# carries owner_site_url; it does not carry this site's home_url).
#
#   stage `own`     — add-on on, tables created, identity minted (after arm), 3 hits.
#                     BOTH arms must land them: an unclaimed or self-owned database is
#                     writable, and this stage is the regression guard proving the
#                     ownership check does not refuse the legitimate owner.
#   stage `foreign` — owner_site_url now names another site. 3 more hits.
#                     before: they LAND (silent cross-install pollution — the defect).
#                     after:  they are REFUSED (row count frozen), degradation
#                     'external database (owner mismatch)' recorded with evidence,
#                     front page stays HTTP 200.
#   stage `adopt`   — SLIMSTAT_EXT_DB_ADOPT defined true in wp-config. 1 more hit.
#                     after: the site re-claims the database in writing — owner
#                     rewritten to home_url(), the hit lands. before: constant unknown,
#                     hit lands anyway (it never stopped).
#
# Counts and presence/absence only — no milliseconds.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

FREE_REF="${1:--}"
PRO_REF="${2:--}"
HTTP_PORT="${3:-18960}"
DB_PORT="${4:-13960}"
DB2_PORT="${5:-13961}"
PHP="${F6_PHP:-8.2}"
WP="${F6_WP:-6.7}"

CELL="f6tf-$(echo "$FREE_REF$PRO_REF" | tr -cd '[:alnum:]' | cut -c1-16)"
CELL_DIR="$WORK_ROOT/f6/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
PROJECT="ssf6$(echo "$CELL" | tr -cd '[:alnum:]' | tr '[:upper:]' '[:lower:]')"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
status="PASS"; reason=""

export COMPOSE_PROJECT_NAME="$PROJECT" PHP_VERSION="$PHP" HTTP_PORT DB_PORT DB2_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"
export DC_EXTRA_FILE="$HARNESS_DIR/docker-compose.db2.yml"
rm -rf "$WP_DIR"
mkdir -p "$WP_DIR" "$ART"

# ── per-ref arms + provisioning (all shared machinery: lib.sh) ──────────────
build_free_arm "$FREE_REF" "$CELL_DIR" || exit 1
build_pro_arm "$PRO_REF" "$CELL_DIR" "$ART" || exit 1

finish() {
  scan_debug_log "$WP_DIR" "$ART" || true
  write_verdict "$ART" "$CELL" "$PHP" "$WP" "$status" "$reason"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  cleanup_free_arm
  cleanup_pro_arm
  log "$CELL → $status ${reason:+($reason)}"
}
trap finish EXIT

echo "CONTROLS:"
echo "  free arm: $(free_arm_desc)"
if [ -n "$PRO_WT" ]; then echo "  pro arm:  $(git -C "$PRO_WT" rev-parse --short HEAD) (requested $PRO_REF), zip rebuilt for this arm"; else echo "  pro arm:  sibling checkout ($(git -C "$PRO_CHECKOUT" rev-parse --short HEAD), working tree)"; fi
echo "  foreign owner fixture: identical DDL + row in both arms — only the CODE differs"

log "[$CELL] build + up"
boot_stack "$ART" "$PHP" || { fail "stack did not come up"; exit 1; }
wait_for 40 3 dc exec -T db2 mysqladmin ping -h127.0.0.1 -uroot -proot --silent \
  || { fail "db2 did not come up"; exit 1; }

provision_wp_cell "$ART" "$WP" "$BASE_URL" "$FREE_SRC" || exit 1
wpc post create --post_title="post-1" --post_status=publish >>"$ART/install.log" 2>&1

enable_custom_db_addon db2 slimstat_ext "$ART"
# Tables (and, on the after arm, identity) on db2 via the admin path that owns DDL.
init_analytics_env "$ART"

rows() { dc exec -T db2 mysql -uroot -proot slimstat_ext -N -e "SELECT COUNT(*) FROM wp_slim_stats;" 2>/dev/null | tr -dc '0-9'; }
hit()  { local n; for n in $(seq 1 "$1"); do curl -s -o /dev/null -A "F6-probe/1.0" "$BASE_URL/?p=1&s=$2&hit=$n"; sleep 1; done; }
owner(){ dc exec -T db2 mysql -uroot -proot slimstat_ext -N -e "SELECT meta_value FROM wp_slim_meta WHERE meta_key='owner_site_url';" 2>/dev/null | tr -d '\r'; }
degr() { wpc eval '$d = get_option("slimstat_degradations", []); echo json_encode(array_keys(is_array($d) ? $d : []));' 2>/dev/null; }

# ── stage `own`: the legitimate owner writes ────────────────────────────────
echo "STAGE own:"
hit 3 own
r_own=$(rows)
echo "  rows after 3 hits as the owner: ${r_own:-?} (must be > 0 in BOTH arms — the check must not refuse the legitimate owner)"
echo "  owner_site_url now: '$(owner)' (after arm: this site; before arm: empty — no identity exists)"
[ "${r_own:-0}" -gt 0 ] || fail "the owner's own hits did not land — the cell is broken or the check over-refuses"

# ── stage `foreign`: the database now belongs to someone else ───────────────
echo "STAGE foreign:"
# The fixture: same DDL the manifest emits, applied by hand so BOTH arms carry it.
dc exec -T db2 mysql -uroot -proot slimstat_ext -e "
  CREATE TABLE IF NOT EXISTS wp_slim_meta (
    meta_key VARCHAR(191) NOT NULL,
    meta_value VARCHAR(2048) DEFAULT NULL,
    dt INT(10) UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT PRIMARY KEY (meta_key)
  ) COLLATE utf8mb4_unicode_ci ENGINE=InnoDB;
  INSERT INTO wp_slim_meta (meta_key, meta_value, dt) VALUES ('owner_site_url', 'https://site-b.example', 0)
    ON DUPLICATE KEY UPDATE meta_value = 'https://site-b.example';" >/dev/null 2>&1 \
  || fail "could not inject the foreign owner fixture"
echo "  owner_site_url set to: '$(owner)' (a second install's claim / a restored foreign dump)"

hit 3 foreign
r_foreign=$(rows)
FRONT_CODE=$(curl -s -o /dev/null -w '%{http_code}' -A "F6-probe/1.0" "$BASE_URL/")
echo "  rows after 3 hits against a FOREIGN database: ${r_foreign:-?} (before: grows — pollution; after: frozen at ${r_own:-?} — refused)"
echo "  front page HTTP: ${FRONT_CODE} (refusal must not kill the site)"
echo "  degradations: $(degr)"

# ── stage `adopt`: the escape hatch, in writing ─────────────────────────────
echo "STAGE adopt:"
wpc config set SLIMSTAT_EXT_DB_ADOPT true --raw --type=constant >>"$ART/settings.log" 2>&1
hit 1 adopt
r_adopt=$(rows)
echo "  rows after 1 hit with SLIMSTAT_EXT_DB_ADOPT: ${r_adopt:-?} (after: lands again — re-claimed)"
echo "  owner_site_url now: '$(owner)' (after: rewritten to this site)"

echo ""
echo "RESULT ($CELL): own=${r_own:-?} foreign=${r_foreign:-?} adopt=${r_adopt:-?} · artifacts in $ART"
