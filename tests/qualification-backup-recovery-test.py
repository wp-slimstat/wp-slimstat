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
ART="$2"; mode="$3"; state=baseline; restores=0
digest() { shasum -a 256 "$1" | awk '{print $1}'; }
mysql_q() {
  case "$1" in
    *'SHOW CREATE'*) [ "$state" = migrated ] && echo 'schema-new' || echo 'schema-old';;
    *'CHECKSUM TABLE'*) printf 'wordpress.wp_slim_stats\t%s\n' "$state" | sed 's/baseline/123/;s/migrated/456/;s/incomplete/789/';;
  esac
}
stats_rows() { case "$state" in baseline) echo 10;; migrated) echo 12;; incomplete) echo 9;; esac; }
fingerprint() { printf '10:%s\n' "$state"; }
fingerprint_core() { fingerprint; }
dump_schema_gz() { printf 'exact backup\n' | gzip -c >"$2"; }
track_hit() { [ "$mode" = missing-hit ] && echo 0 || echo 12; }
scalar_q() { [ "$state" = migrated ] && echo 1 || echo 0; }
mysql_exec() { case "$1" in DELETE*) state=incomplete;; esac; }
import_gz_into_schema() {
  restores=$((restores+1))
  [ "$mode" = import-failed ] && return 1
  if [ "$mode" = incomplete-import ]; then state=incomplete; else state=baseline; fi
}
capture_recovery_backup || exit 1
state=migrated
prove_backup_recovery || exit 1
[ "$restores" = 2 ] || exit 1
'''
with tempfile.TemporaryDirectory() as temp:
    for mode in ['valid', 'missing-hit', 'import-failed', 'incomplete-import']:
        art = pathlib.Path(temp) / mode
        result = subprocess.run(['bash', '-c', script, 'test', str(harness), str(art), mode], capture_output=True)
        assert (result.returncode == 0) == (mode == 'valid'), (mode, result.stdout, result.stderr)
        if mode == 'valid':
            report = json.loads((art / 'backup-recovery/result.json').read_text())
            assert report['writes_since_backup_lost'] == 2
            assert report['schema_restored'] and report['wrong_backup_control'] and report['incomplete_restore_control']
print('PASS: exact backup restoration, explicit later-write loss, wrong backup and incomplete restoration controls')
