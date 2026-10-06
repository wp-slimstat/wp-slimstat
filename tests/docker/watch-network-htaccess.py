#!/usr/bin/env python3
"""Count kernel CLOSE_WRITE events for .htaccess; identical rewrites still count."""
import ctypes, json, os, select, struct, sys, time
libc = ctypes.CDLL(None, use_errno=True)
fd = libc.inotify_init1(os.O_NONBLOCK)
if fd < 0:
    raise OSError(ctypes.get_errno(), 'inotify_init1')
paths = ['/var/www/html', '/var/www/html/wp-content/uploads/wp-slimstat']
watches = {}
for path in paths:
    if os.path.isdir(path):
        wd = libc.inotify_add_watch(fd, path.encode(), 8)
        if wd < 0:
            raise OSError(ctypes.get_errno(), 'inotify_add_watch')
        watches[wd] = path
with open('/tmp/network-htaccess.jsonl', 'a', buffering=1) as output:
    open('/tmp/network-watch-ready', 'w').write(str(os.getpid()))
    while True:
        select.select([fd], [], [], 1)
        try:
            data = os.read(fd, 65536)
        except BlockingIOError:
            continue
        pos = 0
        while pos < len(data):
            wd, mask, cookie, size = struct.unpack_from('iIII', data, pos)
            name = data[pos + 16:pos + 16 + size].split(b'\0', 1)[0].decode()
            pos += 16 + size
            if name == '.htaccess':
                output.write(json.dumps(dict(path=watches[wd] + '/' + name, at=time.time())) + '\n')
