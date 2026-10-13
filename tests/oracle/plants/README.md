# The plant set — the classifier's mutation set

`plants.json` holds one **plant** per label `tests/oracle/classify.py` can emit and one per rule
in its decision table. A plant is an answer **triple** (OLD, NEW, ORACLE), the contract and
register it is judged under, the label it MUST receive, and one sentence saying what mislabelling
it would cost the campaign.

Every rule name the DIFFER can construct — `algebra.DIFF_RULES`, twenty of them — is reached by
a plant or by a direct assertion in section 8 of the gate. That is now a **loop**, not a
sentence: `_diff()` refuses a rule name the tuple does not declare, the gate records every rule
constructed during the run, and it fails naming any declared rule nothing reached.

> **This paragraph used to read "and one per edge in `compare/algebra.py`", and it was false.**
> Run 53's review measured it by wrapping `_diff()`: 11 of 19 edges were reached by no plant at
> all. Among them were `above-the-cut-differs` and `cut-value-differs` — both halves of the only
> rail bounding the tie tolerance — either of which could be turned into `EQUAL` with this gate
> printing `plants=28 assertions=216 failures=0`. Two of the three "one per" claims were
> mechanised as loops over `C.LABELS` and `C.RULES`; the third was prose, and the prose one was
> the one that was wrong (PITFALLS 64). It is a loop now, and eight plants and eleven direct
> assertions were added to make it pass honestly rather than by narrowing the claim.

Run them:

```bash
composer test:classifier-plants          # python3 tests/oracle/classify_test.py
```

The gate is inside `composer test:source-level`, which CI runs. A gate no script invokes runs
nowhere (PITFALLS 4, and PITFALLS 43 for the twenty-four that ran nowhere for months).

## Why plants, and not "unit tests for the classifier"

The classifier's whole value is that its branches were exercised *before* the campaign trusted
them. `tests/control-wiring-test.php` says the same thing about the control analyser it gates:

> An analyser that has only ever been run on code it passes has not been run.

So the plant set is written the way a mutation registry is written. Each plant exists because
some specific way of being wrong would produce a different label for it, and the record says
which way. That is the difference between a fixture and a **discriminating** fixture — PITFALLS
22's question, asked per plant: *what label does this triple get with the defect, and without
it?* If those are the same label, the plant is scenery.

## What each plant declares

| field | meaning |
|---|---|
| `id` | `P<nn>-<slug>`, unique. It is what a failure prints |
| `why` | what the triple is, in campaign terms — usually a real defect from the register |
| `mislabelling_means` | the consequence. Printed on failure, because a red gate should say what it is protecting |
| `contract` | `places` / `limit` / `count_field` / `cut_side` / `exact_columns`. Empty means the defaults |
| `register` | the machine-readable expected-diff entries in force for this plant. Usually `[]` |
| `triple` | `surface`, and the three answer envelopes. `oracle: null` means the oracle had no answer |
| `expect.label` | required. The exact label |
| `expect.rule` | the decision-table rule that must have produced it |
| `expect.disposition` | `pass` / `block` / `unresolved` |
| `expect.arms_rule` | which **algebra** rule decided the OLD-vs-NEW comparison |
| `expect.observable` | what the difference looked like, in the register's vocabulary |
| `expect.register_id`, `expect.pre_blind`, `expect.near_miss` | register facts the verdict must carry |

`expect.rule` and `expect.arms_rule` are not decoration. A plant that reaches the right label
through the wrong rule is a defect in the table's ordering wearing a green tick — the same
lesson as PITFALLS 6 (a mutation killed by an assertion it never touched) and PITFALLS 57 (a
mutation killed by a layer other than the one it was written for).

## The envelopes are the capture's, not a convenient copy

`class` is `ok` / `empty` / `zero` / `error` / `unsupported`, exactly as `slimstat_capture()`
emits it in `tests/docker/report-answers.php`, and the arm envelopes carry the full
`flags{clock_dependent, calendar_day_dependent, pinned}` block because that function emits all
three unconditionally. Nothing in `algebra.py` or `classify.py` *derives* a class from a value:
that line is drawn once, gated by `tests/answer-envelope-classes-test.php` (17 assertions) and
pinned by the mutation `S1-error-reads-as-empty-01`. Two parsers of one contract drift
(PITFALLS 5), and this is the one contract the campaign's foundation rests on.

Values are written the way the two sides really produce them: OLD and NEW hand over **strings**,
because wpdb returns every column as one; the oracle hands over typed Python numbers. A plant
that spelled both sides the same way would not exercise the normalisation every real comparison
depends on.

## The floor

`FLOOR` holds the plant count and the gate requires **equality**, both directions:

* fewer plants than the floor — a plant was deleted; raise the floor deliberately if one is
  genuinely obsolete, never lower it to match a deletion;
* more plants than the floor — write the new number in, because until you do the new plant is
  deletable without any gate noticing.

The second direction is not pedantry. `tests/mutations/FLOOR` failed only on `count < floor`
until PITFALLS 61, which meant every mutation added without ratcheting the file was free to
remove.

`plants.json` also declares `expected_assertions`, checked for exact equality. A plant that
quietly loses its `expect` keys still runs, still prints, and still exits 0 otherwise — a
counter nothing checks is decoration.

## The five controls, which are the point

The gate does not only run the plants. It runs five deliberately degraded classifications and
requires the plant set to **catch** each one. Green is not evidence that a gate works; watching
it go red is (PITFALLS 31).

1. **Flattened classes** — every `error` and `zero` answer rewritten as `empty`, the falsy-test
   classifier. Every plant that turns on the distinction must change label.
2. **Emptied register** — every plant expecting `a` must stop being `a`. If the label survives
   an empty register, the register is decoration and every unexplained difference passes as
   expected.
3. **Dropped LIMIT** — `P23` (tie at the cut) must stop being `equal` when its contract's limit
   is removed. If it passes with and without, it never exercised the tie rule.
4. **Canonicalised row order** — every `ORDER-ONLY` plant must stop being `ORDER-ONLY` once both
   arms' rows are sorted into one sequence. Added in run 53: `ORDER-ONLY` had a discriminating
   plant and no control, so nothing had ever watched the distinction being taken away.
5. **Dropped `places`** — `P25` must stop being `equal` without the emitted precision that made
   two values differing in the eighth decimal render alike. Added in run 53 for the same reason.

The degradation is applied to the DATA, never by editing a file, so nothing has to be restored
afterwards — PITFALLS 33 is an hour of work destroyed by the `git checkout` that was cleaning up
after a probe that had not even run.

## The 38 ways this is proven to fail — registered, not recounted

> **That number is read by the gate, not typed here.** It was wrong twice. First it was a prose
> table of ten mutations someone had once run by hand; run 53 replaced it with a spelled-out
> *"Twenty-two"* — and the follow-up commits took the directory to twenty-five without touching
> the sentence, so the paragraph written to close a stale hand-count went stale itself, one
> commit later, in exactly the same way. Section 3b of `classify_test.py` now parses the digits
> out of the heading above, counts `tests/mutations/S4-*.mutation`, and fails naming both
> numbers when they disagree — and asserts the section's other claim too, that every one of
> those files carries `gate: composer test:classifier-plants`.
>
> That check has no mutation of its own — nothing guards section 3b but section 3b. An earlier
> draft of this line claimed otherwise and named a mutation file nobody had written.

This section used to be a prose table of ten mutations someone had once run by hand. The
mutations were real and the table was accurate; what it could not do is *run again*. The
reasoning for not registering them — that `tests/mutations/` is for PHP gates — was wrong:
`tests/mutation-registry-test.php` accepts `gate: composer <script>` for any registered composer
script, and `test:classifier-plants` is one. (`S2-python-accept-list-widened-01` had already been
proving that with a Python target and a Python gate.)

So they are registry entries now, `S4-*.mutation`, each with `gate: composer
test:classifier-plants` and an `expect:` naming the ONE assertion that must fire — so a mutation
killed by an unrelated assertion reads as INVALID rather than as coverage (PITFALLS 6, ADR-E8).
Run them:

```bash
composer test:mutation-registry                          # the files are well-formed
php tests/verify/bin/run-mutations.php --filter=S4-      # every one must be KILLED
```

Each one restores a defect this gate was written for, and the ids say which: the table's ordering,
which needed two entries because a guard rewrite can say THIS PREDICATE and never THIS POSITION
(`S4-new-errored-below-oracle-rules-01`, `S4-new-errored-below-arm-unsupported-01`), label (a)
granted without the oracle
(`S4-registered-old-error-without-oracle-01`), the two halves of the tie tolerance's rail and its
band guard, the vacuity rule swallowing a real arms difference, the clock/pin flag reads, the
`Decimal(str(float))` boundary, both `cut_side` branches and the guard on the value, six widening
edges in the scalar and envelope comparisons, the negative-zero fork, and the near miss printed
on a hit.

### The MIRROR of a one-sided guard is a defect of its own

Every entry added after run 53's repair round but one says the same thing: a two-sided property
had been pinned on one side, or a rule was pinned above some of what it has to outrank.
`f.agrees(f.oracle_new)` was pinned where the oracle held rows and not where it held nothing
(`S4-oracle-backs-new-by-being-empty-01`); the
clock flag was pinned being read from OLD and not from NEW, and the pin was pinned the other way
round (`S4-clock-flag-read-from-new-only-01`, `S4-pin-read-from-new-only-01`) — each because the
single asymmetric plant that existed happened to lean the way a one-armed reader still sees; the
band guard's `len(a_rows) != limit or len(b_rows) != limit` had every plant sitting at exactly
`limit` on the a-side (`S4-band-guard-drops-the-first-arm-01`); the cut was pinned at the
right END of the ranking and not at the right POSITION in the list, because every band plant
listed its rows in ranked order where `min()` and `rows[-1]` coincide
(`S4-cut-taken-as-the-last-row-01`); `old-errored` was pinned above two of the three rules it has
to outrank (`S4-oracle-errored-outranks-old-errored-01`); and `new-errored` above two of its own
three (`S4-new-errored-below-arm-unsupported-01`).
The seventh is a different shape again — an edge two plants REACHED and no assertion READ — and it is closed by an assertion in section 8 of `classify_test.py` rather than by a registry entry.

The rule that falls out, and it is cheap to apply: **when a guard names two sides, write the
mutation that deletes each side separately.** A conjunction killed only by deleting the whole of
it is pinned by nothing in particular.

And one size up, because the two-sided rule did not cover the case that bit last: **when a rule's
correctness is its POSITION relative to N others, it owes N mutations — or one that MOVES it.**

And a third rung, because neither of the two above caught what Run 55 found: **when a property is
required of a CLASS of rules, holding it on one member proves nothing about the others — and what
it owes is an invariant over the table, not a mutation per rule.** `f.agrees()` was repaired on
`old-errored-registered` and the entry recording that repair asserted, of the only other rule
emitting the same label, that it *"always carried the term"*. It did not
(`S4-oracle-backs-new-by-being-empty-02`). Fifty plants were green with the hole present and
stayed green when it was fixed. `_assert_table_invariants()` now reads each guard's own bytecode at
import so a PASS-granting rule that consults `agrees()` without `ANSWERED` cannot be written at
all. ADR-18 Q2 then made the broader `Facts.agrees()` hollow guard redundant: the decision table
settles every arms difference beside an empty oracle as d-unmodeled before a backing rule can read
it. Its mutation survived for that reason, so the dead guard and obsolete mutation were removed
rather than preserved behind an assertion with no behavioral consequence. PITFALLS 77.
`new-errored` names no sides; it has to outrank three rules, and a guard rewrite gave it two.
`S4-oracle-errored-outranks-old-errored-01` shows the move spelling is available — it carries a
two-hunk diff that relocates a whole `Rule(...)` block — so "one anchor" was never a constraint
of the format, only a habit.

## The PHP rounding probes

`php_round_probes` in `plants.json` is a hand-derived fixture in the sense the encoding fixtures
are: every expected value is what **PHP's own `round()` printed**, never what the Python helper
returned. Measured on **PHP 7.4.33** (`docker run --rm php:7.4-cli`, the declared floor) and on
**PHP 8.5.5** (this workstation, i.e. the rewritten 8.4+ implementation); both printed the same
value for all fifteen probes, and `compare/algebra.py::round_half_up` reproduces all fifteen.

The first eleven are chosen, not sampled: three negatives, because `decimal.ROUND_HALF_UP` is
ties-*away-from-zero* despite its name and that is the half of the claim most likely to be
assumed rather than checked; `1.005`, `0.285`, `2.675`, `8.475`, because a rounder working on
the binary double gives the other answer for each; and one long value for magnitude.

The **last four repeat four of those as JSON numbers**, and that is not duplication. Every
original probe was a JSON *string*, so `_decimal()` took its `str` branch every time and the
float branch — the one `round_half_up`'s docstring calls load-bearing — was never taken by a
probe at all. `Decimal(str(value))` → `Decimal(value)` left the gate green for the whole of S4.
With the four number-spelled probes it takes six assertions red.

## Where the rounding claim STOPS being true — measured, not argued

`php_round_divergences` records the boundary the eleven probes structurally could not find. A
decimal literal of at most 15 significant digits survives pre-8.4 PHP's 15-digit pre-round
unchanged, so short literals are the one class where the two implementations can never disagree —
and all eleven were short literals.

Generated from the plugin's own percentage expression, `round(($n / $d) * 100, $places)`
(`admin/view/wp-slimstat-db.php:2290` and `:2763`), for every `d <= 2000`: 1400 boundary values,
each run through `round()` on `php:7.4-cli` (7.4.33), `php:8.2-cli` (8.2.31), `php:8.3-cli`
(8.3.33) and PHP 8.5.5.

**144 of the 1400 disagree with this helper on 7.4, 8.2 and 8.3. None disagrees on 8.5.5.** Two
of them come from ordinary two-digit funnel inputs:

| value | places | PHP ≤ 8.3 | PHP 8.4+ and this helper |
|---|---|---|---|
| `(23/160)*100` = 14.374999999999998 | 2 | 14.38 | 14.37 |
| `(23/80)*100` = 28.749999999999996 | 1 | 28.8 | 28.7 |

Pre-8.4 `round()` pre-rounds to 15 significant digits, which pulls a double sitting 1–2 ULP
*below* a decimal half exactly onto the half and then rounds it away from zero. The gate asserts
both halves — that the helper gives the 8.4+ answer, **and** that the recorded ≤8.3 answer really
differs from it — because a fixture that can only confirm itself is the thing this gate exists to
refuse. Nothing is misclassified today (`DEFAULT_CONTRACT['places']` is `None`); the decision the
divergence forces is owed to S5, and is written down in `plants.json` rather than guessed at.

## Adding a plant

1. Write the triple and the label. State `mislabelling_means` in terms of what the campaign
   would get wrong — not "the test would fail".
2. Add `expect.rule`, and `expect.arms_rule` when the plant is about an algebra edge.
3. Raise `FLOOR` and `expected_assertions` in the same commit.
4. **Break the thing the plant is for, and watch the gate name your plant.** A plant that has
   never failed is a plant nobody has tested — and the person most likely to add a vacuous one
   is whoever has just finished writing the guard against vacuous guards.
5. **Then register that break**, as `tests/mutations/S4-<what-it-restores>-01.mutation` with
   `gate: composer test:classifier-plants` and an `expect:` naming the assertion that must fire,
   and raise `tests/mutations/FLOOR` by one in the same commit. A break you watched once is a
   memory; a registry entry is re-run by `composer test:mutations` for as long as the file lives.
