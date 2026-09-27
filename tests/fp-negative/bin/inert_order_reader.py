#!/usr/bin/env python3
"""read_export_cli.py with the reader's ORDER BY made inert — and NOTHING else changed.

CONTROL 7's argument is that handing the ordering to the reader OUT OF BAND lets it detect an
export written under a different one. Over a file whose rowid order already equals that ordering
— which is every export this scheme writes, because MySQL wrote the rows in it — a reader whose
sort did nothing returns the same rows in the same sequence and the same hash. So this reader
does exactly that: it still HASHES `order_by` into manifest_hash, so the reader's manifest
precondition is satisfied and the run reaches the comparison, and it drops the ORDER BY from the
scan, so the sequence it folds is whatever the file happens to hold.

The encoder is not touched: read_export and encoding_v1 are imported and used as they ship. Only
the SQL the connection is asked to run is rewritten, at the one place the ordering appears.
"""
import json
import os
import re
import sys

sys.path.insert(0, os.environ["SLIMSTAT_ORACLE_DIR"])
import read_export                                              # noqa: E402

_ORDER_BY = re.compile(r'\s+ORDER BY "[^"]+"\s*$')


class InertOrder:
    """A connection proxy that strips a trailing double-quoted ORDER BY from any statement.

    `_manifest()`'s own `ORDER BY ord` is unquoted and therefore survives — the manifest still
    arrives in declaration order, so this changes the SEQUENCE OF ROWS and nothing else.
    """

    def __init__(self, conn):
        self._conn = conn

    def __getattr__(self, name):
        return getattr(self._conn, name)

    def execute(self, sql, *args):
        return self._conn.execute(_ORDER_BY.sub("", sql), *args)


if len(sys.argv) not in (4, 5):
    print(json.dumps({"error": "usage: inert_order_reader.py <export.sqlite> <table> <order_by> [manifest]"}))
    sys.exit(2)

path, table, order_by = sys.argv[1], sys.argv[2], sys.argv[3]
expected_manifest = sys.argv[4] if len(sys.argv) == 5 else None
try:
    conn = InertOrder(read_export.open_export(path))
    result = read_export.fingerprint_table(conn, table, order_by)
    if expected_manifest is not None and result["manifest_hash"] != expected_manifest:
        raise ValueError(
            f"manifest hash {result['manifest_hash']} does not match the expected "
            f"{expected_manifest} — the export carries a schema the caller did not derive"
        )
    print(json.dumps(result))
except Exception as exc:
    print(json.dumps({"error": f"{type(exc).__name__}: {exc}"}))
    sys.exit(1)
