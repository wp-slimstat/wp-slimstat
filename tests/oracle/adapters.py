#!/usr/bin/env python3
"""Thin transport adapters from raw SQLite exports to independent oracle families."""
import sqlite3

from families.top import rank_top
from families.recent import recent_rows
from families.chart import pageviews_chart
from families.count import count_values


def _text(value):
    return value.decode('utf-8') if isinstance(value, bytes) else value


def recent(export_path, surface, adapter, contract):
    conn = sqlite3.connect('file:%s?mode=ro' % export_path, uri=True)
    conn.text_factory = bytes
    table = adapter['table']
    columns = [_text(row[0]) for row in conn.execute(
        'SELECT name FROM _manifest WHERE tbl = ? ORDER BY ord', (table,))]
    expected = contract['columns']
    if not isinstance(expected, list) or not expected or len(expected) != len(set(expected)):
        raise ValueError('%s: invalid recent-column contract' % surface)
    missing = [column for column in expected if column not in columns]
    if missing:
        raise ValueError('%s: export %s manifest lacks %s' % (surface, table, ', '.join(missing)))
    quoted = ', '.join('"%s"' % name.replace('"', '""') for name in expected)
    rows = [dict(zip(expected, row)) for row in conn.execute(
        'SELECT %s FROM "%s" ORDER BY dt DESC, id DESC LIMIT ?' %
        (quoted, table.replace('"', '""')), (contract['default_limit'],))]
    conn.close()
    value = recent_rows(rows)
    return {'class': 'ok' if value else 'empty', 'value': value,
            'flags': {'clock_dependent': False, 'calendar_day_dependent': False, 'pinned': False}}


def top(export_path, surface, adapter, contract):
    conn = sqlite3.connect('file:%s?mode=ro' % export_path, uri=True)
    conn.text_factory = bytes
    table, dimension = adapter['table'], adapter['dimension']
    columns = [_text(row[0]) for row in conn.execute(
        'SELECT name FROM _manifest WHERE tbl = ? ORDER BY ord', (table,))]
    if not columns:
        raise ValueError('%s: export has no %s manifest' % (surface, table))
    if dimension not in columns:
        raise ValueError('%s: export has no %s dimension' % (surface, dimension))
    # Raw exports deliberately carry invalid UTF-8 in unrelated fields. Decode
    # only the fields this family consumes, leaving the fidelity proof byte-exact.
    columns = list(dict.fromkeys([dimension] + (['blog_id'] if 'blog_id' in columns else [])))
    quoted = ', '.join('"%s"' % name.replace('"', '""') for name in columns)
    rows = [dict(zip(columns, map(_text, row))) for row in conn.execute(
        'SELECT %s FROM "%s"' % (quoted, table.replace('"', '""')))]
    conn.close()
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


def chart(export_path, surface, adapter, contract, windows):
    if not isinstance(windows, dict) or type(windows.get('end')) is not int:
        raise ValueError('%s: chart adapter requires the pinned capture end' % surface)
    if contract.get('timezone') != 'UTC':
        raise ValueError('%s: chart adapter supports only pinned UTC captures' % surface)
    conn = sqlite3.connect('file:%s?mode=ro' % export_path, uri=True)
    conn.text_factory = bytes
    table = adapter['table']
    columns = [_text(row[0]) for row in conn.execute(
        'SELECT name FROM _manifest WHERE tbl = ? ORDER BY ord', (table,))]
    missing = [column for column in ('dt', contract['metric_column']) if column not in columns]
    if missing:
        raise ValueError('%s: export %s manifest lacks %s' % (surface, table, ', '.join(missing)))
    rows = [{'dt': dt, 'ip': metric} for dt, metric in conn.execute(
        'SELECT "dt", "%s" FROM "%s"' %
        (contract['metric_column'].replace('"', '""'), table.replace('"', '""')))]
    conn.close()
    value = pageviews_chart(rows, windows['end'], contract['duration_days'],
                            contract['granularity'], contract['start_of_week'])
    return {'class': 'ok', 'value': value,
            'flags': {'clock_dependent': False, 'calendar_day_dependent': True, 'pinned': True}}


def count(export_path, surface, adapter, contract, windows):
    windowed = contract.get('windowed') is True
    if windowed and (not isinstance(windows, dict)
                     or type(windows.get('start')) is not int
                     or type(windows.get('end')) is not int):
        raise ValueError('%s: count adapter requires the pinned capture window' % surface)
    conn = sqlite3.connect('file:%s?mode=ro' % export_path, uri=True)
    conn.text_factory = bytes
    table, column = adapter['table'], contract['column']
    manifest = [_text(row[0]) for row in conn.execute(
        'SELECT name FROM _manifest WHERE tbl = ? ORDER BY ord', (table,))]
    consumed = [column] + (['dt'] if windowed else [])
    missing = [name for name in consumed if name not in manifest]
    if missing:
        raise ValueError('%s: export %s manifest lacks %s' % (surface, table, ', '.join(missing)))
    quoted = ', '.join('"%s"' % name.replace('"', '""') for name in consumed)
    rows = [dict(zip(consumed, row)) for row in conn.execute(
        'SELECT %s FROM "%s"' % (quoted, table.replace('"', '""')))]
    conn.close()
    value = count_values(rows, column, contract['distinct'],
                         windows['start'] if windowed else None,
                         windows['end'] if windowed else None,
                         contract.get('equality', 'binary'))
    return {'class': 'ok', 'value': value,
            'flags': {'clock_dependent': False, 'calendar_day_dependent': False,
                      'pinned': windowed}}


def oracle_for(export_path, surface, adapter, contracts, windows=None):
    if adapter is None:
        return {'class': 'unmodeled', 'value': None,
                'reason': 'No independent model for this surface'}
    if surface not in contracts['reports'] or adapter.get('family') not in ('top', 'recent', 'chart', 'count'):
        raise ValueError('%s: unknown or uncontracted adapter' % surface)
    family = {'top': top, 'recent': recent}.get(adapter['family'])
    if family:
        return family(export_path, surface, adapter, contracts['reports'][surface])
    family = chart if 'chart' == adapter['family'] else count
    return family(export_path, surface, adapter, contracts['reports'][surface], windows)


def comparison_contract(surface, adapter, contracts):
    if adapter is None:
        return None
    contract = contracts['reports'][surface]
    if adapter['family'] != 'top':
        return None
    return {'limit': contract['default_limit'], 'count_field': contract['count_field']}
