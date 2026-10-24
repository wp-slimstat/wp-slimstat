#!/usr/bin/env python3
from __future__ import annotations
import json,os,re,subprocess,sys,tempfile
from pathlib import Path

HERE=Path(__file__).resolve().parent
PLUGIN=HERE.parent.parent

def fail(message):
    print(f"SEAL GATE: {message}",file=sys.stderr); raise SystemExit(1)

# The name the seal itself mints. Defined once and used by BOTH the artifact census (a directory
# wearing this name can never be excused by the allowlist) and behavior_gate's neutrality check
# further down, which previously spelled it inline.
RUN_DIR_NAME=re.compile(r"R\d{8}-[0-9a-f]{6}")

def static_gate(subject):
    text=subject.read_text()
    if "source \"$HERE/seal-lib.sh\"" not in text or "seal_flip " not in text:
        fail(f"{subject.relative_to(PLUGIN)} writes a run directory and never calls seal_flip")

def artifact_gate(root,declared):
    # A standalone Free checkout has no sibling programme archive. There is nothing to census
    # there; behavior_gate still proves the real caller creates sealed artifacts.
    if not root.is_dir():
        return
    allowed=set()
    if declared.is_file():
        allowed={line.strip() for line in declared.read_text().splitlines() if line.strip() and not line.startswith("#")}
    for directory in sorted(path for path in root.iterdir() if path.is_dir()):
        if (directory/"flip.json").is_file() and (directory/".sealed/mapping.json").is_file():
            continue
        # A NAME THE SEAL ITSELF ISSUES CAN NEVER BE EXCUSED BY NAME. seal_new_run_dir mints
        # `R<date>-<6 hex>` and behavior_gate already asserts every sealed run carries that shape,
        # so a directory wearing it and missing its flip.json is a run whose seal did not complete
        # — the one case the allowlist must not be able to wave through. The allowlist is still
        # the right mechanism for everything else (a line in a tracked file is a reviewed human
        # edit; a marker file inside the directory would be written by whatever failed to seal),
        # but an allowlist with no structural floor is one forgotten `git add` away from
        # excusing exactly what the census exists to catch.
        if RUN_DIR_NAME.fullmatch(directory.name):
            fail(f"{directory} is named like a sealed run but has no flip.json — a seal that did not complete cannot be excused by name, and adding it to runs-not-sealed-comparisons.txt will not help")
        if directory.name in allowed:
            continue
        fail(f"{directory} has no flip.json and is not declared in runs-not-sealed-comparisons.txt — something wrote a run directory without sealing it")

def behavior_gate():
    with tempfile.TemporaryDirectory(prefix="slimstat-seal-gate-") as tmp:
        root=Path(tmp); runs=[]; a=subprocess.check_output(["git","rev-parse","HEAD"],cwd=PLUGIN,text=True).strip()
        # CI checks out depth 1, so HEAD^ is not a portable second arm. Create a dangling test
        # commit over the same tree: it is a valid worktree ref, differs in identity, changes no
        # refs or files, and remains available for all twenty dry runs.
        b=subprocess.check_output([
            "git","-c","user.name=SlimStat Seal Gate","-c","user.email=seal-gate@example.invalid",
            "commit-tree","HEAD^{tree}","-m","seal gate fixture"],cwd=PLUGIN,text=True).strip()
        # Popped, not inherited. A gate whose polarity is set by ambient shell state is not a
        # guard: with SLIMSTAT_SEED_PROFILE exported, reverting the default would leave this
        # green. The gate exists to observe what the entry point CHOOSES.
        env=dict(os.environ,SLIMSTAT_RUNS_ROOT=str(root),SLIMSTAT_SEAL_DRYRUN="1")
        for inherited in ("SLIMSTAT_SEED_PROFILE","SLIMSTAT_COMPARE_CMD"): env.pop(inherited,None)
        # DRAW ORDER, captured from each run as it happens.
        #
        # This used to read `sorted(root.iterdir())` afterwards — directory name order, and a run
        # id is `R<date>-<6 random hex>`, so the sort is a SHUFFLE of the draw sequence. Any test
        # of ORDER is then meaningless by construction, which is not academic: the runs half of
        # the fairness rule exists to catch a counter-backed source, and a counter's perfect
        # 0101… reads as R≈10 once shuffled. Measured with a counter mutation applied — ten draws
        # of 0,1,0,1,… came back as `1010101100`, R=8, indistinguishable from a fair coin. The
        # gate could not have failed that mutation however the band was set.
        #
        # verify-change.sh prints its run directory in the dry-run line, so the order is free.
        ordered=[]; last=None
        for _ in range(20):
            result=subprocess.run([str(HERE/"verify-change.sh"),a,b,"10","2"],cwd=PLUGIN,env=env,text=True,capture_output=True)
            if result.returncode: fail(f"verify-change dry run exited {result.returncode}: {result.stderr.strip()}")
            last=result
            token=result.stdout.split("SEAL DRYRUN:",1)[-1].strip().split()[0] if "SEAL DRYRUN:" in result.stdout else ""
            if not token: fail("a dry run did not name its run directory, so draw order is unknowable")
            ordered.append(Path(token))
        on_disk=sorted(path for path in root.iterdir() if path.is_dir())
        if len(on_disk)!=20: fail(f"20 runs produced {len(on_disk)} distinct run directories")
        # Two different questions: what the runs REPORTED, and what is on disk. A driver that
        # printed a path it did not write would satisfy one and not the other.
        if len(set(ordered))!=20 or set(ordered)!=set(on_disk):
            fail("the run directories named by the dry runs are not the ones on disk")
        # THE CORPUS THE ENTRY POINT SELECTS, read out of the run it just performed rather than
        # out of its source. compare-answers.sh defaults to the I8 profile so archived runs keep
        # meaning what they meant, and its header says "the campaign passes
        # seed-profile-verify.json" — a caller that did not exist. R20260824-a51bf2 therefore
        # seeded I8, and three extended surfaces were empty on BOTH arms: equal, and about a
        # question neither arm was asked. Nothing downstream can catch that, because the packet's
        # nine controls read the answers document and the vacuity lives in the caps file the
        # packet excludes. So it is caught here, before a container is paid for.
        # Bound explicitly rather than read off the loop variable: a loop that ran zero times
        # would raise NameError here instead of refusing by name, and a gate whose failure mode
        # is a traceback is a gate nobody can read.
        if last is not None and "driver=compare-answers.sh" not in last.stdout:
            fail("the entry point ran a substituted comparison driver — "
                 "SLIMSTAT_COMPARE_CMD is a test seam and must not decide a campaign run")
        if last is None or "corpus=seed-profile-verify.json" not in last.stdout:
            reported=(last.stdout.strip() if last else "(no run)")
            fail(f"the entry point does not select the campaign corpus — its dry run reports {reported or '(nothing)'}")
        bits=""
        for run in ordered:
            if not RUN_DIR_NAME.fullmatch(run.name): fail(f"run directory {run.name} is not neutral")
            mapping=json.load((run/".sealed/mapping.json").open())
            if oct((run/".sealed/mapping.json").stat().st_mode & 0o777)!="0o600": fail("mapping.json is not mode 600")
            bits += "0" if mapping["arm-1"]==mapping["ref_a"] else "1"
        # FATAL, and the band is DEGENERATE-SEQUENCE rather than a p-value. DESIGN.md §1:187
        # already ratified this shape — "the gate is deterministic; the population audit is
        # statistical … a CI gate that fails 1% of the time on a correct implementation is a gate
        # people learn to re-run" — and the code had implemented §6's older table instead.
        #
        # Why not the p-test that was here. Exhaustively over all 2^20 sequences, a FAIR coin
        # fails `seal-tool.py fairness` 1.190% of the time (T1 alone 0.258%, the Wald-Wolfowitz
        # runs test 0.937% — the normal approximation to R is poor at N=20). This step has no
        # `if:` and runs on six PHP lanes, so 6.93% of pushes -- one in fourteen -- went red on a
        # correct implementation. Observed here: one run gave k=3 of 20, and the five immediately
        # after gave 14, 8, 12, 10, 10.
        #
        # Why not merely "both assignments occur", which is where this briefly landed. That
        # catches a STUCK source and nothing else. A perfectly alternating source -- 0101... ,
        # k=10, R=20 -- passes it and passes the sign test, and alternation is not hypothetical
        # here: seal_draw_entropy takes `first_byte & 1`, so any counter-backed source alternates.
        # That is the exact mode DESIGN.md §1 gives the runs test for.
        #
        # So: reject only sequences no fair coin plausibly produces, in both modes. Measured
        # false-alarm rate 7.8e-5 per lane, 1 push in 2,132 -- rare enough that a red here is
        # worth reading rather than re-running. The statistical verdict stays with audit-flips,
        # where N can be large enough to carry one.
        k=bits.count("0"); alternations=1+sum(a!=b for a,b in zip(bits,bits[1:]))
        if k<=1 or k>=19:
            # The needle keeps the phrase S6-flip-stuck-entropy-01 has always pinned, because the
            # subject is the same defect and only the band around it widened.
            fail(f"20 of 20 runs assigned arm-1 to the same ref (k={k} of N=20)")
        if alternations>=19:
            fail(f"the flip alternates: R={alternations} runs in N=20, which is a counter, not entropy")

        # ADVISORY beside it: the full statistical verdict, printed so a marginal draw is visible
        # without being fatal.
        fair=subprocess.run([sys.executable,str(HERE/"seal-tool.py"),"fairness",bits],text=True,capture_output=True)
        artifact_gate(root,Path("/nonexistent"))
        verdict="within the fair band" if fair.returncode==0 else f"outside it (advisory) — {fair.stdout.strip()}"
        print(f"SEAL GATE: 20 behavioral runs passed; k={k}, R={alternations}, {verdict}")

def archive_control():
    with tempfile.TemporaryDirectory(prefix="slimstat-archive-control-") as tmp:
        root=Path(tmp); (root/"arm-1-6.0.0.answers.json").write_text('{"_arm_version":"6.0.0"}\n')
        result=subprocess.run([str(HERE/"scrub-audit.sh"),str(root)],capture_output=True)
        if result.returncode!=5: fail("the scrub audit passed an archive tree — it cannot tell a packet from an archive")

subject=Path(os.environ.get("SLIMSTAT_SEAL_SUBJECT",HERE/"verify-change.sh"))
static_gate(subject)
if os.environ.get("SLIMSTAT_SEAL_SKIP_BEHAVIOR")!="1": behavior_gate()
archive_control()
root=Path(os.environ.get("SLIMSTAT_SEAL_RUNS_ROOT",PLUGIN.parent/"jaan-to/outputs/dev/v6-performance/runs"))
declared=Path(os.environ.get("SLIMSTAT_SEAL_DECLARED_UNSEALED",HERE/"runs-not-sealed-comparisons.txt"))
artifact_gate(root,declared)
print("PASS: seal entrypoint — real caller sealed, archive distinguishable, artifact census complete")
