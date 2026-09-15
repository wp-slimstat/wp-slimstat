# Brief — comparator

> Archived for the same reason as the arm-reader brief: Run 58's was not, so no later run could
> claim to have used it. Used unchanged on the canary packet and on the baseline packet.

You are given **two JSON files**, `arm-1` and `arm-2`: the answers two variants of a reporting
system produced for the same set of analytics reports, over the **same corpus**, with the same
absolute time window. The data underneath is identical. Any difference between the two files
comes from the code, not from the data.

You do not know which variant is which, which is older, which direction any change went, or what
changed. There is no mapping available to you and you must not go looking for one. Read **only the
two files named in your task**. Report every path you read.

## Your job

Find **every** difference between the two documents, and characterise each one.

Both completeness and precision matter, and they fail in different ways. A difference you do not
report is one nobody will look at again. A difference you report vaguely — "the reports differ" —
is one nobody can act on, and counts for nothing.

Work through it: compare every top-level key; for list reports compare lengths first, then
membership, then order, then element values; for nested objects compare each field. A one-element
difference at the tail of a long list is exactly as reportable as a large one, and is the harder
one to see.

**An empty findings list is a legitimate answer** if the two documents are equal. Do not
manufacture differences.

## Filing a finding

Every finding carries a `subject` and a `relation`.

`subject` is the **exact top-level key** the finding is about, spelled as it appears in the files.

`relation` is one of exactly these eight, and nothing else:

| relation | means |
|---|---|
| `row-count-differs-by-one` | one arm's list holds exactly **one** element fewer than the other's |
| `row-count-differs-by-many` | one arm's list holds more than one element fewer than the other's |
| `value-differs` | a scalar differs, or an element present in both lists carries a different value |
| `ordering-differs` | the same members in a different order |
| `report-empty` | one arm's report holds no rows where the other's does |
| `report-errored` | one arm reports an error, or the key is absent on one side |
| `internal-inconsistency` | a number contradicts another number **within one document** — worth reporting even though it is not a difference between the two |
| `other` | none of the above fits |

**If more than one relation could apply, choose the most specific.** The row-count relations take
precedence over `internal-inconsistency`; `internal-inconsistency` takes precedence over `other`.

Give `evidence` as the actual numbers, quoted from the files, with the arithmetic shown. Say which
arm is which side of the difference by its label. A finding without numbers cannot be checked and
will be discarded.

## Two further questions

1. Can you tell **which arm is the older version and which is the newer**? If you cannot, say
   `cannot-tell` and list the markers you looked for. A guess dressed as a reading corrupts the
   measurement this question exists to make.
2. For each difference: on the evidence in front of you, is one side **more likely to be correct**
   than the other, and why? Where nothing in the documents settles it, say so — "cannot be settled
   from these documents" is the right answer far more often than a preference is.
