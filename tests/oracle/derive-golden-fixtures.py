#!/usr/bin/env python3
"""Derive the golden encoding fixtures from ENCODING_V1 BY HAND.

The tokens below are typed out from the spec text, not produced by either encoder — that is
what makes the fixture an independent oracle rather than a photograph of whatever the code
happened to do. Only SHA-256 is mechanical, because nobody computes that by hand.

Every hex string here is checkable by inspection: 'abc' is 61 62 63 in ASCII, and so on.
"""
import hashlib, json

def chain(rows):
    h = hashlib.sha256(b"ENCODING_V1").digest()
    for r in rows:
        h = hashlib.sha256(h + r.encode("utf-8")).digest()
    return h.hex()

def manifest_hash(lines, order_by):
    text = "\n".join(lines) + "\n" + "ORDER BY " + order_by
    return hashlib.sha256(text.encode("utf-8")).hexdigest()

CASES = []

# 1. NULL is not the empty string. The distinction the CRC32 probe layer loses entirely.
CASES.append({
    "name": "null_is_not_empty_string",
    "why": "CONCAT_WS skips NULLs silently, so a NULL -> '' migration is invisible to the probe layer. Here the two produce different tokens of different lengths.",
    "columns": [
        {"name": "ip",       "type": "varchar(39)", "nullable": True, "value": None},
        {"name": "other_ip", "type": "varchar(39)", "nullable": True, "value": ""},
    ],
    # \NUL is 4 bytes; \EMPTY is 6.
    "row_encoding": r"4:\NUL|6:\EMPTY",
})

# 2. Integer 0 and the string '0' encode IDENTICALLY. Stated so it cannot surprise later.
CASES.append({
    "name": "int_zero_and_string_zero_collide_by_design",
    "why": "Both render as the byte 0x30. The row encoding does NOT separate them; the MANIFEST does, because the declared type differs. Recorded as a known property, not discovered later as a bug.",
    "columns": [
        {"name": "visit_id",        "type": "int unsigned", "nullable": False, "value": 0},
        {"name": "browser_version", "type": "varchar(15)",  "nullable": True,  "value": "0"},
    ],
    # int 0   -> CAST AS CHAR -> "0" -> HEX -> "30"
    # str '0' -> CAST AS BINARY -> byte 0x30 -> HEX -> "30"
    "row_encoding": "2:30|2:30",
})

# 3. 4-byte UTF-8 survives. 'é' = C3 A9 (2 bytes), '𝄞' U+1D11E = F0 9D 84 9E (4 bytes).
CASES.append({
    "name": "utf8mb4_four_byte_codepoint",
    "why": "A 4-byte codepoint truncated to 3 bytes by a utf8mb3 column is a real data-loss mode; the encoding must show it.",
    "columns": [
        {"name": "city", "type": "varchar(256)", "nullable": True, "value": "é\U0001D11E"},
    ],
    "row_encoding": "12:C3A9F09D849E",
})

# 4. Leading zeros and a trailing space both survive: '007' and 'x '.
CASES.append({
    "name": "leading_zeros_and_trailing_space",
    "why": "'007' compared as a number is 7, and MySQL's non-binary comparison ignores trailing spaces. HEX(CAST(.. AS BINARY)) keeps both.",
    "columns": [
        {"name": "browser_version", "type": "varchar(15)", "nullable": True, "value": "007"},
        {"name": "platform",        "type": "varchar(15)", "nullable": True, "value": "x "},
    ],
    # '0'=30 '0'=30 '7'=37  ;  'x'=78 ' '=20
    "row_encoding": "6:303037|4:7820",
})

# 5. Negative and max-width integers.
CASES.append({
    "name": "negative_and_max_unsigned_int",
    "why": "HEX(-720) on a NUMBER would render a base-16 value; casting to CHAR first is what makes the minus sign survive. The BIGINT UNSIGNED max is the width boundary.",
    "columns": [
        {"name": "tz_offset",  "type": "smallint",       "nullable": True, "value": -720},
        {"name": "content_id", "type": "bigint unsigned","nullable": True, "value": 18446744073709551615},
    ],
    # "-720" = 2D 37 32 30
    # "18446744073709551615" = the ASCII digits, 20 chars -> 40 hex chars
    "row_encoding": "8:2D373230|40:3138343436373434303733373039353531363135",
})

# 6. A NULL integer is the same token as a NULL string — NULL has no type.
CASES.append({
    "name": "null_integer_same_token_as_null_string",
    "why": "NULL is typeless in the row encoding; the manifest carries the type. Pins that an integer column going NULL is not confused with 0.",
    "columns": [
        {"name": "dt_out",   "type": "int unsigned", "nullable": True, "value": None},
        {"name": "referer",  "type": "varchar(2048)","nullable": True, "value": None},
    ],
    "row_encoding": r"4:\NUL|4:\NUL",
})

# 7. TINYINT UNSIGNED is in the pinned set (browser_type, slim_events.type) and had no case,
#    so one of the five accepted type names was an untested branch.
CASES.append({
    "name": "tinyint_unsigned_is_pinned_and_now_exercised",
    "why": "browser_type and slim_events.type are TINYINT UNSIGNED. The spec pins the type; no fixture reached it, so by this file's own standard it was a branch nobody had tested.",
    "columns": [
        {"name": "browser_type", "type": "tinyint unsigned", "nullable": True, "value": 0},
        {"name": "type",         "type": "tinyint unsigned", "nullable": True, "value": 255},
    ],
    # 0 -> "0" -> 30 ; 255 -> "255" -> 32 35 35
    "row_encoding": "2:30|6:323535",
})

for c in CASES:
    c["chained_hash_single_row"] = chain([c["row_encoding"]])

# A multi-row chain, to pin ORDER DEPENDENCE: same rows, different order, different hash.
ROWS_A = [c["row_encoding"] for c in CASES]
ROWS_B = list(reversed(ROWS_A))
order_case = {
    "name": "chain_is_order_dependent",
    "why": "The chain is the identity, so the ORDER BY is part of it. Reversing the same rows must change the hash — otherwise a table re-ordered by a different rule would read as identical.",
    "rows_in_order": ROWS_A,
    "chained_hash": chain(ROWS_A),
    "chained_hash_reversed": chain(ROWS_B),
}
assert order_case["chained_hash"] != order_case["chained_hash_reversed"], "order dependence is vacuous"

# Empty table: the chain is the seed alone, and is NOT the hash of an empty string.
empty_case = {
    "name": "empty_table_is_the_seed_alone",
    "why": "An empty table must hash to something specific, not to nothing. Pins that a wiped corpus cannot be mistaken for an unhydrated one that never ran.",
    "rows_in_order": [],
    "chained_hash": chain([]),
    "sha256_of_empty_string_for_contrast": hashlib.sha256(b"").hexdigest(),
}
assert empty_case["chained_hash"] != empty_case["sha256_of_empty_string_for_contrast"]

MANIFEST = {
    "name": "manifest_hash_covers_type_and_order_by",
    "why": "Schema identity is a separate claim from data identity. A type change must move this hash even when every value is untouched, and the ORDER BY is inside it.",
    "lines": [
        "id|int unsigned|NOT NULL",
        "ip|varchar(39)|NULL",
        "dt|int unsigned|NULL",
    ],
    "order_by": "id",
}
MANIFEST["manifest_hash"] = manifest_hash(MANIFEST["lines"], MANIFEST["order_by"])
# Same columns, one type widened -> different hash.
MANIFEST["manifest_hash_if_ip_widened_to_varchar_45"] = manifest_hash(
    ["id|int unsigned|NOT NULL", "ip|varchar(45)|NULL", "dt|int unsigned|NULL"], "id")
# Same columns, different ORDER BY -> different hash.
MANIFEST["manifest_hash_if_ordered_by_dt"] = manifest_hash(MANIFEST["lines"], "dt")
assert len({MANIFEST["manifest_hash"],
            MANIFEST["manifest_hash_if_ip_widened_to_varchar_45"],
            MANIFEST["manifest_hash_if_ordered_by_dt"]}) == 3, "manifest hash is not discriminating"

# The type table lives HERE, in the artifact both encoders parse, rather than as a constant
# copied into each language. It is the only part of the design the two implementations must
# agree on that a value-based fixture cannot see: add `float` to the PHP list alone and every
# gate stays green, which is the "silent fallback changes a column's meaning" failure the raise
# exists to prevent, relocated one level up.
TYPE_KINDS = {
    "tinyint unsigned": "int",
    "smallint": "int",
    "smallint unsigned": "int",
    "int": "int",
    "int unsigned": "int",
    "bigint unsigned": "int",
    "varchar(39)": "str",
    "varchar(2048)": "str",
}
# Types the spec deliberately writes no rule for. Both encoders MUST raise on each.
TYPES_THAT_MUST_RAISE = [
    "double", "float", "decimal(10,2)", "datetime", "timestamp",
    "binary(16)", "varbinary(64)", "text", "mediumint", "integer", "char(2)", "enum('a','b')",
]

# Integer DISPLAY WIDTH is not part of schema identity: MySQL 8.0.19 removed it, so one schema
# reports two spellings across the 5.6/5.7/8.0 cells run-rollup-floor.sh compares. String length
# IS part of it — a narrowed varchar is data loss.
CANONICAL_TYPE = {
    "$why": "int display width dropped (8.0.19 removed it; the 5.6 floor still prints it); varchar length kept.",
    "same_after_canonicalisation": [["int(10) unsigned", "int unsigned"],
                                    ["INT UNSIGNED", "int unsigned"],
                                    ["smallint(6)", "smallint"],
                                    ["bigint(20) unsigned", "bigint unsigned"]],
    "different_after_canonicalisation": [["varchar(39)", "varchar(45)"]],
}

out = {
    "$spec": "ENCODING_V1 — tests/oracle/encoding-spec.md",
    "$provenance": "Tokens hand-derived from the spec text; SHA-256 computed mechanically. NOT generated by either encoder — a fixture blessed from an implementation's output only proves the implementation is self-consistent.",
    "$seed": "SHA256(b'ENCODING_V1') seeds every chain",
    "field_cases": CASES,
    "order_dependence": order_case,
    "empty_table": empty_case,
    "manifest": MANIFEST,
    "type_kinds": TYPE_KINDS,
    "types_that_must_raise": TYPES_THAT_MUST_RAISE,
    "canonical_type": CANONICAL_TYPE,
    "expected_assertions": {
        "$why": "A counter nothing checks is decoration. Without this, shrinking field_cases to one entry — or to zero — leaves both gates printing PASS. The two sides differ by exactly the 3 SQL-shape checks, which have no Python analogue.",
        "python": 61,
        "php": 63,
    },
}
print(json.dumps(out, indent=2, ensure_ascii=False))
