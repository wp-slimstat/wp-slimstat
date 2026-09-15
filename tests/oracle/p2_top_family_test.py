#!/usr/bin/env python3
"""Required-red cases for pinned p2 top and recent-top reports."""

import sqlite3
import tempfile
from pathlib import Path

from adapters import oracle_for
from families.top import rank_recent_top, rank_top


rows = [
    {'blog_id': 1, 'language': 'en-US', 'browser': 'Chrome', 'browser_version': '120',
     'ip': 'a', 'screen_width': 1920, 'screen_height': 1080, 'resolution': '800x600',
     'country': 'US', 'platform': 'Linux', 'dt': 100},
    {'blog_id': 1, 'language': 'en-US', 'browser': 'Chrome', 'browser_version': '120',
     'ip': 'a', 'screen_width': 1920, 'screen_height': 1080, 'resolution': '800x600',
     'country': 'US', 'platform': 'Linux', 'dt': 300},
    {'blog_id': 1, 'language': None, 'browser': 'Chrome', 'browser_version': '121',
     'ip': None, 'screen_width': 0, 'screen_height': 0, 'resolution': None,
     'country': 'DE', 'platform': 'Windows', 'dt': 250},
]

assert rank_top(rows, ('browser', 'browser_version'), limit=200, start=90, end=300) == [
    {'blog_id': 1, 'browser': 'Chrome', 'browser_version': '120', 'counthits': 2},
    {'blog_id': 1, 'browser': 'Chrome', 'browser_version': '121', 'counthits': 1},
]
assert rank_top(rows, ('screen_width', 'screen_height'), limit=200,
                where=[('screen_width', 'ne', 0), ('screen_height', 'ne', 0)],
                start=90, end=300) == [
    {'blog_id': 1, 'screen_width': 1920, 'screen_height': 1080, 'counthits': 2},
]
assert rank_recent_top(rows, ('country',), ('blog_id',), 200, 90, 300) == [
    {'blog_id': 1, 'country': 'US', 'counthits': 2, 'dt': 300},
    {'blog_id': 1, 'country': 'DE', 'counthits': 1, 'dt': 250},
]
assert rank_recent_top(rows, ('resolution',), ('blog_id',), 1, 90, 300) == [
    {'blog_id': 1, 'resolution': '800x600', 'counthits': 2, 'dt': 300},
]
assert rank_top([{'blog_id': 1, 'language': 'Z'}, {'blog_id': 1, 'language': 'a'}],
                'language', limit=1, equality='ascii_ci') == [
    {'blog_id': 1, 'language': 'a', 'counthits': 1},
]

with tempfile.TemporaryDirectory() as temp:
    path = Path(temp) / 'export.sqlite'
    db = sqlite3.connect(path)
    db.execute('CREATE TABLE _manifest (tbl TEXT, ord INTEGER, name TEXT, type TEXT, nullable INTEGER, wide INTEGER)')
    db.executemany('INSERT INTO _manifest VALUES (?, ?, ?, ?, ?, ?)', [
        ('slim_stats', index, name, 'TEXT', 1, 0)
        for index, name in enumerate(('browser', 'browser_version', 'country', 'dt'))
    ])
    db.execute('CREATE TABLE slim_stats (browser TEXT, browser_version TEXT, country TEXT, dt INTEGER)')
    db.executemany('INSERT INTO slim_stats VALUES (?, ?, ?, ?)', [
        ('Chrome', '120', 'US', 100), ('Chrome', '120', 'US', 300), ('Firefox', '121', 'DE', 250),
    ])
    db.commit()
    db.close()
    contracts = {'reports': {
        'top_user_agent_pinned': {'family': 'top', 'dimensions': ['browser', 'browser_version'],
            'count_field': 'counthits', 'default_limit': 200, 'windowed': True,
            'equality': 'ascii_ci', 'canonical': True},
        'recent_country_pinned': {'family': 'top', 'kind': 'recent_top',
            'count_field': 'counthits', 'default_limit': 200,
            'equality': 'ascii_ci', 'canonical': True},
    }}
    window = {'start': 90, 'end': 300}
    result = oracle_for(path, 'top_user_agent_pinned',
                        {'family': 'top', 'table': 'slim_stats', 'dimension': 'browser', 'blog_id': 1},
                        contracts, window)
    assert result['value'][0] == {'browser': 'Chrome', 'browser_version': '120', 'counthits': 2}, result
    result = oracle_for(path, 'recent_country_pinned',
                        {'family': 'top', 'table': 'slim_stats', 'dimension': 'country', 'blog_id': 1},
                        contracts, window)
    assert result['value'] == [
        {'country': 'DE', 'counthits': 1, 'dt': 250},
        {'country': 'US', 'counthits': 2, 'dt': 300},
    ], result

print('PASS: p2 top models preserve composite grain, predicates, latest ordering and limit')
