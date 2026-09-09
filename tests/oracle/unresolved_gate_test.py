#!/usr/bin/env python3
"""Runnable positive and required-red controls for the evidence gate."""
import copy
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile

from classify import classify

with tempfile.TemporaryDirectory() as temp:
    root = Path(temp)
    triple = {'surface': 'count_records', **{arm: {'class': 'ok', 'value': 3, 'flags': {'clock_dependent': False, 'calendar_day_dependent': False, 'pinned': True}}
                                            for arm in ('old', 'new', 'oracle')}}
    good = {'triples': [triple], 'classifications': [classify(triple).as_dict()], 'register': []}

    def run(documents, population=None, corrupt=None):
        evidence = {'source_shas': dict(free='a'*40, pro='b'*40),
                    'artifact_sha256': dict(free='c'*64, pro='d'*64)}
        for name, data in documents.items():
            path = root / (name + '.json')
            path.write_text(json.dumps(data))
            evidence[name] = {'path': path.name, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()}
        (root / 'population.json').write_text(json.dumps(population if population is not None else ['count_records']))
        (root / 'evidence.json').write_text(json.dumps(evidence))
        if corrupt:
            corrupt()
        return subprocess.run([sys.executable, str(Path(__file__).with_name('unresolved_gate.py')),
                               str(root / 'population.json'), str(root / 'evidence.json')],
                              capture_output=True, text=True)

    result = run(good)
    assert result.returncode == 0, result.stderr
    controls = []
    for name in ('triples', 'classifications'):
        broken = copy.deepcopy(good)
        broken[name] = []
        controls.append((name + ' missing surface', broken, None))
    for label in ('unknown', 'd-unmodeled', 'b'):
        broken = copy.deepcopy(good)
        broken['classifications'][0]['label'] = label
        controls.append((label, broken, None))
    for state in ('empty', 'error', 'unsupported', 'unmodeled'):
        broken = copy.deepcopy(good)
        broken['triples'][0]['oracle'] = {'class': state, 'value': None}
        controls.append(('non-answering ' + state, broken, None))
    broken = copy.deepcopy(good)
    broken['triples'][0]['new']['value'] = 4
    controls.append(('unresolved difference', broken, None))
    controls += [('truncated JSON', good, lambda: (root / 'triples.json').write_text('{')),
                 ('missing file', good, lambda: (root / 'register.json').unlink()),
                 ('truncated manifest', good, lambda: (root / 'evidence.json').write_text('{'))]
    for name, documents, corrupt in controls:
        result = run(documents, corrupt=corrupt)
        assert result.returncode != 0, name + ' incorrectly passed'
        print('PASS required-red:', name)
    assert run(good, population=[]).returncode != 0
    assert run(good, population=['count_records', 'missing_chart']).returncode != 0
    changed = copy.deepcopy(good)
    changed['triples'][0]['old']['value'] = 2
    changed['register'] = [{'r': 'R-test', 'surfaces': ['count_records'],
                            'observable': 'value-up', 'note': 'Synthetic registered correction control.'}]
    changed['classifications'] = [classify(changed['triples'][0], register=changed['register']).as_dict()]
    result = run(changed)
    assert result.returncode == 0, result.stderr
    changed['register'] = []
    assert run(changed).returncode != 0
    print('PASS: healthy equality and registered correction; all refusal controls')
