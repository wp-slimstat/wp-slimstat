<?php

namespace SlimStat\Widgets;

use SlimStat\Channel\ClassificationEngine;

/**
 * Base abstract class for traffic channel widgets.
 *
 * Provides shared functionality for channel widgets including transient caching,
 * date range filtering, and AJAX refresh support.
 *
 * @package SlimStat\Widgets
 * @since 5.1.0
 */
abstract class BaseChannelWidget
{
    /**
     * Widget unique identifier.
     *
     * @var string
     */
    protected string $widget_id;

    /**
     * Widget title for display.
     *
     * @var string
     */
    protected string $widget_title;

    /**
     * Classification engine instance.
     *
     * @var ClassificationEngine
     */
    protected ClassificationEngine $engine;

    /**
     * Cache expiry time (seconds).
     *
     * @var int
     */
    protected int $cache_expiry = 300; // 5 minutes

    /**
     * Constructor.
     *
     * @param string $widget_id Widget unique ID
     * @param string $widget_title Widget display title
     */
    public function __construct(string $widget_id, string $widget_title)
    {
        $this->widget_id = $widget_id;
        $this->widget_title = $widget_title;
        $this->engine = new ClassificationEngine();
    }

    /**
     * Render the widget.
     *
     * Main entry point for widget rendering. Handles caching, date range filtering,
     * and delegates to child class's render_content() method.
     *
     * @param array $args Widget arguments (filters, etc.)
     * @return string Widget HTML output
     */
    public function render(array $args = []): string
    {
        // Check if caching is enabled globally
        $cache_enabled = 'on' === (\wp_slimstat::$settings['enable_cdn'] ?? 'off');

        $cache_key = $this->generate_cache_key($args);
        $cached_output = $cache_enabled ? get_transient($cache_key) : false;

        if (false !== $cached_output) {
            return $cached_output;
        }

        // Get date range from filters
        $date_range = $this->get_date_range_filters($args);

        // Fetch widget data (implemented by child class)
        $data = $this->fetch_data($date_range, $args);

        // Render widget content (implemented by child class)
        $content = $this->render_content($data, $args);

        // Wrap in widget container
        $output = $this->wrap_widget_html($content, $args);

        // Cache the output
        if ($cache_enabled) {
            set_transient($cache_key, $output, $this->cache_expiry);
        }

        return $output;
    }

    /**
     * Render widget content only without wrapper (for SlimStat postbox integration).
     *
     * Used by marketing-page.php which provides its own postbox container.
     * Returns only the inner content without the slimstat-widget wrapper.
     *
     * @param array $args Widget arguments
     * @return string Widget content HTML (no wrapper)
     */
    public function render_content_only(array $args): string
    {
        // Extract date range
        $date_range = $this->get_date_range_filters($args);

        // Fetch widget data
        $data = $this->fetch_data($date_range, $args);

        // Render content only (no wrapper)
        return $this->render_content($data, $args);
    }

    /**
     * Generate cache key for transient storage (T031).
     *
     * Matches SlimStat's pattern: slimstat_widget_{id}_{filters_hash}
     *
     * @param array $args Widget arguments
     * @return string Transient key
     */
    protected function generate_cache_key(array $args): string
    {
        // Include date range and other filters in hash
        $filters_hash = md5(serialize($args));

        return "slimstat_widget_{$this->widget_id}_{$filters_hash}";
    }

    /**
     * Get date range filters from arguments (T032).
     *
     * Retrieves current date range from SlimStat's filter system.
     *
     * @param array $args Widget arguments
     * @return array ['date_from' => int, 'date_to' => int]
     */
    protected function get_date_range_filters(array $args): array
    {
        // Default to last 30 days if not specified
        $date_from = $args['date_from'] ?? (time() - (30 * DAY_IN_SECONDS));
        $date_to = $args['date_to'] ?? time();

        // Check if SlimStat has global date filters set
        if (class_exists('wp_slimstat_db')) {
            // SlimStat stores filters in static properties (will integrate in Phase 5)
            // For now, use provided args or defaults
        }

        return [
            'date_from' => (int) $date_from,
            'date_to' => (int) $date_to,
        ];
    }

    /**
     * Wrap widget content in standard HTML container.
     *
     * Adds refresh button, tooltip, and styling consistent with SlimStat widgets.
     *
     * @param string $content Widget content HTML
     * @param array $args Widget arguments
     * @return string Wrapped HTML
     */
    protected function wrap_widget_html(string $content, array $args): string
    {
        $widget_classes = $args['classes'] ?? ['normal'];
        $widget_classes_str = implode(' ', $widget_classes);

        $tooltip = $args['tooltip'] ?? '';
        $tooltip_html = !empty($tooltip)
            ? sprintf('<span class="slimstat-tooltip-trigger" title="%s">?</span>', esc_attr($tooltip))
            : '';

        return sprintf(
            '<div class="slimstat-widget channel-widget %s" id="%s" data-widget-id="%s">
                <div class="slimstat-widget-header">
                    <h3>%s %s</h3>
                    <a class="refresh" href="#" data-widget-id="%s" title="%s">
                        <span class="dashicons dashicons-update"></span>
                    </a>
                </div>
                <div class="slimstat-widget-content">
                    %s
                </div>
            </div>',
            esc_attr($widget_classes_str),
            esc_attr($this->widget_id),
            esc_attr($this->widget_id),
            esc_html($this->widget_title),
            $tooltip_html,
            esc_attr($this->widget_id),
            esc_attr__('Refresh widget', 'wp-slimstat'),
            $content
        );
    }

    /**
     * Register AJAX refresh handler for this widget (T034).
     *
     * Called during widget initialization to enable AJAX refresh functionality.
     *
     * @return void
     */
    public function register_ajax_handler(): void
    {
        add_action('wp_ajax_slimstat_refresh_channel_' . $this->widget_id, [$this, 'ajax_refresh_callback']);
    }

    /**
     * AJAX callback for widget refresh.
     *
     * Handles AJAX requests from .refresh button click.
     *
     * @return void
     */
    public function ajax_refresh_callback(): void
    {
        // Verify nonce
        check_ajax_referer('slimstat_channel_refresh', 'nonce');

        // Check capability
        if (!current_user_can('view_slimstat')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-slimstat')]);
        }

        // Clear cache for this widget
        $args = $_POST['args'] ?? [];
        $cache_key = $this->generate_cache_key($args);
        delete_transient($cache_key);

        // Re-render widget
        $output = $this->render($args);

        wp_send_json_success([
            'html' => $output,
            'widget_id' => $this->widget_id,
        ]);
    }

    /**
     * Fetch widget data from database.
     *
     * Abstract method to be implemented by child classes.
     *
     * @param array $date_range Date range filters
     * @param array $args Additional widget arguments
     * @return array Widget data
     */
    abstract protected function fetch_data(array $date_range, array $args): array;

    /**
     * Render widget content HTML.
     *
     * Abstract method to be implemented by child classes.
     *
     * @param array $data Widget data from fetch_data()
     * @param array $args Widget arguments
     * @return string Widget content HTML
     */
    abstract protected function render_content(array $data, array $args): string;

    /**
     * Render widget for shortcode output.
     *
     * Simplified rendering for [slimstat f="channel" w="..."] shortcodes.
     * Child classes can override for custom shortcode output.
     *
     * @param array $args Shortcode attributes
     * @return string Shortcode output HTML
     */
    public function render_shortcode(array $args): string
    {
        // Use same render logic but with simplified wrapper
        $date_range = $this->get_date_range_filters($args);
        $data = $this->fetch_data($date_range, $args);

        return $this->render_content($data, $args);
    }

    /**
     * Generate upgrade modal for Pro features (T051, T052, T053).
     *
     * Creates modal HTML for free users clicking Pro widget titles or features.
     * Integrates with SlimStat's existing modal styling (.slimstat-pro-modal).
     *
     * @param string $feature_name Feature being accessed (e.g., "Social Traffic Breakdown")
     * @param string $feature_description Description of the Pro feature
     * @return string Modal HTML
     */
    protected function render_upgrade_modal(string $feature_name, string $feature_description): string
    {
        $modal_id = 'upgrade-modal-' . sanitize_title($feature_name);
        $pricing_url = 'https://www.wp-slimstat.com/pricing/';

        return sprintf(
            '<div id="%s" class="slimstat-pro-modal" style="display:none;">
                <div class="slimstat-modal-overlay"></div>
                <div class="slimstat-modal-content">
                    <button class="slimstat-modal-close" aria-label="%s">
                        <span class="dashicons dashicons-no"></span>
                    </button>
                    <div class="slimstat-modal-header">
                        <span class="dashicons dashicons-star-filled"></span>
                        <h2>%s</h2>
                    </div>
                    <div class="slimstat-modal-body">
                        <p class="feature-description">%s</p>
                        <div class="pro-benefits">
                            <h3>%s</h3>
                            <ul>
                                <li><span class="dashicons dashicons-yes"></span> %s</li>
                                <li><span class="dashicons dashicons-yes"></span> %s</li>
                                <li><span class="dashicons dashicons-yes"></span> %s</li>
                                <li><span class="dashicons dashicons-yes"></span> %s</li>
                                <li><span class="dashicons dashicons-yes"></span> %s</li>
                            </ul>
                        </div>
                    </div>
                    <div class="slimstat-modal-footer">
                        <a href="%s" class="button button-primary button-hero" target="_blank" rel="noopener">
                            %s
                            <span class="dashicons dashicons-external"></span>
                        </a>
                        <p class="upgrade-note">%s</p>
                    </div>
                </div>
            </div>',
            esc_attr($modal_id),
            esc_attr__('Close modal', 'wp-slimstat'),
            esc_html(sprintf(__('Upgrade to unlock %s', 'wp-slimstat'), $feature_name)),
            esc_html($feature_description),
            esc_html__('Included in SlimStat Pro:', 'wp-slimstat'),
            esc_html__('Detailed channel breakdowns (Social, Search, AI platforms)', 'wp-slimstat'),
            esc_html__('CSV export for all widgets', 'wp-slimstat'),
            esc_html__('Email reports with channel data', 'wp-slimstat'),
            esc_html__('Advanced filtering and segmentation', 'wp-slimstat'),
            esc_html__('Priority support and updates', 'wp-slimstat'),
            esc_url($pricing_url),
            esc_html__('View Pricing & Upgrade', 'wp-slimstat'),
            esc_html__('30-day money-back guarantee. Cancel anytime.', 'wp-slimstat')
        );
    }

    /**
     * Render Pro feature placeholder with upgrade trigger (T051).
     *
     * Displays a locked Pro feature with click trigger to show upgrade modal.
     * Use this for Pro-only widgets or features in free plugin.
     *
     * @param string $widget_title Pro widget title
     * @param string $widget_description Brief description of Pro widget
     * @param string $icon_class Dashicons class (default: dashicons-lock)
     * @return string Placeholder HTML with modal trigger
     */
    protected function render_pro_placeholder(
        string $widget_title,
        string $widget_description,
        string $icon_class = 'dashicons-lock'
    ): string {
        $modal_id = 'upgrade-modal-' . sanitize_title($widget_title);

        $placeholder_html = sprintf(
            '<div class="slimstat-pro-feature-placeholder" data-modal-id="%s">
                <div class="pro-feature-icon">
                    <span class="dashicons %s"></span>
                    <span class="pro-badge">%s</span>
                </div>
                <h4>%s</h4>
                <p>%s</p>
                <button type="button" class="button button-secondary slimstat-upgrade-trigger" data-modal-id="%s">
                    <span class="dashicons dashicons-star-filled"></span>
                    %s
                </button>
            </div>',
            esc_attr($modal_id),
            esc_attr($icon_class),
            esc_html__('PRO', 'wp-slimstat'),
            esc_html($widget_title),
            esc_html($widget_description),
            esc_attr($modal_id),
            esc_html__('Upgrade to Pro', 'wp-slimstat')
        );

        // Append modal HTML
        $modal_html = $this->render_upgrade_modal($widget_title, $widget_description);

        return $placeholder_html . $modal_html;
    }

    /**
     * Check if Pro plugin is active.
     *
     * Utility method to detect if wp-slimstat-pro is installed and active.
     *
     * @return bool True if Pro plugin active, false otherwise
     */
    protected function is_pro_active(): bool
    {
        return defined('SLIMSTAT_PRO_ANALYTICS_VERSION') || class_exists('wp_slimstat_pro');
    }
}
