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
        ('slim_stats', 5, 'ip', 'VARCHAR(39)', 1, 0),
    ])
    db.execute('CREATE TABLE slim_stats (id INTEGER, resource BLOB, user_agent BLOB, dt INTEGER, email BLOB, ip BLOB)')
    db.executemany('INSERT INTO slim_stats VALUES (?, ?, ?, ?, ?, ?)',
                   [(1, b'/a', b'\xff', 10, b'ignored', b'a'),
                    (2, b'/b', b'normal', 20, b'\xff', b'b'),
                    (3, b'/a', None, 20, b'ignored', None)])
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

    contracts['reports']['chart_daily'] = {'family': 'chart', 'metric_column': 'ip',
        'duration_days': 1, 'granularity': 'DAY', 'start_of_week': 1, 'timezone': 'UTC'}
    result = oracle_for(path, 'chart_daily', {'family': 'chart', 'table': 'slim_stats'},
                        contracts, {'end': 86400})
    assert result['value']['datasets']['v1'] == [2], result
    try:
        oracle_for(path, 'chart_daily', {'family': 'chart', 'table': 'slim_stats'}, contracts)
        raise AssertionError('chart adapter passed without a pinned capture end')
    except ValueError:
        pass
    bad = {'reports': dict(contracts['reports'])}
    bad['reports']['chart_daily'] = dict(contracts['reports']['chart_daily'], metric_column='missing')
    try:
        oracle_for(path, 'chart_daily', {'family': 'chart', 'table': 'slim_stats'},
                   bad, {'end': 86400})
        raise AssertionError('chart adapter passed without its consumed metric column')
    except ValueError:
        pass

    for key, column, distinct, windowed, expected in (
            ('count_records_id', 'id', False, False, 3),
            ('count_records_ip', 'ip', True, False, 2),
            ('rows_in_window', 'id', False, True, 2)):
        contracts['reports'][key] = {'family': 'count', 'column': column,
                                     'distinct': distinct, 'windowed': windowed,
                                     'equality': 'ascii_ci' if distinct else 'binary'}
        result = oracle_for(path, key, {'family': 'count', 'table': 'slim_stats'},
                            contracts, {'start': 15, 'end': 20})
        assert result['value'] == expected, result
        assert result['flags']['pinned'] is windowed, result

    try:
        oracle_for(path, 'rows_in_window', {'family': 'count', 'table': 'slim_stats'}, contracts)
        raise AssertionError('windowed count adapter passed without pinned bounds')
    except ValueError:
        pass

print('PASS: report evidence adapters')
