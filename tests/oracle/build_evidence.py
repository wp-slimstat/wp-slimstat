#!/usr/bin/env python3
"""Join report captures, caps metadata, exports and reviewed inventory into gate evidence.

Usage: build_evidence.py --input-manifest inputs.json --output fresh-directory
       build_evidence.py --selftest
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
from adapters import comparison_contract, oracle_for  # noqa: E402
from classify import Register, classify  # noqa: E402
sys.path.insert(0, str(HERE.parent / 'docker'))
from answers_population import comparable_keys  # noqa: E402

INPUTS = ('before', 'after', 'before_caps', 'after_caps', 'artifacts',
          'before_export', 'after_export', 'expected_population', 'exclusions',
          'register', 'contracts')
CAPTURE_CLASSES = ('ok', 'empty', 'zero', 'error', 'unsupported')


def require(ok, message):
    if not ok:
        raise ValueError(message)


def read_json(path):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            require(key not in result, 'duplicate JSON key: ' + key)
            result[key] = value
        return result
    return json.loads(path.read_text(), object_pairs_hook=unique,
                      parse_constant=lambda value: require(False, 'non-finite JSON: ' + value))


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def load_inputs(manifest_path):
    manifest = read_json(manifest_path)
    require(set(manifest.get('files', {})) == set(INPUTS), 'input manifest must pin every required file')
    require(isinstance(manifest.get('capture_windows'), dict), 'capture_windows must be recorded')
    loaded = {}
    for name, item in manifest['files'].items():
        path = (manifest_path.parent / item['path']).resolve()
        require(path.is_relative_to(manifest_path.parent.resolve()), name + ' path escapes input directory')
        require(re.fullmatch('[0-9a-f]{64}', item.get('sha256', '')) is not None, name + ' has invalid sha256')
        require(sha256(path) == item['sha256'], name + ' input hash mismatch')
        loaded[name] = path if name.endswith('_export') else read_json(path)
    return manifest, loaded


def inventory(data):
    require(data.get('schema') == 'SLIMSTAT-REPORT-EVIDENCE-INVENTORY-V1', 'invalid inventory schema')
    rows = data.get('surfaces')
    require(isinstance(rows, list) and rows, 'empty inventory')
    result = {}
    for row in rows:
        require(isinstance(row, dict) and set(row) == {'key', 'source', 'adapter'}, 'malformed inventory row')
        require(isinstance(row['key'], str) and row['key'], 'inventory key is empty')
        require(row['source'] in ('legacy', 'legacy-control', 'extended'), row['key'] + ': invalid source')
        require(row['key'] not in result, 'duplicate inventory key: ' + row['key'])
        result[row['key']] = row
    return result


def approved_exclusions(data, expected):
    require(data.get('schema') == 'SLIMSTAT-REPORT-EVIDENCE-EXCLUSIONS-V1', 'invalid exclusions schema')
    approved = data.get('approved')
    require(isinstance(approved, list), 'approved exclusions must be a list')
    keys = set()
    for row in approved:
        require(set(row) == {'key', 'approved_by', 'rationale', 'approval_sha256'}, 'malformed approved exclusion')
        require(row['key'] in expected, 'unapproved exclusion/unexpected key: ' + str(row['key']))
        require(isinstance(row['approved_by'], str) and row['approved_by'].strip(), row['key'] + ': missing approver')
        require(isinstance(row['rationale'], str) and row['rationale'].strip(), row['key'] + ': missing rationale')
        require(re.fullmatch('[0-9a-f]{64}', row['approval_sha256']) is not None, row['key'] + ': invalid approval hash')
        require(row['key'] not in keys, 'duplicate exclusion: ' + row['key'])
        keys.add(row['key'])
    return keys


def control_envelope(surface, windows):
    require(surface in ('window_start', 'window_end'), surface + ': unknown capture control')
    start, end = windows.get('start'), windows.get('end')
    require(type(start) is int and type(end) is int and 0 < start < end,
            'capture_windows must pin positive integer start < end')
    return {'class': 'ok', 'value': windows[surface.removeprefix('window_')],
            'flags': {'clock_dependent': False, 'calendar_day_dependent': False, 'pinned': True}}


def arm_envelope(surface, values, caps, source='legacy', windows=None):
    if source == 'legacy-control':
        # These are harness arguments, explicitly not measured report envelopes.
        control = control_envelope(surface, windows)
        require(surface in values and type(values[surface]) is int
                and values[surface] == control['value'], surface + ': capture control mismatch/missing')
        return control
    statuses = caps.get('_arm_surfaces' if source == 'extended' else '_arm_status')
    require(isinstance(statuses, dict) and surface in statuses, surface + ': missing caps row')
    status = statuses[surface]
    require(isinstance(status, dict), surface + ': malformed envelope')
    require(status.get('class') in CAPTURE_CLASSES, surface + ': malformed envelope class')
    flags = status.get('flags')
    require(isinstance(flags, dict) and all(isinstance(flags.get(k), bool) for k in
            ('clock_dependent', 'calendar_day_dependent', 'pinned')), surface + ': malformed envelope flags')
    if source == 'extended':
        require('value' in status, surface + ': missing extended arm value')
        value = status['value']
    else:
        require(surface in values, surface + ': missing arm value')
        value = values[surface]
    return {'class': status['class'], 'value': value, 'flags': flags,
            'error': status.get('error'), '__unsupported': status.get('__unsupported')}


def artifact_identity(artifacts):
    source, artifact = {}, {}
    for arm in ('before', 'after'):
        pair = artifacts.get(arm)
        require(isinstance(pair, dict) and set(pair) == {'free', 'pro'}, 'missing ' + arm + ' artifact pair')
        source[arm], artifact[arm] = {}, {}
        for plugin in ('free', 'pro'):
            item = pair[plugin]
            require(re.fullmatch('[0-9a-f]{40}', item.get('source_sha', '')) is not None,
                    arm + '.' + plugin + ': invalid source SHA')
            require(re.fullmatch('[0-9a-f]{64}', item.get('sha256', '')) is not None,
                    arm + '.' + plugin + ': invalid artifact SHA256')
            source[arm][plugin], artifact[arm][plugin] = item['source_sha'], item['sha256']
    return source, artifact


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + '\n')


def build(manifest_path, output, resolver=oracle_for):
    manifest, data = load_inputs(manifest_path)
    expected = inventory(data['expected_population'])
    excluded = approved_exclusions(data['exclusions'], expected)
    required = sorted(set(expected) - excluded)
    observed = comparable_keys(data['before'], data['after'])
    unexpected = sorted(set(observed) - set(expected))
    require(not unexpected, 'unexpected capture key(s): ' + ', '.join(unexpected))
    required_legacy = {key for key in required if expected[key]['source'] != 'extended'}
    require(required_legacy <= set(observed), 'required key missing from both arms: ' +
            ', '.join(sorted(required_legacy - set(observed))))
    register_rows = data['register'].get('entries') if isinstance(data['register'], dict) else data['register']
    register = Register(register_rows)
    contracts = data['contracts']
    triples = []
    for surface in required:
        row = expected[surface]
        source, windows = row['source'], manifest['capture_windows']
        triple = {
            'surface': surface,
            'old': arm_envelope(surface, data['before'], data['before_caps'], source, windows),
            'new': arm_envelope(surface, data['after'], data['after_caps'], source, windows),
            'oracle': (control_envelope(surface, windows) if source == 'legacy-control' else
                       resolver(data['after_export'], surface, row['adapter'], contracts)),
        }
        contract = comparison_contract(surface, row['adapter'], contracts)
        if contract:
            triple['contract'] = contract
        triples.append(triple)
    classifications = [classify(row, row.get('contract'), register).as_dict() for row in triples]
    require(not output.exists(), 'output directory already exists')
    output.mkdir(parents=True)
    write_json(output / 'population.json', required)
    write_json(output / 'triples.json', triples)
    write_json(output / 'classifications.json', classifications)
    shutil.copyfile(manifest_path, output / 'inputs.json')
    write_json(output / 'register.json', data['register'])
    source_shas, artifact_sha256 = artifact_identity(data['artifacts'])
    evidence = {'source_shas': source_shas, 'artifact_sha256': artifact_sha256}
    for name in ('triples', 'classifications', 'register'):
        path = output / (name + '.json')
        evidence[name] = {'path': path.name, 'sha256': sha256(path)}
    write_json(output / 'evidence.json', evidence)
    write_json(output / 'run.json', {
        'input_manifest_sha256': sha256(manifest_path),
        'inputs': {name: item['sha256'] for name, item in manifest['files'].items()},
        'capture_windows': manifest['capture_windows'],
    })
    return output


def selftest():
    with tempfile.TemporaryDirectory() as temp:
        root = Path(temp)
        pair = {'free': {'source_sha': 'a' * 40, 'sha256': 'b' * 64},
                'pro': {'source_sha': 'c' * 40, 'sha256': 'd' * 64}}
        artifacts = {'before': pair, 'after': pair}
        contracts = {'reports': {}}

        def prepare(name, before, after, before_caps, after_caps, surfaces=('x',), exclusions=(), register=(), sources=None):
            case = root / name
            case.mkdir()
            docs = {
                'before': before, 'after': after, 'before_caps': before_caps, 'after_caps': after_caps,
                'artifacts': artifacts, 'expected_population': {'schema': 'SLIMSTAT-REPORT-EVIDENCE-INVENTORY-V1',
                    'surfaces': [{'key': key, 'source': (sources or {}).get(key, 'legacy'), 'adapter': None}
                                 for key in surfaces]},
                'exclusions': {'schema': 'SLIMSTAT-REPORT-EVIDENCE-EXCLUSIONS-V1', 'approved': list(exclusions)},
                'register': {'entries': list(register)}, 'contracts': contracts,
            }
            files = {}
            for key in INPUTS:
                path = case / (key + ('.sqlite' if key.endswith('_export') else '.json'))
                path.write_bytes(b'export') if key.endswith('_export') else write_json(path, docs[key])
                files[key] = {'path': path.name, 'sha256': sha256(path)}
            write_json(case / 'inputs.json', {'files': files, 'capture_windows': {'start': 1, 'end': 2}})
            return case

        def caps(classes):
            return {'_arm_status': {key: {'class': klass,
                'error': ({'str': 'self-test SQL failure', 'query': 'SELECT broken', 'count': 1}
                          if klass == 'error' else None),
                'flags': {'clock_dependent': False, 'calendar_day_dependent': False, 'pinned': True}}
                for key, klass in classes.items()}}

        unmodeled = prepare('unmodeled', {'x': 1}, {'x': 1}, caps({'x': 'ok'}), caps({'x': 'ok'}))
        out = build(unmodeled / 'inputs.json', unmodeled / 'out')
        assert read_json(out / 'classifications.json')[0]['label'] == 'd-unmodeled'
        gate = [sys.executable, str(HERE / 'unresolved_gate.py'), str(out / 'population.json'), str(out / 'evidence.json')]
        assert subprocess.run(gate, capture_output=True).returncode != 0

        reg = [{'r': 'R-selftest', 'surfaces': ['x'], 'observable': 'value-up', 'note': 'self-test'}]
        changed = prepare('changed', {'x': 1}, {'x': 2}, caps({'x': 'ok'}), caps({'x': 'ok'}), register=reg)
        resolver = lambda *_args: {'class': 'ok', 'value': 2, 'flags': {'pinned': True}}
        out = build(changed / 'inputs.json', changed / 'out', resolver)
        gate = [sys.executable, str(HERE / 'unresolved_gate.py'), str(out / 'population.json'), str(out / 'evidence.json')]
        assert subprocess.run(gate, capture_output=True).returncode == 0

        # report-answers.php emits window arguments WITHOUT _arm_status rows and
        # extended captures as whole envelopes under _arm_surfaces, not in answers.
        controls = {'window_start': 1, 'window_end': 2}
        control_sources = {key: 'legacy-control' for key in controls}
        case = prepare('real-window-shape', controls, controls, caps({}), caps({}),
                       tuple(controls), sources=control_sources)
        out = build(case / 'inputs.json', case / 'out')
        assert all(row['old']['value'] == row['oracle']['value']
                   for row in read_json(out / 'triples.json'))
        for name, before, after in (
                ('window-mismatch', controls, dict(controls, window_end=3)),
                ('window-missing', controls, {'window_start': 1})):
            case = prepare(name, before, after, caps({}), caps({}), tuple(controls), sources=control_sources)
            try:
                build(case / 'inputs.json', case / 'out')
                raise AssertionError(name + ' passed')
            except ValueError:
                pass

        extended = caps({'get_recent': 'error'})['_arm_status']['get_recent']
        extended = dict(extended, value=None, rows=None, scalar=None, __unsupported=None)
        ext_caps = {'_arm_status': {}, '_arm_surfaces': {'get_recent': extended}}
        case = prepare('real-extended-shape', {}, {}, ext_caps, ext_caps,
                       ('get_recent',), sources={'get_recent': 'extended'})
        out = build(case / 'inputs.json', case / 'out')
        triple = read_json(out / 'triples.json')[0]
        assert triple['old']['class'] == 'error' and triple['old']['error'] == extended['error']
        for name, bad_caps in (
                ('extended-missing', {'_arm_surfaces': {}}),
                ('extended-malformed', {'_arm_surfaces': {'get_recent': {'class': 'empty', 'value': []}}})):
            case = prepare(name, {}, {}, ext_caps, bad_caps,
                           ('get_recent',), sources={'get_recent': 'extended'})
            try:
                build(case / 'inputs.json', case / 'out')
                raise AssertionError(name + ' passed')
            except ValueError:
                pass

        failures = [
            ('one-arm-drop', {}, {'x': 1}, caps({}), caps({'x': 'ok'}), ('x',), ()),
            ('both-drop', {}, {}, caps({}), caps({}), ('x',), ()),
            ('missing-caps', {'x': 1}, {'x': 1}, caps({}), caps({'x': 'ok'}), ('x',), ()),
            ('malformed-envelope', {'x': 1}, {'x': 1}, {'_arm_status': {'x': {'class': 'ok'}}}, caps({'x': 'ok'}), ('x',), ()),
            ('unexpected', {'x': 1, 'y': 2}, {'x': 1}, caps({'x': 'ok'}), caps({'x': 'ok'}), ('x',), ()),
            ('unapproved-exclusion', {'x': 1}, {'x': 1}, caps({'x': 'ok'}), caps({'x': 'ok'}), ('x',),
             ({'key': 'y', 'approved_by': 'owner', 'rationale': 'test', 'approval_sha256': 'e' * 64},)),
        ]
        for name, before, after, bcaps, acaps, surfaces, exclusions in failures:
            case = prepare(name, before, after, bcaps, acaps, surfaces, exclusions)
            try:
                build(case / 'inputs.json', case / 'out')
                raise AssertionError(name + ' passed')
            except ValueError:
                pass

        approval = {'key': 'x', 'approved_by': 'owner', 'rationale': 'approved self-test exclusion',
                    'approval_sha256': 'e' * 64}
        case = prepare('approved', {'x': 1, 'y': 2}, {'x': 1, 'y': 2},
                       caps({'x': 'ok', 'y': 'ok'}), caps({'x': 'ok', 'y': 'ok'}), ('x', 'y'), (approval,))
        out = build(case / 'inputs.json', case / 'out')
        assert read_json(out / 'population.json') == ['y']

        case = prepare('error-preserved', {'x': None}, {'x': None}, caps({'x': 'error'}), caps({'x': 'error'}))
        out = build(case / 'inputs.json', case / 'out')
        assert read_json(out / 'triples.json')[0]['old']['class'] == 'error'

        case = prepare('tampered', {'x': 1}, {'x': 1}, caps({'x': 'ok'}), caps({'x': 'ok'}))
        (case / 'before.json').write_text('{}')
        try:
            build(case / 'inputs.json', case / 'out')
            raise AssertionError('tampered input passed')
        except ValueError:
            pass

        case = prepare('missing-old-artifacts', {'x': 1}, {'x': 1}, caps({'x': 'ok'}), caps({'x': 'ok'}))
        write_json(case / 'artifacts.json', {'before': {}, 'after': pair})
        inputs = read_json(case / 'inputs.json')
        inputs['files']['artifacts']['sha256'] = sha256(case / 'artifacts.json')
        write_json(case / 'inputs.json', inputs)
        try:
            build(case / 'inputs.json', case / 'out')
            raise AssertionError('missing old artifact pair passed')
        except ValueError:
            pass
    print('PASS: build_evidence converter and required refusal controls')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--input-manifest', type=Path)
    parser.add_argument('--output', type=Path)
    parser.add_argument('--selftest', action='store_true')
    args = parser.parse_args()
    try:
        if args.selftest:
            selftest()
        else:
            require(args.input_manifest is not None and args.output is not None,
                    '--input-manifest and --output are required')
            build(args.input_manifest.resolve(), args.output.resolve())
    except (ValueError, KeyError, TypeError, OSError) as error:
        print('FAIL: ' + str(error), file=sys.stderr)
        sys.exit(1)
