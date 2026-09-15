#!/usr/bin/env python3
"""Hand-derived checks for independent scalar count semantics."""

from families.count import count_values


rows = [
    {'id': 1, 'ip': b'a', 'resource': b'/a', 'dt': 10},
    {'id': 2, 'ip': b'a', 'resource': b'/b', 'dt': 20},
    {'id': 3, 'ip': None, 'resource': b'/a', 'dt': 30},
    {'id': 4, 'ip': b'b', 'resource': None, 'dt': 40},
]

assert count_values(rows, 'id') == 4
assert count_values(rows, 'ip', distinct=True) == 2
assert count_values(rows, 'resource', distinct=True) == 2
assert count_values(rows, 'id', start=20, end=30) == 2
assert count_values([{'v': b'/Path'}, {'v': b'/path'}, {'v': b'/path '}],
                    'v', distinct=True, equality='ascii_ci') == 1

try:
    count_values([{'v': '/café'}], 'v', distinct=True, equality='ascii_ci')
    raise AssertionError('non-ASCII value guessed at a MySQL collation')
except ValueError:
    pass

try:
    count_values(rows, 'id', start=30, end=20)
    raise AssertionError('reversed count window passed')
except ValueError:
    pass

print('PASS: scalar counts preserve COUNT null, DISTINCT and inclusive-window semantics')
