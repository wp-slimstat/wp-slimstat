#!/usr/bin/env python3
"""Required-red cases for the next pinned p2 report batch."""

from families.top import rank_recent_top, rank_top


rows = [
    {'blog_id': 1, 'browser': 'Chrome', 'browser_version': '120', 'language': 'en-US',
     'username': 'Alice', 'notes': 'USER:7', 'resource': '/news/', 'content_type': 'category', 'dt': 100},
    {'blog_id': 1, 'browser': 'Chrome', 'browser_version': '120', 'language': 'en-US',
     'username': 'alice ', 'notes': 'user:7', 'resource': '/news', 'content_type': 'category', 'dt': 300},
    {'blog_id': 1, 'browser': 'Firefox', 'browser_version': '121', 'language': 'de-DE',
     'username': 'Bob', 'notes': None, 'resource': '/missing', 'content_type': 'page404', 'dt': 250},
]

assert rank_top(rows, 'username', limit=200,
                where=[('notes', 'contains_ascii_ci', 'user:')], start=90, end=300,
                equality='ascii_ci') == [
    {'blog_id': 1, 'username': 'Alice', 'counthits': 2},
]
assert rank_recent_top(rows, ('browser', 'browser_version'), ('blog_id',), 200, 90, 300,
                       equality='ascii_ci') == [
    {'blog_id': 1, 'browser': 'Chrome', 'browser_version': '120', 'counthits': 2, 'dt': 300},
    {'blog_id': 1, 'browser': 'Firefox', 'browser_version': '121', 'counthits': 1, 'dt': 250},
]
assert rank_recent_top(rows, ('language',), ('blog_id',), 1, 90, 300,
                       equality='ascii_ci') == [
    {'blog_id': 1, 'language': 'en-US', 'counthits': 2, 'dt': 300},
]

try:
    rank_top([{'blog_id': 1, 'username': 'a', 'notes': 'usér:7'}], 'username',
             where=[('notes', 'contains_ascii_ci', 'user:')])
    raise AssertionError('non-ASCII LIKE collation was guessed')
except ValueError:
    pass

print('PASS: pinned p2 models preserve LIKE, composite grouping and latest ordering')
