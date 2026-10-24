# Brief — arm reader

> Archived because Run 58's were not. Its three adjudications were run from prose typed into a
> session and nothing in the tree records what the agents were actually asked, so no later run can
> claim to have used "the same brief". This file is the brief. It is used unchanged on the canary
> packet and on the baseline packet, and changing it invalidates the comparison between them.

You are given **one JSON file**: the answers a reporting system produced for a set of analytics
reports, over one corpus.

You do not know what produced it, when, which of two variants it is, or what the other one is.
Do not try to find out, and do not read anything else. Read **only the file named in your task**.
Report every path you read.

## Your job

Judge this file **on its own internal consistency**. You have no second file to compare against,
so the only defects you can find are the ones visible from inside: a total that does not equal the
sum of its parts, a count that contradicts a related count, a list shorter or longer than the
thing it should match, a report that is empty or errored where its siblings are populated, a value
that cannot be right given another value in the same document.

This is the only leg of the protocol that can find a defect which is present in *both* variants —
where a comparison between them is silent because they agree. Read accordingly: do the arithmetic,
re-derive the sums, compare each report against the reports most like it.

Report what you actually found. **An empty findings list is a legitimate and expected answer** if
the document is internally consistent. Do not manufacture a finding to have something to say, and
do not report a stylistic observation as a defect.

## Filing a finding

Every finding carries a `subject` and a `relation`.

`subject` is the **exact top-level key** of the report the finding is about, spelled as it appears
in the file.

`relation` is one of exactly these eight, and nothing else:

| relation | means |
|---|---|
| `row-count-differs-by-one` | a list report holds exactly **one** element fewer or more than the thing it should match — a directly comparable sibling report, or a cap every comparable report reaches |
| `row-count-differs-by-many` | the same, but by more than one element |
| `value-differs` | a scalar, or an element inside a list, is not the value it should be |
| `ordering-differs` | the right members in the wrong order |
| `report-empty` | a report holds no rows where the document gives reason to expect some |
| `report-errored` | a report reports an error, or is absent where it should be present |
| `internal-inconsistency` | a number contradicts another number in the same document — a total against its parts, a count against a related count |
| `other` | none of the above fits |

**If more than one relation could apply, choose the most specific.** The row-count relations take
precedence over `internal-inconsistency`; `internal-inconsistency` takes precedence over `other`.

Give `evidence` as the actual numbers, quoted from the file, with the arithmetic shown. A finding
without numbers in it cannot be checked and will be discarded.

## One further question

Say whether you can tell **which era or version** of the software produced this file — older or
newer, before or after some change — and how you can tell. If you cannot tell, say
`cannot-tell` and list the markers you looked for and did not find. Guessing is worse than
`cannot-tell`; this question exists to measure whether identifying information survived the scrub,
and a guess dressed as a reading corrupts that measurement.
