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
        ('slim_stats', 2, 'user_agent', 'VARCHAR(2048)', 1, 0),
        ('slim_stats', 3, 'dt', 'INT UNSIGNED', 1, 0),
        ('slim_stats', 4, 'email', 'VARCHAR(256)', 1, 0),
    ])
    db.execute('CREATE TABLE slim_stats (id INTEGER, resource BLOB, user_agent BLOB, dt INTEGER, email BLOB)')
    db.executemany('INSERT INTO slim_stats VALUES (?, ?, ?, ?, ?)',
                   [(1, b'/a', b'\xff', 10, b'ignored'),
                    (2, b'/b', b'normal', 20, b'\xff'),
                    (3, b'/a', None, 20, b'ignored')])
    db.commit()
    db.close()
    contracts = {'reports': {'top_resource': {'family': 'top', 'dimension': 'resource',
        'grain': ['blog_id'], 'count_field': 'counthits', 'default_limit': 1}}}
    result = oracle_for(path, 'top_resource',
        {'family': 'top', 'table': 'slim_stats', 'dimension': 'resource', 'blog_id': 1}, contracts)
    assert result['value'] == [{'resource': '/a', 'counthits': 2}], result
    assert oracle_for(path, 'missing', None, contracts)['class'] == 'unmodeled'

    contracts['reports']['get_recent'] = {'family': 'recent', 'default_limit': 2,
        'columns': ['id', 'resource', 'user_agent', 'dt']}
    result = oracle_for(path, 'get_recent',
        {'family': 'recent', 'table': 'slim_stats'}, contracts)
    assert result['value'] == [
        {'dt': '20', 'id': '3', 'resource': '/a', 'user_agent': None},
        {'dt': '20', 'id': '2', 'resource': '/b', 'user_agent': 'normal'},
    ], result
    bad = {'reports': dict(contracts['reports'])}
    bad['reports']['get_recent'] = dict(contracts['reports']['get_recent'],
                                        columns=['id', 'missing', 'dt'])
    try:
        oracle_for(path, 'get_recent', {'family': 'recent', 'table': 'slim_stats'}, bad)
        raise AssertionError('missing consumed recent column passed')
    except ValueError:
        pass

print('PASS: report evidence adapters')
