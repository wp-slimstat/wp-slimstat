#!/usr/bin/env python3
"""Exercise the actual backup recovery orchestration with a disposable fake DB."""
import json
import pathlib
import subprocess
import tempfile

harness = pathlib.Path(__file__).parent.resolve() / 'docker'
script = r'''
set -uo pipefail
source "$1/backup-recovery.sh"
ART="$2"; mode="$3"; state=baseline; restores=0; restored=0
digest() { shasum -a 256 "$1" | awk '{print $1}'; }
mysql_q() {
  case "$1" in
    'SHOW TABLES'*) [ "$state" = missing-options ] || echo wp_options; echo wp_slim_stats;;
    *'SHOW CREATE'*)
      read -r _consume_loop_input || true
      if [ "$state" = migrated ]; then echo 'schema-new';
      elif [ "$restored" = 0 ]; then
        case "$mode" in
          quoted-default-change) printf '%s\n' 'CREATE TABLE `t` (\n  `column` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '\''a CHARACTER SET utf8mb4 COLLATE b'\'',\n  KEY `idx` (`column`))';;
          index-change) printf '%s\n' 'CREATE TABLE `t` (\n  `column` varchar(20) COLLATE utf8mb4_unicode_ci,\n  KEY `a CHARACTER SET utf8mb4 COLLATE b` (`column`))';;
          *) printf '%s\n' 'CREATE TABLE `t` (\n  `column` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '\''ok'\'',\n  KEY `idx` (`column`))';;
        esac
      else
        case "$mode" in
          charset-change) printf '%s\n' 'CREATE TABLE `t` (\n  `column` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT '\''ok'\'',\n  KEY `idx` (`column`))';;
          collation-change) printf '%s\n' 'CREATE TABLE `t` (\n  `column` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT '\''ok'\'',\n  KEY `idx` (`column`))';;
          type-change) printf '%s\n' 'CREATE TABLE `t` (\n  `column` varchar(21) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '\''ok'\'',\n  KEY `idx` (`column`))';;
          quoted-default-change) printf '%s\n' 'CREATE TABLE `t` (\n  `column` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '\''a COLLATE b'\'',\n  KEY `idx` (`column`))';;
          index-change) printf '%s\n' 'CREATE TABLE `t` (\n  `column` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,\n  KEY `a COLLATE b` (`column`))';;
          *) printf '%s\n' 'CREATE TABLE `t` (\n  `column` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '\''ok'\'',\n  KEY `idx` (`column`))';;
        esac
      fi;;
    *'CHECKSUM TABLE'*) printf 'wordpress.table\t%s\n' "$state" | sed 's/baseline/123/;s/migrated/456/;s/incomplete/789/;s/missing-options/123/';;
  esac
}
stats_rows() { case "$state" in baseline|missing-options) echo 10;; migrated) echo 12;; incomplete) echo 9;; esac; }
fingerprint() { printf '10:%s\n' "$state" | sed 's/missing-options/baseline/'; }
fingerprint_core() { fingerprint; }
dump_schema_gz() { printf 'exact backup\n' | gzip -c >"$2"; }
track_hit() { [ "$mode" = missing-hit ] && echo 0 || echo 12; }
scalar_q() { [ "$state" = migrated ] && echo 1 || echo 0; }
mysql_exec() { case "$1" in 'DROP TABLE'*) state=missing-options;; esac; }
import_gz_into_schema() {
  restores=$((restores+1))
  restored=1
  [ "$mode" = import-failed ] && return 1
  case "$mode" in incomplete-import) state=incomplete;; missing-options-import) state=missing-options;; *) state=baseline;; esac
}
capture_recovery_backup || exit 1
[ "$(wc -l < "$ART/backup-recovery/before/schema.txt" | tr -d ' ')" = 2 ] || exit 1
[ "$(wc -l < "$ART/backup-recovery/before/checksum.txt" | tr -d ' ')" = 2 ] || exit 1
state=migrated
prove_backup_recovery || exit 1
[ "$restores" = 2 ] || exit 1
'''
with tempfile.TemporaryDirectory() as temp:
    for mode in ['valid', 'missing-hit', 'import-failed', 'incomplete-import', 'missing-options-import', 'charset-change', 'collation-change', 'type-change', 'quoted-default-change', 'index-change']:
        art = pathlib.Path(temp) / mode
        result = subprocess.run(['bash', '-c', script, 'test', str(harness), str(art), mode], capture_output=True)
        assert (result.returncode == 0) == (mode == 'valid'), (mode, result.stdout, result.stderr)
        if mode == 'valid':
            report = json.loads((art / 'backup-recovery/result.json').read_text())
            assert report['writes_since_backup_lost'] == 2
            assert report['schema_restored'] and report['wrong_backup_control'] and report['incomplete_restore_control']
            assert report['tables_restored'] == ['wp_options', 'wp_slim_stats']
print('PASS: redundant column charset rendering normalizes while charset, collation, type, quoted-default, and index changes fail')
