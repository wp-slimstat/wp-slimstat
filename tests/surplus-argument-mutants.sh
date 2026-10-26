#!/usr/bin/env bash
# Does the surplus-argument gate see BOTH spellings, and the shipped defect?
set -uo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

S="${TMPDIR:-/tmp}"
GATE="php tests/surplus-argument-scan-test.php"
PROBE=src/__surplus_probe.php
MISMATCH=0

run() {
  local label="$1" want="$2" got
  if $GATE >/dev/null 2>&1; then got=PASS; else got=FAIL; fi
  if [ "$got" = "$want" ]; then
    printf '  %-56s want=%-4s got=%s\n' "$label" "$want" "$got"
  else
    printf '  %-56s want=%-4s got=%s   <-- MISMATCH\n' "$label" "$want" "$got"
    MISMATCH=$((MISMATCH + 1))
  fi
}

echo "surplus-argument gate — both spellings, and the defect that shipped"

rm -f "$PROBE"; run "clean tree" PASS

# The reviewer's finding 1: a fully-qualified call was entirely invisible on PHP 8.
printf '<?php\nfunction __sp() { \\wp_slimstat::date_i18n("U", 1, 2, 3); }\n' > "$PROBE"
run "FULLY-QUALIFIED surplus call (was invisible)" FAIL

printf '<?php\nfunction __sp() { wp_slimstat::date_i18n("U", 1, 2, 3); }\n' > "$PROBE"
run "unqualified surplus call" FAIL

# A closure argument carrying statement-level commas must NOT be miscounted (was a false fail).
printf '<?php\nfunction __sp() { \\wp_slimstat::date_i18n(implode(",", array_map(function ($x) { $a = 1; return $x; }, [1,2]))); }\n' > "$PROBE"
run "closure arg with inner commas (was false FAIL)" PASS

# PHP 7.3+ trailing comma must not read as an extra argument (was a false fail).
printf '<?php\nfunction __sp() { \\wp_slimstat::date_i18n("U", 1,); }\n' > "$PROBE"
run "trailing comma, arity 2 (was false FAIL)" PASS

# A legitimate call at exactly the declared arity.
printf '<?php\nfunction __sp() { \\wp_slimstat::date_i18n("U", 12345); }\n' > "$PROBE"
run "exactly the declared arity" PASS

rm -f "$PROBE"
echo
if [ "$MISMATCH" -eq 0 ]; then echo "VERDICT: all 6 cases correct"; else echo "VERDICT: $MISMATCH mismatch(es)"; fi
exit "$MISMATCH"
