#!/usr/bin/env python3
"""Exercise real extraction and installed-byte checks for paired comparison arms."""
import copy
import hashlib
import json
import pathlib
import subprocess
import tempfile
import zipfile

helper = pathlib.Path(__file__).resolve().parent / 'docker/comparison-artifacts.py'
with tempfile.TemporaryDirectory() as temp:
    root = pathlib.Path(temp)
    data = {}
    for arm, sha in [('before', 'a' * 40), ('after', 'b' * 40)]:
        data[arm] = {}
        for plugin, slug in [('free', 'wp-slimstat'), ('pro', 'wp-slimstat-pro')]:
            archive = root / (arm + '-' + plugin + '.zip')
            with zipfile.ZipFile(archive, 'w') as z:
                z.writestr(slug + '/' + slug + '.php', '<?php // ' + arm)
            data[arm][plugin] = dict(source_sha=sha, path=str(archive), sha256=hashlib.sha256(archive.read_bytes()).hexdigest())
    manifest = root / 'input.json'
    def run(value, out, before='a' * 40, after='b' * 40, mode=None):
        manifest.write_text(json.dumps(value))
        return subprocess.run(['python3', str(helper), str(manifest), before, after, str(out)] + (mode or []), capture_output=True)
    out = root / 'valid'
    r = run(data, out)
    assert r.returncode == 0, r.stderr
    assert (out / 'manifest.json').is_file()
    assert run(data, out).returncode != 0, 'existing preparation accepted'
    for label in ['missing-pro', 'wrong-hash', 'wrong-ref', 'missing-sha', 'relative-path']:
        wrong = copy.deepcopy(data)
        if label == 'missing-pro': del wrong['after']['pro']
        if label == 'wrong-hash': wrong['after']['pro']['sha256'] = '0' * 64
        if label == 'wrong-ref': wrong['after']['free']['source_sha'] = 'c' * 40
        if label == 'missing-sha': del wrong['before']['pro']['source_sha']
        if label == 'relative-path': wrong['before']['free']['path'] = 'plugin.zip'
        assert run(wrong, root / label).returncode != 0, label
    null = {'before': data['before'], 'after': data['before']}
    assert run(null, root / 'null', after='a' * 40).returncode == 0
    wrong = copy.deepcopy(null)
    wrong['after'] = copy.deepcopy(data['after'])
    wrong['after']['free']['source_sha'] = 'a' * 40
    assert run(wrong, root / 'mismatched-null', after='a' * 40).returncode != 0
    installed = root / 'installed'
    installed.mkdir()
    import shutil
    for plugin, slug in [('free', 'wp-slimstat'), ('pro', 'wp-slimstat-pro')]:
        shutil.copytree(out / 'after' / plugin / slug, installed / slug)
    assert run(data, installed, mode=['--verify-installed', 'after']).returncode == 0
    (installed / 'wp-slimstat-pro/wp-slimstat-pro.php').write_text('changed shipping bytes')
    assert run(data, installed, mode=['--verify-installed', 'after']).returncode != 0
print('PASS: exact paired comparison artifacts, identity/hash prerequisites, null pairing and installed tamper control')
