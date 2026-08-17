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

if len(sys.argv) not in (4, 5):
    print(json.dumps({"error": "usage: read_export_cli.py <export.sqlite> <table> <order_by> [expected_manifest_hash]"}))
    sys.exit(2)

path, table, order_by = sys.argv[1], sys.argv[2], sys.argv[3]
expected_manifest = sys.argv[4] if len(sys.argv) == 5 else None
try:
    conn = read_export.open_export(path)
    result = read_export.fingerprint_table(conn, table, order_by)
    # The manifest is carried INSIDE the export, so a reader that trusts it cannot detect an
    # export written under a different schema — demonstrated: editing _manifest to widen a
    # column and mark it NOT NULL left chained_hash identical and exited 0. `order_by` and
    # `spec` are already out-of-band for exactly this reason; this makes the manifest so too.
    if expected_manifest is not None and result["manifest_hash"] != expected_manifest:
        raise ValueError(
            f"manifest hash {result['manifest_hash']} does not match the expected "
            f"{expected_manifest} — the export carries a schema the caller did not derive"
        )
    print(json.dumps(result))
except Exception as exc:                      # a crash must be readable JSON, not a traceback the
    print(json.dumps({"error": f"{type(exc).__name__}: {exc}"}))   # PHP side reports as "no result"
    sys.exit(1)
