#!/usr/bin/env python3
# Does this packet carry anything that names an ERA?
#
# The packet is what a blind adjudicator opens. It must not tell them which arm is v5 and which
# is v6 — not in a filename, not in a JSON key, not in a value.
#
# WHY THE CLASSES ARE APPLIED PER SUBJECT rather than uniformly. The first version scanned every
# file as raw text with every class, and run R20260824-a51bf2 measured what that costs against a
# real corpus: 94 hits per arm, every one a legitimate analytics answer. `-to-` fired 84 times on
# blog slugs like `/faq/do-i-need-to-keep-my-license-updated/`; `\bnew\b` on
# `/a-new-chapter-.../`; `\bv5\b` on `/big-changes-slimstat-v5-1/`; and the hex-ref class on the
# Unix timestamps in `window_start`/`window_end`. The packet was correct and the scrubber refused
# it, so S6 could not close.
#
# The fix is not to scan less. It is to notice that the three subjects differ in who WROTE them:
#
#   paths and filenames   the harness writes them        -> every class
#   JSON keys             the instrument writes them     -> every class
#   JSON values           the SITE'S VISITORS wrote them -> only classes that cannot collide,
#                                                           plus this run's exact literals
#
# The value classes are the ones measured to produce zero hits on real report content, and each
# is identifier-shaped or a harness string rather than anything a URL can be. Deliberately NOT
# among them: `ref`, `era`, `direction`, `comparison-name` and `version`. The first four collide
# with ordinary English and hexadecimal; `version` collides with any site that publishes a URL
# carrying a release number.
#
# Dropping `version` from values would leave a real hole — `_arm_version` is "5.5.1" on one arm
# and "6.0.0" on the other, which identifies them instantly — so it is closed by the LITERALS
# instead: build-packet.py passes this run's actual arm SHAs, fingerprints and version strings,
# and they are matched as exact substrings. That is strictly stronger than the regex it replaces
# (it catches a full 40-character SHA, which `\b[0-9a-f]{7,40}\b` cannot) and it cannot fire on a
# timestamp, because a timestamp is not one of this run's literals.
#
#   scrub-audit.py <packet-dir> [--literals <file>]   exit 5 on any hit
#   scrub-audit.py --selftest [drop-class]            exit 6 if any selftest assertion fails
#
# The literals arrive as a FILE, never as arguments. They are the answer key — `_arm_fingerprint`
# per arm is exactly what the blind hides — and argv is readable by any local user through `ps`
# or /proc/PID/cmdline. Writing them to a 0600 file and then handing that file's CONTENTS to a
# command line would publish the thing the mode was protecting, which is PITFALLS 77's shape (a
# sibling artifact unsealing the packet) one layer down.
from __future__ import annotations
import json,re,sys,tempfile
from pathlib import Path

# THE DENY LIST IS THE SCHEMA'S, not a second copy of it. The two `capability` and
# `arm-metadata` classes used to be hand-transcribed from packet-schema.json, and they had
# already drifted: of the schema's 24 denied keys, SEVEN matched no class at all
# (`_handles`, `_reps`, `memory_reset_peak_usage`, `ext_object_cache`, `geolocation_country`,
# `forced_max_goals`, `forced_max_funnels`) and five of those are not underscore-prefixed, so
# nothing caught them.
#
# That matters because the audit is the independent backstop on build-packet.py's scrubber. A
# key the scrubber stopped removing would have reached the packet and the audit would have
# passed it — which is the shape of a control that cannot fail. Deriving both classes from the
# schema makes drift impossible rather than merely unlikely, and a key added to the schema
# gains an auditor for free.
#
# This is NOT the ENCODING_V1 kind of independence, and the distinction is worth stating: that
# pair is two implementations of an ALGORITHM held to shared fixtures. A deny list is DATA, and
# two hand-maintained copies of data are drift, not corroboration. The independence that does
# the work here survives untouched — the scrubber filters in memory, the audit walks the bytes
# on disk.
_SCHEMA=json.loads(Path(__file__).with_name("packet-schema.json").read_text())
_RULES=_SCHEMA["answer_key_rules"]; _PREFIX=_RULES["forbid_prefix"]

def _denied(underscore:bool):
    names=sorted(n for n in _RULES["deny"] if n.startswith(_PREFIX)==underscore)
    if not names: raise SystemExit("SCRUB: packet-schema.json declares no deny keys")
    return re.compile("|".join(re.escape(n) for n in names),re.I)

CLASSES={
"ref":re.compile(r"\b[0-9a-f]{7,40}\b"),
"version":re.compile(r"\b(?:5\.5(?:\.\d+)?|6\.0(?:\.\d+)?|2\.0\.\d+|3\.0\.\d+)\b|SLIMSTAT_ANALYTICS_VERSION",re.I),
"era":re.compile(r"\bv[56]\b",re.I),
"direction":re.compile(r"\b(?:before|after|old|new|baseline|head)\b",re.I),
"branch":re.compile(r"\b(?:development|master|main)\b|feat/|origin/",re.I),
"arm-metadata":_denied(True),
"capability":_denied(False),
"v6-column":re.compile(r"\b(?:vid_hash|ua_id)\b",re.I),
"migration":re.compile(r"needsMigration|slimstat_run_migrations|wp_ajax_slimstat_run_migrations|Create[A-Z]\w+Migration"),
"path":re.compile(r"/tmp/php-matrix|/var/www|wp-slimstat-pro|worktree|\.claude/plans",re.I),
"comparison-name":re.compile(r"-to-|_to_|\bversus\b|_v_",re.I)}

# WHAT MAY BE APPLIED TO A VALUE, and the list is short because the test is not "does this look
# like a marker" but "can a visitor have written it".
#
# Two questions decide membership, and only one of them is about false positives.
#
# FIRST: does the class name an ERA? That is what the packet must not carry. `arm-metadata`,
# `capability`, `v6-column` and `migration` do — they are the vocabulary of one side of the
# comparison. `path` does NOT: both arms run in the same container, so `/var/www` and
# `/tmp/php-matrix` are identical on each and identify nothing. Path matching is hygiene, and it
# keeps applying to filenames and keys, where the harness is the author.
#
# SECOND: can a visitor have written it? Everything excluded (`ref`, `era`, `direction`,
# `comparison-name`, `version`, `branch`) is excluded because its vocabulary is ordinary English,
# ordinary URL segments or ordinary hexadecimal, and a report answer is a URL a stranger typed.
#
# MEASURED, because on this the two questions disagree and measurement settles it:
#
#   corpus                     class that collided     evidence
#   I8 fixture                 (none of these)         23 packet keys, both arms
#   campaign verify corpus     branch                  `/development/info.php`
#   real 443,535-row dataset   path                    100 rows carry `/var/www`
#
# `branch` survived the first pass because I8 has no `/development/` in it, and the first real
# OLD<->NEW comparison then refused its own packet at exit 5 after a full capture. `path` would
# have done the same to the upgrade rehearsal, which hydrates the real dataset. THE FLOOR IS ONLY
# AS GOOD AS THE CORPUS BEHIND IT — a class that has not collided is not a class that cannot —
# so the fixture below is drawn from real captures, and every remaining class was checked against
# all 443,535 real rows: `vid_hash`, `ua_id`, `pro_active`, `pro_installed`, `needsMigration`,
# `slimstat_run_migrations`, `_arm_*` and `_instrument` appear in zero of them.
#
# What none of this weakens is the identity check. Whether an era leaks is decided by the sealed
# literals — this run's own SHAs, fingerprints and versions — which are exact, not lexical.
VALUE_CLASSES=("arm-metadata","capability","v6-column","migration")

# A file the audit cannot parse is not a file the audit has cleared. Declared here so the
# refusal names a class like every other refusal does, rather than printing a tuple.
UNPARSABLE="unparsable-json"

def text_hits(value:str,names=None):
    return [name for name in (names or CLASSES) if CLASSES[name].search(value)]

def json_hits(document):
    """Every class against every key, the value classes against every scalar leaf."""
    hits=[]
    def walk(node):
        if isinstance(node,dict):
            for key,child in node.items():
                hits.extend(text_hits(str(key)))
                walk(child)
        elif isinstance(node,list):
            for child in node: walk(child)
        elif node is not None and not isinstance(node,bool):
            hits.extend(text_hits(str(node),VALUE_CLASSES))
    walk(document)
    return hits

def scan(root:Path,literals=(),tokens=()):
    # Two kinds, and the difference is not decoration. An EXACT literal is a full identity —
    # a 40-character SHA, a fingerprint, a version — and is matched as a substring, because
    # `\b` would miss it wherever it is glued to surrounding characters. A TOKEN is an
    # abbreviation, and a 7-hex run can plausibly land inside real content, so it is matched on
    # word boundaries instead.
    literals=[str(x) for x in literals if str(x).strip()]
    token_pattern=re.compile(r"\b(?:"+"|".join(re.escape(str(x)) for x in tokens)+r")\b") if tokens else None
    hits=[]
    for path in sorted(root.rglob("*")):
        rel=str(path.relative_to(root))
        hits.extend((rel,name) for name in text_hits(rel))
        if path.is_symlink():
            hits.extend((rel,name) for name in text_hits(str(path.readlink())))
            continue
        if not path.is_file(): continue
        body=path.read_text(encoding="utf-8",errors="replace")
        if path.suffix==".json":
            try: document=json.loads(body)
            except Exception: hits.append((rel,UNPARSABLE)); continue
            hits.extend((rel,name) for name in json_hits(document))
        else:
            hits.extend((rel,name) for name in text_hits(body))
        for literal in literals:
            if literal in body: hits.append((rel,"seal-literal"))
        if token_pattern and token_pattern.search(body): hits.append((rel,"seal-literal"))
    return sorted(set(hits))

def _case_failures(cases:Path,legitimate,literals):
    """The three cases that make the fixture list a control rather than decoration."""
    failures=[]

    # (2) THE FALSE-POSITIVE FLOOR. The pre-fix audit refused a correct packet on 94 hits of this
    # shape, and nothing in its selftest could tell: the old assertion was `set(fixtures) - seen`,
    # which a fixture firing every class in the book also satisfies. Asserting ZERO is the point.
    clean=cases/"clean/arm-1"; clean.mkdir(parents=True)
    (clean/"answers.json").write_text(json.dumps(legitimate))
    noise=scan(cases/"clean",literals)
    if noise: failures.append(f"legitimate report values fired {sorted({n for _,n in noise})}")

    # (3) AND THE FLOOR IS NOT THE CEILING: each real era marker, planted exactly where those
    # values sit, must still be caught — and caught AS a sealed literal. One directory per
    # marker, because planting all six in one file would let a single hit satisfy the whole loop
    # and degrade "each marker is caught" into "some marker is caught".
    for index,literal in enumerate(literals):
        probe=cases/f"probe-{index}"; probe.mkdir()
        (probe/"answers.json").write_text(json.dumps({"top_resource":[{"resource":"/x","x":literal}]}))
        if "seal-literal" not in {name for _,name in scan(probe,literals)}:
            failures.append(f"planted era marker {literal!r} was not caught as a sealed literal")

    # (4) An unreadable JSON file refuses by NAME. The in-flight fix returned a tuple here where
    # a class name was expected, which printed as garbage and made sorted(set(hits)) raise
    # TypeError as soon as any sibling class also fired.
    bad=cases/"bad"; bad.mkdir()
    (bad/"answers.json").write_text("{not json")
    (bad/"origin-development.txt").write_text("x")
    if sorted(n for _,n in scan(bad)) != sorted([UNPARSABLE,"branch"]):
        failures.append(f"unparsable JSON did not refuse by name: {scan(bad)}")
    return failures

# The values that broke R20260824-a51bf2, in one place. The selftest asserts they produce zero
# hits; the negative suite's T12b asserts a packet built from them survives. Two levels of the
# same control, and transcribing the corpus twice had already drifted it — the query-string slug
# that exercises `-to-` and the wordpress.org referer existed on only one side.
LEGITIMATE={"top_resource":[
    {"resource":"/a-new-chapter-for-jacob4078-welcome-to-the-veronalabs-family/","counthits":"31"},
    {"resource":"/big-changes-slimstat-v5-1/","counthits":"12"},
    {"resource":"/faq/do-i-need-to-keep-my-license-updated/","counthits":"9"},
    {"resource":"/cart/?add-to-cart=x","counthits":"4"},
    {"resource":"/development/info.php","counthits":"2"},
    {"resource":"/var/www/html/legacy-import.php","counthits":"1"}],
    "window_start":1784980416,"window_end":1787572416,
    "top_referer":[{"referer":"https://wordpress.org/support/topic/no-cookies/","counthits":"3"}]}

def selftest(drop=None):
    fixtures={"ref":"217bb34e","version":"6.0.0","era":"v6","direction":"before","branch":"origin/development",
    "arm-metadata":"_arm_version","capability":"live_window_end","v6-column":"vid_hash",
    "migration":"needsMigration","path":"/tmp/php-matrix","comparison-name":"a-to-b"}
    legitimate=LEGITIMATE
    # The era markers this run actually carried. They are NOT analytics values — 945c4fdf… is
    # the OLD arm's `_arm_fingerprint` and appears nowhere else in the capture — so each must be
    # caught even though it is sitting where a legitimate value sits.
    literals=["945c4fdf89a9ecf0aef5bb96a893e5eb","232c142e77f41c92c5b50747c981cc52",
              "77c0e9463a3056d2c82a3f883034cb9cff71d8e5","217bb34e798e7e0c8a4069884fb0debe510f57f7",
              "5.5.1","6.0.0"]
    failures=[]
    with tempfile.TemporaryDirectory(prefix="slimstat-scrub-") as tmp:
        root=Path(tmp)
        for name,value in fixtures.items(): (root/f"fixture-{name}.txt").write_text(value)
        seen={name for _,name in scan(root)}
        # Nested, not `tmp+"-clean"`. Concatenating onto the path makes SIBLINGS of the temp
        # directory, which its context manager does not remove — measured at three leaked
        # directories per invocation, on a gate that runs inside test:all on every CI lane. The
        # cases still need their own roots, because the scan above must see the eleven fixtures
        # and nothing else.
        with tempfile.TemporaryDirectory(prefix="slimstat-scrub-cases-") as cases:
            failures.extend(_case_failures(Path(cases),legitimate,literals))

    if drop: seen.discard(drop)
    missing=sorted(set(fixtures)-seen)
    if missing: failures.append(f"class '{missing[0]}' did not fire")
    if failures:
        for problem in failures: print(f"SCRUB SELFTEST: {problem}",file=sys.stderr)
        return 6
    real=sum(len(v) if isinstance(v,list) else 1 for v in legitimate.values())
    print(f"PASS: scrub audit selftest — {len(fixtures)} classes fired, "
          f"{real} real values clean, {len(literals)} era markers caught"); return 0

if len(sys.argv)>=2 and sys.argv[1]=="--false-positive-corpus":
    print(json.dumps(LEGITIMATE)); raise SystemExit(0)
if len(sys.argv)>=2 and sys.argv[1]=="--selftest": raise SystemExit(selftest(sys.argv[2] if len(sys.argv)==3 else None))
if len(sys.argv) not in (2,4) or (len(sys.argv)==4 and sys.argv[2]!="--literals"):
    print("usage: scrub-audit.py <packet-dir> [--literals <file>] | --selftest [drop-class]",file=sys.stderr)
    raise SystemExit(2)
sealed={"exact":[],"tokens":[]}
if len(sys.argv)==4:
    try: sealed=json.loads(Path(sys.argv[3]).read_text())
    except Exception as exc:
        print(f"SCRUB REFUSED: cannot read the sealed literals at {sys.argv[3]}: {exc}",file=sys.stderr)
        raise SystemExit(2)
hits=scan(Path(sys.argv[1]),sealed.get("exact",()),sealed.get("tokens",()))
for path,name in hits: print(f"SCRUB FAILED: {path} carries forbidden class '{name}'",file=sys.stderr)
raise SystemExit(5 if hits else 0)
