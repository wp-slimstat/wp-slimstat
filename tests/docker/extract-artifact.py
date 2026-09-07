#!/usr/bin/env python3
"""Validate explicit qualification ZIP bytes before extracting into an empty directory."""
import hashlib
import io
import pathlib
import re
import stat
import sys
import zipfile

archive, expected, slug, destination = sys.argv[1:]
assert re.fullmatch(r'[a-f0-9]{64}', expected), 'explicit SHA256 required'
assert slug in ('wp-slimstat', 'wp-slimstat-pro'), 'unknown plugin'
data = pathlib.Path(archive).read_bytes()
assert hashlib.sha256(data).hexdigest() == expected, 'artifact checksum mismatch'
out = pathlib.Path(destination)
assert not out.exists() or not any(out.iterdir()), 'extraction destination must be empty'
with zipfile.ZipFile(io.BytesIO(data)) as z:
    names = z.namelist()
    assert names and len(names) == len(set(names)), 'empty or duplicate ZIP members'
    assert f'{slug}/{slug}.php' in names, 'plugin header missing'
    for entry in z.infolist():
        p = pathlib.PurePosixPath(entry.filename)
        assert not p.is_absolute() and p.parts[0] == slug and '..' not in p.parts, 'unsafe ZIP path'
        assert '\\' not in entry.filename and not stat.S_ISLNK(entry.external_attr >> 16), 'unsafe ZIP member'
    assert z.testzip() is None, 'corrupt ZIP member'
    z.extractall(out)
