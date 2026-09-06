#!/usr/bin/env bash
# tests/docker/downgrade-corpus.sh <arm-ref> [source-dump.sql.gz] [out.sql.gz]
#
# BUILD THE CORPUS A VINTAGE CELL NEEDS, FROM A TABLE THAT VINTAGE ACTUALLY BUILT.
#
# ── Why this exists ─────────────────────────────────────────────────────────────────────────
#
# Cells 7a-7d upgrade FROM 4.8.1 / 5.1.5 / 5.2.13 / 5.4.12, and the only real corpus this
# programme has is a 5.5-shaped dump of the workspace's own site: 33 columns, `notes` already in
# the bracketed `[k:v][k:v]` form, no `plugins`. Hand that to a 4.8.1 arm and mysqldump's own
# CREATE TABLE replaces the arm's table with the dump's — the cell becomes 4.8.1 CODE on 5.5
# TABLES, every ADD COLUMN in the 4.8 blocks is a no-op against a column already present, every
# DROP finds nothing to drop, and the DDL path reports green having executed none of itself.
# rehearse-upgrade.sh's C1b (H3) now REFUSES that corpus. This is the other half: the thing that
# produces a corpus C1b accepts.
#
# ── Why it boots a whole cell to do it ──────────────────────────────────────────────────────
#
# The vintage's column list is not written down anywhere it could be read from — it is a PHP
# heredoc inside the arm's own admin file, interpolated with the table prefix. Every alternative
# to running it is a parser for a version of PHP's string syntax, which is the shape PITFALLS
# keeps recording: a table mistaken for a parser. So the arm is installed, ITS OWN installer is
# run (lib.sh's run_vintage_installer — the same helper the cell uses, or the two would build
# different tables), and the column list is read back out of information_schema. What comes out
# is what that vintage builds, not what we believe it builds.
#
# ── What it does ────────────────────────────────────────────────────────────────────────────
#
#   1  install the arm (wp.org bytes, sha256-pinned) and run its own installer
#   2  hydrate the source dump into a SCRATCH schema, untouched
#   3  INSERT ... SELECT the intersection of the two column lists into the arm's tables, under
#      STRICT_ALL_TABLES so a value that does not fit the older column is an ERROR and not a
#      silent truncation
#   4  below 4.8.8, un-bracket `notes` on the way through — the exact inverse of
#      admin/index.php's convert_notes_to_brackets, rendered from lib.sh's ONE copy of that
#      transform, and applied only to values that provably round-trip
#   5  mysqldump the arm's tables back out, gzipped, and re-read the result with dump_columns()
#      to prove the emitted corpus declares the arm's column set — the property C1b will check
#
# ── What it deliberately does NOT do ────────────────────────────────────────────────────────
#
# It does not invent rows, widen a column, or drop a row that will not fit. A value the older
# schema cannot hold stops this script with the server's own error, because the alternative is a
# corpus that is quietly not the site's data and a cell that rehearses an upgrade nobody will
# take. If that happens it is a finding about the vintage, and it belongs in the record.
#
# It also makes no claim about columns the arm has and the source dump lacks: those take the
# vintage's own DDL default and are NAMED in the output, because a corpus where 3 of 31 columns
# are DEFAULT NULL is a different subject from one where 31 of 31 came from a real site.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
[ -f "$HARNESS_DIR/matrix.env" ] && source "$HARNESS_DIR/matrix.env"

ARM_REF="${1:?arm ref — wp.org:<version> or a path to a plugin .zip}"
SRC_DUMP="${2:-}"
OUT="${3:-}"

# Vintage only, and refused rather than defaulted. A git ref resolves to a build of the CURRENT
# schema, so "downgrading" a corpus to it is a no-op that would emit a file named for a vintage
# and shaped like today — the exact confusion the whole H3/H4 pair exists to remove.
ARM_VERSION=$(arm_ref_version "$ARM_REF")
[ -n "$ARM_VERSION" ] || {
  err "downgrade-corpus.sh needs a wp.org:<version> arm: the output is named for a vintage"
  err "  and its shape must be that vintage's. Got '$ARM_REF'."
  exit 2; }

if [ -z "$SRC_DUMP" ]; then
  SRC_DUMP=$(latest_baseline_dump)
fi
[ -n "$SRC_DUMP" ] && [ -f "$SRC_DUMP" ] \
  || { err "no source dump: pass one, or run jaan-to/bin/slimstat-db.sh dump"; exit 2; }

# The same refusal C1 makes in the cell, made here instead — because a v6-shaped dump would be
# projected down to the vintage's columns without complaint, and the resulting corpus would be
# named for a vintage while silently having lost exactly the rows' worth of shape the source was
# chosen for. Caught at the source, once, rather than in four cells.
if dump_has_v6_columns "$SRC_DUMP"; then
  err "$(basename "$SRC_DUMP") already carries v6 columns (vid_hash/ua_id) — it is a MIGRATED"
  err "  dump, not a v5 corpus. Pick an earlier one from ~/slimstat-v6-baselines/."
  exit 2
fi

if [ -z "$OUT" ]; then
  OUT="$HOME/slimstat-v6-baselines/corpus-$ARM_VERSION-$(basename "$SRC_DUMP" .sql.gz).sql.gz"
fi

# H8: a 2019 plugin on a modern WordPress is what a real 4.8 site is today — not a 2019
# WordPress. The corpus builder pins the same topology the cell will use, so the tables it
# produces are the tables that vintage builds on the server the cell runs.
PHP="${TOPOLOGY_PHP:-7.4}"
WP="${TOPOLOGY_WP:-6.7}"
HTTP_PORT="${HTTP_PORT:-18995}"
DB_PORT="${DB_PORT:-13995}"

CELL="corpus-$ARM_VERSION"
CELL_DIR="$WORK_ROOT/rehearse/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
SRC_SCHEMA="corpus_src"

export COMPOSE_PROJECT_NAME="sscorpus$(printf '%s' "$ARM_VERSION" | tr -cd '0-9')"
export PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"

status="PASS"; reason=""
cleanup() { [ "${KEEP_CELL:-0}" = "1" ] || dc down -v --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT

rm -rf "$WP_DIR" "$ART"; mkdir -p "$WP_DIR" "$ART"

note()  { printf '  [%s] %s\n' "$1" "$2"; }
check() { if [ "$2" -eq 0 ]; then note PASS "$1${3:+ — $3}"; else note FAIL "$1${3:+ — $3}"; status=FAIL; reason="${reason:-$1}"; fi; }
# Every step below is a precondition for the next one, so a failure here is fatal rather than
# recorded: a corpus built on top of a failed projection is worse than no corpus.
must()  { check "$1" "$2" "${3:-}"; [ "$2" -eq 0 ] || { err "cannot continue"; exit 1; }; }

TABLES="wp_slim_stats wp_slim_stats_archive wp_slim_events wp_slim_events_archive"

echo "CONTROLS"
echo "  arm:         $ARM_REF"
echo "  source dump: $(basename "$SRC_DUMP")"
echo "  topology:    WP $WP / PHP $PHP"
echo "  output:      $OUT"
echo

log "[$CELL] build + up (PHP $PHP, WP $WP)"
boot_stack "$ART" "$PHP" || { err "stack did not come up — see $ART/build.log, $ART/up.log"; exit 1; }

ARM_ZIP=$(resolve_arm_zip "$ARM_REF") || exit 1
export ARM_FREE_ZIP="$ARM_ZIP"
log "[$CELL] arm $ARM_REF -> $(basename "$ARM_ZIP") (pinned)"
provision_wp_cell "$ART" "$WP" "$BASE_URL" "$PLUGIN_SRC" || exit 1

GOT_VERSION=$(arm_installed_version)
if [ "$GOT_VERSION" = "$ARM_VERSION" ]; then _r=0; else _r=1; fi
must "the installed arm is the vintage requested" "$_r" "WordPress reports ${GOT_VERSION:-nothing}"

INSTALLER=$(run_vintage_installer 2>>"$ART/install.log" | tr -d '[:space:]')
case "$INSTALLER" in
  admin/*) must "the arm's own installer built the arm's own tables" 0 "$INSTALLER" ;;
  *)       must "the arm's own installer built the arm's own tables" 1 "${INSTALLER:-no output}" ;;
esac

# ── The scratch schema ──────────────────────────────────────────────────────
# A second schema in the same server, never the cell's own: the source dump carries its own
# CREATE TABLE for each table, and importing it into `wordpress` would replace the tables the arm
# just built — which is the failure this whole script exists to prevent, committed by the script
# that prevents it.
log "[$CELL] hydrating $(basename "$SRC_DUMP") into $SRC_SCHEMA"
mysql_q "DROP DATABASE IF EXISTS $SRC_SCHEMA; CREATE DATABASE $SRC_SCHEMA DEFAULT CHARACTER SET utf8mb4;" >/dev/null
if ! import_gz_into_schema "$SRC_DUMP" "$SRC_SCHEMA" "$ART/import.err"; then
  err "hydration into $SRC_SCHEMA failed — see $ART/import.err"
  exit 1
fi

# table_columns() reads `wordpress`; the scratch schema needs the same question asked of it.
src_columns() { table_columns "$1" "$SRC_SCHEMA"; }
src_rows() { row_count "$SRC_SCHEMA" "$1"; }
arm_rows() { row_count wordpress "$1"; }

# ── The notes transform, below 4.8.8 ────────────────────────────────────────
# NOT a blanket un-bracketing. `[a:1][b:2]` inverts to `a:1;b:2` and the plugin's own forward
# transform turns that back into `[a:1][b:2]` — but a value whose payload already contains a `;`
# does not round-trip, and neither does one that is itself bracketed twice. Un-bracketing those
# would hand the cell a corpus whose migration CANNOT reproduce the original, and the R3
# fingerprint would then report data loss the plugin did not cause.
#
# So the projection is guarded by the round trip itself, expressed with the plugin's own forward
# expression: convert only where forward(inverse(notes)) = notes. Rows that fail it stay
# bracketed — the migration's `NOT LIKE '[%'` walks past them, and rehearse-upgrade.sh's
# fingerprint projection uses that same predicate, so both sides agree about them.
NOTES_EXPR="notes"
IS_PRE_488=0
if version_lt "$ARM_VERSION" "4.8.8"; then
  IS_PRE_488=1
  _inv=$(notes_inverse notes)
  NOTES_EXPR="CASE WHEN notes IS NOT NULL AND notes <> '' AND notes LIKE '[%' AND notes LIKE '%]'
                    AND ($_inv) NOT LIKE '[%'
                    AND $(notes_forward "($_inv)") = notes
               THEN ($_inv) ELSE notes END"
  echo "  notes:       $ARM_VERSION is below 4.8.8 — the bracketed form is un-bracketed on the way in"
else
  echo "  notes:       $ARM_VERSION is 4.8.8 or newer — the bracketed form is already this vintage's"
fi

# ── The projection ──────────────────────────────────────────────────────────
echo
echo "── projecting the corpus into the arm's own tables ──────────────────────"
TOTAL_IN=0; TOTAL_OUT=0
for t in $TABLES; do
  a_cols=$(table_columns "$t")
  s_cols=$(src_columns "$t")
  if [ -z "$a_cols" ]; then
    must "the arm built $t" 1 "the vintage's installer did not create it"
  fi
  if [ -z "$s_cols" ]; then
    note NOTE "$t is not in the source dump — the arm's empty table is carried through"
    continue
  fi

  dropped=$(columns_missing_from "$s_cols" "$a_cols")   # in the corpus, not in this vintage
  defaulted=$(columns_missing_from "$a_cols" "$s_cols") # in this vintage, not in the corpus
  shared=$(columns_missing_from "$s_cols" "$dropped")   # the intersection, in the source's order

  # SELECT list: the shared columns, with `notes` replaced by the guarded inverse below 4.8.8.
  sel=""; ins=""
  IFS=',' read -r -a _cols <<< "$shared"
  for c in "${_cols[@]}"; do
    [ -n "$c" ] || continue
    ins="${ins:+$ins, }\`$c\`"
    if [ "$c" = "notes" ]; then sel="${sel:+$sel, }$NOTES_EXPR"; else sel="${sel:+$sel, }\`$c\`"; fi
  done
  [ -n "$ins" ] || must "$t shares at least one column with the corpus" 1 "no overlap at all"

  n_in=$(src_rows "$t"); TOTAL_IN=$(( TOTAL_IN + ${n_in:-0} ))
  echo "  $t: ${n_in:-0} rows, $(count_columns "$shared") shared columns"
  [ -n "$dropped" ]   && note NOTE "  dropped (this vintage has no such column): $dropped"
  [ -n "$defaulted" ] && note NOTE "  left at the vintage's DDL default (the corpus has no such column): $defaulted"

  # STRICT_ALL_TABLES is the whole point of the statement. Without it MySQL truncates a value
  # that does not fit the older, narrower column and reports a warning nobody reads — and the
  # corpus is then quietly not the site's data. FOREIGN_KEY_CHECKS off because slim_events
  # references slim_stats and the tables are filled one at a time.
  if ! mysql_exec "
      SET SESSION sql_mode='STRICT_ALL_TABLES';
      SET FOREIGN_KEY_CHECKS=0;
      INSERT INTO wordpress.\`$t\` ($ins) SELECT $sel FROM $SRC_SCHEMA.\`$t\`;
      SET FOREIGN_KEY_CHECKS=1;" "$ART/project-$t.log"; then
    err "projecting $t failed — the older schema could not hold a value the corpus carries:"
    sed -n '1,10p' "$ART/project-$t.log" >&2
    err "This is a finding about $ARM_VERSION, not a harness bug. Record it."
    exit 1
  fi

  n_out=$(arm_rows "$t"); TOTAL_OUT=$(( TOTAL_OUT + ${n_out:-0} ))
  if [ "${n_out:-0}" = "${n_in:-0}" ]; then _r=0; else _r=1; fi
  check "every $t row survived the projection" "$_r" "${n_in:-0} -> ${n_out:-0}"
done

# ── Did the notes transform actually happen, and is there work left for the migration? ──
# Both halves matter and neither implies the other. A corpus with zero un-bracketed notes gives
# cell 7a a 4.8.8 block that runs over nothing and an H5 fingerprint equality that cannot fail —
# green, and evidence of nothing. So the count is asserted, not printed.
if [ "$IS_PRE_488" = 1 ]; then
  PENDING=$(scalar_q "SELECT COUNT(*) FROM wordpress.wp_slim_stats WHERE $(notes_pending notes);")
  STILL_BRACKETED=$(scalar_q "SELECT COUNT(*) FROM wordpress.wp_slim_stats
                                WHERE notes IS NOT NULL AND notes <> '' AND notes LIKE '[%';")
  if [ "${PENDING:-0}" -gt 0 ]; then _r=0; else _r=1; fi
  check "the corpus gives the 4.8.8 conversion something to convert" "$_r" \
        "${PENDING:-0} rows in the pre-4.8.8 form"
  [ "${STILL_BRACKETED:-0}" -gt 0 ] && note NOTE \
    "$STILL_BRACKETED rows kept the bracketed form — their payload does not round-trip, and the migration walks past them by the same predicate"
fi

# ── Emit ────────────────────────────────────────────────────────────────────
echo
echo "── emitting the corpus ──────────────────────────────────────────────────"
mkdir -p "$(dirname "$OUT")"
if ! dump_schema_gz wordpress "$OUT" "$ART/dump.err" $TABLES; then
  err "mysqldump failed — see $ART/dump.err"
  exit 1
fi

# The loop closed. The corpus is only useful if rehearse-upgrade.sh's C1b will ACCEPT it, and
# C1b's question is exactly this one — asked of the FILE, with the same helper, before any
# import. Asking it here means a corpus that would stall a 90-minute cell fails in this script
# instead.
OUT_COLS=$(dump_columns "$OUT" wp_slim_stats)
ARM_COLS=$(table_columns wp_slim_stats)
ONLY_ARM=$(columns_missing_from "$ARM_COLS" "$OUT_COLS")
ONLY_OUT=$(columns_missing_from "$OUT_COLS" "$ARM_COLS")
if [ -z "$ONLY_ARM" ] && [ -z "$ONLY_OUT" ]; then _r=0; else _r=1; fi
check "the emitted corpus declares the arm's own column set (this is what C1b will ask)" "$_r" \
      "arm-only: ${ONLY_ARM:-none}; corpus-only: ${ONLY_OUT:-none}"

OUT_SHA=$(digest "$OUT")
ARM_COL_N=$(count_columns "$ARM_COLS")

write_verdict "$ART" "$CELL" "$PHP" "$WP" "$status" "$reason" \
  "\"arm\":\"$ARM_REF\",\"source_dump\":\"$(basename "$SRC_DUMP")\",\"source_sha256\":\"$(digest "$SRC_DUMP")\",\"corpus\":\"$(basename "$OUT")\",\"corpus_sha256\":\"$OUT_SHA\",\"columns\":$ARM_COL_N,\"rows_in\":$TOTAL_IN,\"rows_out\":$TOTAL_OUT"
DEST=$(publish_verdict "$ART" "$CELL")

echo
echo "  corpus:  $OUT"
echo "  sha256:  $OUT_SHA"
echo "  columns: $ARM_COL_N (the $ARM_VERSION shape)"
echo "  rows:    $TOTAL_IN in -> $TOTAL_OUT out"
[ -n "${DEST:-}" ] && echo "  verdict: $DEST/cell.json"

echo
if [ "$status" = "PASS" ]; then
  echo "VERDICT: a $ARM_VERSION-shaped corpus of $TOTAL_OUT rows, built by $ARM_VERSION's own installer"
  echo "  next:  SCENARIO=U1 TOPOLOGY_PHP=$PHP TOPOLOGY_WP=$WP ./rehearse-upgrade.sh $ARM_REF <new-ref> $OUT"
  exit 0
fi
echo "VERDICT: FAILED — $reason"
exit 1
