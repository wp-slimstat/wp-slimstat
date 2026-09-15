#!/usr/bin/env bash
# Sourced by rehearse-upgrade.sh. Kill the actual PHP worker during its executing ALTER;
# then prove recovery starts only after the owning database session is gone.
interrupt_migration_ddl() {
  dc cp "$HARNESS_DIR/probe-interrupt-ddl.php" wp:/tmp/probe-interrupt-ddl.php >/dev/null || return 1
  wpc eval-file /tmp/probe-interrupt-ddl.php >"$ART/ddl-worker.log" 2>&1 &
  local worker=$! observed='' attempt thread php_pid worker_rc lock_name
  for ((attempt=0; attempt<600; attempt++)); do
    dc cp wp:/tmp/ddl-worker.json "$ART/ddl-worker.json" >/dev/null 2>&1 && break
    kill -0 "$worker" 2>/dev/null || break
    sleep 0.1
  done
  read -r php_pid thread <<<"$(python3 - "$ART/ddl-worker.json" <<'PYPID'
import json,sys
v=json.load(open(sys.argv[1]))
if type(v['pid']) is not int or v['pid'] <= 1 or type(v['connection']) is not int or v['connection'] <= 0:
    raise ValueError('invalid worker identity')
print(v['pid'],v['connection'])
PYPID
)"
  [[ "$php_pid" =~ ^[0-9]+$ ]] && [[ "$thread" =~ ^[0-9]+$ ]] || { wait "$worker"; return 1; }
  for ((attempt=0; attempt<600; attempt++)); do
    observed=$(mysql_q "SELECT ID, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID=$thread AND DB='wordpress' AND INFO REGEXP '^ALTER TABLE' AND INFO LIKE '%vid_hash%' AND (LOWER(STATE) LIKE '%altering table%' OR LOWER(STATE) LIKE '%copy to tmp table%');")
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
  # Resolve the lock from the owning WordPress handle before killing its client. The
  # observer's mysql connection has no default schema, so DATABASE() there is NULL.
  wpc eval-file /tmp/probe-interrupt-ddl.php lock >"$ART/ddl-lock.json" 2>"$ART/ddl-lock-error.log" || return 1
  lock_name=$(python3 - "$ART/ddl-lock.json" "$thread" <<'PYLOCK'
import json,sys
v=json.load(open(sys.argv[1]))
if v.get('owner') != int(sys.argv[2]) or not isinstance(v.get('name'),str) or not v['name'].startswith('wpss_migrate_'):
    raise ValueError('named lock is not owned by the observed DDL session')
print(v['name'])
PYLOCK
) || return 1
  dc exec -T wp kill -9 "$php_pid" >"$ART/ddl-kill.log" 2>&1 || { wait "$worker"; return 1; }
  wait "$worker"; worker_rc=$?
  printf '%s\n' "$worker_rc" >"$ART/ddl-worker.exit"
  [ "$worker_rc" -eq 137 ] || return 1
  if dc exec -T wp kill -0 "$php_pid" >/dev/null 2>&1; then return 1; fi
  # A dead PHP client can leave its DDL session alive. Its named lock remains authoritative:
  # another runner must still refuse until that server session ends.
  if [ "$(scalar_q "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$thread;")" != 0 ]; then
    wpc eval-file /tmp/probe-interrupt-ddl.php refused >"$ART/ddl-lock-refused.log" 2>&1 || return 1
    grep -q '^DDL-CLAIM-REFUSED$' "$ART/ddl-lock-refused.log" || return 1
    mysql_exec "KILL QUERY $thread;" "$ART/ddl-query-cancel.log" || \
      [ "$(scalar_q "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$thread;")" = 0 ] || return 1
  fi
  [ "$(scalar_q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='wordpress' AND TABLE_NAME='wp_slim_stats' AND COLUMN_NAME='vid_hash';")" = 0 ] || return 1
  for ((attempt=0; attempt<600; attempt++)); do
    [ "$(scalar_q "SELECT IS_FREE_LOCK('$lock_name');")" = 1 ] && break
    sleep 0.1
  done
  [ "$(scalar_q "SELECT IS_FREE_LOCK('$lock_name');")" = 1 ] || return 1
  wpc eval-file /tmp/probe-interrupt-ddl.php status >"$ART/ddl-status.log" 2>&1 || return 1
  grep -q '^DDL-NO-STATUS$' "$ART/ddl-status.log" || return 1
  python3 - "$ART/ddl-observed.tsv" "$ART/ddl-interruption.json" "$lock_name" <<'PY'
import json,sys
observed,out,name=sys.argv[1:]
thread,state,sql=open(observed).read().strip().split('\t',2)
json.dump(dict(operation='PHP SIGKILL during executing DDL',connection_id=int(thread),observed_state=state,observed_sql=sql,worker_exit=137,column_still_missing=True,lock_name=name,owner_session_gone=True,resume_required=True),open(out,'w'),indent=2)
PY
}
