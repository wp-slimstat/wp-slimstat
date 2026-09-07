#!/usr/bin/env bash
# Sourced by rehearse-upgrade.sh. Kill the actual PHP worker during its executing ALTER;
# then wait for the real abandoned-claim interval, without rewriting state or shipping files.
interrupt_migration_ddl() {
  dc cp "$HARNESS_DIR/probe-interrupt-ddl.php" wp:/tmp/probe-interrupt-ddl.php >/dev/null || return 1
  wpc eval-file /tmp/probe-interrupt-ddl.php >"$ART/ddl-worker.log" 2>&1 &
  local worker=$! observed='' attempt thread php_pid worker_rc remaining
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
  dc exec -T wp kill -9 "$php_pid" >"$ART/ddl-kill.log" 2>&1 || { wait "$worker"; return 1; }
  wait "$worker"; worker_rc=$?
  printf '%s\n' "$worker_rc" >"$ART/ddl-worker.exit"
  [ "$worker_rc" -eq 137 ] || return 1
  if dc exec -T wp kill -0 "$php_pid" >/dev/null 2>&1; then return 1; fi
  # A disconnected client can leave the server working; explicitly cancel that connection's
  # query if it survives the process kill. An already-disappeared connection needs no cancel.
  if [ "$(scalar_q "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$thread;")" != 0 ]; then
    mysql_exec "KILL QUERY $thread;" "$ART/ddl-query-cancel.log" || \
      [ "$(scalar_q "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$thread;")" = 0 ] || return 1
  fi
  [ "$(scalar_q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='wordpress' AND TABLE_NAME='wp_slim_stats' AND COLUMN_NAME='vid_hash';")" = 0 ] || return 1
  wpc eval-file /tmp/probe-interrupt-ddl.php refused >"$ART/ddl-claim-refused.log" 2>&1 || return 1
  grep -q '^DDL-CLAIM-REFUSED$' "$ART/ddl-claim-refused.log" || return 1
  wpc eval-file /tmp/probe-interrupt-ddl.php claim >"$ART/ddl-claim.json" 2>"$ART/ddl-claim-error.log" || return 1
  remaining=$(python3 - "$ART/ddl-claim.json" "$ART/ddl-observed.tsv" "$ART/ddl-interruption.json" <<'PY'
import json,sys
claim,observed,out=sys.argv[1:]; c=json.load(open(claim))
if any(type(c[k]) is not int for k in ['held','ttl','now']) or c['held'] <= 0 or c['ttl'] <= 0:
    raise ValueError('invalid abandoned claim evidence')
thread,state,sql=open(observed).read().strip().split('\t',2)
seconds=max(0,c['held']+c['ttl']-c['now']+1)
json.dump(dict(operation='PHP SIGKILL during executing DDL',connection_id=int(thread),observed_state=state,observed_sql=sql,worker_exit=137,column_still_missing=True,claim=c,wait_seconds=seconds,resume_required=True),open(out,'w'),indent=2)
print(seconds)
PY
) || return 1
  [[ "$remaining" =~ ^[0-9]+$ ]] || return 1
  log "[$CELL] killed DDL worker; waiting $remaining seconds for the plugin's unmodified abandoned-claim policy"
  while [ "$remaining" -gt 0 ]; do sleep 1; remaining=$((remaining-1)); done
}
