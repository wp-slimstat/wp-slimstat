#!/usr/bin/env bash
# tests/docker/lib.sh — shared helpers for the PHP×WP matrix harness.
# Sourced by run-cell.sh and run-matrix.sh.

# Resolve paths relative to this harness dir.
HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SRC="$(cd "$HARNESS_DIR/../.." && pwd)"   # wp-slimstat repo root
WORK_ROOT="${WORK_ROOT:-/tmp/php-matrix}"
PRO_ZIP="${PRO_ZIP:-$HARNESS_DIR/build/wp-slimstat-pro.zip}"

# Signatures that mean WordPress *core* couldn't boot on this PHP (not a plugin bug).
WP_CORE_FATAL_PATTERNS='Parse error|Fatal error|Uncaught|requires PHP|unsupported|syntax error'
# True if $1 (a log file) contains a WP-core fatal signature.
has_wp_core_fatal() { grep -qiE "$WP_CORE_FATAL_PATTERNS" "$1" 2>/dev/null; }

log()  { printf '\033[1;34m[%s]\033[0m %s\n' "$(date +%H:%M:%S)" "$*"; }
warn() { printf '\033[1;33m[%s] WARN:\033[0m %s\n' "$(date +%H:%M:%S)" "$*"; }
err()  { printf '\033[1;31m[%s] ERROR:\033[0m %s\n' "$(date +%H:%M:%S)" "$*"; }
die()  { err "$*"; exit 1; }

# Two primitives every drill and measurement script here had privately. canary/run-canary.sh
# sourced this file and then redefined `say` byte-identically to `log` above and `die` as `err`
# plus an exit — paying for the source and then shadowing what it bought, so a later edit to `log`
# would appear to have no effect there.
#
# reachability/run-gate.sh has its own copies and keeps them: it deliberately does NOT source this
# file, and its recorded verdict binds to a subject digest under a container-backed gate this
# session cannot execute. Converting a gate on the strength of reading it is the thing PITFALLS
# keeps recording; it is filed as debt instead.
digest() { shasum -a 256 "$1" | awk '{print $1}'; }
now()    { date -u +%FT%TZ; }

# WHERE AN ARM'S WORKTREE LIVES — one definition, because two scripts now write to it.
# compare-answers.sh creates and reuses these; canary/run-canary.sh patches one in place before
# handing the run to verify-change.sh. The path was composed by hand in both, which meant a layout
# change in the owner would have silently sent the drill's `patch -p1 -d` somewhere else.
arm_worktree_dir() { printf '%s/%s/%s/arms/%s' "$WORK_ROOT" "${2:-answers}" "${2:-answers}" "$1"; }

# ── Vintage arms: the ZIP wordpress.org serves, not a ZIP we built ──────────
# H1. Cells 7a-7d upgrade FROM 4.8.1 / 5.1.5 / 5.2.13 / 5.4.12, and build-free.sh cannot produce
# those arms: it hard-requires `.distignore` at the ref, which the 4.8 line predates by years.
# The wp.org ZIP is therefore not merely a more faithful arm, it is the ONLY arm those vintages
# can have — so `use_ref` accepts `wp.org:<version>` and a bare `.zip` path beside a git ref.
#
# Bytes we did not build must be pinned, hence arms.sha256. The failure this refuses is quiet:
# a truncated download, a re-rolled ZIP, a proxy error page saved under the right name — each
# gives a cell that runs to completion and reports on code no site is running. An unpinned
# version is refused BEFORE the download, so "it worked, add the hash after" cannot become the
# habit.
ARMS_DIR="${ARMS_DIR:-$HOME/slimstat-v6-baselines/arms}"
ARMS_MANIFEST="${ARMS_MANIFEST:-$HARNESS_DIR/arms.sha256}"
ARMS_BASE_URL="${ARMS_BASE_URL:-https://downloads.wordpress.org/plugin}"

# The version an arm ref names, or empty for a git ref. Split out because the container-side H1
# control compares it against what WordPress reports, and a second hand-rolled `${ref#wp.org:}`
# there is how the two would drift.
arm_ref_version() { # <ref>
  case "$1" in
    wp.org:*) printf '%s\n' "${1#wp.org:}" ;;
    *)        : ;;
  esac
}

# The pinned digest for a version, or exit 1 if this version is not in the manifest.
arm_manifest_sha() { # <version>
  awk -v want="wp-slimstat.$1.zip" '$2 == want { print $1; hit = 1 } END { exit hit ? 0 : 1 }' \
    "$ARMS_MANIFEST"
}

# Resolve an arm ref to a ZIP on disk, downloading and verifying when it names a wp.org version.
# THREE exit codes, because two would lose the distinction that matters:
#   0  a vintage arm; the path is on stdout
#   2  not a vintage ref at all — the caller should build it from git, as it always did
#   1  a vintage ref that could not be honoured — unpinned, undownloadable, or wrong bytes
# Returning 2 as "failure" would send a typo'd `wp.org:5.5` down the build path, where
# `git rev-parse` fails with a message about a commit that has nothing to do with the mistake.
# Every diagnostic goes to stderr EXPLICITLY. `err` writes to stdout like `log` does, and this
# function's return value IS its stdout — so an unredirected message is captured by
# `zip=$(resolve_arm_zip ...)` and becomes a path-shaped string naming no file. Found by the
# test below, which asserts stdout is empty on every refusal. PITFALLS 130.
resolve_arm_zip() { # <ref>
  local ref="$1" ver zip want got
  case "$ref" in
    wp.org:*) ver=$(arm_ref_version "$ref"); zip="$ARMS_DIR/wp-slimstat.$ver.zip" ;;
    *.zip)    ver=""; zip="$ref" ;;
    *)        return 2 ;;
  esac

  if [ -n "$ver" ]; then
    # Validated before it reaches a path or a URL: `wp.org:../../etc/passwd` is a version string
    # only in the sense that nothing had looked at it.
    printf '%s' "$ver" | grep -qE '^[0-9]+(\.[0-9]+){1,3}$' \
      || { err "not a plugin version: '$ver'" >&2; return 1; }
    want=$(arm_manifest_sha "$ver") \
      || { err "no pinned sha256 for $ver in $ARMS_MANIFEST — add the line in the same commit as the cell that needs it" >&2; return 1; }
    if [ ! -s "$zip" ]; then
      mkdir -p "$ARMS_DIR"
      curl -fsS -o "$zip.part" "$ARMS_BASE_URL/wp-slimstat.$ver.zip" \
        || { rm -f "$zip.part"; err "could not fetch wp-slimstat.$ver.zip from $ARMS_BASE_URL" >&2; return 1; }
      mv "$zip.part" "$zip"
    fi
  fi

  [ -s "$zip" ] || { err "arm ZIP not found or empty: $zip" >&2; return 1; }

  if [ -n "$ver" ]; then
    got=$(digest "$zip")
    [ "$got" = "$want" ] || {
      err "arm ZIP for $ver is not the pinned bytes: expected $want, got $got" >&2
      return 1
    }
  fi

  printf '%s\n' "$zip"
}

# ── Talking to the cell's database ─────────────────────────────────────────
# One row per line, tab-separated, no column headers — the shape every helper below parses.
# It lived in rehearse-upgrade.sh and downgrade-corpus.sh needs the identical thing, which is the
# point at which a private copy becomes drift. A caller may still define its own after sourcing
# this file (tests/rehearsal-column-control-test.php stubs it exactly that way, and the later
# definition wins).
mysql_q() { dc exec -T db mysql -uroot -proot -N -e "$1" 2>/dev/null; }

# One value, whitespace stripped. Every `COUNT(*)` call site was writing the same `tr -d` after
# mysql_q, and one that forgot it compared "114507\n" against "114507".
scalar_q() { # <sql>
  mysql_q "$1" | tr -d '[:space:]'
}

row_count() { # <schema> <table>
  scalar_q "SELECT COUNT(*) FROM \`$1\`.\`$2\`;"
}

# A statement run for its EFFECT, where the server's error text is the thing worth keeping.
# mysql_q swallows stderr — correct when parsing a result set, wrong when a failing INSERT is the
# finding — so this one captures both streams to a log and returns the client's exit status.
mysql_exec() { # <sql> <logfile>
  dc exec -T db mysql -uroot -proot -e "$1" > "$2" 2>&1
}

# A gzipped dump into a named schema. The redirection has to be built here rather than at the
# call site: `gzip -dc … | dc exec` is the only shape that streams a 100 MB dump without landing
# it in the container first.
import_gz_into_schema() { # <dump.gz> <schema> <errlog>
  gzip -dc "$1" | dc exec -T db mysql -uroot -proot "$2" 2>"$3"
}

# The reverse. --no-tablespaces because the client has no PROCESS privilege on 8.0 and the dump
# fails without it; --single-transaction so the count cannot move underneath the dump.
dump_schema_gz() { # <schema> <out.sql.gz> <errlog> <table...>
  local schema="$1" out="$2" errlog="$3"
  shift 3
  dc exec -T db mysqldump -uroot -proot --no-tablespaces --single-transaction "$schema" "$@" \
    2>"$errlog" | gzip -c > "$out"
  # PIPESTATUS, not $?: gzip succeeds on the empty stream a failed mysqldump leaves behind, and
  # the result would be a valid .gz containing no corpus at all.
  [ "${PIPESTATUS[0]}" -eq 0 ]
}

# ── Which dump is the corpus? ──────────────────────────────────────────────
# Newest first, and the caller still has to decide whether it is a v5 corpus: as of 2026-09-05
# the newest dump in the baselines directory is a MIGRATED one carrying vid_hash, so `ls -t |
# head -1` now selects a file every vintage cell must refuse. Both halves live here so the
# refusal is one function rather than a grep each caller writes slightly differently.
latest_baseline_dump() {
  ls -t "$HOME"/slimstat-v6-baselines/slim-analytics-*.sql.gz 2>/dev/null | head -1
}

dump_has_v6_columns() { # <dump.gz> — true (0) when the dump is post-migration
  gzip -dc "$1" 2>/dev/null | grep -qE '`vid_hash`|`ua_id`'
}

# ── The cell's topology, pinned rather than defaulted (H8) ─────────────────
# rehearsal-cells.tsv holds one row per WS4 cell: cell, old_ref, new_ref, wp, php, rationale.
# A cell with no row keeps the caller's defaults — this is additive, and a cell that has not been
# characterised yet should not be blocked by the file that characterises the ones that have.
REHEARSAL_CELLS="${REHEARSAL_CELLS:-$HARNESS_DIR/rehearsal-cells.tsv}"

cell_field() { # <cell> <1-based field> — empty when the cell has no row
  [ -f "$REHEARSAL_CELLS" ] || return 0
  awk -F'\t' -v c="$1" -v n="$2" '$1 == c { print $n; exit }' "$REHEARSAL_CELLS"
}

# Which cell is this run? Keyed off the OLD ref, because that is what actually distinguishes
# 7a from 7d — the arm a site is upgrading from — and because it means a caller states the arm
# once on the command line rather than also naming a cell that has to agree with it.
cell_for_ref() { # <old_ref>
  [ -f "$REHEARSAL_CELLS" ] || return 0
  awk -F'\t' -v r="$1" '$2 == r { print $1; exit }' "$REHEARSAL_CELLS"
}

# ── The vintage arm's own installer (H2) ───────────────────────────────────
# The single line this replaced named `admin/index.php`, which 4.8.1 does not ship: that vintage
# keeps the same class, and the same `wp_slimstat_admin::init_tables($_wpdb='')` signature, in
# admin/wp-slimstat-admin.php. include_once on a missing path is a WARNING, not a fatal, so the
# old line went to a log and the caller carried on with NO wp_slim_stats at all — and then the
# hydration creates the table itself, from the dump, and every schema assertion downstream
# describes the DUMP's shape while claiming to describe the arm's.
#
# Which file ran is echoed back, so a caller's verdict NAMES it rather than assuming it, and the
# two failure modes are distinct strings rather than one silent empty line.
#
# Lives here because two scripts run it: rehearse-upgrade.sh (before hydrating a corpus) and
# downgrade-corpus.sh (before projecting one), and they must build the same tables the same way
# or the corpus does not fit the cell it was built for.
run_vintage_installer() { # optional site URL for per-blog lifecycle rehearsal
  wpc ${1:+--url="$1"} eval '
    $dir = WP_PLUGIN_DIR . "/wp-slimstat/";
    $f = file_exists($dir . "admin/index.php") ? "admin/index.php"
       : (file_exists($dir . "admin/wp-slimstat-admin.php") ? "admin/wp-slimstat-admin.php" : "");
    if ($f === "") { echo "NOFILE"; }
    else {
      include_once($dir . $f);
      if (!method_exists("wp_slimstat_admin", "init_tables")) { echo "NOMETHOD"; }
      else {
        wp_slimstat_admin::init_tables($GLOBALS["wpdb"]);
        /* LEAVE THE OPTIONS ROW A REAL SITE OF THIS VINTAGE WOULD HAVE.
           init_tables() ends, in every vintage, with a comment that says it saves the version in
           the database and an assignment that only touches the in-memory array. The arm own
           saver would persist it -- but slimstat_save_options() takes the settings signature
           AFTER merging defaults over the stored row, so on a request where nothing actually
           changed it short-circuits and writes nothing at all. Combined with an activation hook
           (init_environment) that only calls init_tables, a freshly activated vintage has NO
           options row: it first appears on some later request that changes a setting.

           An absent row is not a neutral starting state. The next code to read the version gets
           array_merge(init_options(), $stored), whose default IS the reading code own version, so
           a 4.8.1 arm introduces itself to its own upgrade path as 6.0.0 and every 4.8.x block is
           skipped (PITFALLS 133) -- and v6 goes further, reading an absent row as a FRESH INSTALL
           while 443k rows sit in the table (PITFALLS 134).

           So the default is a site that has been USED, written as the arm own saver would have
           written it on that first changing request: the arm own merged settings, carrying the
           arm own version. REHEARSE_ARM_OPTIONS=absent opts out and rehearses the other site,
           which is the reproduction for 134. They are different subjects, and a cell records
           which one it ran. */
        if (getenv("REHEARSE_ARM_OPTIONS") !== "absent") {
          $row = get_option("slimstat_options", []);
          /* CLI activation can leave only the installer version in memory/on disk.
             Save the vintage defaults as its settings screen would, not a partial row that
             makes the next network activation read undefined keys such as auto_purge. */
          wp_slimstat::$settings = array_merge(wp_slimstat::init_options(), is_array($row) ? $row : [], wp_slimstat::$settings);
          update_option("slimstat_options", wp_slimstat::$settings);
        }
        /* The return value stays the installer FILENAME and nothing else: both callers compare it
           to a path, and appending a status word here would have failed that comparison in a way
           that reads as "the vintage installer is missing". */
        echo $f;
      }
    }'
}

# The version in the OPTIONS ROW. This is NOT the same question as
# `wp_slimstat::$settings["version"]`, and the difference is the whole of PITFALLS 133: init()
# builds $settings as array_merge(init_options(), $stored) with stored winning, and
# init_options() supplies `version => SLIMSTAT_ANALYTICS_VERSION`. So a row with no version key
# reads back through $settings as the version of the code asking -- never empty, never wrong
# looking, and exactly the value that makes every upgrade block skip itself.
#
# Empty output means the row genuinely has no version key, which is a state a caller has to
# handle rather than paper over: it is the difference between "upgrading from 4.8.1" and "we do
# not know what this is upgrading from".
stored_plugin_version() {
  wpc eval '
    $o = get_option("slimstat_options", []);
    if (!is_array($o)) { $o = []; }
    echo isset($o["version"]) ? $o["version"] : "";' 2>/dev/null | tr -d '[:space:]'
}

# What WordPress says is installed, which is a different claim from what resolve_arm_zip
# verified. `wp plugin install --force` can succeed on a ZIP whose folder name collides with an
# existing plugin directory, leaving the previous arm in place — and then every assertion
# downstream describes the wrong vintage.
arm_installed_version() {
  wpc plugin get wp-slimstat --field=version 2>/dev/null | tr -d '[:space:]'
}

# ── What shape is this table, and what shape is that corpus? ────────────────
# H3. A vintage cell is only a vintage cell if the CORPUS matches the arm. The existing C1 asks
# the dump for `vid_hash`/`ua_id` and stops there, which a 5.5-shaped dump passes under a 4.8.1
# arm — and then the cell is 4.8.1 code on 5.5 tables: every ADD COLUMN in the 4.8 blocks is a
# no-op against a column that is already there, every DROP finds nothing to drop, and the cell
# reports the DDL path green having executed none of it. That is the failure this pair of
# functions exists to make impossible, and it is invisible in every other signal a cell emits.
#
# The comparison is column SET against column SET: what the arm's own DDL just built (read from
# information_schema, so it is the schema that exists rather than the schema we believe in) and
# what the dump file declares (read from the FILE, before the import — afterwards the table IS
# the dump's shape and the question can no longer be asked).

# The live column set of a table, sorted, comma-joined. Empty when the table does not exist.
# The schema defaults to the cell's own `wordpress`; downgrade-corpus.sh asks the identical
# question of its scratch schema, and one function that takes the schema beats two that differ
# by a string literal — the pair would drift in the sort order and the difference would read as
# a schema difference.
table_columns() { # <table> [schema]
  mysql_q "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA='${2:-wordpress}' AND TABLE_NAME='$1' ORDER BY COLUMN_NAME;" \
    | tr -d '\r' | sed '/^$/d' | sort | tr '\n' ',' | sed 's/,$//'
}

# How many columns are in one of those comma-joined sets. Counting `,`+1 is wrong for the empty
# set (it would say 1), and that case is precisely the one a caller is trying to detect.
count_columns() { # <set>
  printf '%s\n' "$1" | tr ',' '\n' | sed '/^$/d' | grep -c '' || true
}

# The column set a gzipped dump declares for a table, in the same shape. Reads only that CREATE
# TABLE block: index lines are `KEY` / `PRIMARY KEY` / `UNIQUE KEY`, which carry a backtick but
# not in first position, and the range ends at the closing `)` so a second table in the same
# file cannot leak in.
dump_columns() { # <dump.gz> <table>
  gzip -dc "$1" \
    | sed -n "/^CREATE TABLE \`$2\` (/,/^)/p" \
    | sed -n 's/^  `\([^`]*\)`.*/\1/p' \
    | sort | tr '\n' ',' | sed 's/,$//'
}

# Which columns are in the first set and not in the second. Both take the comma-joined form
# above; the result is comma-joined too, and empty when the first set is contained in the second.
columns_missing_from() { # <set-a> <set-b>
  local haystack=",$2," c
  # `printf '%s\n'`, not `printf '%s'`: BSD sed does not add the trailing newline the input
  # lacks, and `read` returns false on a final unterminated line WITHOUT running the body, so the
  # LAST column silently dropped out of every difference. Caught by the empty-second-set case in
  # tests/rehearsal-column-control-test.php, which is why that case is there. PITFALLS 131.
  printf '%s\n' "$1" | tr ',' '\n' | sed '/^$/d' | while IFS= read -r c; do
    case "$haystack" in
      *",$c,"*) : ;;
      *)        printf '%s\n' "$c" ;;
    esac
  done | tr '\n' ',' | sed 's/,$//'
}

# ── Which vintage is this, and is it older than the block under test? ───────
# H4/H5. A vintage cell's corpus and its fingerprint both branch on the arm's version: the notes
# column is semicolon-separated below 4.8.8 and bracketed at and above it, and `plugins` /
# `email` / `fingerprint` / `tz_offset` split at 4.8.4.1. `sort -V` is not portable enough to
# decide that (BSD sort grew -V late, and this runs on the host, not in the container), and
# `version_compare` lives in PHP. awk is everywhere and the comparison is four lines.
#
# Component-wise and numeric, so 4.8.10 is above 4.8.9 — which is exactly what a string compare
# gets wrong, and the vintages this programme installs (4.8.1 … 5.4.12) contain the case.
# Missing components are 0, so 4.8 < 4.8.1 and 4.8.0 == 4.8.
version_lt() { # <a> <b> — true (0) when a is strictly older than b
  awk -v a="$1" -v b="$2" 'BEGIN{
    na = split(a, A, "."); nb = split(b, B, ".");
    n = (na > nb) ? na : nb;
    for (i = 1; i <= n; i++) {
      x = (i <= na) ? A[i] + 0 : 0; y = (i <= nb) ? B[i] + 0 : 0;
      if (x < y) exit 0;
      if (x > y) exit 1;
    }
    exit 1 }'
}

# ── The notes transform, in ONE place ──────────────────────────────────────
# H5. Below 4.8.8 `notes` is `k:v;k:v`; at 4.8.8 the upgrade rewrites it to `[k:v][k:v]`. That
# transform is written in admin/index.php (convert_notes_to_brackets), and a vintage cell needs
# it twice more: downgrade-corpus.sh applies its INVERSE to build a pre-4.8.8 corpus, and
# rehearse-upgrade.sh projects it forward so the v5 fingerprint can be compared ACROSS a
# migration that legitimately rewrites the column.
#
# Three copies of a transform is how the rehearsal ends up asserting its own idea of the
# conversion instead of the plugin's. So the expression lives here once, and
# tests/rehearsal-vintage-corpus-test.php asserts that what this renders is character-for-
# character the statement admin/index.php issues. Change the plugin's transform and the gate goes
# red until the rehearsal follows it — which is the only arrangement under which the rehearsal's
# equality is evidence about the plugin rather than about itself.
#
# printf templates, not strings with a name substituted: the forward expression is applied to
# `notes` in the cell and to the INVERSE EXPRESSION in the corpus builder's round-trip guard, and
# a substitution that matched the bare word `notes` inside `REPLACE( notes, ...)` would rewrite
# the wrong occurrence.
NOTES_FORWARD_TEMPLATE="CONCAT( '[', REPLACE( %s, ';', '][' ), ']' )"
# The rows the conversion is OWED, as admin/index.php's own WHERE clause states them: not NULL
# (NULL NOT LIKE '[%' is NULL, so NULL was already excluded — stated rather than relied upon),
# not empty (the empty string satisfies NOT LIKE '[%' and would become the literal '[]'), and not
# already bracketed.
NOTES_PENDING_TEMPLATE="%s IS NOT NULL AND %s <> '' AND %s NOT LIKE '[%%'"
# The inverse: strip the outer brackets, then `][` back to `;`. Used only by the corpus builder.
NOTES_INVERSE_TEMPLATE="REPLACE( SUBSTRING( %s, 2, CHAR_LENGTH(%s) - 2 ), '][', ';' )"

notes_forward() { printf "$NOTES_FORWARD_TEMPLATE" "${1:-notes}"; }
notes_pending() { printf "$NOTES_PENDING_TEMPLATE" "${1:-notes}" "${1:-notes}" "${1:-notes}"; }
notes_inverse() { printf "$NOTES_INVERSE_TEMPLATE" "${1:-notes}" "${1:-notes}"; }

# ── Indexes (H6) ───────────────────────────────────────────────────────────
# `SHOW INDEX` appeared nowhere in this harness, so every index claim in the programme rested on
# the COLUMN existing. P1 is "the upgraded install ends with idx_vid_hash_dt", and a column check
# cannot fail when the index is the thing that is missing — which is the one failure V1 registers
# a mutation for: Schema::ensure() skips a manifest index over a column the same pass adds, so an
# UPGRADED install can carry the column and no index while a FRESH one has both.
#
# information_schema.STATISTICS rather than SHOW INDEX because it is queryable: SHOW returns a
# result set shaped for a human and cannot be filtered or ordered in SQL.
index_columns() { # <table> <index> — the index's columns in SEQ order, comma-joined; empty if absent
  mysql_q "SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA='wordpress' AND TABLE_NAME='$1' AND INDEX_NAME='$2'
             ORDER BY SEQ_IN_INDEX;" \
    | tr -d '\r' | sed '/^$/d' | tr '\n' ',' | sed 's/,$//'
}

# Present at all. Deliberately NOT the whole assertion a cell makes: an index of the right name
# over the wrong columns answers yes here and is useless to the query it exists for, so the cells
# assert index_columns() and use this only where presence really is the question.
has_index() { # <table> <index>
  [ -n "$(index_columns "$1" "$2")" ]
}

# ── Verdicts that outlive /tmp (H7) ────────────────────────────────────────
# Run 63 filed two cell verdicts and both are gone: WORK_ROOT is /tmp/php-matrix, and the record
# in VERIFICATION-PROTOCOL.md cites paths that no longer exist. A verdict nobody can open is a
# claim, not evidence.
#
# The destination is under jaan-to/outputs/, i.e. the ROOT repo rather than this submodule, and
# that directory's .gitignore excludes runs/ wholesale — so the exception for run65-rehearsal/ is
# committed beside this, otherwise the copy lands somewhere `git status` will never mention and
# the next session loses it exactly the way Run 63 did.
publish_verdict() { # <art_dir> <cell> [extra artifact basenames...]
  local art="$1" cell="$2" dest f
  shift 2
  dest="${REHEARSAL_RUNS_DIR:-$PLUGIN_SRC/../jaan-to/outputs/dev/v6-performance/runs/run65-rehearsal}/$cell"
  mkdir -p "$dest" 2>/dev/null || { warn "could not create $dest — the verdict stays in $art only"; return 1; }
  cp "$art/cell.json" "$dest/cell.json" 2>/dev/null \
    || { warn "no cell.json in $art to publish"; return 1; }
  for f in "$@"; do
    [ -f "$art/$f" ] && cp "$art/$f" "$dest/$f" 2>/dev/null
  done
  printf '%s\n' "$dest"
}

# Write a cell's verdict JSON. Args: art_dir cell php wp status reason [extra json fields]
#
# The 7th argument is a pre-formatted JSON fragment WITHOUT the surrounding braces, spliced in
# before `ts`. It exists because a durable verdict (publish_verdict, H7) has to answer "which arm,
# which corpus, which topology" a year from now, and cell/php/wp/status/reason cannot. Optional,
# so run-cell.sh and run-topology.sh keep calling this unchanged; read_verdict_status is
# unaffected either way, since it reads `status` by name and not by position.
write_verdict() {
  local art="$1" cell="$2" php="$3" wp="$4" status="$5" reason="$6" extra="${7:-}"
  # A double quote in the reason would close the JSON string early, so it becomes a single one.
  # Via a VARIABLE holding that quote, and neither obvious spelling works: `\'` is not an escape
  # in the replacement half of ${var//pat/repl}, so it substituted a literal backslash-quote and
  # `\'` is not valid JSON — the verdict parsed as nothing. A bare `'` there does not survive
  # either: inside the double-quoted expansion it OPENS a quoted section and swallows the rest of
  # the file, which `bash -n` reports 200 lines later as an unbalanced paren in build_pro_arm.
  # Both failed on exactly the FAIL path, where the reason is non-empty and the verdict matters
  # most; every PASS verdict written so far had an empty reason and could not show it. Found by
  # the quote-in-reason case in tests/rehearsal-vintage-corpus-test.php. PITFALLS 132.
  local sq="'"
  reason="${reason//\"/$sq}"
  [ -z "$extra" ] || extra="${extra%,},"
  printf '{"cell":"%s","php":"%s","wp":"%s","status":"%s","reason":"%s",%s"ts":"%s"}\n' \
    "$cell" "$php" "$wp" "$status" "$reason" "$extra" "$(date -u +%FT%TZ)" > "$art/cell.json"
}

# Wait until a command succeeds or times out. Args: tries sleep cmd...
wait_for() {
  local tries="$1" nap="$2"; shift 2
  local i
  for ((i=1; i<=tries; i++)); do "$@" >/dev/null 2>&1 && return 0; sleep "$nap"; done
  return 1
}

# Record a cell failure without aborting: downgrade status, keep the FIRST reason.
# Callers declare `status="PASS"; reason=""` before use; write_verdict reads both.
fail() { status="FAIL"; reason="${reason:-$1}"; err "$1"; }

# Final qualification supplies both an artifact and its independently recorded digest.
extract_qualification_artifact() { # <zip> <sha256> <slug> <empty destination>
  python3 "$HARNESS_DIR/extract-artifact.py" "$1" "$2" "$3" "$4"
}

verify_qualification_artifact() { # <zip> <sha256> <slug> <plugins directory>
  python3 "$HARNESS_DIR/extract-artifact.py" "$1" "$2" "$3" "$4" --verify-installed
}

# ── Pro measurement arm ─────────────────────────────────────────────────────
# Resolve which shipped wp-slimstat-pro build a two-arm measurement installs: '-' = the sibling
# checkout's committed HEAD, a ref = that exact commit. build/build-dist.sh owns the only scoper
# toolchain; this helper never manufactures a second artifact recipe. Sets PRO_CHECKOUT,
# PRO_RESOLVED_REF and ARM_PRO_ZIP. Extracted when
# measure-f6-useroverview.sh needed the second copy of measure-f6-external.sh's block:
# arm provenance is the part a sealed measurement's credibility rests on, so it gets one
# owner, like the bring-up helpers above.
build_pro_arm() { # <pro_ref|-> <cell_dir> <art_dir>
  local ref="$1" cell_dir="$2" art="$3"
  PRO_CHECKOUT="${PRO_REPO:-$(cd "$PLUGIN_SRC/.." && pwd)/wp-slimstat-pro}"
  PRO_WT=""
  [ "$ref" = "-" ] && ref=HEAD
  PRO_RESOLVED_REF=$(git -C "$PRO_CHECKOUT" rev-parse "$ref^{commit}") \
    || { err "cannot resolve Pro ref $ref"; return 1; }
  if [ -n "${QUALIFICATION_PRO_ZIP:-}" ]; then
    extract_qualification_artifact "$QUALIFICATION_PRO_ZIP" "${QUALIFICATION_PRO_SHA256:?Pro ZIP digest required}" wp-slimstat-pro "$cell_dir/pro-artifact" || return 1
    ARM_PRO_ZIP="$QUALIFICATION_PRO_ZIP"
    return 0
  fi
  ARM_PRO_ZIP="$HARNESS_DIR/build/wp-slimstat-pro-${PRO_RESOLVED_REF:0:8}.zip"
  PRO_REF_OVERRIDE="$PRO_RESOLVED_REF" PRO_ZIP_OUT="$ARM_PRO_ZIP" \
    PRO_BUILD_LOG="$art/build-pro.log" bash "$HARNESS_DIR/build-pro.sh" \
    || { err "Pro shipped build at $ref failed (see build-pro.log)"; return 1; }
}

# Retained for callers whose traps predate the shipped-artifact builder. Safe to call unconditionally.
cleanup_pro_arm() {
  return 0
}

# The free half of the same provenance rule: '-' = the working tree as it stands, a ref =
# a detached worktree of exactly that ref. Sets FREE_SRC and FREE_WT ('' = working tree).
build_free_arm() { # <free_ref|-> <cell_dir>
  FREE_SRC="$PLUGIN_SRC"; FREE_WT=""
  if [ -n "${QUALIFICATION_FREE_ZIP:-}" ]; then
    extract_qualification_artifact "$QUALIFICATION_FREE_ZIP" "${QUALIFICATION_FREE_SHA256:?Free ZIP digest required}" wp-slimstat "$2/free-artifact" || return 1
    FREE_SRC="$2/free-artifact/wp-slimstat"
    ARM_FREE_ZIP="$QUALIFICATION_FREE_ZIP"
    return 0
  fi
  if [ "$1" != "-" ]; then
    FREE_WT="$2/free-src"; rm -rf "$FREE_WT"
    git -C "$PLUGIN_SRC" worktree add --detach "$FREE_WT" "$1" >/dev/null 2>&1 \
      || { err "cannot create free worktree at $1"; return 1; }
    FREE_SRC="$FREE_WT"
  fi
}

cleanup_free_arm() {
  [ -n "${FREE_WT:-}" ] && git -C "$PLUGIN_SRC" worktree remove --force "$FREE_WT" >/dev/null 2>&1
  return 0
}

# Run a probe twice and byte-compare its fenced JSON — the same-arm null control every
# measure script performs. Extracted at the FOURTH private copy, which was also the first
# to drift (no diff evidence on mismatch, wpc hand-expanded). Args: <probe container path>
# <marker prefix (e.g. UO-NET-JSON)> <artifact basename> ; artifacts land as
# $ART/<base>-1.json, -2.json and, when identical, $ART/<base>.json. Uses fail(), so the
# caller's cell FAILs (without exiting) on any probe error or a mismatch — and a mismatch
# prints the diff head, because "two different answers" with no evidence forces a container
# re-run to learn what differed.
probe_null_control() { # <probe_path> <marker> <base>
  local probe="$1" marker="$2" base="$3" run
  for run in 1 2; do
    wpc eval-file "$probe" > "$ART/$base-run$run.out" 2>&1 || fail "$base probe run $run errored"
    awk -v m="$marker" '$0 == m "-BEGIN" {f=1; next} $0 == m "-END" {f=0} f' \
      "$ART/$base-run$run.out" > "$ART/$base-$run.json"
    [ -s "$ART/$base-$run.json" ] || fail "$base probe run $run produced no JSON"
  done
  if cmp -s "$ART/$base-1.json" "$ART/$base-2.json"; then
    log "[$CELL] $base null control: two runs byte-identical ($(wc -c < "$ART/$base-1.json" | tr -d ' ') bytes)"
    cp "$ART/$base-1.json" "$ART/$base.json"
  else
    fail "$base null control FAILED — same arm, two different answers"
    diff "$ART/$base-1.json" "$ART/$base-2.json" | head -20
  fi
}

# One line of arm provenance for the CONTROLS block, same wording everywhere — the copies
# had already diverged ('+dirty?' vs '+staged') by the time this was extracted.
free_arm_desc() {
  if [ -n "${FREE_WT:-}" ]; then
    echo "$(git -C "$FREE_WT" rev-parse --short HEAD) (pinned ref)"
  else
    echo "WORKING TREE ($(git -C "$PLUGIN_SRC" rev-parse --short HEAD)+uncommitted)"
  fi
}

# The Pro half of the same provenance line. Records the RESOLVED sha, never the ref as typed:
# PRO_REF=development files a verdict naming a branch, and a branch does not identify a build.
# NOTE: four earlier callers still print their own hand-rolled version of this line
# (measure-f6-{external,topologyf,useroverview}.sh, run-email-author-counts.sh) and had already
# drifted apart on spacing before this was extracted -- exactly why free_arm_desc() exists.
# They are owed a migration onto this; rehearse-upgrade.sh is the first caller.
pro_arm_desc() {
  if [ -n "${PRO_RESOLVED_REF:-}" ]; then
    echo "${PRO_RESOLVED_REF:0:8} (shipped ZIP)"
  else
    echo "WORKING TREE ($(git -C "${PRO_CHECKOUT:-.}" rev-parse --short HEAD)+uncommitted)"
  fi
}

# ── Shared WP cell provisioning ─────────────────────────────────────────────
stage_wp_core() { # <art> <wp_version>
  local art="$1" wp="$2"
  if [ -n "${WP_CORE_SOURCE_DIR:-}" ]; then
    [ -d "$WP_CORE_SOURCE_DIR/wp-admin" ] && [ -f "$WP_CORE_SOURCE_DIR/wp-includes/version.php" ] \
      || { fail "local core source is incomplete"; return 1; }
    rsync -a --no-perms --delete --exclude wp-config.php --exclude .htaccess --exclude wp-content/debug.log \
      --exclude 'wp-content/plugins/***' --exclude 'wp-content/uploads/***' --exclude 'wp-content/upgrade/***' \
      "$WP_CORE_SOURCE_DIR/" "$CELL_WP_DIR/" > "$art/install.log" 2>&1 \
      || { fail "local core copy failed"; return 1; }
    wpc core verify-checksums --version="$wp" >> "$art/install.log" 2>&1 \
      || { fail "local core checksum verification failed"; return 1; }
  else
    wpc core download --version="$wp" --force > "$art/install.log" 2>&1 \
      || { fail "core download failed"; return 1; }
  fi
  chmod -R a+rwX "$CELL_WP_DIR/wp-content" 2>/dev/null || true
}

# download → config → install → free source → pro zip → both activations. The third
# script to need this block is what got it extracted, same as build_pro_arm. Cell-specific
# steps (posts, users, WP_DEBUG_DISPLAY) stay in the callers, after this returns.
provision_wp_cell() { # <art> <wp_version> <base_url> <free_src_fallback>
  local art="$1" wp="$2" base_url="$3" free_src="$4"
  stage_wp_core "$art" "$wp" || return 1
  wp_config_debug "$art/install.log"
  wpc core install --url="$base_url" --title="$COMPOSE_PROJECT_NAME" --admin_user=admin \
      --admin_password=admin --admin_email=qa@example.com --skip-email >>"$art/install.log" 2>&1 \
      || { fail "core install failed"; return 1; }
  if [ -n "${ARM_FREE_ZIP:-}" ]; then
    mkdir -p "$CELL_WP_DIR/wp-content/plugins/.free"
    cp "$ARM_FREE_ZIP" "$CELL_WP_DIR/wp-content/plugins/.free/wp-slimstat.zip"
    wpc plugin install /var/www/html/wp-content/plugins/.free/wp-slimstat.zip --activate --force \
      >>"$art/install.log" 2>&1 || { fail "Free ZIP install failed"; return 1; }
  else
    sync_plugin_src "$CELL_WP_DIR" "$free_src"
    wpc plugin activate wp-slimstat >>"$art/install.log" 2>&1 || { fail "free activation failed"; return 1; }
  fi
  # Pro rides only when the caller resolved an arm zip (build_pro_arm sets ARM_PRO_ZIP).
  # A free-only bench cell provisions without it rather than re-inlining this block.
  if [ -n "${ARM_PRO_ZIP:-}" ]; then
    mkdir -p "$CELL_WP_DIR/wp-content/plugins/.pro"
    cp "$ARM_PRO_ZIP" "$CELL_WP_DIR/wp-content/plugins/.pro/wp-slimstat-pro.zip"
    chmod -R a+rwX "$CELL_WP_DIR/wp-content" 2>/dev/null || true
    wpc plugin install /var/www/html/wp-content/plugins/.pro/wp-slimstat-pro.zip --activate --force \
        >>"$art/activate.log" 2>&1 || { fail "pro install failed"; return 1; }
  fi
}

# Point the custom-DB add-on at a host/db with server-side tracking on, root/root creds.
enable_custom_db_addon() { # <host> <dbname> <art>
  wpc eval '
    $s = get_option("slimstat_options", []);
    $s["addon_custom_db_enable"] = "on";
    $s["addon_custom_db_dbhost"] = "'"$1"'";
    $s["addon_custom_db_dbname"] = "'"$2"'";
    $s["addon_custom_db_dbuser"] = "root";
    $s["addon_custom_db_dbpass"] = "root";
    $s["javascript_mode"] = "no";
    $s["is_tracking"] = "on";
    update_option("slimstat_options", $s);
    echo "addon: on host='"$1"' db='"$2"'";
  ' >> "$3/settings.log" 2>&1 || fail "addon settings update failed"
}

# The admin path that owns DDL: creates the analytics tables (and, post-C48, identity)
# on whatever handle the add-on resolves.
init_analytics_env() { # <art>
  wpc eval 'include_once WP_PLUGIN_DIR . "/wp-slimstat/admin/index.php"; wp_slimstat_admin::init_environment(); echo "env init ran";' \
    >> "$1/settings.log" 2>&1 || fail "init_environment failed"
}

# How many slim_ tables a schema holds. One owner for the LIKE pattern and its escaping —
# two cells had already grown two different definitions of "local slim tables".
count_slim_tables() { # <db-service> <schema>
  dc exec -T "$1" mysql -uroot -proot "$2" -N -e \
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$2' AND TABLE_NAME LIKE '%slim\\_%';" 2>/dev/null | tr -dc '0-9'
}

# Drop every slim_ table in a schema, by ENUMERATION — a hardcoded name list cannot see
# multisite prefixes or a table added later, and GROUP_CONCAT truncates at 1024 bytes
# (24 multisite names crossed it: the last DROP arrived cut mid-name and one table
# survived). One DROP per name, composed in shell; FK checks off because slim_events
# references slim_stats and a naive order leaves the parent behind. Verifies to 0 and
# fails otherwise — a silently failed drop lets later checks fail with the wrong blame.
drop_local_slim_tables() { # <db-service> <schema>
  local svc="$1" schema="$2" drops="" t left
  while IFS= read -r t; do
    [ -n "$t" ] && drops="${drops}DROP TABLE IF EXISTS \`${t}\`; "
  done < <(dc exec -T "$svc" mysql -uroot -proot "$schema" -N -e \
    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$schema' AND TABLE_NAME LIKE '%slim\\_%';" 2>/dev/null | tr -d '\r')
  if [ -n "$drops" ]; then
    dc exec -T "$svc" mysql -uroot -proot "$schema" -e \
      "SET FOREIGN_KEY_CHECKS=0; ${drops}SET FOREIGN_KEY_CHECKS=1;" >/dev/null 2>&1 \
      || { fail "could not drop the slim_ tables in $schema"; return 1; }
  fi
  left=$(count_slim_tables "$svc" "$schema")
  [ "${left:-1}" = "0" ] || { fail "slim_ tables remain in $schema after the drop"; return 1; }
}

# Read a cell's verdict status back. Args: cell.json path
#
# The counterpart to write_verdict, and it lives here for the reason PITFALLS #5 gives: one
# writer with two independently-written readers is a disagreement waiting to happen, and it
# stays invisible until one of them produces a confident wrong answer. run-matrix.sh and
# run-topologies.sh both call this, so write_verdict's quoting can change without breaking one
# of them silently.
read_verdict_status() {
  [ -f "$1" ] || { printf 'MISSING'; return; }
  sed -n 's/.*"status":"\([^"]*\)".*/\1/p' "$1" | head -1
}

# ── Shared container bring-up ───────────────────────────────────────────────
# run-cell.sh (PHP×WP axis) and run-topology.sh (install-shape axis) ask different questions but
# stand up the identical stack to ask them. These four helpers are the part that was byte-for-byte
# duplicated; keeping them here means the image args, the debug constants and the rsync excludes
# have ONE owner and the two arms cannot drift.

# docker compose for the current COMPOSE_PROJECT_NAME. DC_EXTRA_FILE lets a cell
# overlay a second compose file (topology B/D add an external-db service) without
# every other cell paying for services it does not use.
dc() { docker compose -f "$HARNESS_DIR/docker-compose.yml" ${DC_EXTRA_FILE:+-f "$DC_EXTRA_FILE"} "$@"; }

# WP-CLI inside the cell's wp container.
wpc() { dc exec -T -u www-data -e REHEARSE_ARM_OPTIONS="${REHEARSE_ARM_OPTIONS:-present}" wp wp --path=/var/www/html "$@"; }

# Build the image and bring the stack up, waiting for MySQL. Args: art_dir php
boot_stack() {
  local art="$1" php="$2"
  dc build --build-arg PHP_VERSION="$php" wp > "$art/build.log" 2>&1 || return 1
  dc up -d                                   > "$art/up.log"    2>&1 || return 2
  wait_for 40 3 dc exec -T db mysqladmin ping -h127.0.0.1 -uroot -proot --silent || return 3
}

# wp-config.php with the debug constants both arms rely on. Args: log_file
wp_config_debug() {
  local log="$1"
  wpc config create --dbname=wordpress --dbuser=root --dbpass=root --dbhost=db:3306 \
      --force --skip-check >>"$log" 2>&1
  wpc config set WP_DEBUG         true  --raw --type=constant >>"$log" 2>&1
  wpc config set WP_DEBUG_LOG     true  --raw --type=constant >>"$log" 2>&1
  wpc config set WP_DEBUG_DISPLAY false --raw --type=constant >>"$log" 2>&1
}

# Copy the working tree's free plugin into the cell. Args: wp_dir
sync_plugin_src() {
  # Args: wp_dir [src_dir=$PLUGIN_SRC]. The optional source is what lets a two-arm
  # measurement (measure-d10.sh) copy from a git worktree of a specific ref through the
  # SAME excludes as every other cell — its first version carried a third private copy of
  # this rsync, which is exactly the drift this helper was extracted to prevent.
  local wp_dir="$1" src="${2:-$PLUGIN_SRC}"
  rm -rf "$wp_dir/wp-content/plugins/wp-slimstat"
  rsync -a --delete --exclude '.git' --exclude 'node_modules' --exclude 'tests/e2e/node_modules' \
        "$src/" "$wp_dir/wp-content/plugins/wp-slimstat/" >/dev/null 2>&1
  chmod -R a+rwX "$wp_dir/wp-content" 2>/dev/null || true
}

# Preserve the debug log and report whether it holds a wp-slimstat fatal. Args: wp_dir art_dir
#
# Setting WP_DEBUG_LOG without ever reading the log is the shape where a plugin fatal raised
# while provisioning four blogs across two networks lands in a file nobody opens.
scan_debug_log() {
  # Declared on separate lines: under `set -u`, a single `local a=$1 b="$a/x"` does not reliably
  # see `a` while the same declaration is still being processed, and it failed exactly that way
  # on first use.
  local wp_dir="$1"
  local art="$2"
  local log="$wp_dir/wp-content/debug.log"

  [ -f "$log" ] || return 1
  cp "$log" "$art/debug.log" 2>/dev/null || true
  grep -qiE 'PHP (Fatal|Parse) error.*wp-slimstat' "$log" 2>/dev/null
}
