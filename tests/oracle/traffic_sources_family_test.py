#!/usr/bin/env python3
"""Hand-derived acceptance checks for slim_p3_02's eight rows and slim_p3_01's two series."""

import json
from pathlib import Path

from families.chart import pageviews_chart
from families.summary import bouncing_pages, traffic_sources_summary


root = Path(__file__).resolve().parent
contracts = json.loads((root / 'report-contracts.json').read_text())['reports']
summary_contract = contracts['get_traffic_sources_summary']
assert summary_contract['report_id'] == 'slim_p3_02'
assert summary_contract['kind'] == 'traffic_sources_summary' and summary_contract['windowed']
for key in ('slim_p3_01_chart_daily', 'slim_p3_01_chart_weekly'):
    chart_contract = contracts[key]
    assert chart_contract['report_id'] == 'slim_p3_01'
    assert (chart_contract['metric_column'], chart_contract['metric2_column']) == ('referer', 'ip')
    assert chart_contract['distinct_v1'] is True
    assert chart_contract['row_filter']['excludes_self'] == {'column': 'referer', 'url': 'home_url'}

# The site's own URLs come from the arm, not from this file: the container's HTTP port is chosen
# at run time, so a literal here would model a different site than the one measured.
capture = (root.parent / 'docker' / 'report-answers.php').read_text()
assert "$slimstat_caps['_self_urls']" in capture
assert "'home_url' => home_url()," in capture
# Row 0 reads a static the report does not compute. Left alone it carries whatever the previous
# capture put there, which makes the answer depend on capture ORDER rather than on the window.
pinned = capture.index("$capture_windowed('get_traffic_sources_summary'")
assert 'wp_slimstat_db::$pageviews = (int) wp_slimstat_db::count_records();' \
    in capture[pinned:pinned + 400]

HOME, HOST = 'http://127.0.0.1:18970', '127.0.0.1'


def row(dt, **overrides):
    base = {'id': 1, 'ip': b'1.1.1.1', 'visit_id': 1, 'browser_type': 0, 'referer': None,
            'resource': b'/a', 'searchterms': None, 'content_type': b'text/html', 'dt': dt}
    base.update(overrides)
    return base


def answers(rows, start=100, end=1000):
    return {item['metric']: item['value']
            for item in traffic_sources_summary(rows, start, end, HOME, HOST)}


corpus = [
    row(60, id=0, ip=b'0.0.0.0', visit_id=10, referer=b'http://early.example/',
        searchterms=b'before the window', resource=b'/early'),
    row(100, id=1, ip=b'1.1.1.1', visit_id=1, referer=b'http://news.example/a',
        searchterms=b'slim stats', resource=b'/one'),
    row(200, id=2, ip=b'1.1.1.1', visit_id=1, referer=b'http://NEWS.example/a', resource=b'/one'),
    row(300, id=3, ip=b'2.2.2.2', visit_id=2, referer=b'http://127.0.0.1:18970/self',
        searchterms=b'ignored', resource=b'/two'),
    row(400, id=4, ip=b'3.3.3.3', visit_id=3, referer=None, resource=None),
    row(500, id=5, ip=b'4.4.4.4', visit_id=4, referer=b'http://other.example/x', resource=b'/three'),
]
value = answers(corpus)
assert [item['metric'] for item in traffic_sources_summary(corpus, 100, 1000, HOME, HOST)] == [
    'Pageviews', 'Unique Referrers', 'Direct Pageviews', 'From External SERP',
    'Unique Landing Pages', 'Bounce Pages', 'New Visitors Rate',
    'Currently from search engines'], 'row order or membership changed'
assert all(item['tooltip'].strip()
           for item in traffic_sources_summary(corpus, 100, 1000, HOME, HOST))

assert value['Pageviews'] == '5', value                      # in-window rows; id 0 is before it
assert value['Unique Referrers'] == '2', value               # NEWS/news fold; the self referer is out
assert value['Direct Pageviews'] == '1', value               # id 4 alone has no resource
assert value['From External SERP'] == '1', value             # id 1; id 3's is this site, id 0 early
assert value['Unique Landing Pages'] == '3', value           # /one, /two, /three
assert value['Currently from search engines'] == '0', value

# Bounce Pages counts pages seen by exactly one visit, and a row with no resource is not a page.
# (The shared where-builder adds `resource IS NOT NULL`; modelling it as its own group counted a
# direct pageview as a bounce.)
assert value['Bounce Pages'] == '2', value                   # /two and /ONE; /one has two rows
assert bouncing_pages(corpus, 100, 1000) == 2
assert bouncing_pages([row(10, id=1, visit_id=1, resource=None)], 0, 100) == 0

# New Visitors Rate: addresses seen exactly once, over human hits, half-up to two places.
thirty_two = [row(110 + index, id=index + 1, ip=b'shared', visit_id=index + 1)
              for index in range(31)] + [row(199, id=99, ip=b'lonely', visit_id=99)]
assert answers(thirty_two)['New Visitors Rate'] == '3.13', '100/32 is 3.125 and rounds up'
assert answers([row(110, ip=b'a', visit_id=1)])['New Visitors Rate'] == '100.00'
assert answers([])['New Visitors Rate'] == '0.00'
# Bots carry addresses but are not human hits, so the ratio can exceed 100 and is clamped there.
skewed = [row(110, id=1, ip=b'a', visit_id=1, browser_type=0),
          row(120, id=2, ip=b'b', visit_id=2, browser_type=1),
          row(130, id=3, ip=b'c', visit_id=3, browser_type=1)]
assert answers(skewed)['New Visitors Rate'] == '100.00', answers(skewed)
assert answers([row(110, ip=None, visit_id=1)])['New Visitors Rate'] == '0.00', \
    'a row with no address is not a new visitor'

# The last row is asked against the wall clock with no date filter. Capture runs at or after the
# window's end, so "newer than end - 300" is a superset of anything that row could still see: an
# empty superset proves 0, and a non-empty one means the corpus has no clock-free answer here.
live = corpus + [row(950, id=7, ip=b'8.8.8.8', visit_id=8, resource=b'/live',
                     referer=b'http://serp.example/', searchterms=b'now')]
try:
    traffic_sources_summary(live, 100, 1000, HOME, HOST)
    raise AssertionError('live-tail corpus answered a clock-bound row')
except ValueError:
    pass
# Same row, but the tail rows are this site's own referer: still nothing that row could count.
tame = corpus + [row(950, id=7, ip=b'8.8.8.8', visit_id=8, resource=b'/live',
                     referer=b'http://127.0.0.1:18970/x', searchterms=b'now')]
assert answers(tame)['Currently from search engines'] == '0'

for bad in ({'home_url': '', 'host': HOST}, {'home_url': HOME, 'host': ''}):
    try:
        traffic_sources_summary(corpus, 100, 1000, bad['home_url'], bad['host'])
        raise AssertionError('missing self URL accepted')
    except ValueError:
        pass

# ── slim_p3_01: two series that disagree about which rows they see ─────────
DAY = 86400
end = 10 * DAY
chart_rows = [
    {'dt': end - DAY, 'referer': b'http://a.example/', 'ip': b'1.1.1.1'},
    {'dt': end - DAY, 'referer': b'http://A.example/', 'ip': b'2.2.2.2'},   # one domain, two IPs
    {'dt': end - DAY, 'referer': b'http://b.example/', 'ip': None},         # domain only
    {'dt': end - DAY, 'referer': b'http://c.example/', 'ip': b'1.1.1.1'},   # address seen already
    {'dt': end - DAY, 'referer': None, 'ip': b'3.3.3.3'},                   # filtered out entirely
    {'dt': end - DAY, 'referer': b'http://127.0.0.1:18970/x', 'ip': b'4.4.4.4'},  # own traffic
]


def keep(row):
    return row['referer'] is not None and b'127.0.0.1:18970' not in row['referer'].lower()


chart = pageviews_chart(chart_rows, end, 5, 'DAY', 1, 'referer', (), 'ascii_ci',
                        True, 'ip', keep)
current = next(item for item in chart['totals'] if item['period'] == 'current')
# v1 counts distinct referers (a/A fold to one, plus b and c); v2 counts distinct addresses over
# the SAME rows, where b has none and c repeats a's — the two series disagree on purpose.
assert current == {'v1': 3, 'v2': 2, 'period': 'current'}, current
assert chart['datasets']['v1'][-2:] == [0, 3] and chart['datasets']['v2'][-2:] == [0, 2], \
    chart['datasets']
# With no second column the model is the single-column one it always was.
single = pageviews_chart(chart_rows, end, 5, 'DAY', 1, 'referer', (), 'ascii_ci', True, None, keep)
assert single['totals'] == [{'v1': 3, 'v2': 3, 'period': 'current'},
                            {'v1': 0, 'v2': 0, 'period': 'previous'}], single['totals']

print('PASS: traffic summary rows, bounce-page NULL drop, new-visitor rounding, live-tail refusal '
      'and the two-column chart')
