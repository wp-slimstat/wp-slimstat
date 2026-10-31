#!/usr/bin/env python3
"""Record one disposable k6 run. Restore the same corpus before each comparison arm."""
import argparse
from datetime import date
import json
import math
import os
from pathlib import Path
import re
import subprocess
import sys


def command(args):
    return subprocess.check_output(args, text=True)


def percentile(values, fraction):
    if not values:
        raise ValueError('Missing timing samples')
    return sorted(values)[max(0, math.ceil(len(values) * fraction) - 1)]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wp', required=True)
    parser.add_argument('--db', required=True)
    parser.add_argument('--database', required=True)
    parser.add_argument('--url', required=True)
    parser.add_argument('--run', required=True)
    parser.add_argument('--mode', choices=['tracking', 'concurrency', 'admin', 'control'], required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--sql-timing', action='store_true')
    parser.add_argument('--from-date')
    parser.add_argument('--to-date')
    args = parser.parse_args()
    if os.environ.get('SLIMSTAT_HOSTING_DISPOSABLE') != '1':
        parser.error('Explicit disposable-stack authorization is required')
    if not re.fullmatch(r'http://(?:127\.0\.0\.1|localhost):\d+', args.url):
        parser.error('Only explicitly ported localhost targets are allowed')
    if not all(re.fullmatch(r'[a-zA-Z0-9_-]+', item) for item in [args.wp, args.db, args.database, args.run]):
        parser.error('Invalid identifier')
    if not args.wp.startswith('slimstat_hosting_') or not args.db.startswith('slimstat_hosting_') or not args.database.startswith('tests-'):
        parser.error('Requires a hosting test container and tests-* database')
    if args.mode == 'admin':
        try:
            if date.fromisoformat(args.from_date) > date.fromisoformat(args.to_date):
                raise ValueError('Reversed report window')
        except (TypeError, ValueError):
            parser.error('Admin measurements require an ordered --from-date/--to-date window')
    args.output.mkdir(parents=True, exist_ok=False)
    wp = ['docker', 'exec', args.wp, 'wp']
    actual = command(wp + ['config', 'get', 'DB_NAME', '--allow-root']).strip()
    if actual != args.database:
        raise ValueError('Container DB_NAME does not match the requested test database')

    def sql(query):
        return command(['docker', 'exec', args.db, 'mysql', '-uroot', '-proot', '-N', '-B', args.database, '-e', query])

    def snapshot():
        return {
            'lock_counters': sql("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_deadlocks','Slow_queries','Threads_running')"),
            'tables': sql("SELECT table_name,table_rows,data_length,index_length FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'wp_slim_%' ORDER BY table_name"),
            'tracked': int(sql("SELECT COUNT(*) FROM wp_slim_stats WHERE LOCATE('hosting=" + args.run + "-', resource) > 0")),
            'distinct_resources': int(sql("SELECT COUNT(DISTINCT resource) FROM wp_slim_stats WHERE LOCATE('hosting=" + args.run + "-', resource) > 0")),
        }

    before = snapshot()
    if before['tracked']:
        raise ValueError('Run ID already exists; use a unique ID')
    plugins = json.loads(command(wp + ['plugin', 'list', '--format=json', '--allow-root']))
    if args.mode == 'control' and any(p['status'] == 'active' and p['name'] in ['wp-slimstat', 'wp-slimstat-pro'] for p in plugins):
        raise ValueError('Control requires both analytics plugins to be inactive')
    metadata = {'arguments': vars(args) | {'output': str(args.output)}, 'plugins': plugins,
                'client_profile': os.environ.get('BENCH_CLIENTS', 'single-address'),
                'php': command(['docker', 'exec', args.wp, 'php', '-v']),
                'wordpress': command(wp + ['core', 'version', '--allow-root']).strip(),
                'database': sql('SELECT VERSION()').strip(), 'before': before,
                'timing_scope': 'Instrumented mysqli query time' if args.sql_timing else 'HTTP request duration; admin HTML shell excludes later AJAX/browser rendering'}
    (args.output / 'manifest.json').write_text(json.dumps(metadata, indent=2))
    env = os.environ | {'BASE_URL': args.url, 'RUN_ID': args.run, 'MODE': args.mode, 'SQL_TIMING': '1' if args.sql_timing else '0'}
    if args.mode == 'admin':
        env.update(REPORT_FROM=args.from_date, REPORT_TO=args.to_date)
    with (args.output / 'resources.jsonl').open('w') as resources, (args.output / 'k6.log').open('w') as log:
        stats = subprocess.Popen(['docker', 'stats', '--format', '{{json .}}', args.wp, args.db], stdout=resources)
        try:
            result = subprocess.run(['k6', 'run', '--summary-export', str(args.output / 'summary.json'),
                                     '--out', 'json=' + str(args.output / 'samples.json.gz'),
                                     str(Path(__file__).with_name('load.js'))], env=env, stdout=log, stderr=subprocess.STDOUT)
        finally:
            stats.terminate()
            stats.wait(timeout=10)
    after = snapshot()
    ledger = command(['docker', 'exec', args.wp, 'cat', '/tmp/slimstat-bench/qlog.jsonl'])
    rows = [row for line in ledger.splitlines() if (row := json.loads(line)).get('label') == args.run]
    (args.output / 'queries.jsonl').write_text(''.join(json.dumps(row) + '\n' for row in rows))
    summary = json.loads((args.output / 'summary.json').read_text())
    iterations = int(summary['metrics']['iterations']['count'])
    checks = {'k6_thresholds': result.returncode == 0, 'ledger_complete': len(rows) == iterations}
    if args.mode in ['tracking', 'concurrency']:
        checks['one_row_per_iteration'] = after['tracked'] == after['distinct_resources'] == iterations
    else:
        checks['no_tracking_rows'] = after['tracked'] == 0
    insert = [value for row in rows for value in row.get('query_ms', {}).get('stats_insert', [])]
    if args.sql_timing and args.mode in ['tracking', 'concurrency']:
        checks['insert_samples_complete'] = len(insert) == iterations
        checks['insert_p95_under_15ms'] = bool(insert) and percentile(insert, .95) < 15
    report = {'checks': checks, 'iterations': iterations, 'after': after, 'insert_samples': len(insert),
              'insert_p95_ms': percentile(insert, .95) if insert else None,
              'query_means': {key: sum(row.get(key, 0) for row in rows) / len(rows) for key in
                              ['total', 'reads', 'writes', 'options_reads', 'options_writes', 'slim_reads', 'slim_writes']} if rows else {}}
    (args.output / 'result.json').write_text(json.dumps(report, indent=2))
    print(json.dumps(report, indent=2))
    return 0 if all(checks.values()) else 1


if __name__ == '__main__':
    if sys.argv[1:] == ['--self-test']:
        assert percentile([10, 1, 4, 2, 3], .95) == 10
        assert percentile([10, 1, 4, 2, 3], .5) == 3
        try:
            percentile([], .95)
        except ValueError:
            pass
        else:
            raise AssertionError('Empty samples must fail')
        args = [sys.executable, __file__, '--wp', 'slimstat_hosting_guard', '--db', 'slimstat_hosting_guard',
                '--database', 'tests-guard', '--url', 'http://127.0.0.1:1', '--run', 'guard',
                '--mode', 'admin', '--output', '/tmp/slimstat-date-guard-must-not-exist']
        for dates in [[], ['--from-date', '2026-08-31', '--to-date', '2026-08-01'],
                      ['--from-date', '2026-02-30', '--to-date', '2026-08-01']]:
            rejected = subprocess.run(args + dates, text=True, capture_output=True,
                                      env=os.environ | {'SLIMSTAT_HOSTING_DISPOSABLE': '1'})
            assert rejected.returncode == 2 and 'ordered' in rejected.stderr, rejected.stderr
        print('PASS: percentile and missing-sample contracts')
        print('PASS: invalid report windows rejected before database access')
    else:
        sys.exit(main())
