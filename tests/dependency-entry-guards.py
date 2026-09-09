#!/usr/bin/env python3
"""Local PHP HTTP/CLI proof for three scoped modules with top-level side effects.
Run with the same PHP runtime as the candidate; source/code hashes are emitted.
This intentionally fails on a runtime that cannot parse a guarded module.
"""
import hashlib
import json
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

root = Path(__file__).resolve().parents[1]
files = [
    'src/Dependencies/Symfony/Component/String/Slugger/AsciiSlugger.php',
    'src/Dependencies/Symfony/Contracts/Service/ServiceSubscriberTrait.php',
    'src/Dependencies/Symfony/Contracts/Service/Test/ServiceLocatorTest.php',
]
guard = "\n\n// Scoped SlimStat module: allow plugin/CLI autoload, deny direct web execution.\nif (!defined('ABSPATH') && PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {\n    http_response_code(403);\n    exit;\n}\n"
proof = {'php': subprocess.check_output(['php', '-v'], text=True), 'source_files': {}}
with tempfile.TemporaryDirectory(prefix='slimstat-entry-guards-') as temp:
    folder = Path(temp)
    for i, name in enumerate(files):
        source = (root / name).read_bytes()
        assert source.decode().count(guard) == 1, 'Guard missing or duplicated: ' + name
        (folder / (str(i) + '.php')).write_bytes(source)
        proof['source_files'][name] = hashlib.sha256(source).hexdigest()
    # Remove only the tested guard, so the required-red exercises identical module code.
    (folder / 'original.php').write_text((folder / '2.php').read_text().replace(guard, '', 1))
    # Optional dependency stubs let this check isolate guard behavior, not vendor wiring.
    wrapper = r'''<?php
namespace Symfony\Contracts\Translation { interface LocaleAwareInterface {} }
namespace SlimStat\Dependencies\Symfony\Component\String\Slugger { interface SluggerInterface {} }
namespace SlimStat\Dependencies\Symfony\Contracts\Service { function trigger_deprecation(...$args) {} }
namespace SlimStat\Dependencies\Symfony\Contracts\Service\Test { class ServiceLocatorTestCase {} }
namespace {
    if (PHP_SAPI !== 'cli') { define('ABSPATH', __DIR__); }
    require __DIR__ . '/0.php'; require __DIR__ . '/1.php'; require __DIR__ . '/2.php';
    echo 'supported-context-reached';
}
'''
    (folder / 'supported.php').write_text(wrapper)
    cli = subprocess.run(['php', str(folder / 'supported.php')], capture_output=True, text=True)
    proof['cli'] = {'exit_status': cli.returncode, 'stdout': cli.stdout, 'stderr': cli.stderr}
    assert cli.returncode == 0 and cli.stdout == 'supported-context-reached', proof['cli']
    with socket.socket() as reservation:
        reservation.bind(('127.0.0.1', 0))
        port = reservation.getsockname()[1]
    def fetch(path):
        try:
            with urllib.request.urlopen(f'http://127.0.0.1:{port}/{path}', timeout=3) as response:
                return response.status, response.read().decode()
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode()
    with (folder / 'server.log').open('w+') as log:
        server = subprocess.Popen(['php', '-d', 'display_errors=1', '-S', f'127.0.0.1:{port}', '-t', temp], stdout=log, stderr=log)
        try:
            for attempt in range(100):
                try:
                    status, body = fetch('supported.php')
                    break
                except (urllib.error.URLError, ConnectionError):
                    if server.poll() is not None:
                        raise RuntimeError('PHP HTTP server exited')
                    time.sleep(.05)
            else:
                raise RuntimeError('PHP HTTP server did not start')
            assert status == 200 and body == 'supported-context-reached', (status, body)
            proof['wordpress_context'] = status
            proof['direct_http'] = {}
            for i, name in enumerate(files):
                status, body = fetch(str(i) + '.php')
                assert status == 403 and body == '', (name, status, body)
                proof['direct_http'][name] = status
            status, body = fetch('original.php')
            assert status == 200 and 'ServiceLocatorTestCase' in body, (status, body)
            proof['required_red_guard_removed'] = {'status': status, 'top_level_class_alias_executed': True}
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait()
            log.seek(0)
            proof['server_log'] = log.read()
print(json.dumps(proof, indent=2))
