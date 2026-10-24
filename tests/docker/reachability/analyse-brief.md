# Reachability analysis brief — the LLM half of the differential protocol

This is the prompt an isolated analyser is given, and the schema its answer must satisfy. It is a
file rather than a paragraph in a driver because the protocol's whole claim is that the analyser
was asked the SAME question in every state — baseline, M1, M2 — and a question retyped per run is
three questions.

## What you are asked

You are given ONE file. Read it and answer, for **every control in its CONTROLS block**:

1. **Call chain.** The concrete path from the top of the file (the script is executed by
   `wp eval-file`, so the file's top-level statement stream IS the entrypoint) to the statement
   that renders this control. Name each enclosing construct: `try` block, `foreach`, `if`,
   closure, function. A statement inside a closure or function DECLARATION runs only if something
   invokes it — if that is the case, say what invokes it and where, or say that nothing does.
2. **Exit effect.** If this control's predicate is false, does the process exit non-zero? Follow
   the actual variable: which callable renders it, what that callable records into, whether it
   captures that by reference, and whether the terminal `exit()` is guarded on the same variable.
   A control that renders a line into a list nothing reads is **exit-ineffective**, even though
   it prints `[!!]` perfectly.
3. **Side.** Which side of the equality this control's evidence comes from —
   `mysql-only` (it observes only the MySQL/PHP path), `export-only` (only the SQLite/Python
   path), or `shared` (its evidence passes through code both sides use). For `shared`, name the
   shared component.
4. **Circularity.** For the file as a whole: of the values it compares, which are genuinely
   produced by two independent implementations, and which are one implementation observed twice?
   Quantify it — how much of the equality could not fail?

## What counts as a defect

Report a defect **only** for these two relations, and only when you can point at the lines:

| relation | meaning |
|---|---|
| `reachability` | the control's call site is not reached on an ordinary run |
| `exit-effect` | the control executes, but its failure cannot change the exit status |

Do not report style, wording, coverage gaps, or anything the file itself discloses under
"WHAT IT DOES NOT PROVE" or a control's "NOT observed" line. Those are not what is being asked
and they dilute the one signal this protocol reads.

If you cannot complete the analysis — the file is unreadable, you run out of context, you are
unsure — set `status` to `refused` and say why. **A refusal is an honest answer and is recorded
as one.** It is scored as a MISS, which is correct: an analyser that cannot answer has not
detected anything.

## The digest is not optional

Compute the SHA-256 of the exact file you read and put it in `subject_sha256`:

```
shasum -a 256 <path>
```

The protocol ties every analysis to the state it analysed by that digest. An analysis whose
digest does not match the state it claims to describe is DISCARDED — which is the only defence
against a cached, remembered or stale reading being counted as a fresh detection.

## Answer schema

Return exactly this JSON object and nothing else:

```json
{
  "subject_path": "<absolute path you read>",
  "subject_sha256": "<64 hex chars>",
  "status": "analysed | refused",
  "refused_because": "<null, or why you could not answer>",
  "controls": [
    {
      "n": 1,
      "name": "NON-VACUOUS",
      "line": 573,
      "call_chain": ["file top-level", "try { … }", "$control(...) at line 573"],
      "renders_via": "$control",
      "records_into": "$failures",
      "reachable": true,
      "exit_effective": true,
      "side": "shared",
      "side_reason": "…"
    }
  ],
  "defects": [
    {
      "control": 4,
      "relation": "reachability",
      "summary": "<one sentence naming the control and what is wrong>",
      "evidence_lines": [599, 610]
    }
  ],
  "circularity": {
    "two_implementation": ["…"],
    "one_implementation_observed_twice": ["…"],
    "assessment": "<a paragraph: how much of the equality could not fail, and why>"
  }
}
```

`defects` is `[]` when the wiring is sound. It is not a place for observations.
