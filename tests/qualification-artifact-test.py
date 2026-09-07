#!/usr/bin/env python3
"""Run with python3 tests/qualification-artifact-test.py; no Docker or dependencies."""
import hashlib
import pathlib
import subprocess
import tempfile
import zipfile

helper = pathlib.Path(__file__).parent / 'docker/extract-artifact.py'
with tempfile.TemporaryDirectory() as temp:
    root = pathlib.Path(temp)
    for name, members, valid in [
        ('free', ['wp-slimstat/wp-slimstat.php'], True),
        ('pro', ['wp-slimstat-pro/wp-slimstat-pro.php'], True),
        ('traversal', ['wp-slimstat/wp-slimstat.php', 'wp-slimstat/../../escape'], False),
        ('absolute', ['wp-slimstat/wp-slimstat.php', '/escape'], False),
        ('missing-header', ['wp-slimstat/readme.txt'], False),
    ]:
        archive = root / (name + '.zip')
        slug = 'wp-slimstat-pro' if name == 'pro' else 'wp-slimstat'
        with zipfile.ZipFile(archive, 'w') as z:
            for member in members:
                z.writestr(member, '<?php // artifact fixture')
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        command = ['python3', str(helper), str(archive), digest, slug, str(root / name)]
        result = subprocess.run(command, capture_output=True)
        assert (result.returncode == 0) == valid, (name, result.stderr)
        if valid:
            assert (root / name / slug / (slug + '.php')).read_text() == '<?php // artifact fixture'
            assert subprocess.run(command, capture_output=True).returncode != 0, 'nonempty extraction accepted'
            command[3] = '0' * 64
            assert subprocess.run(command, capture_output=True).returncode != 0, 'wrong digest accepted'
print('PASS: exact Free/Pro bytes, checksum refusal, unsafe paths, missing header, nonempty destination')

seed = helper.parent / 'seed-bench.sh'
r = subprocess.run(['bash', str(seed), '5000000'], capture_output=True)
assert r.returncode != 0 and b'requires SEED_VINTAGE_REF' in r.stdout, r.stderr
print('PASS: 5M source-less seeding refused before Docker')

# bash -n cannot see malformed Python inside a heredoc.
import re
for script in ['rehearse-uninstall.sh', 'rehearse-upgrade-network.sh', 'seed-bench.sh', 'interrupt-ddl.sh']:
    source = (helper.parent / script).read_text()
    for match in re.finditer(r"<<'(?P<tag>PY\w*)'\n(?P<code>.*?)\n(?P=tag)(?:\n|$)", source, re.S):
        compile(match['code'], script + ':' + match['tag'], 'exec')
print('PASS: embedded Python syntax in artifact, seed and interruption harnesses')
