#!/usr/bin/env python3
"""Hand-derived acceptance checks for the independent chart family."""

from datetime import datetime, timezone
import json
from pathlib import Path

from families.chart import pageviews_chart


contracts = json.loads(Path(__file__).with_name('report-contracts.json').read_text())['reports']
assert {key for key, value in contracts.items() if value['family'] == 'chart'} == {
    'chart_daily', 'chart_weekly', 'chart_searchterms_pinned', 'chart_users_pinned',
    'slim_p4_26_01_chart_daily', 'slim_p4_26_01_chart_weekly',
    'slim_p3_01_chart_daily', 'slim_p3_01_chart_weekly'}
assert (contracts['chart_daily']['duration_days'], contracts['chart_daily']['granularity']) == (5, 'DAY')
assert (contracts['chart_weekly']['duration_days'], contracts['chart_weekly']['granularity']) == (60, 'WEEK')
assert contracts['slim_p4_26_01_chart_daily']['metric_column'] == 'outbound_resource'
for key in ('chart_daily', 'chart_weekly'):
    assert contracts[key]['metric_column'] == 'id'
    assert contracts[key]['metric2_column'] == 'ip'
capture = (Path(__file__).parents[1] / 'docker' / 'report-answers.php').read_text()
assert "$args['chart_data'] = $chart_data;" in capture
for key in ('slim_p4_26_01_chart_daily', 'slim_p4_26_01_chart_weekly'):
    start = capture.index("$capture_ext('%s'" % key)
    assert '$outbound_chart_data' in capture[start:start + 350]
for key in ('slim_p3_01_chart_daily', 'slim_p3_01_chart_weekly'):
    start = capture.index("$capture_ext('%s'" % key)
    assert '$traffic_chart_data' in capture[start:start + 350]
# Chart::fetchChartData() allowlists the WHERE against the report registry, so a capture that
# spells it differently from slim_p3_01 does not capture that report — it captures nothing.
registry = (Path(__file__).parents[2] / 'admin' / 'view' / 'wp-slimstat-reports.php').read_text()
clause = '\'(referer IS NOT NULL AND referer NOT LIKE "%\' . home_url() . \'%")\''
assert registry.count(clause) == 1 and capture.count(clause) == 1


def ts(value):
    return int(datetime.fromisoformat(value).replace(tzinfo=timezone.utc).timestamp())


rows = [
    {'dt': ts('2026-01-05T04:00:00'), 'ip': b'a'},
    {'dt': ts('2026-01-05T05:00:00'), 'ip': None},
    {'dt': ts('2026-01-06T04:00:00'), 'ip': b'a'},
    {'dt': ts('2026-01-06T05:00:00'), 'ip': b'b'},
    {'dt': ts('2026-01-07T04:00:00'), 'ip': b'b'},
    {'dt': ts('2026-01-08T04:00:00'), 'ip': b'a'},
    {'dt': ts('2026-01-08T05:00:00'), 'ip': None},
    {'dt': ts('2026-01-09T04:00:00'), 'ip': b'b'},
    {'dt': ts('2026-01-09T05:00:00'), 'ip': b'c'},
    {'dt': ts('2026-01-10T04:00:00'), 'ip': b'c'},
]
capture_end = ts('2026-01-11T12:00:00')
# Inclusive equal-length periods meet without sharing an endpoint. A pageview
# at the previous period's first second must not disappear from the reference.
boundary = pageviews_chart([
    {'dt': ts('2026-01-05T00:00:00'), 'ip': b'previous-first'},
    {'dt': ts('2026-01-07T23:59:59'), 'ip': b'previous-last'},
    {'dt': ts('2026-01-08T00:00:00'), 'ip': b'current-first'},
], capture_end, 3, 'DAY')
assert boundary['datasets_prev']['v1'] == [1, 0, 1], boundary
assert boundary['datasets']['v1'] == [1, 0, 0], boundary
assert boundary['period_end'] == ts('2026-01-10T23:59:59')
assert boundary['previous_end'] == ts('2026-01-07T23:59:59')

# Jan 6-10 touches one calendar week; Jan 1-5 touches two. The second
# previous bucket has a real value and the absent current bucket is unknown.
unequal_weeks = pageviews_chart([
    {'dt': ts('2026-01-01T00:00:00'), 'ip': b'a'},
    {'dt': ts('2026-01-05T12:00:00'), 'ip': b'b'},
    {'dt': ts('2026-01-06T12:00:00'), 'ip': b'c'},
], capture_end, 5, 'WEEK')
assert unequal_weeks['labels'] == ["'2026/01/06'", ''], unequal_weeks
assert unequal_weeks['prev_labels'] == ['2026/01/01', '2026/01/05'], unequal_weeks
assert unequal_weeks['datasets']['v1'] == [1, None], unequal_weeks
assert unequal_weeks['datasets_prev']['v1'] == [1, 1], unequal_weeks
assert unequal_weeks['totals'][1]['v1'] == 2, unequal_weeks

daily = pageviews_chart(rows, capture_end, 3, 'DAY')
assert daily == {
    'labels': ["'2026/01/08'", "'2026/01/09'", "'2026/01/10'"],
    'period_end': ts('2026-01-10T23:59:59'),
    'previous_end': ts('2026-01-07T23:59:59'),
    'totals': [
        {'v1': 4, 'v2': 3, 'period': 'current'},
        {'v1': 4, 'v2': 2, 'period': 'previous'},
    ],
    'prev_labels': ['2026/01/05', '2026/01/06', '2026/01/07'],
    'datasets': {'v1': [1, 2, 1], 'v2': [1, 2, 1]},
    'datasets_prev': {'v1': [1, 2, 1], 'v2': [1, 2, 1]},
    'today': '2026/01/11',
    'granularity': 'DAY',
}, daily

weekly = pageviews_chart(rows, capture_end, 10, 'WEEK')
assert weekly['labels'] == ["'2026/01/01'", "'2026/01/05'"], weekly
assert weekly['prev_labels'] == ['2025/12/22', '2025/12/29'], weekly
assert weekly['datasets'] == {'v1': [0, 8], 'v2': [0, 3]}, weekly
assert weekly['today'] == '2026/01/05', weekly

# Pageviews count every recorded ID; unique addresses still omit NULL independently.
identified = [dict(row, id=index + 1) for index, row in enumerate(rows)]
pageviews = pageviews_chart(identified, capture_end, 3, 'DAY', metric_column='id', metric2_column='ip')
assert pageviews['datasets'] == {'v1': [2, 2, 1], 'v2': [1, 2, 1]}, pageviews
assert pageviews['datasets_prev'] == {'v1': [2, 2, 1], 'v2': [1, 2, 1]}, pageviews
assert pageviews['totals'] == [
    {'v1': 5, 'v2': 3, 'period': 'current'},
    {'v1': 5, 'v2': 2, 'period': 'previous'},
], pageviews

search_rows = [
    {'dt': ts('2026-01-08T04:00:00'), 'searchterms': b'term'},
    {'dt': ts('2026-01-08T05:00:00'), 'searchterms': b'TERM '},
    {'dt': ts('2026-01-09T04:00:00'), 'searchterms': b'_'},
    {'dt': ts('2026-01-09T05:00:00'), 'searchterms': None},
]
search = pageviews_chart(search_rows, capture_end, 3, 'DAY', 1, 'searchterms', ('', '_'), 'ascii_ci')
assert search['datasets'] == {'v1': [2, 0, 0], 'v2': [1, 0, 0]}, search

users = pageviews_chart([
    {'dt': ts('2026-01-08T04:00:00'), 'username': b'Alice'},
    {'dt': ts('2026-01-08T05:00:00'), 'username': b'alice '},
    {'dt': ts('2026-01-09T04:00:00'), 'username': None},
], capture_end, 3, 'DAY', 1, 'username', (), 'ascii_ci')
assert users['datasets'] == {'v1': [2, 0, 0], 'v2': [1, 0, 0]}, users

try:
    pageviews_chart([{'dt': ts('2026-01-08T04:00:00'), 'searchterms': 'café'}],
                    capture_end, 3, 'DAY', 1, 'searchterms', ('', '_'), 'ascii_ci')
    raise AssertionError('non-ASCII chart collation was guessed')
except ValueError:
    pass

try:
    pageviews_chart([{'dt': 'not-an-int', 'ip': b'a'}], capture_end, 3, 'DAY')
    raise AssertionError('non-integer chart timestamp passed')
except ValueError:
    pass

print('PASS: independent DAY/WEEK chart buckets, totals, distinct values and null handling')
