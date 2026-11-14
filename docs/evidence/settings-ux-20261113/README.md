# Settings and notifications redesign: before and after

Captured on November 14, 2026 from a local wp-env site (WordPress 6.4, English, reduced motion). `before-*` is `fix/privacy-audit-20261113` at 69257827; `after-*` is `feat/settings-notifications-ux-20261113`. The same database, options and admin user were used for both, with the 6.0 release notice and the privacy review notice both pending.

| Pair | What changed |
|---|---|
| `dashboard-desktop` | The SlimStat 6 release card no longer shows on the Dashboard (it stays in the SlimStat header, once per user). The privacy review is an info task instead of a warning. |
| `report-header-desktop` | The privacy review shows as info instead of a warning; the release bar in the SlimStat header is unchanged. |
| `settings-general-desktop` | General is grouped by goal and opens with a Setup status panel. Search, a sticky save bar, compact rows. |
| `settings-tab2-desktop` | Tracker is now Tracking. Privacy rows moved to Privacy & GDPR, expert rows to Advanced. |
| `settings-license-desktop` | The new tab order and settings search; the License content in Free is unchanged. |
| `settings-general-mobile` | 390 px wide. |
| `settings-privacy-rtl` | Before: the old Tracker tab, which held the privacy rows. After: the new Privacy & GDPR tab. Both in RTL. |
| `pro-invalid-report` | Pro active with an invalid license: one line and the main fix outside License settings. |
| `pro-invalid-license` | Pro active with an invalid license: the full banner stays on License settings. |

After only: `settings-privacy-desktop` (summary and review task), `settings-search-desktop` (search for "cookie"), `settings-unsaved-desktop` (unsaved state), `settings-advanced-desktop` (Advanced tab), `settings-oldlink-desktop` (an old `tab=2#anonymize_ip` link landing on its new tab).

Full-page captures render the sticky save bar in flow, so it does not cover content. Images are reduced to 256 colors. The CookieYes and WordPress notices belong to other plugins and core. These files are excluded from release packages by `/docs` in `.distignore`.
