#!/usr/bin/env bash
# Measure what one tracked hit costs the database.
#
#   tests/bench/hit-cost.sh <base-url> [iterations]
#
# Scorecard metric M2: queries + wp_options writes per hit. Reports each hit shape
# separately, because they take different paths and only one of them is the happy path:
#
#   pageview   a brand-new pageview           (Processor::process)
#   bot        a hit the tracker refuses      (Utils::logError, bot UA)
#   burst      the same hit past the rate cap (Utils::logError 429)
#
# The rejected shapes matter as much as the happy path: bots are ~23.5% of hits on the
# reference dataset and every one is rejected, so a write there is a write on a quarter
# of all traffic.
#
# PACING: the rate limiter keys on the raw REMOTE_ADDR, which is identical for every
# request from one benchmark host, so all traffic shares a single bucket. The measured
# shapes are paced under the cap; `burst` deliberately exceeds it. (That every client
# behind one CDN or NAT egress shares a bucket the same way is defect D29.)
#
# CONSENT: every request carries `slimstat_gdpr_consent=accepted`. Without it, a site with
# `gdpr_enabled` on refuses every shape at Processor.php:95 with -301 before any tracking
# work happens, and all three tables below then describe the consent-rejection path while
# looking exactly like a successful run. An independent re-measurement found this had
# happened. Override with SLIMSTAT_BENCH_CONSENT= to measure the refusal path deliberately.
#
# Requires an otherwise idle site. Note that "otherwise idle" is an assumption this script
# cannot check: the query and write COUNTS are deterministic enough to survive a contended
# box, but the p50 column is not — treat it as indicative only unless the host is quiesced.
set -euo pipefail

BASE_URL="${1:-${BASE_URL:-}}"
ITER="${2:-20}"

if [[ -z "$BASE_URL" ]]; then
  echo "usage: $0 <base-url> [iterations]" >&2
  exit 2
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_CONTENT="${WP_CONTENT_DIR:-$(cd "$ROOT/../.." && pwd)}"
MU_DIR="$WP_CONTENT/mu-plugins"
LOG="$WP_CONTENT/uploads/slimstat-bench/qlog.jsonl"

mkdir -p "$MU_DIR"
cp "$ROOT/tests/bench/mu/slimstat-bench-qlog.php" "$MU_DIR/"
rm -f "$LOG"

# Take the instrument back out however this exits. It is inert without the bench
# header, but leaving a test mu-plugin behind on someone's install is not the
# harness's call to make.
trap 'rm -f "$MU_DIR/slimstat-bench-qlog.php"' EXIT

HIT="$BASE_URL/wp-json/slimstat/v1/hit"
b64url() { printf '%s' "$1" | base64 | tr '+/' '-_' | tr -d '='; }
RES="$(b64url "$BASE_URL/bench-page/")"

# The tracker answers with `<id>.<checksum>` when it stored the hit and `-<code>`
# when it refused. Recording it is what stops a shape from silently measuring the
# wrong path — a "rejected" shape measures nothing of the kind on a site where
# `ignore_bots` is off, which is the shipped default.
REPLIES="$WP_CONTENT/uploads/slimstat-bench/replies.txt"
rm -f "$REPLIES"

CONSENT="${SLIMSTAT_BENCH_CONSENT-slimstat_gdpr_consent=accepted}"

fire() { # label body pace [user-agent]
  local label="$1" body="$2" pace="$3" ua="${4:-Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120 Safari/537.36}"
  for ((i = 0; i < ITER; i++)); do
    local reply
    reply="$(curl -sk -X POST -H 'Content-Type: application/json' \
      -H "X-Slimstat-Bench: $label" -H "User-Agent: $ua" \
      ${CONSENT:+-H "Cookie: $CONSENT"} -d "$body" "$HIT" || echo 'CURL-FAIL')"
    printf '%s\t%s\n' "$label" "$reply" >> "$REPLIES"
    # Written as an `if`, not `[[ … ]] && sleep`: a bare test as the last statement
    # of a function returns its own exit status, so the unpaced shape returned 1 and
    # `set -e` killed the run just before it printed anything.
    if [[ "$pace" != "0" ]]; then
      sleep "$pace"
    fi
  done
}

echo "== firing $ITER hits per shape at $HIT"
fire pageview "{\"res\":\"$RES\",\"sw\":1920,\"sh\":1080,\"bw\":1600,\"bh\":900,\"fh\":\"benchfp0000000000000000000000000\"}" 0.6
fire bot      "{\"res\":\"$RES\"}" 0.6 "Googlebot/2.1 (+http://www.google.com/bot.html)"
fire burst    "{\"res\":\"$RES\"}" 0

echo
echo "== tracker replies (stored = <id>.<hash>, refused = -<code>)"
sort "$REPLIES" | uniq -c | sed 's/^/   /'

# Refuse to print a results table for a run that never reached the tracking path. -301 is
# "no consent"; if the `pageview` shape never stored anything, every number below would
# describe the rejection path while looking like a normal result. This is not hypothetical:
# it is how one round of these measurements was taken before anyone noticed.
STORED="$(awk -F'\t' '$1 == "pageview" && $2 !~ /^-/' "$REPLIES" | wc -l | tr -d ' ')"
if [[ "$STORED" -eq 0 ]]; then
  echo >&2
  echo "ERROR: not one 'pageview' hit was stored — this run measured a rejection path." >&2
  if grep -q -- '-301' "$REPLIES"; then
    echo "  Replies are -301: consent enforcement (gdpr_enabled) refused every hit." >&2
    echo "  The consent cookie is sent by default; SLIMSTAT_BENCH_CONSENT is currently '${CONSENT:-<empty>}'." >&2
  else
    echo "  See the reply tally above for the refusal code." >&2
  fi
  echo "VERDICT: ERROR"
  exit 1
fi

if [[ ! -f "$LOG" ]]; then
  echo "ERROR: no ledger written — is $MU_DIR/slimstat-bench-qlog.php loaded?" >&2
  echo "VERDICT: ERROR"
  exit 1
fi

php -r '
$rows = [];
foreach (file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    if (is_array($r) && isset($r["label"])) { $rows[$r["label"]][] = $r; }
}
if (!$rows) { echo "ERROR: ledger is empty\nVERDICT: ERROR\n"; exit(1); }
$keys = ["total", "reads", "writes", "options_reads", "options_writes", "slim_reads", "slim_writes"];
printf("\n%-10s %5s %8s %7s %7s %7s %7s %7s %7s %8s\n",
    "shape", "n", "queries", "reads", "writes", "opt_r", "opt_w", "slim_r", "slim_w", "p50 ms");
foreach ($rows as $label => $set) {
    $n = count($set);
    $avg = [];
    foreach ($keys as $k) { $avg[$k] = array_sum(array_column($set, $k)) / $n; }
    $ms = array_column($set, "ms");
    sort($ms);
    printf("%-10s %5d %8.2f %7.2f %7.2f %7.2f %7.2f %7.2f %7.2f %8.1f\n",
        $label, $n, $avg["total"], $avg["reads"], $avg["writes"],
        $avg["options_reads"], $avg["options_writes"], $avg["slim_reads"], $avg["slim_writes"],
        $ms[(int) (count($ms) * 0.5)]);
}
echo "\nVERDICT: OK\n";
' "$LOG"
