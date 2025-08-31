# WP-SlimStat Project Rules (Master)

## WordPress Coding Standards

* **Indentation:** Tabs (not spaces).
* **Naming:** Prefix public classes, functions, hooks, and globals with `wp_slimstat_*`; Pro uses `WpSlimstatPro\…` namespaces.
* **Brace/Spacing:** Follow WP PHP Coding Standards (opening brace on same line; standard spacing).
* **Yoda Conditions:** `if ( 'value' === $var )`.
* **Docs:** PHPDoc for all classes, methods, hooks; meaningful inline comments.

## Version Support

* **Minimum WordPress:** **5.6+**
* **Minimum PHP:** **7.4+** (ensure forward compatibility with PHP 8.0–8.3).
* Use typed properties, arrow functions, and `declare(strict_types=1);` where appropriate; avoid deprecated 8.x-removed features.

## GPL Compliance & Open Source

* **License:** GPLv2+ (or GPL-compatible) for all code/assets.
* **No Encrypted/Obfuscated Code:** Ship sources for any minified assets.
* **Headers:** Add GPL license headers to new files; keep build scripts open.

## Secure Coding Practices

* **Sanitize Inputs:** `sanitize_text_field()`, `intval()`, `sanitize_email()`, `sanitize_key()`, `wp_unslash()` + `esc_url_raw()` where relevant.
* **Escape Outputs:** `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` by context.
* **DB Safety:** Always use `$wpdb->prepare()`; no raw interpolated SQL.
* **No Unsafe Functions:** No `eval()`/dynamic include of user-controlled paths.
* **Types & Validation:** Validate type/format before processing.

## Security & Permissions

* **Nonces + Caps:** Nonce check + capability check for every state-changing action (`manage_options` or custom `slimstat_*`).
* **AJAX/REST:** Use `permission_callback`; never expose raw queries; validate/sanitize all params.
* **Escaping Everywhere:** Output is escaped at render time; never echo unsanitized DB/user values.
* **Indexes:** Ensure SlimStat tables are indexed for safe, fast queries.

## Nonce & Permission Checks (Forms)

* `wp_nonce_field()` in forms; `wp_verify_nonce()` on submit.
* `current_user_can()` for admin actions; respect roles/capabilities.
* CSRF prevention on all POST/AJAX routes.

## Privacy & GDPR Compliance (Analytics-Specific)

* **Consent Required:** No tracking without opt-in; integrate with WP privacy policy tools/consent UI.
* **Anonymization:** Hash or truncate IPs before storage; avoid storing direct personal identifiers.
* **Respect Opt-Out:** Front-end opt-out (cookie/localStorage). Honor globally.
* **Retention:** Admin setting for data retention; purge via WP-Cron.
* **No External Calls by Default:** Geolocation/3rd-party services are **opt-in** with clear disclosure.
* **WP Privacy API:** Implement personal data exporters/erasers where applicable.
* **No Regressions:** New code must maintain or improve GDPR posture.

## No External Calls Without Opt-In

* **No Silent Phone-Home:** Any remote request must be user-initiated or explicitly enabled.
* **Documented:** List endpoints, purpose, and data sent.
* **No Usage Telemetry** without explicit, revocable consent.

## WordPress Plugin Directory Guidelines

* **No Crippleware:** Free plugin fully works; Pro extends via addons/hooks.
* **No Unauthorized Ads/Links:** "Powered by" is opt-in only.
* **Admin Notices:** Relevant, dismissible, minimal.
* **Graceful Fallbacks:** External service failures don't break the site.
* **Repository Eligibility:** Follow all WP.org rules strictly.

## Architecture & Code Organization

### Core (Free)

* **Pattern:** Procedural singleton (`wp_slimstat` static class) + small focused classes.
* **Structure:**

  * Admin logic in `admin/`
  * Admin views/templates in `admin/view/` (minimal logic)
  * Data & services in `src/`
* **Separation of Concerns:** Keep templates thin; move logic into classes.

### Pro

* **Pattern:** Modern OOP with namespaces + DI container.
* **Namespaces:** `WpSlimstatPro\…` with directories like `src/Admin/`, `src/Addon/`.
* **Services:** Register via service providers (DI); avoid mixing paradigms with Free.

### Extensibility

* **Hooks:** Provide `do_action()`/`apply_filters()` for key lifecycle points.
* **Free vs Pro Boundaries:** Pro adds features via hooks/classes, not hidden gates in Free.
* **Schema Versioning:** Migrate with `dbDelta()` behind version checks.

## Consistency Across Free & Pro

* **Hook Stability:** Maintain BC for public hooks/APIs; deprecate gradually.
* **Identifiers:** Don't rename/remove globals/options without a migration path.
* **Text Domains:** `wp-slimstat` (Free), `wp-slimstat-pro` (Pro); i18n everywhere.
* **Modularity:** Avoid monoliths; keep logical file/module boundaries.

## Performance & Resource Usage

* **Scalable Storage:** Use dedicated SlimStat tables; avoid `postmeta/options` for logs.
* **Batch/Async:** Heavy jobs via WP-Cron/batches (e.g., IP lookups, aggregations).
* **Caching:** Transients/object cache for repeated aggregates.
* **Big Data Safety:** No `SELECT *` on large tables; paginate; limit columns; ensure indexes.
* **Memory & Time:** Optimize for high-traffic sites.

## Error Handling & Logging

* **Graceful Failures:** Try/catch risky code; never fatal in admin/front.
* **Admin Notices:** Friendly, non-sensitive error messages.
* **Logging:** `WP_DEBUG_LOG`/`error_log()`; never log personal data.
* **Uninstall:** `uninstall.php` cleans tables/options/settings introduced by SlimStat.

## Documentation

* **PHPDoc:** For public methods/classes; document filter/action signatures.
* **Readme/Changelog:** Keep `readme.txt` + `CHANGELOG.md` current for user-impacting changes.
* **Comments:** Clarify complex logic; err on the side of over-explaining.

## Internationalization & Accessibility

* **i18n:** Wrap all user-facing strings; load text domain early.
* **RTL & a11y:** RTL-ready admin UI; semantic headings; ARIA where appropriate.

## File Naming & Placement

* **Core:** Files prefixed `wp-slimstat-*`; class files in `src/` by concern.
* **Pro:** Namespaced classes under `src/…`; service providers by feature area.
* **Admin:** Views under `admin/view/`; logic in `admin/` classes.

## Testing Requirements

* **Unit Tests:** `WP_UnitTestCase` for data layer/edge cases.
* **Large Dataset Tests:** Validate performance on millions of rows (synthetic fixtures OK).
* **Security Tests:** Inputs sanitized, outputs escaped; nonce/cap checks present.
* **Integration:** Confirm new features don't break existing reports/tracking.
* **Cross-Version:** Test WP 5.6+ and PHP 7.4–8.3.

## Development Workflow

* **CI/CD:** Run PHPCS (WordPress rules), unit tests, and linting on every PR.
* **Static Analysis:** PHPStan/Psalm (with WP stubs) at level 6–8.
* **E2E (critical flows):** Playwright/Cypress hitting a test site to ensure visits get logged.
* **Build Hygiene:** No `var_dump()`/echo in shipped code; remove debug code before release.

## Backward Compatibility

* **Hooks:** If changing a hook, add `_deprecated_hook()` and a replacement filter/action.
* **Options/Tables:** Migrate options/DB schema on upgrade; never drop data silently.

## Packaging & Deployment

* **Plugin Headers (main file):**

  * `Requires at least: 5.6`
  * `Requires PHP: 7.4`
  * `Tested up to: [latest]`
  * `Stable tag: [x.y.z]`
* **Readme Compliance:** Changelog per release; stable tag matches Git tag.
* **Exclude Dev Files:** Use `.gitattributes`/build script to exclude tests, CI configs, node_modules, etc.

## Multisite & WooCommerce Considerations

* **Multisite:** Data stored per-site; test network activate/deactivate; no cross-site leakage.
* **WooCommerce Tracking:** Use official hooks (`woocommerce_thankyou`, `woocommerce_add_to_cart`, etc.) for ecommerce events; no template overrides for tracking.
* **Graceful Deactivation:** Turning off Pro must not corrupt/lose Free data or break admin UI.

## Data Portability

* **Export/Erase:** Implement `wp_privacy_personal_data_exporter` and `…_eraser` for SlimStat's stored data (if any is personal).
* **Formats:** Provide CSV/JSON exports with proper escaping and column headers.

## Code Review Checklist (pre-PR)

1. ✅ WP Coding Standards (PHPCS clean)
2. ✅ Nonce + capability checks on mutations
3. ✅ Inputs sanitized; outputs escaped
4. ✅ GDPR-safe (consent, anonymization, opt-out honored)
5. ✅ Architecture consistent (Free vs Pro patterns)
6. ✅ Efficient queries; indexes present; no N+1
7. ✅ Graceful error handling; no sensitive logs
8. ✅ Docs updated (PHPDoc, readme, changelog)
9. ✅ Tests pass (unit/integration); manual smoke tests on fresh & large datasets
10. ✅ Backward compatible (hooks, options, schema)
