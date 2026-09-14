#!/usr/bin/env python3
"""Hand-derived checks for recent-download and visit-boundary page reports."""

import json
from pathlib import Path

from families.pages import grouped_values, recent_downloads, visit_boundary_pages


contracts = json.loads(Path(__file__).with_name("report-contracts.json").read_text())["reports"]
expected = {
    "slim_p4_07_top_categories": ("slim_p4_07", "top_dimension"),
    "slim_p4_09_top_downloads": ("slim_p4_09", "top_dimension"),
    "slim_p4_13_top_internal_searches": ("slim_p4_13", "top_dimension"),
    "slim_p4_15_recent_categories": ("slim_p4_15", "recent_dimension"),
    "slim_p4_152_recent_tags": ("slim_p4_152", "recent_dimension"),
    "slim_p4_16_top_not_found": ("slim_p4_16", "top_dimension"),
    "slim_p4_18_top_authors": ("slim_p4_18", "top_dimension"),
    "slim_p4_19_top_tags": ("slim_p4_19", "top_dimension"),
    "slim_p4_20_recent_downloads": ("slim_p4_20", "recent_downloads"),
    "slim_p4_24_exit_pages": ("slim_p4_24", "exit_pages"),
    "slim_p4_25_entry_pages": ("slim_p4_25", "entry_pages"),
}
assert {key: (contracts[key]["report_id"], contracts[key]["kind"]) for key in expected} == expected
capture = (Path(__file__).parents[1] / "docker" / "report-answers.php").read_text()
for key in expected:
    assert key in capture
assert "'order_by'    => 'MAX(dt) DESC'" in capture
assert "['slim_p4_24_exit_pages' => 'MAX', 'slim_p4_25_entry_pages' => 'MIN']" in capture
assert "unset($row['visit_id']);" in capture
assert "$grouped_page_shapes" in capture


rows = [
    {"id": 1, "visit_id": 7, "resource": b"/entry", "content_type": b"page", "dt": 10},
    {"id": 2, "visit_id": 7, "resource": b"/exit", "content_type": b"download", "dt": 20},
    {"id": 3, "visit_id": 8, "resource": b"/entry", "content_type": b"download", "dt": 11},
    {"id": 4, "visit_id": 8, "resource": b"/other", "content_type": b"download", "dt": 30},
    {"id": 5, "visit_id": 9, "resource": b"/exit", "content_type": b"DOWNLOAD ", "dt": 40},
    {"id": 6, "visit_id": 0, "resource": b"/zero-first", "content_type": b"page", "dt": 12},
    {"id": 7, "visit_id": 0, "resource": b"/zero-last", "content_type": b"page", "dt": 13},
]

assert recent_downloads(rows, 10, 40, 2) == [
    {"resource": "/other", "counthits": "1", "dt": "30"},
    {"resource": "/exit", "counthits": "2", "dt": "40"},
]
assert grouped_values(rows, 10, 40, "resource", 200, content_type="down", contains=True) == [
    {"resource": "/entry", "counthits": "1"},
    {"resource": "/other", "counthits": "1"},
    {"resource": "/exit", "counthits": "2"},
]
assert grouped_values([dict(rows[0], resource=b"/entry///")], 10, 40, "resource", 200,
                      trim_slash=True, recent=True) == [
    {"resource": "/entry", "counthits": "1", "dt": "10"},
]
assert visit_boundary_pages(rows, 10, 40, "MIN", 200) == [
    {"resource": "/exit", "counthits": "1"},
    {"resource": "/zero-first", "counthits": "1"},
    {"resource": "/entry", "counthits": "2"},
]
assert visit_boundary_pages(rows, 10, 40, "MAX", 200) == [
    {"resource": "/other", "counthits": "1"},
    {"resource": "/zero-last", "counthits": "1"},
    {"resource": "/exit", "counthits": "2"},
]

try:
    visit_boundary_pages(rows + [dict(rows[0], id=8, visit_id=10, resource=b"/ENTRY")],
                         10, 40, "MIN", 200)
    raise AssertionError("ambiguous case-insensitive display value passed")
except ValueError:
    pass

try:
    recent_downloads([dict(rows[1], resource="/café")], 10, 40, 200)
    raise AssertionError("non-ASCII collation was guessed")
except ValueError:
    pass

print("PASS: grouped page reports preserve filters, visit boundaries, visit zero, ranking and LIMIT")
