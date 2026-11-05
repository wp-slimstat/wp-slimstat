# Form Analytics: architecture decision, 2026-11-04

Status: implemented release candidate; remote CI and review remain release gates. Worktrees: ~/slimstat-lanes/form-analytics-20261104/{wp-slimstat,wp-slimstat-pro}; both feat/form-analytics-20261104 from fetched origin/development (b8bb7b36 / c9458d0).

## Decision
Use a hybrid with **public render hooks, one shared browser observer, and server-side outcome adapters**. Universal behavior does not mean inferring a successful submission from arbitrary HTML forms. Builders identify eligible forms through a SlimStat-owned marker. Public post-processing hooks confirm outcomes. No network interception, private plugin database readers, field-value inspection, synthetic thank-you detection, or class-name selector catalogue.

| Approach | Coverage | Frontend / server cost | Complexity | Maintenance / update resilience |
|---|---|---|---|---|
| Generic submit listener | Almost every HTML form, attempts only | Very low | Low | Stable browser API but fundamentally incorrect success metric |
| DOM/CSS + response/message recognition | Broad initial coverage | Observers and plugin-specific parsing | Medium/high | Fragile on markup, translations, themes and AJAX changes |
| Browser success events alone | Documented AJAX paths | Low, no server hooks | Low/medium | Misses redirects/non-AJAX; several builders intentionally show success to spam |
| Server hooks alone | Confirmed outcomes, no seen/start denominator | Zero behavior script | Low | Reliable when hook semantics are checked; cannot measure abandonment |
| Full bespoke adapter per builder | Potentially broad | Duplicated script and event paths | High | Unnecessary maintenance and divergent metric definitions |
| **Public-hook hybrid (selected)** | Behavior + confirmed outcomes on verified paths | One conditional module; bounded shared batches; no new queries/requests without eligible forms | Moderate shared core, small adapters | Changes isolated to identity/outcome mapping; conservative failure mode |

Do not add a generic event bus, adapter dependency injection container, polling daemon, remote compatibility service, or separate tracking endpoint. Reuse WordPress actions/filters, SlimStat transport, consent, database handle, schema manifest, report authorization, retention and existing Pro providers.

## Adapter contract
A small register() interface and capability metadata. Registry checks plugin bootstrap constants/classes after plugins_loaded; it does not query installed plugins or probe plugin tables on frontend requests. Each adapter only (1) marks a rendered form through a public extension point and (2) maps a public outcome hook into submitted / invalid / spam / failed / unknown. Unknown never becomes submitted. All adapters ship in Free. Methods accept mixed third-party inputs and validate their shape before access. Analytics failures cannot change the builder response, preventDefault, redirect, send mail, or stop processing.

Public render filters/actions insert an inert hidden marker owned by SlimStat; no values of visitor fields are inspected. A shared module binds native visibility, focus/input and invalid events to its own marker's form. Only plugin-documented render notifications may trigger rediscovery; a bounded observer may be used inside a known dynamic form container. No document-wide permanent observer or polling on formless pages. Arbitrary native forms are not automatically instrumented; adding one requires a reviewed adapter with a server confirmation callback; authentication and payment fields are excluded from field analytics.

Public interfaces are preferred, not automatically trusted: source inspection found documentation omissions. Version number alone is not proof; exact hook semantics and behavioral tests establish support. A missing hook cannot be detected by has_action(), since SlimStat's own callback would make it appear present. Display tested-version coverage and last observed confirmation without inventing health conclusions from zero submissions.

## Attribution and accuracy
Reuse a verified existing SlimStat pageview. Bind the opaque page context to the form using plugin-supported metadata transport; validate signature, form identity, retained page, source page, current consent and exclusions again on the server. No matching by email, IP, submitted fields, or latest entry. A signed page identifier is a bearer capability, not proof that a form succeeded; only the server adapter grants success. Cached HTML contains only form identity, never a visitor-specific token. Ambiguous/missing context loses attribution instead of guessing. Asynchronous background hooks without original visitor consent/context do not create attributed conversions.

Use one form-session state, not one row per interaction. Collapse retries and repeated notifications atomically. Separate observed visibility, starts, validation, spam, confirmation and completion time. Do not invent seen/start events from a server success. Define rates from a consistent observed cohort and show unobserved/unattributed outcomes separately if captured. Multi-step navigation and draft saves are not confirmation. Keep browser failure observations distinct from server spam/confirmation. Historical Goals data stays unchanged; verified outcomes produce a distinct `[type:form_complete][form:builder:id]` event once. The legacy submit-attempt vocabulary remains separate; historical data is not rewritten. Existing event notes on marked forms no longer copy field values, text, titles or keystrokes.

A single session may submit repeatedly. Report completed form sessions for funnel percentages, with separate verified submissions only where reliable attempt/entry identifiers are available. Do not silently call sessions entries or leads. A successful submission is neither a qualified lead nor proof of delivered email, completed payment or revenue.

## Storage, privacy and reporting
On-demand versioned tables declared in Schema: bounded mutable form-session summaries plus daily aggregates. The Free setup does not ALTER existing visit/events tables, backfill, or scan history. Pro journey reports reuse the existing Ecommerce visit index. If absent, an owner can request a background online-only index build; reports never build it, and unsupported hosts do not fall back to a table copy. Setup runs in an authorized admin context, verifies schema and supports retry. Existing history is never rewritten. Reuse external analytics DB handle and per-site prefix.

Daily form/device/channel aggregates supply Free overview, trends, device and top-five channel comparison. Retained form-session links support Pro drilldowns and strict temporal same-visit attribution to the existing WooCommerce projection, without counting an order multiple times. Never mix currencies. Pro field storage is bounded numeric positions and counters only; no labels, names, values, lengths, keystrokes or payment metadata. Field positions refer to the first visible control layout on the first recorded page per visit; changed controls or steps are not silently combined. Suggestions cannot claim causation. No premium capture/calculation solely for teasers.

Consent is required on both browser and server when configured. Anonymous aggregate counting is not a consent exemption. Follow opt-out, DNT, tracking exclusions and retention. Export/erase form-session metadata through WordPress privacy APIs using the existing visitor association, never collected form email. Retention/deletion must remove/reconcile linked summaries and cached reports. Aggregates must not retain high-cardinality personal dimensions after their source is erased. Keep denied, pending and revoked consent cases in contract tests.

## Benchmarks and release gates
Proposed budgets, not measurements: no-form delta 0 queries, 0 tracking requests, 0 form-module requests; conditional script <=6 KB gzip; <=20 eligible forms/page and <=64 structural field slots/form; <=8 KB batch; one visibility observer and delegated behavior listeners; no request per keystroke; repeated batch writes idempotent; no writes on denied consent; period-indexed aggregate queries with bounded rows; no million-visit joins for Free teasers. Measure query counters, EXPLAIN, handler reads, database bytes, dashboard median/p95, memory and throughput against the same fixtures and baseline. A budget is not a claimed result.

Compatibility test plan (not a blanket passing claim): pinned real packages and a scheduled latest-stable lane; render identity, AJAX/non-AJAX, redirect, validation, spam-with-success-response, draft, multi-step, duplicate callback, missing API, malformed arguments, activation/deactivation, cookies disabled, consent revoke, blocked tracking, cached HTML, concurrent submissions, Pro absent/unlicensed, privacy purge, schema interruption and multisite isolation. Browser simulations supplement but never replace real plugin tests. Licensed Elementor Pro and Gravity Forms require authorized packages in private CI; public-hook documentation alone is not runtime qualification.

## UI shape
Production WordPress admin surface, matching SlimStat Overview, Goals & Funnels and Ecommerce. Owner checks lead performance at a desk in daylight within the existing light admin. Restrained inherited tokens, system typography, native filters and tables. Primary action: choose a form, understand where observed visitors stop, choose one evidence-backed improvement. Overview form table; detail funnel, data-quality note, one prioritized opportunity, device/source tables, trend, then contextual Pro sections. Low-data, disabled-consent, no-forms, not-yet-seen, unverified integration, database-unavailable and stale-report states are explicit. Mobile rows retain labels, controls work by keyboard, focus is visible, logical CSS supports RTL, all copy is translatable. Existing native visual direction is fixed by the requested brief; new brand imagery is unnecessary.

Free previews reuse available Free facts (e.g. observed starts without completion) and the existing labelled illustrative blurred layout. They never put protected numbers in HTML, JSON, CSS, tooltips or accessible text. Insights require adequate denominators and confidence; suggested interventions are hypotheses.

## Proposal corrections
WPForms 2.0 already has self-hosted field timing/error/abandonment analytics; Lite includes views. Forminator can deliberately return success for spam. Gravity's after_submission includes spam. Formidable's create-entry hook also includes drafts/spam. Fluent's submission_inserted may run for stored spam, depending on spam settings. Server hook names alone are not success contracts. Pro's differentiation is cross-builder context, useful Free coverage, acquisition and carefully bounded purchase attribution, not a claim that competitors lack field metrics.

## Native report integration
The pinned `slim_forms_01` report uses the existing date picker, filters, saved segments, refresh, AI and Pro export routes. `addon_form` carries form identity through every route. Free reads day-indexed aggregates for device and traffic-channel equality filters; unsupported combinations display an explanation rather than silently ignoring the selection. Dates cover up to 366 whole days. Pro advanced filters must use retained cohort data, enforce the same permissions and disclose their retention boundary. Reports are restricted to site administrators with SlimStat reporting access; they do not bypass author-restricted report permissions.

Charts use the bundled Chart.js dependency, white SlimStat report panels, explicit gaps and accessible numeric tables. Native refresh destroys old chart instances before rebuilding them. The menu uses the existing per-site 15-day New badge, with uninstall cleanup.

## Additional verified interfaces
- Formidable publishes `frmFormErrors`: https://formidableforms.com/knowledgebase/javascript-after-form-submit/
- Snow Monkey publishes `smf.invalid`: https://github.com/inc2734/snow-monkey-forms/blob/master/changelog.txt
- Fluent's submission hooks: https://developers.fluentforms.com/hooks/actions/
- WPForms confirmation is `wpforms_process_complete`, not the earlier processing action: https://wpforms.com/developers/wpforms_process_complete/

Validation listeners read only event identity, never the messages or answers. Real tests exposed a legacy tracker issue with a form's shadowed action property; URL derivation now accepts only strings.

## Qualified coverage and edition boundaries

| Integration | Public identity / outcome contract | Runtime evidence | Edition |
|---|---|---|---|
| Contact Form 7 | `wpcf7_form_elements`, `wpcf7_submit` result status | 6.2.4.1: AJAX, invalid input, spam, mail failure, success | Free |
| WPForms | `wpforms_frontend_output`, `wpforms_process_complete`; separate spam hook | Lite 2.0.3.1: AJAX invalid/success | Free |
| Fluent Forms | `fluentform/form_element_start`, `before_insert_submission`, `submission_inserted` | 6.2.17: AJAX invalid/success, transport metadata | Free |
| Forminator | public render hooks, entry status plus both final submit hooks | 1.58.4: AJAX invalid/success; unit contracts reject deceptive spam success | Free |
| Ninja Forms | `ninja_forms_display_before_fields`, `ninja_forms_after_submission`; documented extra data channel | 3.16.0: AJAX invalid/success | Free |
| Formidable | `frm_entry_form`, `frm_after_create_entry`, public entry status API | 7.0: invalid/success; draft/spam classification contracts | Free |
| Snow Monkey Forms | WordPress `render_block`, `snow_monkey_forms/complete/message` | 13.0.1: validation, confirmation step, final success | Free |
| MW WP Form | `mwform_start_main_process`, form-end filter, dynamic after-send hook | 5.1.8: validation, confirmation step, final success | Free |
| Gravity Forms | `gform_form_tag`, `gform_after_submission`, explicit active/spam entry status | Adapter implemented against documented contracts; licensed runtime **not qualified** | Free |
| Elementor Pro | Public `elementor_pro/forms/new_record` documented | **Not implemented** without a licensed runtime to verify final outcome/transport | — |
| Arbitrary native forms / login / registration | No common business-success contract | **Not automatically tracked** | — |
| WooCommerce checkout | Reuses existing Ecommerce order capture and projection | 10.6.2: real classic checkout following a confirmed CF7 form | Existing checkout analytics; Pro form attribution |

Public-plugin scenarios run against actual locally installed releases on WordPress 7.1.4, PHP 8.5.5 and MySQL 8.0.35. Pinned/latest CI is added in both repositories; the latest lane is an early warning, not a promise that every future update is supported. Existing PHP-floor static checks remain. Licensed builders, older WordPress/plugin combinations and all third-party add-ons require further runtime qualification. Failures cannot be relabeled as successes.

| Capability | Free | Pro |
|---|---|---|
| Supported adapters, detection, collection pause, privacy controls | Yes | Same Free implementation |
| Seen, started, confirmed completed visits and observed completion rate | Yes | Same definitions |
| Unfinished starts, validation/spam/processing outcomes where exposed | Yes | Same definitions |
| Desktop/mobile, top five channels, completion time, daily trends and evidence-gated suggestions | Yes | Same definitions |
| Native dates, form selection, device/channel equality filters, saved segments, refresh, Copy AI Prompt | Yes | Yes |
| Structural field interactions, browser errors, focused time, last unfinished field | No capture or calculations for a teaser | Yes; 64 visible controls, 10,000 summaries and 20 layouts per selection |
| Advanced native filters, top source/campaign/country/browser/page breakdowns | Honest unavailable selection | Yes; retained first-recorded form-page cohort |
| Equal complete-period history | No | Yes; no invented pre-collection history |
| Adjacent pages before/after confirmation | No | Top ten aggregate paths each direction; query/fragment removed, indexed visits only |
| Same-visit last-confirmed-form WooCommerce net sales | No | 24-hour maximum, separate currencies, refunds, ambiguous ordering excluded |
| Native CSV export / existing in-product AI | Existing Free previews / clipboard | Authorized aggregate form rows / existing AI provider workflow |

This implementation does not add automated alerts, CRM-qualified lead identification, cross-device attribution, unrestricted raw visitor journeys or field-detail CSV exports. Repeated submissions of the same form in one visit count once; these are completed visits, not entry or lead counts. Field errors cover browser validation; plugin-specific overall errors may lack a field signal. Processing success is not a guarantee of email delivery or lead quality. Historical Goals stay intact; future event notes on eligible forms omit field text to prevent answer leakage.

## Maintenance and operational limits

- Add a small `Adapter::register()` class and a registry entry only after verifying the public identity/outcome contract. Shared capture, consent, storage, deduplication and UI remain unchanged. There is no private plugin-table reader or external compatibility service.
- Cached full-page HTML contains a site/form HMAC only. Per-visit signed context is added after SlimStat confirms a pageview. A cache that prevents the module from loading can lose measurements but cannot verify false completions.
- Identity is `(form, visit)` with page fallback when a visit is unavailable. Device/source/day belong to the first recorded form page. Report dates select those cohorts, not a calendar count of entries received.
- The endpoint accepts at most 8 KiB / 20 forms. The browser batches up to 7,500 JSON characters. Client flags cannot claim spam, failure or success; server adapters grant these separately.
- Collection performs one transactional cohort update and daily delta per change, not a raw interaction insert. Duplicate flags do not change counts. An existing builder transaction causes analytics to fail closed rather than commit that transaction.
- A 30-second aggregate cache is shared by report routes. Privacy erasure changes the existing global data version immediately. Date reads exclude expired aggregates even while purge is pending. Row deletion cascades through the existing pageview foreign key; coarse identifier-free daily totals remain after personal erasure until retention expiry.
- Setup creates only the two versioned on-demand tables. Recovery verifies columns, indexes, InnoDB and the retention foreign key. Interrupted setup can be retried by an authorized owner; no destructive repair or historical backfill is attempted.
- Site administrators need SlimStat report access as well as `manage_options`. Network-merged form reports are rejected, and storage uses the current site prefix and configured analytics database handle. No new minimum PHP/WordPress requirement is declared.

See [verification evidence](form-analytics/verification.md) and [reproducible benchmark results](form-analytics/benchmark.json).
