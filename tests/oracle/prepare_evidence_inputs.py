#!/usr/bin/env python3
"""Freeze one completed comparison capture into build_evidence.py inputs."""
import argparse
import hashlib
import json
from pathlib import Path
import shutil
import tempfile

HERE = Path(__file__).resolve().parent


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def prepare(artifacts, output):
    if output.exists():
        raise ValueError('output directory already exists')
    run = json.loads((artifacts / 'run.json').read_text())
    windows = run.get('capture_windows')
    if not isinstance(windows, dict) or not 0 < windows.get('start', 0) < windows.get('end', 0):
        raise ValueError('capture run has no valid pinned window')
    block = run.get('blocks', 0) - 1
    sources = {
        'before': artifacts / 'before.json',
        'after': artifacts / 'after.json',
        'before_caps': artifacts / 'before-caps.json',
        'after_caps': artifacts / 'after-caps.json',
        'artifacts': artifacts / 'artifacts.json',
        'before_export': artifacts / 'exports' / 'before' / ('block-%d.sqlite' % block),
        'after_export': artifacts / 'exports' / 'after' / ('block-%d.sqlite' % block),
        'expected_population': HERE / 'expected_population.json',
        'exclusions': HERE / 'exclusions.json',
        'register': HERE / 'register.json',
        'contracts': HERE / 'report-contracts.json',
    }
    missing = [name for name, path in sources.items() if not path.is_file()]
    if missing:
        raise ValueError('missing capture input(s): ' + ', '.join(missing))
    output.mkdir(parents=True)
    files = {}
    for name, source in sources.items():
        suffix = '.sqlite' if name.endswith('_export') else '.json'
        target = output / (name + suffix)
        shutil.copyfile(source, target)
        files[name] = {'path': target.name, 'sha256': sha256(target)}
    (output / 'inputs.json').write_text(json.dumps({
        'files': files, 'capture_windows': windows,
    }, indent=2, sort_keys=True) + '\n')
    return output / 'inputs.json'


def selftest():
    with tempfile.TemporaryDirectory() as temp:
        root = Path(temp); artifacts = root / 'artifacts'; artifacts.mkdir()
        (artifacts / 'run.json').write_text('{"blocks":1,"capture_windows":{"start":1,"end":2}}\n')
        for name in ('before.json', 'after.json', 'before-caps.json', 'after-caps.json', 'artifacts.json'):
            (artifacts / name).write_text('{}\n')
        for arm in ('before', 'after'):
            path = artifacts / 'exports' / arm; path.mkdir(parents=True)
            (path / 'block-0.sqlite').write_bytes(b'sqlite')
        manifest = prepare(artifacts, root / 'frozen')
        data = json.loads(manifest.read_text())
        assert set(data['files']) == {'before', 'after', 'before_caps', 'after_caps', 'artifacts',
                                      'before_export', 'after_export', 'expected_population',
                                      'exclusions', 'register', 'contracts'}
        assert all(sha256(manifest.parent / row['path']) == row['sha256'] for row in data['files'].values())
    print('PASS: evidence input preparation')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--artifacts', type=Path)
    parser.add_argument('--output', type=Path)
    parser.add_argument('--selftest', action='store_true')
    args = parser.parse_args()
    try:
        if args.selftest:
            selftest()
        elif args.artifacts and args.output:
            prepare(args.artifacts.resolve(), args.output.resolve())
        else:
            raise ValueError('--artifacts and --output are required')
    except (ValueError, OSError, KeyError, TypeError) as error:
        parser.exit(1, 'FAIL: %s\n' % error)
