#!/usr/bin/env bash
# Sourced by the disposable upgrade cell; uses lib.sh's dump/import primitives.
backup_recovery_snapshot() {
  local out="$1"
  mkdir -p "$out" || return 1
  mysql_q "SHOW CREATE TABLE wordpress.wp_slim_stats;" >"$out/schema.txt" || return 1
  mysql_q "CHECKSUM TABLE wordpress.wp_slim_stats EXTENDED;" >"$out/checksum.txt" || return 1
  grep -Eq '[[:space:]][0-9]+$' "$out/checksum.txt" || return 1
  stats_rows >"$out/rows.txt" || return 1
  FP_NOTES_EXPR=notes fingerprint >"$out/fingerprint.txt" || return 1
  fingerprint_core >"$out/fingerprint-core.txt" || return 1
  [ -s "$out/schema.txt" ] && [ -s "$out/fingerprint.txt" ]
}

capture_recovery_backup() {
  RECOVERY_DIR="$ART/backup-recovery"
  mkdir -p "$RECOVERY_DIR" || return 1
  backup_recovery_snapshot "$RECOVERY_DIR/before" || return 1
  dump_schema_gz wordpress "$RECOVERY_DIR/backup.sql.gz" "$RECOVERY_DIR/backup.err" || return 1
  gzip -t "$RECOVERY_DIR/backup.sql.gz" || return 1
  RECOVERY_SHA=$(digest "$RECOVERY_DIR/backup.sql.gz") || return 1
  printf '%s\n' "$RECOVERY_SHA" >"$RECOVERY_DIR/backup.sha256"
}

restore_recovery_backup() {
  local backup="$1"
  [ "$(digest "$backup")" = "$RECOVERY_SHA" ] || return 1
  gzip -t "$backup" || return 1
  # The harness owns this container and database. Drop first so an incomplete import
  # cannot inherit tables or rows from the pre-restoration state.
  mysql_exec 'DROP DATABASE wordpress; CREATE DATABASE wordpress;' "$RECOVERY_DIR/reset.log" || return 1
  import_gz_into_schema "$backup" wordpress "$RECOVERY_DIR/import.err"
}

verify_recovery_backup() {
  local out="$1" part
  backup_recovery_snapshot "$out" || return 1
  for part in schema checksum rows fingerprint fingerprint-core; do
    cmp -s "$RECOVERY_DIR/before/$part.txt" "$out/$part.txt" || return 1
  done
  [ "$(scalar_q "SELECT COUNT(*) FROM wordpress.wp_slim_stats WHERE resource='/rehearse-post-backup';")" = 0 ]
}

prove_backup_recovery() {
  local hit
  hit=$(track_hit rehearse-post-backup)
  [ "${hit:-0}" -gt 0 ] || return 1
  [ "$(scalar_q "SELECT COUNT(*) FROM wordpress.wp_slim_stats WHERE resource='/rehearse-post-backup';")" = 1 ] || return 1
  printf '%s\n' "$hit" >"$RECOVERY_DIR/post-backup-write-id.txt"
  backup_recovery_snapshot "$RECOVERY_DIR/before-restore" || return 1
  printf 'SELECT 1;\n' | gzip -c >"$RECOVERY_DIR/wrong-backup.sql.gz"
  if restore_recovery_backup "$RECOVERY_DIR/wrong-backup.sql.gz"; then return 1; fi
  printf 'PASS: wrong backup rejected before database replacement\n' >"$RECOVERY_DIR/controls.log"
  restore_recovery_backup "$RECOVERY_DIR/backup.sql.gz" || return 1
  verify_recovery_backup "$RECOVERY_DIR/restored" || return 1
  # A real missing baseline row must fail the same oracle used for acceptance.
  mysql_exec 'DELETE FROM wordpress.wp_slim_stats ORDER BY id LIMIT 1;' "$RECOVERY_DIR/incomplete-control.log" || return 1
  if verify_recovery_backup "$RECOVERY_DIR/incomplete"; then return 1; fi
  printf 'PASS: incomplete restoration rejected by data fingerprint\n' >>"$RECOVERY_DIR/controls.log"
  restore_recovery_backup "$RECOVERY_DIR/backup.sql.gz" || return 1
  verify_recovery_backup "$RECOVERY_DIR/final" || return 1
  python3 - "$RECOVERY_DIR" "$RECOVERY_SHA" "$hit" <<'PY'
import json, pathlib, sys
out, sha, hit = sys.argv[1:]
p = pathlib.Path(out)
before = int((p / 'before/rows.txt').read_text())
after = int((p / 'before-restore/rows.txt').read_text())
json.dump(dict(status='PASS', backup_sha256=sha, restored_rows=before,
    writes_since_backup_lost=after-before, marked_write_id=int(hit), marked_write_absent=True,
    treatment='Database restored to backup point. Later writes are lost; no export/replay performed.',
    schema_restored=True, wrong_backup_control=True, incomplete_restore_control=True),
    (p / 'result.json').open('w'), indent=2)
PY
}
