#!/usr/bin/env python3
from __future__ import annotations
import json,os,re,subprocess,sys,tempfile
from pathlib import Path

HERE=Path(__file__).resolve().parent
PLUGIN=HERE.parent.parent

def fail(message):
    print(f"SEAL GATE: {message}",file=sys.stderr); raise SystemExit(1)

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
        if directory.name in allowed:
            continue
        fail(f"{directory} has no flip.json and is not declared pre-S6 — something wrote a run directory without sealing it")

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
        last=None
        for _ in range(20):
            result=subprocess.run([str(HERE/"verify-change.sh"),a,b,"10","2"],cwd=PLUGIN,env=env,text=True,capture_output=True)
            if result.returncode: fail(f"verify-change dry run exited {result.returncode}: {result.stderr.strip()}")
            last=result
        runs=sorted(path for path in root.iterdir() if path.is_dir())
        if len(runs)!=20: fail(f"20 runs produced {len(runs)} distinct run directories")
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
        for run in runs:
            if not re.fullmatch(r"R\d{8}-[0-9a-f]{6}",run.name): fail(f"run directory {run.name} is not neutral")
            mapping=json.load((run/".sealed/mapping.json").open())
            if oct((run/".sealed/mapping.json").stat().st_mode & 0o777)!="0o600": fail("mapping.json is not mode 600")
            bits += "0" if mapping["arm-1"]==mapping["ref_a"] else "1"
        if len(set(bits))!=2: fail("20 of 20 runs assigned arm-1 to the same ref")
        fair=subprocess.run([sys.executable,str(HERE/"seal-tool.py"),"fairness",bits],text=True,capture_output=True)
        if fair.returncode: fail(f"flip fairness failed at N=20: {fair.stdout.strip()}")
        artifact_gate(root,Path("/nonexistent"))
        print(f"SEAL GATE: 20 behavioral runs passed; k={bits.count('0')} assignments to ref_a")

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
declared=Path(os.environ.get("SLIMSTAT_SEAL_PRE_S6",HERE/"pre-s6-runs.txt"))
artifact_gate(root,declared)
print("PASS: seal entrypoint — real caller sealed, archive distinguishable, artifact census complete")
