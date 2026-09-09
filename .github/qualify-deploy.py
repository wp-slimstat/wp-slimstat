#!/usr/bin/env python3
"""Validate GitHub's existing run/jobs receipts; has no upload transport."""
import argparse
from datetime import datetime, timezone
import json
from pathlib import Path
import re


def qualify(run, pages, expected, sha, now=None):
    now = now or datetime.now(timezone.utc)
    if not re.fullmatch(r'[0-9a-f]{40}', sha):
        raise ValueError('invalid candidate SHA')
    if not isinstance(run, dict) or any(run.get(k) != v for k, v in {
        'head_sha': sha, 'event': 'push', 'status': 'completed', 'conclusion': 'success',
    }.items()) or type(run.get('id')) is not int or run['id'] <= 0:
        raise ValueError('missing, mismatched, incomplete or failed push run')
    try:
        updated = datetime.fromisoformat(run['updated_at'].replace('Z', '+00:00'))
        age = (now - updated).total_seconds()
    except (KeyError, TypeError, ValueError, AttributeError) as error:
        raise ValueError('invalid evidence timestamp') from error
    if age < 0 or age > 86400:
        raise ValueError('stale or future evidence')
    if not expected or len(expected) != len(set(expected)) or any(not isinstance(x, str) or not x.strip() for x in expected):
        raise ValueError('missing or ambiguous expected lanes')
    if not isinstance(pages, list) or not pages:
        raise ValueError('missing jobs pages')
    jobs = []
    total = None
    for page in pages:
        if not isinstance(page, dict) or type(page.get('total_count')) is not int or not isinstance(page.get('jobs'), list):
            raise ValueError('malformed jobs page')
        if total is not None and total != page['total_count']:
            raise ValueError('inconsistent jobs pagination')
        total = page['total_count']
        jobs.extend(page['jobs'])
    if total != len(jobs) or any(not isinstance(job, dict) for job in jobs):
        raise ValueError('truncated jobs evidence')
    for name in expected:
        matches = [job for job in jobs if job.get('name') == name]
        if len(matches) != 1:
            raise ValueError('missing or duplicate required lane: ' + name)
        job = matches[0]
        if any(job.get(k) != v for k, v in {
            'head_sha': sha, 'run_id': run['id'], 'status': 'completed', 'conclusion': 'success',
        }.items()):
            raise ValueError('mismatched, incomplete or failed lane: ' + name)
        try:
            completed = datetime.fromisoformat(job['completed_at'].replace('Z', '+00:00'))
            age = (now - completed).total_seconds()
        except (KeyError, TypeError, ValueError, AttributeError) as error:
            raise ValueError('invalid lane completion timestamp: ' + name) from error
        if age < 0 or age > 86400:
            raise ValueError('stale or future lane: ' + name)
    return True


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--run', required=True)
    parser.add_argument('--jobs', required=True)
    parser.add_argument('--expected', required=True)
    parser.add_argument('--sha', required=True)
    args = parser.parse_args()
    try:
        qualify(json.loads(Path(args.run).read_text()), json.loads(Path(args.jobs).read_text()),
                Path(args.expected).read_text().splitlines(), args.sha)
    except (OSError, ValueError, TypeError) as error:
        raise SystemExit('deploy refused: ' + str(error))
    print('PASS: current exact-SHA push run and complete required jobs')
