#!/usr/bin/env python3
"""Hand-derived goal and funnel fixture semantics."""

from families.goals import funnel_results, goal_results, percent_text


rows = [
    {"id": 1, "resource": b"/", "browser": b"Chrome", "country": b"us",
     "fingerprint": b"a", "visit_id": 1, "ip": b"1", "dt": 10},
    {"id": 2, "resource": b"/next", "browser": b"Chrome", "country": b"us",
     "fingerprint": b"a", "visit_id": 1, "ip": b"1", "dt": 20},
    {"id": 3, "resource": b"plain", "browser": b"Firefox", "country": None,
     "fingerprint": b"b", "visit_id": 2, "ip": b"2", "dt": 15},
]
rule = {"dimension": "resource", "operator": "contains", "value": "/"}
assert goal_results(rows, 10, 20, rule) == {
    "total": 2, "uniques": 1, "cr": 50, "total_visitors": 2}

steps = [dict(rule, name="step-1"),
         {"name": "step-2", "dimension": "browser", "operator": "is_not_empty", "value": ""}]
assert funnel_results(rows, 10, 20, steps) == [
    {"name": "step-1", "visitors": 1, "pct": 100, "dropoff": 0, "unreachable": False},
    {"name": "step-2", "visitors": 1, "pct": 100, "dropoff": 0, "unreachable": False},
]
assert percent_text(99.9) == "99.9%" and percent_text(100) == "100%"

print("PASS: pinned goals and funnels preserve visitor identity, order, row exclusion and percentages")
