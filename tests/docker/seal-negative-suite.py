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

def fixture(root,reports=True):
    result=command([str(HERE/"seal.sh"),"flip",str(root),REF_A,REF_B,"40","30","0"])
    if result.returncode: raise RuntimeError(result.stderr)
    run=Path(result.stdout.strip()); art=root/"art"; art.mkdir()
    common={"count_records_id":15000,"rows_in_window":10000,"count_records_resource":3000,
            "top_resource":[{"resource":"/a","counthits":2}]}
    before=dict(common,_arm_version="5.5.1",_arm_fingerprint="fingerprint-a")
    after=dict(common,_arm_version="6.0.0",_arm_fingerprint="fingerprint-b")
    (art/"before.json").write_text(json.dumps(before)+"\n")
    (art/"after.json").write_text(json.dumps(after)+"\n")
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

expected=["T1","T2-ii","T2b","T2c","T3","T3b","T3c","T4","T4b","T4c","T5a","T5b","T6","T6b","T6c","T7a","T7b","T7c","T8","T9","T10"]
if required!=expected or controls!=["T0","T2-i","T7d"]:
    print(f"SEAL SUITE: declaration/execution mismatch required={required} controls={controls}",file=sys.stderr); raise SystemExit(6)
print("SEAL SUITE: 21 declared negative tests, 21 executed, 21 red · 3 controls, 3 as expected")
