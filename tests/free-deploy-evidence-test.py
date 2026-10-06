#!/usr/bin/env python3
"""Fake transport only: malformed or unqualified existing GitHub receipts never upload."""
from copy import deepcopy
from datetime import datetime, timezone
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile

sys.dont_write_bytecode = True
root = Path(__file__).resolve().parents[1]
module = importlib.util.spec_from_file_location('qualify_deploy', root / '.github/qualify-deploy.py')
qualifier = importlib.util.module_from_spec(module)
module.loader.exec_module(qualifier)
now = datetime(2026, 9, 7, 20, tzinfo=timezone.utc)
sha = 'a' * 40
expected = subprocess.check_output(['bash', str(root / '.github/expected-lanes.sh')], text=True).splitlines()
assert expected, 'Existing expected-lane format is empty'
run = {'id': 42, 'head_sha': sha, 'event': 'push', 'status': 'completed', 'conclusion': 'success', 'updated_at': '2026-09-07T19:00:00Z'}
jobs = [{'name': name, 'run_id': 42, 'head_sha': sha, 'status': 'completed', 'conclusion': 'success', 'completed_at': '2026-09-07T18:59:00Z'} for name in expected]
pages = [{'total_count': len(jobs), 'jobs': jobs}]
checks = 0

def rehearsal(label, run_value, pages_value, expected_value=expected, valid=False):
    global checks
    uploads = []
    try:
        qualifier.qualify(run_value, pages_value, expected_value, sha, now)
        uploads.append('fake-only upload')
    except (ValueError, TypeError):
        pass
    assert len(uploads) == (1 if valid else 0), label
    checks += 1

rehearsal('baseline', run, pages, valid=True)
rehearsal('missing run', None, pages)
rehearsal('missing jobs', run, [])
rehearsal('missing expectations', run, pages, [])
rehearsal('duplicate expectations', run, pages, expected + expected[:1])
for field, value in [('head_sha', 'b' * 40), ('event', 'pull_request'), ('status', 'in_progress'), ('conclusion', 'failure'), ('id', None), ('updated_at', '2026-08-01T00:00:00Z'), ('updated_at', '2026-09-08T00:00:00Z'), ('updated_at', 'broken')]:
    bad = dict(run, **{field: value})
    rehearsal('run ' + field + ':' + str(value), bad, pages)
for field, value in [('head_sha', 'b' * 40), ('run_id', 43), ('status', 'in_progress'), ('conclusion', 'failure'), ('conclusion', 'skipped'), ('completed_at', '2026-08-01T00:00:00Z'), ('completed_at', None)]:
    bad = deepcopy(pages)
    bad[0]['jobs'][0][field] = value
    rehearsal('job ' + field + ':' + str(value), run, bad)
bad = deepcopy(pages)
bad[0]['jobs'].pop()
rehearsal('truncated page', run, bad)
bad[0]['total_count'] -= 1
rehearsal('missing required lane', run, bad)
bad = deepcopy(pages)
bad[0]['jobs'].append(deepcopy(jobs[0]))
bad[0]['total_count'] += 1
rehearsal('duplicate lane', run, bad)
rehearsal('malformed page', run, [None])
# A genuine multi-page response is accepted only when its declared total is complete.
mid = len(jobs) // 2
split = [{'total_count': len(jobs), 'jobs': jobs[:mid]}, {'total_count': len(jobs), 'jobs': jobs[mid:]}]
rehearsal('complete pagination', run, split, valid=True)
split[1]['total_count'] += 1
rehearsal('inconsistent page total', run, split)
with tempfile.TemporaryDirectory(prefix='free-deploy-json-red-') as temporary:
    directory = Path(temporary)
    (directory / 'run.json').write_text('{"id":')
    (directory / 'jobs.json').write_text(json.dumps(pages))
    (directory / 'expected.txt').write_text('\n'.join(expected) + '\n')
    result = subprocess.run([sys.executable, str(root / '.github/qualify-deploy.py'), '--sha', sha, '--run', str(directory / 'run.json'), '--jobs', str(directory / 'jobs.json'), '--expected', str(directory / 'expected.txt')], capture_output=True, text=True)
    assert result.returncode != 0 and 'deploy refused' in result.stderr, 'truncated JSON accepted'
    checks += 1
print(f'PASS: {checks} fake-transport cases; missing/stale/mismatched/failed/incomplete evidence refuses upload')
