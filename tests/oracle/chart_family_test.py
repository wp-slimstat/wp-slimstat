#!/usr/bin/env python3
"""Hand-derived acceptance checks for the independent chart family."""

from datetime import datetime, timezone
import json
from pathlib import Path

from families.chart import pageviews_chart


contracts = json.loads(Path(__file__).with_name('report-contracts.json').read_text())['reports']
assert {key for key, value in contracts.items() if value['family'] == 'chart'} == {
    'chart_daily', 'chart_weekly'}
assert (contracts['chart_daily']['duration_days'], contracts['chart_daily']['granularity']) == (5, 'DAY')
assert (contracts['chart_weekly']['duration_days'], contracts['chart_weekly']['granularity']) == (60, 'WEEK')


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
daily = pageviews_chart(rows, capture_end, 3, 'DAY')
assert daily == {
    'labels': ["'2026/01/08'", "'2026/01/09'", "'2026/01/10'"],
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

try:
    pageviews_chart([{'dt': 'not-an-int', 'ip': b'a'}], capture_end, 3, 'DAY')
    raise AssertionError('non-integer chart timestamp passed')
except ValueError:
    pass

print('PASS: independent DAY/WEEK chart buckets, totals, distinct values and null handling')
