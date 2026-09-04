# S7 — the canary drill, pre-declared

> **Written and digest-pinned BEFORE the canary run.** Everything below — the subject, the
> defect, what counts as a catch, what counts as a miss, and what the controls are — is fixed
> here first. A detection criterion authored after the reports are in is a description of what
> happened, not a gate. `run-canary.sh predeclare` records this file's sha256 into the run
> record; `compute-canary-verdict.py` refuses a verdict if the file has moved since.

## Why this exists

Run 58 ran a sealed blind OLD↔NEW comparison end to end: a scrubbed packet, nine controls PASS,
three isolated agents, both arm readers answering *cannot tell*. It established that the blind
seal **runs**. It established nothing about whether blind adjudication **works**, because nothing
was hidden in that packet for it to find. Until a planted defect is caught, every adjudication in
this programme is an unfalsified procedure.

## The subject and the defect — pre-declared

| | |
|---|---|
| **carrier report** | `top_resource` |
| **why this report** | it is the only report in the answer set that both (a) saturates its result cap — 200 rows on this corpus, printed by the run's own CONTROLS block — and (b) has five siblings that also return exactly 200, so a one-row shortfall is anomalous *within a single arm* as well as *between two arms*. On `top_browser` (59), `top_country` (105), `top_platform` (20) and `top_platform_prefixed` (10) a −1 truncation changes nothing at all, because those reports never reach the cap. |
| **defect class** | off-by-one truncation of a ranked list at the LIMIT |
| **mechanism** | in the NEW arm only, `get_top()` applies `limit − 1` when and only when it is asked for the bare `resource` column with no WHERE — the call the `top_resource` report makes, and no other captured report makes |
| **pre-declared observable** | exactly one arm's `top_resource` holds **199** rows where the other holds **200**; the missing element is the lowest-ranked one; every other report in both arms is unaffected by the canary |
| **blast radius** | ONE report. This is deliberate and is the difference between this drill and a louder one: a patch at the shared LIMIT site would have moved six reports at once and would have been far easier to see. |

The patch is `C1-top-limit-off-by-one.canary`. It is applied to an **arm worktree** by
`run-canary.sh`, never committed to any branch, and restored byte-identically afterwards with the
restoration verified by digest rather than by `git checkout` — a restore that silently did nothing
looks exactly like a clean one.

## What counts as a CATCH

A detection counts **only** if a filed adjudication report contains a finding that carries **both**:

1. `subject == "top_resource"` — the pre-declared carrier, named; and
2. `relation == "row-count-differs-by-one"` — the pre-declared defect, named from the closed
   vocabulary every adjudicator is given.

The vocabulary is closed and identical for every agent, on the canary packet and on the baseline
packet alike, and it is what makes the finding machine-checkable instead of a reading of prose.
The vocabulary is not a hint: `row-count-differs-by-one` is one of eight relations, and the
baseline control below is what proves an agent cannot simply reach for the suspicious-sounding one.

## What counts as a MISS

Everything else, and each of these is a miss on its own:

- a finding naming a different report, however correct it is;
- a finding naming `top_resource` under a different relation;
- "the two arms differ" without naming the subject;
- an error, a refusal, a timeout, or an empty report;
- **a finding that is also present in the BASELINE adjudication** — see controls.

## Which leg is expected to catch it, declared before the run

Three legs see the packet, and they are not equally able to see this defect. Saying so in advance
is the point: otherwise whichever leg happens to catch it reads as the leg that was supposed to.

| leg | what it holds | expected |
|---|---|---|
| **arm reader** on the poisoned arm | one arm's answers, nothing else | **CAN** catch: `top_resource` 199 against five siblings at 200 is an internal anomaly |
| **arm reader** on the clean arm | one arm's answers, nothing else | **CANNOT** catch — there is nothing wrong in what it holds. A canary finding here is a FALSE POSITIVE and is recorded as one. |
| **comparator** | both arms' answers, no mapping | **CAN** catch: 199 against 200 is a direct difference |

The drill passes if **any** leg catches it. Which leg did is recorded, because a catch by the
comparator alone leaves the single-arm leg — the only one that can find a defect both arms share,
PITFALLS 88 — still unevidenced.

## Controls

| id | what it proves | fails how |
|---|---|---|
| `predeclaration_pinned` | these criteria were fixed before the reports existed | this file's sha256 moved between `predeclare` and `verdict` |
| `digest_moved` | the patch actually applied | subject digest identical before and after `apply` |
| `subject_restored` | the arm worktree is pristine again | restored digest != baseline digest |
| `canary_observable` | the poison reached the answers | the packet does not show 199 against 200 on `top_resource` |
| `baseline_clean` | the catch is not something an agent says about any packet | the same three briefs, run against the **unpoisoned** Run-58 packet `R20260824-2c8d1a`, produce a finding meeting the CATCH criteria |
| `arms_differ` … `names_neutral` | the sealed run's own nine controls | as `seal-tool.py validate` defines them |

`baseline_clean` is the control that carries this drill. Without it, "an agent flagged
`top_resource` with a row-count relation" is not evidence of anything: it has to be shown that the
same agents, under the same brief, do **not** say it about a packet with no canary in it.

## On a MISS — failure protocol 3

Blast radius depends on where the chain broke, and the two cases have different costs:

- **the comparison never flagged the poisoned report** → rig-level defect. Fix the comparison, add
  the case as a permanent plant, re-run the deterministic stages against the same sealed captures.
- **the comparison flagged it and the blind adjudicator waved it through** → the **adjudication
  layer** is rejected and redesigned; every prior blind adjudication is void and re-done under the
  new protocol; the seal stays closed throughout.

Either way the retry uses a **different** canary patch. The burned one is never reused.

## What this drill does NOT establish

- One defect class of one — truncation. The four-class canary is a Phase 2 item and stays owed.
- One report. Nothing here says the other 25 surfaces are adjudicated with the same sensitivity.
- The isolation of the adjudicating agents is enforced by **instruction**, not by a sandbox: each
  is told to read one named file and to report every file it read. The packet is scrubbed and the
  mapping is `0600` under a `0700` directory, so the arm→ref mapping is not casually reachable —
  but an agent that went looking for the run directory could read what its own uid owns. Run 58
  had the same exposure. It is recorded here rather than described as isolation.
