# QA validation and implementation plans

Validated 2026-09-27 against Free development `2f4ab919` and Pro development `fb3cad5`.

- [Full validation report](v6-qa-validation-report.md)
- [Implementation plan](001-v6-known-identity-reports.md)

| Order | Plan | Status | Dependencies |
|---|---|---|---|
| 1 | 001 — Known Visitors and Authors eligibility | Merged into development; [PR #342](https://github.com/wp-slimstat/wp-slimstat/pull/342) | None; Free fix, paired Pro consumer validation |

The two confirmed QA findings share one root cause and one focused production-file fix. Browser/bot/screen splitting is a documented correction, not a parser regression. Pro activation and widget-removal allegations do not justify runtime fixes. Small old/new numeric deltas, current migration health, OS deltas and endpoint-title behavior need the runtime evidence identified in the report.

Implementation and focused verification are recorded in the plan. No release qualification is claimed; unresolved QA evidence requirements remain in the validation report.
