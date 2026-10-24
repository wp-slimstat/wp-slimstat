#!/usr/bin/env python3
"""Thin transport adapters from raw SQLite exports to independent oracle families."""
import json
import sqlite3

from families.top import rank_current, rank_recent_top, rank_top, top_events, top_outbound
from families.recent import filtered_recent, recent_rows, recent_events
from families.chart import pageviews_chart
from families.group_by import grouped_concat
from families.count import count_values, count_singletons
from families.summary import (bouncing_pages, bouncing_visits, pages_per_visit,
                              traffic_sources_summary, visit_duration, visitors_summary)
from families.pages import (filtered_recent, grouped_dimensions, grouped_values, recent_downloads,
                            recent_outbound, visit_boundary_pages)
from families.goals import funnel_results, goal_results, percent_text


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


def _canonical(rows, string_counts=False, string_fields=()):
    def key(row):
        fields = tuple(string_fields) + (('counthits',) if string_counts else ())
        value = dict(row, **{field: str(row[field]) for field in fields}) if fields else row
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
    if contract.get('kind') == 'filtered_recent':
        rows = _read_rows(conn, surface, adapter['table'], contract['columns'])
        conn.close()
        value = _canonical(filtered_recent(rows, contract['columns'], contract['where'],
                                           contract['window_start'], contract['window_end'],
                                           contract['default_limit']))
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
    dimensions = contract.get('dimensions', [dimension])
    columns = [_text(row[0]) for row in conn.execute(
        'SELECT name FROM _manifest WHERE tbl = ? ORDER BY ord', (table,))]
    if not columns:
        raise ValueError('%s: export has no %s manifest' % (surface, table))
    missing = [name for name in dimensions if name not in columns]
    if missing:
        raise ValueError('%s: export has no %s dimension' % (surface, ', '.join(missing)))
    # Raw exports deliberately carry invalid UTF-8 in unrelated fields. Decode
    # only the fields this family consumes, leaving the fidelity proof byte-exact.
    consumed = list(dimensions)
    if contract.get('windowed') or contract.get('kind') in ('current', 'recent_top'):
        consumed.append('dt')
    if contract.get('kind') == 'current':
        consumed.append('dt_out')
    consumed += [item[0] for item in contract.get('where', [])]
    columns = list(dict.fromkeys(consumed + (['blog_id'] if 'blog_id' in columns else [])))
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
    if contract.get('kind') == 'current':
        ranked = rank_current(rows, dimension, ('blog_id',), contract['default_limit'],
                              contract['window_end'], ('dt_out', 'dt'),
                              contract.get('include_max_dt', False), contract.get('exclude_empty', False),
                              contract.get('equality', 'binary'))
    elif contract.get('kind') == 'recent_top':
        ranked = rank_recent_top(rows, dimensions, ('blog_id',), contract['default_limit'],
                                 contract['window_start'], contract['window_end'],
                                 contract.get('equality', 'binary'), contract.get('where', ()))
    else:
        ranked = rank_top(rows, dimensions, ('blog_id',), contract['default_limit'],
                          contract.get('transform'), contract.get('exclude_null', False),
                          contract.get('where', ()), contract.get('window_start'), contract.get('window_end'),
                          contract.get('equality', 'binary'))
    fields = list(dimensions) + [contract['count_field']] + (
        ['dt'] if contract.get('include_max_dt') or contract.get('kind') == 'recent_top' else [])
    value = [{field: row[field] for field in fields} for row in ranked]
    if contract.get('canonical'):
        string_fields = list(dimensions) if contract.get('string_dimensions') else []
        string_fields += ['counthits'] + (
            ['dt'] if contract.get('include_max_dt') or contract.get('kind') == 'recent_top' else [])
        value = _canonical(value, string_fields=string_fields)
    return {'class': 'ok' if value else 'empty', 'value': value,
            'flags': {'clock_dependent': False, 'calendar_day_dependent': False, 'pinned': True}}


def _row_filter(surface, spec, windows):
    """Build a chart's WHERE from the contract plus the arm's own captured URLs.

    Only slim_p3_01 carries one. `excludes_self` names the URL to exclude rather than the URL
    itself, because the container's port is chosen at run time — a literal in the contract would
    model a different site than the one that was measured.
    """
    if not spec:
        return None
    present = tuple(spec.get('present', ()))
    exclude = spec.get('excludes_self')
    needle = None
    if exclude:
        value = (windows.get('self_urls') or {}).get(exclude['url'])
        if not isinstance(value, str) or not value:
            raise ValueError('%s: chart filter needs the captured %s' % (surface, exclude['url']))
        needle = value.encode('ascii').lower()

    def keep(row):
        if any(row[column] is None for column in present):
            return False
        if exclude is None:
            return True
        value = row[exclude['column']]
        if value is None:
            return False
        raw = value if isinstance(value, bytes) else value.encode('ascii')
        return needle not in raw.lower()
    return keep


def group_by(export_path, surface, adapter, contract, windows):
    if (not isinstance(windows, dict) or type(windows.get('start')) is not int
            or type(windows.get('end')) is not int):
        raise ValueError('%s: group-by adapter requires the pinned capture window' % surface)
    conn = sqlite3.connect('file:%s?mode=ro' % export_path, uri=True)
    conn.text_factory = bytes
    consumed = list(dict.fromkeys([contract['group_by'], contract['column_group'], 'dt']))
    rows = _read_rows(conn, surface, adapter['table'], consumed)
    conn.close()
    value = grouped_concat(rows, windows['start'], windows['end'], contract['group_by'],
                           contract['column_group'], contract['default_limit'],
                           contract.get('equality', 'ascii_ci'))
    value = [dict(row, counthits=str(row['counthits'])) for row in value]
    return {'class': 'ok' if value else 'empty', 'value': _canonical(value),
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
    metric_column = contract['metric_column']
    second_column = contract.get('metric2_column')
    spec = contract.get('row_filter') or {}
    consumed = list(dict.fromkeys(
        ['dt', metric_column] + ([second_column] if second_column else [])
        + list(spec.get('present', ())) + ([spec['excludes_self']['column']] if spec.get('excludes_self') else [])))
    missing = [column for column in consumed if column not in columns]
    if missing:
        raise ValueError('%s: export %s manifest lacks %s' % (surface, table, ', '.join(missing)))
    quoted = ', '.join('"%s"' % name.replace('"', '""') for name in consumed)
    rows = [dict(zip(consumed, row)) for row in conn.execute(
        'SELECT %s FROM "%s"' % (quoted, table.replace('"', '""')))]
    conn.close()
    value = pageviews_chart(rows, windows['end'], contract['duration_days'],
                            contract['granularity'], contract['start_of_week'], metric_column,
                            tuple(contract.get('excluded', ())), contract.get('equality', 'binary'),
                            contract.get('distinct_v1', False), second_column,
                            _row_filter(surface, spec, windows))
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
    kinds = {
        'bouncing_visits': (('id', 'visit_id', 'browser_type', 'dt'), bouncing_visits),
        'bouncing_pages': (('visit_id', 'content_type', 'resource', 'dt'), bouncing_pages),
        'pages_per_visit': (('visit_id', 'dt'), pages_per_visit),
        'visit_duration': (('visit_id', 'browser_type', 'dt', 'dt_out'), visit_duration),
        'visitors_summary': (('id', 'visit_id', 'browser_type', 'ip', 'username', 'dt'), visitors_summary),
        'traffic_sources_summary': (('id', 'ip', 'visit_id', 'browser_type', 'referer', 'resource',
                                     'searchterms', 'content_type', 'dt'), traffic_sources_summary),
    }
    if (contract.get('kind') not in kinds or not isinstance(windows, dict)
            or type(windows.get('start')) is not int or type(windows.get('end')) is not int):
        raise ValueError('%s: unsupported or unpinned summary contract' % surface)
    conn = sqlite3.connect('file:%s?mode=ro' % export_path, uri=True)
    table = adapter['table']
    columns, model = kinds[contract['kind']]
    manifest = {_text(row[0]) for row in conn.execute(
        'SELECT name FROM _manifest WHERE tbl = ?', (table,))}
    missing = [column for column in columns if column not in manifest]
    if missing:
        raise ValueError('%s: export %s manifest lacks %s' % (surface, table, ', '.join(missing)))
    quoted = ', '.join('"%s"' % column for column in columns)
    rows = [dict(zip(columns, row)) for row in conn.execute(
        'SELECT %s FROM "%s"' % (quoted, table.replace('"', '""')))]
    conn.close()
    if contract['kind'] == 'traffic_sources_summary':
        urls = windows.get('self_urls') or {}
        value = _canonical(model(rows, windows['start'], windows['end'],
                                 urls.get('home_url'), urls.get('host')))
    else:
        value = model(rows, windows['start'], windows['end'])
    return {'class': 'ok', 'value': value,
            'flags': {'clock_dependent': False, 'calendar_day_dependent': False, 'pinned': True}}


def pages(export_path, surface, adapter, contract, windows):
    if (contract.get('kind') not in ('recent_downloads', 'entry_pages', 'exit_pages',
                                     'top_dimension', 'recent_dimension', 'top_dimensions',
                                     'recent_rows', 'recent_outbound')
            or not isinstance(windows, dict) or type(windows.get('start')) is not int
            or type(windows.get('end')) is not int):
        raise ValueError('%s: unsupported or unpinned page contract' % surface)
    grouped = contract['kind'] in ('top_dimension', 'recent_dimension')
    multi = contract['kind'] == 'top_dimensions'
    recent_filtered = contract['kind'] == 'recent_rows'
    outbound = contract['kind'] == 'recent_outbound'
    dimension = contract.get('dimension')
    content_filter = contract.get('content_type')
    if grouped and (not isinstance(dimension, str) or not dimension
                    or (content_filter is not None and (
                        not isinstance(content_filter, dict)
                        or content_filter.get('operator') not in ('exact', 'contains')
                        or not isinstance(content_filter.get('value'), str)))):
        raise ValueError('%s: invalid grouped page contract' % surface)
    if (multi and (not isinstance(contract.get('dimensions'), list)
                   or not contract['dimensions'] or not isinstance(contract.get('filter_column'), str)
                   or type(contract.get('filter_value')) is not int)) \
            or (recent_filtered and (not isinstance(dimension, str) or not dimension
                                     or contract.get('mode') not in ('feeds', 'searches'))):
        raise ValueError('%s: invalid page contract' % surface)
    if outbound:
        columns = ('outbound_resource', 'dt', 'dt_out')
    elif multi:
        columns = tuple(contract['dimensions']) + (contract['filter_column'], 'dt')
    elif recent_filtered:
        columns = tuple(dict.fromkeys((dimension, 'resource', 'content_type', 'dt', 'ip')))
    else:
        columns = ((dimension, 'dt') + (('content_type',) if content_filter else ())) if grouped \
            else (('resource', 'content_type', 'dt') if contract['kind'] == 'recent_downloads'
                  else ('id', 'visit_id', 'resource', 'dt'))
    conn = sqlite3.connect('file:%s?mode=ro' % export_path, uri=True)
    conn.text_factory = bytes
    table = adapter['table']
    manifest = {_text(row[0]) for row in conn.execute(
        'SELECT name FROM _manifest WHERE tbl = ?', (table,))}
    missing = [column for column in columns if column not in manifest]
    if missing:
        raise ValueError('%s: export %s manifest lacks %s' % (surface, table, ', '.join(missing)))
    rows = [dict(zip(columns, row)) for row in conn.execute(
        'SELECT %s FROM "%s"' % (', '.join('"%s"' % column for column in columns),
                                  table.replace('"', '""')))]
    conn.close()
    if outbound:
        value = recent_outbound(rows, windows['start'], windows['end'], contract['default_limit'])
    elif contract['kind'] == 'recent_downloads':
        value = recent_downloads(rows, windows['start'], windows['end'], contract['default_limit'])
    elif grouped:
        value = grouped_values(
            rows, windows['start'], windows['end'], dimension, contract['default_limit'],
            content_type=content_filter['value'] if content_filter else None,
            contains=bool(content_filter and content_filter['operator'] == 'contains'),
            require_nonempty=contract.get('require_nonempty') is True,
            trim_slash=contract.get('trim_trailing_slash') is True,
            recent=contract['kind'] == 'recent_dimension',
            dimension_prefix=contract.get('dimension_prefix'),
            filter_join=contract.get('filter_join', 'and'))
    elif multi:
        value = grouped_dimensions(rows, windows['start'], windows['end'], contract['dimensions'],
                                   contract['default_limit'], contract['filter_column'],
                                   contract['filter_value'], contract.get('exclude_filter') is True)
    elif recent_filtered:
        value = filtered_recent(rows, windows['start'], windows['end'], dimension,
                                contract['default_limit'], contract['mode'])
    else:
        value = visit_boundary_pages(rows, windows['start'], windows['end'],
                                     'MIN' if contract['kind'] == 'entry_pages' else 'MAX',
                                     contract['default_limit'])
    return {'class': 'ok' if value else 'empty', 'value': value,
            'flags': {'clock_dependent': False, 'calendar_day_dependent': False, 'pinned': True}}


def goals(export_path, surface, adapter, contract, windows):
    if (contract.get('kind') not in ('goal_result', 'goals_raw', 'funnel_result', 'funnels_raw')
            or not isinstance(windows, dict) or type(windows.get('start')) is not int
            or type(windows.get('end')) is not int):
        raise ValueError('%s: unsupported or unpinned goal contract' % surface)
    columns = ('id', 'resource', 'browser', 'country', 'fingerprint', 'visit_id', 'ip', 'dt')
    conn = sqlite3.connect('file:%s?mode=ro' % export_path, uri=True)
    conn.text_factory = bytes
    table = adapter['table']
    manifest = {_text(row[0]) for row in conn.execute(
        'SELECT name FROM _manifest WHERE tbl = ?', (table,))}
    missing = [column for column in columns if column not in manifest]
    if missing:
        raise ValueError('%s: export %s manifest lacks %s' % (surface, table, ', '.join(missing)))
    rows = [dict(zip(columns, row)) for row in conn.execute(
        'SELECT %s FROM "%s"' % (', '.join('"%s"' % column for column in columns),
                                  table.replace('"', '""')))]
    conn.close()
    if contract['kind'] in ('goal_result', 'goals_raw'):
        value = goal_results(rows, windows['start'], windows['end'], contract['rule'])
        if contract['kind'] == 'goals_raw':
            value = [{'goal_name': contract['name'], 'uniques': value['uniques'],
                      'total': value['total'], 'cr': percent_text(value['cr'])}]
    else:
        value = funnel_results(rows, windows['start'], windows['end'], contract['steps'])
        if contract['kind'] == 'funnels_raw':
            value = [{'funnel_name': contract['name'], 'step_name': row['name'],
                      'step_order': index + 1, 'visitors': row['visitors'],
                      'pct': percent_text(row['pct']), 'dropoff': row['dropoff']}
                     for index, row in enumerate(value)]
    return {'class': 'ok' if value else 'empty', 'value': value,
            'flags': {'clock_dependent': False, 'calendar_day_dependent': False, 'pinned': True}}


def oracle_for(export_path, surface, adapter, contracts, windows=None):
    if adapter is None:
        return {'class': 'unmodeled', 'value': None,
                'reason': 'No independent model for this surface'}
    if surface not in contracts['reports'] or adapter.get('family') not in ('top', 'recent', 'chart', 'count', 'summary', 'pages', 'goals', 'group_by'):
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
    if contract.get('kind') in ('recent_events', 'filtered_recent'):
        if not isinstance(windows, dict) or type(windows.get('start')) is not int or type(windows.get('end')) is not int:
            raise ValueError('%s: recent adapter requires the pinned capture window' % surface)
        contract = dict(contract, window_start=windows['start'], window_end=windows['end'])
    if adapter['family'] == 'top' and (contract.get('windowed') or contract.get('kind') in ('current', 'recent_top')):
        if not isinstance(windows, dict) or type(windows.get('start')) is not int or type(windows.get('end')) is not int:
            raise ValueError('%s: top adapter requires the pinned capture window' % surface)
        contract = dict(contract, window_start=windows['start'], window_end=windows['end'])
    family = {'top': top, 'recent': recent}.get(adapter['family'])
    if family:
        return family(export_path, surface, adapter, contract)
    family = {'chart': chart, 'count': count, 'summary': summary, 'pages': pages,
              'goals': goals, 'group_by': group_by}[adapter['family']]
    return family(export_path, surface, adapter, contracts['reports'][surface], windows)


def comparison_contract(surface, adapter, contracts):
    if adapter is None:
        return None
    contract = contracts['reports'][surface]
    if adapter['family'] != 'top':
        return None
    return {'limit': contract['default_limit'], 'count_field': contract['count_field']}
