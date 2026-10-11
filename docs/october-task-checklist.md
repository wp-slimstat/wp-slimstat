# SlimStat — Final task backlog

Reviewed: **11 October 2026**. **28 tasks**, with stable IDs from the conversation. Implementation and validation recorded below: 27 tasks addressed within the documented test matrix; task 18 remains partial pending the original theme and affected page.

Evidence: **22 screenshots** — 19 supplied by the user and 3 existing project references. Screenshots show reported/current examples, not proposed designs. Suspected causes are identified separately from confirmed observations.

## Index

| Area | Task IDs |
| --- | --- |
| Traffic Sources: Channels, campaigns, UTM builder | 1–4, 25–26 |
| Ecommerce | 5–11, 27 |
| Heatmaps | 12–18 |
| Email Report / Shortcodes | 19 / 20 |
| Settings / Migration / Notifications | 21, 28 / 22 / 23 |
| Pro page | 24 |

Related work: **1–3** share the modal pattern; **5 + 27** share chart/metric semantics; **6 + 7 + 9 + 26** share report navigation; **11 + 12** share date controls; **14 + 16 + 21** share Settings; **17 + 18 + 24** cover Heatmaps availability, reliability, and promotion. **28** governs every task that touches upgrade defaults, stored settings, or migrations.

## Shared acceptance rules

- Reuse existing components and settings. Preserve data definitions, permissions, consent behavior, filters, exports, and explicit user preferences unless a task specifically changes them.
- **Upgrades must preserve existing users’ configurations and configuration-driven behavior.** New-install defaults must not overwrite existing choices, including values inherited from earlier defaults. Apply task 28 to core/Pro updates and migrations; the Heatmaps default-on request applies only to genuinely new installations.
- Make changed controls keyboard accessible, responsive, and usable in RTL. Localize labels, counts, and suggested English copy. Modals need a clear close action, Escape support, contained focus, and focus return without moving the underlying page.
- Investigations must distinguish observations from causes. Keep tasks unchecked until the requested change or investigation has been completed and its relevant checks recorded.
- Working values: **20 rows/page** is the current shared default; **30 clicks** and **5 previous-period orders** are the user's suggested initial thresholds. **60-second refresh** is an assistant-proposed starting point to validate, not a user requirement or a measured service guarantee.

## 1. Open the UTM builder in a modal

- [x] Replace the inline expandable builder with the existing Goals & Funnels overlay style and interaction pattern.
- [x] Open the overlay from every campaign-building entry point without jumping to the top or changing the report's scroll position.
- [x] Preserve all fields, validation, and URL generation. Show the generated link with one-click **Copy campaign URL** and visible success/failure feedback.

Screenshots: Existing UTM builder (`task-notes/screenshots/utm-builder.png`) · Funnel overlay style reference (`task-notes/screenshots/funnel-modal-reference.png`).

**Result (11 October 2026):** Verified. Native [UTM dialog](../admin/view/partials/utm-builder.php), shared [dialog focus/scroll handling](../admin/assets/js/report-dialogs.js). Fields, validation and copy behavior retained.

**Evidence:** [utm-builder.spec.ts](../tests/e2e/utm-builder.spec.ts), [acquisition-reports.spec.ts](../tests/e2e/acquisition-reports.spec.ts), [october-backlog.spec.ts](../tests/e2e/october-backlog.spec.ts): real tagged visit, Unicode, all six tags, keyboard/Escape/focus/scroll. [Executed results](../docs/evidence/october-backlog/results.json).

## 2. Make campaign creation easier to discover

- [x] In the UTM Campaigns empty state, show a prominent prompt such as **“Need a campaign link?”** and a primary **“Build your first campaign link”** button.
- [x] Keep a prominent **“Build campaign link”** button beside the report heading when data exists. Both states open the modal from task 1.
- [x] Keep copy accurate: this creates a tagged URL; report entries appear after recorded visits. No results in the selected period does not prove the user has never created a campaign.

Screenshot: Existing campaign report and creation button (`task-notes/screenshots/utm-campaigns-report.png`).

**Result (11 October 2026):** Verified. [AcquisitionReport](../src/Reports/AcquisitionReport.php) adds the empty-period primary CTA and report heading action without claiming no campaign was ever created.

**Evidence:** UTM, acquisition, and October browser specs above, both empty and populated periods. [Executed results](../docs/evidence/october-backlog/results.json).

## 3. Move Channels and Campaigns explanations into help modals

- [x] Replace long introductory text with compact help buttons: **“What are channels?”** and **“What are campaigns? How do they work?”**.
- [x] Replace the footer's inline **“How to read this report”** expanders with modal actions too. Use one help modal per report for both entry points, preserving the report definitions and attribution guidance without duplicating explanations.
- [x] Match the Goals & Funnels modal style. Use readable body text, comfortable line/paragraph spacing, and a suitable text width; opening help must not resize cards or shift nearby reports.

Screenshots: Campaign explanation (`task-notes/screenshots/utm-campaigns-report.png`) · Expanded Channels help (`task-notes/screenshots/channels-report-help-expanded.jpg`) · Overlay style reference (`task-notes/screenshots/funnel-modal-reference.png`).

**Result (11 October 2026):** Verified. One help dialog per report serves both buttons; native modal does not expand the report card.

**Evidence:** October dialog focus containment and scroll-return test; acquisition mobile/RTL/keyboard test. [Executed results](../docs/evidence/october-backlog/results.json).

## 4. Show channel / campaign counts beside pageviews

- [x] Extend the summary badge to **“2 channels - 100 pageviews”** in Channels and **“2 campaigns - 100 pageviews”** in UTM Campaigns.
- [x] Count distinct report groups across the selected date range and filters, not just visible rows or expanded source combinations. Do not label a capped count as an exact total.
- [x] Handle zero, singular/plural labels, and case-sensitive campaign identities consistently with the report.

Screenshot: Current pageviews-only badge (`task-notes/screenshots/pageviews-summary-before.jpg`).

**Result (11 October 2026):** Verified. Group count uses the merged report groups before pagination; capped counts say “At least”.

**Evidence:** Acquisition fixtures cover exact group/pageview counts, source combinations, capped breakdowns, filters and page boundaries; [AcquisitionReport](../src/Reports/AcquisitionReport.php). [Executed results](../docs/evidence/october-backlog/results.json).

## 5. Make Ecommerce chart gaps understandable

- [x] Investigate unexpected gaps in **Average order value** and **Tracked purchase rate** (renamed in task 27), for both selected and previous periods.
- [x] Plot valid zeroes at zero. Show **“No orders”**, **“No data”**, or **“Not enough visits”** for unavailable intervals; do not turn undefined ratios into measured zeroes or connect gaps as though values were observed.
- [x] Explain unavailable intervals directly on the chart, with accessible details and matching **View exact values** entries. Preserve date alignment and calculations.

Project check: [Ecommerce definitions](../docs/ecommerce.md) explicitly make AOV unavailable without orders and rate points unavailable below **100 eligible visits**. Those gaps need a better explanation, not fabricated values.

Screenshots: Purchase-rate gaps (`task-notes/screenshots/tracked-purchase-rate-gaps.jpg`) · Average-order-value gaps (`task-notes/screenshots/average-order-value-gaps.jpg`).

**Result (11 October 2026):** Verified. [Ecommerce formatting](../admin/view/partials/ecommerce.php) and [chart details](../admin/assets/js/ecommerce.js) distinguish undefined ratios from measured zeroes. Gaps remain disconnected; current/previous interval explanations match exact values.

**Evidence:** [Ecommerce fixture](../tests/e2e/helpers/ecommerce-data.php) verifies zero purchase rate with sufficient visits, null AOV without orders, distinct visits and calendar alignment. [October Ecommerce](../tests/e2e/october-ecommerce.spec.ts) checks visible gap explanations. [Executed results](../docs/evidence/october-backlog/results.json).

## 6. Keep the Acquisition full report consistent with its preview

Scope: **Ecommerce → Acquisition → Channels / Sources / Campaigns**.

- [x] Rename **“View report”** to **“Full report”** and update related instructions. Keep the same bar rows, labels, metric values, and filter actions when expanded; remove the duplicated, differently styled table while preserving access to its metrics.
- [x] Show approximately five rows within the card's scrollable area. Use the shared page-size setting (**20 by default**) and bottom previous/next arrows for additional pages. Keep the collapse action available.
- [x] Page and sort across the available matching results, not only the initial top ten. Preserve the selected tab, metric, direction, date range, currency, and filters. Respect existing result safeguards and disclose any cap; do not silently truncate a “Full report”.
- [x] Move **Export** to the card's top action area, outside the scrolling rows, matching other tables. Preserve the export's report scope and disclosed limit.
- [x] Check short lists and more than one page of results, including page boundaries and switching tabs after expansion.

Project check: [Shared defaults](../wp-slimstat.php) set `rows_to_show` to 20; [current Ecommerce behavior](../docs/ecommerce.md) is five visible rows expanding to ten. This task changes that behavior.

Screenshots: Preview and button (`task-notes/screenshots/acquisition-view-report-button.jpg`) · Duplicated full-report layout (`task-notes/screenshots/acquisition-full-report-layout-mismatch.jpg`) · Export at the bottom (`task-notes/screenshots/acquisition-export-at-bottom.jpg`).

**Result (11 October 2026):** Verified. Same acquisition bars in preview/full report, all metrics retained, 20-row default pagination, five-row-height scroll area, export above rows; 1,000-group cap disclosed.

**Evidence:** October Ecommerce creates 24 additional real WC orders, checks >20 sources, next/previous, sorting, tab persistence and CSV. [Report](../src/Ecommerce/Report.php), [template](../admin/view/partials/ecommerce.php), [JS](../admin/assets/js/ecommerce.js). [Executed results](../docs/evidence/october-backlog/results.json).

## 7. Clarify the purpose of the Pro reports discovery card

- [x] Explain that **“Your Pro reports are ready”** offers shortcuts to existing campaign, device, and coupon reports and what users can learn from them. Remove **“Start with a question”**, which suggests a question-entry feature.
- [x] Use clear report-opening labels and the new **“Full report”** terminology. Preserve dismissal behavior and ensure the actions open the promised report.

Suggested description: **“Explore your sales by campaign, device, or coupon. Choose a report below, then open Full report to see more results or export them.”**

Screenshot: Unclear Pro reports card (`task-notes/screenshots/pro-reports-discovery-license-message.jpg`).

**Result (11 October 2026):** Verified. Discovery card describes existing report shortcuts and Full report; dismissal stays in the existing path.

**Evidence:** [Template](../admin/view/partials/ecommerce.php); October Ecommerce and existing [dashboard tests](../tests/e2e/ecommerce-dashboard.spec.ts). [Executed results](../docs/evidence/october-backlog/results.json).

## 8. Fix the conflicting license activation message

- [x] Reproduce the user's active-license case: Ecommerce asks to **“Activate your license”** while the user reports **“Pro active”** above. Check the authoritative license state and the meaning of each status indicator.
- [x] Hide activation requests for a confirmed active site license. If Pro availability and update licensing are genuinely different states, describe the actual action needed without contradictory wording.
- [x] Check active, inactive/expired, and unavailable/stale status. Do not interpret a failed status lookup as a confirmed inactive license.

Evidence limit: the supplied crop shows the activation prompt, not the header or authoritative license record; the cause remains unverified.

Screenshot: Reported conflicting activation prompt (`task-notes/screenshots/pro-reports-discovery-license-message.jpg`).

**Result (11 October 2026):** Verified controlled states. Pro availability is distinguished from update licensing; confirmed active status suppresses the prompt. Unreachable checks preserve a prior confirmed status; an unknown/new key stays unknown.

**Evidence:** [Pro license tests](https://github.com/wp-slimstat/wp-slimstat-pro/blob/fix/october-task-backlog/tests/nexus-api-test.php) exercises active/inactive/unreachable/new-key paths. October Ecommerce checks active-state copy. The user's cropped screenshot cannot establish their actual license-service response. [Executed results](../docs/evidence/october-backlog/results.json).

## 9. Clarify the purchase journey note and fix its report action

- [x] Explain the incomplete recorded journey in plain language, preserving the dynamic visit count and singular/plural forms. Do not describe completed purchases as abandonment.
- [x] Make **“Explore products”** clearly open the relevant product report. Prevent the unexplained jump to the page top; if the destination is elsewhere on the page, bring that report into view and focus it. Preserve the selected reporting context.

Suggested copy: **“In 6 visits that ended in a purchase, not all steps were recorded. Visitors may have used express checkout or taken another route, or tracking may have missed a step. This does not mean they abandoned their purchase.”** Use the real count, not a fixed 6.

Screenshot: Unclear journey note and product-report action (`task-notes/screenshots/purchase-journey-unclear-note-products-link.png`).

**Result (11 October 2026):** Verified. Dynamic journey count and plain explanation; Explore products opens and focuses the product panel while retaining report context.

**Evidence:** October Ecommerce checks focus; [template](../admin/view/partials/ecommerce.php) keeps singular/plural translation. [Executed results](../docs/evidence/october-backlog/results.json).

## 10. Verify that the currency selector lists all applicable active currencies

- [x] Verify the store's currency configuration and supported multi-currency integration against selector options. Include all supported active currencies and keep currencies needed for historical orders accessible; identify missing options rather than assuming a USD-only list is faulty.
- [x] Check a single-currency store, multiple order currencies, and an active supported currency with no orders in the selected period. Show a clear empty state when the selection has no sales.
- [x] Verify consistent monetary filtering in metrics, charts, reports, and exports without mixing currencies or implying exchange-rate conversion. Traffic counts remain currency-independent; purchase-rate wording must explain its selected-currency numerator.

Project check: [Report data](../src/Ecommerce/Report.php) currently lists distinct currencies from stored order summaries, not a list of configured active currencies. Supporting a configured currency without recorded orders needs explicit verification against the store's supported integration.

Screenshot: Currency selector showing only USD (`task-notes/screenshots/ecommerce-currency-usd-only.jpg`).

**Result (11 October 2026):** Verified supported API contract. Store currency + historical currencies + enabled WooPayments currencies are combined, with no conversion.

**Evidence:** October Ecommerce checks USD/EUR monetary isolation, GBP empty state and a WooPayments `get_enabled_currencies()` contract fixture with active/no-order GBP. Real enabled-currency implementation was inspected in the installed WooPayments source. An authenticated WooPayments service was not contacted. [Executed results](../docs/evidence/october-backlog/results.json).

## 11. Align the Ecommerce date range with other report pages

- [x] Match the shared date control's appearance, placement, presets, custom range, defaults, timezone handling, and cross-page selection behavior. Repair differences using the shared control rather than introducing another picker.
- [x] Preserve the selected range when moving between reports, consistent with the rest of SlimStat, and apply it to Ecommerce metrics, charts, reports, exports, and previous-period comparisons.

Screenshot: Not supplied for this cross-page request.

**Result (11 October 2026):** Verified; Ecommerce already uses the shared picker. Shared control retained.

**Evidence:** Existing dashboard date tests cover keyboard presets, custom ranges, saved segments, compatible filters and period comparisons; [shared date control](../admin/assets/js/daterangepicker/slimstat-daterangepicker.js). [Executed results](../docs/evidence/october-backlog/results.json).

## 12. Fix Heatmaps date-range placement

- [x] Align the Heatmaps date control with the standard report toolbar. Fix the visible mismatch where the trigger is on the left and its dropdown opens at the far right.
- [x] Anchor the menu to its trigger and keep it visible within the viewport on desktop, mobile, and RTL layouts, including after scrolling.

Screenshot: Date trigger and detached menu (`task-notes/screenshots/heatmaps-table-date-range.jpg`).

**Result (11 October 2026):** Verified. Menu anchors to trigger, clamps within viewport, follows scroll/resize.

**Evidence:** October heatmap test checks desktop 1280 and mobile 390, LTR/RTL; existing [toolbar spec](../tests/e2e/heatmaps-list-toolbar.spec.ts). [Executed results](../docs/evidence/october-backlog/results.json).

## 13. Make Heatmaps table indicators easier to understand

- [x] Replace the dominant-device indicator with a three-color **Desktop / Mobile / Tablet** bar and each device's percentage. Provide readable labels or accessible hover/focus/tap details; explain the percentage denominator and handle unknown devices without misclassifying them.
- [x] Explain the dot beside **Clicks** with an accessible tooltip if it conveys useful information; otherwise remove it.
- [x] Add **“Low data”** below **30 clicks** in the selected range and filters, with a short explanation. This is a usability threshold, not statistical assurance; check 29 and 30 clicks.
- [x] Use a readable URL path when a page title is missing; use **“Homepage”** for `/` and preserve access to the original URL.

Screenshot: Heatmaps device, click, and page indicators (`task-notes/screenshots/heatmaps-table-date-range.jpg`).

**Result (11 October 2026):** Verified. Device percentages include unknowns in the all-click denominator; unexplained dot removed; <30 is Low data; `/` becomes Homepage.

**Evidence:** October heatmap fixture checks 29/30 boundary, unknown devices and empty title. [Heatmaps JS](../admin/assets/js/heatmaps.js). [Executed results](../docs/evidence/october-backlog/results.json).

## 14. Protect the Delete heatmap data action

- [x] Prefer moving **“Delete heatmap data”** into **Settings → Danger zone**. If it remains on Heatmaps, require a separate confirmation where the user types **DELETE** before deletion is enabled.
- [x] Explain the exact deletion scope and recovery limits; preserve confirmation, cancellation, permissions, and protection against unintended requests. Test with disposable staging data.

Coordinate with task 21; this heatmap-specific action is not automatically equivalent to **Delete Records**.

Related screenshot: Heatmaps page context (`task-notes/screenshots/heatmaps-table-date-range.jpg`). The delete action is not visible in this image.

**Result (11 October 2026):** Verified. Typed DELETE remains on Heatmaps with independent server validation after nonce/capability checks.

**Evidence:** [Destructive staging test](../tests/e2e/october-heatmap-delete.spec.ts) backs up/restores both full-capture tables, checks cancellation, wrong text, invalid nonce, successful deletion and preserved stats/events. Existing REST tests cover permissions. [Executed results](../docs/evidence/october-backlog/results.json).

## 15. Keep Heatmaps data fresh automatically

- [x] Investigate **“Updated 80 min ago”** across cache freshness and page refresh behavior. Automatically refresh while the page is visible; **60 seconds** is a proposed starting point to validate against query cost and data arrival.
- [x] Refresh the actual data, not just the label. Keep manual **Refresh**, filters, sorting, pagination, and scroll position. Show the true data-computation time and a clear retry state on failure.
- [x] Avoid overlapping requests, pause polling in hidden tabs, and refresh stale results on return. Test newly recorded legacy clicks and full Heatmaps events, plus idle and failed-request states.

Project check: [Heatmap cache](../src/Heatmap/Query.php) can last 12 hours and notes that legacy clicks do not invalidate it; [the list UI](../admin/assets/js/heatmaps.js) fetches on load/filter/manual refresh without periodic polling. These are investigation leads, not a reproduced root cause for the screenshot.

Screenshot: Stale update time (`task-notes/screenshots/heatmaps-stale-update-time.jpg`).

**Result (11 October 2026):** Verified. Cache freshness bounded to one minute; visible-tab polling forces real reads, serializes requests, preserves controls, pauses while hidden and retries failures.

**Evidence:** October polling clock test; [PagesTest](../tests/Unit/Heatmap/PagesTest.php); [real database freshness probe](../tests/fixtures/october-heatmap-freshness.php) proves legacy 0→1 after refresh and full capture 1→2 after invalidation. Tiny fixture query timing is evidence of this run, not a production latency guarantee. [Executed results](../docs/evidence/october-backlog/results.json).

## 16. Add a direct Heatmap settings shortcut

- [x] Add a visible **“Heatmap settings”** action for users allowed to manage settings.
- [x] Open the correct Settings tab and scroll/focus directly to the Heatmaps section, rather than landing at the top of general settings.

Screenshot: Existing Heatmaps toolbar (`task-notes/screenshots/heatmaps-table-date-range.jpg`).

**Result (11 October 2026):** Verified. For Pro administrators, Settings shortcut targets tab 7, `#heatmap_capture`, with focus.

**Evidence:** October heatmap browser test; [template](../admin/view/heatmaps.php), [settings focus handler](../admin/assets/js/admin.js). [Executed results](../docs/evidence/october-backlog/results.json).

## 17. Enable Heatmaps by default only on new Pro installations

- [x] Verify full click-and-scroll capture starts enabled on genuinely new installations using Pro; changing only the viewer/overlay toggle does not satisfy this task. Keep the page and Settings consistent and let users turn capture off.
- [x] On upgrades or Pro activation for an existing site, preserve the site's prior capture state, sampling, exclusions, consent behavior, and saved preferences. Keep tracking off if it was off, including through an older default; do not retroactively apply the new-install default.
- [x] Check fresh installs separately from core upgrades, Pro upgrades, and Pro activation on existing sites. For absent or legacy settings, preserve the prior effective behavior rather than assuming that no saved preference permits enabling tracking. Follow task 28.

Project check: [Current defaults](../wp-slimstat.php) already set fresh installations to full capture while upgrade defaults remain off. Preserve that distinction and verify the affected path. The user's final upgrade-preservation instruction supersedes the earlier proposal to enable old default-off installations.

Related screenshot: Tracking status context (`task-notes/screenshots/heatmaps-table-date-range.jpg`). The default-off claim is user-reported; this image itself shows tracking enabled.

**Result (11 October 2026):** Verified. Fresh Pro defaults full; fresh core-only defaults off. Existing-site Pro activation and missing-options recovery preserve off.

**Evidence:** [Upgrade browser fixture](../tests/e2e/october-upgrade.spec.ts) runs actual init on isolated fresh option/table prefixes, Pro reactivation and missing options with existing analytics; unit recovery coverage. [Executed results](../docs/evidence/october-backlog/results.json).

## 18. Diagnose the missing Heatmap preview and provide recovery guidance

- [ ] Reproduce the affected `/pricing/` page on staging. Check normal page loading first: the fallback screenshot reports **“The theme directory "slimstats" does not exist.”** Verify installed versus configured theme and any imported settings. A relationship to manually seeded data is still only a hypothesis.
- [ ] After correcting any confirmed site/theme issue on staging, retest the embedded preview and **“Open heatmap on the page”** with real recorded interactions and seeded data. If preview failure remains, investigate embedding restrictions, redirects/authentication, scripts, and load timeouts.
- [ ] Verify click/scroll overlays and positioning at desktop, tablet, and mobile sizes, using the affected theme and a standard theme. Preserve page, device, date range, and compatible filters through the fallback.
- [x] Give a cause-specific explanation where detectable, a retry action, and concise recovery guidance. Otherwise state that the cause is unknown and provide concrete checks; include what to do if the fallback also fails. Keep security protections intact.

The user planned to repair the theme and report back; incorporate that result when available without blocking independent staging checks.

Screenshots: Embedded preview unavailable (`task-notes/screenshots/heatmap-page-preview-unavailable.jpg`) · Fallback missing-theme error (`task-notes/screenshots/heatmap-open-on-page-missing-theme.jpg`).

**Result (11 October 2026):** **Partial / external blocker.** Missing theme reproduced on disposable WordPress; cause-specific guidance, Retry and a working direct overlay fallback implemented. Fallback carries dates/device/filters through the existing permission-checked REST route.

**Evidence:** [Preview test](../tests/e2e/october-preview.spec.ts) restores Twenty Twenty-Five, checks direct overlay and desktop/tablet/mobile click/scroll previews. Overlay responses use controlled data. Original `slimstats` theme, original `/pricing/`, and real interactions on that theme remain unavailable and are not marked complete. [Executed results](../docs/evidence/october-backlog/results.json).

## 19. Organize and search the Email Report selection list

- [x] Group available reports by the existing report categories and alphabetize names within each category using the interface language.
- [x] Add name search, hide empty categories, and show a clear no-results state. Preserve every checked report during search, clearing, sorting, and saving, including hidden selections.
- [x] Under **“Reports”**, add **“Select the reports you want included in your scheduled emails.”** Retain the recipient/Ecommerce privacy guidance and existing scheduling behavior.

Screenshot: Long unorganized report checklist (`task-notes/screenshots/email-report-unorganized-selection.jpg`).

**Result (11 October 2026):** Verified. Pro email reports grouped by catalog category (uncatalogued supported reports retained), locale-collated by name, searchable without disabling hidden inputs.

**Evidence:** October browser test checks sorting, no results and save/reload of a hidden checked selection; [Pro email regression results](https://github.com/wp-slimstat/wp-slimstat-pro/blob/fix/october-task-backlog/docs/evidence/october-backlog/regressions.txt). [Executed results](../docs/evidence/october-backlog/results.json).

## 20. Fix missing charts in shortcode previews

- [x] Reproduce the blank **Pageviews** preview and check the other supported chart reports. Fix the rendering cause while preserving working table previews.
- [x] Verify initial rendering and updates after report, period, filter, or chart-interval changes and **Preview** clicks, without a full page reload.
- [x] Match the generated shortcode's data and confirm it renders on the site. Show an accurate empty/error state when appropriate rather than an unexplained blank chart.

Screenshot: Blank shortcode chart preview (`task-notes/screenshots/shortcode-chart-preview-blank.jpg`).

**Result (11 October 2026):** Verified. Chart assets explicitly loaded on Shortcodes; inserted charts initialized after preview response; previous instances destroyed before removal.

**Evidence:** October browser test checks all six supported chart widgets, period/filter changes, weekly interval and the generated shortcode's matching frontend data; [existing table/permission tests](../tests/e2e/shortcodes-page.spec.ts). [Executed results](../docs/evidence/october-backlog/results.json).

## 21. Explain the origin and test plan for Danger zone options

Scope: **Delete Records / Factory Reset / Delete data on uninstall**. This is an investigation deliverable, not a request to execute these actions on the user's site.

- [x] Trace each option through Git history, requirements/issues, and available notes. State whether it is new, renamed, or moved; cite the evidence and user need. If no requirement is found, mark the rationale unverified rather than attributing it to AI without evidence.
- [x] Record a concise explanation for each option in this document: intended use, exact effect on analytics/archives/settings/related plugin data, defaults, and recovery limits. Flag discrepancies between UI promises and actual behavior.
- [x] Provide a staging checklist for each action: permissions, confirmation/cancel, deleted versus preserved data, and expected results. Cover both uninstall toggle states and reinstallation, with disposable data and a restorable backup for destructive checks.

Screenshot: Danger zone options needing explanation (`task-notes/screenshots/settings-danger-zone-options.jpg`).

**Result (11 October 2026):** Investigation complete. See exact scope, history and staging checklist below. UI promises corrected; no Danger zone actions executed against the user's site.

**Evidence:** [Settings handlers](../admin/config/index.php), [schema/FKs](../src/Schema/Schema.php), [uninstall](../uninstall.php), [uninstall safety tests](../tests/uninstall-data-safety-test.php). [Executed results](../docs/evidence/october-backlog/results.json).


| Action | Origin / user need | Actual scope and recovery |
| --- | --- | --- |
| Delete Records | Present in the checked repository history by `053bf5d5` (2019-09-18); not introduced by this backlog. Grouped into Danger zone in `422e50fd` (#345). Clearing active analytics is the evident function; the original request/author intent was not found and is unverified. | Deletes active `slim_events` then `slim_stats`, preserving IDs' auto-increment sequence. Declared FK cascades remove linked full heatmap rows; Ecommerce `stat_id` becomes NULL while order summaries remain. Archives, heatmap element dictionary, other dimension tables, settings, goals/funnels and WooCommerce orders remain. Older/custom schemas lacking those FK relationships need separate orphan checks. Recovery requires a backup; archives contain older retained records, not necessarily deleted active rows. |
| Factory Reset | Same historical action and later visual grouping; original requirement unverified. Intended use is an explicit configuration reset. | Replaces the main `slimstat_options` array with fresh defaults (including full capture when Pro is active). Removes Pro/custom values stored in that array; does not directly delete analytics, WooCommerce orders, separate goals/funnels/filter options, user layout metadata or records. Restore configuration backup to undo. Runtime scheduler reconciliation may occur afterward. Never invoked automatically by these upgrades. |
| Delete data on uninstall | Explicit opt-in safety fix `b4d9bbd5`, with older uninstall path history including `4abf134e`/`ea9c215d`; credential cleanup hardened in `69e4de0d`. Prevent accidental data loss when removing/reinstalling the plugin. | Absent/off is retain: analytics/settings remain, but scheduled jobs, transients and owned cached artifacts are removed; local/network custom DB credentials are cleared. Reinstallation may require credentials/setup again. On is destructive: manifest-owned tables including archives and addon projections plus owned options/user metadata are removed in the applicable site/network scope. WooCommerce order source records are not SlimStat tables. External DB cleanup can fail if unreachable; inspect the outcome. Only backups can restore deleted data. |

UI discrepancies corrected: “all information” did not describe Delete Records accurately; copying archives cannot generally undo it; archives are not always deleted on uninstall; reinstall does not restore cleared DB credentials automatically.

Staging checklist (a requested plan, not a claim these destructive operations were run on the user's site):

1. Create a disposable clone and verify a restorable DB/files backup. Seed current stats/events, archives, full heatmap records/dictionary, Ecommerce summaries and original WC orders, goals/funnels/saved filters, custom settings, user layouts, cron and a dummy custom DB connection. Record counts and redacted setting hashes.
2. For each action, verify unauthorized users and invalid/missing nonces cannot mutate anything. Cancel browser confirmation and verify all snapshots unchanged.
3. Delete Records: accept once; compare the exact scope above including FK cascades/SET NULL and archives; simulate a DB failure and verify partial failure is not described as success; restore backup.
4. Factory Reset: accept once; compare the main options array to `get_fresh_defaults`, verify source records and separately stored configuration remain; check capture and email scheduling behavior; restore backup.
5. Uninstall OFF: remove, inspect retained tables/settings and removed jobs/artifacts/credentials, reinstall both plugins, restore credentials as required, verify retained reports. Repeat ON: verify owned tables/options removed but unrelated WP/WC data retained, reinstall and confirm a genuinely fresh state. Repeat network and custom DB scopes if used; explicitly exercise an unreachable external DB.


## 22. Simplify migration into one clear required-update flow

- [x] Present required updates as one guided **“Start Migration”** action in the brand's primary style, with a plain-language reason and **back up your database first** reminder. Keep technical steps and diagnostics secondary.
- [x] While a required migration is pending, show a persistent administrator banner across SlimStat pages with that direct action and backup reminder. State the verified consequence of delay; do not claim statistics are wrong merely because an optional step remains.
- [x] Show progress and actionable failure/retry guidance. When all required steps succeed, show a green **“✓ Database is up to date”** status and remove the pending banner. Optional steps must not block success.
- [x] Collapse optional work under **“Advanced (optional)”**. Explain its actual benefit and any tracking interruption. **“This currently has no effect on speed or statistics. You do not need to run it.”** is suitable only where verified; the character-set conversion must still explain its emoji support and tracking pause.
- [x] Estimate time from this site's affected row counts plus available timing evidence, clearly marked approximate. If evidence is insufficient, say an estimate is unavailable instead of inventing one.
- [x] Test required-pending, running, failed, completed, and optional-only states. Snapshot configuration before/after migration and verify unchanged effective settings under task 28. The screenshot says **no migration is required** and should therefore look complete, not urgent.

Screenshot: Technical optional-only migration screen (`task-notes/screenshots/migration-status-technical-optional-steps.jpg`).

**Result (11 October 2026):** Verified. Persistent required banner, Start Migration, backup reminder, honest unavailable time estimate, progress/error/retry, green required-complete state, optional work separately collapsed.

**Evidence:** [Real migration browser test](../tests/e2e/october-migration.spec.ts) drops/recreates one disposable index, simulates a 503, retries successfully, verifies settings hash unchanged and optional-only completion. Migration unit/standalone regression tests. [Executed results](../docs/evidence/october-backlog/results.json).

## 23. Fix notification UI leakage across SlimStat pages

- [x] Reproduce the notification controls and both Inbox/Dismissed empty states appearing inline above Migration. Find the previous fix and determine why this page was missed or regressed; the screenshot establishes a UI defect, not a server error.
- [x] Fix the shared cause, such as component integration or asset loading, across all affected pages. The notification panel must stay hidden until opened, render correctly, and show only the active tab's content.
- [x] Check every SlimStat page using the shared header with empty and populated notifications, including opening/closing, switching tabs, and dismissing. Add focused regression coverage for the identified cause.

Screenshot: Notification content leaking into Migration (`task-notes/screenshots/migration-notifications-layout-broken.jpg`).

**Result (11 October 2026):** Verified. Shared [notification enqueuer](../admin/index.php) also used by [MigrationAdmin](../src/Migration/Admin/MigrationAdmin.php).

**Evidence:** October browser test checks 13 screens, empty/populated inboxes, hidden initial panel, opening/closing and exclusive tabs; dismissal persistence on Migration. Prior fix `b223fdff` loaded header CSS but omitted notification component assets. [Executed results](../docs/evidence/october-backlog/results.json).

## 24. Update Heatmaps promotion on the Pro page

- [x] Use this concise description: **“See click and scroll maps for each page on mobile, tablet, and desktop. Find dead clicks and rage clicks, and view campaign visitors separately.”**
- [x] Include supporting details for all requested features: dead/rage clicks, three device-size previews, visitors who reached a goal or came from a campaign, CSV downloads, and the **“Heatmap insights”** email report. Keep claims accurate for the available capture mode and access level.
- [x] Add **“Open Heatmaps”** below the description, matching **“Open Ecommerce”**, with the existing permission/Pro access behavior.

Screenshot: Not supplied for the Pro-page copy request.

**Result (11 October 2026):** Verified. Requested Heatmaps description, accurate full-capture features and Open Heatmaps action.

**Evidence:** [Pro page](../admin/view/upgrade-pro.php) source review against Pro viewer/filter/export/digest features; page exercised by header browser census. [Executed results](../docs/evidence/october-backlog/results.json).

## 25. Move Channels and UTM Campaigns to the top of Traffic Sources

- [x] Place **Channels** and **UTM Campaigns** together as the first report section, below the page header/shared filters and before other traffic reports.
- [x] Preserve functionality and put them first in mobile reading order too.

Screenshot: Not supplied for the section-order request.

**Result (11 October 2026):** Verified. Channels and Campaigns rendered first on Traffic Sources, without rewriting saved Customizer metadata.

**Evidence:** October DOM order check; acquisition Customizer relocation and [layout tests](../tests/e2e/traffic-sources-layout.spec.ts). [Executed results](../docs/evidence/october-backlog/results.json).

## 26. Link traffic acquisition reports to Ecommerce sales

- [x] For users with access to Pro and WooCommerce reports, add a discoverable **“View sales in Ecommerce”** action from Channels/UTM Campaigns to the corresponding sales report. Row actions should carry the selected campaign/channel.
- [x] Open the correct Ecommerce acquisition tab and preserve the date range and compatible filters. Map real identities, not display labels alone; if a selection cannot be mapped, explain it rather than silently showing unrelated sales.
- [x] Show a clear no-sales state when appropriate and preserve Ecommerce's attribution definitions. Check destination access and both channel/campaign navigation paths.

Screenshot: Not supplied for this missing-navigation request.

**Result (11 October 2026):** Verified. Pro/WooCommerce-authorized sales actions carry normalized dates/compatible filters and raw group identity; open correct acquisition tab.

**Evidence:** October Ecommerce navigates Channels/Campaigns → Ecommerce and checks the shared picker date; acquisition permissions/filter tests; existing Ecommerce no-sales and unsupported-filter states. [Executed results](../docs/evidence/october-backlog/results.json).

## 27. Clarify Ecommerce comparisons and purchase-rate labeling

- [x] For **Net sales**, **Orders**, and **Average order value**, replace percentage changes with **“Not enough data in the previous period”** when the previous period has fewer than **5 included orders** in the same reporting scope. Keep current values visible.
- [x] Preserve missing-history/import warnings and zero/nonpositive-baseline handling. The 5-order rule must not make an otherwise invalid comparison look valid, and it does not replace the purchase rate's visit-based sample requirement.
- [x] Rename **“Tracked purchase rate”** to **“Purchase rate (tracked visits)”** — **“نرخ خرید (بازدیدهای ردیابی‌شده)”** in Persian — consistently across the card, chart, and help.
- [x] Directly below the rate, show **“Visits with a purchase ÷ eligible tracked visits.”** Keep numerator/denominator counts available and explain eligibility and selected-currency scope in help. Retain the existing 100-eligible-visit minimum.
- [x] Verify previous periods with 0, 4, and 5 orders, unavailable comparisons, and sufficient/insufficient tracked visits; coordinate the chart states with task 5.

Related screenshot: Metric cards and existing purchase-rate label (`task-notes/screenshots/tracked-purchase-rate-gaps.jpg`).

**Result (11 October 2026):** Verified. Previous-period order threshold applies to sales/orders/AOV after completeness checks; purchase rate keeps its 100-visit rule. Label and Persian translation updated, denominator visible.

**Evidence:** October Ecommerce checks previous 0/4/5 orders and currency/traffic scope; calendar fixture verifies valid zero rate vs unavailable AOV; [fa_IR](../languages/wp-slimstat-fa_IR.po). [Executed results](../docs/evidence/october-backlog/results.json).

## 28. Preserve all existing settings and behavior during upgrades

Scope: **All core and Pro settings**, including activation, upgrade routines, migrations, and runtime default merging. This is a mandatory compatibility requirement across the backlog.

- [x] Audit every settings group and every path that reads, initializes, merges, renames, migrates, or saves configuration. An upgrade must retain each existing user's values and configuration-driven behavior, including values inherited from old defaults, explicit off/false/zero/empty values, and customized settings.
- [x] Keep new-install defaults separate from upgrades and Pro activation on existing sites. A missing legacy setting is not permission to turn on tracking or change established behavior. New settings must preserve existing behavior until the user chooses otherwise.
- [x] Where a setting must be renamed or its format migrated, map the old choice to an equivalent value without losing it. Do not reset unrelated configuration. Factory Reset remains an explicit user action and must never run as part of an upgrade.
- [x] Check tracking/consent, Heatmaps, retention/deletion, reporting/date preferences, Ecommerce, email schedules/recipients, access controls, license configuration, and other installed core/Pro options. Record any internal version/schema metadata changes separately from user configuration; do not expose secrets in the comparison report.
- [x] On staging, compare stored settings and effective behavior before/after supported core and Pro upgrade paths using legacy-default, customized, explicitly-disabled, and missing/renamed-setting cases. Include Pro activation on an existing site, rerunning an upgrade, and a failed/interrupted migration followed by retry. Test multisite settings if supported.
- [x] Record a concise audit result in this document: settings covered, upgrade paths tested, unintended differences found and fixed, and remaining limitations. Acceptance: no unexplained change to existing configuration or behavior; new-install defaults apply only to fresh installations.

Screenshot: Not supplied (cross-project upgrade compatibility requirement).

**Result (11 October 2026):** Verified within the upgrade matrix below. Removed preference resets; raw stored choices beat merged defaults; equivalent legacy rename preserved.

**Evidence:** [118 preservation assertions](../tests/v5460-settings-migration-test.php), actual single-site init/admin cycles, fresh/recovery/Pro activation, migration retry snapshot, [real multisite probe](../tests/fixtures/october-network-settings.php). [Executed results](../docs/evidence/october-backlog/results.json).


Audited core `init`, activation, default merge, `update_tables_and_options`, schema migrations, Settings save/reset and uninstall; Pro license initialization/save, Heatmaps start/settings-save, email report save/scheduling, Network View overrides, custom database and MaxMind runtime overrides. All ordinary core default keys are included in the stored snapshot. Additional cases cover consent/cookies/IP handling, tracking transport, heatmap capture/rate/exclusions, retention/archive/uninstall flags, reporting rows/date settings, access settings, email recipients/frequency/selection/attachments/author delivery/filter/text, license status and unknown nested extension values. Separate goals, funnels, saved filters and Ecommerce state remain owned by their existing explicit operations.

Changes found and fixed:

- Pre-5.4.7 code reset cookies, JavaScript tracking, anonymization and hashing. Removed those resets, including inherited old defaults.
- Legacy consent mapping could overwrite modern explicit choices because merged defaults hid which keys were actually saved. Pass the raw stored array and skip the mapping when modern keys exist.
- Old menu upgrades unconditionally enabled a setting. Removed the forced preference reset.
- Old index upgrade forced indexing on and removed unrelated obsolete options. Preserve the saved indexing choice and those option values; keep the equivalent `track_users` → `ignore_wp_users` mapping, with modern choices taking precedence.
- Missing options on an existing physical database incorrectly retained fresh defaults. Restore conservative upgrade defaults, while respecting explicitly enforced network settings.
- Core-only fresh installation no longer enables full capture later merely because Pro is subsequently activated.
- Unreachable license verification no longer writes a confirmed inactive state.

Matrix: simulated stored versions 5.4.0, 5.4.5, 5.5.0 and 6.0.0 through actual WordPress init/admin upgrade, each repeated; raw migration flags 0/5.4.0/5.4.6/5.4.7/5.5.0/6.0.0 with off/no/false/zero/empty/on; old/new renamed key precedence; fresh core and fresh Pro on isolated prefixes; missing settings over existing analytics; existing-site Pro activation; required migration transport failure and real retry; disposable multisite network admin and child-site effective settings, repeat and Pro network reactivation. No unexplained user-setting differences in these cases. Version/migration markers and `browscap_last_modified` are internal metadata, excluded explicitly. No secret values are printed in evidence.

Limitations: stored-version fixtures exercise the current upgrade paths, not installation of every historic plugin binary. Network fixture is a two-site WordPress network, not the repository's 101-site performance rehearsal. External custom DB outage and every third-party consent adapter were not re-qualified in this backlog; existing unit coverage still passes. Obsolete settings whose features no longer exist are retained as values; that does not resurrect removed features.

## Review and handoff status

- Each task now has implementation references and executable evidence. Original screenshot links remain intact.
- Core and Pro use `fix/october-task-backlog`, targeting `development`; paired PR links will be recorded here.
- Validation used disposable WordPress/database fixtures; the user's Local settings and data were not modified.
- Task 18 is deliberately not marked complete: the original `slimstats` theme and `/pricing/` page are unavailable. Missing-theme guidance and a working fallback are implemented; standard-theme previews pass.
- Test scope and limitations, settings audit and Danger zone history are recorded in [the evidence report](../docs/october-backlog.md).
