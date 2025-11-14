<?php

namespace SlimStat\Admin;

/**
 * Marketing page for Traffic Channel Report widgets.
 *
 * Registers "Marketing" menu item under SlimStat admin menu and provides
 * page rendering with widget container structure.
 *
 * @package SlimStat\Admin
 * @since 5.1.0
 */
class MarketingPage
{
    /**
     * Page slug for Marketing menu.
     *
     * @var string
     */
    public const PAGE_SLUG = 'slimstat-marketing';

    /**
     * Initialize Marketing page.
     *
     * Registers admin menu and page hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        // Register Marketing screen in SlimStat's screen info array (BUG-005 fix)
        add_filter('slimstat_screens_info', [self::class, 'register_screen_info'], 5);
        add_action('admin_menu', [self::class, 'register_menu'], 20);
    }

    /**
     * Register Marketing screen in SlimStat's screens_info array (BUG-005 fix).
     *
     * SlimStat's architecture requires all admin pages to be registered in the
     * $screens_info array for proper integration with navigation, styling, and widgets.
     *
     * @param array $screens_info Existing screens
     * @return array Modified screens with Marketing added
     */
    public static function register_screen_info(array $screens_info): array
    {
        $screens_info[self::PAGE_SLUG] = [
            'is_report_group' => true,
            'show_in_sidebar' => true,
            'title'           => __('Marketing', 'wp-slimstat'),
            'capability'      => 'can_view',
            'callback'        => [self::class, 'render_page'],
        ];

        return $screens_info;
    }

    /**
     * Register "Marketing" menu item (T036).
     *
     * Adds submenu under SlimStat admin menu with view_slimstat capability check.
     *
     * @return void
     */
    public static function register_menu(): void
    {
        add_submenu_page(
            'slimview1', // Parent slug (SlimStat main menu)
            __('Marketing', 'wp-slimstat'), // Page title
            __('Marketing', 'wp-slimstat'), // Menu title
            'view_slimstat', // Capability
            self::PAGE_SLUG, // Menu slug
            [self::class, 'render_page'] // Callback
        );
    }

    /**
     * Render Marketing page (T037).
     *
     * Loads and renders the marketing-page.php template with widget containers.
     *
     * @return void
     */
    public static function render_page(): void
    {
        // Check capability
        if (!current_user_can('view_slimstat')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wp-slimstat'));
        }

        // Load template
        $template_path = dirname(plugin_dir_path(__FILE__), 2) . '/admin/view/marketing-page.php';

        if (file_exists($template_path)) {
            include $template_path;
        } else {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('Marketing', 'wp-slimstat') . '</h1>';
            echo '<p>' . esc_html__('Template file not found.', 'wp-slimstat') . '</p>';
            echo '</div>';
        }
    }
}
