"""ENCODING_V1 — the Python half, written from tests/oracle/encoding-spec.md.

This side reads TYPED VALUES out of the SQLite export and encodes them itself. It deliberately
does not call, import, or mirror the PHP implementation: S3's claim is that the export
preserved the values, and that claim only means something if two encoders written from the spec
arrive at the same bytes. If this file were derived from the other one, S3 would prove that a
token survived a copy — not that the data did.

Both implementations are held to tests/oracle/golden-encoding-fixtures.json, whose tokens were
hand-derived from the spec rather than blessed from either encoder's output.
"""
import hashlib
import re

SPEC_VERSION = "ENCODING_V1"
NULL_TOKEN = r"\NUL"
EMPTY_TOKEN = r"\EMPTY"

# The pinned set is integers and varchars. Anything else must RAISE rather than fall through to
# a default — a silent fallback is how a column's meaning changes without any hash changing.
#
# These lists are EXACTLY the spec's pinned set and no wider. An earlier version also accepted
# mediumint/integer/char/text, which the spec does not authorise, no fixture exercises, and the
# real schema does not declare — widening the accept-list widens the only safety property this
# function has.
_INT_TYPES = ("tinyint", "smallint", "int", "bigint")
_STR_TYPES = ("varchar",)


def kind(declared_type):
    """Map a SHOW COLUMNS type to 'int' or 'str'. Raises on anything else, by design.

    Public: read_export.py needs it, and reaching across a module boundary for a private
    name was the only such call in tests/oracle/.
    """
    base = declared_type.strip().lower().split("(")[0].split()[0]
    if base in _INT_TYPES:
        return "int"
    if base in _STR_TYPES:
        return "str"
    raise ValueError(
        f"ENCODING_V1 has no rule for type {declared_type!r}. This is deliberate: the pinned "
        f"set is integers and varchars only. Adding a rule means adding a golden fixture that "
        f"exercises it — an unexercised branch is one nobody has tested."
    )


def encode_field(value, declared_type):
    """One column -> one token. See the field-encoding table in the spec."""
    kind_of = kind(declared_type)          # validate the type even when the value is NULL
    if value is None:
        return NULL_TOKEN
    if kind_of == "int":
        if isinstance(value, bool):      # bool is an int subclass in Python; MySQL has no bool
            raise ValueError("refusing to encode a Python bool as an integer column")
        if isinstance(value, (bytes, bytearray)):
            value = value.decode("ascii")
        return format(int(value), "d").encode("ascii").hex().upper()
    # Strings: hash the STORED BYTES, never a re-encoded rendering.
    raw = value if isinstance(value, (bytes, bytearray)) else str(value).encode("utf-8")
    if len(raw) == 0:
        return EMPTY_TOKEN
    return bytes(raw).hex().upper()


def encode_row(values, declared_types):
    """Length-prefixed fields joined by '|'."""
    if len(values) != len(declared_types):
        raise ValueError(f"{len(values)} values against {len(declared_types)} declared types")
    parts = []
    for value, declared in zip(values, declared_types):
        token = encode_field(value, declared)
        # len(token) IS the byte length: every token this can produce is ASCII by construction
        # (\NUL, \EMPTY, or hex in [0-9A-F]). Encoding it first only built and discarded a bytes
        # copy to measure a number already in hand — 30 pinned columns over 443k rows is 13.3M
        # throwaway allocations. The PHP side already used strlen(); the golden fixtures pin the
        # prefix values, so the gate proves the two forms agree.
        parts.append(f"{len(token)}:{token}")
    return "|".join(parts)


def chain(row_encodings):
    """h := SHA256(h || row); seeded with the spec version so ENCODING_V2 cannot collide."""
    h = hashlib.sha256(SPEC_VERSION.encode("ascii")).digest()
    for row in row_encodings:
        h = hashlib.sha256(h + row.encode("utf-8")).digest()
    return h.hex()


def canonical_type(declared_type):
    """Canonicalise a declared type for the manifest hash.

    MySQL 8.0.19 removed integer display width, so the same column reports 'int unsigned' on
    8.x and 'int(10) unsigned' on the 5.6 floor. Hashing the raw SHOW COLUMNS string would make
    the SCHEMA half of the fingerprint read as drift between two servers holding an identical
    schema — and run-rollup-floor.sh runs one corpus across 8.0, 5.7 and 5.6, so every pinned
    integer column would trip it.

    This is the rule src/Schema/Schema.php::charLength() already documents for the same reason:
    char/varchar keep their declared length on every supported server, integer display widths
    do not. Width is dropped for integers and KEPT for strings, where a narrowed column is real
    data loss and must move the hash.
    """
    t = " ".join(str(declared_type).split()).strip().lower()
    if kind(t) == "int":
        t = re.sub(r"\s*\(\s*\d+\s*\)", "", t)
    return t


def manifest_hash(columns, order_by):
    """Schema identity, separate from data identity. `columns` is (name, type, nullable)."""
    lines = [f"{n}|{canonical_type(t)}|{'NULL' if nullable else 'NOT NULL'}"
             for n, t, nullable in columns]
    text = "\n".join(lines) + "\n" + "ORDER BY " + order_by
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def fingerprint(rows, columns, order_by):
    """The pair a caller actually wants: data identity and schema identity, together.

    `rows` may be any iterable, INCLUDING a live sqlite3 cursor. An earlier version built every
    row encoding into a list before chaining — ~290 MB of strings held at once over the 443k
    export, for a list chain() only iterates — and took len(rows), which forced the caller to
    fetchall() the whole export first and doubled the peak. Counting during the chain keeps it
    O(1) in encodings and lets the caller stream.
    """
    declared = [t for _, t, _ in columns]
    counted = 0

    def encoded():
        nonlocal counted
        for row in rows:
            counted += 1
            yield encode_row(row, declared)

    chained = chain(encoded())          # chain() drains the generator before counted is read
    return {
        "rows": counted,
        "chained_hash": chained,
        "manifest_hash": manifest_hash(columns, order_by),
        "spec": SPEC_VERSION,
    }
