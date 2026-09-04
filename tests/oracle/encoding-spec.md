# ENCODING_V1 — the canonical row encoding behind fingerprint v2

Two independent implementations must agree byte for byte:

| side | file | reads from |
|---|---|---|
| PHP | `tests/bench/lib/fingerprint-v2.php` | MySQL, via `HEX()` in SQL |
| Python | `tests/oracle/encoding_v1.py` | the SQLite export, via typed values |

They are written from **this document**, not from each other. That is the point: S3's claim is
that the SQLite export preserved the VALUES, and it can only mean that if the two encoders
arrived at the same bytes independently. `tests/oracle/golden-encoding-fixtures.json` holds
hand-computed expected bytes and hashes; both implementations must reproduce every one.

## Why a fingerprint at all

A campaign that compares two arms must first prove the two arms were given the **same rows**.
Without it, every difference downstream has a second explanation nobody can rule out. The
existing `COUNT(*) + SUM(CRC32(CONCAT_WS(…)))` probe cannot carry that claim:

- `CONCAT_WS` **skips NULLs silently.** A NULL→`''` migration is invisible to it — and NULL vs
  `''` is exactly what R16 turned on.
- `SUM()` is **order-invariant and additive**: two rows swapping values can leave the sum
  unchanged, and CRC32 collisions are reachable at 32 bits over a 443k-row corpus.

So the probe stays as a cheap in-loop check that may **raise** an alarm and may never **clear**
identity. Only the strong layer below clears.

## Field encoding

Each column produces one token. Tokens are ASCII.

| case | token | why |
|---|---|---|
| SQL `NULL` (any type) | `\NUL` | distinct from empty string — the distinction the probe layer loses |
| empty string `''` | `\EMPTY` | distinct from NULL, and self-describing when a human reads the encoding |
| integer types | `HEX(CAST(col AS CHAR))` — hex of the **decimal rendering** | `HEX()` is not one function: given a number it renders the value in base 16 (`HEX(255)`=`'FF'`), given a string it renders that string's bytes (`HEX('255')`=`'323535'`). Casting to CHAR first fixes which one is meant. Display width is irrelevant to the value, which matters because MySQL 8.0.19 dropped it |
| string types | `HEX(CAST(col AS BINARY))` | the explicit `BINARY` cast is what makes it collation- and charset-transform-proof; without it the server may transcode between the connection and column charsets |

**Integer types in the pinned set:** `INT`, `INT UNSIGNED`, `SMALLINT`, `SMALLINT UNSIGNED`,
`TINYINT UNSIGNED`, `BIGINT UNSIGNED`.
**String types in the pinned set:** `VARCHAR`.

**Known and deliberate: integer `0` and the string `'0'` encode identically**, both to the
token `2:30`. The row encoding does not separate them; the **manifest** does, because the
declared types differ and the manifest hash is compared alongside. Written down here, and
pinned by a golden fixture, so it is a recorded property rather than something discovered
later and mistaken for a defect.

**There are no other types in the pinned set**, and the encoders **must fail loudly** on one
they do not recognise rather than falling through to a default. A silent fallback is how a
column's meaning changes without any hash changing. Both `slim_stats` and `slim_events` are
integers and varchars only: `dt`/`dt_out` are epoch `INT UNSIGNED`, not `DATETIME`, so no
session-timezone pin is needed; there are no `DECIMAL`/`FLOAT` columns, so the float-formatting
hazard does not arise; and `vid_hash` (`BINARY`) is **v6-added and therefore excluded by
pinning**. Rules for those types are deliberately **not** written here — a rule nothing
exercises is a rule nobody has tested, and this file would rather be short and true.

**Type resolution is a pure function of the declared type alone.** Which rule a column takes
depends on its `SHOW COLUMNS` type and on nothing else — not the cell's value, not the row, not
the connection charset or session state. This is stated because both encoders now *depend* on it:
each memoises the type→rule lookup, and both callers hoist it out of the row loop
(`export-snapshot.php` per column before writing, `read_export.py` per column before reading).
A future rule that inspected anything beyond the declared type would silently invalidate four
optimisations at once, so such a rule may not be added without removing them first.

## Row encoding

Each token is length-prefixed and the fields are joined:

```
field  := <byte-length of token> ":" <token>
row    := field ( "|" field )*
```

`|` and `:` are safe separators because they cannot occur in hex output (`[0-9A-F]`) nor in
`\NUL` / `\EMPTY`. The length prefix means no token can be confused with a boundary even if a
future token type were to contain one.

## The chain

```
h := SHA256( b"ENCODING_V1" )                 # 32 raw bytes, not hex
for each row, in the declared ORDER BY:
    h := SHA256( h || utf8(row_encoding) )
fingerprint := lowercase hex of final h
```

Seeding with the literal `ENCODING_V1` means a future `ENCODING_V2` changes every hash rather
than silently producing comparable-looking numbers under different rules.

The chain is **order-dependent**, so the ORDER BY is part of the identity, not an
implementation detail:

- `slim_stats` and `slim_events` have a unique auto-increment key — order by it (`id`,
  `event_id`).
- A table without one is ordered by the **full pinned tuple**, every encoded column in manifest
  order. That is a total order by construction: two rows identical in every pinned column are
  interchangeable in the chain, so any tie between them cannot change the result.

## The column manifest hash — schema identity, separately

Data identity and schema identity are different claims and are computed separately, so a
migration that adds a column does not read as a data change:

```
manifest_line := name "|" declared_type "|" ("NULL" | "NOT NULL")
manifest      := manifest_line ( "\n" manifest_line )* "\n" "ORDER BY " <order-by clause>
manifest_hash := lowercase hex of SHA256( utf8(manifest) )
```

Taken from `SHOW COLUMNS` over the **pinned** columns only. Migration-added columns are
excluded by pinning; the pinned set's own types cannot change silently, because a type change
moves `manifest_hash` even when every value is untouched. **The ORDER BY is inside the manifest
hash** — a table re-ordered by a different rule must read as a different fingerprint rather
than as the same one.

**`declared_type` is CANONICALISED first, and this is not cosmetic.** MySQL 8.0.19 removed
integer display width, so one column reports `int unsigned` on 8.x and `int(10) unsigned` on the
5.6 floor. `run-rollup-floor.sh` runs one corpus across 8.0, 5.7 and 5.6 and asserts the
fingerprints match — hashing the raw `SHOW COLUMNS` string would make every pinned integer
column report schema drift between servers holding an identical schema, at exactly the
comparison this hash exists to make. So:

- **integers** — display width is DROPPED (`int(10) unsigned` → `int unsigned`);
- **strings** — declared length is KEPT (`varchar(39)` ≠ `varchar(45)`), because a narrowed
  varchar is real data loss and must move the hash;
- the whole string is lowercased and its internal whitespace collapsed.

This is the rule `src/Schema/Schema.php::charLength()` already documents and applies, for the
same reason and in this programme's own words: *"a normaliser that is right on one server and
wrong on the other … is a second parser disagreeing with the first, which is this programme's
most repeated defect."* Golden fixtures pin both halves — four spelling pairs that must
canonicalise equal, one varchar pair that must not.

**The pinned column set itself is NOT decided here, and that is an open obligation.** The list
in this document is prose; the encoders take a caller-supplied array; and no caller exists yet.
The tree already has a single source of schema truth — `Schema::columns()`, gated by
`tests/schema-single-source-test.php` — and the first caller **must derive the pinned set from
it** (minus the v6-added `vid_hash` / `ua_id`, named as an explicit exclusion in one place)
rather than hand-transcribing a fourth column list. `run-rollup-floor.sh` is already the third.
S3 owns this.

## What this fingerprint does NOT establish

- **Not a cross-charset logical comparison.** `CAST(col AS BINARY)` hashes the STORED bytes, so
  the same text in a `utf8mb3` table and a `utf8mb4` table hashes differently. That is correct
  for this campaign — both arms hydrate from one dump into one schema, so a charset difference
  between them would be a real difference in what they were given — but it means the number
  must never be used to compare corpora across schemas. (The live install is `utf8mb3_general_ci`
  while the bench containers run `utf8mb4`; those are not comparable by this measure and are
  not meant to be.)
- **Not a claim about unpinned columns.** By construction.
- **Not a substitute for the probe layer**, which is cheap enough to run in-loop. The two answer
  different questions: the probe asks "did anything obviously move", the chain asks "are these
  the same rows".
- **Not valid under `NO_BACKSLASH_ESCAPES`.** The `\NUL` / `\EMPTY` sentinels reach the server as
  SQL literals containing a backslash, and MySQL resolves `'\\NUL'` to `\NUL` only while that
  mode is off; with it on the server yields `\\NUL` — five characters, not four — so both the
  token and its length prefix diverge from the PHP encoder, silently. This is the scheme's only
  `sql_mode` dependency and it is the price of self-describing tokens over pure length-prefixing
  (`-1:` for NULL, `0:` for empty, which would be immune but unreadable).
  `slimstat_fp2_table_fingerprint()` asserts the mode is off and refuses rather than hashing
  through it.
- **The SQL path is NOT YET PROVEN against the PHP path.** The golden fixtures exercise the
  pure-PHP encoder; the generated SQL is checked only at the shape level, which asserts that a
  builder emitted the literals it was written to emit and cannot fail for anything that matters
  (`HEX`/`CAST` semantics, `sql_mode`, connection charset, `CHAR_LENGTH` vs `strlen`). The real
  gate feeds the same adversarial rows through both paths in a container and compares bytes.
  **S3 owns it. Until it lands, no fingerprint taken through the SQL path should be cited.**

## Where the fixtures live

`tests/oracle/golden-encoding-fixtures.json`, beside the spec and both encoders, rather than in
`tests/fixtures/golden/` where this repo's other golden data sits. The reason is that this file
is not only expected values: it also carries the **type→kind map** and the **must-raise list**
that both encoders read at runtime in their gates. That table is the one part of the design the
two implementations must agree on which a value-based fixture cannot see — add `float` to one
language's accept-list alone and every value assertion still passes — so it belongs in the
shared artifact, next to the document that defines it.
