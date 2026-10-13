#!/usr/bin/env python3
"""File adjudication reports into a sealed run, refusing any that cannot be tied to what it read.

    file-reports.py <sealed-run> <staged-packet-dir> <agent-json> [<agent-json> ...]
    file-reports.py --selftest

Each `<agent-json>` is a workflow result object holding one or more of `arm-1`, `arm-2`,
`comparator`. Three checks stand between an agent's output and the sealed run's evidence
directory, and this refuses rather than filing when any fails:

  1. DIGEST ATTESTATION. The report says which bytes it read. If that digest is not the digest of
     the file it was given, the analysis is discarded — that is what stops a cached, stale or
     hallucinated reading from being counted (the reachability gate's rule, one layer up).
  2. THE OTHER ARM IS UNMENTIONED. seal-tool.py refuses to unseal a run whose arm-1 report
     contains the string "arm-2" or vice versa, because a reader that names the other arm was not
     reading one arm. Checked here so the failure names the offending report rather than
     surfacing four steps later as a seal refusal.
  3. saw_mapping IS FALSE. A comparator that read the mapping is not a blind comparator.

Check 2 is deliberately a second implementation of a rule seal-tool.py also enforces — the point
is error locality, catching it at the filing rather than at the unseal. That makes the selftest
below load-bearing rather than optional: the duplicate is the copy nobody validates, and two
implementations of one rule drift. It had none until the S7 review, while the verdict computer
next to it had 27 assertions and four registered mutations.
"""
from __future__ import annotations

import hashlib
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from seal_common import manifest_digest  # noqa: E402  — one definition, see seal_common.py


class Refused(Exception):
    """A report that cannot be tied to what it read. Raised so --selftest can assert on it."""


def file_reports(run: Path, staged: Path, reports: dict, write: bool = True) -> dict:
    actual = {
        arm: hashlib.sha256((staged / arm / "answers.json").read_bytes()).hexdigest()
        for arm in ("arm-1", "arm-2")
    }
    out = run / "adjudication"
    if write:
        out.mkdir(parents=True, exist_ok=True)
    filed = {}

    for arm in ("arm-1", "arm-2"):
        r = reports.get(arm)
        if r is None:
            raise Refused(f"{arm} has no report — a leg that filed nothing is not a leg that found nothing")
        if r.get("answers_sha256") != actual[arm]:
            raise Refused(f"{arm} attests {r.get('answers_sha256')} but was given {actual[arm]}")
        other = "arm-2" if arm == "arm-1" else "arm-1"
        # On what the AGENT wrote, before augmentation — the augmented fields are a digest and the
        # arm's own label, neither of which can carry the other arm's name. Checking the agent's
        # own text is what the rule is about.
        if other in json.dumps(r):
            raise Refused(f"{arm}'s report mentions {other} — it was not reading one arm")
        r = {**r, "arm": arm, "packet_sha256": manifest_digest(run, arm)}
        filed[arm] = r
        if write:
            (out / f"{arm}.report.json").write_text(json.dumps(r, indent=2, sort_keys=True) + "\n")

    c = reports.get("comparator")
    if c is None:
        raise Refused("no comparator report")
    if c.get("a_sha256") != actual["arm-1"] or c.get("b_sha256") != actual["arm-2"]:
        raise Refused("comparator attests digests that are not the files it was given")
    if c.get("saw_mapping") is not False:
        raise Refused("comparator reports saw_mapping=true — that is not a blind comparison")
    c = {**c,
         "arm-1": {"packet_sha256": manifest_digest(run, "arm-1")},
         "arm-2": {"packet_sha256": manifest_digest(run, "arm-2")}}
    filed["comparator"] = c
    if write:
        (out / "comparator.report.json").write_text(json.dumps(c, indent=2, sort_keys=True) + "\n")
    return filed


def selftest() -> int:
    """One accepted case and one required-red per refusal, over a synthetic run and packet."""
    import shutil
    import tempfile

    tmp = Path(tempfile.mkdtemp(prefix="canary-file-reports-"))
    try:
        run, staged = tmp / "run", tmp / "staged"
        digests = {}
        for arm in ("arm-1", "arm-2"):
            (staged / arm).mkdir(parents=True)
            body = json.dumps({"top_resource": [arm]})
            (staged / arm / "answers.json").write_text(body)
            digests[arm] = hashlib.sha256(body.encode()).hexdigest()
            (run / "packet" / arm).mkdir(parents=True)
        (run / "packet/MANIFEST.sha256").write_text(
            "aa  packet/arm-1/answers.json\nbb  packet/arm-2/answers.json\n")

        def good():
            return {
                "arm-1": {"answers_sha256": digests["arm-1"], "findings": []},
                "arm-2": {"answers_sha256": digests["arm-2"], "findings": []},
                "comparator": {"a_sha256": digests["arm-1"], "b_sha256": digests["arm-2"],
                               "saw_mapping": False, "findings": []},
            }

        def mutate(**over):
            r = good()
            for k, v in over.items():
                r[k.replace("_", "-", 1) if k.startswith("arm") else k] = v
            return r

        cases = [
            ("a well-formed set is filed", good(), None),
            ("a wrong digest is refused",
             mutate(arm_1={"answers_sha256": "deadbeef", "findings": []}), "attests"),
            ("a missing arm report is refused", {k: v for k, v in good().items() if k != "arm-2"}, "no report"),
            ("an arm naming the other arm is refused",
             mutate(arm_1={"answers_sha256": digests["arm-1"],
                           "findings": [{"evidence": "arm-2 holds 200"}]}), "mentions arm-2"),
            ("a comparator that saw the mapping is refused",
             mutate(comparator={"a_sha256": digests["arm-1"], "b_sha256": digests["arm-2"],
                                "saw_mapping": True, "findings": []}), "saw_mapping"),
            ("a comparator attesting the wrong bytes is refused",
             mutate(comparator={"a_sha256": "cafe", "b_sha256": digests["arm-2"],
                                "saw_mapping": False, "findings": []}), "not the files"),
            ("a missing comparator is refused", {k: v for k, v in good().items() if k != "comparator"}, "no comparator"),
        ]
        bad = 0
        for name, reports, expect in cases:
            try:
                file_reports(run, staged, reports, write=False)
                got = None
            except Refused as exc:
                got = str(exc)
            ok = (expect is None and got is None) or (expect is not None and got is not None and expect in got)
            bad += 0 if ok else 1
            detail = "accepted" if got is None else f"refused: {got[:60]}"
            print(f"  [{'PASS' if ok else 'FAIL'}] file-reports: {name} — {detail}")
        reds = sum(1 for _, _, e in cases if e is not None)
        colour = "\033[1;32m" if not bad else "\033[1;31m"
        print(f"{colour}SELFTEST: {len(cases) - bad}/{len(cases)} assertions agree "
              f"({reds} required-red)\033[0m")
        return 1 if bad else 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


def main() -> int:
    if "--selftest" in sys.argv:
        return selftest()
    if len(sys.argv) < 4:
        print(__doc__)
        return 2
    run, staged = Path(sys.argv[1]), Path(sys.argv[2])
    reports: dict = {}
    for path in sys.argv[3:]:
        blob = json.loads(Path(path).read_text())
        reports.update(blob.get("result", blob))
    try:
        filed = file_reports(run, staged, reports)
    except Refused as exc:
        print(f"REFUSED: {exc}", file=sys.stderr)
        return 1
    for name, r in filed.items():
        print(f"  filed {name}.report.json   digest attested and matched   findings={len(r['findings'])}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
