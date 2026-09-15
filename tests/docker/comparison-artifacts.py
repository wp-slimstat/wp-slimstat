#!/usr/bin/env python3
"""Prepare or verify the exact paired ZIPs for an OLD/NEW report comparison."""
import json
import pathlib
import re
import subprocess
import sys


def unique(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('duplicate artifact field: ' + key)
        result[key] = value
    return result


def require(ok, message):
    if not ok:
        raise ValueError(message)


manifest, before, after, destination, *mode = sys.argv[1:]
require(mode in ([], ['--verify-installed', 'before'], ['--verify-installed', 'after']), 'unknown comparison artifact operation')
data = json.loads(pathlib.Path(manifest).read_text(), object_pairs_hook=unique)
require(isinstance(data, dict) and set(data) == {'before', 'after'}, 'both artifact arms required')
out = pathlib.Path(destination)
helper = pathlib.Path(__file__).with_name('extract-artifact.py')
for arm, free_sha in [('before', before), ('after', after)]:
    pair = data[arm]
    require(isinstance(pair, dict) and set(pair) == {'free', 'pro'}, 'explicit Free and Pro artifacts required')
    for plugin, slug in [('free', 'wp-slimstat'), ('pro', 'wp-slimstat-pro')]:
        item = pair[plugin]
        require(isinstance(item, dict) and set(item) == {'source_sha', 'path', 'sha256'}, 'incomplete artifact identity')
        require(isinstance(item['source_sha'], str) and re.fullmatch('[a-f0-9]{40}', item['source_sha']), 'full source SHA required')
        require(plugin != 'free' or item['source_sha'] == free_sha, 'artifact source differs from comparison arm')
        require(isinstance(item['path'], str) and pathlib.Path(item['path']).is_absolute(), 'absolute artifact path required')
        require(isinstance(item['sha256'], str) and re.fullmatch('[a-f0-9]{64}', item['sha256']), 'artifact SHA256 required')
if before == after:
    require(data['before'] == data['after'], 'same-source null control must use identical paired artifacts')
if not mode:
    require(not out.exists(), 'comparison artifact directory already exists')
    out.mkdir(parents=True)
for arm in ([mode[1]] if mode else ['before', 'after']):
    for plugin, slug in [('free', 'wp-slimstat'), ('pro', 'wp-slimstat-pro')]:
        item = data[arm][plugin]
        target = out if mode else out / arm / plugin
        subprocess.run([sys.executable, str(helper), item['path'], item['sha256'], slug, str(target)] + mode[:1], check=True)
if not mode:
    (out / 'manifest.json').write_text(json.dumps(data, indent=2) + '\n')
