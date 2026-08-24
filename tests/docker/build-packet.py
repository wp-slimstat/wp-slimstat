#!/usr/bin/env python3
from __future__ import annotations
import hashlib,json,os,shutil,subprocess,sys
from pathlib import Path

if len(sys.argv)!=5:
    print("usage: build-packet.py <run> <artifacts> <ref-a> <ref-b>",file=sys.stderr); raise SystemExit(2)
run,artifacts=Path(sys.argv[1]),Path(sys.argv[2]); ref_a,ref_b=sys.argv[3:]
if (run/"seal.json").exists():
    print("SEAL REFUSED: pre-S6 seal.json cannot be turned into a blind packet",file=sys.stderr); raise SystemExit(3)
mapping=json.load((run/".sealed/mapping.json").open())
schema=json.load(Path(__file__).with_name("packet-schema.json").open()); deny=set(schema["answer_key_rules"]["deny"])
source_for_ref={ref_a:"before",ref_b:"after"}
captured={}
for arm in ("arm-1","arm-2"):
    side=source_for_ref.get(mapping[arm])
    if side is None:
        print(f"SEAL REFUSED: mapping {arm} does not name either captured ref",file=sys.stderr); raise SystemExit(3)
    source=artifacts/f"{side}.json"
    if not source.is_file():
        print(f"SEAL REFUSED: capture artifact {source} is absent",file=sys.stderr); raise SystemExit(3)
    values=json.load(source.open()); captured[arm]=values
    clean={k:v for k,v in values.items() if not k.startswith("_") and k not in deny}
    (run/f"packet/{arm}/answers.json").write_text(json.dumps(clean,sort_keys=True,separators=(",",":"))+"\n")
    timing=artifacts/f"{side}-timing.json"
    if timing.is_file(): shutil.copyfile(timing,run/f"timing/{arm}.timing.json")
(run/"packet/contract.md").write_text("# Blind adjudication contract\n\nJudge only the arm answers and declared report semantics.\n")
audit=Path(__file__).with_name("scrub-audit.sh")
result=subprocess.run([str(audit),str(run/"packet")],check=False)
if result.returncode:
    shutil.rmtree(run/"packet"); raise SystemExit(result.returncode)

def passed(control_id: str, condition: bool, evidence: str):
    return {"id":control_id,"status":"PASS" if condition else "FAIL","evidence":evidence}

a,b=captured["arm-1"],captured["arm-2"]
same_fingerprint=a.get("_arm_fingerprint")==b.get("_arm_fingerprint")
null_control=bool(mapping.get("null_control"))
entropy=str(mapping.get("entropy_hex", ""))
flip=json.load((run/"flip.json").open())
commitment_ok=(len(entropy)==64 and hashlib.sha256(entropy.encode()).hexdigest()==flip.get("commitment"))
mode=oct(os.stat(run/".sealed/mapping.json").st_mode & 0o777)[2:]
lists=[(arm,key) for arm,values in captured.items() for key,value in values.items()
       if not key.startswith("_") and isinstance(value,list) and not value]
neutral=subprocess.run(
    ["bash","-c",'source "$1"; PLUGIN_SRC="$2" seal_assert_neutral_names "$3"',"bash",
     str(Path(__file__).with_name("seal-lib.sh")),str(Path(__file__).resolve().parents[2]),str(run)],
    check=False,capture_output=True,text=True)
controls=[
    passed("arms_identical" if null_control else "arms_differ",
           same_fingerprint if null_control else not same_fingerprint,
           f"fingerprints {'match' if same_fingerprint else 'differ'}"),
    passed("corpus_nontrivial",all(v.get("count_records_id",0)>10000 for v in (a,b)),
           f"rows={a.get('count_records_id')},{b.get('count_records_id')}"),
    passed("window_is_strict_subset",all(0<v.get("rows_in_window",0)<v.get("count_records_id",0) for v in (a,b)),
           f"window={a.get('rows_in_window')}/{a.get('count_records_id')},{b.get('rows_in_window')}/{b.get('count_records_id')}"),
    passed("cardinality_past_cliff",all(v.get("count_records_resource",0)>2048 for v in (a,b)),
           f"resources={a.get('count_records_resource')},{b.get('count_records_resource')}"),
    passed("no_list_report_empty",not lists,"empty="+("none" if not lists else repr(lists))),
    passed("packet_scrub_clean",result.returncode==0,f"scrub-audit exited {result.returncode}"),
    passed("flip_commitment_verified",commitment_ok,"sha256(entropy_hex) compared with flip.json"),
    passed("mapping_mode_0600",mode=="600",f"mode={mode}"),
    passed("names_neutral",neutral.returncode==0,"seal_assert_neutral_names exited "+str(neutral.returncode)),
]
(run/"controls.json").write_text(json.dumps(controls,sort_keys=True)+"\n")
failed=[row for row in controls if row["status"]!="PASS"]
if failed:
    for row in failed: print(f"SEAL REFUSED: control {row['id']} failed: {row['evidence']}",file=sys.stderr)
    shutil.rmtree(run/"packet")
    raise SystemExit(6)
manifest=run/"packet/MANIFEST.sha256"; lines=[]
for path in sorted((run/"packet").rglob("*")):
    if path.is_file() and path!=manifest:
        lines.append(f"{hashlib.sha256(path.read_bytes()).hexdigest()}  {path.relative_to(run)}\n")
manifest.write_text("".join(lines),encoding="ascii")
