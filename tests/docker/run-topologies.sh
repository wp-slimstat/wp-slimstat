#!/usr/bin/env bash
# tests/docker/run-topologies.sh [topology ...]
#
# Runs every install shape jaan-to/outputs/dev/v6-performance/TOPOLOGIES.md defines and prints
# one table. With no arguments it runs all five; name topologies to run a subset.
#
# Concurrency comes from matrix.env's CONCURRENCY, using the same `wait -n` slot loop
# run-matrix.sh uses. The first version of this ran strictly sequentially and justified it by
# citing port-collision risk — which the per-topology HTTP_BASE+i / DB_BASE+i allocation and the
# unique COMPOSE_PROJECT_NAME had already engineered away. A stated reason the code does not
# actually have is worth less than no reason at all.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/lib.sh"
[ -f "$HERE/matrix.env" ] && source "$HERE/matrix.env"

ALL=(A C-subdir C-subdomain C-mainonly E)
TOPOLOGIES=("${@:-}")
[ -z "${TOPOLOGIES[0]:-}" ] && TOPOLOGIES=("${ALL[@]}")

HTTP_BASE="${TOPOLOGY_HTTP_BASE:-18900}"
DB_BASE="${TOPOLOGY_DB_BASE:-13900}"
CONCURRENCY="${CONCURRENCY:-2}"

i=0
running=0

for t in "${TOPOLOGIES[@]}"; do
  i=$((i + 1))
  mkdir -p "$WORK_ROOT/topologies/topology-$t/artifacts"
  log "launching $t (http $((HTTP_BASE + i)), db $((DB_BASE + i)))"
  bash "$HERE/run-topology.sh" "$t" $((HTTP_BASE + i)) $((DB_BASE + i)) \
    > "$WORK_ROOT/topologies/topology-$t/run.log" 2>&1 &
  running=$((running + 1))
  if [ "$running" -ge "$CONCURRENCY" ]; then wait -n 2>/dev/null || wait; running=$((running - 1)); fi
done
wait

echo
failed=0

for t in "${TOPOLOGIES[@]}"; do
  art="$WORK_ROOT/topologies/topology-$t/artifacts"

  # read_verdict_status lives in lib.sh, shared with run-matrix.sh. Two hand-written readers of
  # one writer is the shape PITFALLS #5 is built around — and the two regexes already differed
  # before this was extracted.
  status=$(read_verdict_status "$art/cell.json")

  networks="?"; blogs="?"; view=""
  if [ -f "$art/shape.json" ]; then
    networks=$(sed -n 's/.*"networks":\([0-9]*\).*/\1/p' "$art/shape.json")
    blogs=$(sed -n 's/.*"blogs":\([0-9]*\).*/\1/p' "$art/shape.json")
    total=$(sed -n 's/.*"network_view_total":\([0-9]*\).*/\1/p' "$art/shape.json")
    want=$(sed -n 's/.*"golden_expected":\([0-9]*\).*/\1/p' "$art/shape.json")
    [ -n "$total" ] && [ "$total" != "$want" ] && view="  Network View: $total (golden says $want — F9)"
    [ -n "$total" ] && [ "$total" = "$want" ] && view="  Network View: $total ✓"
  fi

  printf '%-14s %-8s %2s network(s)  %2s blog(s)%s\n' "$t" "$status" "$networks" "$blogs" "$view"
  case "$status" in PASS) ;; *) failed=$((failed + 1)) ;; esac
done

echo
if [ "$failed" -gt 0 ]; then
  err "$failed topology/topologies did not pass"
  exit 1
fi

log "all ${#TOPOLOGIES[@]} topologies passed"
