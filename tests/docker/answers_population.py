#!/usr/bin/env python3
"""Which keys of an answers document are REPORTS, defined once.

`compare-answers.sh` prints a verdict two ways — `IDENTICAL — N reports` and
`DIFFERENCES in D of N reports` — and until Run 61 each branch computed N for itself. They
disagreed by three:

    if not diffs:
        print('VERDICT: IDENTICAL - %d reports' % len([k for k in a if not k.startswith('_arm_')]))
    print('VERDICT: DIFFERENCES in %d of %d reports' % (len(diffs), len(set(a) | set(b))))
                                                                     ^^^^^^^^^^^^^^^^^^^^
The diff loop skips every `_arm_` key: they are provenance (`_arm_version`, `_arm_fingerprint`,
`_arm_files`), the `arms_differ` control asserts they MUST differ, and they are not report answers.
So the DIFFERENCES branch divided a numerator counted over 23 keys by a denominator counted over
26 — three keys barred from the top and guaranteed to differ at the bottom.

**Every non-identical run this harness ever printed carried the inflated figure**, and it reached
Run 50 ("2 of 26 core reports differ"), Run 58 ("DIFFERENCES in 2 of 26") and Run 60 ("3 of 26").
The numerators were always right and no verdict changes; the denominators are corrected to 23.

Nothing caught it because **the two branches are never taken by the same run**, so no single log
shows both numbers. Two archived logs of the same document shape do — the null control ends
`IDENTICAL — 23 reports` and the campaign run ends `DIFFERENCES in 2 of 26 reports`, and those two
lines sat in the same directory for months. It was found by counting the packet by hand during an
adversarial re-derivation of S1's closure.

The rule lives here, both branches call it, and it has its own required-red fixtures — because a
population rule with one copy per caller is how the two callers came to disagree.
"""
from __future__ import annotations

import pathlib
import sys

# Provenance about the ARM, not an answer from a report. `arms_differ` requires these to differ,
# so counting them as reports means counting keys that can never agree.
ARM_PREFIX = "_arm_"


def comparable_keys(a: dict, b: dict) -> list:
    """The report keys present in either document, sorted. Provenance excluded.

    Union rather than intersection, deliberately: a key present on one arm and absent on the other
    is a real difference and must be in the denominator. Only the `_arm_` prefix is stripped.
    """
    return sorted(k for k in set(a) | set(b) if not k.startswith(ARM_PREFIX))


def _selftest() -> int:
    arm = {"_arm_version": "5.5.1", "_arm_fingerprint": "aaa", "_arm_files": 113}
    brm = {"_arm_version": "6.0.0", "_arm_fingerprint": "bbb", "_arm_files": 122}
    cases = [
        ("provenance keys are never reports",
         comparable_keys({**arm, "top_resource": 1}, {**brm, "top_resource": 2}),
         ["top_resource"]),
        ("a key on one arm only is still in the denominator",
         comparable_keys({"top_resource": 1}, {"top_resource": 1, "count_exit_pages": 0}),
         ["count_exit_pages", "top_resource"]),
        ("identical documents still report their population",
         comparable_keys({**arm, "x": 1, "y": 2}, {**arm, "x": 1, "y": 2}),
         ["x", "y"]),
        ("a document of nothing but provenance has no reports",
         comparable_keys(arm, brm), []),
        ("the real campaign shape counts 23, not 26",
         len(comparable_keys(
             {**arm, **{f"r{i}": i for i in range(23)}},
             {**brm, **{f"r{i}": i for i in range(23)}})),
         23),
    ]
    bad = 0
    for name, got, want in cases:
        ok = got == want
        bad += 0 if ok else 1
        print(f"  [{'PASS' if ok else 'FAIL'}] population: {name}: expected {want!r}, got {got!r}")

    # THE INVARIANT THE TWO BRANCHES BROKE: whatever the diff loop can produce must be a SUBSET of
    # what the denominator counts. This is the assertion whose absence let one branch drift.
    a = {**arm, "same": 1, "moved": 1, "old_only": 1}
    b = {**brm, "same": 1, "moved": 2, "new_only": 1}
    pop = comparable_keys(a, b)
    diffs = [k for k in pop if a.get(k) != b.get(k)]
    subset = set(diffs) <= set(pop)
    ratio_sane = len(diffs) <= len(pop)
    for name, got in (("every difference is inside the population", subset),
                      ("the numerator can never exceed the denominator", ratio_sane)):
        bad += 0 if got else 1
        print(f"  [{'PASS' if got else 'FAIL'}] population: {name}")

    # THE CONSUMER STILL DERIVES FROM THIS MODULE, checked rather than assumed.
    #
    # The first version of this fix unified the two DENOMINATORS and left the numerator — the diff
    # loop, ~170 lines away in the same heredoc — spelling `key.startswith('_arm_')` for itself. So
    # the registry mutation that edits ARM_PREFIX moved the denominator and could not reach the
    # number printed to its left, which is a mutation that proves nothing about the production path
    # (PITFALLS 91, one file over). Caught in review before it was committed.
    #
    # This is a shape assertion and is labelled as one: it cannot prove the consumer is correct,
    # only that it has not grown a fourth private copy of the rule this module exists to own.
    consumer = pathlib.Path(__file__).resolve().parent / "compare-answers.sh"
    if consumer.is_file():
        text = consumer.read_text()
        block = text.split("python3 - \"$ART\"", 1)[-1].split("\nPY\n", 1)[0]
        checks = [
            ("the consumer imports the rule", "from answers_population import comparable_keys" in block, True),
            ("and spells it nowhere itself", "startswith('_arm_')" in block, False),
            ("its diff loop derives from the same population", "for key in comparable" in block, True),
        ]
        for name, got, want in checks:
            ok = got is want
            bad += 0 if ok else 1
            print(f"  [{'PASS' if ok else 'FAIL'}] population: {name}")
        extra = len(checks)
    else:
        print("  [SKIP] population: compare-answers.sh not in this checkout — consumer unchecked")
        extra = 0

    total = len(cases) + 2 + extra
    colour = "\033[1;32m" if not bad else "\033[1;31m"
    print(f"{colour}PASS: answers population — {total - bad}/{total} assertions "
          f"(one rule, both verdict branches)\033[0m" if not bad
          else f"{colour}FAIL: answers population — {bad} of {total} assertions\033[0m")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(_selftest() if "--selftest" in sys.argv else 2)
