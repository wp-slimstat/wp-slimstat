"""S5 acceptance gate for the independent top family."""

import json
import pathlib
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

from families.top import evaluate, rank_top


def fail(message):
    raise AssertionError(message)


fixture = subprocess.run(
    ["php", str(ROOT / "tests/fixtures/golden/emit-top-case.php")],
    check=True,
    stdout=subprocess.PIPE,
    text=True,
)
case = json.loads(fixture.stdout)
contracts = json.loads((ROOT / "tests/oracle/report-contracts.json").read_text())
contract = contracts["reports"]["top_resource"]

expected = case["expected"]
result = evaluate("top_resource", contract, case["rows"], expected["limit"])

if result["report_id"] != expected["report_id"]:
    fail("top_resource report id drifted: %s != %s" % (result["report_id"], expected["report_id"]))
if result["rows"] != expected["rows"]:
    fail("top_resource ranked rows differ from hand truth: %r != %r" % (result["rows"], expected["rows"]))
if len(result["rows"]) != expected["limit"]:
    fail("top_resource LIMIT not applied: got %d rows, expected %d" % (len(result["rows"]), expected["limit"]))
if result["rows"][1]["counthits"] != result["rows"][2]["counthits"]:
    fail("top_resource fixture has no retained tie, so tie-break behavior is untested")

reversed_rows = list(reversed(case["rows"]))
if evaluate("top_resource", contract, reversed_rows, expected["limit"])["rows"] != result["rows"]:
    fail("top_resource answer depends on input row order")

unlimited = rank_top(case["rows"], contract["dimension"], tuple(contract["grain"]), None)
if len(unlimited) <= expected["limit"]:
    fail("top_resource fixture has no row beyond LIMIT, so truncation is vacuous")
if unlimited[:expected["limit"]] != expected["rows"]:
    fail("top_resource orders after LIMIT instead of before it")

merged_counts = {}
for row in case["rows"]:
    value = row[contract["dimension"]]
    merged_counts[value] = merged_counts.get(value, 0) + 1
if max(merged_counts.values()) != 16 or max(row["counthits"] for row in result["rows"]) != 7:
    fail("top_resource fixture no longer separates preserved blog grain (7) from merged grain (16)")

print("PASS: S5 top family — report slim_p1_08, %d raw rows, LIMIT %d, tie and grain traps live" % (
    len(case["rows"]), expected["limit"]
))
