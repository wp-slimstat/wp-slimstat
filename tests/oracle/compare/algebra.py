"""ENCODING-of-answers, so to speak: THE differ. One implementation, two consumers.

The campaign compares three answers per report — OLD plugin, NEW plugin, and an independent
ORACLE that recomputes from raw rows — and every difference has to land in exactly one class.
Both the oracle families and `tests/oracle/classify.py` ask "do these two answers differ?", and
they ask it HERE, deliberately: two implementations of that question is the common-mode fork
this campaign warns about in its own risk table, and it is PITFALLS 5 in this workspace's own
history (the mutation runner and the mutation registry each parsed `.mutation` files; they had
drifted before either landed, and the drift produced a confident SURVIVED against working code).
A tolerance that lives in one file cannot fork between the two sides of the comparison.

WHAT THIS FILE DOES NOT DO, and it is the more important half:

  * It does not decide the answer CLASS. `error` (the query or call failed), `empty` (it ran and
    returned no rows) and `zero` (it ran and returned the number 0) are three different answers,
    and the line between them is drawn ONCE, by `slimstat_capture()` in
    tests/docker/report-answers.php, gated by tests/answer-envelope-classes-test.php (17
    assertions) and pinned by the mutation `S1-error-reads-as-empty-01`. This file READS
    `env['class']` and refuses an envelope that has none. Re-deriving the class from the value
    here would be a second parser of the one contract the campaign rests on — the exact thing
    PITFALLS 5 records, and the exact conflation PITFALLS 38 cost: two reports that compared
    equal in every arm of every run because neither had ever executed.
  * It does not decide (a)/(b)/(c)/(d). That is classify.py, which consumes these verdicts.

FOUR VERDICTS, not a boolean:

    EQUAL         the two answers are the same answer under the stated rule
    ORDER_ONLY    the same rows in a different sequence — a DISTINCT outcome, never folded into
                  either EQUAL or DIFFER, because "same rows, different order" is a real finding
                  with a different owner (collation, ORDER BY tie-break, server version) than a
                  value change, and A/A treats it as a FAIL while OLD-vs-NEW does not
    DIFFER        the answers differ in value
    INCOMPARABLE  no comparison was possible, or the comparison that was possible could not have
                  failed. An `error` against a value is INCOMPARABLE; so is a LIMIT report whose
                  every row ties at one count, because any N of the tied rows is a legal answer
                  and two disjoint answers would then read as agreement. PITFALLS 38 and 44 are
                  both the same sentence: a comparison that cannot fail is not a pass.

Every function that decides something states its RULE by name in the returned Diff, so a reader
of a scorecard line can ask "which rule decided this?" and get an answer that is not "the code".

Python 3.x, standard library only, no third-party imports — same constraint as encoding_v1.py,
for the same reason: this runs in a bench container that installs python3 and nothing else.
"""

import decimal
import json
import re
from collections import Counter
from dataclasses import dataclass, field

# ── The verdict vocabulary ──────────────────────────────────────────────────────────────────
EQUAL = "EQUAL"
ORDER_ONLY = "ORDER_ONLY"
DIFFER = "DIFFER"
INCOMPARABLE = "INCOMPARABLE"
VERDICTS = (EQUAL, ORDER_ONLY, DIFFER, INCOMPARABLE)

# The capture's own five classes, spelled exactly as slimstat_capture() and the `$unsupported`
# closure emit them (tests/docker/report-answers.php). This tuple is a CONSUMER of that
# vocabulary, not a second definition of it: adding a sixth here would not create one there.
CAPTURE_CLASSES = ("ok", "empty", "zero", "error", "unsupported")

# The oracle's one extra class. The oracle is the side this repo's Python constructs, and it
# needs a way to say "I have no model for this surface" that can never be mistaken for
# agreement — classify.py maps it to (d)-unmodeled. There is no capture-side spelling of this
# because an arm always answers something, even if the answer is an error.
ORACLE_ONLY_CLASSES = ("unmodeled",)

ANSWER_CLASSES = CAPTURE_CLASSES + ORACLE_ONLY_CLASSES

# The three values slimstat_capture() treats as `empty`, listed here so check_envelope() can
# refuse an envelope whose class and value disagree. Copied deliberately as a CHECK of the other
# side's branch, never as a re-implementation of it: nothing here classifies, it only refuses.
EMPTY_VALUES = ([], None, "")

# A contract's defaults. S5 will supply real ones per report (`report-contracts.json`); until it
# does, every caller passes what it knows and inherits the rest.
#
#   places        decimal places the answer is EMITTED at, or None for exact comparison
#   limit         the report's LIMIT, or None. Enables the tie-at-the-cut rule and nothing else
#   count_field   the column the LIMIT ranks on
#   cut_side      'min' for a DESC report (the cut is the smallest count), 'max' for ASC
#   exact_columns columns compared as bytes even when both sides parse as numbers — the escape
#                 hatch for a zero-padded code ('007' and 7 are equal as numbers and different
#                 as answers)
DEFAULT_CONTRACT = {
    "places": None,
    "limit": None,
    "count_field": "counthits",
    "cut_side": "min",
    "exact_columns": (),
}


@dataclass(frozen=True)
class Diff:
    """What differed, WHERE, and which rule decided it.

    Deliberately not a bool. `if compare(a, b):` would read DIFFER as true and EQUAL as true —
    both objects are truthy — so the most natural misuse is also the most silent one. __bool__
    raises instead: the caller must name the verdict it means. This is the same argument the
    campaign makes about `exit 0` (PITFALLS 46) and about empty-compares-equal (PITFALLS 38) —
    an answer that reads as success by default eventually will.
    """

    verdict: str
    rule: str
    where: str
    detail: str
    parts: dict = field(default_factory=dict)

    def __post_init__(self):
        if self.verdict not in VERDICTS:
            raise ValueError("verdict %r is not one of %r" % (self.verdict, VERDICTS))

    def __bool__(self):
        raise TypeError(
            "a Diff is not a boolean — check .verdict against algebra.EQUAL / ORDER_ONLY / "
            "DIFFER / INCOMPARABLE. Truthiness would silently read DIFFER as agreement."
        )

    def as_dict(self):
        return {
            "verdict": self.verdict,
            "rule": self.rule,
            "where": self.where,
            "detail": self.detail,
            "parts": dict(self.parts),
        }


# ── The edges: every rule name a Diff may carry, DECLARED rather than derived ────────────────
#
# Two consumers depend on this tuple, and it exists because of what happened without it.
#
#   * `_diff()` below refuses a rule name that is not declared here, so an edge cannot be added
#     to this file without appearing in this list;
#   * tests/oracle/classify_test.py asserts that a full gate run CONSTRUCTS every name in it, so
#     an edge no plant and no direct assertion reaches turns the gate RED.
#
# WHY IT IS WRITTEN OUT BY HAND rather than scraped from this file's own source. A coverage
# check that derives its own subject set derives the set that is already satisfied — PITFALLS 32,
# where a scan computed its own denominator and reported full coverage of the files it could
# see. Scraping `_diff(..., "name"` would do exactly that: delete a call site and its name leaves
# the denominator with it, so the check goes green by losing its subject.
#
# WHY IT EXISTS AT ALL. plants/README.md claimed "one plant per edge in compare/algebra.py" for
# the whole of S4. Run 53's review measured it: 11 of 19 edges were reached by nothing at all,
# including BOTH halves of the tie tolerance's only rail (`cut-value-differs` and
# `above-the-cut-differs`), either of which could be flipped to EQUAL with the gate staying
# green. The claim was prose; this is the mechanism (PITFALLS 64).
DIFF_RULES = (
    # compare_scalar
    "both-null",
    "null-vs-value",
    "numeric-exact",
    "numeric-at-N-places",
    "shape-differs",
    "string-exact",
    # compare_rows
    "rows-equal",
    "rows-reordered",
    "row-count",
    "row-values",
    # compare_banded
    "cut-value-differs",
    "above-the-cut-differs",
    "whole-result-is-one-tie-band",
    "tie-at-the-cut",
    # compare_envelopes
    "class-mismatch",
    "both-error",
    "both-unsupported",
    "both-unmodeled",
    "both-hollow",
    "both-zero",
)

_NUMERIC_AT_PLACES = re.compile(r"^numeric-at-\d+-places$")


def rule_family(rule):
    """The DECLARED name of a rule whose spelling carries a parameter.

    `numeric-at-2-places` and `numeric-at-4-places` are one edge at two precisions; the digit
    stays in the emitted name so a scorecard line says which precision decided the comparison.
    Both consumers of DIFF_RULES — the refusal in `_diff()` and the coverage loop in the gate —
    normalise through this one function, because two spellings of "which rule is this" is the
    same fork this whole file exists to prevent one level down (PITFALLS 5).
    """
    return _NUMERIC_AT_PLACES.sub("numeric-at-N-places", rule)


def _diff(verdict, rule, where, detail, **parts):
    if rule_family(rule) not in DIFF_RULES:
        raise ValueError(
            "rule %r is not declared in DIFF_RULES. Every edge this differ can take is listed "
            "there, and the gate asserts each one is reached by something — an undeclared rule "
            "would be an edge with no possible coverage, which is what the declaration exists "
            "to make impossible." % (rule,))
    return Diff(verdict=verdict, rule=rule, where=where, detail=detail, parts=parts)


def contract_with_defaults(contract):
    """Fill a partial contract, refusing keys nobody defined.

    An unknown key is a typo that would otherwise be ignored in silence — `{'limit_': 20}` would
    disable the tie rule while reading, to a human, as if it enabled it. PITFALLS 26's corollary
    in one line: a tool that silently ignores an argument it does not understand is how a whole
    run measured the wrong thing and said so confidently.
    """
    merged = dict(DEFAULT_CONTRACT)
    if contract:
        unknown = sorted(set(contract) - set(DEFAULT_CONTRACT))
        if unknown:
            raise ValueError(
                "contract carries key(s) %r that this differ does not read; every rule here "
                "takes its parameters from DEFAULT_CONTRACT's keys only" % (unknown,)
            )
        merged.update(contract)
    if merged["cut_side"] not in ("min", "max"):
        raise ValueError("cut_side must be 'min' or 'max', not %r" % (merged["cut_side"],))
    return merged


# ── Numbers ─────────────────────────────────────────────────────────────────────────────────


def _decimal(value):
    """A Decimal from the DECIMAL RENDERING of a value, never from a float's binary expansion.

    `Decimal(1.005)` is 1.00499999999999989341858963598497211933135986328125 — the exact binary
    double — and rounds to 1.00 at two places. `Decimal(str(1.005))` is 1.005 and rounds to 1.01,
    which is what PHP's round() returns (measured below). The arm answers arrive as decimal
    STRINGS anyway, because wpdb returns every column as a string; it is the ORACLE side, which
    computes in Python, that can hand us a float. Taking str() of it puts both sides in the same
    notation the report was emitted in.

    Non-finite is refused rather than parsed. Decimal('NaN') and Decimal('Infinity') both parse
    happily, and NaN compares unequal to everything including itself — a value that makes every
    assertion about it false is the shape of a guard that cannot fire (PITFALLS 69).
    """
    if isinstance(value, bool):
        # bool is an int subclass in Python; MySQL has no bool, so a bool here is a caller bug
        # rather than a value. Same refusal, same reason, as encoding_v1.encode_field().
        raise ValueError("refusing to compare a Python bool as a number")
    if isinstance(value, decimal.Decimal):
        parsed = value
    elif isinstance(value, int):
        parsed = decimal.Decimal(value)
    elif isinstance(value, float):
        parsed = decimal.Decimal(str(value))
    elif isinstance(value, (bytes, bytearray)):
        parsed = decimal.Decimal(bytes(value).decode("utf-8").strip())
    elif isinstance(value, str):
        parsed = decimal.Decimal(value.strip())
    else:
        raise decimal.InvalidOperation("%r is not a number" % (value,))
    if not parsed.is_finite():
        raise decimal.InvalidOperation("refusing a non-finite value: %r" % (value,))
    return parsed


def as_number(value):
    """The Decimal for a value that IS one, or None. Never raises for a non-number."""
    if value is None or isinstance(value, bool):
        return None
    try:
        return _decimal(value)
    except (decimal.InvalidOperation, ValueError, ArithmeticError):
        return None


def round_half_up(value, places):
    """THE rounding helper. One of them, for both sides of every comparison.

    `decimal.ROUND_HALF_UP` in Python means "ties away from zero" — despite the name, it is not
    ties-toward-positive-infinity. PHP's round() is documented as ties away from zero. So the two
    agree on negatives as well as on the non-negative values these reports emit, and the caveat
    usually attached to this pairing ("identical for non-negative values") understates the
    agreement rather than overstating it.

    MEASURED, not recalled (PITFALLS 42: prose about another function's behaviour is an assertion
    nothing gates). Fifteen probes through `php -r 'round($v, $p)'` on **PHP 7.4.33** (the
    declared floor, `docker run --rm php:7.4-cli`) and on **PHP 8.5.5** (this workstation)
    against `Decimal(str(v)).quantize(..., ROUND_HALF_UP)`: -0.5, -1.5, 2.5, 1.005, -1.005,
    0.285, 1.55, 1.15, 2.675, 8.475, 1234567890.12345, and 1.005 / 0.285 / 2.675 / 8.475 again as
    JSON NUMBERS rather than strings. All fifteen agree on both interpreters, including the
    classic binary-representation traps (1.005 -> 1.01, 0.285 -> 0.29) where a naive float-based
    rounder gives the other answer. The probe table lives in tests/oracle/plants/plants.json
    under `php_round_probes` and the gate replays it, so this paragraph is a claim something
    checks rather than a claim about a claim (PITFALLS 64).

    The four repeated-as-numbers probes are not padding. Until run 53 every probe was a JSON
    STRING, so `_decimal()`'s float branch — the one the next bullet calls load-bearing — was
    never taken by the fixture, and replacing `Decimal(str(value))` with `Decimal(value)` left
    the gate green. A boundary named in a docstring and unobservable to the gate is the shape
    this file's own header warns about.

    WHERE IT STOPS BEING TRUE, stated because a rule without a boundary is a rule nobody can
    apply:
      * Construct the Decimal from a float's exact binary value instead of its rendering
        (`Decimal(1.005)`) and 1.005 rounds to 1.00 while PHP gives 1.01. `_decimal()` is why
        that does not happen here; change it and this agreement goes with it — six of the
        fifteen probes go red, which is how that sentence became checkable.
      * Beyond 2^53 a PHP float cannot represent consecutive integers, so PHP's round() answers
        about a value it never held (round(9007199254740993.0) is 9007199254740992). This helper
        is exact at any magnitude, so at that scale the two are answering different questions,
        and the answer to compare is the one MySQL emitted as a string, never a float.
      * **PHP <= 8.3 DISAGREES WITH THIS HELPER, and the earlier probes could not have shown
        it.** PHP 8.4 rewrote round(); 7.4/8.0-8.3 first pre-round the value to 15 significant
        digits, which pulls a double sitting 1-2 ULP BELOW a decimal half exactly ONTO the half
        and then rounds it away from zero. Every short decimal literal survives that pre-round
        unchanged, so a fixture made only of short literals is the one class where the two can
        never disagree — which is what all eleven original probes were.

        Settled by measurement, not by argument. 1400 boundary values were generated from the
        plugin's OWN percentage expression `round(($n / $d) * 100, $places)`
        (admin/view/wp-slimstat-db.php:2290 and :2763) for every d <= 2000, and each was run
        through `round()` on php:7.4-cli (7.4.33), php:8.2-cli (8.2.31), php:8.3-cli (8.3.33)
        and PHP 8.5.5. **144 of them disagree with this helper on 7.4, 8.2 and 8.3; none
        disagrees on 8.5.5.** Two, from ordinary two-digit funnel inputs:

            (23/160)*100 = 14.374999999999998 @2 -> PHP <= 8.3: 14.38, here: 14.37
            (23/80)*100  = 28.749999999999996 @1 -> PHP <= 8.3: 28.8,  here: 28.7

        The divergence is a FIXTURE in plants.json (`php_round_divergences`) rather than a
        sentence: the gate asserts this helper gives the 8.4+ answer AND that the recorded <=8.3
        answer differs from it, so neither half can rot silently. The consequence is owed to S5
        and is recorded here rather than guessed at: once a report contract sets `places`, an
        oracle-side value on this boundary compared against a string emitted by a pre-8.4 arm
        produces a false DIFFER. The two available fixes are to quantize the oracle the way the
        arm's interpreter did, or to compare only against the arm's emitted string; picking one
        needs the contract that does not exist yet, so what this file does today is refuse to
        claim an agreement it does not have.
    """
    if places is None:
        raise ValueError("round_half_up needs a decimal place count; None means 'do not round'")
    with decimal.localcontext() as ctx:
        # 50 digits: a BIGINT UNSIGNED renders to 20, and quantize() raises InvalidOperation
        # rather than silently losing digits when the result exceeds the context precision.
        ctx.prec = 50
        return _decimal(value).quantize(decimal.Decimal(1).scaleb(-int(places)),
                                        rounding=decimal.ROUND_HALF_UP)


# ── Scalars ─────────────────────────────────────────────────────────────────────────────────


def compare_scalar(a, b, places=None):
    """One scalar against another: int, float at the emitted precision, string, None.

    The rules, in the order they are tried:

      both-null            None on both sides is agreement. NULL is not '' and not 0 — the
                           distinction ENCODING_V1 spends two token spellings on, and the one
                           R16 turned on (`count(ip)` skipped NULLs in a function answering
                           *pages* per visit)
      null-vs-value        exactly one side NULL: a difference, always. Never "empty-ish"
      numeric              both sides parse as finite numbers: compared as decimals, rounded
                           first when the contract declares an emitted precision. This is what
                           lets wpdb's string "42" equal the oracle's int 42 — the two sides
                           compute in different languages and MySQL hands PHP strings
      shape-differs        one parses as a number and the other does not
      string               neither parses: compared as exact text, byte for byte
    """
    if a is None and b is None:
        return _diff(EQUAL, "both-null", "scalar", "both sides are NULL")
    if a is None or b is None:
        return _diff(DIFFER, "null-vs-value", "scalar",
                     "NULL on one side and a value on the other: %r vs %r — NULL is not '' and "
                     "not 0" % (a, b), a=a, b=b)

    na, nb = as_number(a), as_number(b)
    if na is not None and nb is not None:
        if places is None:
            if na == nb:
                return _diff(EQUAL, "numeric-exact", "scalar", "%s == %s" % (na, nb))
            return _diff(DIFFER, "numeric-exact", "scalar", "%s != %s" % (na, nb), a=str(na), b=str(nb))
        ra, rb = round_half_up(na, places), round_half_up(nb, places)
        if ra == rb:
            return _diff(EQUAL, "numeric-at-%d-places" % places, "scalar",
                         "%s and %s both render %s at %d places" % (na, nb, ra, places))
        return _diff(DIFFER, "numeric-at-%d-places" % places, "scalar",
                     "%s -> %s but %s -> %s at %d places" % (na, ra, nb, rb, places),
                     a=str(ra), b=str(rb))

    if (na is None) != (nb is None):
        return _diff(DIFFER, "shape-differs", "scalar",
                     "one side is a number and the other is not: %r vs %r" % (a, b), a=a, b=b)

    sa, sb = _text(a), _text(b)
    if sa == sb:
        return _diff(EQUAL, "string-exact", "scalar", "identical text")
    return _diff(DIFFER, "string-exact", "scalar", "%r != %r" % (sa, sb), a=sa, b=sb)


def _text(value):
    if isinstance(value, (bytes, bytearray)):
        return bytes(value).decode("utf-8", "surrogateescape")
    return value if isinstance(value, str) else str(value)


# ── Rows ────────────────────────────────────────────────────────────────────────────────────


def _cell(value, column, contract):
    """One cell as a canonical comparison token.

    NULL gets a token of its own (`\\NUL`, borrowed from ENCODING_V1's spelling) so it cannot
    collide with the string 'None' or with an empty string. A value that parses as a number
    normalises to its decimal form — rounded when the contract declares a precision — so a
    string from wpdb and an int from the oracle are one token. Everything else is its text.

    `exact_columns` opts a column out of the numeric normalisation. It exists because '007' and
    7 are the same number and different answers, and a differ with no escape hatch for that
    would have to choose one of the two errors for every column in every report.

    NEGATIVE ZERO IS ZERO HERE, because it is zero one function up. `Decimal('-0') ==
    Decimal('0')` is True, so compare_scalar('-0', '0') returns EQUAL on numeric-exact — but
    `normalize()` keeps the sign bit, so the token below was 'n:-0' against 'n:0' and the ROW
    path returned DIFFER for the identical pair of values. One differ giving two answers for one
    pair, decided by whether the report's answer happened to be shaped as rows or as a scalar,
    is PITFALLS 5's two-parsers-of-one-contract inside the single file written to prevent it.
    ENCODING_V1 canonicalises the same case on purpose (tests/bench/lib/fingerprint-v2.php:88,
    `-0 is 0`); half of that canonicalisation was carried across to this token and half was not.
    """
    if isinstance(value, (list, dict, tuple)):
        raise ValueError(
            "column %r holds a nested value (%r). Report rows are flat scalars; a nested one "
            "means the caller handed this differ something other than a row set" % (column, value)
        )
    if value is None:
        return "\\NUL"
    if column in tuple(contract["exact_columns"]):
        return "t:" + _text(value)
    number = as_number(value)
    if number is None:
        return "t:" + _text(value)
    if contract["places"] is not None:
        number = round_half_up(number, contract["places"])
    if number == 0:
        # abs() and not `+number`: under a localcontext that is not the default, unary plus
        # applies the context's rounding and precision to a value this function has already
        # decided. The only thing that must change is the sign of a zero. Reached both from a
        # literal '-0' (MySQL's ROUND() and PHP's round(-0.4) both print it) and from rounding,
        # where round_half_up('-0.4', 0) is Decimal('-0').
        number = abs(number)
    return "n:" + str(number.normalize())


def _row_key(row, contract):
    """A whole row as one canonical string, column names included.

    Sorted by column, so a row from a `ksort()`ed capture and a row a Python family built in
    another order are one key — the capture already ksorts (`slimstat_rows()`), and relying on
    that ordering rather than restating it would make this differ depend on a property of the
    other side that nothing here checks.
    """
    if isinstance(row, dict):
        items = sorted((str(k), _cell(v, str(k), contract)) for k, v in row.items())
    elif isinstance(row, (list, tuple)):
        # A positional row: index as the column name, so a positional and an associative row
        # never accidentally key alike.
        items = [("#%d" % i, _cell(v, "#%d" % i, contract)) for i, v in enumerate(row)]
    else:
        raise ValueError("a row must be a mapping or a sequence, not %r" % (type(row).__name__,))
    return json.dumps(items, sort_keys=True, ensure_ascii=False)


def compare_rows(a_rows, b_rows, contract=None):
    """Row lists: value equality, with ORDER-ONLY as a distinct outcome.

      rows-equal       same rows, same sequence
      rows-reordered   same multiset of rows, different sequence -> ORDER_ONLY. Not EQUAL,
                       because in an A/A run (same code, same data, same server) a reordering is
                       an instrument or query defect and never noise; not DIFFER, because
                       between OLD and NEW it is a different finding with a different owner
      row-count        the lists are different lengths
      row-values       same length, at least one row present on one side only
    """
    contract = contract_with_defaults(contract)
    keys_a = [_row_key(r, contract) for r in a_rows]
    keys_b = [_row_key(r, contract) for r in b_rows]

    if keys_a == keys_b:
        return _diff(EQUAL, "rows-equal", "rows",
                     "%d row(s), identical in value and in order" % len(keys_a))

    if Counter(keys_a) == Counter(keys_b):
        first = next(i for i, (x, y) in enumerate(zip(keys_a, keys_b)) if x != y)
        return _diff(ORDER_ONLY, "rows-reordered", "row %d" % first,
                     "the same %d row(s) in a different sequence; first divergence at row %d"
                     % (len(keys_a), first), first_divergent_row=first)

    if len(keys_a) != len(keys_b):
        only_a, only_b = _only_in(keys_a, keys_b)
        return _diff(DIFFER, "row-count", "rows",
                     "%d row(s) against %d" % (len(keys_a), len(keys_b)),
                     rows_a=len(keys_a), rows_b=len(keys_b),
                     only_a=only_a[:3], only_b=only_b[:3])

    only_a, only_b = _only_in(keys_a, keys_b)
    return _diff(DIFFER, "row-values", "rows",
                 "%d row(s) on each side; %d present only on the first, %d only on the second"
                 % (len(keys_a), len(only_a), len(only_b)),
                 only_a=only_a[:3], only_b=only_b[:3])


def _only_in(keys_a, keys_b):
    ca, cb = Counter(keys_a), Counter(keys_b)
    return sorted((ca - cb).elements()), sorted((cb - ca).elements())


def compare_banded(a_rows, b_rows, contract=None):
    """Band-by-band comparison with tie tolerance AT THE CUT.

    THE CASE THIS EXISTS FOR. A `LIMIT 20` report whose 20th and 21st candidates tie on the
    ranking column may legitimately return either of them: which one arrives is decided by the
    ORDER BY tie-break, and the two eras do not spell that tie-break the same way — OLD's
    get_top_aggr orders by `counthits DESC` alone while NEW added the grouped column as a
    secondary key, after a same-corpus null control swapped rows 19 and 20 between two identical
    runs. Reporting that as a difference would manufacture a blocking finding out of the query
    plan. Reporting a DROPPED row as a tie would hide canary class ① (`LIMIT 20 -> 19`), which
    is the drill the campaign uses to prove its adjudication has power. Both must be true at
    once, so the tolerance is bounded to the tie band and to nothing else:

      * the CUT VALUE (the count of the last-ranked row) must be equal on both sides;
      * every row ABOVE the cut must be identical on both sides, as a multiset;
      * within the cut band, membership may differ — and only there.

    ORDER_ONLY PRECEDENCE. The module-level invariant that a pure reorder is a distinct outcome
    binds this function too, including when both answers are exactly `limit` rows. When the two
    cut bands have identical membership, there is no tie substitution to pardon; comparison
    delegates to compare_rows() so the same rows in another sequence remain ORDER_ONLY. This is
    ADR-18 Q3, stated here rather than inherited silently from the branch that implements it.

    THE CUT IS min() (or max() under `cut_side: 'max'` for an ascending report), never
    `rows[-1]`. The extended tier's rows arrive canonically SORTED BY ENCODED ROW
    (`slimstat_canon_rows()`, which sorts by json_encode to get a total order across arms), so
    the last element of the list is not the last-ranked row. A rule that read rows[-1] would be
    correct on the legacy keys and quietly wrong on the extended ones — the shape of PITFALLS 45,
    where a copied walk drifted in units and passed for the right verdict anyway.

    NO CUT, NO TOLERANCE. The tolerance applies only where BOTH answers are EXACTLY `limit` long,
    because only then is there a cut at all. Anything else falls through to compare_rows()
    unchanged:

      * SHORTER than the limit — the report was not truncated there, so a missing row is a drop
        and not a tie. This half was always here.
      * LONGER than the limit — the guard read `< limit` until run 53's review, so an arm that
        returned MORE rows than its contract's LIMIT still entered the band, and a LIMIT 3
        answer of three rows against a five-row answer whose tail tied at the cut compared EQUAL
        on `tie-at-the-cut`. A dropped LIMIT in the query builder is a plausible v6 defect and
        `rows-up` is an observable the register already models, so the case is anticipated
        rather than exotic; on a real report whose cut value is 1 — the ordinary shape of a
        count-1 tail — it pardoned an unbounded tail. Two things disagreed and only one could be
        right: THE GUARD WAS WRONG AND THE COMMENT BELOW WAS RIGHT. The comment above the
        membership check asserted "both answers are exactly `limit` long"; `< limit` established
        only "at least `limit`", and the EQUAL return sat directly under it (PITFALLS 10/31 — a
        comment claiming a property the next lines do not perform). The guard now establishes
        what the comment always claimed.

    WHOLE-BAND: if every row ties at the cut value, "above the cut" is empty and the rule would
    accept two disjoint answers as equal. That is a comparison that cannot fail, so the verdict
    is INCOMPARABLE and classify.py labels it (d)-vacuous. PITFALLS 38 and 44 in one branch:
    identical is not evidence until the comparison could have come out otherwise.
    """
    contract = contract_with_defaults(contract)
    limit = contract["limit"]
    if limit is None:
        return compare_rows(a_rows, b_rows, contract)
    if len(a_rows) != limit or len(b_rows) != limit:
        return compare_rows(a_rows, b_rows, contract)

    field_name = contract["count_field"]
    counts_a = _ranking_counts(a_rows, field_name, "the first answer")
    counts_b = _ranking_counts(b_rows, field_name, "the second answer")
    pick = min if contract["cut_side"] == "min" else max
    cut_a, cut_b = pick(counts_a), pick(counts_b)

    if cut_a != cut_b:
        return _diff(DIFFER, "cut-value-differs", "the cut band",
                     "the last-ranked row holds %s on one side and %s on the other, so the two "
                     "answers were cut in different places" % (cut_a, cut_b),
                     cut_a=str(cut_a), cut_b=str(cut_b))

    beyond = (lambda c: c > cut_a) if contract["cut_side"] == "min" else (lambda c: c < cut_a)
    above_a = Counter(_row_key(r, contract) for r, c in zip(a_rows, counts_a) if beyond(c))
    above_b = Counter(_row_key(r, contract) for r, c in zip(b_rows, counts_b) if beyond(c))

    if above_a != above_b:
        only_a = sorted((above_a - above_b).elements())
        only_b = sorted((above_b - above_a).elements())
        return _diff(DIFFER, "above-the-cut-differs", "the rows above the cut",
                     "the tie band is the only place membership may differ; %d row(s) above the "
                     "cut are on the first side only and %d on the second"
                     % (len(only_a), len(only_b)),
                     only_a=only_a[:3], only_b=only_b[:3], cut=str(cut_a))

    if not above_a:
        return _diff(INCOMPARABLE, "whole-result-is-one-tie-band", "the cut band",
                     "all %d row(s) tie at %s, so any %d of the tied candidates is a legal "
                     "answer and two disjoint answers would compare equal — this comparison "
                     "could not have failed" % (limit, cut_a, limit),
                     cut=str(cut_a), limit=limit)

    at_a = Counter(_row_key(r, contract) for r, c in zip(a_rows, counts_a) if not beyond(c))
    at_b = Counter(_row_key(r, contract) for r, c in zip(b_rows, counts_b) if not beyond(c))
    # The two bands hold the same NUMBER of rows by arithmetic — the guard at the top of this
    # function established that both answers are exactly `limit` long, and the check above
    # established that their above-cut multisets are equal — so only membership can differ here.
    # That arithmetic is why the two swap counts below are reported together: they must be equal,
    # and printing only one of them is how a five-row answer against a three-row limit once
    # reported "drew 0 different member(s)" while two rows had been added.
    if at_a == at_b:
        return compare_rows(a_rows, b_rows, contract)

    swapped_out = sorted((at_a - at_b).elements())
    swapped_in = sorted((at_b - at_a).elements())
    return _diff(EQUAL, "tie-at-the-cut", "the cut band",
                 "identical above the cut (%s); within the tie band at %s the first answer drew "
                 "%d member(s) the second did not and the second drew %d the first did not, "
                 "which the ORDER BY tie-break decides and the data does not"
                 % (cut_a, cut_a, len(swapped_out), len(swapped_in)),
                 cut=str(cut_a), swapped_out=swapped_out[:3], swapped_in=swapped_in[:3])


def _ranking_counts(rows, field_name, which):
    """Every row's ranking value, as numbers, or a loud failure.

    A row that does not carry the contract's count_field, or carries something that is not a
    number, ends the comparison here rather than falling through to a default. A silent fallback
    is how a column's meaning changes without anything moving (the encoders' own rule, in
    ENCODING_V1's spec), and the fallback available here — "treat the missing count as 0" —
    would put every such row in the tie band and pardon a real drop.
    """
    counts = []
    for i, row in enumerate(rows):
        if not isinstance(row, dict) or field_name not in row:
            raise ValueError(
                "%s: row %d has no %r column, but the contract declares a LIMIT ranked on it. "
                "Either the contract names the wrong column or these are not the rows it "
                "describes." % (which, i, field_name))
        number = as_number(row[field_name])
        if number is None:
            raise ValueError(
                "%s: row %d holds %r in the ranking column %r, which is not a number"
                % (which, i, row[field_name], field_name))
        counts.append(number)
    return counts


# ── Values, whatever shape they are ─────────────────────────────────────────────────────────


def compare_values(a, b, contract=None):
    """Dispatch on shape: two row lists band-compare, two scalars scalar-compare, a mix DIFFERs.

    A list against a scalar is `shape-differs` and not an attempt to coerce either one. A report
    that used to answer with rows and now answers with a number has changed its answer, and the
    honest verdict is that they are different answers.
    """
    contract = contract_with_defaults(contract)
    a_is_rows, b_is_rows = isinstance(a, list), isinstance(b, list)
    if a_is_rows and b_is_rows:
        return compare_banded(a, b, contract)
    if a_is_rows != b_is_rows:
        return _diff(DIFFER, "shape-differs", "value",
                     "one answer is a row list and the other is a scalar (%r vs %r)"
                     % (type(a).__name__, type(b).__name__))
    return compare_scalar(a, b, contract["places"])


# ── Envelopes — the class line, consumed and never re-drawn ─────────────────────────────────


def check_envelope(env, role):
    """Refuse an envelope whose class and value disagree. This VALIDATES; it never derives.

    The difference matters more than it looks. Deriving the class here would put a second
    implementation of `error` vs `empty` vs `zero` in the tree, and the two would drift — that
    is PITFALLS 5, and the specific conflation is PITFALLS 38's two reports that compared equal
    for weeks because a failed query and an honest nothing are both `[]`. Refusing an incoherent
    envelope costs nothing and can only fire when a producer is already wrong.

    `role` is 'old', 'new' or 'oracle', and it is not decoration: the two ARM envelopes must
    carry the flags block, because slimstat_capture() guarantees it through an array_merge with
    all three keys and a missing one would silently read as "not clock-dependent" — which is how
    a clock-driven difference gets adjudicated as a code change.
    """
    if role not in ("old", "new", "oracle"):
        raise ValueError("role must be 'old', 'new' or 'oracle', not %r" % (role,))
    if not isinstance(env, dict):
        raise ValueError("%s: an answer envelope must be a mapping, not %r"
                         % (role, type(env).__name__))
    cls = env.get("class")
    if cls not in ANSWER_CLASSES:
        raise ValueError(
            "%s: answer class %r is not one of %r. The class is READ from the capture "
            "(tests/docker/report-answers.php) and never inferred here — an envelope without one "
            "is a producer that has not drawn the error/empty/zero line at all."
            % (role, cls, ANSWER_CLASSES))
    if role != "oracle":
        flags = env.get("flags")
        if not isinstance(flags, dict) or not {
            "clock_dependent", "calendar_day_dependent", "pinned"
        } <= set(flags):
            raise ValueError(
                "%s: an arm envelope must carry flags{clock_dependent, calendar_day_dependent, "
                "pinned}; slimstat_capture() emits all three unconditionally, so an envelope "
                "missing one did not come from the capture — and a missing clock flag reads as "
                "false, which is the direction that hides a clock-driven difference." % role)

    value = env.get("value")
    if cls == "empty" and not any(value is v or value == v for v in EMPTY_VALUES):
        raise ValueError("%s: class 'empty' with a value of %r — the capture calls [], null and "
                         "'' empty and nothing else" % (role, value))
    if cls == "zero":
        number = as_number(value)
        if number is None or number != 0:
            raise ValueError("%s: class 'zero' must carry a numeric zero, not %r" % (role, value))
    if cls == "ok":
        if any(value is v or value == v for v in EMPTY_VALUES):
            raise ValueError("%s: class 'ok' with an empty value %r — that is the capture's "
                             "'empty', and the two must not be spelled the same" % (role, value))
        number = as_number(value)
        if number is not None and number == 0 and not isinstance(value, list):
            raise ValueError("%s: class 'ok' carrying the number 0 — that is the capture's "
                             "'zero'" % role)
    if cls == "error" and not env.get("error"):
        # PITFALLS 59: the arm that failed threw away the record of why. An error envelope
        # without its error record hands the operator the name of the thing that failed and
        # nothing else.
        raise ValueError("%s: class 'error' with no error record — the statement and message are "
                         "the whole diagnostic value of an error answer" % role)
    if cls in ("unsupported", "unmodeled") and not (env.get("__unsupported") or env.get("reason")):
        raise ValueError("%s: class %r must carry a reason ('__unsupported' or 'reason'). A "
                         "missing key reads as 'nobody looked'" % (role, cls))
    return cls


def compare_envelopes(old_env, new_env, contract=None, roles=("old", "new")):
    """Two capture envelopes: the class line first, the values only where both sides have one.

      class-mismatch   the two answers are not the same KIND of answer. `error` != `empty` !=
                       `zero` != `ok`, and this is the branch that keeps them apart at
                       comparison time as the capture keeps them apart at measurement time
      both-error       two errors carry no comparable value -> INCOMPARABLE. Not EQUAL: two
                       reports that both failed have not agreed about anything
      both-unsupported / both-unmodeled -> INCOMPARABLE, same reason
      both-hollow      two empties are equal AS CLASSES, and the caller is told so by the rule
                       name, because "both empty" is exactly the agreement PITFALLS 38 warns
                       about and classify.py has to look at it again with the oracle in hand
      both-zero        two measured zeroes are equal, and are NOT hollow
      value            two `ok` answers, compared by value
    """
    contract = contract_with_defaults(contract)
    # `roles` exists so the oracle can sit on either side of a comparison without the ARM
    # validation being applied to it — the oracle is not a capture and carries no flags block.
    a_cls = check_envelope(old_env, roles[0])
    b_cls = check_envelope(new_env, roles[1])

    if a_cls != b_cls:
        return _diff(DIFFER, "class-mismatch", "class",
                     "%s against %s — error, empty, zero and ok are four different answers"
                     % (a_cls, b_cls), a_class=a_cls, b_class=b_cls)

    if a_cls == "error":
        return _diff(INCOMPARABLE, "both-error", "class",
                     "both answers failed; two failures carry no comparable value",
                     a_error=_error_text(old_env), b_error=_error_text(new_env))
    if a_cls in ("unsupported", "unmodeled"):
        return _diff(INCOMPARABLE, "both-" + a_cls, "class",
                     "neither side answered: %s / %s"
                     % (_reason(old_env), _reason(new_env)))
    if a_cls == "empty":
        return _diff(EQUAL, "both-hollow", "class",
                     "both answers ran and returned no rows — equal, and equal for a reason that "
                     "cannot fail on its own")
    if a_cls == "zero":
        return _diff(EQUAL, "both-zero", "class",
                     "both answers ran and returned the number 0 — a measurement, not an absence")

    return compare_values(old_env.get("value"), new_env.get("value"), contract)


def _error_text(env):
    err = env.get("error") or {}
    if isinstance(err, dict):
        return str(err.get("str") or err.get("query") or err)
    return str(err)


def _reason(env):
    return str(env.get("__unsupported") or env.get("reason") or "(no reason given)")
