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
        ('slim_stats', 6, 'visit_id', 'INT UNSIGNED', 1, 0),
        ('slim_stats', 7, 'browser_type', 'TINYINT UNSIGNED', 1, 0),
        ('slim_stats', 8, 'dt_out', 'INT UNSIGNED', 1, 0),
        ('slim_stats', 9, 'username', 'VARCHAR(255)', 1, 0),
        ('slim_stats', 10, 'outbound_resource', 'VARCHAR(2048)', 1, 0),
        ('slim_stats', 11, 'content_type', 'VARCHAR(255)', 1, 0),
        ('slim_stats', 12, 'browser', 'VARCHAR(40)', 1, 0),
        ('slim_stats', 13, 'platform', 'VARCHAR(255)', 1, 0),
        ('slim_stats', 14, 'referer', 'VARCHAR(2048)', 1, 0),
        ('slim_stats', 15, 'searchterms', 'VARCHAR(2048)', 1, 0),
    ])
    db.execute('CREATE TABLE slim_stats (id INTEGER, resource BLOB, user_agent BLOB, dt INTEGER, '
               'email BLOB, ip BLOB, visit_id INTEGER, browser_type INTEGER, dt_out INTEGER, '
               'username BLOB, outbound_resource BLOB, content_type BLOB, browser BLOB, '
               'platform BLOB, referer BLOB, searchterms BLOB)')
    db.executemany('INSERT INTO slim_stats VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                   [(1, b'/a', b'\xff', 10, b'ignored', b'a', 1, 0, 40, b'Sam', b'https://a', b'page',
                     b'Chrome', b'Win', b'http://site.example/self', b'q'),
                    (2, b'/b', b'normal', 20, b'\xff', b'b', 1, 0, 70, b'sam ', b'https://a', b'download',
                     b'Chrome', b'Mac', b'http://news.example/a', None),
                    (3, b'/a', None, 20, b'ignored', None, 2, 1, 90, None, None, b'DOWNLOAD ',
                     None, b'Linux', b'http://other.example/x', None)])
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

    contracts['reports']['slim_p4_26_01_chart_daily'] = dict(
        contracts['reports']['chart_daily'], metric_column='outbound_resource')
    result = oracle_for(path, 'slim_p4_26_01_chart_daily',
                        {'family': 'chart', 'table': 'slim_stats'}, contracts, {'end': 86400})
    assert result['value']['datasets'] == {'v1': [2], 'v2': [1]}, result

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

    contracts['reports']['get_visits_duration'] = {
        'family': 'summary', 'kind': 'visit_duration'}
    result = oracle_for(path, 'get_visits_duration',
                        {'family': 'summary', 'table': 'slim_stats'}, contracts,
                        {'start': 10, 'end': 20})
    assert next(row for row in result['value'] if row['metric'] == '31 - 60 seconds') == {
        'counthits': 1, 'details': 'Hits: 1', 'metric': '31 - 60 seconds', 'value': '100.00%'}, result
    assert result['value'][-1]['value'] == '01:00', result

    for key, kind, expected in (
            ('bouncing_visits_pinned', 'bouncing_visits', 0),
            ('get_max_and_average_pages_per_visit', 'pages_per_visit',
             [{'avghits': '1.5000', 'maxhits': '2'}])):
        contracts['reports'][key] = {'family': 'summary', 'kind': kind}
        result = oracle_for(path, key, {'family': 'summary', 'table': 'slim_stats'},
                            contracts, {'start': 10, 'end': 20})
        assert result['value'] == expected, result

    contracts['reports']['get_visitors_summary'] = {
        'family': 'summary', 'kind': 'visitors_summary'}
    result = oracle_for(path, 'get_visitors_summary',
                        {'family': 'summary', 'table': 'slim_stats'}, contracts,
                        {'start': 10, 'end': 20})
    values = {row['metric']: row['value'] for row in result['value']}
    assert values['Visits'] == '1' and values['Unique IPs'] == '2', result
    assert values['Bounce rate'] == '0.00' and values['Known visitors'] == '1', result

    contracts['reports']['count_bouncing_pages'] = {
        'family': 'summary', 'kind': 'bouncing_pages'}
    result = oracle_for(path, 'count_bouncing_pages',
                        {'family': 'summary', 'table': 'slim_stats'}, contracts,
                        {'start': 10, 'end': 20})
    assert result['value'] == 1, result

    for key, kind, expected in (
            ('slim_p4_20_recent_downloads', 'recent_downloads', [
                {'counthits': '1', 'dt': '20', 'resource': '/a'},
                {'counthits': '1', 'dt': '20', 'resource': '/b'}]),
            ('slim_p4_24_exit_pages', 'exit_pages', [
                {'counthits': '1', 'resource': '/a'}, {'counthits': '1', 'resource': '/b'}]),
            ('slim_p4_25_entry_pages', 'entry_pages', [
                {'counthits': '2', 'resource': '/a'}])):
        contracts['reports'][key] = {'family': 'pages', 'kind': kind, 'default_limit': 200}
        result = oracle_for(path, key, {'family': 'pages', 'table': 'slim_stats'}, contracts,
                            {'start': 10, 'end': 20})
        assert result['value'] == expected, result

    contracts['reports']['slim_p4_09_top_downloads'] = {
        'family': 'pages', 'kind': 'top_dimension', 'dimension': 'resource',
        'content_type': {'operator': 'exact', 'value': 'download'}, 'default_limit': 200}
    result = oracle_for(path, 'slim_p4_09_top_downloads',
                        {'family': 'pages', 'table': 'slim_stats'}, contracts,
                        {'start': 10, 'end': 20})
    assert result['value'] == [
        {'counthits': '1', 'resource': '/a'}, {'counthits': '1', 'resource': '/b'}], result

    contracts['reports']['slim_p2_24_top_bots'] = {
        'family': 'pages', 'kind': 'top_dimensions', 'dimensions': ['resource', 'username'],
        'filter_column': 'browser_type', 'filter_value': 1, 'default_limit': 200}
    result = oracle_for(path, 'slim_p2_24_top_bots',
                        {'family': 'pages', 'table': 'slim_stats'}, contracts,
                        {'start': 10, 'end': 20})
    assert result['value'] == [
        {'counthits': '1', 'resource': '/a', 'username': None}], result

    contracts['reports']['slim_p4_01_recent_outbound'] = {
        'family': 'pages', 'kind': 'recent_outbound', 'default_limit': 200}
    result = oracle_for(path, 'slim_p4_01_recent_outbound',
                        {'family': 'pages', 'table': 'slim_stats'}, contracts,
                        {'start': 10, 'end': 20})
    assert result['value'] == [
        {'counthits': 2, 'dt': 70, 'outbound_resource': 'https://a'}], result

    contracts['reports']['slim_p4_04_recent_feeds'] = {
        'family': 'pages', 'kind': 'recent_rows', 'dimension': 'resource',
        'mode': 'feeds', 'default_limit': 200}
    result = oracle_for(path, 'slim_p4_04_recent_feeds',
                        {'family': 'pages', 'table': 'slim_stats'}, contracts,
                        {'start': 10, 'end': 20})
    assert result == {'class': 'empty', 'value': [], 'flags': {
        'clock_dependent': False, 'calendar_day_dependent': False, 'pinned': True}}, result

    # group-by: the transport reads exactly the two contract columns plus dt, and stringifies the
    # count the way every other surface reports one.
    contracts['reports']['get_group_by'] = {
        'family': 'group_by', 'group_by': 'browser', 'column_group': 'platform',
        'default_limit': 200, 'equality': 'ascii_ci', 'windowed': True}
    result = oracle_for(path, 'get_group_by', {'family': 'group_by', 'table': 'slim_stats'},
                        contracts, {'start': 10, 'end': 20})
    assert result['value'] == [{'browser': 'Chrome', 'counthits': '2',
                                'column_group': 'Mac;;;Win'}], result

    # The site's own URLs ride in with the window, not with the contract: without them the second
    # row would count this site's own referer and the fourth would call a self-referred hit a SERP.
    self_urls = {'home_url': 'http://site.example', 'host': 'site.example'}
    contracts['reports']['get_traffic_sources_summary'] = {
        'family': 'summary', 'kind': 'traffic_sources_summary'}
    result = oracle_for(path, 'get_traffic_sources_summary',
                        {'family': 'summary', 'table': 'slim_stats'}, contracts,
                        {'start': 10, 'end': 20, 'self_urls': self_urls})
    values = {row['metric']: row['value'] for row in result['value']}
    assert values['Pageviews'] == '3' and values['Unique Referrers'] == '2', result
    assert values['From External SERP'] == '0' and values['Bounce Pages'] == '1', result

    # Two series over the same rows, one of them filtered by the self URL the window carries.
    contracts['reports']['slim_p3_01_chart_daily'] = {
        'family': 'chart', 'metric_column': 'referer', 'metric2_column': 'ip',
        'distinct_v1': True, 'equality': 'ascii_ci', 'granularity': 'DAY', 'duration_days': 1,
        'start_of_week': 1, 'timezone': 'UTC',
        'row_filter': {'present': ['referer'],
                       'excludes_self': {'column': 'referer', 'url': 'home_url'}}}
    result = oracle_for(path, 'slim_p3_01_chart_daily', {'family': 'chart', 'table': 'slim_stats'},
                        contracts, {'end': 86400, 'self_urls': self_urls})
    # Two referers survive the filter; only one of those rows carries an address.
    assert result['value']['datasets'] == {'v1': [2], 'v2': [1]}, result

print('PASS: report evidence adapters')
