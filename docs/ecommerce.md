# SlimStat Ecommerce — implementation and acceptance contract

Research/design baseline: 27 September 2026. Implementation contract. Runtime verification and hosting limits are recorded below.

## Design basis

WooCommerce is authoritative for commerce; SlimStat supplies supported consented traffic. The design prioritizes decisions, progressive disclosure, explicit attribution coverage, separate currencies and small-sample guards. Free provides a complete useful page; Pro adds analytical depth and export. No new identity service or standalone application is required.

Current primary references verified:

- https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/ — WC CRUD works across legacy and HPOS; declare compatibility after verifying.
- https://developer.woocommerce.com/docs/features/orders/wc-get-orders/ — bounded order retrieval through the supported abstraction.
- https://woocommerce.com/document/order-attribution-tracking/ — WooCommerce's attribution is session-scoped, not historical multi-touch identity.
- https://woocommerce.com/document/woocommerce-analytics/ — distinguish sales, discounts, refunds, tax, shipping, order statuses and report dates.

## Product and visual direction

Audience: a store owner reviewing performance inside their normal daytime WordPress admin. Reuse SlimStat's light surfaces, typography, spacing, focus colors and shared header/date/filter controls. Use a compact KPI strip, large trend, paired ranked lists and progressive disclosure; avoid a separate app, customer portraits, decorative gradients and inaccessible dual axes.

One Ecommerce page: performance → revenue drivers → observed journey → next investigation. Details and definitions expand inline. Tables stay usable on mobile through compact columns and labelled horizontal scrolling; functionality is not hidden behind “use desktop.” Values, signs and labels carry meaning independently of color. No new frontend framework or runtime dependency.

## Metric contract

- **Order cohort:** standard WooCommerce `shop_order` records created inside the selected site-calendar window. Included statuses: `processing`, `completed`, `refunded`. Pending, on-hold, failed, cancelled, checkout-draft and trash do not contribute sales. A processing order is an accepted order, not a guarantee of gateway settlement (e.g. cash on delivery). Custom statuses are not silently classified as paid.
- **Net sales:** order total − order tax − shipping, then subtract all recorded refund amounts and add back the tax and shipping amounts explicitly refunded. Order totals already include discounts; never subtract discounts twice. Fees remain included. Full and partial refunds use the original order's cohort, including refunds made after the selected period. This is current net value of orders created in the period, not cash flow or accounting profit. Historical values can change after a refund/status edit.
- **Orders:** distinct included order IDs, including fully refunded orders. Zero-value orders count. Status changes and repeated hooks replace a projection; they never append another sale.
- **AOV:** net sales / included orders, undefined when orders = 0.
- **Refunds:** recorded refunded amount, including refunded tax/shipping; disclose the net-sales adjustment separately. No inferred refunds from a status label alone.
- **Products:** item totals after discounts and explicit line refunds, excluding tax/shipping; net quantities subtract explicit refunded quantities. An amount-only refund cannot be allocated to a product. Fees and unallocated adjustments are displayed separately so users can reconcile product totals with net sales.
- **Discounts/coupons:** original discount amounts and orders using a coupon, not incremental revenue caused by that coupon. Multiple coupons mean rows overlap; coupon order counts are not additive.
- **Currency:** one selected order currency at a time; show its ISO code. No exchange rates and no mixed-currency sum. Use fixed decimal arithmetic for accumulation, WooCommerce formatting for presentation. Currency filters do not apply to traffic counts, which have no currency; purchase rates refer to purchases in the selected currency and state that limitation.
- **Dates:** use the native datepicker's site-calendar boundaries and filters. Store order creation UTC as well as SlimStat-compatible wall time. Rebuild if the reporting timezone changes. Comparisons use an adjacent equal calendar window, with partial-period comparisons explicitly labelled. Refunds follow the order cohort in both periods.
- **Association:** only the existing verified, unexpired SlimStat tracking cookie, an existing retained human pageview and current consent/exclusion checks may connect checkout to a tracked visit. No IP/email matching, new identifier, cross-device inference or historical association backfill. Guest checkout is supported. Multiple orders in a visit count as multiple orders but one converting visit.
- **Acquisition:** retained SlimStat session-entry attribution when a verified association exists; otherwise use WooCommerce's already recorded order attribution as a separately identified fallback. Missing attribution is Unattributed, never inferred Direct. No reconstruction of old session funnels from orders.
- **Tracked purchase rate:** distinct tracked visits with an associated included order created in the same reporting window / eligible tracked visits active in that window. Eligibility requires consent-compatible order association tracking. Apply the same native traffic filter predicate to numerator and denominator. Show numerator, denominator and coverage. No rate under 100 eligible visits; this is a display floor, not statistical significance.
- **Journey:** observed product → successful add-to-cart → checkout entry → included order in time order within the same retained visit and reporting window. Express/direct checkout can bypass steps; purchases outside this sequence are shown separately. Missing instrumentation, older dates and unavailable visit association are unavailable data, not zero. This sequence does not prove why a visitor left or establish final cart abandonment.
- **Customer segments:** guest versus registered-account orders only. Do not relabel these as new/repeat customers or infer guest lifetime identity.

## Report specification and edition matrix

All commerce reports require WooCommerce, reporting setup and the commerce-report capability plus SlimStat report access. Native date filters and comparisons apply. Native traffic filters/saved segments narrow commerce to associated retained visits; the page states this and shows the excluded/unmatched coverage. Unsupported addon/network scopes must be explicitly refused, not silently dropped. Currency applies to commerce only.

| Report / business question | Calculation / sources | Decision and limits | Edition |
|---|---|---|---|
| Performance: what did these orders earn? | Net sales, distinct orders, AOV from WC cohort; current versus adjacent prior window; adaptive trend (at most 62 intervals) | Separate volume from basket value. Zero orders gives no AOV; refunds can revise history. No profit/cash claim. | Free |
| Tracking coverage: how much can I explain? | Included orders with retained verified SlimStat association / all included orders; separate WC-source coverage | Fix missing attribution before comparing traffic efficiency; never inflate total traffic or impute missing orders. | Free |
| Channels and sources: where does revenue come from? | Single-touch order net sales grouped by normalized acquisition; explicit unattributed row and provider | Investigate highest absolute contributors, not noisy ratios. All rows plus Other reconcile to totals. Native filters exclude unassociated WC-only records. | Free |
| Products: what sells and what is returned? | WC line net revenue and units after explicit line refunds; unallocated adjustment reconciliation | Identify meaningful products; amount-only refunds are not assigned proportionally without evidence. | Free |
| Purchase journey: where does the observed sequence stop? | Distinct eligible visits reaching ordered recorded stages; same window and native filters | Investigate largest observed count drop. Describe association/instrumentation gaps and bypass purchases. No historical fabricated funnel. | Free |
| Next investigations: what should I examine first? | At most three deterministic observations using absolute affected volume/value; explicit threshold/sample/coverage | Coverage first when weak; then refunds, largest measured journey loss, strongest revenue contributor. Investigation hypotheses are labelled; no predicted gains or causal claims. | Free |
| Campaigns and landing pages: which acquisition deserves attention? | Same order engine grouped by campaign or retained entry page; matched traffic context where comparable | Follow through to native UTM/channel/page filters. Missing landing data is Unattributed; WC-only source data cannot support session conversion. | Pro |
| Device and guest/account segments: which audience differs? | Same engine by retained browser/device category or WC customer ID presence (boolean only) | Device gaps are observations, not proof of poor mobile UX; guest/account is not repeat purchase. | Pro |
| Coupons/refund detail: where do discounts and returns concentrate? | WC coupon amounts/order use and product line refunds | Inspect promotion exposure and product issues. No coupon ROI, profitability, fraud or misuse accusation. | Pro |
| Export: take evidence into a review | CSV of the current scoped aggregate report, same permission/date/currency/filters | No customer PII; neutralize spreadsheet formulas. Bounded export with explicit row limit. | Pro |

## Installation, upgrade and recovery

Requires the paired Free build, WooCommerce 8.3+ and its Action Scheduler, a 64-bit PHP runtime, and the normal SlimStat database permissions. Verified runtime: WooCommerce 10.6.2, WordPress 7.1/7.1.2, PHP 8.2/8.3, MariaDB 12.3/MySQL 8.0. HPOS and legacy order storage use the same WC CRUD implementation. WooCommerce's own WordPress/PHP requirements also apply.

1. Back up the database and install Free, then the paired Pro build if needed. Keep normal scheduled actions running.
2. Open **SlimStat → Ecommerce → Set up Ecommerce** as a user with SlimStat access and `manage_woocommerce` or `manage_options`. Viewing requires SlimStat access plus `view_woocommerce_reports` or `manage_options`.
3. Setup version 1 creates only `{prefix}slim_ecommerce` and the `(visit_id,dt,id)` analytics index. The online index build can take time and disk space on a large database. It refuses unsupported online DDL rather than falling back to a blocking copy. No WooCommerce table is changed. Import uses WC field queries on HPOS and its documented legacy query extension with a narrowly scoped WordPress ID predicate.
4. Background jobs process at most 50 orders after a stable ID cursor, bounded by the setup ceiling (without scanning empty ID ranges). The dashboard labels totals provisional until import completes. Status changes, refunds and deletions queue only the order ID. Action Scheduler group: `slimstat-ecommerce`.
5. Verify the selected currency and date range, read the coverage line, then review the highest revenue products/channels and the first evidence-backed investigation. Allow new consented traffic to accumulate before interpreting the journey.

**Recovery:** open Definitions → Rebuild reports (or the visible recovery button). Setup is repeatable; per-order replacements use transactions and preserve retained associations and erasure flags. A rebuild also removes projected orders deleted while tracking was inactive. SlimStat deactivation cancels its jobs and marks reports as needing reconciliation; reactivation refuses stale totals until rebuilt. A timezone change requires a rebuild as well. An unreadable order leaves a visible provisional status and the first failing order ID while other orders continue. Resolve the WC data/database issue and rebuild; partial totals are never labelled complete. Jobs that fail leave a visible status; inspect WooCommerce → Status → Scheduled Actions before retrying. Migrations-disabled mode refuses setup.

For a malformed column/index, retain a backup and repair the exact structure against `src/Schema/Schema.php`; setup does not silently drop or narrow existing data. Restore a database backup for a failed manual repair. Do not drop the projection to troubleshoot ordinary import errors: doing so would discard order/visit links and erasure flags that cannot be reconstructed from WooCommerce. Downgrading either plugin leaves WC orders intact; Pro skips its addon when the Free API is absent. Deactivating WooCommerce retains aggregate data but disables reporting; privacy cleanup remains registered in Free.

**Retention/privacy:** the projection follows SlimStat's analytics retention in days (0 = no automatic expiry). Reports exclude expired commerce immediately; maintenance deletes at most 1,000 rows per job. Deleting a retained entry pageview atomically nulls its order associations with a foreign key. WC erasure and WordPress email/IP privacy workflows remove association and copied acquisition, and later synchronization preserves that erasure. No billing addresses, names, emails, IPs, cart contents or new browser identifiers are added to commerce storage. Order IDs remain necessary for reconciliation and are included only in the authenticated personal-data export, not aggregate dashboard CSVs. Uninstall follows SlimStat's existing delete-data policy and table inventory; normal deactivation does not delete data.

**Freshness/performance:** order changes are asynchronous; traffic-derived responses cache for up to 60 seconds. One bounded SQL response serves the page; Pro CSV reuses the same formulas and filters, caps rankings at 1,000 rows and neutralizes spreadsheet formulas. Ordinary storefront boot adds hook registration only; checkout records a constant-size association and schedules the order. No reporting scan or historical import runs inside checkout. Native chart/table navigation and the report refresh remain unchanged. A projection row is an order summary, product line or coupon, with `(order_id,item_id)` preventing duplicates. Decimal accumulation uses six fixed decimal places; input beyond supported precision fails visibly instead of rounding silently.

## Verification and limits

Runnable acceptance checks live in `tests/Unit/Ecommerce/`, `tests/e2e/ecommerce-dashboard.spec.ts`, `tests/e2e/woocommerce-purchase-journey.spec.ts` and their guarded PHP helpers. Pro adds `tests/e2e/ecommerce-export.spec.ts`. Helpers refuse the real Local database; browser tests use disposable wp-env. Never run fixture helpers against a store database.

- Free: 553 unit tests / 1,245 assertions; 159 integration tests / 399 assertions. Pro: 113 unit tests / 251 assertions. Both PHPStan checks pass.
- Fixture checks reconcile USD115 net sales, four included orders, USD28.75 AOV, 1/120 tracked buying visits, EUR200 isolated, explicit partial/full refunds, quantities, comparison dates and unmatched orders. Additional checks exercise privacy, exclusions, rebuild, query failure, currency, date boundaries and cache invalidation.
- Browser checks exercise classic and Blocks guest purchases, Free without Pro, WC deactivation, permission/nonce rejection, native dates/saved segments/filters, responsive tables, keyboard disclosure and matching CSV output.
- Automated accessibility scan: no violations in the Ecommerce region, 26 passing checks; SVG chart-text contrast requires manual review. Desktop, 390px mobile and RTL visuals inspected. No claim of complete screen-reader certification.
- A synthetic 100,000-order/100,000-visit cohort reconciled across all eight Pro dimensions. Across three repetitions, median report-method time was 2.79 seconds cold, 4.28 seconds filtered, and 0.07 milliseconds warmed in the same PHP process (zero reporting queries). A narrow date range used the period index; the full cohort created seven disk temporary tables (eight when filtered). These exclude HTTP/WordPress startup and are absolute observations, not a speedup claim or hosting SLA. Large uncached reports remain an optimization target on constrained hosts. Database work is aggregate SQL; source rows are not loaded into PHP.

External database hosts, multisite/network aggregation, every currency extension/custom order status, payment gateway, CMP and WC 8.3 minor version have not all been tested. Network-merged Ecommerce and unsupported addon filters are explicitly refused. Eligible visits have no currency: the purchase numerator uses the chosen currency while the displayed denominator covers all eligible visits. No profit, ROAS, LTV, cross-device attribution, historical funnels, checkout-field errors or causal revenue uplift is claimed; those require additional reliable inputs or experiments.

## Real-store data quality

Local verification also exercised pre-existing invalid serialized item metadata. The dashboard correctly keeps its figures provisional, identifies a failing order for authorized users and directs the owner to resolve synchronization before drawing revenue conclusions. Other orders continue importing; expired orders skip unnecessary item/refund reads. Repairing WooCommerce source data is separate from this feature's non-destructive reporting migration.
