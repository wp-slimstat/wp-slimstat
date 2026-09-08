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
            verified = subprocess.run(command + ['--verify-installed'], capture_output=True)
            assert verified.returncode == 0, verified.stderr
            (root / name / slug / (slug + '.php')).write_text('tampered after install')
            assert subprocess.run(command + ['--verify-installed'], capture_output=True).returncode != 0, 'installed edit accepted'
            command[3] = '0' * 64
            command.insert(1, '-O')
            assert subprocess.run(command, capture_output=True).returncode != 0, 'wrong digest accepted'
print('PASS: exact Free/Pro bytes, checksum refusal, unsafe paths, missing header, nonempty destination')

seed = helper.parent / 'seed-bench.sh'
r = subprocess.run(['bash', str(seed), '5000000'], capture_output=True)
assert r.returncode != 0 and b'requires SEED_VINTAGE_REF' in r.stdout, r.stderr
print('PASS: 5M source-less seeding refused before Docker')

# bash -n cannot see malformed Python inside a heredoc.
import re
for script in ['rehearse-uninstall.sh', 'rehearse-upgrade-network.sh', 'rehearse-upgrade.sh', 'run-cell.sh', 'seed-bench.sh', 'interrupt-ddl.sh']:
    source = (helper.parent / script).read_text()
    for match in re.finditer(r"<<'(?P<tag>PY\w*)'\n(?P<code>.*?)\n(?P=tag)(?:\n|$)", source, re.S):
        compile(match['code'], script + ':' + match['tag'], 'exec')
print('PASS: embedded Python syntax in artifact, seed and interruption harnesses')

# Inspect generated engine arguments without starting Docker. A caller's explicit overlay wins.
import os
artifact_inputs = ['QUALIFICATION_FREE_ZIP', 'QUALIFICATION_FREE_SHA256', 'QUALIFICATION_PRO_ZIP', 'QUALIFICATION_PRO_SHA256']
for script in ['rehearse-upgrade-network.sh', 'rehearse-uninstall.sh']:
    for missing in artifact_inputs:
        env = dict(os.environ, **{name: 'fixture' for name in artifact_inputs})
        env.pop(missing)
        refused = subprocess.run(['bash', str(helper.parent / script)], env=env, capture_output=True)
        assert refused.returncode != 0 and missing.encode() in refused.stderr, (script, missing, refused.stderr)
print('PASS: network/lifecycle qualification refuses each missing paired artifact input before Docker')
# Execute the actual optional-flag call under the platform Bash nounset mode.
with tempfile.TemporaryDirectory() as temp:
    for script in ['rehearse-upgrade-network.sh', 'rehearse-uninstall.sh']:
        source = (helper.parent / script).read_text()
        line = next(line for line in source.splitlines() if line.startswith('wpc core multisite-install '))
        for flag in ['', '--subdomains']:
            shell = 'set -eu; NETWORK_INSTALL_ARG="$1"; ART="$2"; BASE_URL=http://example.test; wpc() { printf "%s\\n" "$@"; }; ' + line
            result = subprocess.run(['bash', '-c', shell, 'test', flag, temp], capture_output=True)
            assert result.returncode == 0, (script, flag, result.stderr)
            args = (pathlib.Path(temp) / 'install.log').read_text().splitlines()
            assert ('--subdomains' in args) == bool(flag), (script, flag, args)
            (pathlib.Path(temp) / 'install.log').unlink()
print('PASS: native subdirectory/subdomain routing works with platform Bash nounset')
with tempfile.TemporaryDirectory() as temp:
    root = pathlib.Path(temp)
    fake = root / 'docker'
    fake.write_text('#!/bin/sh\n[ "$1" = ps ] && exit 0\n[ "$1" = volume ] && exit 0\nexit 1\n')
    fake.chmod(0o755)
    for explicit in [False, True]:
        case = root / str(explicit)
        env = dict(os.environ, PATH=str(root) + os.pathsep + os.environ['PATH'], WORK_ROOT=str(case))
        for key in ['SEED_VINTAGE_REF', 'DC_EXTRA_FILE', 'SEED_DUMP_OUT', 'REHEARSAL_RUNS_DIR']:
            env.pop(key, None)
        if explicit:
            overlay = root / 'caller.yml'
            overlay.write_text('services: {}\n')
            env['DC_EXTRA_FILE'] = str(overlay)
        r = subprocess.run(['bash', str(seed), '1'], env=env, capture_output=True)
        assert r.returncode != 0, 'fake Docker unexpectedly booted'
        generated = list(case.glob('bench/seed.*/compose-seed.yml'))
        assert bool(generated) != explicit, 'caller engine overlay was replaced'
        if generated:
            assert '--skip-log-bin' in generated[0].read_text(), 'large-corpus binary logging left enabled'
print('PASS: default corpus binary logging disabled; explicit engine overlay preserved')

# Corpus preparation must preserve previous outputs and refuse shared project resources.
import gzip
with tempfile.TemporaryDirectory() as temp:
    root = pathlib.Path(temp)
    source = root / 'source.sql.gz'
    source.write_bytes(gzip.compress(b'CREATE TABLE wp_slim_stats (id int);\n'))
    fake = root / 'docker'
    fake.write_text('#!/bin/sh\ncase "$1" in ps) [ "$CORPUS_COLLISION" != container ] || echo occupied; exit 0;; volume) [ "$CORPUS_COLLISION" != volume ] || echo occupied; exit 0;; *) exit 99;; esac\n')
    fake.chmod(0o755)
    for collision in ['output', 'container', 'volume']:
        output = root / (collision + '.sql.gz')
        if collision == 'output':
            output.write_bytes(b'preserved')
        env = dict(os.environ, PATH=str(root) + os.pathsep + os.environ['PATH'], WORK_ROOT=str(root / collision), CORPUS_COLLISION=collision)
        r = subprocess.run(['bash', str(helper.parent / 'downgrade-corpus.sh'), 'wp.org:5.3.5', str(source), str(output)], env=env, capture_output=True)
        expected = b'refusing to overwrite existing corpus' if collision == 'output' else b'Refusing existing corpus project'
        assert r.returncode != 0 and expected in r.stdout, (collision, r.stdout, r.stderr)
        if collision == 'output':
            assert output.read_bytes() == b'preserved'
print('PASS: corpus preparation preserves existing output and refuses container/volume collisions')
