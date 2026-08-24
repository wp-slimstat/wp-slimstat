#!/usr/bin/env python3
from __future__ import annotations
import re,sys,tempfile
from pathlib import Path

CLASSES={
"ref":re.compile(r"\b[0-9a-f]{7,40}\b"),
"version":re.compile(r"\b(?:5\.5(?:\.\d+)?|6\.0(?:\.\d+)?|2\.0\.\d+|3\.0\.\d+)\b|SLIMSTAT_ANALYTICS_VERSION",re.I),
"era":re.compile(r"\bv[56]\b",re.I),
"direction":re.compile(r"\b(?:before|after|old|new|baseline|head)\b",re.I),
"branch":re.compile(r"\b(?:development|master|main)\b|feat/|origin/",re.I),
"arm-metadata":re.compile(r"_arm_(?:version|fingerprint|files|pro|caps|status|surfaces)|_instrument",re.I),
"capability":re.compile(r"count_exit_pages|live_window_end|get_goal_results_arity|get_funnel_results_arity|chart_data_path|pro_installed|pro_active|network_merge_active|recent_columns_shape",re.I),
"v6-column":re.compile(r"\b(?:vid_hash|ua_id)\b",re.I),
"migration":re.compile(r"needsMigration|slimstat_run_migrations|wp_ajax_slimstat_run_migrations|Create[A-Z]\w+Migration"),
"path":re.compile(r"/tmp/php-matrix|/var/www|wp-slimstat-pro|worktree|\.claude/plans",re.I),
"comparison-name":re.compile(r"-to-|_to_|\bversus\b|_v_",re.I)}

def scan(root:Path):
    hits=[]
    for path in sorted(root.rglob("*")):
        rel=str(path.relative_to(root)); subjects=[rel]
        if path.is_symlink(): subjects.append(str(path.readlink()))
        elif path.is_file(): subjects.append(path.read_text(encoding="utf-8",errors="replace"))
        for name,pattern in CLASSES.items():
            if any(pattern.search(value) for value in subjects): hits.append((rel,name))
    return sorted(set(hits))

def selftest(drop=None):
    fixtures={"ref":"217bb34e","version":"6.0.0","era":"v6","direction":"before","branch":"origin/development",
    "arm-metadata":"_arm_version","capability":"live_window_end","v6-column":"vid_hash",
    "migration":"needsMigration","path":"/tmp/php-matrix","comparison-name":"a-to-b"}
    with tempfile.TemporaryDirectory(prefix="slimstat-scrub-") as tmp:
        root=Path(tmp)
        for name,value in fixtures.items(): (root/f"fixture-{name}.txt").write_text(value)
        seen={name for _,name in scan(root)}
    if drop: seen.discard(drop)
    missing=sorted(set(fixtures)-seen)
    if missing:
        print(f"SCRUB SELFTEST: class '{missing[0]}' did not fire",file=sys.stderr); return 6
    print("PASS: scrub audit selftest — 11 classes fired"); return 0

if len(sys.argv)>=2 and sys.argv[1]=="--selftest": raise SystemExit(selftest(sys.argv[2] if len(sys.argv)==3 else None))
if len(sys.argv)!=2:
    print("usage: scrub-audit.py <packet-dir> | --selftest [drop-class]",file=sys.stderr); raise SystemExit(2)
hits=scan(Path(sys.argv[1]))
for path,name in hits: print(f"SCRUB FAILED: {path} carries forbidden class '{name}'",file=sys.stderr)
raise SystemExit(5 if hits else 0)
