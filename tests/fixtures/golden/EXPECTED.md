# The golden fixture — hand-computed, and why it beats 443,535 rows

> **What this is.** Forty tracked pageviews across three counted subsites plus one archived
> subsite, small enough that every reported number can be checked by hand, and shaped so that
> the *specific* ways a network report goes wrong all produce a visibly different answer.
>
> **What it is for.** The 443k reference dataset can tell you a query got slower. It cannot tell
> you a total is wrong, because nobody knows what the right total is. This fixture knows.

---

## Why a small fixture is the stronger instrument here

Phase D and F9 concern **network aggregation**, and every defect in that area has the same
shape: a number that is plausible but wrong. A big random dataset cannot catch those, because
the only available oracle is the code under test. Three properties make this fixture able to:

1. **Unique visitors are NOT additive.** One visitor (`10.0.0.1`) appears on two subsites. So
   the network-wide distinct-IP count is **6** while the sum of per-blog counts is **7**. Any
   implementation that adds per-blog numbers reports 7 and looks fine.
2. **Bounce rate is NOT averageable.** Network bounce rate is **1/7 = 14.2857%**; the mean of
   the three per-blog rates is **(0 + 0 + 50)/3 = 16.667%**. Both are "about 15%", which is
   exactly why the wrong one survives review.
3. **An archived subsite must contribute nothing.** Blog 4 is archived and holds 6 rows,
   four of them on the shared path. Include it wrongly and the total reads 46 instead of 40 and
   `/about/` gains a third row — the live cross-network/blog leak X8 describes, made visible.

A fourth property is structural rather than numeric: `/about/` exists on blog 1 **and** blog 3.
Under ratified decision **P3** that is *two rows, listed twice with the site name* — not one row
of 11. A merged implementation produces a number that is not wrong so much as meaningless, and
the fixture is the only place that distinction is checkable.

---

## The data

Four subsites. Three are counted; blog 4 is archived and must be excluded everywhere.

| blog | status | rows | visits | distinct IPs |
|---|---|---:|---:|---:|
| 1 (main) | public | 15 | 3 | 3 |
| 2 | public | 14 | 2 | 2 |
| 3 | public | 11 | 2 | 2 |
| **counted total** | | **40** | **7** | **6** ← not 7 |
| 4 | **archived** | 6 | 1 | 1 |

### Per visit

| blog | visit | ip | pageviews | resources |
|---|---|---|---:|---|
| 1 | 101 | 10.0.0.1 | 6 | `/`×3, `/about/`×2, `/pricing/`×1 |
| 1 | 102 | 10.0.0.2 | 5 | `/`×2, `/about/`×2, `/pricing/`×1 |
| 1 | 103 | 10.0.0.3 | 4 | `/`×1, `/about/`×1, `/pricing/`×2 |
| 2 | 201 | **10.0.0.1** | 7 | `/`×4, `/shop/`×2, `/contact/`×1 |
| 2 | 202 | 10.0.0.4 | 7 | `/`×3, `/shop/`×2, `/contact/`×2 |
| 3 | 301 | 10.0.0.5 | 10 | `/about/`×6, `/`×2, `/team/`×2 |
| 3 | 302 | 10.0.0.6 | **1** | `/`×1 — the only bounce |
| 4 | 401 | 10.0.0.9 | 6 | `/about/`×4, `/`×2 — archived, excluded |

`10.0.0.1` is deliberately the same person on blogs 1 and 2. That single fact is what makes
visitor counts non-additive.

### Per resource, per blog

| resource | blog 1 | blog 2 | blog 3 | (blog 4, excluded) |
|---|---:|---:|---:|---:|
| `/` | 6 | 7 | 3 | 2 |
| `/about/` | 5 | — | 6 | 4 |
| `/pricing/` | 4 | — | — | — |
| `/shop/` | — | 4 | — | — |
| `/contact/` | — | 3 | — | — |
| `/team/` | — | — | 2 | — |
| **blog total** | **15** | **14** | **11** | 6 |

---

## The expected answers, computed by hand

Every number below is arrived at by counting the table above — never by running the plugin.
`tests/golden-fixture-test.php` recomputes each one from the raw rows with trivial array code
and fails if the two derivations disagree, so the fixture cannot drift from its own oracle.

| # | Question | Answer | The wrong answer it catches |
|---|---|---:|---|
| 1 | Network pageviews | **40** | 46 — archived blog included |
| 2 | Network distinct visitors (by IP) | **6** | 7 — per-blog counts summed |
| 3 | Sum of per-blog distinct visitors | 7 | — (stated so #2 is falsifiable) |
| 4 | Network distinct visits | **7** | 8 — archived blog included |
| 5 | Bounces (visits with exactly 1 pageview) | **1** | 0 — bounce computed per blog then summed wrongly |
| 6 | Network bounce rate | **1/7 = 14.2857%** | 16.667% — mean of per-blog rates |
| 7 | Rows for `/about/` in a network "top resources" report | **2** (blog 1 = 5, blog 3 = 6) | 1 row of 11 — merged, violating P3 |
| 8 | Largest single `/about/` figure | **6** (blog 3) | 11 — merged |
| 9 | Top resource network-wide, per-blog rows | `/` on blog 2 = **7** | `/` = 16 — merged across blogs |
| 10 | Any figure sourced from blog 4 | **none** | — |

### Ranked Top Web Pages (`slim_p1_08`)

The ranked answer preserves blog grain, orders `counthits` descending, then breaks ties by
resource and blog id before applying `LIMIT 7`:

| rank | blog | resource | counthits |
|---:|---:|---|---:|
| 1 | 2 | `/` | **7** |
| 2 | 1 | `/` | **6** |
| 3 | 3 | `/about/` | **6** |
| 4 | 1 | `/about/` | **5** |
| 5 | 1 | `/pricing/` | **4** |
| 6 | 2 | `/shop/` | **4** |
| 7 | 3 | `/` | **3** |

The equal counts at ranks 2–3 and 5–6 exercise ordinary ties. Rank 7 cuts through the three-hit
tie: `/` on blog 3 is retained while `/contact/` on blog 2 is immediately outside the limit.
Applying the limit before ordering therefore produces a different answer. Merging blog rows
instead would manufacture `/ = 16`, which is not this report's grain.

### The two traps, written out

**Visitors (#2 vs #3).** Distinct IPs across the counted blogs are
`{10.0.0.1, .2, .3, .4, .5, .6}` = **6**. Per blog: 3 + 2 + 2 = **7**. The difference is
`10.0.0.1`, counted once network-wide and twice when summed.

**Bounce (#5 vs #6).** Exactly one visit has a single pageview: visit 302 on blog 3.

- Network: 1 bounce ÷ 7 visits = **14.2857%**
- Per blog: blog 1 = 0/3 = 0%, blog 2 = 0/2 = 0%, blog 3 = 1/2 = 50%
- Mean of those: (0 + 0 + 50) / 3 = **16.667%**

Both look like "roughly 15% of visits bounce". Only one is the rate.

---

## Time axis

All rows sit on fixed UTC timestamps inside a known window so date-range filtering is
checkable rather than incidental:

- **Day A** = `2026-01-15`, **Day B** = `2026-01-16`, **Day C** = `2026-02-20`
- Days A and B are inside a 30-day window ending `2026-02-10`; day C is outside it.

| day | visits on it | rows |
|---|---|---:|
| A | 101 (6) + 201 (7) + 301 (10) | **23** |
| B | 102 (5) + 202 (7) | **12** |
| C | 103 (4) + 302 (1) | **5** |
| | | **40** |

So a 30-day report returns **35** (A + B) where an all-time report returns **40**. Different,
which is the property that matters.

> The first draft of this section claimed 28 and 12 — numbers that do not even sum to 40. They
> were written without doing the arithmetic, and `golden-fixture-test.php` failed on them
> immediately. That is the fixture's own mechanism working on its author, and it is the reason
> the expected values are recomputed rather than trusted.

This is the property the 443k dataset does **not** have: its 20× duplication compressed the
time axis to ~33 days, so every scan reports `examined ≈ 401,240` at both −30 and −90 and no
range conclusion is falsifiable (seam I8 rebuilds it). Here, a report that ignores the date
filter returns 40 where 35 is correct, and says so immediately.

---

## What this fixture does NOT establish

It is a **correctness** oracle, not a performance one. Forty rows cannot show a plan change, an
index being used, or a regression in row-reads — those need I8's reshaped dataset. Claiming a
speed result from this fixture would be the "measured on the wrong instrument" error the
verification protocol exists to prevent.

It also does not cover topology D (external database) or F (refused), and it asserts nothing
about subdomain vs subdirectory addressing: those are environment shapes, exercised by
`tests/docker/run-topology.sh`, not data shapes.
