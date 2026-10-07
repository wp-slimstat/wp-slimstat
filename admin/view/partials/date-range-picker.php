<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed by wp_slimstat_admin::get_template().
/**
 * The global date range picker; slimstat-daterangepicker.js finds it inside #slimstat-date-filters.
 *
 * @var string $label Range as the button shows it.
 * @var string $start First day, Y-m-d.
 * @var string $end   Last day, Y-m-d.
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<fieldset id="slimstat-date-filters" class="wp-ui-highlight">
    <div class="slimstat-date-range-picker">
        <button type="button" class="slimstat-date-range-btn" aria-haspopup="true" aria-expanded="false">
            <div class="datepicker-badge-elements">
                <svg class="calendar-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none">
                    <defs>
                        <clipPath id="slimstat-calendar-clip">
                            <path fill="#fff" d="M0 0h16v16H0z"/>
                        </clipPath>
                    </defs>
                    <g clip-path="url(#slimstat-calendar-clip)" stroke="currentColor" stroke-linejoin="round">
                        <path d="M13 2.5H3a.5.5 0 0 0-.5.5v10a.5.5 0 0 0 .5.5h10a.5.5 0 0 0 .5-.5V3a.5.5 0 0 0-.5-.5z"/>
                        <g stroke-linecap="round">
                            <path d="M11 1.5v2m-6-2v2m-2.5 2h11"/>
                        </g>
                    </g>
                </svg>
                <span class="date-label"><?php echo esc_html($label); ?></span>
            </div>
            <div class="datepicker-badge-elements">
                <span class="caret"></span>
            </div>
        </button>
        <input type="text" class="slimstat-date-range-input" style="display: none;" data-start="<?php echo esc_attr($start); ?>" data-end="<?php echo esc_attr($end); ?>" />
    </div>
</fieldset><!-- #slimstat-date-filters -->
