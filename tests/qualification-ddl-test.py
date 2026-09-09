#!/usr/bin/env python3
"""Behavioral controls for the host DDL observer, independent of Docker."""
import pathlib
import subprocess
import tempfile

harness = pathlib.Path(__file__).parent.resolve() / 'docker'
with tempfile.TemporaryDirectory() as temp:
    root = pathlib.Path(temp)
    for name, state, worker_exit, column_exists, refusal, valid in [
        ('interrupted', 'altering table', 137, 0, 'DDL-CLAIM-REFUSED', True),
        ('success-is-not-interruption', 'altering table', 0, 0, 'DDL-CLAIM-REFUSED', False),
        ('no-observation', '', 137, 0, 'DDL-CLAIM-REFUSED', False),
        ('ddl-already-completed', 'altering table', 137, 1, 'DDL-CLAIM-REFUSED', False),
        ('claim-not-refused', 'altering table', 137, 0, '', False),
    ]:
        art = root / name
        art.mkdir()
        script = r'''
source "$1/interrupt-ddl.sh"
HARNESS_DIR="$1"; ART="$2"; observation="$3"; worker_exit="$4"; column_exists="$5"; refusal="$6"; CELL=test
log() { return 0; }
dc() {
  if [ "$1" = cp ] && [ "$2" = wp:/tmp/ddl-worker.json ]; then printf '{"pid":123,"connection":42}' >"$3"; fi
  if [ "$1" = exec ] && [ "$5" = -0 ]; then return 1; fi
  return 0
}
wpc() {
  case "${3:-}" in
    claim) printf '{"held":1,"ttl":1,"now":3}'; return 0;;
    refused) printf '%s\n' "$refusal"; return 0;;
    *) return "$worker_exit";;
  esac
}
mysql_q() { [ -z "$observation" ] || printf '42\t%s\tALTER TABLE wp_slim_stats ADD COLUMN vid_hash BINARY(16)\n' "$observation"; }
scalar_q() { case "$1" in *COLUMNS*) printf '%s' "$column_exists";; *) printf 0;; esac; }
mysql_exec() { [ "$1" = 'KILL QUERY 42;' ]; }
interrupt_migration_ddl
'''
        r = subprocess.run(['bash', '-c', script, 'test', str(harness), str(art), state, str(worker_exit), str(column_exists), refusal], capture_output=True)
        assert (r.returncode == 0) == valid, (name, r.stdout, r.stderr)
print('PASS: observed DDL, killed worker, missing column and real claim refusal required')
