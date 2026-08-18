"""The (a)/(b)/(c)/(d) decision table over an answer TRIPLE — OLD, NEW, and the ORACLE.

The campaign's binding classification, in the plan's own words:

    (a) NEW differs, NEW proven correct     -> must map to an expected-diffs R# entry, else
                                               (a)-UNREGISTERED, which BLOCKS until adjudicated
    (b) NEW differs, NEW proven wrong       -> release-blocking regression
    (c) OLD and NEW agree, oracle disagrees -> both wrong, OR the oracle is — a first-class
                                               outcome, never silently resolved in the oracle's
                                               favour
    (d) truth cannot be established         -> UNRESOLVED, never PASS; sub-labels d-vacuous,
                                               d-clock, d-unmodeled

WHAT THIS FILE IS, structurally. The decision is a TABLE — an ordered list of `Rule` records,
each carrying the label it produces and the sentence explaining why it produces it — walked
top to bottom until one matches. It is written that way so a reader can check the classification
without reading control flow: a nest of ifs hides its own ordering, and the ordering is where
this kind of table goes wrong (an error rule below a value rule quietly turns a failed query
into a difference of opinion about numbers).

THREE THINGS IT CONSUMES RATHER THAN REBUILDS:

  1. The ANSWER CLASS. `error`, `empty` and `zero` are drawn apart once, by `slimstat_capture()`
     in tests/docker/report-answers.php, gated by tests/answer-envelope-classes-test.php and
     pinned by mutation `S1-error-reads-as-empty-01`. Nothing here infers a class from a value.
     Two parsers of one contract drift, and this particular contract is the one the whole
     campaign rests on (PITFALLS 5, and PITFALLS 38 for what the conflation actually cost).
  2. The DIFFER. `compare/algebra.py`, shared with the oracle families so a tolerance cannot
     fork between the side that computes an answer and the side that judges it.
  3. The REGISTER. `EXPECTED-DIFFS.md` is prose for humans; this file takes a `Register` of
     machine-readable entries from its caller. There is deliberately no code here that parses
     the markdown: a half-working parser of a document whose entries decide whether a difference
     blocks a release is worse than no parser, because it fails toward "no entry found", which
     reads as (a)-UNREGISTERED — a defect wearing the costume of diligence.

WHY THE REGISTER MATCH CHECKS THE OBSERVABLE, not just the surface. Run 50 amended two entries
because the symptom they described was not the symptom that was measured (R4/R5 say funnels
"reported zero"; on MySQL 8 they ERROR). The register's own note on that amendment is the rule
this implements: *an entry that describes the wrong symptom is worse than no entry, because a
classifier reading it files a registered defect as unregistered and blocks*. So a surface-only
match is reported as a NEAR MISS in the verdict detail and does not license (a) — the reader is
told an entry exists and why it did not apply, which is the feedback that gets the register
corrected instead of the finding waved through.

Python 3.x, standard library only.
"""

import json
import os
import sys
from dataclasses import dataclass, field

# No package installation, no __init__.py, no autoloader — every gate in this tree runs as a
# plain script. `compare` resolves as a namespace package once this file's own directory is on
# the path, which is the same one-line arrangement encoding_v1_test.py uses.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from compare import algebra  # noqa: E402  (the path insert above is a prerequisite)


class ClassificationError(Exception):
    """Raised where the table could not decide. Never returned as a label, and never a pass."""


# ── The labels, and what each one COSTS ─────────────────────────────────────────────────────
#
# `disposition` is the only thing downstream may act on, and there are three of them:
#
#   pass       the surface is settled and does not hold up the merge gate
#   block      the surface holds up the merge gate until a human adjudicates it
#   unresolved truth was not established; the surface is LISTED and never counted as a pass
#
# (d) is unresolved by construction, and an import-time invariant below refuses a table in which
# any d-* label is a pass — because "never PASS" written in a plan is a sentence, and this is a
# mechanism (PITFALLS 64: a mechanism is a file in the diff; prose describing one is a plan).
DISPOSITIONS = ("pass", "block", "unresolved")

LABELS = {
    "equal": {
        "disposition": "pass",
        "means": "all three answers agree, and the agreement is over a real value",
    },
    "zero": {
        "disposition": "pass",
        "means": "all three agree that the answer is the measured number 0 — a report that RAN "
                 "and counted nothing, which is not the same as one that returned no rows",
    },
    "a": {
        "disposition": "pass",
        "means": "NEW differs and the oracle backs NEW, and the difference maps to a register "
                 "entry whose declared observable is the one that was measured",
    },
    "a-UNREGISTERED": {
        "disposition": "block",
        "means": "NEW differs and the oracle backs NEW, but no register entry covers this "
                 "surface with this observable. Blocks until adjudicated — this is the class "
                 "the register exists to keep empty",
    },
    "b": {
        "disposition": "block",
        "means": "NEW differs and the oracle backs OLD: a release-blocking regression",
    },
    "c": {
        "disposition": "block",
        "means": "OLD and NEW agree and the oracle disagrees. Both arms are wrong, or the "
                 "oracle is; it is never resolved toward the oracle by this file",
    },
    "c-THREE-WAY": {
        "disposition": "block",
        "means": "the arms differ and the oracle was matched by neither of them — three "
                 "different answers, or a comparison against one arm that established nothing "
                 "(a LIMIT answer whose every row ties at the cut agrees with anything). Same "
                 "disposition as (c) and a different shape, so a scorecard cannot report it as "
                 "'the arms agreed'",
    },
    "d-vacuous": {
        "disposition": "unresolved",
        "means": "the comparison could not have failed — every side empty, or a LIMIT report "
                 "whose every row ties at the cut. Agreement here is arithmetic, not evidence",
    },
    "d-clock": {
        "disposition": "unresolved",
        "means": "the surface is clock- or calendar-day-dependent and was not captured under a "
                 "pinned window, so a difference cannot be attributed to the code",
    },
    "d-unmodeled": {
        "disposition": "unresolved",
        "means": "no third answer exists: the oracle has no model, or errored, or an arm could "
                 "not be asked. Never agreement",
    },
    "SQL-error": {
        "disposition": "block",
        "means": "an arm's answer is class `error`. On NEW it is a regression candidate; on OLD "
                 "it is a known-bug candidate that becomes a register entry only through the "
                 "pre-blind evidence gate, never by having errored",
    },
    "hollow": {
        "disposition": "block",
        "means": "both arms returned no rows on a surface the oracle says has some — an "
                 "instrument or corpus defect, and never recorded as a zero",
    },
    "ORDER-ONLY": {
        "disposition": "unresolved",
        "means": "the arms hold the same rows in a different sequence. Not a value difference "
                 "and not a pass: nothing here established which order is right, and in an A/A "
                 "run the same verdict is an outright FAIL",
    },
}

# ── Observables — WHAT the difference looked like ───────────────────────────────────────────
#
# Computed from the triple, and matched against what a register entry DECLARES. Split in two on
# purpose: `ok-to-error` is observable and is NOT registrable, so no register entry can ever
# explain a NEW arm that errors. That rule is the plan's failure protocol 6 in mechanical form —
# *"OLD errored, so register it" without the evidence chain is how a NEW defect that happens to
# error differently gets laundered into an expected diff* — and it is enforced at Register
# construction rather than by the order of the table below, so re-ordering the table cannot
# reopen it.
OBSERVABLES = (
    "error-to-ok",
    "error-to-hollow",
    "ok-to-error",
    "hollow-to-value",
    "value-to-hollow",
    "rows-up",
    "rows-down",
    "rows-same-count-values-differ",
    "value-up",
    "value-down",
    "value-differs",
    "shape-differs",
    "order-only",
    "none",
)

NON_REGISTRABLE_OBSERVABLES = ("ok-to-error",)


class Register:
    """The machine-readable expected-diff register: R#, the surfaces it covers, the observable.

    Entry shape:

        {"r": "R15",
         "surfaces": ["uniques_browser", "uniques_country"],
         "observable": "rows-up",
         "pre_blind": false,
         "note": "get_top_aggr's array form parsed use_date_filters and never consulted it"}

    `pre_blind` is carried through to the verdict and printed by the scorecard as
    `direction: pre-blind, not independently confirmed`. A pre-blind entry was adjudicated by
    someone who knew which arm was which, so it is evidence that the difference EXISTS and never
    evidence about its direction — and it may never be cited as evidence that the blind works.
    """

    def __init__(self, entries=()):
        self.entries = []
        for i, raw in enumerate(entries):
            entry = dict(raw)
            for required in ("r", "surfaces", "observable"):
                if not entry.get(required):
                    raise ValueError("register entry %d has no %r" % (i, required))
            if not isinstance(entry["surfaces"], (list, tuple)) or not entry["surfaces"]:
                raise ValueError("register entry %s: 'surfaces' must be a non-empty list"
                                 % entry["r"])
            if entry["observable"] not in OBSERVABLES:
                raise ValueError(
                    "register entry %s declares observable %r, which is not one of %r. An "
                    "unknown observable would match nothing and read as a missing entry."
                    % (entry["r"], entry["observable"], OBSERVABLES))
            if entry["observable"] in NON_REGISTRABLE_OBSERVABLES:
                raise ValueError(
                    "register entry %s declares observable %r. The register may explain OLD's "
                    "failures; it may never explain NEW erroring. Registering that observable "
                    "is how a NEW defect that happens to error differently gets laundered into "
                    "an expected diff." % (entry["r"], entry["observable"]))
            entry.setdefault("pre_blind", False)
            self.entries.append(entry)

    @classmethod
    def from_json(cls, path):
        """Load `expected-diffs.json` once it exists. Nothing loads EXPECTED-DIFFS.md."""
        with open(path) as handle:
            data = json.load(handle)
        return cls(data["entries"] if isinstance(data, dict) else data)

    def match(self, surface, observable):
        """(exact hit or None, [entries that cover the surface but not this observable])."""
        near = []
        for entry in self.entries:
            if surface not in entry["surfaces"]:
                continue
            if entry["observable"] == observable:
                return entry, near
            near.append(entry)
        return None, near


# ── The facts a rule may look at ────────────────────────────────────────────────────────────


@dataclass(frozen=True)
class Facts:
    surface: str
    old_class: str
    new_class: str
    oracle_class: str
    arms: algebra.Diff            # OLD vs NEW
    oracle_new: algebra.Diff      # ORACLE vs NEW
    oracle_old: algebra.Diff      # ORACLE vs OLD
    clock: bool                   # either arm declares clock- or calendar-day-dependence
    pinned: bool                  # BOTH arms captured under a pinned window
    observable: str
    register_hit: dict = None
    register_near: tuple = ()

    def agrees(self, diff):
        """The oracle 'agrees' when it matches in VALUE — order is not the oracle's to claim.

        A Python family builds its rows in whatever order it iterated; that ordering carries no
        authority, so ORDER_ONLY between the oracle and an arm is agreement about the answer.
        Between the two ARMS it is not — there the order came from two SQL engines and is a
        finding of its own, which is why ORDER-ONLY is a label and not a footnote.
        """
        return diff.verdict in (algebra.EQUAL, algebra.ORDER_ONLY)



@dataclass(frozen=True)
class Rule:
    name: str
    label: str
    why: str
    when: object  # callable(Facts) -> bool


# ── THE TABLE ───────────────────────────────────────────────────────────────────────────────
#
# Ordered. First match wins. Read top to bottom: the class-level facts (an arm failed, an arm
# could not be asked, nobody answered, both arms are hollow) are settled before any value is
# compared, because a value comparison against a failed query is a category error that produces
# a confident number.
#
# THE FIRST FOUR RULES ARE FACTS ABOUT THE ARMS, and they sit above the three facts about the
# ORACLE deliberately. The ordering used to be the other way round, and run 53's review measured
# what that cost: with `oracle-absent` at position 1, a NEW arm that FATALLY ERRORED on a surface
# the oracle has no model for came out `d-unmodeled` / unresolved instead of `SQL-error` / block.
# Failure protocol 6 makes a SQL error on NEW a (b)-candidate that BLOCKS, unconditionally and
# without mentioning the oracle — and `unmodeled` is the DEFAULT oracle state through S4-S7, when
# one family exists, so that was the common case rather than a corner. Whether NEW's query threw
# is not a question the oracle has any bearing on.
RULES = (
    Rule(
        "new-errored", "SQL-error",
        "NEW's answer is class `error`. Terminal, and reached FIRST: no third opinion is needed "
        "to know that NEW's query failed, and no register entry may explain a NEW arm that "
        "fails, however the failure is spelled. At position 1 this fires whatever the oracle's "
        "state and whatever OLD did, which is the guarantee the position buys",
        lambda f: f.new_class == "error",
    ),
    Rule(
        "arm-unsupported", "d-unmodeled",
        "an arm could not be asked this question at all (the surface does not exist in that "
        "era). Judged on another cell, or listed — never scored as a difference. Below "
        "`new-errored`, because an era that cannot be asked is not the same fact as a query that "
        "was asked and failed",
        lambda f: "unsupported" in (f.old_class, f.new_class),
    ),
    Rule(
        "old-errored-registered", "a",
        "OLD failed where NEW answers, a register entry covers this surface with exactly this "
        "observable, AND the oracle backs NEW's replacement answer — the R4/R5 shape, where "
        "5.5.1's funnels ERROR on MySQL 8 and v6 answers. All three are required: (a) means NEW "
        "PROVEN CORRECT, and a registered OLD failure is evidence that the difference EXISTS, "
        "never evidence that the number NEW put in its place is right. Accepted for existence; "
        "if the entry is pre-blind its DIRECTION is not",
        lambda f: (f.old_class == "error" and f.register_hit is not None
                   and f.agrees(f.oracle_new)),
    ),
    Rule(
        "old-errored", "SQL-error",
        "OLD's answer is class `error`, and either nothing in the register covers it or no "
        "oracle answer backs what NEW replaced it with. A known-bug CANDIDATE, not a known bug: "
        "it becomes an expected diff only through the pre-blind evidence gate — the captured "
        "statement, hand truth that NEW's answer is right, and user sign-off. A register entry "
        "supplies the first of those and never the second",
        lambda f: f.old_class == "error",
    ),
    Rule(
        "oracle-absent", "d-unmodeled",
        "the oracle has no model for this surface, or the surface cannot be asked of it. An "
        "absent third answer is never agreement — that is the whole reason there is a third one",
        lambda f: f.oracle_class in ("unmodeled", "unsupported"),
    ),
    Rule(
        "oracle-errored", "d-unmodeled",
        "the oracle itself failed, so no truth was computed. Reporting the arms' agreement here "
        "would be reporting that two answers agreed with nothing",
        lambda f: f.oracle_class == "error",
    ),
    Rule(
        "hollow-arms", "hollow",
        "both arms returned no rows and the oracle says this surface has some. Two empties "
        "compare equal and always will, which is exactly how uniques_browser and "
        "uniques_country agreed in every arm of every run while neither had ever executed "
        "(PITFALLS 38). Instrument or corpus defect; never recorded as a zero",
        lambda f: f.old_class == "empty" and f.new_class == "empty" and f.oracle_class != "empty",
    ),
    Rule(
        "all-hollow", "d-vacuous",
        "every side is empty. The comparison agrees and could not have done anything else, so "
        "it establishes nothing about the code",
        lambda f: f.old_class == "empty" and f.new_class == "empty",
    ),
    Rule(
        "comparison-could-not-fail", "d-vacuous",
        "the ARMS comparison reported INCOMPARABLE — a LIMIT report whose every row ties at the "
        "cut, where any N of the tied candidates is a legal answer and two disjoint answers "
        "would read as agreement. Scoped to the arms on purpose: this predicate used to fire "
        "when ANY of the three comparisons was vacuous, so a vacuous ORACLE-vs-OLD leg filed a "
        "REAL arms difference as 'the comparison could not have failed'. Measured on canary "
        "class 1 (LIMIT 20 -> 19) over a flat tail: the poisoned report came out d-vacuous, "
        "while the same poison over a non-flat tail blocked — the drill passing or failing on "
        "whether the corpus happened to be flat. A verdict whose own detail.arms records a row "
        "count difference may not tell the reader nothing could have failed",
        lambda f: f.arms.verdict == algebra.INCOMPARABLE,
    ),
    Rule(
        "clock-unpinned", "d-clock",
        "the arms differ on a surface flagged clock- or calendar-day-dependent that was not "
        "captured under a pinned window. Two arms run minutes apart select different rows, so "
        "the difference cannot be attributed to the code — re-capture pinned before judging it",
        lambda f: f.arms.verdict != algebra.EQUAL and f.clock and not f.pinned,
    ),
    Rule(
        "order-only", "ORDER-ONLY",
        "the arms hold the same rows in a different sequence and the oracle agrees about the "
        "rows. A finding with its own owner — collation, ORDER BY tie-break, server version — "
        "and not a value difference",
        lambda f: f.arms.verdict == algebra.ORDER_ONLY and f.agrees(f.oracle_new),
    ),
    Rule(
        "order-only-oracle-differs", "c",
        "the arms hold the same rows in a different sequence and the ORACLE holds different "
        "rows. The arms agree about the values, so this is (c): both wrong, or the oracle is",
        lambda f: f.arms.verdict == algebra.ORDER_ONLY,
    ),
    Rule(
        "arms-agree-oracle-agrees-zero", "zero",
        "all three answer the number 0. Reported as its own label so a real measured zero is "
        "never filed beside a hollow one — the distinction R17's 'Today reads 0' turns on",
        lambda f: f.arms.verdict == algebra.EQUAL and f.agrees(f.oracle_new)
        and f.new_class == "zero",
    ),
    Rule(
        "arms-agree-oracle-agrees", "equal",
        "all three agree over a real value. The ordinary outcome, and the only one that needs "
        "no adjudication",
        lambda f: f.arms.verdict == algebra.EQUAL and f.agrees(f.oracle_new),
    ),
    Rule(
        "arms-agree-oracle-differs", "c",
        "OLD and NEW agree and the oracle does not. A first-class outcome: it is not resolved "
        "toward the oracle here, because 'the oracle is wrong' and 'both arms are wrong' are "
        "both live and only adjudication can separate them",
        lambda f: f.arms.verdict == algebra.EQUAL,
    ),
    Rule(
        "new-backed-registered", "a",
        "NEW differs from OLD, the oracle backs NEW, and a register entry covers this surface "
        "with this observable. An expected diff — a number that changed on purpose",
        lambda f: f.agrees(f.oracle_new) and f.register_hit is not None,
    ),
    Rule(
        "new-backed-unregistered", "a-UNREGISTERED",
        "NEW differs from OLD and the oracle backs NEW, but nothing in the register describes "
        "this difference. BLOCKS: an unexplained improvement is still unexplained, and the "
        "register is the only place a deliberate change is written down before it is measured",
        lambda f: f.agrees(f.oracle_new),
    ),
    Rule(
        "old-backed", "b",
        "NEW differs from OLD and the oracle backs OLD. A release-blocking regression",
        lambda f: f.agrees(f.oracle_old),
    ),
    Rule(
        "oracle-alone", "c-THREE-WAY",
        "NEW differs from OLD and neither arm was matched by the oracle. Both arms are wrong, "
        "or the oracle is — the same disposition as (c), kept separate so nothing can report it "
        "as an agreement between the arms. This is also where a real arms difference lands when "
        "the oracle's comparison against ONE arm was vacuous (canary class 1 over a flat tie "
        "band): still a block, and its detail names the rule each leg took, which is the "
        "information an adjudicator needs and 'the comparison could not have failed' destroyed",
        lambda f: True,
    ),
)


def _assert_table_invariants():
    """Run at import. A table this file's callers trust is checked before it is used.

    These are the properties the plan states in prose, made mechanical: (d) can never be a pass;
    (c) always blocks; every label is produced by some rule and every rule produces a known
    label. A label nothing emits is decoration, and a rule emitting an unknown label would fail
    at classification time on some future triple instead of here, on every import.
    """
    produced = set()
    for rule in RULES:
        if rule.label not in LABELS:
            raise ClassificationError("rule %r produces unknown label %r" % (rule.name, rule.label))
        produced.add(rule.label)
    orphans = sorted(set(LABELS) - produced)
    if orphans:
        raise ClassificationError(
            "label(s) %r are declared but no rule produces them — a label nothing emits cannot "
            "be tested and cannot be trusted" % (orphans,))
    for label, spec in LABELS.items():
        if spec["disposition"] not in DISPOSITIONS:
            raise ClassificationError("label %r has disposition %r" % (label, spec["disposition"]))
        if label.startswith("d-") and spec["disposition"] == "pass":
            raise ClassificationError(
                "label %r is a (d) and (d) is UNRESOLVED, never PASS" % label)
    for label in ("c", "c-THREE-WAY", "a-UNREGISTERED", "b", "SQL-error", "hollow"):
        if LABELS[label]["disposition"] != "block":
            raise ClassificationError("label %r must block" % label)
    names = [rule.name for rule in RULES]
    if len(set(names)) != len(names):
        raise ClassificationError("two rules share a name; a verdict must name one rule")


_assert_table_invariants()


# ── The verdict ─────────────────────────────────────────────────────────────────────────────


@dataclass(frozen=True)
class Verdict:
    label: str
    disposition: str
    rule: str
    why: str
    surface: str
    observable: str
    register_id: str = None
    pre_blind: bool = False
    detail: dict = field(default_factory=dict)

    def __bool__(self):
        raise TypeError(
            "a Verdict is not a boolean — read .disposition ('pass' / 'block' / 'unresolved'). "
            "Truthiness would read a release-blocking regression as success."
        )

    def as_dict(self):
        return {
            "label": self.label,
            "disposition": self.disposition,
            "rule": self.rule,
            "why": self.why,
            "surface": self.surface,
            "observable": self.observable,
            "register_id": self.register_id,
            "pre_blind": self.pre_blind,
            "detail": dict(self.detail),
        }


def _unmodeled(reason):
    return {"class": "unmodeled", "value": None, "reason": reason}


def _flag(env, name):
    flags = env.get("flags") or {}
    return bool(flags.get(name))


def observable_of(old_env, new_env, arms):
    """WHAT the difference looked like, in the register's vocabulary.

    Computed from the two ARM envelopes only. The oracle decides who is right; it has no say in
    what the symptom was, and letting it have one would make a register entry's meaning depend
    on the state of the oracle that judged it.
    """
    old_cls, new_cls = old_env["class"], new_env["class"]
    answered = ("ok", "zero")

    if new_cls == "error":
        return "ok-to-error"
    if old_cls == "error":
        return "error-to-ok" if new_cls in answered else "error-to-hollow"
    if old_cls == "empty" and new_cls in answered:
        return "hollow-to-value"
    if new_cls == "empty" and old_cls in answered:
        return "value-to-hollow"
    if arms.verdict == algebra.EQUAL:
        return "none"
    if arms.verdict == algebra.ORDER_ONLY:
        return "order-only"

    old_value, new_value = old_env.get("value"), new_env.get("value")
    if isinstance(old_value, list) and isinstance(new_value, list):
        if len(new_value) > len(old_value):
            return "rows-up"
        if len(new_value) < len(old_value):
            return "rows-down"
        return "rows-same-count-values-differ"
    if isinstance(old_value, list) != isinstance(new_value, list):
        return "shape-differs"

    old_number, new_number = algebra.as_number(old_value), algebra.as_number(new_value)
    if old_number is not None and new_number is not None:
        return "value-up" if new_number > old_number else "value-down"
    return "value-differs"


def classify(triple, contract=None, register=None):
    """Classify one answer triple. Returns a Verdict; never a bool, never None.

    `triple` is {"surface": str, "old": envelope, "new": envelope, "oracle": envelope|None}.
    A missing or None oracle becomes an `unmodeled` envelope rather than a skipped comparison:
    the one thing an absent oracle must never produce is agreement.
    """
    surface = triple.get("surface")
    if not surface:
        raise ClassificationError("a triple must name its surface; a verdict with no subject "
                                  "cannot be looked up, argued with, or re-derived")
    old_env, new_env = triple["old"], triple["new"]
    oracle_env = triple.get("oracle") or _unmodeled(
        "no oracle answer was supplied for this surface")

    register = register if register is not None else Register(())
    if not isinstance(register, Register):
        register = Register(register)

    arms = algebra.compare_envelopes(old_env, new_env, contract)
    oracle_new = algebra.compare_envelopes(oracle_env, new_env, contract, roles=("oracle", "new"))
    oracle_old = algebra.compare_envelopes(oracle_env, old_env, contract, roles=("oracle", "old"))

    observable = observable_of(old_env, new_env, arms)
    hit, near = (None, [])
    if observable not in NON_REGISTRABLE_OBSERVABLES:
        hit, near = register.match(surface, observable)

    facts = Facts(
        surface=surface,
        old_class=old_env["class"],
        new_class=new_env["class"],
        oracle_class=oracle_env["class"],
        arms=arms,
        oracle_new=oracle_new,
        oracle_old=oracle_old,
        # Either arm declaring clock dependence makes the surface clock-dependent: the flag
        # describes the QUESTION, and one era spelling it and the other not is itself a reason
        # to distrust a difference.
        clock=any(_flag(env, name) for env in (old_env, new_env)
                  for name in ("clock_dependent", "calendar_day_dependent")),
        # BOTH arms must be pinned for the pin to help. A window pinned on one side only leaves
        # the other free to move, which is the state the pin exists to prevent.
        pinned=_flag(old_env, "pinned") and _flag(new_env, "pinned"),
        observable=observable,
        register_hit=hit,
        register_near=tuple(near),
    )

    for rule in RULES:
        if not rule.when(facts):
            continue
        spec = LABELS[rule.label]
        detail = {
            "classes": {"old": facts.old_class, "new": facts.new_class,
                        "oracle": facts.oracle_class},
            "arms": arms.as_dict(),
            "oracle_vs_new": oracle_new.as_dict(),
            "oracle_vs_old": oracle_old.as_dict(),
            "clock_dependent": facts.clock,
            "pinned": facts.pinned,
            "means": spec["means"],
        }
        if near and hit is None:
            # The near miss is FEEDBACK, and it is the reason this is not a surface-only match.
            # An operator who sees "R2 covers this surface, but declares rows-up and we measured
            # rows-same-count-values-differ" fixes the register; one who sees only UNREGISTERED
            # re-derives the whole finding.
            #
            # `hit is None` is the whole point of the feedback and was missing. R2 as amended in
            # Run 50 declares TWO observables, and `observable` takes exactly one value per
            # entry, so R2-as-amended has one legal encoding: two entries sharing r="R2". Match
            # the second and the verdict printed "R2 declares rows-up, we measured
            # rows-same-count-values-differ" — a near-miss warning naming the entry that had just
            # granted the pass, and an instruction to amend a register that was amended with user
            # sign-off before this classifier existed. Feedback about a miss, on a hit, is noise
            # that costs the operator the same re-derivation the field exists to save. It also
            # made the field depend on JSON key ORDER: reverse the two entries and it vanished.
            detail["register_near_miss"] = [
                {"r": entry["r"], "declares": entry["observable"], "measured": observable}
                for entry in near
            ]
        if hit is not None and rule.label == "a" and hit.get("pre_blind"):
            detail["direction"] = ("pre-blind, not independently confirmed — registered for "
                                   "existence only, never as evidence that the blind works")

        label = rule.label
        # Belt and braces over the import-time invariant: a (d) that reached a pass disposition
        # would be the campaign's one unforgivable outcome, so it is checked again at the point
        # of emission rather than only at the point of definition.
        if label.startswith("d-") and spec["disposition"] == "pass":
            raise ClassificationError("(d) may never be a pass: %s" % label)

        return Verdict(
            label=label,
            disposition=spec["disposition"],
            rule=rule.name,
            why=rule.why,
            surface=surface,
            observable=observable,
            register_id=(hit or {}).get("r") if label == "a" else None,
            pre_blind=bool((hit or {}).get("pre_blind")) if label == "a" else False,
            detail=detail,
        )

    # Unreachable while the last rule's predicate is `True`, and it raises rather than returning
    # anything, because the one thing a decision table must not do when it cannot decide is
    # produce a label. Not plantable by construction — the table is total — which is why the
    # gate asserts totality structurally instead (every rule has a plant, and the last rule's
    # predicate is checked to be unconditional).
    raise ClassificationError(
        "no rule matched %s — the table is not total, which means some triple has been silently "
        "unclassified for as long as that has been true" % surface)
