#!/usr/bin/env python3
"""THE GATE for the answer classifier: every plant, through classify.py, to its exact label.

  python3 tests/oracle/classify_test.py          (composer test:classifier-plants)

The plants ARE the mutation set for this classifier, in the same sense the seven inline fixtures
in tests/control-wiring-test.php are for the control analyser: a classifier that has only ever
been run on inputs it labels correctly has not been run. Each plant in
tests/oracle/plants/plants.json states, in its own record, what mislabelling it would cost the
campaign — so a failure here prints the consequence and not only the diff.

WHAT THIS FILE ASSERTS, and why each part exists:

  1. EXACT LABEL, and the exact RULE that produced it. Not "did not pass" and not "differs":
     a gate satisfied by any red is a gate that goes green again for the wrong reason
     (PITFALLS 6 — a mutation killed by a different assertion proves nothing about the one it
     was written for). A plant that reaches the right label through the wrong rule is a bug in
     the table's ordering wearing a green tick.
  2. COVERAGE, three times over. Every label the classifier can emit has at least one plant,
     every RULE in the decision table has at least one plant, and every rule name the DIFFER can
     construct — `compare/algebra.py`'s declared `DIFF_RULES` — is reached by this run. A label
     with no plant is an untested branch; a rule with no plant is the same thing one level down,
     and the second is the one an opt-in coverage check misses (PITFALLS 32 — a gate that
     derives its own subject set derives the compliant one).

     The third leg is new in run 53 and it is the one the README had been ASSERTING. plants/
     README.md claimed a plant "per edge in compare/algebra.py" for the whole of S4; measured, 11
     of 19 edges were reached by nothing, including BOTH halves of the tie tolerance's only rail,
     either of which could be flipped to EQUAL with this gate printing green. Two of the three
     "one per" claims were mechanised and the third was prose — and the prose one was the false
     one (PITFALLS 64).
  3. A FLOOR, exactly as tests/mutations/FLOOR works: the plant count must EQUAL
     tests/oracle/plants/FLOOR. Fewer means a plant was deleted; more means one was added
     without ratcheting the file that makes it undeletable. Both directions fail, because
     `count < floor` alone leaves every new plant free to be removed at zero cost — the ratchet
     PITFALLS 61 had to close on the mutation registry.
  4. FIVE POSITIVE CONTROLS that prove this plant set can tell a broken classifier from a
     working one, by RUNNING one. Asserting that a fixture is discriminating is a claim; making
     the discrimination happen is a measurement (PITFALLS 61: the guard was real, the argument
     for it was right, and nothing in the suite could distinguish its presence from its
     absence). The controls flatten the answer classes, empty the register, drop the contract's
     LIMIT, put both arms' rows in one order, and drop the contract's `places` — and each must
     move a label that the plant set says it should. The last two are new in run 53: ORDER-ONLY
     and the rounding boundary each had a discriminating plant and no control, so nothing had
     watched either distinction being taken away.
  5. MUST-RAISE cases, each pinned to a SUBSTRING of the message, so a refusal that happens for
     an unrelated reason cannot be mistaken for the refusal under test.

Python 3.x, standard library only.
"""

import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import classify as C                     # noqa: E402
from compare import algebra              # noqa: E402

PLANTS_DIR = os.path.join(HERE, "plants")
FIXTURE = json.load(open(os.path.join(PLANTS_DIR, "plants.json")))
PLANTS = FIXTURE["plants"]

# The three flags slimstat_capture() emits unconditionally. One spelling, because `check_envelope`
# hard-requires exactly this key set and a second copy would go stale in silence.
ARM_FLAGS = {"clock_dependent": False, "calendar_day_dependent": False, "pinned": True}

passed = 0
failures = []

# ── The differ's edge recorder ──────────────────────────────────────────────────────────────
#
# Every Diff in this tree is constructed by algebra._diff(), so wrapping that one function
# records which of algebra.DIFF_RULES this run actually reached — plants, controls and the direct
# assertions at the bottom alike. Installed HERE, before anything classifies, because a recorder
# installed halfway through measures the second half and reports on the whole.
#
# This is the same instrument run 53's review used to measure that 11 of 19 edges were reached by
# nothing at all. It lives in the gate rather than in algebra.py because the production path must
# not carry a counter, and it wraps rather than re-derives: scraping the source for `_diff(...,
# "name"` would let a deleted call site take its own name out of the denominator, which is a
# coverage check that passes by losing its subject (PITFALLS 32).
seen_diff_rules = set()
_real_diff = algebra._diff


def _recording_diff(verdict, rule, *args, **kwargs):
    # Recorded only once the Diff is actually built, so a rule name that _diff() REFUSES does not
    # enter the coverage set on its way to raising.
    diff = _real_diff(verdict, rule, *args, **kwargs)
    seen_diff_rules.add(algebra.rule_family(rule))
    return diff


algebra._diff = _recording_diff


def check(label, got, want, consequence=None):
    """One assertion. Every failure prints what it got, what was wanted, and what it costs."""
    global passed
    if got == want:
        passed += 1
        return True
    message = "%s\n      got  %r\n      want %r" % (label, got, want)
    if consequence:
        message += "\n      mislabelling this means: %s" % consequence
    failures.append(message)
    return False


def check_raises(label, fn, expect_substring, exc=Exception):
    """A refusal, pinned to its REASON.

    `expect_substring` is the same device as a mutation's `expect:` line: without it, a function
    that raises for an unrelated reason — a typo in the fixture, a missing key — looks exactly
    like the refusal being tested, and the assertion passes while guarding nothing.
    """
    global passed
    try:
        fn()
    except exc as err:
        if expect_substring in str(err):
            passed += 1
            return
        failures.append("%s\n      raised, but for another reason: %s\n      expected to contain: %s"
                        % (label, err, expect_substring))
        return
    failures.append("%s\n      did not raise at all; expected a refusal containing: %s"
                    % (label, expect_substring))


# ── 1. Every plant, to its exact label ──────────────────────────────────────────────────────
#
# The optional expectations are checked only when the plant declares them, so a plant stays
# readable — but each declared one is an assertion of its own and is counted as one.

seen_labels = set()
seen_rules = set()

for plant in PLANTS:
    pid = plant["id"]
    expect = plant["expect"]
    consequence = plant.get("mislabelling_means")

    try:
        verdict = C.classify(plant["triple"], plant.get("contract"),
                             C.Register(plant.get("register", [])))
    except Exception as err:                                     # noqa: BLE001
        failures.append("%s: classify() raised %s: %s" % (pid, type(err).__name__, err))
        continue

    seen_labels.add(verdict.label)
    seen_rules.add(verdict.rule)

    check("%s label" % pid, verdict.label, expect["label"], consequence)
    if "rule" in expect:
        check("%s decided by rule" % pid, verdict.rule, expect["rule"], consequence)
    if "disposition" in expect:
        check("%s disposition" % pid, verdict.disposition, expect["disposition"], consequence)
    if "arms_rule" in expect:
        # WHICH algebra rule decided the arm comparison. This is what pins the tie-at-the-cut,
        # class-mismatch and rounding edges to the branch that is supposed to handle them,
        # rather than to any branch that happens to give the same label.
        check("%s arms compared by" % pid, verdict.detail["arms"]["rule"], expect["arms_rule"],
              consequence)
    if "observable" in expect:
        check("%s observable" % pid, verdict.observable, expect["observable"], consequence)
    if "register_id" in expect:
        check("%s register id" % pid, verdict.register_id, expect["register_id"], consequence)
    if "pre_blind" in expect:
        check("%s pre_blind" % pid, verdict.pre_blind, expect["pre_blind"], consequence)
    if "near_miss" in expect:
        near = [entry["r"] for entry in verdict.detail.get("register_near_miss", [])]
        check("%s names the register near miss" % pid, near, expect["near_miss"], consequence)


# ── 2. Coverage: every label, and every rule, has a plant ───────────────────────────────────

for label in sorted(C.LABELS):
    check("label %r has a plant" % label, label in seen_labels, True,
          "a label with no plant is a branch nobody has executed — and the classifier's whole "
          "value is that its branches were exercised before the campaign trusted them")

for rule in C.RULES:
    check("rule %r has a plant" % rule.name, rule.name in seen_rules, True,
          "a rule with no plant can be deleted, reordered or inverted without any gate noticing")

# The reverse direction: a label or rule name that no plant produced is caught above; a plant
# that produced a label the table does not declare cannot happen (classify refuses it at import),
# and that refusal is asserted here rather than assumed.
check("every label a plant produced is declared", sorted(seen_labels - set(C.LABELS)), [],
      "a verdict carrying an undeclared label has no disposition, so nothing downstream knows "
      "whether it blocks")


# ── 3. The floor ────────────────────────────────────────────────────────────────────────────

floor_path = os.path.join(PLANTS_DIR, "FLOOR")
floor = int(open(floor_path).read().strip()) if os.path.isfile(floor_path) else 0
if floor < 1:
    failures.append("tests/oracle/plants/FLOOR is missing or unreadable — without it a plant set "
                    "that shrinks to one still prints green")
elif len(PLANTS) < floor:
    failures.append(
        "%d plant(s) against a FLOOR of %d — a plant was deleted. Raise the floor deliberately "
        "if one is genuinely obsolete; never lower it to match a deletion" % (len(PLANTS), floor))
elif len(PLANTS) > floor:
    failures.append(
        "%d plant(s) but FLOOR is still %d — write %d into tests/oracle/plants/FLOOR. Until you "
        "do, the new plant(s) are deletable without any gate noticing"
        % (len(PLANTS), floor, len(PLANTS)))
else:
    passed += 1

ids = [plant["id"] for plant in PLANTS]
check("plant ids are unique", len(set(ids)), len(ids),
      "two plants sharing an id makes a failure report ambiguous about which one failed")


# ── 3b. The README's own count of this gate's mutations, read rather than believed ──────────
#
# plants/README.md heads a section "The N ways this is proven to fail — registered, not
# recounted". That N has now been wrong TWICE. The first time it was a prose table of ten
# mutations someone had once run by hand; run 53 replaced it with a spelled-out "Twenty-two",
# and the follow-up commits took the directory to twenty-five without touching the sentence —
# the same defect one commit later, in the paragraph written to close it.
#
# So the number is not typed by hand any more; it is READ from the heading and compared against
# the directory. The second assertion is the section's other claim, which was equally unchecked:
# every one of those files must name THIS gate, because a registered mutation whose `gate:` runs
# some other script says nothing about the plant set (PITFALLS 64 — a mechanism is a file in the
# diff, prose describing one is a plan).

MUTATIONS_DIR = os.path.join(os.path.dirname(HERE), "mutations")
README_PATH = os.path.join(PLANTS_DIR, "README.md")
S4_MUTATIONS = sorted(name for name in os.listdir(MUTATIONS_DIR)
                      if name.startswith("S4-") and name.endswith(".mutation"))

_heading = re.search(r"^## The (\d+) ways this is proven to fail",
                     open(README_PATH).read(), re.M)
check("plants/README.md states its mutation count in a form this gate can read",
      _heading is not None, True,
      "without the machine-readable heading the count goes back to being a spelled-out number "
      "nothing checks, which is how it drifted from ten to twenty-two to twenty-five")
check("plants/README.md's mutation count matches tests/mutations/",
      int(_heading.group(1)) if _heading else None, len(S4_MUTATIONS),
      "a reader auditing the section against the directory finds entries the record does not "
      "account for and has to re-derive which — the cost V13 and S4-IND-04 were filed for")

# Matched with the header GRAMMAR the .mutation format actually uses — `key:` then whitespace
# then the value — not with the six-space column this directory happens to be aligned to today.
# The first version required the literal "gate:      composer test:classifier-plants": re-align
# one file to a single space and it satisfies slimstat_mutation_parse(), the registry gate and
# the runner, while this check reports it as wrongly gated. A third reader of a format that
# already has one owner (tests/lib/mutations.php: "one parser, because two parsers are two
# formats"), pinned to whitespace nothing else pins. PITFALLS 5.
_GATE_RE = re.compile(r"^gate:\s*composer\s+test:classifier-plants\s*$", re.M)
_wrong_gate = sorted(
    name for name in S4_MUTATIONS
    if not _GATE_RE.search(open(os.path.join(MUTATIONS_DIR, name)).read()))
check("every S4 mutation is gated by this file", _wrong_gate, [],
      "the README says every one of them carries `gate: composer test:classifier-plants`; one "
      "that names a different script is counted as coverage of the plant set and is not")

# ── 3c. The README's mutation IDs name real files ───────────────────────────────────────────
#
# 3b counts. It does not read. The section it guards named eight mutations and FIVE of the ids
# did not exist: three were near-misses of the real filenames, one described an assertion that
# lives in section 8 rather than a registry entry, and one — `S4-readme-mutation-count-unchecked`
# — claimed the 3b gate above was itself mutation-covered when no such file was ever written.
# That last one is PITFALLS 64's sentence exactly, three lines under a paragraph quoting it, in
# the same commit that added the count check. Counting was the axis somebody thought of; naming
# was the axis the drift used.
_README_IDS = sorted(set(re.findall(r"`(S4-[A-Za-z0-9._-]+)`", open(README_PATH).read())))
_phantom = [i for i in _README_IDS if i + ".mutation" not in S4_MUTATIONS]
check("every mutation id cited in plants/README.md is a file on disk", _phantom, [],
      "a citation to a mutation that does not exist reads as coverage and audits as coverage; "
      "the reader only learns otherwise by running ls, which is the one thing prose cannot make "
      "them do")
check("the README cites enough mutation ids to be worth checking", len(_README_IDS) >= 5, True,
      "if the section stopped naming any file, the check above would pass over an empty list — "
      "loudest exactly when blind, which is the shape this whole gate exists against")


# ── 4. The table's own invariants, printed rather than assumed ──────────────────────────────

for label, spec in sorted(C.LABELS.items()):
    if label.startswith("d-"):
        check("(d) label %r is never a pass" % label, spec["disposition"] != "pass", True,
              "(d) means truth was not established; a (d) that passes is the campaign reporting "
              "a verdict it does not have")

check("(c) blocks", C.LABELS["c"]["disposition"], "block",
      "(c) resolved toward the oracle would silently certify a defect present in BOTH eras — "
      "the one class an A/B comparison cannot see on its own")
check("(c) three-way blocks", C.LABELS["c-THREE-WAY"]["disposition"], "block")
check("(a)-UNREGISTERED blocks", C.LABELS["a-UNREGISTERED"]["disposition"], "block")
check("(b) blocks", C.LABELS["b"]["disposition"], "block")
check("an error answer blocks", C.LABELS["SQL-error"]["disposition"], "block")
check("a hollow answer blocks", C.LABELS["hollow"]["disposition"], "block")

# The last rule must be unconditional, which is what makes the table total. `when(None)` is the
# cheapest possible proof: `lambda f: True` ignores its argument, and anything that inspects one
# will raise or answer False here.
try:
    total = C.RULES[-1].when(None) is True
except Exception:                                                # noqa: BLE001
    total = False
check("the table's last rule is unconditional", total, True,
      "a table that can fall through raises instead of labelling — better than a wrong label, "
      "but it means some triple has been unclassifiable for as long as that has been true")


# ── 4b. An oracle that did not ANSWER backs nobody ──────────────────────────────────────────
#
# S4 green criterion 2, in its own words: "no path to `a` exists on which the oracle is absent,
# errored, or disagrees with NEW". WHY `agrees()` alone does not establish that travels with the
# code, on `Facts.agrees` and on `new-backed-registered`'s own `why`; what it COST is in
# S4-oracle-backs-new-by-being-empty-02 and PITFALLS 77.
#
# A SWEEP rather than one more plant, because the claim is universal and a plant pins one triple.
# That distinction is the whole finding: `P45` pins this exact shape for the SIBLING rule and did
# not generalise, and all fifty plants were green with the defect present AND after it was fixed.
#
# Asserted on what may NOT happen — never on a destination label. Where these triples belong is
# an OPEN QUESTION: by the taxonomy at the top of classify.py they are (d), "truth cannot be
# established", and they currently land on `c-THREE-WAY`/block, whose `means` is at least true of
# them. Planting a destination would capture the implementation, which criterion 5 forbids, and
# would go red when the ADR lands — creating pressure to preserve whatever it froze.

NON_ANSWERING_ORACLES = {
    "empty": {"class": "empty", "value": []},
    "error": {"class": "error", "value": None,
              "error": {"str": "the oracle's own query failed", "query": "SELECT 1", "count": 1}},
    "unsupported": {"class": "unsupported", "value": None, "reason": "not asked of that era"},
    "unmodeled": {"class": "unmodeled", "value": None, "reason": "no family models this surface"},
}
check("every answer class outside ANSWERED has a fixture in this sweep",
      sorted(NON_ANSWERING_ORACLES), sorted(set(algebra.ANSWER_CLASSES) - set(C.ANSWERED)),
      "a sixth answer class would be swept by nothing and could back an arm by returning nothing "
      "— the enumeration is derived so adding a class cannot narrow this section in silence")

# The rules that cite the oracle as BACKING an arm. Named rather than derived: the property is
# what the rule's verdict CLAIMS, which no introspection can read. Checked against the table so a
# rename cannot leave this list quietly matching nothing.
BACKED_BY_ORACLE = ("new-backed-registered", "new-backed-unregistered", "old-backed")
check("every rule named as citing the oracle is still in the table",
      sorted(set(BACKED_BY_ORACLE) & {r.name for r in C.RULES}), sorted(BACKED_BY_ORACLE),
      "a renamed rule would drop out of this list and the sweep below would assert nothing about "
      "it while still printing green")

REGISTER_HIT = [{"r": "R99", "surfaces": ["top_pages"], "observable": "value-to-hollow"}]
ARM_PAIRS = (("ok", "empty"), ("empty", "ok"), ("empty", "zero"), ("zero", "empty"),
             ("ok", "zero"), ("zero", "ok"), ("ok", "ok"))
ARM_VALUES = {"ok": [{"cnt": 5}], "empty": [], "zero": 0}


def arm(cls, value):
    return {"class": cls, "value": value, "flags": dict(ARM_FLAGS)}


# Printed, not merely asserted — section 4's habit. When the ADR lands, this is the record of
# where these triples used to go.
observed = {}
for old_cls, new_cls in ARM_PAIRS:
    old_val = ARM_VALUES[old_cls]
    new_val = [{"cnt": 9}] if (new_cls == "ok" and old_cls == "ok") else ARM_VALUES[new_cls]
    for name, oracle in list(NON_ANSWERING_ORACLES.items()) + [("ABSENT", None)]:
        where = "old=%s new=%s oracle=%s" % (old_cls, new_cls, name)
        verdict = C.classify({"surface": "top_pages", "old": arm(old_cls, old_val),
                              "new": arm(new_cls, new_val), "oracle": oracle},
                             None, C.Register(REGISTER_HIT))
        observed.setdefault((verdict.label, verdict.rule), []).append(where)
        check("(a) is not granted by an oracle that never answered — %s" % where,
              verdict.label != "a", True,
              "(a) means NEW PROVEN CORRECT. An oracle that returned nothing has proven nothing, "
              "and `agrees()` is satisfied by two empties comparing EQUAL for the one reason two "
              "empties always will")
        check("...and it does not PASS — %s" % where, verdict.disposition != "pass", True,
              "a PASS here certifies a release against an answer nobody computed")
        check("...and no rule claims the oracle BACKED an arm — %s" % where,
              verdict.rule not in BACKED_BY_ORACLE, True,
              "`old-backed` on old=empty/new=zero prints 'the oracle backs OLD: a release-"
              "blocking regression' about a surface that started reporting a measured zero — a "
              "FIX, called a regression, on the strength of an oracle that returned nothing")

print("  4b: an oracle that did not answer sends %d triples to:" % sum(map(len, observed.values())))
for (label, rule), wheres in sorted(observed.items()):
    print("      %-14s %-24s x%d" % (label, rule, len(wheres)))

# THE ANTI-VACUITY HALF — PITFALLS 63, the shape section 8 exists to refuse. Derived from the
# plant set rather than hand-built, so it cannot drift from what the register actually declares.
backed_plant = next(p for p in PLANTS if p["expect"].get("rule") == "new-backed-registered")
backed = C.classify(backed_plant["triple"], backed_plant.get("contract"),
                    C.Register(backed_plant.get("register", [])))
check("an oracle that DID answer and backs NEW still grants (a)", backed.label, "a",
      "every assertion above would pass if nothing could reach (a) at all, which is a gate "
      "guarding an unreachable branch rather than a live one")
check("...and that (a) passes", backed.disposition, "pass")


# ── 5. POSITIVE CONTROLS — the plant set is made to catch three broken classifiers ──────────
#
# Not "the plants look discriminating". Three deliberately degraded inputs are RUN through the
# same classifier, and each must move the labels the plant set claims to protect.

def degrade(triple, transform):
    """A triple with `transform` applied to each answer envelope it carries.

    The shared half of every control below: walk the three roles, copy the envelope so the plant
    fixture is never mutated in place, and leave a role alone when it holds no envelope (`oracle:
    null` is a plant's way of saying the oracle had no answer, and a control that turned that
    into something else would be degrading a different triple than the one it names).
    """
    out = {"surface": triple["surface"]}
    for role in ("old", "new", "oracle"):
        env = triple.get(role)
        out[role] = transform(dict(env)) if isinstance(env, dict) else env
    return out


def flatten_classes(triple):
    """Every `error` and `zero` answer rewritten as `empty` — the falsy-test classifier.

    This is `S1-error-reads-as-empty-01` one layer up: a classifier that cannot tell a failed
    query from an honest nothing from a measured zero. It is applied to the DATA rather than to
    the source, so no file is mutated and nothing has to be restored (PITFALLS 33: the revert is
    where the damage happens).
    """
    def flatten(env):
        if env.get("class") in ("error", "zero"):
            env["class"] = "empty"
            env["value"] = []
            env.pop("error", None)
        return env

    return degrade(triple, flatten)


class_sensitive = [p for p in PLANTS
                   if any(isinstance(p["triple"].get(role), dict)
                          and p["triple"][role].get("class") in ("error", "zero")
                          for role in ("old", "new", "oracle"))]

check("there are plants that turn on the error/empty/zero distinction", len(class_sensitive) >= 5,
      True, "without them nothing here would notice a classifier that treats all three as falsy")

for plant in class_sensitive:
    flat = C.classify(flatten_classes(plant["triple"]), plant.get("contract"),
                      C.Register(plant.get("register", [])))
    check("CONTROL: %s changes label when error/zero are flattened into empty" % plant["id"],
          flat.label != plant["expect"]["label"], True,
          "this plant would be blind to a classifier that reads `error` and `zero` as `empty` — "
          "which is the conflation the whole campaign's foundation rests on")

register_backed = [p for p in PLANTS if p["expect"]["label"] == "a"]
check("there are plants whose label depends on the register", len(register_backed) >= 2, True,
      "(a) is the only label that requires a register match; with no such plant the register "
      "could be ignored entirely and every gate would stay green")

for plant in register_backed:
    stripped = C.classify(plant["triple"], plant.get("contract"), C.Register(()))
    check("CONTROL: %s is no longer (a) with an empty register" % plant["id"],
          stripped.label != "a", True,
          "if the label survives an empty register, the register is decoration and every "
          "unexplained difference passes as expected")

tie_plant = next(p for p in PLANTS if p["id"] == "P23-tie-at-the-cut-is-not-a-difference")
no_limit = C.classify(tie_plant["triple"], {}, C.Register(()))
check("CONTROL: the tie tolerance is load-bearing (P23 without its LIMIT is not equal)",
      no_limit.label != "equal", True,
      "if P23 passes with and without the contract's limit, it does not exercise the "
      "tie-at-the-cut rule at all and the rule is untested")


def canonicalise_row_order(triple):
    """Every row list sorted into one order — the 'order does not matter' capture.

    The fourth control, and it degrades the DATA the way the other three do. ORDER-ONLY is the
    label with the least intuitive disposition in the whole table (the same rows, and still not a
    pass, because in an A/A run a reordering is an instrument defect), so a plant landing on it
    for some incidental reason rather than for the sequence would be scenery. Sorting both arms
    into the same order must move the label; if it does not, the plant was never about order.

    The sort key is json.dumps here rather than algebra._row_key, and that is the point: a
    control keyed by the differ under test moves WITH the differ, so a mutation that changed row
    identity would degrade the control and the thing it is controlling for at the same time.
    """
    def in_one_order(env):
        if isinstance(env.get("value"), list):
            env["value"] = sorted(env["value"],
                                  key=lambda row: json.dumps(row, sort_keys=True, default=str))
        return env

    return degrade(triple, in_one_order)


order_plants = [p for p in PLANTS if p["expect"]["label"] == "ORDER-ONLY"]
check("there are plants whose label depends on the row SEQUENCE", len(order_plants) >= 1, True,
      "ORDER-ONLY is a label of its own precisely because folding it into equal would hide a "
      "collation or tie-break change; with no plant on it, the fold costs nothing")

for plant in order_plants:
    reordered = C.classify(canonicalise_row_order(plant["triple"]), plant.get("contract"),
                           C.Register(plant.get("register", [])))
    check("CONTROL: %s stops being ORDER-ONLY once both arms are in one order" % plant["id"],
          reordered.label != "ORDER-ONLY", True,
          "if the label survives the rows being put in the same sequence, it was never about "
          "the sequence — and a differ that folded rows-reordered into rows-equal would keep "
          "this plant green")

precision_plant = next(p for p in PLANTS if p["id"] == "P25-float-inside-the-emitted-precision")
no_places = C.classify(precision_plant["triple"], {}, C.Register(()))
check("CONTROL: the emitted precision is load-bearing (P25 without its `places` is not equal)",
      no_places.label != "equal", True,
      "P25 passes because two values that differ in the eighth decimal render the same at the "
      "four places the report emits. If it passed with and without `places`, the rounding is "
      "not what made it pass and the whole numeric-at-N-places edge is untested by it")


# ── 6. The rounding claim in algebra.py, replayed against PHP's own output ──────────────────

probes = FIXTURE["php_round_probes"]
for case in probes["cases"]:
    got = algebra.round_half_up(case["value"], case["places"])
    # %r, not %s: four of these probes exist only to carry the value as a JSON NUMBER rather than
    # a string, and a label that renders both as `1.005` cannot say which one failed.
    check("round_half_up(%r, %d) matches PHP round()" % (case["value"], case["places"]),
          str(got), case["php_round"],
          "the emitted-precision comparison is only meaningful if it rounds the way the number "
          "was rounded when it was emitted")

# ── 6b. And where it does NOT match, pinned in both directions ──────────────────────────────
#
# The helper agrees with PHP 8.4+ and disagrees with 7.4 / 8.0-8.3 — the declared floor and most
# of the support matrix — on values that sit 1-2 ULP below a decimal half, which is where the
# plugin's own `round(($n / $d) * 100, $p)` lands routinely. Measured on four interpreters over
# 1400 boundary values; see plants.json's `php_round_divergences` for the method and the count.
#
# Two assertions per case, and the second is the point. Asserting only that the helper returns
# the 8.4+ answer would leave a fixture that confirms itself: if the pre-8.4 answer recorded here
# were wrong, or stopped being different, nothing would say so and the boundary would quietly
# become a claim about nothing. This is the same shape as `expect:` on a mutation — a red that
# cannot say WHICH thing it is about is not evidence about either.
for case in FIXTURE["php_round_divergences"]["cases"]:
    got = str(algebra.round_half_up(case["value"], case["places"]))
    check("round_half_up(%r, %d) gives the PHP 8.4+ answer" % (case["value"], case["places"]),
          got, case["php_84_plus_round"],
          "this helper rounds the decimal RENDERING, which is what PHP 8.4+ does; matching the "
          "pre-8.4 pre-rounding correction instead would be a silent change of comparison "
          "semantics for every arm in the support matrix")
    check("PHP <= 8.3 really does answer differently for %r at %d places"
          % (case["value"], case["places"]),
          case["php_74_to_83_round"] != case["php_84_plus_round"], True,
          "if the two answers were the same, this case pins nothing and the divergence this "
          "block records — the one the eleven short-literal probes structurally could not see — "
          "would be back to being a sentence")

check("NaN is refused rather than compared", algebra.as_number("NaN"), None,
      "a NaN compares unequal to everything including itself, so a comparison against one can "
      "never fail — a guard in the wrong units (PITFALLS 69)")
check("Infinity is refused rather than compared", algebra.as_number("Infinity"), None)


# ── 7. Must-raise: the refusals, each pinned to its reason ──────────────────────────────────


check_raises("an unknown answer class is refused",
             lambda: algebra.check_envelope({"class": "blank", "value": None, "flags": ARM_FLAGS},
                                            "new"),
             "is not one of")
check_raises("an arm envelope without the flags block is refused",
             lambda: algebra.check_envelope({"class": "ok", "value": "1"}, "new"),
             "must carry flags")
check_raises("class 'empty' carrying rows is refused",
             lambda: algebra.check_envelope(
                 {"class": "empty", "value": [{"a": 1}], "flags": ARM_FLAGS}, "new"),
             "class 'empty' with a value")
check_raises("class 'zero' carrying a non-zero is refused",
             lambda: algebra.check_envelope({"class": "zero", "value": 5, "flags": ARM_FLAGS},
                                            "new"),
             "must carry a numeric zero")
check_raises("class 'ok' carrying nothing is refused",
             lambda: algebra.check_envelope({"class": "ok", "value": [], "flags": ARM_FLAGS},
                                            "new"),
             "class 'ok' with an empty value")
# The three EMPTY_VALUES are ([], None, "") and only the first was ever asserted, so dropping ""
# from that tuple — which is how an `ok` envelope carrying the empty string starts being compared
# as a value — cost nothing. NULL, the empty string and no rows are three answers here.
check_raises("class 'ok' carrying the empty string is refused",
             lambda: algebra.check_envelope({"class": "ok", "value": "", "flags": ARM_FLAGS},
                                            "new"),
             "class 'ok' with an empty value")
check_raises("class 'ok' carrying NULL is refused",
             lambda: algebra.check_envelope({"class": "ok", "value": None, "flags": ARM_FLAGS},
                                            "new"),
             "class 'ok' with an empty value")
check_raises("class 'ok' carrying the number 0 is refused",
             lambda: algebra.check_envelope({"class": "ok", "value": "0", "flags": ARM_FLAGS},
                                            "new"),
             "that is the capture's 'zero'")
check_raises("an error answer with no error record is refused",
             lambda: algebra.check_envelope({"class": "error", "value": [], "flags": ARM_FLAGS},
                                            "old"),
             "no error record")
check_raises("an unsupported answer with no reason is refused",
             lambda: algebra.check_envelope(
                 {"class": "unsupported", "value": None, "flags": ARM_FLAGS}, "old"),
             "must carry a reason")
check_raises("a register entry with an unknown observable is refused",
             lambda: C.Register([{"r": "R99", "surfaces": ["x"], "observable": "vibes"}]),
             "is not one of")
check_raises("a register entry may never explain NEW erroring",
             lambda: C.Register([{"r": "R99", "surfaces": ["x"], "observable": "ok-to-error"}]),
             "may never explain NEW erroring")
check_raises("a contract key nobody reads is refused",
             lambda: algebra.contract_with_defaults({"limit_": 20}),
             "does not read")
# An unknown contract KEY was refused and an invalid cut_side VALUE was not asserted anywhere, so
# deleting that guard was free — and with it gone `cut_side: 'minimum'` silently takes the `max`
# branch, which computes the cut at the wrong end of a descending report and returns EQUAL on
# tie-at-the-cut for a top-ranked row that genuinely changed.
check_raises("a cut_side nobody implements is refused",
             lambda: algebra.contract_with_defaults({"cut_side": "minimum"}),
             "must be 'min' or 'max'")
check_raises("a rule name the differ never declared is refused",
             lambda: algebra._diff(algebra.DIFFER, "invented-rule", "nowhere", "x"),
             "not declared in DIFF_RULES")
check_raises("a LIMIT contract over rows with no ranking column is refused",
             lambda: algebra.compare_banded([{"a": 1}], [{"a": 2}], {"limit": 1}),
             "has no 'counthits' column")
check_raises("a triple with no surface is refused",
             lambda: C.classify({"old": {"class": "ok", "value": "1", "flags": ARM_FLAGS},
                                 "new": {"class": "ok", "value": "1", "flags": ARM_FLAGS}}),
             "must name its surface", C.ClassificationError)
check_raises("a Diff is not a boolean",
             lambda: bool(algebra.compare_scalar("1", "1")),
             "not a boolean", TypeError)
check_raises("a Verdict is not a boolean",
             lambda: bool(C.classify(PLANTS[0]["triple"], PLANTS[0].get("contract"))),
             "not a boolean", TypeError)


# ── 8. The differ's own edges, where no answer TRIPLE can reach them ────────────────────────
#
# Some of algebra's edges cannot be planted, and saying which and why is the point of this
# section — an edge left uncovered "because it is unreachable" is exactly the claim that needs
# checking (PITFALLS 63: a guard whose subject the read path cannot observe has to be asserted
# somewhere the read path is not).
#
#   both-null / null-vs-value   check_envelope refuses an `ok` envelope carrying None, so a
#                               top-level NULL scalar cannot reach compare_scalar through
#                               compare_envelopes at all. The oracle FAMILIES call compare_scalar
#                               directly, which is the path these two are for.
#   string-exact, shape-differs no capture surface answers a non-numeric scalar today, so a plant
#     (scalar site)             for either would have to invent one. Asserted here rather than
#                               planted with a fabricated surface.
#   both-error, both-unsupported / both-unmodeled
#                               new-errored, old-errored, arm-unsupported and the two oracle
#                               rules all fire before any rule reads the arms verdict, so no
#                               triple can carry these to a label. They still decide what a
#                               family sees when it compares two answers of its own.
#   both-hollow                 P03 and P12 REACH it, and neither one READS it: `hollow-arms`
#                               and `all-hollow` fire on the class line and never consult
#                               `arms.verdict`, so the rule NAME entered the section-9 coverage
#                               set while its verdict was pinned by nothing. Measured: EQUAL
#                               could be changed to DIFFER, INCOMPARABLE or ORDER_ONLY with all
#                               fifty plants green. It remains an explicit algebra contract even
#                               though ADR-18 Q2 now settles hollow oracle legs by class first.
#
# Each asserts the VERDICT and the RULE, because a right verdict from the wrong branch is the
# thing expect.arms_rule exists to catch one level up.


def check_diff(label, diff, verdict, rule, consequence=None):
    check("%s verdict" % label, diff.verdict, verdict, consequence)
    check("%s rule" % label, diff.rule, rule, consequence)


check_diff("NULL against NULL is agreement", algebra.compare_scalar(None, None),
           algebra.EQUAL, "both-null",
           "NULL is a value the two eras can genuinely agree about; folding it into '' or 0 is "
           "the distinction ENCODING_V1 spends two token spellings on")
check_diff("NULL against a value is a difference", algebra.compare_scalar(None, 0),
           algebra.DIFFER, "null-vs-value",
           "R16 is exactly this defect — count(ip) skipped NULLs in a function answering PAGES "
           "per visit — so a differ for which NULL equals 0 cannot see the class of bug it was "
           "built for")
check_diff("two different texts are a difference",
           algebra.compare_scalar("v6.0.1", "v6.0.2"), algebra.DIFFER, "string-exact",
           "with this EQUAL, every non-numeric scalar answer — a version, a date, a status "
           "string — compares clean no matter what it says")
check_diff("identical text is agreement", algebra.compare_scalar("2026-08-01", "2026-08-01"),
           algebra.EQUAL, "string-exact")
check_diff("a number against a non-number is a difference",
           algebra.compare_scalar("42", "n/a"), algebra.DIFFER, "shape-differs",
           "an answer that stopped being a number has changed; coercing either side would pick "
           "one of the two possible wrong answers for every report")
check_diff("two failures have not agreed about anything",
           algebra.compare_envelopes({"class": "error", "value": [], "error": {"str": "a"},
                                      "flags": ARM_FLAGS},
                                     {"class": "error", "value": [], "error": {"str": "b"},
                                      "flags": ARM_FLAGS}),
           algebra.INCOMPARABLE, "both-error",
           "EQUAL here would report two broken queries as a settled surface — PITFALLS 38 with "
           "an error in place of the empty list")
check_diff("two arms that could not be asked have not agreed either",
           algebra.compare_envelopes({"class": "unsupported", "value": None, "reason": "x",
                                      "flags": ARM_FLAGS},
                                     {"class": "unsupported", "value": None, "reason": "y",
                                      "flags": ARM_FLAGS}),
           algebra.INCOMPARABLE, "both-unsupported")
check_diff("two oracles with no model have not agreed either",
           algebra.compare_envelopes({"class": "unmodeled", "value": None, "reason": "x"},
                                     {"class": "unmodeled", "value": None, "reason": "y"},
                                     roles=("oracle", "oracle")),
           algebra.INCOMPARABLE, "both-unmodeled")
check_diff("two empties are EQUAL, and the rule name says why that is not evidence",
           algebra.compare_envelopes({"class": "empty", "value": [], "flags": ARM_FLAGS},
                                     {"class": "empty", "value": [], "flags": ARM_FLAGS}),
           algebra.EQUAL, "both-hollow",
           "this is the one verdict in DIFF_RULES that two plants REACHED and no assertion READ. "
           "classify.py now settles both arms and oracle hollow legs by class before backing "
           "rules, but the algebra still owes an explicit meaning for two empty envelopes")

# The two ROW-KEY properties, which no plant reaches because no plant needs them yet.
#
# `exact_columns` is the escape hatch for a zero-padded code, and deleting its branch left the
# whole gate green — '007' and 7 are the same number and different answers, and a differ with no
# hatch has to choose one of the two errors for every column in every report. Asserted in both
# directions, because a hatch that is always open is the same defect as one that is nailed shut.
check_diff("a column declared exact compares as bytes",
           algebra.compare_rows([{"code": "007"}], [{"code": 7}],
                                {"exact_columns": ("code",)}),
           algebra.DIFFER, "row-values")
check_diff("and the same column is a number without the declaration",
           algebra.compare_rows([{"code": "007"}], [{"code": 7}]),
           algebra.EQUAL, "rows-equal")

# A row's key carries its COLUMN NAMES. Drop them and two rows holding the same values under
# different columns are one row — a column swap, which is precisely the shape of a rewrite defect
# in a query builder that assembles its SELECT list positionally.
check_diff("two rows with the same values under different columns are different rows",
           algebra.compare_rows([{"a": 1, "b": 2}], [{"a": 2, "b": 1}]),
           algebra.DIFFER, "row-values",
           "with the names dropped from the key these compare as the same multiset in another "
           "order, so a swapped pair of columns reads as ORDER-ONLY rather than as a difference")

# Negative zero, which had TWO answers in one differ: EQUAL down the scalar path (Decimal('-0')
# == Decimal('0')) and DIFFER down the row path, because normalize() keeps the sign bit and the
# cell token read 'n:-0' against 'n:0'. Which answer you got depended on whether the report's
# shape was rows or a scalar. Both directions are asserted, so restoring the fork breaks one of
# them whichever way it is restored.
check_diff("negative zero is zero (scalar path)", algebra.compare_scalar("-0", "0"),
           algebra.EQUAL, "numeric-exact")
check_diff("negative zero is zero (row path)",
           algebra.compare_rows([{"v": "-0"}], [{"v": "0"}]), algebra.EQUAL, "rows-equal",
           "one differ giving two answers for one pair of values, decided by the report's shape "
           "rather than by the data, is PITFALLS 5 inside the single file written to prevent it")
check_diff("negative zero is zero after rounding, too",
           algebra.compare_rows([{"v": "-0.4"}], [{"v": "0.4"}], {"places": 0}),
           algebra.EQUAL, "rows-equal",
           "round_half_up('-0.4', 0) is Decimal('-0'), so the fork is reachable from ordinary "
           "values and not only from a literal '-0'")


# ── 9. Every edge the DIFFER can take was taken by something above ──────────────────────────
#
# WHAT THIS LOOP ESTABLISHES, AND WHAT IT DOES NOT. It records that the edge was CONSTRUCTED, not
# that anything read what it returned. An edge reached only through `expect.arms_rule` has its
# NAME asserted and its VERDICT free: `both-hollow` was reached by two plants and could still be
# flipped to any of the four verdicts with the whole gate green, because the two rules that label
# those plants fire on the class line and never consult `arms.verdict`. That gap is closed one
# edge at a time in section 8, not by this loop — so read a green here as "nothing was deleted",
# and never as "every edge is pinned".

for rule in algebra.DIFF_RULES:
    check("algebra rule %r is reached by this run" % rule, rule in seen_diff_rules, True,
          "an edge no plant and no assertion reaches can be inverted, widened or deleted with "
          "this gate green — which is how both halves of the tie tolerance's only rail sat "
          "unexercised through the whole of S4 while the README claimed one plant per edge")

check("every edge this run reached is declared", sorted(seen_diff_rules - set(algebra.DIFF_RULES)),
      [], "a rule name outside DIFF_RULES would be an edge the coverage loop above cannot see, "
          "so the loop would report full coverage of a smaller differ than the one that ran")


# ── Report ──────────────────────────────────────────────────────────────────────────────────
#
# The assertion COUNT is itself asserted, for the reason the sibling gates give: shrinking the
# plant set to one entry, or deleting every optional expectation, leaves this file printing OK
# and exiting 0 — a counter nothing checks is decoration.

expected = FIXTURE["expected_assertions"]
total_ran = passed + len(failures)
if total_ran != expected:
    failures.append(
        "assertion floor — ran %d, plants.json declares %d. A shrunk plant set, or a plant that "
        "quietly lost its expectations, must not print green" % (total_ran, expected))

print("SLIMSTAT-CLASSIFY-PLANTS plants=%d assertions=%d failures=%d"
      % (len(PLANTS), total_ran, len(failures)))

if failures:
    sys.stderr.write("FAIL: the classifier does not label its plants as declared (%d problem(s))\n"
                     % len(failures))
    for problem in failures:
        sys.stderr.write("  - %s\n" % problem)
    sys.exit(1)

print("PASS: %d plants — one per label, one per decision-table rule, and every one of the %d "
      "edges the differ can take reached by a plant or an assertion; each to its exact label and "
      "the exact rule that produced it; error, empty and zero stay three answers; (d) never "
      "passes; (c) is never resolved toward the oracle; and five controls prove the set catches "
      "a classifier that stops making those distinctions"
      % (len(PLANTS), len(algebra.DIFF_RULES)))
