#!/usr/bin/env python3
"""Hand-derived checks for independent summary semantics."""

from families.summary import bouncing_pages, bouncing_visits, pages_per_visit, visit_duration, visitors_summary


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

page_rows = [
    {"visit_id": 1, "content_type": b"post", "resource": b"/single", "dt": 10},
    {"visit_id": 2, "content_type": b"post", "resource": b"/double", "dt": 11},
    {"visit_id": 3, "content_type": b"post", "resource": b"/double", "dt": 12},
    {"visit_id": 4, "content_type": b"404", "resource": b"/excluded", "dt": 13},
    {"visit_id": 0, "content_type": b"post", "resource": b"/excluded", "dt": 14},
]
assert bouncing_pages(page_rows, 10, 14) == 1

audience = [
    {"id": 1, "visit_id": 1, "browser_type": 0, "ip": b"A", "username": b"sam", "dt": 10},
    {"id": 2, "visit_id": 2, "browser_type": 0, "ip": b"a", "username": b"Sam ", "dt": 11},
    {"id": 3, "visit_id": 2, "browser_type": 0, "ip": None, "username": None, "dt": 12},
    {"id": 4, "visit_id": 3, "browser_type": 1, "ip": b"bot", "username": b"", "dt": 13},
    {"id": 5, "visit_id": 0, "browser_type": 0, "ip": b"zero", "username": None, "dt": 14},
    {"id": 6, "visit_id": 4, "browser_type": 0, "ip": b"late", "username": None, "dt": 21},
]
assert bouncing_visits(audience, 10, 20) == 1
assert pages_per_visit(audience, 10, 20) == [{"avghits": "1.3333", "maxhits": "2"}]
summary = {row["metric"]: row["value"] for row in visitors_summary(audience, 10, 20)}
assert summary == {
    "Bots": "1", "Bounce rate": "66.67", "Known visitors": "2", "Longest visit": "2 hits",
    "Pageviews per visit": "1.33", "Single-page Visits": "2", "Unique IPs": "1", "Visits": "2",
}, summary
assert next(row for row in visitors_summary(audience[:1], 10, 20)
            if row["metric"] == "Bounce rate")["value"] == "100.00"
try:
    visitors_summary([dict(audience[0], username="café")], 10, 20)
    raise AssertionError("non-ASCII summary collation was guessed")
except ValueError:
    pass

print("PASS: audience summaries preserve distinct, bounce, bot, null and collation semantics")
