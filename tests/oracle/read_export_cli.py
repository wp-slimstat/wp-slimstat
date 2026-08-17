#!/usr/bin/env python3
"""Print the ENCODING_V1 fingerprint of one table in a SQLite export, as JSON.

  python3 tests/oracle/read_export_cli.py <export.sqlite> <table> <order_by>

A thin argv/JSON shell over read_export.fingerprint_table(), so the PHP-side fidelity gate can
call the Python encoder as a subprocess and compare bytes. All the logic lives in the module;
this file exists because a gate written in one language has to reach the other somehow.
"""
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import read_export

if len(sys.argv) != 4:
    print(json.dumps({"error": "usage: read_export_cli.py <export.sqlite> <table> <order_by>"}))
    sys.exit(2)

path, table, order_by = sys.argv[1], sys.argv[2], sys.argv[3]
try:
    conn = read_export.open_export(path)
    print(json.dumps(read_export.fingerprint_table(conn, table, order_by)))
except Exception as exc:                      # a crash must be readable JSON, not a traceback the
    print(json.dumps({"error": f"{type(exc).__name__}: {exc}"}))   # PHP side reports as "no result"
    sys.exit(1)
