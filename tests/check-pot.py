#!/usr/bin/env python3
"""Compare gettext message/context/plural identities using pinned WP-CLI."""
import ast
import json
from pathlib import Path
import re
import shlex
import subprocess
import sys
import tempfile


def messages(path):
    entries, current, key = set(), {}, None
    def finish():
        if current.get('msgid'):
            entry = tuple(current.get(k, '') for k in ('msgctxt', 'msgid', 'msgid_plural'))
            if entry in entries:
                raise ValueError('duplicate gettext entry: ' + repr(entry))
            entries.add(entry)
        current.clear()
    for raw in Path(path).read_text().splitlines() + ['']:
        line = raw.strip()
        if not line:
            finish()
            key = None
        elif line.startswith('#'):
            continue
        elif line.startswith('"'):
            if key is None:
                raise ValueError('orphan gettext continuation')
            current[key] += ast.literal_eval(line)
        else:
            match = re.fullmatch(r'(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?) (".*")', line)
            if not match:
                raise ValueError('invalid gettext syntax: ' + line)
            key = match[1]
            current[key] = ast.literal_eval(match[2])
    if not entries:
        raise ValueError('empty catalog')
    return entries


def generate(root, output):
    version = subprocess.check_output(['wp', '--version'], text=True).strip().splitlines()[-1]
    if version != 'WP-CLI 2.12.0':
        raise ValueError('expected WP-CLI 2.12.0, found ' + version)
    args = shlex.split(json.loads((root / 'composer.json').read_text())['scripts']['i18n:pot'])
    if args[:4] != ['wp', 'i18n', 'make-pot', '.']:
        raise ValueError('unexpected catalog generator')
    args[4] = str(output)
    subprocess.run(args, cwd=root, check=True)


def check(root):
    args = shlex.split(json.loads((root / 'composer.json').read_text())['scripts']['i18n:pot'])
    with tempfile.TemporaryDirectory(prefix='pot-freshness-') as tmp:
        generated = Path(tmp) / 'fresh.pot'
        generate(root, generated)
        old, new = messages(root / args[4]), messages(generated)
        if old != new:
            raise ValueError('stale POT: missing=' + repr(sorted(new - old)) + '; obsolete=' + repr(sorted(old - new)))
    print('PASS: catalog message/context/plural identities match generated source')


if __name__ == '__main__':
    check(Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1])
