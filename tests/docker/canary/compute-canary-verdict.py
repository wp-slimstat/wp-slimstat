#!/usr/bin/env python3
"""S7 canary verdict — recomputed from atoms, never read off an agent's summary.

run-canary.sh applies, measures and restores; it does not decide. This does, and it decides from
files written by different steps at different times: the pre-declaration (pinned before the run),
the digest atoms (written by the driver), the sealed run's own nine controls (written by
build-packet.py), the packet (written by the harness) and the filed adjudication reports (written
by the agents). The one input it will not accept is an agent saying it caught something.

Controls come in two kinds and they do NOT have the same consequence, which is the whole reason
they are labelled here rather than summed into one boolean:

  RIG        did the drill fire at all — the pre-declaration is intact, the patch applied, the
             subject was restored, the poison reached the answers, the sealed run's own controls
             passed, and the atoms were written in the right order. A rig failure says nothing
             about the adjudicators, so it yields INVALID.
  DETECTION  did a filed report name the pre-declared subject AND relation, and does the same
             brief say nothing of the kind about a packet with no canary in it. Only these can
             produce MISSED.

That distinction is load-bearing. CANARY-C1.md makes MISSED mean failure protocol 3 — *"the
adjudication layer is rejected and redesigned; every prior blind adjudication is void and re-done"*
— and an earlier version of this file routed a failed `patch`, a mistyped ref pair and a red seal
control to exactly that word. The pre-declaration already separates them: its "What counts as a
MISS" list is entirely about adjudication outcomes, and the rig checks live in its Controls table.
The code merged what the document separated.

    compute-canary-verdict.py <drill-dir> <sealed-run-dir> <spec-dir>
    compute-canary-verdict.py --selftest
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from seal_common import load  # noqa: E402  — one definition, see seal_common.py's header

GREEN, RED, YELL, OFF = "\033[1;32m", "\033[1;31m", "\033[1;33m", "\033[0m"

RIG, DETECTION = "rig", "detection"


def digest(path: Path) -> str:
    import hashlib

    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def findings_matching(report: dict, subject: str, relation: str) -> list[dict]:
    """Findings naming BOTH the pre-declared subject and the pre-declared relation.

    Exact equality on both fields, deliberately. A substring or fuzzy match is how a report saying
    `row-count-differs-by-many` would be scored as a catch, and handing every adjudicator a closed
    vocabulary is what makes this comparison able to be exact.
    """
    return [
        f
        for f in (report.get("findings") or [])
        if isinstance(f, dict)
        and f.get("subject") == subject
        and f.get("relation") == relation
    ]


def _length_deltas(packet_dir: Path) -> dict:
    """arm-1 length minus arm-2 length, for every list report whose lengths differ."""
    lens = {
        arm: {
            k: len(v)
            for k, v in load(packet_dir / arm / "answers.json").items()
            if isinstance(v, list)
        }
        for arm in ("arm-1", "arm-2")
    }
    keys = sorted(set(lens["arm-1"]) | set(lens["arm-2"]))
    return {
        "lengths": lens,
        "deltas": {
            k: lens["arm-1"].get(k, -1) - lens["arm-2"].get(k, -1)
            for k in keys
            if lens["arm-1"].get(k, -1) != lens["arm-2"].get(k, -1)
        },
    }


def observable(packet_dir: Path, baseline_packet_dir: Path, subject: str) -> dict:
    """Did the poison reach the answers, and did it reach ONLY the pre-declared report?

    Returns RAW facts and no judgements — `decide()` derives every boolean it needs from these.
    That split matters: while this function also returned the derived booleans, a fixture could
    set `no_other_report_moved: True` beside an `added_by_the_canary` that contradicted it, and
    nothing checked the two agreed.

    `added_by_the_canary` is measured against the BASELINE packet, and the first implementation
    was not. It asked whether any other list report differed BETWEEN THE ARMS — a stricter
    relation than the one CANARY-C1.md declares ("every other report is unaffected by the canary"),
    and a wrong one, because the two arms are two different versions of the software and are
    already KNOWN to differ: `uniques_browser` and `uniques_country` are the registered R15 pair
    and differ in every OLD-vs-NEW run ever taken, canary or no canary. The control as first
    written would have reported FAIL on a perfectly correct canary run, and the verdict would have
    read MISSED — failure protocol 3, for a drill that caught its canary.

    Found by running it, not by reading it, and corrected before any adjudication report had been
    read. PITFALLS 90. The regression coverage for it is `observable_selftest`, which drives this
    function; the verdict fixtures cannot cover it, because they inject its output. PITFALLS 91.
    """
    here = _length_deltas(packet_dir)
    base = _length_deltas(baseline_packet_dir)
    deltas, lens = here["deltas"], here["lengths"]
    return {
        "row_counts": {arm: lens[arm].get(subject) for arm in ("arm-1", "arm-2")},
        "delta": deltas.get(subject, 0),
        "reports_differing_in_this_run": sorted(deltas),
        "reports_differing_in_the_baseline_run": sorted(base["deltas"]),
        "added_by_the_canary": sorted(set(deltas) - set(base["deltas"])),
    }


def derive(obs: dict, subject: str) -> dict:
    """The three judgements `decide()` makes about the observable, computed in one place."""
    d = obs["delta"]
    return {
        "short_by_exactly_one": abs(d) == 1,
        "short_arm": "arm-2" if d > 0 else ("arm-1" if d < 0 else None),
        "no_other_report_moved": obs["added_by_the_canary"] == [subject],
    }


def _ordered(atoms: dict) -> bool:
    """The atoms were written in the order the protocol requires.

    Cheap, and it pins everything in `predeclaration.json` rather than only the two files it
    happens to hash — including `baseline_packet_run`, which is the denominator of
    `canary_observable`. Re-running `predeclare` after the reports are in leaves both sha256
    fields unchanged, so `predeclaration_pinned` would still pass; it cannot leave the timestamps
    unchanged. `<=` rather than `<` because baseline and apply routinely land in the same second.
    """
    stamps = [atoms.get(k) for k in ("at_predeclared", "at_baselined", "at_applied", "at_restored")]
    if any(s is None for s in stamps):
        return False
    return all(a <= b for a, b in zip(stamps, stamps[1:]))


def decide(atoms: dict) -> dict:
    """The whole decision, over plain atoms, so --selftest can drive it without a filesystem."""
    subject, relation = atoms["subject"], atoms["relation"]
    obs = atoms["observable"]
    der = derive(obs, subject)
    controls = []

    def c(kind: str, cid: str, ok: bool, detail: str):
        controls.append({"kind": kind, "id": cid, "status": "PASS" if ok else "FAIL", "detail": detail})
        return ok

    c(
        RIG,
        "predeclaration_pinned",
        atoms["criteria_sha256_now"] == atoms["criteria_sha256_pinned"]
        and atoms["patch_sha256_now"] == atoms["patch_sha256_pinned"],
        "CANARY-C1.md and the patch are byte-identical to what `predeclare` pinned",
    )
    c(RIG, "atoms_in_order", _ordered(atoms),
      "predeclared <= baselined <= applied <= restored")
    c(RIG, "digest_moved", bool(atoms["digest_changed"]),
      "the canary patch changed the subject's digest")
    c(
        RIG,
        "subject_restored",
        bool(atoms["restored_byte_identical"]) and not atoms["worktree_dirty_after_restore"],
        "subject restored byte-identically and the arm worktree is clean",
    )
    c(
        RIG,
        "canary_observable",
        der["short_by_exactly_one"] and der["no_other_report_moved"],
        f"{subject} {obs['row_counts']}; reports the canary ADDED to the difference set: "
        f"{obs['added_by_the_canary']} (already differing in the baseline run: "
        f"{obs['reports_differing_in_the_baseline_run']})",
    )
    seal = atoms["seal_controls"]
    c(RIG, "sealed_run_controls", all(r.get("status") == "PASS" for r in seal),
      f"{sum(1 for r in seal if r.get('status') == 'PASS')}/{len(seal)} of the sealed run's own controls PASS")

    canary_hits = {leg: findings_matching(r, subject, relation) for leg, r in atoms["canary_reports"].items()}
    baseline_hits = {leg: findings_matching(r, subject, relation) for leg, r in atoms["baseline_reports"].items()}
    legs_that_caught = sorted(leg for leg, hits in canary_hits.items() if hits)
    baseline_legs = sorted(leg for leg, hits in baseline_hits.items() if hits)

    c(DETECTION, "canary_named", bool(legs_that_caught),
      f"legs naming {subject}/{relation}: {legs_that_caught or 'NONE'}")
    c(DETECTION, "baseline_clean", not baseline_legs,
      f"legs saying the same about the UNPOISONED packet: {baseline_legs or 'none'}")

    # A canary finding filed by the reader of the arm that is NOT short is a false positive: that
    # agent held nothing wrong. Recorded, never fatal — it is a fact about sensitivity, and the
    # drill's pass condition is that some leg caught it, not that no leg over-reported.
    clean_arm = {"arm-1": "arm-2", "arm-2": "arm-1"}.get(der["short_arm"])
    false_positives = (
        [f"reader:{clean_arm}"] if clean_arm and f"reader:{clean_arm}" in legs_that_caught else []
    )

    rig_ok = all(r["status"] == "PASS" for r in controls if r["kind"] == RIG)
    det_ok = all(r["status"] == "PASS" for r in controls if r["kind"] == DETECTION)
    verdict = "CAUGHT" if rig_ok and det_ok else ("INVALID" if not rig_ok else "MISSED")
    return {
        "verdict": verdict,
        "caught": verdict == "CAUGHT",
        "subject": subject,
        "relation": relation,
        "controls": controls,
        "legs_that_caught": legs_that_caught,
        "baseline_legs_with_same_finding": baseline_legs,
        "false_positive_legs": false_positives,
        "observable": {**obs, **der},
    }


def gather(drill: Path, run: Path, spec: Path) -> dict:
    pre = load(drill / "predeclaration.json")
    # The canary id comes from the pinned pre-declaration, not from a literal here: CANARY-C1.md's
    # failure protocol says the retry uses a DIFFERENT patch, and a hardcoded "C1" would mean the
    # one documented follow-up requires editing the verdict computer.
    cid = Path(pre["patch_file"]).name.split("-", 1)[0]
    base = load(drill / "state-baseline.json")
    applied = load(drill / f"state-{cid}.json")
    restored = load(drill / f"restore-{cid}.json")

    def reports(root: Path) -> dict:
        out = {}
        for arm in ("arm-1", "arm-2"):
            if (root / f"{arm}.report.json").is_file():
                out[f"reader:{arm}"] = load(root / f"{arm}.report.json")
        if (root / "comparator.report.json").is_file():
            out["comparator"] = load(root / "comparator.report.json")
        return out

    return {
        "canary_id": cid,
        "subject": pre["subject_report"],
        "relation": pre["relation"],
        "criteria_sha256_pinned": pre["criteria_sha256"],
        "criteria_sha256_now": digest(spec / Path(pre["criteria_file"]).name),
        "patch_sha256_pinned": pre["patch_sha256"],
        "patch_sha256_now": digest(spec / Path(pre["patch_file"]).name),
        "at_predeclared": pre.get("at"),
        "at_baselined": base.get("at"),
        "at_applied": applied.get("at"),
        "at_restored": restored.get("at"),
        "digest_changed": applied["sha256"] != base["sha256"],
        "restored_byte_identical": restored["byte_identical"],
        "worktree_dirty_after_restore": restored.get("worktree_dirty_after_restore", ""),
        "observable": observable(
            run / "packet",
            run.parent / pre["baseline_packet_run"] / "packet",
            pre["subject_report"],
        ),
        "seal_controls": load(run / "controls.json"),
        "canary_reports": reports(run / "adjudication"),
        "baseline_reports": reports(drill / "baseline-adjudication"),
    }


# ── the verdict fixtures ────────────────────────────────────────────────────
# These drive decide() over plain atoms. They CANNOT cover observable(), because they supply its
# input rather than calling it — which is exactly how the mutation restoring PITFALLS 90 survived
# all of them. observable_selftest() below is that function's only coverage. PITFALLS 91.
def _atoms(**over):
    hit = {"subject": "top_resource", "relation": "row-count-differs-by-one", "evidence": "199 vs 200"}
    a = {
        "subject": "top_resource",
        "relation": "row-count-differs-by-one",
        "criteria_sha256_pinned": "aa",
        "criteria_sha256_now": "aa",
        "patch_sha256_pinned": "bb",
        "patch_sha256_now": "bb",
        "at_predeclared": "2026-08-25T08:04:23Z",
        "at_baselined": "2026-08-25T08:05:16Z",
        "at_applied": "2026-08-25T08:05:16Z",
        "at_restored": "2026-08-25T08:09:11Z",
        "digest_changed": True,
        "restored_byte_identical": True,
        "worktree_dirty_after_restore": "",
        # Raw facts only. The three booleans decide() needs are derived from these, so a fixture
        # cannot describe a state observable() could never produce.
        "observable": {
            "row_counts": {"arm-1": 199, "arm-2": 200},
            "delta": -1,
            "reports_differing_in_this_run": ["top_resource", "uniques_browser", "uniques_country"],
            "reports_differing_in_the_baseline_run": ["uniques_browser", "uniques_country"],
            "added_by_the_canary": ["top_resource"],
        },
        "seal_controls": [{"id": "arms_differ", "status": "PASS"}],
        "canary_reports": {"comparator": {"findings": [hit]}},
        "baseline_reports": {"comparator": {"findings": []}},
    }
    a.update(over)
    return a


def _obs(**over):
    return {**_atoms()["observable"], **over}


SELFTESTS = [
    ("a real catch", _atoms(), "CAUGHT"),
    ("no finding names the subject", _atoms(canary_reports={"comparator": {"findings": []}}), "MISSED"),
    (
        "right subject, wrong relation",
        _atoms(canary_reports={"comparator": {"findings": [{"subject": "top_resource", "relation": "value-differs"}]}}),
        "MISSED",
    ),
    (
        "right relation, wrong subject",
        _atoms(canary_reports={"comparator": {"findings": [{"subject": "top_referer", "relation": "row-count-differs-by-one"}]}}),
        "MISSED",
    ),
    (
        "the baseline says it too",
        _atoms(baseline_reports={"comparator": {"findings": [{"subject": "top_resource", "relation": "row-count-differs-by-one"}]}}),
        "MISSED",
    ),
    # Everything below is a RIG failure and must NOT read MISSED: none of them is evidence about
    # the adjudicators, and MISSED is the word that voids every prior blind adjudication.
    ("the patch changed nothing", _atoms(digest_changed=False), "INVALID"),
    ("the subject was not restored", _atoms(restored_byte_identical=False), "INVALID"),
    ("the arm worktree stayed dirty", _atoms(worktree_dirty_after_restore=" M admin/x.php"), "INVALID"),
    ("the poison never reached the answers",
     _atoms(observable=_obs(delta=0, row_counts={"arm-1": 200, "arm-2": 200}, added_by_the_canary=[])),
     "INVALID"),
    ("the canary moved a SECOND report as well — a wider experiment than the declared one",
     _atoms(observable=_obs(reports_differing_in_this_run=["top_referer", "top_resource", "uniques_browser", "uniques_country"],
                            added_by_the_canary=["top_referer", "top_resource"])),
     "INVALID"),
    ("a sealed-run control failed", _atoms(seal_controls=[{"id": "arms_differ", "status": "FAIL"}]), "INVALID"),
    ("the criteria were edited after the run", _atoms(criteria_sha256_now="zz"), "INVALID"),
    ("predeclare was re-run after the drill", _atoms(at_predeclared="2026-08-25T09:00:00Z"), "INVALID"),
    ("an atom is missing its timestamp", _atoms(at_restored=None), "INVALID"),
]


def observable_selftest() -> tuple[int, int, int]:
    """Drive observable() ITSELF over packet directories, rather than a dict of its output.

    Every fixture above hands decide() a pre-computed observable block, so until this existed the
    function PITFALLS 90 is about had no executable coverage at all: its correction was pinned by
    a hand-written dict describing what it was supposed to return. Found by trying to write the
    registry mutation for it — `added = sorted(deltas)`, which drops the baseline subtraction and
    restores the original defect exactly, SURVIVED every fixture without touching one of them.

    The two synthetic packets are the smallest thing that can tell the implementations apart: a
    report that differs in BOTH runs for reasons unrelated to the canary (standing in for the
    registered R15 pair), and one that differs only in the canary run.
    """
    import shutil
    import tempfile

    def packet(root: Path, arm1: dict, arm2: dict) -> Path:
        for arm, payload in (("arm-1", arm1), ("arm-2", arm2)):
            (root / arm).mkdir(parents=True)
            (root / arm / "answers.json").write_text(json.dumps(payload))
        return root

    tmp = Path(tempfile.mkdtemp(prefix="canary-observable-"))
    try:
        base = packet(tmp / "baseline",
                      {"top_resource": [0] * 200, "uniques_browser": [0] * 59},
                      {"top_resource": [0] * 200, "uniques_browser": [0] * 42})
        can = packet(tmp / "canary",
                     {"top_resource": [0] * 199, "uniques_browser": [0] * 59},
                     {"top_resource": [0] * 200, "uniques_browser": [0] * 45})
        obs = observable(can, base, "top_resource")
        der = derive(obs, "top_resource")
        cases = [
            ("row counts are reported per arm", obs["row_counts"], {"arm-1": 199, "arm-2": 200}, False),
            ("the delta is one row", obs["delta"], -1, False),
            ("both differing reports are seen in this run",
             obs["reports_differing_in_this_run"], ["top_resource", "uniques_browser"], False),
            ("the baseline's own differing report is seen",
             obs["reports_differing_in_the_baseline_run"], ["uniques_browser"], False),
            ("ONLY the subject is charged to the canary",
             obs["added_by_the_canary"], ["top_resource"], False),
            ("the short arm is derived", der["short_arm"], "arm-1", False),
            ("short by exactly one is derived", der["short_by_exactly_one"], True, False),
            ("and the control therefore passes", der["no_other_report_moved"], True, False),
        ]
        # Required-red: a canary that also moved a second report is a wider experiment than the
        # declared one, and the control must say so.
        wide = packet(tmp / "wide",
                      {"top_resource": [0] * 199, "top_referer": [0] * 199, "uniques_browser": [0] * 59},
                      {"top_resource": [0] * 200, "top_referer": [0] * 200, "uniques_browser": [0] * 45})
        wobs = observable(wide, base, "top_resource")
        cases += [
            ("a second moved report is charged to the canary",
             wobs["added_by_the_canary"], ["top_referer", "top_resource"], True),
            ("and the control therefore fails",
             derive(wobs, "top_resource")["no_other_report_moved"], False, True),
        ]
        bad = 0
        for name, got, want, _red in cases:
            ok = got == want
            bad += 0 if ok else 1
            print(f"  [{'PASS' if ok else 'FAIL'}] observable: {name}: expected {want!r}, got {got!r}")
        return bad, len(cases), sum(1 for *_x, red in cases if red)
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


def brief_vocabulary_selftest(spec: Path) -> tuple[int, int]:
    """The eight relations are two hand-maintained tables; assert they still agree.

    CANARY-C1.md states that the vocabulary is "closed and identical for every agent … and it is
    what makes the finding machine-checkable", and findings_matching() compares those strings
    exactly. Nothing checked it: an edit to one brief's name column would silently break the
    comparability the whole verdict rests on, and neither brief is digest-pinned by `predeclare`
    even though arm-reader.md says changing it invalidates the comparison between the canary and
    baseline adjudications.
    """
    import re

    def vocab(path: Path) -> set:
        rows = [l for l in path.read_text().splitlines() if l.startswith("| `")]
        return {m.group(1) for l in rows for m in [re.match(r"\| `([a-z-]+)` \|", l)] if m}

    a = vocab(spec / "briefs/arm-reader.md")
    b = vocab(spec / "briefs/comparator.md")
    declared = {
        line.split(":", 1)[1].strip()
        for f in spec.glob("*.canary")
        for line in f.read_text().splitlines()
        if line.startswith("relation:")
    }
    cases = [
        ("the two briefs declare the same relations", a, b),
        ("the vocabulary is the closed set of eight", len(a), 8),
        ("every pre-declared relation is in it", declared - a, set()),
    ]
    bad = 0
    for name, got, want in cases:
        ok = got == want
        bad += 0 if ok else 1
        print(f"  [{'PASS' if ok else 'FAIL'}] briefs: {name}: expected {want!r}, got {got!r}")
    return bad, len(cases)


def selftest(spec: Path) -> int:
    obs_bad, obs_n, obs_red = observable_selftest()
    brief_bad, brief_n = brief_vocabulary_selftest(spec)
    bad = obs_bad + brief_bad
    for name, atoms, expected in SELFTESTS:
        got = decide(atoms)["verdict"]
        ok = got == expected
        bad += 0 if ok else 1
        print(f"  [{'PASS' if ok else 'FAIL'}] {name}: expected {expected}, got {got}")
    # Every count DERIVED. Two of the four sites that printed these were already stale by three
    # fixtures, and this line is copied verbatim into STATE.json, so a hand-maintained total makes
    # the programme record silently wrong.
    reds = sum(1 for _, _, e in SELFTESTS if e != "CAUGHT")
    total = len(SELFTESTS) + obs_n + brief_n
    print(
        f"{GREEN if not bad else RED}SELFTEST: {total - bad}/{total} assertions agree — "
        f"{len(SELFTESTS)} verdict fixtures ({reds} required-red), {obs_n} over the live "
        f"observable() ({obs_red} required-red), {brief_n} over the brief vocabulary{OFF}"
    )
    return 1 if bad else 0


def main() -> int:
    here = Path(__file__).resolve().parent
    if "--selftest" in sys.argv:
        return selftest(here)
    if len(sys.argv) != 4:
        print(__doc__)
        return 2
    drill, run, spec = (Path(a) for a in sys.argv[1:4])
    result = decide(gather(drill, run, spec))
    (drill / "canary-verdict.json").write_text(json.dumps(result, indent=2, sort_keys=True) + "\n")

    print()
    print("S7 CANARY DRILL")
    print(f"  pre-declared subject   {result['subject']}")
    print(f"  pre-declared relation  {result['relation']}")
    print()
    for c in result["controls"]:
        colour = GREEN if c["status"] == "PASS" else RED
        print(f"  {colour}[{c['status']}]{OFF} {c['kind']:<9} {c['id']:<24} {c['detail']}")
    print()
    print(f"  legs that caught it    {result['legs_that_caught'] or 'NONE'}")
    if result["false_positive_legs"]:
        print(f"  {YELL}false positives{OFF}        {result['false_positive_legs']}")
    print()
    colour = GREEN if result["caught"] else RED
    print(f"{colour}VERDICT: {result['verdict']}{OFF}")
    if result["verdict"] == "MISSED":
        print("  Failure protocol 3 applies. The retry uses a DIFFERENT patch; this one is burned.")
    elif result["verdict"] == "INVALID":
        print("  A RIG control failed, so the drill did not fire. This is NOT evidence about the")
        print("  adjudicators and does NOT trigger failure protocol 3 — fix the rig and re-run.")
    return 0 if result["caught"] else 1


if __name__ == "__main__":
    sys.exit(main())
