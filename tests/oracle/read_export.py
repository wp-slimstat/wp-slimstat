"""Recompute the ENCODING_V1 fingerprint over the SQLite export — INDEPENDENTLY.

This side never reads a token. The export stores typed raw VALUES (strings as BLOB, integers as
INTEGER, SQL NULL as NULL), and this module applies the spec to them itself. That is what makes
the equality check evidence about the EXPORT: had the export stored pre-encoded tokens, equality
would prove a token survived a copy, and a value mangled in transit beside its intact token would
pass.

The column manifest travels inside the export (`_manifest`), so this file does not re-derive the
pinned set. A second derivation would be a second parser disagreeing with the first — the defect
this programme records most.
"""
import sqlite3

import encoding_v1 as E


def _text(v):
    """Manifest metadata is ASCII identifiers, not data.

    The connection sets `text_factory = bytes` so DATA is never decoded behind our backs — that
    is the whole point of storing strings as BLOB. But column names and declared types are
    identifiers we have to reason about as text, so they are decoded explicitly here. Doing it in
    one helper keeps the distinction visible: everything else in this module stays bytes.
    """
    return v.decode("utf-8") if isinstance(v, (bytes, bytearray)) else v


def _manifest(conn, table):
    # Bind the str, NOT bytes. text_factory governs how results are RETURNED, not how parameters
    # are bound: binding bytes binds a BLOB, and in SQLite a BLOB never equals a TEXT column, so
    # the lookup silently matched nothing.
    rows = conn.execute(
        "SELECT name, type, nullable, wide FROM _manifest WHERE tbl = ? ORDER BY ord",
        (_text(table),),
    ).fetchall()
    if not rows:
        raise ValueError(f"the export carries no manifest for {table!r}")
    return [(_text(n), _text(t), int(nu), int(w)) for n, t, nu, w in rows]


def _value(value, is_str, is_wide, label):
    """One stored cell -> the value ENCODING_V1 should see.

    BLOB is how the export refuses to let SQLite reinterpret bytes. For a string column those
    bytes ARE the value, so they pass through untouched. For an integer too wide for SQLite's
    signed INTEGER they are its decimal rendering, decoded to ASCII so encode_field re-renders
    from the digits — never through a Python int that a 64-bit column could have narrowed.

    `is_str` arrives PRECOMPUTED rather than derived here. Deriving it cost one E.kind() per
    string cell — roughly a third of the 22.2M calls this module made over the 443k export — for
    an answer that is fixed per COLUMN, so fingerprint_table() resolves it once and this indexes
    the result. A list index beats even a cache hit: no hash, no dict probe. The other two-thirds,
    the calls encode_field() makes, cannot be hoisted away — that primitive is exercised
    standalone by the golden fixtures and has to keep validating its own argument — and those are
    the ones encoding_v1.kind()'s memo exists for.
    """
    if isinstance(value, bytes):
        if is_wide:
            return value.decode("ascii")
        if not is_str:
            raise ValueError(f"{label}: unexpected BLOB for a narrow integer")
    return value


def fingerprint_table(conn, table, order_by):
    """Chain over the export's rows, re-encoding each value from the spec.

    `order_by` is passed in rather than read from the export on purpose: the ORDER BY is part of
    the identity, and a reader that took it from the same file it is checking could not detect an
    export written under a different ordering.

    The counting, chaining and result shape are `encoding_v1.fingerprint()`'s — this file only
    adapts stored values into spec values. An earlier version re-implemented all three around a
    `zip()`, which silently TRUNCATES on a row/type length mismatch where `encode_row()` raises.
    """
    man = _manifest(conn, table)
    columns = [(name, declared, bool(nullable)) for name, declared, nullable, _ in man]

    # Per-column facts, resolved ONCE instead of once per cell — see _value(). E.kind() raising
    # on a type the spec has no rule for happens HERE as a side effect, which is where it belongs:
    # before a single row is read, rather than partway through a chain that has already consumed
    # half the export.
    percol = [(E.kind(declared) == "str", bool(w), f"{_text(table)}.{name}")
              for name, declared, _nullable, w in man]

    quoted = ", ".join('"' + n.replace('"', '""') + '"' for n, _, _ in columns)
    cur = conn.execute(f'SELECT {quoted} FROM "{table}" ORDER BY "{order_by}"')

    # zip() truncating on a length mismatch is caught downstream, not here: a short row reaches
    # encode_row() with fewer values than declared types, which raises.
    rows = (
        [_value(v, is_str, is_wide, label) for v, (is_str, is_wide, label) in zip(row, percol)]
        for row in cur
    )
    return E.fingerprint(rows, columns, order_by)


def open_export(path):
    conn = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
    conn.text_factory = bytes      # never let sqlite3 decode a BLOB or TEXT into str for us
    spec = conn.execute("SELECT v FROM _meta WHERE k = 'spec'").fetchone()
    if not spec:
        raise ValueError("the export declares no spec version")
    got = spec[0].decode() if isinstance(spec[0], bytes) else spec[0]
    if got != E.SPEC_VERSION:
        raise ValueError(f"export was written under {got}, this reader implements {E.SPEC_VERSION}")
    return conn
