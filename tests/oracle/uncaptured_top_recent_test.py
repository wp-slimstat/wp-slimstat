#!/usr/bin/env python3
"""Required-red hand cases for the next report-order top/recent models."""

from families.recent import filtered_recent
from families.top import rank_current, rank_top


rows = [
    {'id': 1, 'blog_id': 1, 'ip': 'a', 'username': None, 'searchterms': None,
     'language': 'en-US', 'referer': '/r1', 'resource': '/a', 'dt_out': 0, 'dt': 100},
    {'id': 2, 'blog_id': 1, 'ip': 'a', 'username': 'alice', 'searchterms': 'term',
     'language': 'en-GB', 'referer': '/r2', 'resource': '/b', 'dt_out': 0, 'dt': 290},
    {'id': 3, 'blog_id': 1, 'ip': None, 'username': '', 'searchterms': '_',
     'language': None, 'referer': '/r3', 'resource': '/c', 'dt_out': 295, 'dt': 200},
]

assert rank_top(rows, 'searchterms', limit=200,
                where=[('searchterms', 'not_in', (None, '', '_'))], start=90, end=300,
                equality='ascii_ci') == [
    {'blog_id': 1, 'searchterms': 'term', 'counthits': 1}]
assert rank_top(rows, 'language', limit=200, transform='language_prefix', start=90, end=300) == [
    {'blog_id': 1, 'language': 'en', 'counthits': 2},
    {'blog_id': 1, 'language': None, 'counthits': 1},
]
assert rank_current(rows, 'ip', ('blog_id',), 200, 500, ('dt_out', 'dt'), True) == [
    {'blog_id': 1, 'ip': 'a', 'counthits': 1, 'dt': 290},
    {'blog_id': 1, 'ip': None, 'counthits': 1, 'dt': 200},
]
assert rank_current(rows, 'username', ('blog_id',), 200, 500, ('dt_out', 'dt'), False, True) == [
    {'blog_id': 1, 'username': 'alice', 'counthits': 1}]
assert filtered_recent(rows, ['searchterms', 'referer', 'resource', 'dt', 'ip'],
                       [('searchterms', 'not_in', (None, '', '_'))], 90, 300, 200) == [
    {'dt': '290', 'ip': 'a', 'referer': '/r2', 'resource': '/b', 'searchterms': 'term'}]

assert rank_top([{'blog_id': 1, 'username': 'Alice'}, {'blog_id': 1, 'username': 'alice '}],
                'username', equality='ascii_ci') == [
    {'blog_id': 1, 'username': 'Alice', 'counthits': 2}]
try:
    rank_top([{'blog_id': 1, 'username': 'café'}], 'username', equality='ascii_ci')
    raise AssertionError('non-ASCII top collation was guessed')
except ValueError:
    pass

print('PASS: pinned top/recent models preserve strict live bounds, NULL filters, transforms and columns')
