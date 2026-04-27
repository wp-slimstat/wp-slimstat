<?php
/**
 * Destructive-action confirm sheet.
 *
 * Single DOM fragment injected once per slimview6 page load; JS toggles .is-open
 * and populates the title / body / action attributes before showing.
 *
 * Rendered only when the current screen emits goals/funnels admin UI (so it's
 * never present in the WP dashboard widget, the shortcode output, or the email
 * report).
 *
 * @since 5.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="slimstat-gf-sheet" id="slimstat-gf-confirm-sheet" role="dialog" aria-modal="true" aria-labelledby="slimstat-gf-confirm-title" aria-hidden="true">
    <div class="slimstat-gf-sheet__backdrop" data-action="close-confirm-sheet"></div>
    <div class="slimstat-gf-sheet__panel" tabindex="-1">
        <h2 class="slimstat-gf-sheet__title" id="slimstat-gf-confirm-title" data-role="confirm-title">
            <?php esc_html_e('Delete this?', 'wp-slimstat'); ?>
        </h2>
        <p class="slimstat-gf-sheet__body" data-role="confirm-body"></p>
        <p class="slimstat-gf-sheet__warning" data-role="confirm-warning">
            <?php esc_html_e('Historical data stays — only the definition is removed. You can always rebuild it.', 'wp-slimstat'); ?>
        </p>
        <div class="slimstat-gf-sheet__actions">
            <button type="button" class="button" data-action="close-confirm-sheet" data-role="confirm-cancel">
                <?php esc_html_e('Cancel', 'wp-slimstat'); ?>
            </button>
            <button type="button"
                    class="button button-primary slimstat-gf-sheet__destructive"
                    data-action="confirm-destructive"
                    data-role="confirm-destructive">
                <?php esc_html_e('Delete', 'wp-slimstat'); ?>
            </button>
        </div>
    </div>
</div>
