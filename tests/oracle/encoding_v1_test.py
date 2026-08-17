#!/usr/bin/env python3
"""The Python encoder against the hand-computed golden fixtures.

  python3 tests/oracle/encoding_v1_test.py

Every expected value comes from tests/oracle/golden-encoding-fixtures.json, whose tokens were
typed out from the spec rather than captured from this code. A fixture blessed from an
implementation's own output proves only that the implementation is self-consistent, which is
the failure this whole campaign is built to avoid.

The assertion COUNT is itself asserted at the end. Without that, shrinking field_cases to one
entry — or to zero — leaves this gate printing OK and exiting 0, which is a counter nothing
checks, which is decoration (the sibling gate answer-envelope-classes-test.php made the same
argument first).
"""
import json, os, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import encoding_v1 as E

HERE = os.path.dirname(os.path.abspath(__file__))
FIXTURES = json.load(open(os.path.join(HERE, "golden-encoding-fixtures.json")))

passed = failed = 0


def check(label, got, want):
    global passed, failed
    if got == want:
        passed += 1
    else:
        failed += 1
        print(f"FAIL: {label}\n      got  {got!r}\n      want {want!r}", file=sys.stderr)


def check_raises(label, fn):
    """Routed through check() rather than bumping the counter directly: the two most important
    assertions in this file — that the fail-loudly rule is a property of the code and not a
    sentence in a document — must not be the two the counter does not own."""
    try:
        fn()
        check(label, "no exception", "ValueError")
    except ValueError:
        check(label, "ValueError", "ValueError")


for case in FIXTURES["field_cases"]:
    values = [c["value"] for c in case["columns"]]
    types = [c["type"] for c in case["columns"]]
    check(f"{case['name']} row encoding", E.encode_row(values, types), case["row_encoding"])
    check(f"{case['name']} chained hash",
          E.chain([case["row_encoding"]]), case["chained_hash_single_row"])

order = FIXTURES["order_dependence"]
check("chain in declared order", E.chain(order["rows_in_order"]), order["chained_hash"])
check("chain reversed", E.chain(list(reversed(order["rows_in_order"]))),
      order["chained_hash_reversed"])
# The point of the case, asserted rather than implied by two hashes sitting side by side.
check("order actually changes the hash",
      order["chained_hash"] != order["chained_hash_reversed"], True)

empty = FIXTURES["empty_table"]
check("empty table hashes to the seed alone", E.chain([]), empty["chained_hash"])
check("empty table is NOT sha256 of nothing",
      E.chain([]) != empty["sha256_of_empty_string_for_contrast"], True)

man = FIXTURES["manifest"]
cols = [(n, t, nul == "NULL") for n, t, nul in (line.split("|") for line in man["lines"])]
check("manifest hash", E.manifest_hash(cols, man["order_by"]), man["manifest_hash"])
widened = [(n, "varchar(45)" if n == "ip" else t, nul) for n, t, nul in cols]
check("a widened type moves the manifest hash",
      E.manifest_hash(widened, man["order_by"]),
      man["manifest_hash_if_ip_widened_to_varchar_45"])
check("a different ORDER BY moves the manifest hash",
      E.manifest_hash(cols, "dt"), man["manifest_hash_if_ordered_by_dt"])

# The type table is read from the shared fixture, not from a constant copied into each
# language. It is the one thing both encoders must agree on that a value-based fixture cannot
# see: add 'float' to one side's list alone and every value assertion still passes.
for declared, want_kind in FIXTURES["type_kinds"].items():
    check(f"kind of {declared}", E._kind(declared), want_kind)
for declared in FIXTURES["types_that_must_raise"]:
    check_raises(f"{declared} must raise", lambda d=declared: E.encode_field(1, d))
    # A NULL in an unsupported column is still unsupported; returning \NUL early would hide it.
    check_raises(f"{declared} must raise even for NULL", lambda d=declared: E.encode_field(None, d))

ct = FIXTURES["canonical_type"]
for a, b in ct["same_after_canonicalisation"]:
    check(f"canonical_type({a}) == canonical_type({b})",
          E.canonical_type(a), E.canonical_type(b))
for a, b in ct["different_after_canonicalisation"]:
    check(f"canonical_type({a}) != canonical_type({b})",
          E.canonical_type(a) != E.canonical_type(b), True)

# fingerprint() must accept a one-shot ITERATOR, not only a list — the export is streamed from
# a sqlite3 cursor, and an earlier version called len(rows), which silently required the caller
# to materialise the whole thing first.
streamed = E.fingerprint(iter([[None, ""], ["", None]]),
                         [("ip", "varchar(39)", True), ("other_ip", "varchar(39)", True)], "id")
check("fingerprint counts a one-shot iterator", streamed["rows"], 2)
check("fingerprint over an iterator matches the chain",
      streamed["chained_hash"],
      E.chain([E.encode_row([None, ""], ["varchar(39)", "varchar(39)"]),
               E.encode_row(["", None], ["varchar(39)", "varchar(39)"])]))

expected = FIXTURES["expected_assertions"]["python"]
if passed + failed != expected:
    print(f"FAIL: assertion floor — ran {passed + failed}, fixture declares {expected}. "
          f"A shrunk fixture must not print green.", file=sys.stderr)
    failed += 1

print(f"{'FAIL' if failed else 'OK'}: encoding_v1 — {passed} assertions passed, {failed} failed")
sys.exit(1 if failed else 0)
