#!/usr/bin/env python3
"""Hand-derived checks for independent summary semantics."""

from families.summary import visit_duration


def row(visit_id, dt, dt_out, browser_type=0):
    return {"visit_id": visit_id, "browser_type": browser_type, "dt": dt, "dt_out": dt_out}


rows = [
    row(1, 100, 130),
    row(2, 100, 131), row(2, 120, 160),
    row(3, 100, 280), row(4, 100, 400), row(5, 100, 520),
    row(6, 100, 700), row(7, 100, 701),
    row(8, 100, None),
    row(10, 100, 130, browser_type=1), row(0, 100, 130), row(11, 99, 130),
]
value = visit_duration(rows, 100, 120)
by_metric = {item["metric"]: item for item in value}
assert [by_metric[name]["counthits"] for name in (
    "0 - 30 seconds", "31 - 60 seconds", "1 - 3 minutes", "3 - 5 minutes",
    "5 - 7 minutes", "7 - 10 minutes", "More than 10 minutes",
)] == [1, 1, 1, 1, 1, 1, 1]
assert by_metric["0 - 30 seconds"]["value"] == "12.50%"
assert by_metric["Average Visit Duration"]["value"] == "05:11"
assert [item.get("counthits") for item in value[:-1]] == [1] * 7
assert [item["metric"] for item in value[:4]] == [
    "0 - 30 seconds", "1 - 3 minutes", "3 - 5 minutes", "31 - 60 seconds"]
assert visit_duration([], 1, 2)[-1] == {
    "details": "", "metric": "Average Visit Duration", "value": "0:00"}
assert all(item["value"] == "0%" for item in visit_duration([], 1, 2)[:-1])

try:
    visit_duration([{"visit_id": 1}], 1, 2)
    raise AssertionError("missing consumed duration columns passed")
except ValueError:
    pass

print("PASS: visit-duration summary preserves grouping, bounds, nulls and weighted average")
