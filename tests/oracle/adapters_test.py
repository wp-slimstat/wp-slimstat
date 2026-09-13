#!/usr/bin/env python3
import sqlite3
import tempfile
from pathlib import Path

from adapters import oracle_for

with tempfile.TemporaryDirectory() as temp:
    path = Path(temp) / 'export.sqlite'
    db = sqlite3.connect(path)
    db.execute('CREATE TABLE _manifest (tbl TEXT, ord INTEGER, name TEXT, type TEXT, nullable INTEGER, wide INTEGER)')
    db.executemany('INSERT INTO _manifest VALUES (?, ?, ?, ?, ?, ?)', [
        ('slim_stats', 0, 'id', 'INT UNSIGNED', 0, 0),
        ('slim_stats', 1, 'resource', 'VARCHAR(2048)', 1, 0),
    ])
    db.execute('CREATE TABLE slim_stats (id INTEGER, resource BLOB)')
    db.executemany('INSERT INTO slim_stats VALUES (?, ?)', [(1, b'/a'), (2, b'/b'), (3, b'/a')])
    db.commit()
    db.close()
    contracts = {'reports': {'top_resource': {'family': 'top', 'dimension': 'resource',
        'grain': ['blog_id'], 'count_field': 'counthits', 'default_limit': 1}}}
    result = oracle_for(path, 'top_resource',
        {'family': 'top', 'table': 'slim_stats', 'dimension': 'resource', 'blog_id': 1}, contracts)
    assert result['value'] == [{'resource': '/a', 'counthits': 2}], result
    assert oracle_for(path, 'missing', None, contracts)['class'] == 'unmodeled'

print('PASS: report evidence adapters')
