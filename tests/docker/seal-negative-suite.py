#!/usr/bin/env python3
from __future__ import annotations
import hashlib,json,os,shutil,subprocess,sys,tempfile
from pathlib import Path

HERE=Path(__file__).resolve().parent
PLUGIN=HERE.parent.parent
REF_A="a"*40; REF_B="b"*40
required=[]; controls=[]

def command(args,env=None):
    return subprocess.run(args,cwd=PLUGIN,env=dict(os.environ,**(env or {})),text=True,capture_output=True)

def shell(script,env=None):
    return command(["bash","-c",script],env)

def check(name,result,code,needle,control=False):
    output=result.stdout+result.stderr
    if result.returncode!=code or needle not in output:
        print(f"SEAL SUITE: {name} expected exit {code} and {needle!r}, got {result.returncode}\n{output}",file=sys.stderr)
        raise SystemExit(1)
    (controls if control else required).append(name)

# The one definition of a capture pair. These three numbers are not arbitrary — they are exactly
# what clears build-packet.py's `corpus_nontrivial`, `window_is_strict_subset` and
# `cardinality_past_cliff` thresholds. A second hand-transcribed copy in a shell stub meant that
# moving a threshold would fix the obvious place and silently leave the stub behind, and T11
# would then go GREEN VACUOUSLY: "no packet built" is also what you get when no packet COULD be
# built.
COMMON={"count_records_id":15000,"rows_in_window":10000,"count_records_resource":3000,
        "top_resource":[{"resource":"/a","counthits":2}]}

def capture_pair():
    return (dict(COMMON,_arm_version="5.5.1",_arm_fingerprint="fingerprint-a"),
            dict(COMMON,_arm_version="6.0.0",_arm_fingerprint="fingerprint-b"))

def compare_stub(path,verdict,code):
    """A compare-answers.sh that writes real captures and then returns `code`."""
    before,after=capture_pair()
    body="".join(f"cat > \"$A/{side}.json\" <<'JSON'\n{json.dumps(values)}\nJSON\n"
                 for side,values in (("before",before),("after",after)))
    path.write_text('#!/usr/bin/env bash\n'
                    'A="${WORK_ROOT:-/tmp}/answers/answers/artifacts"; mkdir -p "$A"\n'
                    f'{body}echo "VERDICT: {verdict}"; exit {code}\n')
    path.chmod(0o755); return path

def fixture(root,reports=True,build=True):
    result=command([str(HERE/"seal.sh"),"flip",str(root),REF_A,REF_B,"40","30","0"])
    if result.returncode: raise RuntimeError(result.stderr)
    run=Path(result.stdout.strip()); art=root/"art"; art.mkdir()
    before,after=capture_pair()
    (art/"before.json").write_text(json.dumps(before)+"\n")
    (art/"after.json").write_text(json.dumps(after)+"\n")
    if build:
        result=command([str(HERE/"build-packet.sh"),str(run),str(art),REF_A,REF_B])
        if result.returncode: raise RuntimeError(result.stderr)
    if reports: file_reports(run)
    return run,art

def digest(run,arm):
    lines=(run/"packet/MANIFEST.sha256").read_bytes().splitlines(keepends=True)
    return hashlib.sha256(b"".join(x for x in lines if f"packet/{arm}/".encode() in x)).hexdigest()

def file_reports(run):
    for arm in ("arm-1","arm-2"):
        (run/f"adjudication/{arm}.report.json").write_text(json.dumps({"arm":arm,"packet_sha256":digest(run,arm),"findings":[]})+"\n")
    (run/"adjudication/comparator.report.json").write_text(json.dumps({"saw_mapping":False,
      "arm-1":{"packet_sha256":digest(run,"arm-1")},"arm-2":{"packet_sha256":digest(run,"arm-2")}})+"\n")

with tempfile.TemporaryDirectory(prefix="slimstat-seal-negative-") as temp:
    root=Path(temp)
    run,_=fixture(root/"t0")
    check("T0",command([str(HERE/"seal.sh"),"--unseal",str(run)]),0,"arm-1=",True)
    check("T1",command([sys.executable,str(HERE/"seal-tool.py"),"fairness","0"*50]),1,"T1 FAIL")

    run,_=fixture(root/"t2ii")
    os.chmod(run/".sealed/mapping.json",0o644)
    check("T2-ii",shell(f'source "{HERE}/seal-lib.sh"; seal_assert_private "{run}/.sealed/mapping.json" 600'),3,"is mode 644, required 600")

    run,_=fixture(root/"t2i")
    check("T2-i",shell(f'source "{HERE}/seal-lib.sh"; seal_assert_private "{run}/.sealed/mapping.json" 600; echo PRIVATE_OK'),0,"PRIVATE_OK",True)

    run,_=fixture(root/"t2b")
    check("T2b",shell(f'source "{HERE}/seal-lib.sh"; seal_assert_private "{run}/.sealed/mapping.json" 600',
      {"SLIMSTAT_SEAL_TEST_OWNER":str(os.getuid()+1)}),3,"is owned by uid")

    run,_=fixture(root/"t2c"); os.chmod(run/".sealed",0o755)
    check("T2c",shell(f'source "{HERE}/seal-lib.sh"; seal_assert_private "{run}/.sealed" 700'),3,"is mode 755, required 700")

    run,_=fixture(root/"t3"); (run/"before").mkdir()
    check("T3",shell(f'source "{HERE}/seal-lib.sh"; seal_assert_neutral_names "{run}"'),3,"contains a direction, branch, or version marker")

    run,_=fixture(root/"t3b"); (run/"packet/after.json").write_text("{}\n")
    check("T3b",shell(f'source "{HERE}/seal-lib.sh"; seal_assert_neutral_names "{run}"'),3,"contains a direction, branch, or version marker")

    run,art=fixture(root/"t3c"); (run/"seal.json").write_text("{}\n")
    check("T3c",command([str(HERE/"build-packet.sh"),str(run),str(art),REF_A,REF_B]),3,"pre-S6 seal.json cannot be turned into a blind packet")

    run,_=fixture(root/"t4",reports=False)
    check("T4",command([str(HERE/"seal.sh"),"--unseal",str(run)]),4,"P4 unmet")

    run,_=fixture(root/"t4b"); report=json.load((run/"adjudication/arm-1.report.json").open()); report["packet_sha256"]="0"*64
    (run/"adjudication/arm-1.report.json").write_text(json.dumps(report)+"\n")
    check("T4b",command([str(HERE/"seal.sh"),"--unseal",str(run)]),4,"attests the wrong packet digest")

    run,_=fixture(root/"t4c"); first=command([str(HERE/"seal.sh"),"--unseal",str(run)])
    if first.returncode: raise RuntimeError(first.stderr)
    check("T4c",command([str(HERE/"seal.sh"),"--unseal",str(run)]),4,"P7 unmet")

    run,_=fixture(root/"t5a"); rows=json.load((run/"controls.json").open()); rows[0]["status"]="FAIL"
    (run/"controls.json").write_text(json.dumps(rows)+"\n")
    check("T5a",command([str(HERE/"seal.sh"),"--unseal",str(run)]),4,"status=FAIL")

    run,_=fixture(root/"t5b"); rows=json.load((run/"controls.json").open())[1:]
    (run/"controls.json").write_text(json.dumps(rows)+"\n")
    check("T5b",command([str(HERE/"seal.sh"),"--unseal",str(run)]),4,"control 'arms_differ' is absent")

    run,_=fixture(root/"t6"); (run/"packet/arm-1/leak.json").write_text('{"_arm_version":"x"}\n')
    check("T6",command([str(HERE/"scrub-audit.sh"),str(run/"packet")]),5,"forbidden class 'arm-metadata'")

    run,_=fixture(root/"t6b"); (run/"packet/arm-1/leak.json").write_text('{"live_window_end":true}\n')
    check("T6b",command([str(HERE/"scrub-audit.sh"),str(run/"packet")]),5,"forbidden class 'capability'")

    check("T6c",command([str(HERE/"scrub-audit.sh"),"--selftest","capability"]),6,"class 'capability' did not fire")
    check("T7a",command([sys.executable,str(HERE/"seal-tool.py"),"fairness","0"*50]),1,"T2 FAIL")
    check("T7b",command([sys.executable,str(HERE/"seal-tool.py"),"fairness","01"*25]),1,"T2 FAIL")
    check("T7c",shell(f'source "{HERE}/seal-lib.sh"; seal_validate_entropy aa'),3,"requires 32 bytes")
    check("T7d",command([sys.executable,str(HERE/"seal-tool.py"),"fairness","00101101001110100101100110100110110010110010101101"]),0,"T1 PASS",True)

    subject=root/"bypass.sh"; text=(HERE/"verify-change.sh").read_text().replace("seal_flip ","seal_bypass ")
    subject.write_text(text)
    check("T8",command([str(HERE/"seal-entrypoint-gate.sh")],
      {"SLIMSTAT_SEAL_SUBJECT":str(subject),"SLIMSTAT_SEAL_SKIP_BEHAVIOR":"1","SLIMSTAT_SEAL_RUNS_ROOT":str(root/"none")}),1,"writes a run directory and never calls seal_flip")

    archive=root/"archive"; archive.mkdir(); (archive/"arm-1-6.0.0.answers.json").write_text('{"_arm_version":"6.0.0"}\n')
    check("T9",command([str(HERE/"scrub-audit.sh"),str(archive)]),5,"SCRUB FAILED")

    orphan=root/"orphan-root"; (orphan/"R20260824-aaaaaa").mkdir(parents=True); empty=root/"empty.txt"; empty.write_text("")
    check("T10",command([str(HERE/"seal-entrypoint-gate.sh")],
      {"SLIMSTAT_SEAL_SKIP_BEHAVIOR":"1","SLIMSTAT_SEAL_RUNS_ROOT":str(orphan),"SLIMSTAT_SEAL_PRE_S6":str(empty)}),1,"has no flip.json and is not declared pre-S6")

    # ── T11: a comparison that ABORTED must not leave an adjudicable packet ────────────────
    # compare-answers.sh writes both captures BEFORE it evaluates a single control, so on a
    # vacuity abort $ART is fully populated and self-consistent. Measured against the pre-fix
    # entry point with exactly this stub: 2 answer files, controls.json with all nine PASS, and
    # a signed MANIFEST — a packet indistinguishable from a good one, for a run that had just
    # printed ABORTED. seal-tool.py cannot catch it either; it validates the packet, not the
    # verdict that produced it.
    aborting=compare_stub(root/"abort-cmd.sh","ABORTED",1)
    t11_runs=root/"t11-runs"
    check("T11",command([str(HERE/"verify-change.sh"),"HEAD","HEAD~1","10","2"],
      {"SLIMSTAT_RUNS_ROOT":str(t11_runs),"SLIMSTAT_COMPARE_CMD":str(aborting),
       "WORK_ROOT":str(root/"t11-work")}),1,"no packet built")
    leaked=sorted(t11_runs.rglob("packet/*/answers.json")) if t11_runs.is_dir() else []
    if leaked:
        print(f"SEAL SUITE: T11 refused in words and built {len(leaked)} packet files anyway",file=sys.stderr)
        raise SystemExit(1)

    # ── T11b (control): a comparison that REACHED a verdict still builds one ───────────────
    # Without this, T11 is satisfied by an entry point that never builds a packet at all.
    differing=compare_stub(root/"differ-cmd.sh","DIFFERENCES",2)
    t11b_runs=root/"t11b-runs"
    check("T11b",command([str(HERE/"verify-change.sh"),"HEAD","HEAD~1","10","2"],
      {"SLIMSTAT_RUNS_ROOT":str(t11b_runs),"SLIMSTAT_COMPARE_CMD":str(differing),
       "WORK_ROOT":str(root/"t11b-work")}),2,"ANSWERS DIFFER",True)
    if len(sorted(t11b_runs.rglob("packet/*/answers.json")))!=2:
        print("SEAL SUITE: T11b reached a verdict and produced no packet",file=sys.stderr)
        raise SystemExit(1)

    # ── T12: an era marker planted as an answer VALUE ──────────────────────────────────────
    # `_arm_fingerprint` is stripped as a KEY, and 945c4fdf… identified the OLD arm of
    # R20260824-a51bf2 on sight. As a value no structural rule can see it, which is what the
    # sealed literals are for. Note this cannot be a generic hex rule: the same capture carries
    # Unix timestamps in window_start/window_end that any such rule reads as refs.
    run,art=fixture(root/"t12",reports=False,build=False)
    values=json.load((art/"before.json").open())
    values["top_resource"]=[{"resource":"/a","x":"fingerprint-a"}]
    (art/"before.json").write_text(json.dumps(values)+"\n")
    check("T12",command([str(HERE/"build-packet.sh"),str(run),str(art),REF_A,REF_B]),5,"seal-literal")

    # ── T12b (control): real report content that merely LOOKS like a marker ────────────────
    # Every value here is from R20260824-a51bf2's own capture, and every one of them tripped the
    # pre-fix audit — 94 hits per arm, none a leak. The run could not close because of it. A
    # scrubber that refuses this is as broken as one that passes T12.
    run,art=fixture(root/"t12b",reports=False,build=False)
    values=json.load((art/"before.json").open())
    corpus=command([str(HERE/"scrub-audit.sh"),"--false-positive-corpus"])
    if corpus.returncode: raise RuntimeError(corpus.stderr)
    values.update(json.loads(corpus.stdout))
    (art/"before.json").write_text(json.dumps(values)+"\n")
    check("T12b",command([str(HERE/"build-packet.sh"),str(run),str(art),REF_A,REF_B]),0,"blind packet built",True)

    # ── T13 (control): a denied key NESTED in a report row is scrubbed, not merely audited ──
    # The committed scrubber filtered top-level keys only, so a capability key inside a list of
    # report rows reached the packet and was caught downstream by the audit — which destroys the
    # packet rather than cleaning it. The recursive form removes it, so the packet builds AND
    # the key is gone. Asserted on the bytes, because "the audit passed" is also what a
    # scrubber that deleted everything would produce.
    run,art=fixture(root/"t13",reports=False,build=False)
    values=json.load((art/"before.json").open())
    values["top_resource"]=[{"resource":"/a","counthits":2,"live_window_end":123}]
    (art/"before.json").write_text(json.dumps(values)+"\n")
    check("T13",command([str(HERE/"build-packet.sh"),str(run),str(art),REF_A,REF_B]),0,"blind packet built",True)
    packet=(run/"packet/arm-1/answers.json").read_text()
    if "live_window_end" in packet or '"resource":"/a"' not in packet:
        print(f"SEAL SUITE: T13 packet is {packet[:160]}",file=sys.stderr); raise SystemExit(1)

    # ── T14: an era marker planted into the packet AFTER it was built ──────────────────────
    # unseal() re-audits packet/ for exactly this, and it was running a WEAKER rule than the one
    # that built the packet: the literals live in the captures and the fingerprints never enter
    # the mapping, so unseal could not re-derive them. The one check written to catch a late leak
    # was the one that would have missed it. build-packet.py now persists them to
    # .sealed/literals.json at 0600 — the answer key's own mode, since `_arm_fingerprint` is
    # precisely what the blind hides — and unseal reads them back.
    run,_=fixture(root/"t14")
    (run/"packet/arm-1/answers.json").write_text(
        json.dumps({"top_resource":[{"resource":"/a","x":"fingerprint-a"}]})+"\n")
    check("T14",command([str(HERE/"seal.sh"),"--unseal",str(run)]),4,"scrub audit found hits")

    # ── T15: the sealed literals are MISSING rather than weakened ──────────────────────────
    # The first version of the guard above fell back to an audit without them, so a run whose
    # provenance had been removed re-audited under the weaker rule and unsealed cleanly. T14
    # proves the rule is used; this proves it cannot be skipped.
    run,_=fixture(root/"t15")
    (run/".sealed/literals.json").unlink()
    check("T15",command([str(HERE/"seal.sh"),"--unseal",str(run)]),4,"literals.json is absent")

expected=["T1","T2-ii","T2b","T2c","T3","T3b","T3c","T4","T4b","T4c","T5a","T5b","T6","T6b","T6c","T7a","T7b","T7c","T8","T9","T10","T11","T12","T14","T15"]
if required!=expected or controls!=["T0","T2-i","T7d","T11b","T12b","T13"]:
    print(f"SEAL SUITE: declaration/execution mismatch required={required} controls={controls}",file=sys.stderr); raise SystemExit(6)
print(f"SEAL SUITE: {len(expected)} declared negative tests, {len(required)} executed, {len(required)} red · "
      f"{len(controls)} controls, {len(controls)} as expected")
