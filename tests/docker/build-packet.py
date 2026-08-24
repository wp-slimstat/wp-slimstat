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
def scrub_values(value):
    if isinstance(value,dict):
        return {k:scrub_values(v) for k,v in value.items() if not k.startswith("_") and k not in deny}
    if isinstance(value,list): return [scrub_values(v) for v in value]
    return value

# Under a NULL CONTROL both refs are the same, so `source_for_ref` collapses to one key and both
# arms read `after.json` -- the `before` capture is discarded and the two blind arms are the same
# bytes by construction. `arms_identical` then compares a fingerprint with itself and cannot fail,
# and the noise floor the run exists to measure never reaches an adjudicator. The flip still
# decides which arm holds which pass, so the blind is unchanged and the two captures are real.
null_sides=("before","after") if mapping.get("b")==0 else ("after","before")
for index,arm in enumerate(("arm-1","arm-2")):
    side=null_sides[index] if bool(mapping.get("null_control")) else source_for_ref.get(mapping[arm])
    if side is None:
        print(f"SEAL REFUSED: mapping {arm} does not name either captured ref",file=sys.stderr); raise SystemExit(3)
    source=artifacts/f"{side}.json"
    if not source.is_file():
        print(f"SEAL REFUSED: capture artifact {source} is absent",file=sys.stderr); raise SystemExit(3)
    values=json.load(source.open()); captured[arm]=values
    clean=scrub_values(values)
    (run/f"packet/{arm}/answers.json").write_text(json.dumps(clean,sort_keys=True,separators=(",",":"))+"\n")
    timing=artifacts/f"{side}-timing.json"
    if timing.is_file(): shutil.copyfile(timing,run/f"timing/{arm}.timing.json")
(run/"packet/contract.md").write_text("# Blind adjudication contract\n\nJudge only the arm answers and declared report semantics.\n")
# THE LITERALS THIS RUN CARRIES, handed to the audit rather than left to a regex to guess.
# `_arm_fingerprint` is the strongest era marker in the capture — 945c4fdf… identified the OLD
# arm in R20260824-a51bf2 and appears nowhere else — and `_arm_version` is "5.5.1" against
# "6.0.0". Both are stripped from the packet as KEYS above; this is what catches them if they
# ever appear as a VALUE, where no structural rule can see them. See scrub-audit.py's header for
# why the generic `ref`/`version` regexes cannot do this job over real report content.
identity_keys=schema["answer_key_rules"]["identity_value_keys"]
# Read here rather than below, because the floor's expectation depends on it: a NULL CONTROL runs
# ONE ref as both arms, so the two arms SHARE every identity field by definition. The first
# version of this floor did not know that and refused the noise-floor run outright — measured,
# `3 distinct literals, expected 6`, on a run whose whole purpose is that the arms are the same.
# A floor that cannot tell "the arms are identical because that is the experiment" from "the
# arms are identical because the harness failed to swap them" is not a floor; the `arms_identical`
# / `arms_differ` control below is what draws that line, and this reads the same field.
null_control=bool(mapping.get("null_control"))
literals=set()
for arm,values in captured.items():
    for key in identity_keys:
        value=values.get(key)
        # REFUSED, not skipped. `if value:` would quietly shrink the literal set when the
        # instrument renames a field, and the control below would then pass against two
        # literals while its evidence string reported the smaller number — a gate that
        # silently does not run, which is this programme's most repeated defect.
        if value in (None,""):
            print(f"SEAL REFUSED: {arm} carries no {key}, so its era cannot be scrubbed by value",
                  file=sys.stderr); raise SystemExit(3)
        literals.add(str(value))
# Full SHAs as substrings; abbreviations are matched on word boundaries by the audit, because a
# 7-hex run can land inside real content. DESIGN §5 asks for both.
literals |= {ref_a,ref_b}
abbreviated=sorted({ref[:n] for ref in (ref_a,ref_b) for n in range(7,13)})
literals=sorted(literals)
# Derived, not asserted. Both branches are one formula with two collapses -- one era rather than
# two, one ref rather than two -- and `len({ref_a,ref_b})` computes the second instead of
# hardcoding it, so a run mislabelled null with two distinct refs gets the right expectation and
# therefore the right diagnosis rather than this floor's.
eras=1 if null_control else len(captured)
expected_literals=len(identity_keys)*eras+len({ref_a,ref_b})
if len(literals)!=expected_literals:
    detail=("a null control's arms must share every identity field" if null_control
            else "the two arms share an identity field")
    print(f"SEAL REFUSED: {len(literals)} distinct literals, expected {expected_literals} — {detail}",
          file=sys.stderr); raise SystemExit(3)
# Persisted, not recomputed. `seal.sh --unseal` re-audits the packet to catch anything planted
# into it AFTER it was built — that is the whole point of that check — and it cannot re-derive
# these: the captures are not in the packet, and the fingerprints and versions never enter the
# mapping. Without the file the unseal audit is strictly weaker than the build audit, which
# means the one check written to catch a late leak is the one that would miss it.
#
# Under .sealed/ at 0600, because the literals ARE the answer key: `_arm_fingerprint` per arm is
# exactly what the blind is hiding.
sealed_literals=run/".sealed/literals.json"
sealed_literals.write_text(json.dumps({"exact":literals,"tokens":abbreviated},
                                      sort_keys=True,separators=(",",":"))+"\n")
sealed_literals.chmod(0o600)
audit=Path(__file__).with_name("scrub-audit.sh")
result=subprocess.run([str(audit),str(run/"packet"),"--literals",str(sealed_literals)],check=False)
if result.returncode:
    shutil.rmtree(run/"packet"); raise SystemExit(result.returncode)

def passed(control_id: str, condition: bool, evidence: str):
    return {"id":control_id,"status":"PASS" if condition else "FAIL","evidence":evidence}

a,b=captured["arm-1"],captured["arm-2"]
same_fingerprint=a.get("_arm_fingerprint")==b.get("_arm_fingerprint")
entropy=str(mapping.get("entropy_hex", ""))
flip=json.load((run/"flip.json").open())
commitment_ok=(len(entropy)==64 and hashlib.sha256(entropy.encode()).hexdigest()==flip.get("commitment"))
# seal_assert_private, not a mode comparison. T2b in the negative suite exists to prove the
# OWNERSHIP half is load-bearing, and a bare `st_mode & 0o777 == 0o600` cannot see it — so the
# control that reports on the mapping's privacy was weaker than the assertion the suite proves
# is required. Shelled out through the same mechanism `names_neutral` already uses.
private=subprocess.run(
    ["bash","-c",'source "$1"; seal_assert_private "$2" 600',"bash",
     str(Path(__file__).with_name("seal-lib.sh")),str(run/".sealed/mapping.json")],
    check=False,capture_output=True,text=True)
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
    passed("packet_scrub_clean",result.returncode==0 and len(literals)==expected_literals,
           f"scrub-audit exited {result.returncode} against {len(literals)} exact literals "
           f"and {len(abbreviated)} abbreviations"),
    passed("flip_commitment_verified",commitment_ok,"sha256(entropy_hex) compared with flip.json"),
    passed("mapping_mode_0600",private.returncode==0,
           f"mode={mode}, owner asserted by seal_assert_private (exit {private.returncode})"),
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
