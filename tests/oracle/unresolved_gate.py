#!/usr/bin/env python3
"""Fail closed over an explicitly reviewed population of existing classifier triples.

Usage: unresolved_gate.py population.json evidence.json
Evidence names sha256-pinned triples, classifications and register JSON files relative
 to itself, plus source_shas and artifact_sha256. Classification is rederived, never
accepted from a PASS string. This proves only the supplied population, not all UI reports.
"""
import hashlib
import json
from pathlib import Path
import re
import sys

from classify import LABELS, Register, classify


def require(condition, message):
    if not condition:
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


def gate(population_path, evidence_path):
    population = read_json(population_path)
    require(isinstance(population, list) and population
            and all(isinstance(s, str) and s for s in population), 'empty/invalid population')
    require(len(set(population)) == len(population), 'duplicate population surface')
    evidence = read_json(evidence_path)
    for field, width in [('source_shas', 40), ('artifact_sha256', 64)]:
        values = evidence[field]
        require(set(values) == {'free', 'pro'}, 'missing paired ' + field)
        require(all(isinstance(v, str) and re.fullmatch('[0-9a-f]{%d}' % width, v)
                    for v in values.values()), 'invalid ' + field)
    documents = {}
    for name in ('triples', 'classifications', 'register'):
        entry = evidence[name]
        path = (evidence_path.parent / entry['path']).resolve()
        require(path.is_relative_to(evidence_path.parent.resolve()), 'evidence path escapes directory')
        require(hashlib.sha256(path.read_bytes()).hexdigest() == entry['sha256'],
                'hash mismatch: ' + name)
        documents[name] = read_json(path)
    triples, reported = documents['triples'], documents['classifications']
    require(isinstance(triples, list) and isinstance(reported, list), 'expected evidence arrays')
    for name, rows in [('triples', triples), ('classifications', reported)]:
        keys = [row['surface'] for row in rows]
        require(len(keys) == len(set(keys)) and set(keys) == set(population),
                name + ': missing, duplicate or unexpected surfaces')
    reported = {row['surface']: row for row in reported}
    entries = documents['register']
    entries = entries['entries'] if isinstance(entries, dict) else entries
    register = Register(entries)
    for entry in entries:
        require(isinstance(entry.get('note'), str) and entry['note'].strip(),
                'registered difference lacks rationale')
    verdicts = []
    for triple in triples:
        surface = triple['surface']
        row = reported[surface]
        require(row.get('label') in LABELS, surface + ': unknown classification')
        # Pre-blind existence credit is insufficient for development correctness.
        require(triple.get('oracle', {}).get('class') in ('ok', 'zero'),
                surface + ': oracle did not answer')
        verdict = classify(triple, triple.get('contract'), register).as_dict()
        for field in ('label', 'disposition', 'register_id', 'observable'):
            require(row.get(field) == verdict[field], surface + ': classification mismatch: ' + field)
        require(verdict['disposition'] == 'pass' and not verdict['pre_blind'],
                surface + ': unresolved or blocking ' + verdict['label'])
        verdicts.append(verdict)
    return {'status': 'PASS', 'population': population, 'verdicts': verdicts,
            'source_shas': evidence['source_shas'], 'artifact_sha256': evidence['artifact_sha256']}


if __name__ == '__main__':
    try:
        require(len(sys.argv) == 3, 'usage: unresolved_gate.py population.json evidence.json')
        print(json.dumps(gate(Path(sys.argv[1]), Path(sys.argv[2])), sort_keys=True))
    except (ValueError, KeyError, TypeError, OSError, AttributeError) as error:
        print('FAIL: ' + str(error), file=sys.stderr)
        sys.exit(1)
