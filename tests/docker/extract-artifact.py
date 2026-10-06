#!/usr/bin/env python3
"""Validate explicit qualification ZIP bytes before extracting into an empty directory."""
import hashlib
import io
import json
import pathlib
import re
import stat
import sys
import zipfile

def require(condition, message):
    if not condition:
        raise ValueError(message)


archive, expected, slug, destination, *mode = sys.argv[1:]
require(mode in ([], ['--verify-installed']), 'unknown artifact operation')
require(re.fullmatch(r'[a-f0-9]{64}', expected), 'explicit SHA256 required')
require(slug in ('wp-slimstat', 'wp-slimstat-pro'), 'unknown plugin')
data = pathlib.Path(archive).read_bytes()
require(hashlib.sha256(data).hexdigest() == expected, 'artifact checksum mismatch')
out = pathlib.Path(destination)
if not mode:
    require(not out.exists() or not any(out.iterdir()), 'extraction destination must be empty')
with zipfile.ZipFile(io.BytesIO(data)) as z:
    names = z.namelist()
    require(names and len(names) == len(set(names)), 'empty or duplicate ZIP members')
    require(f'{slug}/{slug}.php' in names, 'plugin header missing')
    for entry in z.infolist():
        p = pathlib.PurePosixPath(entry.filename)
        require(not p.is_absolute() and p.parts[0] == slug and '..' not in p.parts, 'unsafe ZIP path')
        require('\\' not in entry.filename and not stat.S_ISLNK(entry.external_attr >> 16), 'unsafe ZIP member')
    require(z.testzip() is None, 'corrupt ZIP member')
    if mode:
        files = [entry for entry in z.infolist() if not entry.is_dir()]
        for entry in files:
            installed = out / entry.filename
            require(not installed.is_symlink() and installed.is_file(), 'installed shipping file missing: ' + entry.filename)
            require(installed.read_bytes() == z.read(entry), 'installed shipping file changed: ' + entry.filename)
        print(json.dumps(dict(slug=slug, zip_sha256=expected, shipping_files_verified=len(files))))
    else:
        z.extractall(out)
