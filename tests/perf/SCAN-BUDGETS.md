# Report scan budgets

The gate still rejects `ALL` and `index` access on tables above 100,000 rows.
An exact query/report pair in `lib/aggregate-budgets.json` may instead pass a
measured aggregate budget. Unknown queries, changed predicates/limits, unresolved
aliases, incomplete captures, rendering errors, and failed measurements fail.
Live Analytics' selective queries have no aggregate exemption.

Each listed query computes a count, distinct count, grouped summary, or average
over its requested history. For a wide date range, scanning can be the correct
plan. The original rule rejected those plans regardless of their actual cost.
On the common 120,009-row fixture, the 53 rejected scans read at most 168,972
handler rows and took at most 188ms per raw-connection sample on OrbStack.
Both master and development contain these aggregate query shapes.

The replacement budgets are 500ms per query sample and twice the input table's
row count plus 100 handler reads (including index and temporary-table work).
All three samples must pass; retries and medians cannot discard a failure.
The sum of maximum sampled scan costs for each report/date-range must also stay
under 2 seconds. These limits allow the measured linear aggregates while
rejecting repeated full-table joins or slower queries. The time limit stays
fixed when the fixture grows. These are release test budgets, not a guarantee
for arbitrary production hardware or data sizes.

Only fixture hostname, table prefix, whitespace and ten-digit timestamps are
normalized. Every range is still captured and measured separately. The file
also pins aggregate shapes that currently use a range/index lookup, so an
optimizer's decision to scan a broad date range receives the same cost test.
Do not generate new entries just because CI fails: inspect the exact SQL,
confirm the intended aggregation, and record measurements before reviewing a
budget change. Keep the k6 latency and zero-drop thresholds unchanged.
