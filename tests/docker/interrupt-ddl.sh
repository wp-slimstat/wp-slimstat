#!/usr/bin/env bash
# Sourced by rehearse-upgrade.sh before its ordinary completion/resume assertions.
# KILL QUERY cancels an executing server DDL, distinct from the network worker SIGKILL.
interrupt_migration_ddl() {
  dc cp "$HARNESS_DIR/probe-interrupt-ddl.php" wp:/tmp/probe-interrupt-ddl.php >/dev/null || return 1
  wpc eval-file /tmp/probe-interrupt-ddl.php >"$ART/ddl-worker.log" 2>&1 &
  local worker=$! observed='' attempt thread worker_rc
  for ((attempt=0; attempt<600; attempt++)); do
    observed=$(mysql_q "SELECT ID, STATE, INFO FROM information_schema.PROCESSLIST WHERE DB='wordpress' AND INFO REGEXP '^ALTER TABLE' AND (LOWER(STATE) LIKE '%altering table%' OR LOWER(STATE) LIKE '%copy to tmp table%') LIMIT 1;")
    [ -z "$observed" ] || break
    kill -0 "$worker" 2>/dev/null || break
    sleep 0.1
  done
  if [ -z "$observed" ]; then
    wait "$worker"; worker_rc=$?
    printf 'No executing DDL observed; worker exit %s. No interruption credit.\n' "$worker_rc" >"$ART/ddl-interruption-error.log"
    return 1
  fi
  printf '%s\n' "$observed" >"$ART/ddl-observed.tsv"
  thread=${observed%%$'\t'*}
  [[ "$thread" =~ ^[0-9]+$ ]] || return 1
  mysql_exec "KILL QUERY $thread;" "$ART/ddl-kill.log" || { wait "$worker"; return 1; }
  wait "$worker"; worker_rc=$?
  printf '%s\n' "$worker_rc" >"$ART/ddl-worker.exit"
  [ "$worker_rc" -ne 0 ] || return 1
  python3 - "$ART/ddl-worker.log" "$ART/ddl-observed.tsv" "$ART/ddl-interruption.json" <<'PY'
import json,sys
log,observed,out=sys.argv[1:]
rows=[x.split(':',1)[1] for x in open(log) if x.startswith('DDL-WORKER:')]
assert len(rows)==1, 'worker failed without a migration outcome'
r=json.loads(rows[0]); assert isinstance(r,dict) and any(value is False for value in r.values()), 'interruption not reported as migration failure'
thread,state,sql=open(observed).read().strip().split('\t',2)
json.dump(dict(operation='KILL QUERY',connection_id=int(thread),observed_state=state,observed_sql=sql,migrations=r,resume_required=True),open(out,'w'),indent=2)
PY
}
