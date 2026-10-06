#!/usr/bin/env python3
"""Behavioral checks for captured top/count contracts added in this lane."""

import json
import sqlite3
import tempfile
from pathlib import Path

from adapters import oracle_for


root = Path(__file__).resolve().parent
contracts = json.loads((root / 'report-contracts.json').read_text())
adapters = {
    row['key']: row['adapter']
    for row in json.loads((root / 'expected_population.json').read_text())['surfaces']
}

with tempfile.TemporaryDirectory() as temp:
    path = Path(temp) / 'export.sqlite'
    db = sqlite3.connect(path)
    db.execute('CREATE TABLE _manifest (tbl TEXT, ord INTEGER, name TEXT, type TEXT, nullable INTEGER, wide INTEGER)')
    columns = ('id', 'visit_id', 'browser_type', 'referer', 'platform', 'resource')
    db.executemany('INSERT INTO _manifest VALUES (?, ?, ?, ?, ?, ?)', [
        ('slim_stats', i, name, 'TEXT', 1, 0) for i, name in enumerate(columns)
    ])
    db.execute('CREATE TABLE slim_stats (id INTEGER, visit_id INTEGER, browser_type INTEGER, referer BLOB, platform BLOB, resource BLOB)')
    db.executemany('INSERT INTO slim_stats VALUES (?, ?, ?, ?, ?, ?)', [
        (1, 10, 0, b'https://www.example.com/a', b'Windows', b'/post/'),
        (2, 10, 0, b'https://www.example.com/b', b'Windows', b'/post///'),
        (3, 11, 1, b'https://sub.example.com/a', b'Linux', b'/other/'),
        (4, None, None, None, None, None),
    ])
    db.commit()
    db.close()

    expected = {
        'top_referer': [
            {'referer': None, 'counthits': 1},
            {'referer': 'https://sub.example.com/a', 'counthits': 1},
            {'referer': 'https://www.example.com/a', 'counthits': 1},
            {'referer': 'https://www.example.com/b', 'counthits': 1},
        ],
        'top_referer_domains': [
            {'referer': 'example.com', 'counthits': 2},
            {'referer': 'sub.example.com', 'counthits': 1},
        ],
        'top_platform_prefixed': [
            {'platform': 'p-Win', 'counthits': 2},
            {'platform': None, 'counthits': 1},
            {'platform': 'p-Lin', 'counthits': 1},
        ],
        'top_resource_trimmed': [
            {'resource': '/post', 'counthits': 2},
            {'resource': None, 'counthits': 1},
            {'resource': '/other', 'counthits': 1},
        ],
        'count_human_hits': 2,
        'count_records_visit_id': 2,
    }
    for surface, value in expected.items():
        result = oracle_for(path, surface, adapters[surface], contracts)
        assert result['value'] == value, (surface, result)

print('PASS: captured top transforms and filtered/distinct counts preserve NULL and tie semantics')
