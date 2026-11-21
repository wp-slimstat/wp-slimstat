#!/usr/bin/env bash
set -euo pipefail
if [ "$#" -eq 0 ]; then echo 'Pass the k6 scripts to run' >&2; exit 2; fi
results=tests/perf/results
mkdir -p "$results"
docker info --format '{{json .}}' > "$results/docker-info.json"
docker stats --format '{{json .}}' > "$results/resources.jsonl" &
monitor_pid=$!
trap 'kill "$monitor_pid" 2>/dev/null || true' EXIT
for script in "$@"; do
  name=$(basename "$script" .js)
  date -u '+%FT%TZ' > "$results/$name.started"
  status=0
  k6 run --summary-export "$results/$name.json" "$script" > "$results/$name.log" 2>&1 || status=$?
  tail -65 "$results/$name.log"
  date -u '+%FT%TZ' > "$results/$name.finished"
  if [ "$status" -ne 0 ]; then exit "$status"; fi
done
