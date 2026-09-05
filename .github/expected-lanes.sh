#!/usr/bin/env bash
#
# The CI lanes a tag must find green before anything is published to wordpress.org.
#
# main.yml is tag-triggered and cannot `needs:` across workflow files, so its gate asks
# GitHub about the tagged commit's CI run and compares the conclusions against this list.
# The list is DERIVED FROM ci.yml AT THIS COMMIT, so there is no number to drift: bump the
# PHP matrix, add a WordPress lane, widen the escaping gate's condition, and the deploy gate
# follows in the same commit that made the change.
#
# It lives in a file rather than inline in main.yml for one reason: a derivation inlined in
# a workflow can only be read, and every gate in this repository that was only read turned
# out to be wrong in a way reading did not show. As a script it can be EXECUTED by
# tests/perf-gate-integrity-test.php §5b, which computes the same set from ci.yml through
# a different reader (slimstat_ci_wp_lanes() + slimstat_ci_step_runs_for()) and requires the
# two to agree exactly. `/.github` is in .distignore, so this never reaches the SVN repo.
#
# Writes ONLY to stdout. The deploy job redirects it into $RUNNER_TEMP; nothing this workflow
# runs may create a file inside the checkout, because the checkout is what gets rsynced to
# wordpress.org and .distignore is its only exclusion list.
#
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ci="$root/.github/workflows/ci.yml"

[ -f "$ci" ] || { echo "expected-lanes: no ci.yml at $ci" >&2; exit 1; }

# COMMENT LINES DROPPED BEFORE ANY VERSION IS READ. ci.yml is roughly half comment, and its
# comments quote lane versions as prose -- "it said 6.4 || 7.0 while 7.1 was added" sits four
# lines above the condition it describes. Both this repo's §8 gate and its Pro twin have been
# fooled by exactly that shape: a version found in a comment credited a lane that does not run.
code=$(grep -v '^[[:space:]]*#' "$ci")

# ---- Tier 1: one lane per PHP in the `fast:` job's matrix ------------------------------
tier1=$(printf '%s\n' "$code" \
  | sed -n '/^  fast:/,/^  phpstan:/p' \
  | grep -m1 -oE 'php: \[[^]]*\]' \
  | grep -oE '[0-9]+\.[0-9]+' || true)

# ---- Tier 2: the lanes that carry the BLOCKING escaping gate ---------------------------
# tests/reports-output-escaping-test.php is the only XSS gate over the report render path
# that has a CI home at all, and it is a blocking step inside Tier 2 -- so a commit whose
# Tier 2 lane is red is a commit whose XSS gate may have failed. Requiring Tier 1 and PHPStan
# alone let that commit deploy.
#
# Not the whole Tier 2 matrix: the interior WP lanes exist for compat breadth and their E2E
# step is continue-on-error, so requiring them would gate the release on lane setup. The lanes
# named here are the ones where a red job means a release gate said no.
tier2_block=$(printf '%s\n' "$code" | sed -n '/^  standard:/,/^  nightly:/p')

esc_wp=$(printf '%s\n' "$tier2_block" \
  | grep -B4 'reports-output-escaping-test\.php' \
  | grep -oE "matrix\.wp == '[0-9]+\.[0-9]+'" \
  | grep -oE '[0-9]+\.[0-9]+' || true)

if [ -z "$esc_wp" ]; then
  # A step with no `if:` runs on every cell of the matrix. Falling back to the full lane list
  # is the honest reading of that, and it fails CLOSED: the set can only grow. An empty set
  # here would make the whole Tier 2 requirement vacuous, which is the failure this file is
  # being added to fix.
  esc_wp=$(printf '%s\n' "$tier2_block" | grep -oE '\{ wp: "[0-9]+\.[0-9]+"' | grep -oE '[0-9]+\.[0-9]+' || true)
fi

echo "Static analysis · PHPStan"

for v in $tier1; do
  echo "Tier 1 · fast · PHP $v"
done

for wp in $esc_wp; do
  # `.` is any-character in a regex, so 6.4 would also match a lane called 674.
  wp_re="${wp//./[.]}"
  php=$(printf '%s\n' "$tier2_block" \
    | grep -oE "\{ wp: \"${wp_re}\", php: \"[0-9]+\.[0-9]+\" \}" \
    | grep -oE 'php: "[0-9]+\.[0-9]+"' \
    | grep -oE '[0-9]+\.[0-9]+' \
    | head -1 || true)
  if [ -z "$php" ]; then
    echo "expected-lanes: ci.yml runs the escaping gate on WP ${wp}, but the Tier 2 matrix declares no such lane" >&2
    exit 1
  fi
  echo "Tier 2 · standard · E2E · WP $wp · PHP $php"
done

# ---- Vacuity floors, one per family ----------------------------------------------------
# `wc -l < expected.txt -lt 2` was the whole floor before, and it is satisfied by PHPStan plus
# one Tier 1 lane -- so the entire Tier 2 requirement could vanish from this derivation and the
# deploy would still be reported as gated. Each family answers for itself.
[ -n "$tier1" ]  || { echo "expected-lanes: no Tier 1 PHP matrix found in ci.yml" >&2; exit 1; }
[ -n "$esc_wp" ] || { echo "expected-lanes: no Tier 2 WordPress lanes found in ci.yml" >&2; exit 1; }
