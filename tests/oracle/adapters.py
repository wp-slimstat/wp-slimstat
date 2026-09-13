#!/usr/bin/env python3
"""Thin transport adapters from raw SQLite exports to independent oracle families."""
import json
from pathlib import Path
import sqlite3

from families.top import rank_top


def _text(value):
    return value.decode('utf-8') if isinstance(value, bytes) else value


def top(export_path, surface, adapter, contract):
    conn = sqlite3.connect('file:%s?mode=ro' % export_path, uri=True)
    conn.text_factory = bytes
    table, dimension = adapter['table'], adapter['dimension']
    columns = [_text(row[0]) for row in conn.execute(
        'SELECT name FROM _manifest WHERE tbl = ? ORDER BY ord', (table,))]
    if not columns:
        raise ValueError('%s: export has no %s manifest' % (surface, table))
    quoted = ', '.join('"%s"' % name.replace('"', '""') for name in columns)
    rows = [dict(zip(columns, map(_text, row))) for row in conn.execute(
        'SELECT %s FROM "%s"' % (quoted, table.replace('"', '""')))]
    blog_id = adapter.get('blog_id')
    if blog_id is not None:
        if 'blog_id' in columns:
            rows = [row for row in rows if int(row['blog_id']) == blog_id]
        else:
            rows = [dict(row, blog_id=blog_id) for row in rows]
    ranked = rank_top(rows, dimension, ('blog_id',), contract['default_limit'])
    value = [{dimension: row[dimension], contract['count_field']: row['counthits']}
             for row in ranked]
    return {'class': 'ok' if value else 'empty', 'value': value,
            'flags': {'clock_dependent': False, 'calendar_day_dependent': False, 'pinned': True}}


def oracle_for(export_path, surface, adapter, contracts):
    if adapter is None:
        return {'class': 'unmodeled', 'value': None,
                'reason': 'No independent model for this surface'}
    if adapter.get('family') != 'top' or surface not in contracts['reports']:
        raise ValueError('%s: unknown or uncontracted adapter' % surface)
    return top(export_path, surface, adapter, contracts['reports'][surface])


def comparison_contract(surface, adapter, contracts):
    if adapter is None:
        return None
    contract = contracts['reports'][surface]
    return {'limit': contract['default_limit'], 'count_field': contract['count_field']}
