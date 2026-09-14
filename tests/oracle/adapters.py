#!/usr/bin/env python3
"""Thin transport adapters from raw SQLite exports to independent oracle families."""
import json
import sqlite3

from families.top import rank_top, top_events, top_outbound
from families.recent import recent_rows, recent_events
from families.chart import pageviews_chart
from families.count import count_values, count_singletons
from families.summary import visit_duration


def _text(value):
    return value.decode('utf-8') if isinstance(value, bytes) else value


def _read_rows(conn, surface, table, expected):
    columns = [_text(row[0]) for row in conn.execute(
        'SELECT name FROM _manifest WHERE tbl = ? ORDER BY ord', (table,))]
    missing = [column for column in expected if column not in columns]
    if missing:
        raise ValueError('%s: export %s manifest lacks %s' % (surface, table, ', '.join(missing)))
    quoted = ', '.join('"%s"' % name.replace('"', '""') for name in expected)
    return [dict(zip(expected, map(_text, row))) for row in conn.execute(
        'SELECT %s FROM "%s"' % (quoted, table.replace('"', '""')))]


def _canonical(rows, string_counts=False):
    def key(row):
        value = dict(row, counthits=str(row['counthits'])) if string_counts else row
        return json.dumps(value, ensure_ascii=True, separators=(',', ':'), sort_keys=True).replace('/', '\\/')
    return sorted(rows, key=key)


def recent(export_path, surface, adapter, contract):
    conn = sqlite3.connect('file:%s?mode=ro' % export_path, uri=True)
    conn.text_factory = bytes
    if contract.get('kind') == 'recent_events':
        stats = _read_rows(conn, surface, 'slim_stats', ['id', 'ip', 'resource', 'dt'])
        events = _read_rows(conn, surface, 'slim_events', contract['columns'][:-2])
        conn.close()
        value = _canonical(recent_events(stats, events, contract['window_start'], contract['window_end']))
        return {'class': 'ok' if value else 'empty', 'value': value,
                'flags': {'clock_dependent': False, 'calendar_day_dependent': False, 'pinned': True}}
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
    ranked = rank_top(rows, dimension, ('blog_id',), contract['default_limit'],
                      contract.get('transform'), contract.get('exclude_null', False))
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
    if contract.get('kind') == 'singletons':
        if not isinstance(windows, dict) or type(windows.get('start')) is not int or type(windows.get('end')) is not int:
            raise ValueError('%s: singleton adapter requires the pinned capture window' % surface)
        consumed = list(dict.fromkeys([contract['group_column'], contract['counted_column'], 'dt']
                                      + [row[0] for row in contract['where']]))
        rows = _read_rows(conn, surface, adapter['table'], consumed)
        conn.close()
        value = count_singletons(rows, contract['group_column'], contract['counted_column'],
                                 contract['where'], windows['start'], windows['end'], contract['equality'])
        return {'class': 'ok', 'value': value,
                'flags': {'clock_dependent': False, 'calendar_day_dependent': False, 'pinned': True}}
    table, column = adapter['table'], contract['column']
    manifest = [_text(row[0]) for row in conn.execute(
        'SELECT name FROM _manifest WHERE tbl = ? ORDER BY ord', (table,))]
    where_not_equal = contract.get('where_not_equal')
    consumed = [column] + (['dt'] if windowed else []) + ([where_not_equal['column']] if where_not_equal else [])
    consumed = list(dict.fromkeys(consumed))
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
                         contract.get('equality', 'binary'),
                         ((where_not_equal['column'], where_not_equal['value']) if where_not_equal else None))
    return {'class': 'ok', 'value': value,
            'flags': {'clock_dependent': False, 'calendar_day_dependent': False,
                      'pinned': windowed}}


def summary(export_path, surface, adapter, contract, windows):
    if (contract.get('kind') != 'visit_duration' or not isinstance(windows, dict)
            or type(windows.get('start')) is not int or type(windows.get('end')) is not int):
        raise ValueError('%s: unsupported or unpinned summary contract' % surface)
    conn = sqlite3.connect('file:%s?mode=ro' % export_path, uri=True)
    table = adapter['table']
    columns = ('visit_id', 'browser_type', 'dt', 'dt_out')
    manifest = {_text(row[0]) for row in conn.execute(
        'SELECT name FROM _manifest WHERE tbl = ?', (table,))}
    missing = [column for column in columns if column not in manifest]
    if missing:
        raise ValueError('%s: export %s manifest lacks %s' % (surface, table, ', '.join(missing)))
    rows = [dict(zip(columns, row)) for row in conn.execute(
        'SELECT "visit_id", "browser_type", "dt", "dt_out" FROM "%s"' %
        table.replace('"', '""'))]
    conn.close()
    value = visit_duration(rows, windows['start'], windows['end'])
    return {'class': 'ok', 'value': value,
            'flags': {'clock_dependent': False, 'calendar_day_dependent': False, 'pinned': True}}


def oracle_for(export_path, surface, adapter, contracts, windows=None):
    if adapter is None:
        return {'class': 'unmodeled', 'value': None,
                'reason': 'No independent model for this surface'}
    if surface not in contracts['reports'] or adapter.get('family') not in ('top', 'recent', 'chart', 'count', 'summary'):
        raise ValueError('%s: unknown or uncontracted adapter' % surface)
    contract = contracts['reports'][surface]
    if contract.get('kind') in ('top_events', 'top_outbound'):
        if not isinstance(windows, dict) or type(windows.get('start')) is not int or type(windows.get('end')) is not int:
            raise ValueError('%s: specialized top adapter requires the pinned capture window' % surface)
        conn = sqlite3.connect('file:%s?mode=ro' % export_path, uri=True)
        conn.text_factory = bytes
        if contract['kind'] == 'top_events':
            rows = _read_rows(conn, surface, 'slim_events', ['notes', 'dt'])
            value = _canonical(top_events(rows, windows['start'], windows['end'],
                                          contract['default_limit']), string_counts=True)
        else:
            rows = _read_rows(conn, surface, 'slim_stats', ['outbound_resource', 'dt', 'dt_out'])
            value = _canonical(top_outbound(rows, windows['start'], windows['end'], contract['default_limit']))
        conn.close()
        return {'class': 'ok' if value else 'empty', 'value': value,
                'flags': {'clock_dependent': False, 'calendar_day_dependent': False, 'pinned': True}}
    if contract.get('kind') == 'recent_events':
        if not isinstance(windows, dict) or type(windows.get('start')) is not int or type(windows.get('end')) is not int:
            raise ValueError('%s: recent-events adapter requires the pinned capture window' % surface)
        contract = dict(contract, window_start=windows['start'], window_end=windows['end'])
    family = {'top': top, 'recent': recent}.get(adapter['family'])
    if family:
        return family(export_path, surface, adapter, contract)
    family = {'chart': chart, 'count': count, 'summary': summary}[adapter['family']]
    return family(export_path, surface, adapter, contracts['reports'][surface], windows)


def comparison_contract(surface, adapter, contracts):
    if adapter is None:
        return None
    contract = contracts['reports'][surface]
    if adapter['family'] != 'top':
        return None
    return {'limit': contract['default_limit'], 'count_field': contract['count_field']}
