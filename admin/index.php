<?php

use SlimStat\Services\GeoService;
use SlimStat\Components\DateRangeHelper;
use SlimStat\Services\Admin\Notification\NotificationFactory;
use SlimStat\Schema\Schema;
class wp_slimstat_admin
{
    /**
     * Column drift observed by the last reconciliation (F4).
     *
     * Durable on purpose. The degradation channel heals by FORGETTING — a record not re-stamped
     * within DEGRADATION_TTL is pruned as "stopped happening" — and ensure() runs only on
     * activation and a version-gated upgrade. Without a durable copy, a permanently drifted
     * install would show this for one three-hour window per plugin release.
     *
     * Never autoloaded: read on admin screens only, on installs already unhealthy enough to have
     * drift (C34).
     */
    const COLUMN_DRIFT_OPTION = 'slimstat_schema_column_drift';

    /** Throttles the admin_init re-observation. Self-expiring, so nothing has to clear it. */
    const COLUMN_DRIFT_CHECK_TRANSIENT = 'slimstat_column_drift_checked';

    public static $screens_info      = [];
    public static $config_url        = '';
    public static $current_screen    = 'slimview1';
    public static $page_location     = 'slimstat';
    public static $meta_user_reports = [];
    public static $settings = [];
    public static $user_reports = [];
    public static $admin_notice    = '';
    public static $main_menu_slug = 'slimview1';

    /**
     * Dimensions for which the filter-options search uses unanchored LIKE
     * (%needle%) instead of the default left-anchored prefix match. These are
     * either multi-token fields (notes, category) or free-form strings where
     * users naturally search fragments (user_agent, outbound_resource URLs).
     * resource + referer are URL-like for the same reason: a user searching a
     * path fragment ("pricing") shouldn't have to type the leading slash to
     * match "/pricing/" — the prefix anchor made that fail. (#18)
     */
    private const FILTER_SEARCH_SUBSTRING_DIMENSIONS = [
        'notes', 'searchterms', 'content_type', 'category', 'author', 'outbound_resource', 'user_agent', 'resource', 'referer',
    ];

    /** Resume point for the 4.8.8 notes conversion. Non-autoloaded; removed on completion. */
    private const NOTES_CURSOR_OPTION = 'slimstat_notes_migration_cursor';

    /** Rows per statement. Batches walk ROWS, not id space — see convert_notes_to_brackets(). */
    private const NOTES_BATCH_SIZE = 20000;

    /** Wall-clock budget for the whole schema upgrade, well inside a default max_execution_time. */
    private const SCHEMA_UPGRADE_TIME_BUDGET = 10;

    /** Single-flight claim for the schema upgrade. Non-autoloaded; released in a finally. */
    private const SCHEMA_LOCK_OPTION = 'slimstat_schema_upgrade_lock';

    /** A claim older than this is assumed dead and taken over. Longer than the request budget. */
    private const SCHEMA_LOCK_STALE_AFTER = 900;

    protected static $data_for_column = [
        'url'   => [],
        'sql'   => [],
        'count' => [],
    ];

    /**
     * Init -- Sets things up.
     */
    public static function init()
    {
        // Redirect to the pro settings
        add_action('admin_menu', function () {
            if (is_admin() && isset($_GET['page']) && 'slimpro' === $_GET['page'] && wp_slimstat::pro_is_installed()) {
                wp_safe_redirect(admin_url('admin.php?page=slimconfig&tab=7'));
                exit();
            }
        });

        // Action for reset layout
        add_action('admin_post_slimstat_reset_layout', ['wp_slimstat_admin', 'handle_reset_layout']);

        // Define the default screens
        $has_network_reports = get_user_option('meta-box-order_slimstat_page_slimlayout-network', 1);

        self::$screens_info = [
            'slimview1' => [
                'is_report_group' => true,
                'show_in_sidebar' => true,
                'title'           => __('Real-time', 'wp-slimstat'),
                'capability'      => 'can_view',
                'callback'        => [self::class, 'wp_slimstat_include_view'],
            ],
            'slimview2' => [
                'is_report_group' => true,
                'show_in_sidebar' => true,
                'title'           => __('Overview', 'wp-slimstat'),
                'capability'      => 'can_view',
                'callback'        => [self::class, 'wp_slimstat_include_view'],
            ],
            'slimview3' => [
                'is_report_group' => true,
                'show_in_sidebar' => true,
                'title'           => __('Audience', 'wp-slimstat'),
                'capability'      => 'can_view',
                'callback'        => [self::class, 'wp_slimstat_include_view'],
            ],
            'slimview4' => [
                'is_report_group' => true,
                'show_in_sidebar' => true,
                'title'           => __('Site Analysis', 'wp-slimstat'),
                'capability'      => 'can_view',
                'callback'        => [self::class, 'wp_slimstat_include_view'],
            ],
            'slimview5' => [
                'is_report_group' => true,
                'show_in_sidebar' => true,
                'title'           => __('Traffic Sources', 'wp-slimstat'),
                'capability'      => 'can_view',
                'callback'        => [self::class, 'wp_slimstat_include_view'],
            ],
            'slimview6' => [
                'is_report_group' => true,
                'show_in_sidebar' => true,
                'title'           => __('Goals & Funnels', 'wp-slimstat'),
                // Optional page-intro lead: any screen that declares one gets a
                // framing H1 (from 'title') + this lead above its report boxes.
                'lead'            => __('Define the conversions that matter, then string them into funnels to see where visitors drop off.', 'wp-slimstat'),
                'capability'      => 'can_view',
                'callback'        => [self::class, 'wp_slimstat_include_view'],
            ],
            'slimemail' => [
                'is_report_group' => false,
                'show_in_sidebar' => true,
                'title'           => wp_slimstat::pro_is_installed() ? __('Email Report', 'wp-slimstat') : __('Email Report (pro)', 'wp-slimstat'),
                'capability'      => 'can_view',
                'callback'        => [self::class, 'wp_slimstat_include_email_report'],
            ],
            'slimlayout' => [
                'is_report_group' => false,
                'show_in_sidebar' => true,
                'title'           => __('Customize', 'wp-slimstat'),
                'capability'      => 'can_customize',
                'callback'        => [self::class, 'wp_slimstat_include_layout'],
            ],
            'slimconfig' => [
                'is_report_group' => false,
                'show_in_sidebar' => true,
                'title'           => __('Settings', 'wp-slimstat'),
                'capability'      => 'can_admin',
                'callback'        => [self::class, 'wp_slimstat_include_config'],
            ],
            'slimpro' => [
                'is_report_group' => false,
                'show_in_sidebar' => current_user_can('manage_options'),
                'title'           => apply_filters('slimstat_upgrade_to_pro_title', __('Upgrade to Pro', 'wp-slimstat')),
                'capability'      => 'can_admin',
                'callback'        => [self::class, 'wp_slimstat_pro'],
            ],
            'dashboard' => [
                'is_report_group' => true,
                'show_in_sidebar' => false,
                'title'           => __('WordPress Dashboard', 'wp-slimstat'),
                'capability'      => '',
                'callback'        => '', // No callback and capabilities are needed if show_in_sidebar is false
            ],
            'inactive' => [
                'is_report_group' => true,
                'show_in_sidebar' => false,
                'title'           => __('Inactive Reports'),
                'capability'      => '',
                'callback'        => '', // No callback and capabilities are needed if show_in_sidebar is false
            ],
        ];
        self::$screens_info = apply_filters('slimstat_screens_info', self::$screens_info);

        // If the plugin was network activated, the tables might not have been created for this specific site
        $table_list = wp_slimstat::$wpdb->get_results(sprintf("SHOW TABLES LIKE '%sslim_stats'", $GLOBALS['wpdb']->prefix));
        if (empty($table_list)) {
            self::init_environment();
        }

        // Settings URL
        if (!is_network_admin()) {
            self::$config_url = get_admin_url($GLOBALS['blog_id'], 'admin.php?page=slimconfig&amp;tab=');
        } else {
            self::$config_url = network_admin_url('admin.php?page=slimconfig&amp;tab=');
        }

        // Current Screen
        if (!empty($_REQUEST['page']) && array_key_exists($_REQUEST['page'], self::$screens_info)) {
            self::$current_screen = $_REQUEST['page'];
        }

        // Page Location
        if ('no' != wp_slimstat::$settings['use_separate_menu']) {
            self::$page_location = 'admin';
        }

        // Is the menu position setting being updated?
        if (!empty($_POST['slimstat_update_settings']) && wp_verify_nonce($_POST['slimstat_update_settings'], 'slimstat_update_settings') && !empty($_POST['options']['use_separate_menu'])) {
            wp_slimstat::$settings['use_separate_menu'] = ('on' == $_POST['options']['use_separate_menu']) ? 'on' : 'no';
        }

        // Retrieve this user's custom report assignment (Customizer)
        // Superadmins can customize the layout at network level, to override per-site settings
        self::$meta_user_reports = get_user_option('meta-box-order_' . wp_slimstat_admin::$page_location . '_page_slimlayout-network', 1);

        // No network-wide settings found
        if (empty(self::$meta_user_reports)) {
            self::$meta_user_reports = get_user_option('meta-box-order_' . wp_slimstat_admin::$page_location . '_page_slimlayout', $GLOBALS['current_user']->ID);
        }

        // Subsite creation moved to wp-slimstat.php's unconditional wp_initialize_site
        // registration (D10): from here it existed only for logged-in admin requests, so
        // WP-CLI and REST site creation found no callback and got no tables.

        // WPMU - Blog Deleted
        add_filter('wpmu_drop_tables', [self::class, 'drop_tables'], 10, 2);

        // Display a notice that hightlights this version's features
        if (!empty($_GET['page']) && false !== strpos($_GET['page'], 'slimview') && (!empty(self::$admin_notice) && 'on' == wp_slimstat::$settings['notice_latest_news'] && is_super_admin())) {
            add_action('admin_notices', [self::class, 'show_latest_news']);

        }

        // Remove spammers from the database
        if ('on' == wp_slimstat::$settings['ignore_spammers']) {
            add_action('transition_comment_status', [self::class, 'remove_spam'], 15, 3);
        }

        // Add a menu to the admin bar
        if ('no' != wp_slimstat::$settings['use_separate_menu'] && is_admin_bar_showing()) {
            add_action('admin_bar_menu', [self::class, 'add_menu_to_adminbar'], 100);
            add_action('admin_enqueue_scripts', [self::class, 'enqueue_adminbar_styles']);
            add_action('wp_enqueue_scripts', [self::class, 'enqueue_adminbar_styles']);
        }

        // Inject the modern Goals & Funnels shared DOM fragments (confirm sheet,
        // goal drawer, funnel builder) exactly once per admin page, and only on
        // pages that actually render slim_p9_01 / slim_p9_02. The check here
        // re-uses the same helper as the asset enqueue gate.
        add_action('admin_footer', [self::class, 'print_goals_funnels_dom']);

        if (function_exists('is_network_admin') && !is_network_admin()) {
            // Add the appropriate entries to the admin menu, if this user can view/admin  Slimstat
            add_action('admin_menu', [self::class, 'add_menus']);

            // Display the column in the Edit Posts / Pages screen
            if ('on' == wp_slimstat::$settings['add_posts_column']) {
                $post_types = get_post_types(['public' => true, 'show_ui' => true], 'names');
                include_once(plugin_dir_path(__FILE__) . 'view/wp-slimstat-reports.php');
                include_once(plugin_dir_path(__FILE__) . 'view/wp-slimstat-db.php');

                foreach ($post_types as $a_post_type) {
                    add_filter(sprintf('manage_%s_posts_columns', $a_post_type), [self::class, 'add_column_header']);
                    add_action(sprintf('manage_%s_posts_custom_column', $a_post_type), [self::class, 'add_post_column'], 10, 2);
                }

                if (false !== strpos($_SERVER['REQUEST_URI'], 'edit.php')) {
                    add_action('admin_enqueue_scripts', [self::class, 'wp_slimstat_stylesheet']);
                    add_action('wp', [self::class, 'init_data_for_column']);
                }
            }

            // Update the table structure and options, if needed
            if (!empty(wp_slimstat::$settings['version']) && SLIMSTAT_ANALYTICS_VERSION != wp_slimstat::$settings['version']) {
                add_action('admin_init', [self::class, 'update_tables_and_options']);
            }
        }

        // Initialize Reports system for SlimStat pages and AJAX requests
        $is_slimstat_page = (!empty($_GET['page']) && 0 === strpos($_GET['page'], 'slim'));
        $is_slimstat_ajax = (!empty($_POST['action']) && (
            'slimstat_load_report' === $_POST['action'] ||
            'slimstat_get_live_analytics_data' === $_POST['action']
        ));

        if ($is_slimstat_page || $is_slimstat_ajax) {
            // Initialize the new Reports system FIRST before legacy system loads
            \SlimStat\Reports\Bootstrap::get_instance()->init();
        }

        // Load the library of functions to generate the reports
        if ($is_slimstat_page || (!empty($_POST['action']) && 'slimstat_load_report' == $_POST['action'])) {
            include_once(plugin_dir_path(__FILE__) . 'view/wp-slimstat-reports.php');
            wp_slimstat_reports::init();

            if (!empty($_POST['report_id'])) {
                $report_id = sanitize_title($_POST['report_id'], 'slim_p0_00');

                if (!empty(wp_slimstat_reports::$reports[$report_id])) {
                    add_action('wp_ajax_slimstat_load_report', ['wp_slimstat_reports', 'callback_wrapper'], 10, 2);
                }
            }
        }

        // Dashboard Widgets
        if ('on' == wp_slimstat::$settings['add_dashboard_widgets']) {
            $sanitized_uri  = sanitize_url(wp_unslash($_SERVER['REQUEST_URI']));
            $request_length = strlen($sanitized_uri);
            $temp           = $request_length - 10;

            if (false !== strpos($sanitized_uri, '/wp-admin/index.php') || ($temp >= 0 && $temp <= $request_length && false !== strpos($sanitized_uri, '/wp-admin/', $temp))) {
                add_action('admin_enqueue_scripts', [self::class, 'wp_slimstat_enqueue_scripts']);
                add_action('admin_enqueue_scripts', [self::class, 'wp_slimstat_stylesheet']);
            }

            add_action('wp_dashboard_setup', [self::class, 'add_dashboard_widgets']);
        }

        // AJAX Handlers
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $ajax_actions = [
                'slimstat_notice_latest_news'       => 'notices_handler',
                'slimstat_notice_geolite'           => 'notices_handler',
                'slimstat_notice_browscap'          => 'notices_handler',
                'slimstat_notice_browscap_fileinfo' => 'notices_handler',
                'slimstat_notice_caching'           => 'notices_handler',
                'slimstat_manage_filters'        => 'manage_filters',
                'slimstat_delete_pageview'       => 'delete_pageview',
                'slimstat_update_geoip_database' => 'update_geoip_database',
                'slimstat_check_geoip_database'  => 'check_geoip_database',
                'slimstat_get_filter_options'    => 'get_filter_options',
                'slimstat_get_online_visitors'   => 'get_online_visitors',
                'slimstat_get_adminbar_stats'    => 'get_adminbar_stats',
                'slimstat_save_goal'             => 'ajax_save_goal',
                'slimstat_delete_goal'           => 'ajax_delete_goal',
                'slimstat_save_funnel'           => 'ajax_save_funnel',
                'slimstat_delete_funnel'         => 'ajax_delete_funnel',
                'slimstat_load_funnel_data'      => 'ajax_load_funnel_data',
                'slimstat_test_funnel_step'      => 'ajax_test_funnel_step',
            ];
            foreach ($ajax_actions as $action => $handler) {
                add_action('wp_ajax_' . $action, [self::class, $handler]);
            }

            // Live Analytics AJAX handler is registered via init_hooks() in Bootstrap
            // No need to call it separately here - it's already registered
        }

        // Schedule a daily cron job to purge the data
        if (!wp_next_scheduled('wp_slimstat_purge')) {
            wp_schedule_event(time(), 'twicedaily', 'wp_slimstat_purge');
        }

        // The daily-salt cron is retired (W6). It was anchored at wp_schedule_event(time(),
        // 'daily', …) — i.e. to whenever the plugin was activated — so it fired at an
        // arbitrary hour, by which point the day's salt already existed and the run was a
        // no-op. The salt is minted on demand instead, under a compare-and-swap, by the
        // first request of each UTC day (IPHashProvider::generateDailySalt()).
        //
        // Not re-anchored to midnight: WP-Cron is request-triggered, so "due at 00:00 UTC"
        // means "runs inside the first request after 00:00 UTC" — the same request that
        // already mints. The name is the actual hazard: it promises a rotation the code no
        // longer performs, so whoever makes it rotate again would re-deliver the
        // split-population bug from a scheduler, mid-day.
        if (wp_next_scheduled('wp_slimstat_generate_daily_salt')) {
            wp_clear_scheduled_hook('wp_slimstat_generate_daily_salt');
        }

        // Schedule a weekly cron job to update geoip database automatically
        if (!wp_next_scheduled('wp_slimstat_update_geoip_database')) {
            $nextRunInterval = wp_slimstat::get_schedule_interval('weekly');
            wp_schedule_event(time() + $nextRunInterval, 'weekly', 'wp_slimstat_update_geoip_database');
        }

        // Fallback: if WP-Cron is disabled or scheduling failed, trigger a non-blocking direct update
        // This ensures environments with DISABLE_WP_CRON still receive GeoIP database updates
        $cron_disabled = (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) || !wp_next_scheduled('wp_slimstat_update_geoip_database');
        $geoip_provider = \wp_slimstat::resolve_geolocation_provider();
        if ($cron_disabled && false !== $geoip_provider && is_admin() && !wp_doing_ajax()
            && current_user_can(\wp_slimstat::$settings['capability_can_admin'])) {
            // Update if DB is missing or last update is older than the most recent past scheduled window
            $last_update = (int) get_option('slimstat_last_geoip_dl', 0);

            // Calculate the most recent "first Tuesday + 2 days" that has already passed
            $this_month_update = strtotime('first Tuesday of this month') + (86400 * 2);
            $current_time = time();

            // If this month's update window hasn't arrived yet, use last month's window
            if ($current_time < $this_month_update) {
                $this_update = strtotime('first Tuesday of last month') + (86400 * 2);
            } else {
                $this_update = $this_month_update;
            }

            $needs_update = $last_update < $this_update;
            $db_missing = false;
            if (!$needs_update) {
                // Time check passed — only check DB existence if time says we're current
                try {
                    $uses_db = in_array($geoip_provider, \SlimStat\Services\GeoService::DB_PROVIDERS, true);
                    if ($uses_db) {
                        $service    = new \SlimStat\Services\Geolocation\GeolocationService($geoip_provider, []);
                        $db_missing = !file_exists($service->getProvider()->getDbPath());
                    }
                } catch (\Throwable $e) {
                    $db_missing = true;
                }
            }

            if ($needs_update || $db_missing) {
                // Fire admin-ajax in a non-blocking way to run the existing update handler
                $ajax_url = admin_url('admin-ajax.php');
                // Forward only WordPress authentication cookies for security
                $cookie_header = '';
                if (!headers_sent() && $_COOKIE !== [] && is_array($_COOKIE)) {
                    $pairs = [];
                    // Only forward WordPress authentication cookies
                    $allowed_cookie_prefixes = [
                        'wordpress_logged_in_',
                        'wordpress_sec_',
                        'wp-settings-',
                        'wp-settings-time-',
                    ];
                    foreach ($_COOKIE as $k => $v) {
                        $is_allowed = false;
                        foreach ($allowed_cookie_prefixes as $prefix) {
                            if (strpos($k, $prefix) === 0) {
                                $is_allowed = true;
                                break;
                            }
                        }
                        if ($is_allowed) {
                            $pairs[] = rawurlencode($k) . '=' . rawurlencode(sanitize_text_field(wp_unslash($v)));
                        }
                    }
                    $cookie_header = implode('; ', $pairs);
                }
                $args = [
                    'timeout'  => 0.01,
                    'blocking' => false,
                    'body'     => [
                        'action'   => 'slimstat_update_geoip_database',
                        'security' => wp_create_nonce('slimstat_geoip_action'),
                    ],
                    'headers' => $cookie_header !== '' && $cookie_header !== '0' ? ['Cookie' => $cookie_header] : [],
                ];
                // Best-effort call; ignore response
                wp_safe_remote_post($ajax_url, $args);
            }
        }

        // Add style to the admin menu
        add_action('admin_head', [self::class, 'styling_admin_menu']);

        // Add lock export button in report header
        add_filter('slimstat_report_header_buttons', fn ($_header_buttons, $_report_id) => self::add_lock_export_button($_header_buttons, $_report_id), 10, 2);

        self::register_goals_funnels_header_hooks();

        // Sync index options with actual DB state — one SHOW INDEX for all of them, and none at
        // all once every option is stamped.
        //
        // This was the seventh single-index probe site and the only one on a per-request path:
        // up to six `SHOW INDEX … WHERE Key_name = 'x'` on every wp-admin and admin-ajax
        // request, five of them duplicating a key list the first already returned. It also
        // interacts badly with the honest stamping introduced alongside Schema::ensure(): the
        // old code stamped 'yes' whether or not the build succeeded, so the loop fell silent
        // after one pass, while a correctly-unstamped option on a large-table install would
        // have left this probing six times per request indefinitely.
        self::sync_index_options();

        self::register_index_hooks();

        // Register the combined notice
        add_action('admin_notices', ['wp_slimstat_admin', 'show_indexes_notice']);

        // Surface fail-soft degradations (issue #325) and retire stale ones.
        // admin-ajax.php also fires admin_init, but never renders admin_notices, so
        // reconciling there would add a wp_options read to every heartbeat and
        // autosave for nothing.
        if (!wp_doing_ajax()) {
            // BEFORE the reconciliation, at a lower priority. reconcile_degradations() prunes
            // records past DEGRADATION_TTL, so re-stating the drift afterwards would leave one
            // request showing a notice the pruner had just removed and the next showing none.
            // Stated first, pruned second — a still-drifted install never falls out of the notice.
            add_action('admin_init', ['wp_slimstat_admin', 'refresh_column_drift_notice'], 98);
            add_action('admin_init', ['wp_slimstat', 'reconcile_degradations'], 99);
            add_action('admin_notices', ['wp_slimstat_admin', 'show_degradation_notice']);
        }

        // Initialize notification system
        if (class_exists('SlimStat\\Services\\Admin\\Notification\\NotificationManager')) {
            new \SlimStat\Services\Admin\Notification\NotificationManager();
        }
        // Initialize cron manager for notifications
        if (class_exists('SlimStat\\Services\\CronEventManager')) {
            new \SlimStat\Services\CronEventManager();
        }
    }

    // END: init

    /**
     * Add style to the admin menu
     */
    public static function styling_admin_menu()
    {
        if (!wp_slimstat::pro_is_installed()) {
            echo '<style> a.wp-slimstat-upgrade-to-pro {background-color: #f22f46 !important;color: #fff !important;font-weight: 600 !important;} </style>';
        }
        // The time-limited "New" badge on the Goals & Funnels item renders in the
        // global sidebar, so its style must load on every admin page (not just
        // slimview6). Tiny, so always emit it. (#20)
        echo '<style> #adminmenu .slimstat-gf-new-badge {display:inline-block;margin-inline-start:6px;padding:0 6px;border-radius:9px;background:var(--wp-admin-theme-color,#2271b1);color:#fff;font-size:9px;font-weight:600;line-height:16px;text-transform:uppercase;letter-spacing:.03em;vertical-align:middle;} </style>';
    }

    /**
     * "New" badge HTML for the Goals & Funnels sidebar item, shown for 15 days
     * after the feature became available on this site, then it disappears.
     * Returns '' once the window elapses. The window is anchored the first time
     * the menu builds after this version ships, so existing installs start their
     * countdown then. (#20)
     */
    private static function goals_funnels_new_badge()
    {
        $since = (int) get_option('slimstat_goals_funnels_since', 0);
        if ($since <= 0) {
            $since = time();
            update_option('slimstat_goals_funnels_since', $since);
        }
        if ((time() - $since) >= (15 * DAY_IN_SECONDS)) {
            return '';
        }
        return ' <span class="slimstat-gf-new-badge">' . esc_html__('New', 'wp-slimstat') . '</span>';
    }

    /**
     * Clears every cron job this plugin schedules.
     *
     * The hook list lives in src/cron-hooks.php so this and uninstall.php cannot
     * drift apart — see the note there.
     */
    public static function deactivate()
    {
        foreach (require SLIMSTAT_DIR . '/src/cron-hooks.php' as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /**
     *  Reset layout
     */
    public static function handle_reset_layout()
    {
        // Check nonce
        if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'reset_layout')) {
            wp_die(__('Sorry, you are not allowed to access this page.', 'wp-slimstat'));
        }

        $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%meta-box-order_admin_page_slimlayout%%'", $GLOBALS['wpdb']->prefix));
        $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%mmetaboxhidden_admin_page_slimview%%'", $GLOBALS['wpdb']->prefix));
        $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%meta-box-order_slimstat%%'", $GLOBALS['wpdb']->prefix));
        $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%metaboxhidden_slimstat%%'", $GLOBALS['wpdb']->prefix));
        $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%closedpostboxes_slimstat%%'", $GLOBALS['wpdb']->prefix));

        // Redirect to layout page
        wp_safe_redirect(admin_url('admin.php?page=slimlayout'));
        die();
    }

    /**
     * Support for WP MU site deletion
     */
    public static function drop_tables($_tables = [], $_blog_id = 1)
    {
        // Derived from the manifest so a table added in Phase G cannot survive `wpmu_drop_tables`
        // — deleting a subsite would leave its analytics behind forever, on the one code path
        // where nobody looks afterwards. Schema::tables() is already in FK-safe order (children
        // first), which core preserves when it issues the DROPs.
        foreach (Schema::tables() as $suffix) {
            $_tables[$suffix] = $GLOBALS['wpdb']->prefix . $suffix;
        }

        return $_tables;
    }

    // END: drop_tables

    /**
     * Creates tables, initializes options and schedules purge cron
     */
    public static function init_environment()
    {
        if (function_exists('apply_filters')) {
            $my_wpdb = apply_filters('slimstat_custom_wpdb', $GLOBALS['wpdb']);
        }

        // Create the tables and reconcile every index in the manifest. The five probe-and-create
        // blocks that used to live here were four of the six independent index creators C11
        // enumerated; they duplicated entries init_tables() already handled, each with its own
        // `SHOW INDEX` round trip and its own unconditional "yes" stamp.
        self::init_tables($my_wpdb);

        // Initialize atomic visit ID counter (fix for issue #155 - performance regression)
        \SlimStat\Tracker\VisitIdGenerator::initializeCounter();

        // Hard-flush rewrite rules so the adblock bypass rewrite is written to .htaccess.
        // Caching plugins (WP Rocket, W3TC) route requests via .htaccess before WordPress
        // loads — a soft flush (false) only updates the DB and would not help.
        flush_rewrite_rules();

        return true;
    }

    // END: init_environment

    /**
     * Creates and populates tables, if they aren't already there.
     */
    public static function init_tables($_wpdb = '')
    {
        // One reconciliation, from the manifest, replacing four hand-written CREATE TABLEs and
        // a $index_defs loop that probed five indexes one statement at a time.
        //
        // Idempotent in both directions: it creates what is missing on a fresh install and adds
        // what is missing on an upgraded one. Those were separate, mutually exclusive code paths
        // before — a fresh install stamps the version before any admin page renders, so the
        // upgrade gate never fires, and an update never fires activation, so the create gate
        // never fires. That is how fresh installs ended up with 11 secondary indexes on
        // slim_stats and upgraded ones with 13 (C39).
        // The collation is passed as a closure, not a value: resolving it costs an
        // information_schema query and it is consumed only when a table must actually be
        // created, which on a healthy install is never.
        $prefix = $GLOBALS['wpdb']->prefix;
        $report = Schema::ensure(
            $_wpdb,
            $prefix,
            static function () {
                return Schema::targetCollation($GLOBALS['wpdb']);
            },
            self::disabled_index_groups()
        );

        // Let's save the version in the database
        if (empty(wp_slimstat::$settings['version'])) {
            wp_slimstat::$settings['version'] = SLIMSTAT_ANALYTICS_VERSION;
        }

        self::stamp_index_options($report['present'], $prefix);
        self::record_column_drift($report);

        // C48 — mint the install's identity beside its data, first-writer-wins. Here and
        // not inside ensure(): ensure() is a DDL reconciler the tracker's repair path also
        // runs, and identity should be minted by the admin path that owns the schema, on
        // the same handle the tables were just ensured on. Skipped when slim_meta failed
        // to create — writing identity into a missing table would only stamp failures.
        if ($_wpdb instanceof \wpdb && !in_array($prefix . 'slim_meta', $report['failed'], true)) {
            \SlimStat\Schema\Meta::ensureIdentity($_wpdb, $prefix);
        }

        return $report;
    }

    /**
     * Report column drift the reconciliation OBSERVED and deliberately did not repair (F4).
     *
     * `Schema::ensure()` reconciles tables and indexes and never columns — an `ALTER` here runs
     * on `admin_init`, and rebuilding a 443k-row fact table there is the hazard S7 removed. What
     * was missing was never the repair. It was that column drift was reportable by NOTHING: two
     * bespoke probes and a write-path column-dropper absorbed the symptom, and no code path could
     * say "this install's schema does not match the manifest".
     *
     * So it becomes a degradation — the existing mechanism for "we kept working, and something is
     * not right" — rather than a notice of its own. Both known cases are real and neither is
     * urgent: `ua_id` absent on an install that has not run the optional migration, and `email`
     * one character narrow on anything upgraded from below 4.8.2, whose version stamp means the
     * repaired block will never run again.
     *
     * PERSISTED DURABLY AS WELL AS RECORDED, and the second half is not belt-and-braces.
     *
     * The degradation channel HEALS BY FORGETTING: a record older than DEGRADATION_TTL (3 hours)
     * without a re-stamp is treated as "the failure stopped happening" and pruned. That rule is
     * right for `gdpr_banner`, which recurs on every front-end request and therefore re-stamps
     * itself. It is exactly wrong here, because ensure() runs only on activation and on a
     * version-gated upgrade — so a permanently drifted install would show this for one three-hour
     * window per plugin release and then look healthy for a year.
     *
     * So the drift is written where it cannot age out, and re-recorded from there on every
     * reconcile pass. Absence of the option is what clears it, which is the same shape C34 used
     * for the purge: the durable fact is the thing that is true, and the notice is synthesised
     * from it rather than being the only copy.
     *
     * @param array{columns_missing?:string[], columns_narrow?:array<string,string>} $report
     */
    private static function record_column_drift($report)
    {
        $drift = self::persist_column_drift(self::format_column_drift(
            $report['columns_missing'] ?? [],
            $report['columns_narrow'] ?? []
        ));

        if ([] === $drift) {
            return;
        }

        self::announce_column_drift($drift);
    }

    /**
     * Re-state the drift as a degradation, so the notice cannot age out beneath it.
     *
     * RE-DERIVED, NOT REPLAYED. The stored list is written only by init_tables(), which runs on
     * activation and on a version-gated upgrade — so nothing recomputed it after a migration
     * successfully added the column, and the notice re-stated drift that no longer existed until
     * the next plugin release. Re-observing here is what lets a completed migration clear it.
     *
     * THROTTLED to DEGRADATION_REFRESH, and the population is why. The `email` column is one
     * character narrow on every install upgraded from below 4.8.2 and F4 forbids repairing it, so
     * the drift option is PERMANENT on a large, healthy-in-every-other-respect slice of the
     * installed base — not on a broken few. Without the throttle each of those pays five
     * `SHOW COLUMNS` on every wp-admin page load, forever, to re-derive a list record_degradation()
     * will only re-stamp once an hour anyway. A healthy install still pays nothing at all: it has
     * no option and returns above.
     */
    public static function refresh_column_drift_notice()
    {
        $stored = get_option(self::COLUMN_DRIFT_OPTION, []);

        if (!is_array($stored) || [] === $stored) {
            return;
        }

        $current = $stored;

        if (false === get_transient(self::COLUMN_DRIFT_CHECK_TRANSIENT)) {
            set_transient(self::COLUMN_DRIFT_CHECK_TRANSIENT, 1, wp_slimstat::DEGRADATION_REFRESH);
            $current = self::persist_column_drift(self::observe_column_drift());
        }

        if ([] === $current) {
            return;
        }

        self::announce_column_drift($current);
    }

    /**
     * Store a drift list, or clear the option when there is none. Returns what it stored.
     *
     * One owner for the persistence rule, because there are two producers — the reconciliation
     * that observes drift and the admin_init pass that re-observes it — and `autoload=false` is
     * load-bearing at both: an option that joins `alloptions` is read on EVERY request, and this
     * one is read on admin screens only.
     *
     * @param string[] $drift
     *
     * @return string[]
     */
    private static function persist_column_drift(array $drift)
    {
        if ([] === $drift) {
            // Cleared by the ABSENCE of drift, not by the passage of time.
            delete_option(self::COLUMN_DRIFT_OPTION);

            return [];
        }

        update_option(self::COLUMN_DRIFT_OPTION, $drift, false);

        return $drift;
    }

    /**
     * Column drift as it is RIGHT NOW, in the shape persist_column_drift() stores.
     *
     * Reads only. `Schema::columnDrift()` issues one `SHOW COLUMNS` per reconciling table and
     * repairs nothing — deliberately not `Schema::ensure()`, which also creates tables and builds
     * indexes. Re-observing drift on `admin_init` must not be able to change the schema (F4, S7).
     *
     * @return string[]
     */
    private static function observe_column_drift()
    {
        $drift = Schema::columnDrift(
            \SlimStat\Migration\MigrationService::analyticsConnection(),
            $GLOBALS['wpdb']->prefix
        );

        return self::format_column_drift($drift['missing'], $drift['narrow']);
    }

    /**
     * Render qualified drift as the display lines the notice and the option both hold.
     *
     * Shared by both producers. The stored list and a freshly observed one are compared for
     * equality, so a formatting disagreement between them would not raise an error — it would
     * silently rewrite the option on every pass.
     *
     * @param string[]              $missing
     * @param array<string,string>  $narrow
     *
     * @return string[] sorted, so the (step, message) de-dupe in record_degradation() holds
     */
    private static function format_column_drift(array $missing, array $narrow)
    {
        $drift = [];

        foreach ($missing as $column) {
            $drift[] = $column . ' (absent)';
        }

        foreach ($narrow as $column => $widths) {
            $drift[] = sprintf('%s (%s)', $column, $widths);
        }

        // Sorted so the message is stable across runs: an unsorted list whose order follows
        // SHOW COLUMNS would defeat the (step, message) de-dupe and re-record on every pass.
        sort($drift);

        return $drift;
    }

    /** @param string[] $drift */
    private static function announce_column_drift(array $drift)
    {
        wp_slimstat::record_degradation(
            'schema column drift',
            sprintf(
                'these columns differ from the manifest: %s. Reports and tracking keep working; '
                    . 'affected fields may be absent or truncated.',
                implode(', ', $drift)
            ),
            wp_slimstat::DEGRADATION_OPERATIONAL
        );
    }

    /**
     * Optional-index groups the user has switched OFF.
     *
     * Settings -> Maintenance -> "Database Indexes" DROPs four indexes when toggled off. Without
     * this, ensure() would rebuild them on the next admin_init and the toggle would silently
     * reverse itself — a behaviour regression introduced by a refactor whose whole point was to
     * change no behaviour.
     *
     * @return string[]
     */
    private static function disabled_index_groups()
    {
        // Absent means ON: the shipped default is 'on', and an install whose settings row
        // predates the toggle must not be read as having opted out of four indexes.
        return ('no' === (wp_slimstat::$settings['db_indexes'] ?? 'on')) ? ['db_indexes'] : [];
    }

    /**
     * Stamp the `slimstat_*_indexed` options for indexes CONFIRMED present.
     *
     * Confirmed, not attempted. The old code stamped 'yes' unconditionally right after a
     * CREATE INDEX whose result it never checked — so an index build that timed out on a large
     * table still recorded success, and show_indexes_notice(), which reads these stamps to
     * decide whether to offer a retry button, could never offer one. The notice was blind to
     * exactly the failure it exists for.
     *
     * @param string[] $present Resolved index names confirmed on the table.
     * @param string   $prefix
     */
    private static function stamp_index_options(array $present, $prefix)
    {
        $confirmed = array_flip($present);

        foreach (Schema::tables() as $suffix) {
            foreach (array_keys(Schema::indexes($suffix)) as $index) {
                $option = Schema::indexOption($index);
                if (null !== $option && isset($confirmed[Schema::resolve($index, $prefix)])) {
                    update_option($option, 'yes');
                }
            }
        }
    }

    // END: init_tables

    /**
     * Updates stuff around as needed (table schema, options, settings, files, etc)
     *
     * Fail-soft wrapper. This runs on `admin_init` with nothing above it to catch a
     * throw, and the version stamp is the LAST statement in the body — so any
     * uncaught error here means the stamp never lands, the branch is re-entered on
     * the next request, and wp-admin white-screens permanently with no route out
     * from inside WordPress.
     *
     * That is not hypothetical: `unset($wp_slimstat::$settings[...])` in the <4.8.4
     * branch shipped exactly this shape (S1), and the ~180 lines below contain enough
     * DDL, filtered `$wpdb` handles and legacy branches to do it again.
     *
     * Deliberately does NOT stamp the version on failure. Stamping would make the
     * page load, but the column ALTERs in the legacy branches carry no per-step flag
     * of their own (unlike the index steps, which have `slimstat_*_indexed` /
     * `goals_indexes`), so a swallowed failure would skip them forever and leave the
     * table permanently missing `email` / `fingerprint` / `tz_offset`. Retrying a
     * visible failure is the lesser harm. Bounding that retry is separate work — it
     * needs the claim-lock and per-step stamping, not a wider catch here.
     */
    public static function update_tables_and_options()
    {
        if (!self::may_run_schema_ddl()) {
            return false;
        }

        if (!self::claim_schema_lock()) {
            return false;
        }

        try {
            return self::run_schema_upgrade();
        } catch (\Throwable $e) {
            wp_slimstat::record_degradation('schema upgrade', $e, wp_slimstat::DEGRADATION_OPERATIONAL);

            return false;
        } finally {
            // Every exit path, including the deliberate early return the notes
            // conversion uses to resume. A resumable migration that locks itself out
            // until the stale-lock timeout is not resumable.
            delete_option(self::SCHEMA_LOCK_OPTION);
        }
    }

    /**
     * May THIS request run schema DDL?
     *
     * `admin_init` is not "an admin page": wp-admin/admin-ajax.php fires it too, and
     * nothing else on the path checked a capability — wp-slimstat.php gates only on
     * is_user_logged_in(), and wp_slimstat_admin::init() on nothing. So a subscriber
     * opening /wp-admin/profile.php, a Heartbeat tick or an autosave could trigger
     * DROP COLUMN, four table rebuilds, up to nine index builds and a full-table UPDATE.
     *
     * Cron and REST are excluded for a different reason than capability: they are
     * background and third-party surfaces where nobody sees the outcome, and the
     * tracker itself is a REST route — running a multi-minute ALTER there is the
     * difference between an upgrade and an outage.
     *
     * Consequence worth stating: a site where nobody with manage_options ever loads
     * wp-admin now stays un-upgraded rather than being upgraded by a subscriber. That
     * is the correct trade — but it is a trade, and the durable fix is the migration
     * registry's own WP-CLI driver and admin page, not a wider gate here.
     *
     * Scope: this guards the version-gated UPGRADE only. The two CREATE-TABLE repair
     * paths — init() when the tables are missing, and the tracker's failed-INSERT
     * recovery — are deliberately left ungated for now. They are the safety net for
     * sites whose tables were never created, which is a live bug today (activation
     * hooks are registered inside `if (is_admin())`, so `wp plugin activate` creates
     * nothing). Gating them before fixing that would remove the net and the fall.
     * They need their own treatment: a one-shot claim and a narrower trigger than
     * "any INSERT failed".
     */
    private static function may_run_schema_ddl()
    {
        // The only abort a wp.org plugin can offer: no staged rollout, no canary, no
        // telemetry, no remote switch. This is one honour point; the migration runner
        // and its AJAX handler need their own.
        if (defined('SLIMSTAT_DISABLE_MIGRATIONS') && SLIMSTAT_DISABLE_MIGRATIONS) {
            return false;
        }

        if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return false;
        }

        return current_user_can('manage_options');
    }

    /**
     * Claim the single-flight lock, or decline.
     *
     * Deliberately NOT `add_option()`. Core's `add_option()` decides whether the option
     * exists with a PHP-level `get_option()` pre-check and then issues
     * `INSERT ... ON DUPLICATE KEY UPDATE`, which OVERWRITES (wp-includes/option.php).
     * The unique index never rejects anything, so two concurrent requests can both pass
     * the pre-check and both believe they hold the lock — the exact race this exists to
     * close. A raw INSERT that lets the `option_name` unique index reject the loser is
     * what actually makes the claim atomic.
     *
     * `wp_cache_add()` is the codebase's other claim idiom (the tracker's rate limiter),
     * but it is only atomic against a PERSISTENT object cache. Most wp.org installs have
     * none, where it degrades to a per-request array and would grant the lock to every
     * concurrent request. The index is the only thing present on every install.
     *
     * A run killed by max_execution_time never reaches the `finally` that releases,
     * so a stale claim is taken over — via a conditional UPDATE matching the exact
     * value observed, so two requests that both see the same stale claim cannot both
     * win. The staleness threshold is much longer than the upgrade's own wall-clock
     * budget: the budget bounds one request's work, while a killed request may have
     * left an ALTER running server-side after PHP has gone.
     *
     * `autoload = 'no'` is written directly because this bypasses the options API.
     * Verified outside `wp_autoload_values_to_autoload()` on both WP 5.6 and 7.0.
     */
    private static function claim_schema_lock()
    {
        global $wpdb;

        $now = time();

        $suppressed = $wpdb->suppress_errors(true);
        $claimed    = $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
            self::SCHEMA_LOCK_OPTION,
            (string) $now
        ));
        $wpdb->suppress_errors($suppressed);

        // The row is written behind the options API's back, so drop its caches.
        wp_cache_delete(self::SCHEMA_LOCK_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');

        if ($claimed) {
            return true;
        }

        $held = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s",
            self::SCHEMA_LOCK_OPTION
        ));

        if ($held > 0 && ($now - $held) < self::SCHEMA_LOCK_STALE_AFTER) {
            return false;
        }

        $took = $wpdb->query($wpdb->prepare(
            "UPDATE `{$wpdb->options}` SET option_value = %s
              WHERE option_name = %s AND option_value = %s",
            (string) $now,
            self::SCHEMA_LOCK_OPTION,
            (string) $held
        ));

        wp_cache_delete(self::SCHEMA_LOCK_OPTION, 'options');

        return (bool) $took;
    }

    /**
     * Convert pre-4.8.8 semicolon-separated `notes` to the bracketed `[k:v]` form.
     *
     * Returns true when the whole table is converted, false when it must resume.
     *
     * Batched rather than issued as one statement. The original was a single unbatched
     * `UPDATE ... WHERE notes NOT LIKE '[%'` with no bound: on a large table it cannot
     * finish inside max_execution_time, and because the schema version is stamped only
     * at the end of the upgrade, each attempt rolled back its undo and the whole
     * sequence restarted on the next admin request — an unbounded retry loop rather
     * than a failed upgrade.
     *
     * Two correctness fixes to the predicate:
     *   - `notes <> ''` — the empty string satisfies `NOT LIKE '[%'`, so the original
     *     rewrote empty notes to the literal '[]'. Measured 0 such rows on the
     *     reference table, but it is wrong wherever they exist.
     *   - `notes IS NOT NULL` — stated rather than relied upon. `NULL NOT LIKE '[%'`
     *     is NULL, so NULL rows were already excluded; making it explicit stops a
     *     later edit from turning 74% of the table into '[]'.
     *
     * Verified on the reference table: the boundary query plans as `type=range`,
     * `key=PRIMARY`, `Using index`, and returns NULL past the last row so the tail
     * terminates without a special case.
     *
     * KNOWN LIMITATION, shared with every other flag in this function: the cursor is a
     * local `wp_options` row while the rows it describes may live on a remote
     * connection (Pro's external-DB addon). Repointing that connection leaves a cursor
     * describing a different table. Fixing it properly needs the connection-keyed
     * schema-version scheme, not a local patch here.
     */
    private static function convert_notes_to_brackets($my_wpdb, $began)
    {
        $table = $GLOBALS['wpdb']->prefix . 'slim_stats';
        $state = get_option(self::NOTES_CURSOR_OPTION, []);

        $cursor  = isset($state['cursor']) ? (int) $state['cursor'] : 0;
        $ceiling = isset($state['ceiling']) ? (int) $state['ceiling'] : 0;

        // Pin the ceiling once, on the first pass, and carry it across resumes.
        //
        // Without it the loop chases its own tail: the tracker keeps inserting while the
        // migration runs, each new row gets a higher id, and the walk keeps finding more
        // to look at. Those rows are already in bracketed form — Processor builds `notes`
        // as an array and serialises it — so the UPDATE skips them, but the WALK does
        // not, and on a busy site that extends the ceiling for as long as the migration
        // lasts. Pinning defines the work set as "rows that existed when this started",
        // which makes termination a property of the code rather than a race the insert
        // rate happens to lose.
        if ($ceiling <= 0) {
            $ceiling = (int) $my_wpdb->get_var("SELECT MAX(id) FROM {$table}");
            if ($ceiling <= 0) {
                delete_option(self::NOTES_CURSOR_OPTION);

                return true; // empty table, nothing to convert
            }
        }

        while ($cursor < $ceiling) {
            // Walk ROWS, not id space. `id` is sparse after years of purges — measured
            // on the reference table, MAX(id) is 19,125,340 against 443,535 rows, a 43x
            // gap. A fixed id-span loop would issue 957 statements and 957 option
            // writes, ~96% of them over empty ranges; this issues 23. The subquery is
            // an index-only scan of at most NOTES_BATCH_SIZE entries, and it returns the
            // last id when fewer than a full batch remain, so the tail needs no special
            // case. Empty result => NULL => 0 => done.
            $upper = (int) $my_wpdb->get_var($my_wpdb->prepare(
                "SELECT MAX(id) FROM (SELECT id FROM {$table} WHERE id > %d AND id <= %d ORDER BY id LIMIT %d) AS batch",
                $cursor,
                $ceiling,
                self::NOTES_BATCH_SIZE
            ));

            if ($upper <= $cursor) {
                break;
            }

            $result = $my_wpdb->query($my_wpdb->prepare(
                "UPDATE {$table} SET notes = CONCAT( '[', REPLACE( notes, ';', '][' ), ']' )
                  WHERE id > %d AND id <= %d
                    AND notes IS NOT NULL AND notes <> '' AND notes NOT LIKE '[%%'",
                $cursor,
                $upper
            ));

            if (false === $result) {
                wp_slimstat::record_degradation(
                'notes format migration',
                $my_wpdb->last_error,
                wp_slimstat::DEGRADATION_OPERATIONAL
            );

                return false;
            }

            $cursor = $upper;
            update_option(self::NOTES_CURSOR_OPTION, ['cursor' => $cursor, 'ceiling' => $ceiling], false);

            if ((time() - $began) >= self::SCHEMA_UPGRADE_TIME_BUDGET) {
                return false;
            }
        }

        delete_option(self::NOTES_CURSOR_OPTION);

        return true;
    }

    /**
     * The schema/settings upgrade itself. Only ever called through the wrapper above.
     */
    private static function run_schema_upgrade()
    {
        $my_wpdb        = apply_filters('slimstat_custom_wpdb', $GLOBALS['wpdb']);
        $upgrade_began  = time();

        // --- Updates for version 4.8.2 ---
        if (version_compare(wp_slimstat::$settings['version'], '4.8.2', '<')) {
            // Add new email column to database.
            //
            // The width comes from the manifest, and it did not used to: this block declared
            // VARCHAR(255) while Schema declares VARCHAR(256), so an install upgraded from below
            // 4.8.2 and a fresh one had differently shaped `slim_stats` — C39's finding, on a
            // column instead of an index, and invisible to the gate because the gate knew every
            // DDL keyword except ADD COLUMN. See Schema::addColumnSql().
            // Written out rather than looped, and that is the point rather than an oversight:
            // the gate resolves each (table, column) pair against the loaded manifest, and it can
            // only do that for LITERAL arguments. A loop over `$suffix` is tidier source and
            // statically unreadable — which is precisely how a column nobody declared got added.
            $prefix = $GLOBALS['wpdb']->prefix;
            $my_wpdb->query(Schema::addColumnSql('slim_stats', 'email', $prefix, 'username'));
            $my_wpdb->query(Schema::addColumnSql('slim_stats_archive', 'email', $prefix, 'username'));
        }

        // --- END: Updates for version 4.8.2 ---

        // --- Updates for version 4.8.4 ---
        if (version_compare(wp_slimstat::$settings['version'], '4.8.4', '<')) {
            // Switch option to track WP users (from track to ignore)
            wp_slimstat::$settings['ignore_wp_users'] = (!empty(wp_slimstat::$settings['track_users']) && 'no' == wp_slimstat::$settings['track_users']) ? 'on' : 'no';

            // Remove unused options
            unset(wp_slimstat::$settings['track_users']);
            unset(wp_slimstat::$settings['enable_javascript']);
            unset(wp_slimstat::$settings['honor_dnt_header']);
            unset(wp_slimstat::$settings['no_maxmind_warning']);
            unset(wp_slimstat::$settings['no_browscap_warning']);
            unset(wp_slimstat::$settings['use_european_separators']);
            unset(wp_slimstat::$settings['date_format']);
            unset(wp_slimstat::$settings['time_format']);
            unset(wp_slimstat::$settings['expand_details']);

            // The four indexes this block used to add by hand are in the manifest, and the
            // single init_tables() call at the end of this function reconciles them along with
            // every other. Restating them here was one of the six creators C11 enumerated, and
            // the one that made a Phase E drop unholdable: an install upgrading from below 4.8.4
            // re-added four dropped indexes with no way for anything to know it had.
            wp_slimstat::$settings['db_indexes'] = 'on';
        }

        // --- END: Updates for version 4.8.4 ---

        // --- Updates for version 4.8.4.1 ---
        if (version_compare(wp_slimstat::$settings['version'], '4.8.4.1', '<')) {
            // Goodbye, browser plugins. Rendered from Schema, which refuses to drop anything the
            // manifest still declares — the same guard as the ADD side, pointing the other way.
            wp_slimstat::$wpdb->query(Schema::dropColumnSql('slim_stats', 'plugins', $GLOBALS['wpdb']->prefix));

            // Hello there, fingerprint and timezone offset.
            //
            // `fingerprint` was VARCHAR(256) on slim_stats and VARCHAR(255) on its own archive,
            // in two adjacent lines of this block. Both now render from the one declaration.
            $prefix = $GLOBALS['wpdb']->prefix;
            $my_wpdb->query(Schema::addColumnSql('slim_stats', 'fingerprint', $prefix, 'language'));
            $my_wpdb->query(Schema::addColumnSql('slim_stats_archive', 'fingerprint', $prefix, 'language'));
            $my_wpdb->query(Schema::addColumnSql('slim_stats', 'tz_offset', $prefix, 'outbound_resource'));
            $my_wpdb->query(Schema::addColumnSql('slim_stats_archive', 'tz_offset', $prefix, 'outbound_resource'));
        }

        // --- END: Updates for version 4.8.4.1 ---

        // --- Updates for version 4.8.8 ---
        if (version_compare(wp_slimstat::$settings['version'], '4.8.8', '<')) {
            // The fingerprint index this block used to add is in the manifest, and the
            // reconciliation below honours the same `db_indexes` setting this branch checked.

            if (!self::convert_notes_to_brackets($my_wpdb, $upgrade_began)) {
                // Incomplete or failed. Return WITHOUT stamping the version, so the
                // next admin request resumes from the stored cursor. Stamping here
                // would abandon every remaining row in a half-converted column.
                return false;
            }
        }

        // --- Updates for version 5.4.0 ---
        if (version_compare(wp_slimstat::$settings['version'], '5.4.0', '<')) {
            // Migrate legacy 'adblock' tracking method to 'adblock_bypass' (renamed in v5.3.0)
            if (!empty(wp_slimstat::$settings['tracking_request_method']) && 'adblock' === wp_slimstat::$settings['tracking_request_method']) {
                wp_slimstat::$settings['tracking_request_method'] = 'adblock_bypass';
            }

            // Default use_separate_menu to 'on' if not already set
            if (empty(wp_slimstat::$settings['use_separate_menu'])) {
                wp_slimstat::$settings['use_separate_menu'] = 'on';
            }
        }

        // --- Updates for version 5.4.1 ---
        // Fix admin bar migration: empty('no') returned false in 5.4.0, missing users with legacy 'no' value
        // Safe because this runs once (version bumps to 5.4.1 after), users who disable later are already on 5.4.1+
        if (version_compare(wp_slimstat::$settings['version'], '5.4.1', '<')) {
            wp_slimstat::$settings['use_separate_menu'] = 'on';
        }

        // --- Bring the schema up to the manifest ---
        //
        // ONE reconciliation for the whole upgrade, replacing the 5.4.3 dt_visit block, the
        // goals/funnels block and the 4.8.4/4.8.8 index adds above. Every index those blocks
        // created is declared in Schema, so an install arriving here from any version converges
        // on the same shape — which is precisely what did not happen before: the create path and
        // the upgrade path are mutually exclusive by construction, so fresh installs never got
        // the goal/funnel indexes and pre-5.4.0 installs never got the five $index_defs ones.
        //
        // Cost on a healthy install is one SHOW TABLES and one SHOW INDEX per table and no
        // writes, against the fourteen single-index probes across six call sites it replaces.
        $schema_report = self::init_tables($my_wpdb);

        // #318: only claim the goals indexes are done once all three are CONFIRMED present. A
        // large-table ALTER that times out leaves this unset, which is what makes
        // MigrationService surface its one-click retry.
        if (empty(wp_slimstat::$settings['goals_indexes'])) {
            $goal_indexes = ['idx_goal_queries', 'idx_funnel_queries', 'idx_events_notes_dt'];

            if ([] === array_diff($goal_indexes, $schema_report['present'])) {
                wp_slimstat::$settings['goals_indexes'] = 'on';
            }
        }

        // Clear stale query cache transients on upgrade to prevent data inconsistencies
        // (e.g., cached $pageviews causing percentage >100% in reports — see #270)
        //
        // The prefix here used to be `wp_slimstat_cache_`, which nothing writes. The keys
        // come from Query::getCacheKeyForQuery() and are prefixed `wp_slimstat_query_`, so
        // this DELETE matched zero rows on every upgrade, forever — measured on the
        // reference install: 0 rows matched the old LIKE against 2,146 that existed. The
        // stale-cache inconsistency it was written to prevent was never actually prevented,
        // and nothing else purges these.
        //
        // Batched rather than one unbounded DELETE, and batched rather than a single
        // LIMIT 1000. An install that has been accumulating since the prefix first drifted
        // can hold far more than one batch — this one held 2,146 — and a bare LIMIT would
        // leave the rest behind while reporting success. The loop is capped so a
        // pathological table cannot stall the upgrade.
        //
        // Shares the request's wall-clock budget with the notes conversion above.
        // Without that, the upgrade could carefully spend 10s converting notes and then
        // issue up to 50,000 more row deletes in the same request with no clock at all.
        for ($sweep = 0; $sweep < 50 && (time() - $upgrade_began) < self::SCHEMA_UPGRADE_TIME_BUDGET; $sweep++) {
            $deleted = $GLOBALS['wpdb']->query(
                "DELETE FROM {$GLOBALS['wpdb']->options}
                  WHERE option_name LIKE '\_transient\_wp\_slimstat\_query\_%'
                     OR option_name LIKE '\_transient\_timeout\_wp\_slimstat\_query\_%'
                  LIMIT 1000"
            );

            if (!$deleted) {
                break;
            }
        }

        // Rotate the goals/funnels cache version on upgrade so pre-fix cached
        // results (e.g. goal "uniques" that excluded NULL-fingerprint visitors)
        // are recomputed immediately rather than lingering for the 5–15 min
        // transient TTL after the uniques identity changed. (#3)
        update_option('slimstat_goals_cache_ver', (string) microtime(true), false);

        // Now we can update the version stored in the database
        wp_slimstat::$settings['version']            = SLIMSTAT_ANALYTICS_VERSION;
        wp_slimstat::$settings['notice_latest_news'] = 'on';
        wp_slimstat::update_option('slimstat_options', wp_slimstat::$settings);

        return true;
    }

    // END: update_tables_and_options

    public static function add_dashboard_widgets()
    {
        if (!self::can_view_stats()) {
            return;
        }

        // Initialize the new Reports system FIRST before legacy system loads
        \SlimStat\Reports\Bootstrap::get_instance()->init();

        // The Reports library is only loaded on the plugin's screens
        include_once(plugin_dir_path(__FILE__) . 'view/wp-slimstat-reports.php');
        wp_slimstat_reports::init();

        if (!empty(wp_slimstat_reports::$user_reports['dashboard']) && is_array(wp_slimstat_reports::$user_reports['dashboard'])) {
            foreach (wp_slimstat_reports::$user_reports['dashboard'] as $a_report_id) {
                if (empty(wp_slimstat_reports::$reports[$a_report_id])) {
                    continue;
                }
                // Force compact rendering on the WP Dashboard for goals/funnels so
                // drawer/builder/confirm-sheet markup never mounts inside the widget.
                // Mutation is kept local: we only re-bind the registry field when
                // registering this specific widget, avoiding cross-request leaks.
                if ('slim_p9_01' === $a_report_id || 'slim_p9_02' === $a_report_id) {
                    wp_slimstat_reports::$reports[$a_report_id]['callback_args']['is_widget'] = true;
                }
                wp_add_dashboard_widget($a_report_id, wp_slimstat_reports::$reports[$a_report_id]['title'], ['wp_slimstat_reports', 'callback_wrapper']);
            }
        }
    }

    // END: add_dashboard_widgets

    /**
     * Removes 'spammers' from the database when the corresponding comments are marked as spam
     */
    public static function remove_spam($_new_status = '', $_old_status = '', $_comment = '')
    {
        $my_wpdb = apply_filters('slimstat_custom_wpdb', $GLOBALS['wpdb']);

        if ('spam' == $_new_status && !empty($_comment->comment_author) && !empty($_comment->comment_author_IP)) {
            $my_wpdb->query(wp_slimstat::$wpdb->prepare("
				DELETE ts
				FROM {$GLOBALS['wpdb']->prefix}slim_stats ts
				WHERE username = %s OR INET_NTOA(ip) = %s", $_comment->comment_author, $_comment->comment_author_IP));
        }
    }

    // END: remove_spam

    /**
     * Loads a custom stylesheet file for the administration panels
     */
    public static function wp_slimstat_stylesheet($_hook = '')
    {
        wp_register_style('wp-slimstat', plugins_url('/admin/assets/css/admin.css', __DIR__), false, SLIMSTAT_ANALYTICS_VERSION);
		wp_enqueue_style('wp-slimstat');

		wp_register_style(
			'wp-slimstat-header-modern',
			plugins_url('/admin/assets/css/header-modern.css', __DIR__),
			['wp-slimstat'],
			SLIMSTAT_ANALYTICS_VERSION
		);
		wp_enqueue_style('wp-slimstat-header-modern');

		// Goals & Funnels CSS — only loaded on screens that actually render those reports.
		// Honors slimlayout/Customize drag by inspecting the user's resolved report layout.
		if (self::needs_goals_funnels_assets()) {
			wp_register_style(
				'wp-slimstat-tokens',
				plugins_url('/admin/assets/css/tokens.css', __DIR__),
				[],
				SLIMSTAT_ANALYTICS_VERSION
			);
			wp_enqueue_style('wp-slimstat-tokens');

			wp_register_style(
				'wp-slimstat-goals-funnels',
				plugins_url('/admin/assets/css/goals-funnels.css', __DIR__),
				['wp-slimstat', 'wp-slimstat-tokens'],
				SLIMSTAT_ANALYTICS_VERSION
			);
			wp_enqueue_style('wp-slimstat-goals-funnels');
		}

        if (!empty(wp_slimstat::$settings['custom_css'])) {
            wp_add_inline_style('wp-slimstat', wp_slimstat::$settings['custom_css']);
        }
    }

    /**
     * Returns true when the current admin context renders slim_p9_01 or slim_p9_02.
     * Covers the direct slimview6 page, the WP dashboard, and screens that have
     * Goals/Funnels dragged in via the Customizer.
     *
     * @since 5.5.0
     */
    public static function needs_goals_funnels_assets()
    {
        // Only memoize `true` — a `false` answer is provisional until the reports
        // registry has loaded. Caching `false` too early (e.g. during
        // admin_enqueue_scripts on index.php, before wp_slimstat_reports::init()
        // runs) would cause the dashboard widget path to miss its own assets.
        static $memo = null;
        if ($memo === true) {
            return true;
        }

        if (!empty($_GET['page']) && 'slimview6' === $_GET['page']) {
            return $memo = true;
        }

        if (!class_exists('wp_slimstat_reports', false)) {
            return false;
        }

        $pagenow = $GLOBALS['pagenow'] ?? '';
        if ('index.php' === $pagenow) {
            $dashboard_reports = wp_slimstat_reports::$user_reports['dashboard'] ?? [];
            if (in_array('slim_p9_01', (array) $dashboard_reports, true)
                || in_array('slim_p9_02', (array) $dashboard_reports, true)) {
                return $memo = true;
            }
        }

        $current = self::$current_screen;
        if (!empty($current)) {
            $reports_on_screen = wp_slimstat_reports::$user_reports[$current] ?? [];
            if (in_array('slim_p9_01', (array) $reports_on_screen, true)
                || in_array('slim_p9_02', (array) $reports_on_screen, true)) {
                return $memo = true;
            }
        }

        return false;
    }

    /**
     * Emits the Goals & Funnels shared DOM (confirm sheet, goal drawer, funnel
     * builder) once per admin page, gated on the same helper as asset enqueue.
     *
     * @since 5.5.0
     */
    public static function print_goals_funnels_dom()
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        if (!self::needs_goals_funnels_assets()) {
            return;
        }

        $printed         = true;
        $dimensions      = self::get_goal_dimensions();
        // Funnel steps offer only action-oriented dimensions; goals keep the full
        // list (a "Country = gb" goal is legitimate). (#17)
        $funnel_step_dimensions = self::get_funnel_step_dimensions();
        $operators       = self::get_goal_operators();
        $operator_labels = self::get_goal_operator_labels();

        $partials_dir = plugin_dir_path(__FILE__) . 'view/partials/goals-funnels/';
        include $partials_dir . 'confirm-sheet.php';
        include $partials_dir . 'goal-drawer.php';
        include $partials_dir . 'funnel-builder.php';
    }

    // END: wp_slimstat_stylesheet

    /**
     * Adds a shared body class to all Slimstat admin screens.
     */
    public static function add_admin_body_class($classes)
    {
        return $classes . ' slimstat-admin-page';
    }

    /**
     * Loads user-defined stylesheet code
     */
    public static function wp_slimstat_userdefined_stylesheet()
    {
        echo '<style type="text/css" media="screen">' . wp_slimstat::$settings['custom_css'] . '</style>';
    }

    // END: wp_slimstat_userdefined_stylesheet

    /**
     * Enqueues Javascript and styles needed in the admin
     */
    public static function wp_slimstat_enqueue_scripts($_hook = '')
    {
        $current_screen = get_current_screen();
        if ($current_screen && false !== strpos((string) ($current_screen->id ?? ''), 'slim')) {
            wp_enqueue_script('dashboard');
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_script('jquery-ui-sortable');
        }

        // Enqueue the built-in code editor to use on the Settings
        if ($current_screen) {
            wp_enqueue_code_editor(['type' => 'text/html']);
        }

        // Enqueue date range picker assets for report pages
        $should_load_datepicker = false;
        if (isset($_GET['page'])) {
            $page = sanitize_text_field($_GET['page']);
            if (false !== strpos($page, 'slim') && false === strpos($page, 'setting')) {
                $should_load_datepicker = true;
            }
        }

        if ($should_load_datepicker) {

            // Enqueue moment.js
            wp_enqueue_script('slimstat-moment', plugins_url('/admin/assets/js/daterangepicker/moment.min.js', __DIR__), [], '2.30.2', true);

            // Enqueue daterangepicker
            wp_enqueue_script('slimstat-daterangepicker', plugins_url('/admin/assets/js/daterangepicker/daterangepicker.min.js', __DIR__), ['jquery', 'slimstat-moment'], '3.1.0', true);

            // Enqueue our custom date picker
            wp_enqueue_script('slimstat-custom-datepicker', plugins_url('/admin/assets/js/daterangepicker/slimstat-daterangepicker.js', __DIR__), ['jquery', 'slimstat-daterangepicker'], SLIMSTAT_ANALYTICS_VERSION, true);

            // Enqueue date picker styles
            wp_enqueue_style('slimstat-daterangepicker-base', plugins_url('/admin/assets/css/daterangepicker/daterangepicker.css', __DIR__), [], '3.1.0');
            wp_enqueue_style('slimstat-daterangepicker-custom', plugins_url('/admin/assets/css/daterangepicker/slimstat-datepicker-styles.css', __DIR__), ['slimstat-daterangepicker-base'], SLIMSTAT_ANALYTICS_VERSION);

            // Localize date picker script
            $datepicker_params = [
                'ajax_url' => admin_url('admin-ajax.php'),
                'clear_cache_nonce' => wp_create_nonce('slimstat_clear_cache'),
                'options' => [
                    'wp_timezone' => DateRangeHelper::get_wp_timezone(),
                    'start_of_week' => DateRangeHelper::get_week_start(),
                    'date_format' => DateRangeHelper::get_date_format()
                ],
                'strings' => DateRangeHelper::get_localized_strings()
            ];
            wp_localize_script('slimstat-custom-datepicker', 'SlimStatDatePicker', $datepicker_params);
        }

        // Shared wp.i18n accessor (window.wpSlimstatI18n) for every admin script
        // that carries translatable strings. Depends on wp-i18n; the scripts below
        // depend on this handle so the accessor is defined before they run.
        wp_enqueue_script('slimstat-i18n', plugins_url('/admin/assets/js/i18n.js', __DIR__), ['wp-i18n'], SLIMSTAT_ANALYTICS_VERSION, true);

        // slimstat-i18n dependency + script translations so admin.js's __() strings
        // (combobox labels, etc.) load their JSON translations at runtime. Without
        // this, the strings are extracted into the .pot but never translated.
        wp_enqueue_script('slimstat_admin', plugins_url('/admin/assets/js/admin.js', __DIR__), ['jquery-ui-dialog', 'slimstat-i18n'], SLIMSTAT_ANALYTICS_VERSION, true);
        self::set_slimstat_script_translations('slimstat_admin');

        // Enqueue notification assets if notifications are enabled
        if (wp_slimstat::$settings['display_notifications'] == 'on') {
            wp_enqueue_style('slimstat_notifications', plugins_url('/admin/assets/css/notifications.css', __DIR__), [], SLIMSTAT_ANALYTICS_VERSION);
            wp_enqueue_style('slimstat_header_notifications', plugins_url('/admin/assets/css/header-notifications.css', __DIR__), [], SLIMSTAT_ANALYTICS_VERSION);
            wp_enqueue_script('slimstat_notifications', plugins_url('/admin/assets/js/notifications.js', __DIR__), ['jquery'], SLIMSTAT_ANALYTICS_VERSION, false);

            // Pass notification data to Javascript
            $notification_params = [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('wp_rest'),
            ];
            wp_localize_script('slimstat_notifications', 'slimstat_admin', $notification_params);
        }

        // Pass some information to Javascript
        $params = [
            'async_load'        => empty(wp_slimstat::$settings['async_load']) ? 'no' : wp_slimstat::$settings['async_load'],
            'datepicker_image'  => plugins_url('/admin/assets/images/datepicker.png', __DIR__),
            'refresh_interval'  => intval(wp_slimstat::$settings['refresh_interval']),
            'page_location'     => self::$page_location,
            'clear_cache_nonce' => wp_create_nonce('slimstat_clear_cache'),
            'goals_nonce'       => wp_create_nonce('slimstat_goals_nonce'),
            'ajax_url'          => admin_url('admin-ajax.php'),
            // Shared with the filter form-builder so value-less operators (is_empty/
            // is_not_empty) are never treated as a "remove filter" signal. See #305.
            // Guarded: this method also runs on the Dashboard-widget path, where
            // wp_slimstat_db may not be included — fall back to the literal list.
            'valueless_operators' => class_exists('wp_slimstat_db') ? wp_slimstat_db::$valueless_operators : ['is_empty', 'is_not_empty'],
            // Canonical date/misc filter keys, shared with SlimStatGetFiltersForAjax() so it
            // strips the same non-column keys when harvesting filters for a sub-report (#22).
            'non_column_filter_keys' => class_exists('wp_slimstat_db')
                ? wp_slimstat_db::NON_COLUMN_FILTER_KEYS
                : ['strtotime', 'minute', 'hour', 'day', 'month', 'year', 'interval', 'interval_hours', 'interval_minutes', 'limit_results', 'start_from'],
            // WP-locale number separators so JS-rendered (lazily-loaded) funnel tabs
            // match the server's number_format_i18n() output instead of the browser
            // locale's toLocaleString().
            'number_format'       => [
                'decimal_point' => is_object($GLOBALS['wp_locale'] ?? null) ? ($GLOBALS['wp_locale']->number_format['decimal_point'] ?? '.') : '.',
                'thousands_sep' => is_object($GLOBALS['wp_locale'] ?? null) ? ($GLOBALS['wp_locale']->number_format['thousands_sep'] ?? ',') : ',',
            ],
            // Network-scope handshake for Pro's Network View, which UNIONs every
            // subsite's data into one report. admin-ajax.php carries no screen
            // context, so the network screen has to say which scope it wants —
            // explicitly. It used to be inferred from the Referer header, which the
            // client controls, so any subsite Administrator could ask for the whole
            // network. Minted only for a user who already holds the capability, and
            // Pro re-checks that capability server-side: this parameter selects
            // scope, it never grants it. Empty everywhere else, which means
            // single-site — the safe default.
            //
            // The capability is the one stats_view_capability() already returns on a
            // network screen. Both sides must name the same one, or a user who can
            // open the network report gets main-site numbers under a network heading
            // with nothing anywhere saying so.
            'network_scope_nonce' => (is_multisite() && is_network_admin() && current_user_can('manage_network'))
                ? wp_create_nonce('slimstat_network_scope')
                : '',
        ];
        wp_localize_script('slimstat_admin', 'SlimStatAdminParams', $params);

        // Goals & Funnels AJAX handlers — gated to screens that actually render those reports.
        if (self::needs_goals_funnels_assets()) {
            wp_enqueue_script(
                'slimstat-goals-funnels',
                plugins_url('/admin/assets/js/goals-funnels.js', __DIR__),
                ['jquery', 'slimstat_admin', 'slimstat-i18n'],
                SLIMSTAT_ANALYTICS_VERSION,
                true
            );
            self::set_slimstat_script_translations('slimstat-goals-funnels');
        }
    }

    // END: wp_slimstat_enqueue_scripts

    /**
     * Registers JS translations for one of our scripts so its wp.i18n strings
     * load their JSON language pack at runtime. Shared by every enqueued script
     * that carries translatable strings (admin.js, goals-funnels.js).
     */
    private static function set_slimstat_script_translations(string $handle): void
    {
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'wp-slimstat', plugin_dir_path(__DIR__) . 'languages');
        }
    }

    /**
     * Adds a new entry in the admin menu, to view the stats
     */
    public static function add_menus($_s = '')
    {
        global $submenu;

        $minimum_capability = self::stats_view_capability();

        // Find the first available location (screens with no reports assigned to them are hidden from the nav)
		$parent = '';
		if (is_array(self::$meta_user_reports)) {
			foreach (self::$screens_info as $a_screen_id => $a_screen_info) {
				if (!empty(self::$meta_user_reports[$a_screen_id]) && $a_screen_info['show_in_sidebar']) {
					$parent = $a_screen_id;
					break;
				}
			}
		}

		// If no parent was found in the user meta, use the first available screen as the parent
		if (empty($parent) && !empty(self::$screens_info)) {
			$parent = array_key_first(self::$screens_info);
		}

		// Don't show the menu if no screens are available at all
		if (empty($parent) || !isset(self::$screens_info[$parent])) {
			return null;
		}

		self::$main_menu_slug = $parent;

        // Build menu title with notification badge
        $menu_title = __('SlimStat', 'wp-slimstat');
        if (class_exists(NotificationFactory::class) && wp_slimstat::$settings['display_notifications'] === 'on') {
            $notification_count = NotificationFactory::getNewNotificationCount();
            if ($notification_count > 0) {
                $menu_title .= sprintf(
                    ' <span class="update-plugins count-%d"><span class="plugin-count">%s</span></span>',
                    $notification_count,
                    number_format_i18n($notification_count)
                );
            }
        }

        // Add the main menu
        add_menu_page(
            __('SlimStat', 'wp-slimstat'),
            $menu_title,
            $minimum_capability,
            $parent,
            [self::class, 'wp_slimstat_include_view'],
            'dashicons-chart-area'
        );

        foreach (self::$screens_info as $a_screen_id => $a_screen_info) {
            if (isset(self::$meta_user_reports[$a_screen_id]) && empty(self::$meta_user_reports[$a_screen_id])) {
                continue;
            }

            $minimum_capability = 'read';
            if (!empty($a_screen_info['capability']) && false === strpos(wp_slimstat::$settings[$a_screen_info['capability']], (string) $GLOBALS['current_user']->user_login) && !empty(wp_slimstat::$settings['capability_' . $a_screen_info['capability']])) {
                $minimum_capability = wp_slimstat::$settings['capability_' . $a_screen_info['capability']];
            }

            if ($a_screen_info['show_in_sidebar']) {
                // Sidebar label may carry the time-limited "New" badge; the page
                // title (browser tab) stays plain. (#20)
                $menu_label = $a_screen_info['title'];
                if ('slimview6' === $a_screen_id) {
                    $menu_label .= self::goals_funnels_new_badge();
                }
                $new_entry[] = add_submenu_page(
                    $parent,
                    $a_screen_info['title'],
                    $menu_label,
                    $minimum_capability,
                    $a_screen_id,
                    $a_screen_info['callback']
                );
            }
        }

        if (isset($submenu[$parent])) {
            array_walk($submenu[$parent], function (&$item) {
                if (isset($item[2]) && 'slimpro' === $item[2]) {
                    $item[4] = isset($item[4]) ? $item[4] . ' wp-slimstat-upgrade-to-pro' : ' wp-slimstat-upgrade-to-pro';
                }
            });
        }

        // Load styles and Javascript needed to make the reports look nice and interactive
        foreach ($new_entry as $a_entry) {
            add_action('load-' . $a_entry, [self::class, 'wp_slimstat_stylesheet']);
            add_action('load-' . $a_entry, [self::class, 'wp_slimstat_enqueue_scripts']);
            add_action('load-' . $a_entry, [self::class, 'contextual_help']);
            add_action('load-' . $a_entry, function () {
                add_filter('admin_body_class', [wp_slimstat_admin::class, 'add_admin_body_class']);
            });
        }

        return $_s;
    }

    // END: add_menus

    /**
     * Enqueue admin bar modal styles globally (admin + frontend)
     */
    /**
     * Whether the current user may see SlimStat's stats.
     *
     * One predicate for a question that had three answers: add_menu_to_adminbar()
     * computed a capability including the network-admin branch, check_ajax_view_capability()
     * computed the same thing without it, and enqueue_adminbar_styles() did not ask at
     * all — so a logged-in subscriber downloaded admin-bar-modal.css (14.8 KB) and
     * adminbar-realtime.js (6.9 KB), paid a wp_create_nonce(), and then polled
     * admin-ajax.php every minute forever for a menu that never rendered. The JS guards
     * only on its localize blob being present, not on the menu existing in the DOM.
     *
     * Six call sites computed this inline — the admin bar, the dashboard widget, the
     * admin menu and three AJAX handlers — and only two of them carried the
     * network-admin branch. Adding it everywhere is a no-op for the AJAX and dashboard
     * paths: admin-ajax.php is not a network-admin screen, so is_network_admin() is
     * false there either way.
     *
     * @since 5.6.0
     * @return bool
     */
    private static function can_view_stats()
    {
        return current_user_can(self::stats_view_capability());
    }

    /**
     * The capability a user needs to see SlimStat's stats.
     *
     * The admin menu needs the capability itself (add_menu_page() takes one); the admin
     * bar, its assets and the AJAX handler need only the yes/no. Four byte-identical
     * copies of this computation existed, and they had already drifted — the AJAX one
     * omitted the network-admin branch.
     *
     * @since 5.6.0
     * @return string
     */
    private static function stats_view_capability()
    {
        // Guarded like the plugin's other is_network_admin() call: this predicate is now
        // consulted from wp_enqueue_scripts on the front end, and the AJAX handlers,
        // where the admin-context helpers are not guaranteed to be loaded. Without the
        // guard the funnel AJAX endpoints fatal.
        if (function_exists('is_network_admin') && is_network_admin()) {
            return 'manage_network';
        }

        // A whitelisted user gets the minimum capability instead of the configured one.

        $whitelisted = false !== strpos(
            (string) wp_slimstat::$settings['can_view'],
            (string) ($GLOBALS['current_user']->user_login ?? '')
        );

        if (!$whitelisted && !empty(wp_slimstat::$settings['capability_can_view'])) {
            return wp_slimstat::$settings['capability_can_view'];
        }

        return 'read';
    }

    public static function enqueue_adminbar_styles()
    {
        // Gated on the same capability the menu itself is: these assets exist only to
        // style and refresh that menu, and the answer is already known here.
        if (is_admin_bar_showing() && self::can_view_stats()) {
            wp_enqueue_style(
                'slimstat-adminbar',
                plugins_url('/admin/assets/css/admin-bar-modal.css', __DIR__),
                [],
                SLIMSTAT_ANALYTICS_VERSION
            );

            // Enqueue admin bar realtime JS for stats auto-refresh (frontend + admin)
            // On frontend: self-polls every minute
            // On admin: defers to admin.js slimstat:minute_pulse
            wp_enqueue_script(
                'slimstat-adminbar-realtime',
                plugins_url('/admin/assets/js/adminbar-realtime.js', __DIR__),
                [],
                SLIMSTAT_ANALYTICS_VERSION,
                true
            );

            wp_localize_script('slimstat-adminbar-realtime', 'SlimStatAdminBar', [
                'ajax_url'  => admin_url('admin-ajax.php'),
                'security'  => wp_create_nonce('meta-box-order'),
                'is_pro'    => wp_slimstat::pro_is_installed(),
                'i18n'      => [
                    'was_last_day' => esc_html__('was %s last day', 'wp-slimstat'),
                    'online_users' => esc_html__('Online Users', 'wp-slimstat'),
                    'count_label'  => esc_html__('Count', 'wp-slimstat'),
                    'now'          => esc_html__('Now', 'wp-slimstat'),
                    'min_ago'      => esc_html__('min ago', 'wp-slimstat'),
                ],
            ]);
        }
    }

    // END: enqueue_adminbar_styles

    /**
     * Adds a new entry in the WordPress Admin Bar with stats modal
     */
    public static function add_menu_to_adminbar()
    {
        if (!self::can_view_stats()) {
            return;
        }

        // This runs on every logged-in FRONTEND pageview, so it reads from the
        // shared 60-second cache rather than querying. See adminbar_today_stats().
        $today_stats         = self::adminbar_today_stats();
        $sessions_today      = $today_stats['sessions'];
        $views_today         = $today_stats['views'];
        $sessions_yesterday  = $today_stats['sessions_yesterday'];
        $views_yesterday     = $today_stats['views_yesterday'];
        $referrals_today     = $today_stats['referrals'];
        $referrals_yesterday = $today_stats['referrals_yesterday'];

        $online_count = self::online_count();

        // Determine premium status early (needed for chart data)
        $is_pro = wp_slimstat::pro_is_installed();

        // Query minute-by-minute data for the CSS bar chart (30-minute window)
        // Reuse LiveAnalyticsReport's session-spanning query for consistent data (#221)
        if ($is_pro) {
            $live_report  = new \SlimStat\Reports\Types\Analytics\LiveAnalyticsReport();
            $chart_result = $live_report->get_users_chart_data();
            $minute_data  = $chart_result['data'];
            $max_count    = $chart_result['max_value'];
        } else {
            // Fake placeholder data for non-Pro users
            $minute_data = [3, 5, 4, 7, 6, 8, 5, 9, 7, 6, 8, 10, 7, 5, 6, 8, 9, 7, 6, 5, 8, 10, 9, 7, 6, 8, 5, 7, 6, 8];
            $max_count = 10;
        }

        // Build chart HTML
        $chart_bars = '';
        $total_bars = count($minute_data);
        foreach ($minute_data as $i => $count) {
            // Multiply first, divide once, round once — `($count / $max_count) * 100` rounds
            // twice and loses the exact half (23/40 arrives as 57.49999999999999289457, so
            // PHP 8.4+ draws the bar at 57% where the ratio is 57.5%). $max_count is the
            // maximum of the series. This site has never carried a zero guard, and one is NOT
            // added here: both forms divide by $max_count exactly once, so the behaviour when
            // it is 0 is identical before and after. Saying so rather than implying a safety
            // this line does not provide. ADR-17; PITFALLS 72.
            $height_pct = round((100 * $count) / $max_count);
            $is_peak = ($count === $max_count && $count > 0);
            $bar_class = $is_peak ? ' slimstat-adminbar__chart-bar--peak' : '';
            $minutes_ago = $total_bars - 1 - $i; // 29 for first bar, 0 for last bar
            $time_text = $minutes_ago === 0
                ? esc_html__('Now', 'wp-slimstat')
                : sprintf('%d %s', $minutes_ago, esc_html__('min ago', 'wp-slimstat'));
            $chart_bars .= sprintf(
                '<div class="slimstat-adminbar__chart-bar%s" style="height:%d%%" data-count="%d" data-minutes-ago="%d">'
                . '<span class="slimstat-adminbar__chart-tooltip">'
                . '<strong>%s</strong>'
                . '%s: %d<br>'
                . '%s'
                . '</span></div>',
                $bar_class,
                $count > 0 ? max($height_pct, 3) : 0, // 0% for empty, min 3% for non-zero
                $count,
                $minutes_ago,
                esc_html__('Online Users', 'wp-slimstat'),
                esc_html__('Count', 'wp-slimstat'),
                $count,
                $time_text
            );
        }
        $view_url = get_admin_url($GLOBALS['blog_id'], 'admin.php?page=');
        $overview_url = $view_url . 'slimview2';
        $upgrade_url = 'https://wp-slimstat.com/pricing/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=adminbar';

        // Add parent node
        $GLOBALS['wp_admin_bar']->add_menu([
            'id'    => 'slimstat-header',
            'title' => '<span class="ab-icon dashicons dashicons-chart-area" style="font-size:1rem;margin-top:3px"></span>'
                     . sprintf(__('Online: %s', 'wp-slimstat'), '<span id="slimstat-adminbar-online-header">' . number_format_i18n($online_count) . '</span>'),
            'href'  => $overview_url,
        ]);

        // Add stats grid node
        // For non-Pro users, show fake data for Views and Referrals
        $views_display = $is_pro ? number_format_i18n($views_today) : '248';
        $views_yesterday_display = $is_pro ? number_format_i18n($views_yesterday) : '312';
        $referrals_display = $is_pro ? number_format_i18n($referrals_today) : '18';
        $referrals_yesterday_display = $is_pro ? number_format_i18n($referrals_yesterday) : '24';
        $blur_class = $is_pro ? '' : ' slimstat-adminbar__stat-card--blur';

        $stats_html = '<div class="slimstat-adminbar__stats-grid">'
            // Online Users (top left)
            . '<div class="slimstat-adminbar__stat-card">'
            . '<div class="slimstat-adminbar__stat-title">' . esc_html__('Online Users', 'wp-slimstat')
            . ' <span class="slimstat-adminbar__realtime-dot"></span></div>'
            . '<div class="slimstat-adminbar__stat-count" id="slimstat-adminbar-online-count">' . number_format_i18n($online_count) . '</div>'
            . '<div class="slimstat-adminbar__realtime-badge">'
            . '<span class="slimstat-adminbar__realtime-pulse"></span> '
            . esc_html__('Realtime', 'wp-slimstat') . '</div>'
            . '</div>'
            // Sessions Today (top right)
            . '<div class="slimstat-adminbar__stat-card">'
            . '<div class="slimstat-adminbar__stat-title">' . esc_html__('Sessions Today', 'wp-slimstat') . '</div>'
            . '<div class="slimstat-adminbar__stat-count" id="slimstat-adminbar-sessions-count">' . number_format_i18n($sessions_today) . '</div>'
            . '<div class="slimstat-adminbar__stat-comparison" id="slimstat-adminbar-sessions-compare">'
            . sprintf(esc_html__('was %s last day', 'wp-slimstat'), number_format_i18n($sessions_yesterday))
            . '</div></div>'
            // Views Today (bottom left) - blur for non-Pro
            . '<div class="slimstat-adminbar__stat-card' . $blur_class . '">'
            . '<div class="slimstat-adminbar__stat-title">' . esc_html__('Views Today', 'wp-slimstat') . '</div>'
            . '<div class="slimstat-adminbar__stat-count" id="slimstat-adminbar-views-count">' . $views_display . '</div>'
            . '<div class="slimstat-adminbar__stat-comparison" id="slimstat-adminbar-views-compare">'
            . sprintf(esc_html__('was %s last day', 'wp-slimstat'), $views_yesterday_display)
            . '</div></div>'
            // Referrals Today (bottom right) - blur for non-Pro
            . '<div class="slimstat-adminbar__stat-card' . $blur_class . '">'
            . '<div class="slimstat-adminbar__stat-title">' . esc_html__('Referrals Today', 'wp-slimstat') . '</div>'
            . '<div class="slimstat-adminbar__stat-count" id="slimstat-adminbar-referrals-count">' . $referrals_display . '</div>'
            . '<div class="slimstat-adminbar__stat-comparison" id="slimstat-adminbar-referrals-compare">'
            . sprintf(esc_html__('was %s last day', 'wp-slimstat'), $referrals_yesterday_display)
            . '</div></div>'
            . '</div>';

        $GLOBALS['wp_admin_bar']->add_node([
            'id'     => 'slimstat-adminbar-stats',
            'parent' => 'slimstat-header',
            'title'  => $stats_html,
            'meta'   => ['class' => 'slimstat-adminbar__stats-wrapper'],
        ]);

        // Add chart node
        $chart_wrapper_class = $is_pro ? 'slimstat-adminbar__chart-container' : 'slimstat-adminbar__chart-container slimstat-adminbar__chart-blur';
        $chart_html = '<div class="' . $chart_wrapper_class . '">'
            . '<div class="slimstat-adminbar__chart-bars" id="slimstat-adminbar-chart-bars">' . $chart_bars . '</div>'
            . '</div>';

        $GLOBALS['wp_admin_bar']->add_node([
            'id'     => 'slimstat-adminbar-chart',
            'parent' => 'slimstat-header',
            'title'  => $chart_html,
            'meta'   => ['class' => 'slimstat-adminbar__chart-wrapper'],
        ]);

        // Add CTA node (free users only)
        if (!$is_pro) {
            $cta_html = '<div class="slimstat-adminbar__cta">'
                . '<div class="slimstat-adminbar__cta-text">'
                . esc_html__('Unlock the Full Power of SlimStat Analytics', 'wp-slimstat')
                . '</div>'
                . '<a href="' . esc_url($upgrade_url) . '" target="_blank" class="slimstat-adminbar__cta-button">'
                . esc_html__('Unlock SlimStat Pro', 'wp-slimstat') . '</a>'
                . '</div>';

            $GLOBALS['wp_admin_bar']->add_node([
                'id'     => 'slimstat-adminbar-cta',
                'parent' => 'slimstat-header',
                'title'  => $cta_html,
                'meta'   => ['class' => 'slimstat-adminbar__cta-wrapper'],
            ]);
        }

        // Add footer node
        $footer_html = '<div class="slimstat-adminbar__footer">'
            . '<div class="slimstat-adminbar__footer-logo">'
            . '<svg width="20" height="20" viewBox="0 0 30 30" fill="none" xmlns="http://www.w3.org/2000/svg">'
            . '<path fill-rule="evenodd" clip-rule="evenodd" d="M0 15C0 6.71582 6.7069 0 14.9801 0C20.2546 0 24.8865 2.72788 27.5572 6.84316L19.371 15.1743H19.3643V15.1877C19.0765 15.4893 18.5946 15.496 18.2934 15.2011C18.2599 15.1743 18.2331 15.1408 18.2064 15.1005L15.9239 11.9638C13.9627 9.27614 10.047 9.03485 7.77787 11.4678L0.589029 19.1756C0.194112 17.8217 0 16.4142 0 15ZM2.69079 23.5858C5.40167 27.4665 9.89302 30.0067 14.9801 30.0067C23.2533 30.0067 29.9602 23.2909 29.9602 15.0067C29.9602 13.7399 29.8062 12.5134 29.5117 11.3405L22.604 18.3646C20.3148 20.7172 16.466 20.4424 14.5316 17.7949L12.2491 14.6582C12.0015 14.3231 11.5329 14.2426 11.1916 14.4906C11.1514 14.5174 11.1179 14.5509 11.0845 14.5845L2.69079 23.5858Z" fill="#F22F46"/>'
            . '</svg>'
            . '<span class="slimstat-adminbar__footer-brand">SlimStat</span>'
            . '</div>'
            . '<a href="' . esc_url($overview_url) . '" class="slimstat-adminbar__footer-link">'
            . esc_html__('Explore Details', 'wp-slimstat')
            . ' <span class="dashicons dashicons-external" style="font-size:12px"></span>'
            . '</a></div>';

        $GLOBALS['wp_admin_bar']->add_node([
            'id'     => 'slimstat-adminbar-footer',
            'parent' => 'slimstat-header',
            'title'  => $footer_html,
            'meta'   => ['class' => 'slimstat-adminbar__footer-wrapper'],
        ]);
    }

    // END: add_menu_to_adminbar

    /**
     * Includes the appropriate panel to view the stats
     */
    public static function wp_slimstat_include_view()
    {
        include(__DIR__ . '/view/index.php');
    }

    // END: wp_slimstat_include_view

    /**
     * Includes the screen to arrange the reports
     */
    public static function wp_slimstat_include_layout()
    {
        include(__DIR__ . '/view/layout.php');
    }

    // END: wp_slimstat_include_layout

    /**
     * Includes the email report screen
     */
    public static function wp_slimstat_include_email_report()
    {
        include(__DIR__ . '/view/email-report.php');
    }

    // END: wp_slimstat_include_email_report

    /**
     * Handles the upgrade to pro from the free version
     */
    public static function wp_slimstat_pro()
    {
        include(__DIR__ . '/view/upgrade-pro.php');
    }

    // END: wp_slimstat_include_addons

    /**
     * Includes the appropriate panel to configure Slimstat
     */
    public static function wp_slimstat_include_config()
    {
        include(__DIR__ . '/config/index.php');
    }

    // END: wp_slimstat_include_config

    /**
     * Retrieves all the information to be used in the custom column on posts, pages and CPTs
     */
    public static function init_data_for_column()
    {
        if (!is_array($GLOBALS['wp_query']->posts)) {
            return 0;
        }

        foreach ($GLOBALS['wp_query']->posts as $a_post) {
            self::$data_for_column['url'][$a_post->ID] = parse_url(get_permalink($a_post->ID));
            self::$data_for_column['url'][$a_post->ID] = self::$data_for_column['url'][$a_post->ID]['path'] . (empty(self::$data_for_column['url'][$a_post->ID]['query']) ? '' : '?' . self::$data_for_column['url'][$a_post->ID]['query']);
            self::$data_for_column['sql'][$a_post->ID] = self::$data_for_column['url'][$a_post->ID] . '%';
        }

        /**
         * https://wordpress.org/support/topic/you-have-an-error-in-your-sql-syntax-22/#post-12565619
         */
        if (empty(self::$data_for_column) || empty(self::$data_for_column['url'])) {
            return 0;
        }

        wp_slimstat_db::init('interval equals -' . wp_slimstat::$settings['posts_column_day_interval']);

        $column = ('on' == wp_slimstat::$settings['posts_column_pageviews']) ? 'id' : 'ip';
        $where  = wp_slimstat_db::get_combined_where('(' . implode(' OR ', array_fill(1, count(self::$data_for_column['url']), 'resource LIKE %s')) . ')', '*', true);

        $sql = wp_slimstat::$wpdb->prepare("
			SELECT resource, COUNT( DISTINCT {$column} ) as counthits
			FROM {$GLOBALS['wpdb']->prefix}slim_stats
			WHERE " . $where . '
			GROUP BY resource
			LIMIT 0, ' . wp_slimstat_db::$filters_normalized['misc']['limit_results'], self::$data_for_column['sql']);

        $results = wp_slimstat_db::get_results($sql);

        foreach (self::$data_for_column['url'] as $post_id => $a_url) {
            self::$data_for_column['count'][$post_id] = 0;

            foreach ($results as $i => $a_row) {
                if (false !== strpos($a_row['resource'], (string) $a_url)) {
                    self::$data_for_column['count'][$post_id] += $a_row['counthits'];
                    unset($results[$i]);
                }
            }
        }

        return null;
    }

    // END: init_data_for_column

    /**
     * Adds a new column header to the Posts panel (to show the number of pageviews for each post)
     */
    public static function add_column_header($_columns = [])
    {
        if (empty(wp_slimstat::$settings['posts_column_day_interval'])) {
            wp_slimstat::$settings['posts_column_day_interval'] = 28;
        }

        if ('on' == wp_slimstat::$settings['posts_column_pageviews']) {
            $_columns['wp-slimstat'] = '<span class="slimstat-icon" title="' . sprintf(__('Pageviews in the last %s days', 'wp-slimstat'), wp_slimstat::$settings['posts_column_day_interval']) . '"><span class="screen-reader-text">' . __('Views') . '</span></span>';
        } else {
            $_columns['wp-slimstat'] = '<span class="slimstat-icon" title="' . sprintf(__('Unique IPs in the last %s days', 'wp-slimstat'), wp_slimstat::$settings['posts_column_day_interval']) . '"></span>';
        }

        return $_columns;
    }

    // END: add_comment_column_header

    /**
     * Adds a new column to the Posts management panel
     */
    public static function add_post_column($_column_name, $_post_id)
    {
        if ('wp-slimstat' != $_column_name || empty(self::$data_for_column['url'][$_post_id])) {
            return 0;
        }

        $count = empty(self::$data_for_column['count'][$_post_id]) ? 0 : self::$data_for_column['count'][$_post_id];

        echo '<a href="' . wp_slimstat_reports::fs_url('resource starts_with ' . self::$data_for_column['url'][$_post_id] . '&&&interval equals -' . wp_slimstat::$settings['posts_column_day_interval']) . '">' . $count . '</a>';
        return null;
    }

    // END: add_column

    /**
     * Displays an alert message
     */
    public static function show_message($_message = '', $_type = 'info', $_dismiss_handle = '')
    {
        if (empty($_message)) {
            return 0;
        }

        $_message = wpautop(wp_kses_post($_message));

        if (!empty($_dismiss_handle)) {
            echo '<div id="slimstat-notice-' . esc_attr($_dismiss_handle) . '" class="notice is-dismissible slimstat-notice notice-' . esc_attr($_type) . '">' . $_message . '</div>';
        } else {
            echo '<div class="notice notice-' . esc_attr($_type) . ' slimstat-notice">' . $_message . '</div>';
        }

        return null;
    }

    // END: show_message

    /**
     * Displays a message related to the current version of Slimstat
     */
    public static function show_latest_news()
    {
        self::show_message(self::$admin_notice, 'info', 'latest-news');
    }

    // END: show_latest_news


    /**
     * Handles the Ajax request to hide the admin notice
     */
    public static function notices_handler()
    {
        $tag = current_filter();

        if (!empty($tag) && current_user_can('manage_options') && wp_verify_nonce($_POST['security'], 'meta-box-order')) {
            $tag                         = str_replace('wp_ajax_slimstat_', '', $tag);
            wp_slimstat::$settings[$tag] = 'no';

            // Save the default values in the database
            wp_slimstat::update_option('slimstat_options', wp_slimstat::$settings);
        }

        exit();
    }

    // END: notices_handler

    // ---- Goals & Funnels CRUD ---- //

    /**
     * Invalidates all goal/funnel/visitor caches by incrementing version.
     * Works with both wp_options and external object cache (Redis/Memcached).
     *
     * When no persistent object cache is present, orphaned version-keyed
     * transient rows in wp_options aren't cleaned up until WordPress's
     * `delete_expired_transients` cron fires (every 12h). We GC them here
     * so frequently-edited installs don't accumulate hundreds of rows.
     */
    private static function clear_goals_cache()
    {
        // Sub-second precision avoids collisions when two saves land in the
        // same second (time() granularity was causing cache-miss no-ops).
        update_option('slimstat_goals_cache_ver', (string) microtime(true), false);

        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            return;
        }

        global $wpdb;
        if (!$wpdb) {
            return;
        }

        $like_goal         = $wpdb->esc_like('_transient_slimstat_goal_')           . '%';
        $like_goal_timeout = $wpdb->esc_like('_transient_timeout_slimstat_goal_')   . '%';
        $like_funnel       = $wpdb->esc_like('_transient_slimstat_funnel_')         . '%';
        $like_funnel_t     = $wpdb->esc_like('_transient_timeout_slimstat_funnel_') . '%';
        // Unique-visitor denominator transients (CR math) — version-keyed since 5.5.0
        // so they accumulate one row per date range; sweep them here too.
        $like_uv           = $wpdb->esc_like('_transient_slimstat_uv_')             . '%';
        $like_uv_timeout   = $wpdb->esc_like('_transient_timeout_slimstat_uv_')     . '%';

        // LIMIT 1000 mirrors update_tables_and_options()'s bounded transient sweep so
        // an install with many accumulated rows doesn't run an unbounded synchronous
        // DELETE on an admin save.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s LIMIT 1000",
            $like_goal,
            $like_goal_timeout,
            $like_funnel,
            $like_funnel_t,
            $like_uv,
            $like_uv_timeout
        ));
    }

    /**
     * Returns validated goal dimensions available for selection.
     */
    public static function get_goal_dimensions()
    {
        return [
            'resource'      => __('Page URL', 'wp-slimstat'),
            'content_type'  => __('Content Type', 'wp-slimstat'),
            'content_id'    => __('Content ID', 'wp-slimstat'),
            'searchterms'   => __('Search Terms', 'wp-slimstat'),
            'country'       => __('Country', 'wp-slimstat'),
            'browser'       => __('Browser', 'wp-slimstat'),
            'platform'      => __('Operating System', 'wp-slimstat'),
            'referer'       => __('Referer', 'wp-slimstat'),
            'username'      => __('Username', 'wp-slimstat'),
            'event_notes'   => __('Event', 'wp-slimstat'),
        ];
    }

    /**
     * Dimensions allowed for FUNNEL STEPS — a subset of get_goal_dimensions()
     * restricted to action/journey-oriented entities. Attribute dimensions
     * (Country, Browser, Operating System, Referer, Username) describe WHO a
     * visitor is, not what they DID, so they make no sense as a funnel step
     * ("Homepage → Chrome → Checkout") and stay reserved for goals. Sliced from
     * get_goal_dimensions() via array_intersect_key so labels + order never
     * drift from the canonical list. (#17)
     */
    public static function get_funnel_step_dimensions()
    {
        $allowed = ['resource', 'content_type', 'content_id', 'searchterms', 'event_notes'];
        return array_intersect_key(self::get_goal_dimensions(), array_flip($allowed));
    }

    /**
     * Returns validated goal operators.
     */
    public static function get_goal_operators()
    {
        return ['equals', 'is_not_equal_to', 'contains', 'does_not_contain', 'starts_with', 'ends_with', 'matches', 'is_empty', 'is_not_empty'];
    }

    /**
     * Returns an operator-key => human-label map, sourced from wp_slimstat_db's
     * canonical operator-names table. Falls back to the key when the DB class is
     * not yet loaded (admin_footer hooks may fire before reports init in rare paths).
     *
     * @since 5.5.0
     */
    public static function get_goal_operator_labels()
    {
        $labels  = [];
        $has_db  = class_exists('wp_slimstat_db');
        foreach (self::get_goal_operators() as $op) {
            $labels[$op] = ($has_db && !empty(wp_slimstat_db::$operator_names[$op]))
                ? wp_slimstat_db::$operator_names[$op]
                : $op;
        }
        return $labels;
    }

    /**
     * Sanitizes and validates a goal definition array.
     *
     * Accepts raw slashed input (callers hand through `$_POST` directly) and
     * runs wp_unslash() before the per-field sanitizers so admin-entered values
     * containing quotes or backslashes round-trip correctly.
     */
    private static function sanitize_goal($raw, $is_funnel_step = false)
    {
        if (!is_array($raw)) {
            return false;
        }
        $raw        = wp_unslash($raw);
        // Funnel steps accept only action-oriented dimensions; goals accept all.
        // Validating server-side keeps an attribute dimension from being POSTed
        // past the (already-restricted) builder dropdown. (#17)
        $dimensions = $is_funnel_step ? self::get_funnel_step_dimensions() : self::get_goal_dimensions();
        $operators  = self::get_goal_operators();

        $goal = [
            // A provided id is honoured only so callers can match an existing
            // record for update; new records get a server-assigned id via
            // next_record_id() (never a client value, never microtime — which
            // collides on sub-ms saves and overflows on 32-bit PHP).
            'id'        => !empty($raw['id']) ? intval($raw['id']) : 0,
            'name'      => !empty($raw['name']) ? sanitize_text_field($raw['name']) : '',
            'dimension' => !empty($raw['dimension']) && isset($dimensions[$raw['dimension']]) ? $raw['dimension'] : '',
            'operator'  => !empty($raw['operator']) && in_array($raw['operator'], $operators, true) ? $raw['operator'] : '',
            'value'     => isset($raw['value']) ? sanitize_text_field($raw['value']) : '',
            'active'    => isset($raw['active']) ? (bool) $raw['active'] : true,
        ];

        if (empty($goal['name']) || empty($goal['dimension']) || empty($goal['operator'])) {
            return false;
        }

        // A value-bearing operator must carry a non-empty value; otherwise the
        // query builder emits an unbound "%s" placeholder that breaks the SQL and
        // silently reports 0. Only the valueless operators may omit a value — sourced
        // from the shared wp_slimstat_db list (guarded; the db class may not be loaded
        // yet on the save path) so it never drifts. See #305.
        $valueless = class_exists('wp_slimstat_db') ? wp_slimstat_db::$valueless_operators : ['is_empty', 'is_not_empty'];
        if ('' === $goal['value'] && !in_array($goal['operator'], $valueless, true)) {
            return false;
        }

        return $goal;
    }

    /**
     * Returns a collision-free id for a new goal/funnel record: max existing id + 1.
     * Replaces the old microtime()-based id (which collided on sub-millisecond
     * saves and overflowed to 0 on 32-bit PHP), and is never client-supplied.
     */
    private static function next_record_id(array $records)
    {
        $max = 0;
        foreach ($records as $record) {
            if (isset($record['id']) && (int) $record['id'] > $max) {
                $max = (int) $record['id'];
            }
        }
        return $max + 1;
    }

    /**
     * AJAX: Save (create/update) a goal.
     */
    public static function ajax_save_goal()
    {
        check_ajax_referer('slimstat_goals_nonce', 'security');

        if (!current_user_can(wp_slimstat::$settings['capability_can_admin'])) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-slimstat')]);
        }

        $goal = self::sanitize_goal($_POST);
        if (!$goal) {
            wp_send_json_error(['message' => __('Invalid goal definition', 'wp-slimstat')]);
        }

        $goals     = get_option('slimstat_goals', []);
        $max_goals = apply_filters('slimstat_max_goals', 1);

        // Update only when a client-supplied id matches an existing goal; anything
        // else is a create. New records get a server-assigned id (next_record_id)
        // so a client can't force a collision/overwrite by sending an arbitrary id.
        // NOTE: this read-modify-write of the slimstat_goals option assumes a single
        // editor — concurrent admin saves can still last-writer-win (acceptable for
        // an admin-only, low-frequency setting).
        $found = false;
        if (!empty($goal['id'])) {
            foreach ($goals as $i => $existing) {
                if ((int) $existing['id'] === (int) $goal['id']) {
                    $goals[$i] = $goal;
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            // Hard cap on total stored goals (active + paused). Paused goals don't
            // count against the active-tier limit below, so without this they could
            // grow the slimstat_goals option without bound.
            $hard_cap = (int) apply_filters('slimstat_goals_hard_cap', 50);
            if (count($goals) >= $hard_cap) {
                wp_send_json_error(['message' => __('Too many goals stored. Delete unused goals before adding more.', 'wp-slimstat')]);
            }
            $goal['id'] = self::next_record_id($goals);
        }

        // Count active goals in the state that *would* result from this save.
        // For updates, $goals already reflects the incoming edit; for creates,
        // append hypothetically for the check only. This catches the bypass
        // where activating a previously-paused goal on update slipped past the
        // limit because the old guard only ran on creates.
        $post_save = $found ? $goals : array_merge($goals, [$goal]);
        $active_count = count(array_filter($post_save, function ($g) {
            return !empty($g['active']);
        }));

        if ($active_count > $max_goals) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %d is the max goal count for the tier */
                    __('Goal limit reached (%d). Upgrade to Pro for more goals.', 'wp-slimstat'),
                    $max_goals
                ),
            ]);
        }

        if (!$found) {
            $goals[] = $goal;
        }

        update_option('slimstat_goals', $goals, false);
        self::clear_goals_cache();
        wp_send_json_success(['goals' => $goals]);
    }

    /**
     * AJAX: Delete a goal by ID.
     */
    public static function ajax_delete_goal()
    {
        check_ajax_referer('slimstat_goals_nonce', 'security');

        if (!current_user_can(wp_slimstat::$settings['capability_can_admin'])) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-slimstat')]);
        }

        $goal_id = isset($_POST['goal_id']) ? intval(wp_unslash($_POST['goal_id'])) : 0;
        if ($goal_id <= 0) {
            wp_send_json_error(['message' => __('Invalid goal id', 'wp-slimstat')]);
        }

        $goals    = get_option('slimstat_goals', []);
        $filtered = array_values(array_filter($goals, function ($g) use ($goal_id) {
            return isset($g['id']) && (int) $g['id'] !== $goal_id;
        }));

        if (count($filtered) === count($goals)) {
            wp_send_json_error(['message' => __('Goal not found', 'wp-slimstat')], 404);
        }

        update_option('slimstat_goals', $filtered, false);
        self::clear_goals_cache();
        wp_send_json_success(['goals' => $filtered]);
    }

    /**
     * AJAX: Save (create/update) a funnel.
     */
    public static function ajax_save_funnel()
    {
        check_ajax_referer('slimstat_goals_nonce', 'security');

        if (!current_user_can(wp_slimstat::$settings['capability_can_admin'])) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-slimstat')]);
        }

        $max_funnels = apply_filters('slimstat_max_funnels', 0);
        if ($max_funnels <= 0) {
            wp_send_json_error(['message' => __('Funnels require SlimStat Pro', 'wp-slimstat')]);
        }

        $raw_steps = isset($_POST['steps']) && is_array($_POST['steps']) ? $_POST['steps'] : [];
        if (count($raw_steps) < 2 || count($raw_steps) > 5) {
            wp_send_json_error(['message' => __('Funnels require 2-5 steps', 'wp-slimstat')]);
        }

        $steps = [];
        foreach ($raw_steps as $raw_step) {
            $step = self::sanitize_goal($raw_step, true);
            if (!$step) {
                wp_send_json_error(['message' => __('Invalid step definition', 'wp-slimstat')]);
            }
            $steps[] = $step;
        }

        $raw_funnel_name = isset($_POST['funnel_name']) ? wp_unslash((string) $_POST['funnel_name']) : '';
        $incoming_id     = !empty($_POST['funnel_id']) ? intval(wp_unslash($_POST['funnel_id'])) : 0;
        $funnel = [
            // Provisional id; reassigned with a server-side value for creates below
            // (never microtime — it collides on sub-ms saves / overflows on 32-bit).
            'id'    => $incoming_id,
            'name'  => sanitize_text_field($raw_funnel_name),
            'steps' => $steps,
        ];

        if (empty($funnel['name'])) {
            wp_send_json_error(['message' => __('Funnel name is required', 'wp-slimstat')]);
        }

        $funnels = get_option('slimstat_funnels', []);

        // Update only when a client-supplied id matches an existing funnel; else create.
        $found = false;
        if ($incoming_id > 0) {
            foreach ($funnels as $i => $existing) {
                if ((int) $existing['id'] === $incoming_id) {
                    $funnels[$i] = $funnel;
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            if (count($funnels) >= $max_funnels) {
                wp_send_json_error([
                    'message' => sprintf(
                        __('Funnel limit reached (%d).', 'wp-slimstat'),
                        $max_funnels
                    ),
                ]);
            }
            $funnel['id'] = self::next_record_id($funnels);
            $funnels[] = $funnel;
        }

        update_option('slimstat_funnels', $funnels, false);
        self::clear_goals_cache();
        wp_send_json_success(['funnels' => $funnels]);
    }

    /**
     * AJAX: Delete a funnel by ID.
     */
    public static function ajax_delete_funnel()
    {
        check_ajax_referer('slimstat_goals_nonce', 'security');

        if (!current_user_can(wp_slimstat::$settings['capability_can_admin'])) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-slimstat')]);
        }

        $funnel_id = isset($_POST['funnel_id']) ? intval(wp_unslash($_POST['funnel_id'])) : 0;
        if ($funnel_id <= 0) {
            wp_send_json_error(['message' => __('Invalid funnel id', 'wp-slimstat')]);
        }

        $funnels  = get_option('slimstat_funnels', []);
        $filtered = array_values(array_filter($funnels, function ($f) use ($funnel_id) {
            return isset($f['id']) && (int) $f['id'] !== $funnel_id;
        }));

        if (count($filtered) === count($funnels)) {
            wp_send_json_error(['message' => __('Funnel not found', 'wp-slimstat')], 404);
        }

        update_option('slimstat_funnels', $filtered, false);
        self::clear_goals_cache();
        wp_send_json_success(['funnels' => $filtered]);
    }

    /**
     * AJAX: Return per-step results + summary for a single funnel by id.
     * Used by the funnel tab bar's lazy-load — inactive tabs fetch on click.
     *
     * @since 5.5.0
     */
    public static function ajax_load_funnel_data()
    {
        check_ajax_referer('slimstat_goals_nonce', 'security');

        if (!self::check_ajax_view_capability()) {
            return;
        }

        $funnel_id = intval($_POST['funnel_id'] ?? 0);
        $funnels   = get_option('slimstat_funnels', []);
        $funnel    = null;
        foreach ($funnels as $f) {
            if (intval($f['id']) === $funnel_id) {
                $funnel = $f;
                break;
            }
        }

        if (!$funnel) {
            wp_send_json_error(['message' => __('Funnel not found', 'wp-slimstat')], 404);
        }

        // Hydrate the DB layer (columns + the on-screen date range). This AJAX
        // action isn't covered by the admin bootstrap's init(), so without it the
        // date filter collapses to `dt BETWEEN 0 AND 0` and every step returns 0 —
        // which is why only the first, server-rendered funnel showed data. (#8)
        self::ensure_goals_db_initialized();

        $step_results = wp_slimstat_db::get_funnel_results($funnel);

        // Summary: step count + total conversion rate (null when step 1 had no visitors,
        // so the UI renders "No matching visitors" instead of a fake 100%).
        $step_one_visitors = $step_results[0]['visitors'] ?? 0;
        $total_cr          = null;
        if ($step_one_visitors > 0) {
            $total_cr = (count($step_results) > 1)
                ? $step_results[count($step_results) - 1]['pct']
                : 100;
        }

        $unreachable_count = 0;
        foreach ($step_results as $step) {
            if (!empty($step['unreachable'])) {
                $unreachable_count++;
            }
        }

        wp_send_json_success([
            'funnel_id' => $funnel_id,
            'steps'     => $step_results,
            'summary'   => [
                'step_count'        => count($step_results),
                'total_cr'          => $total_cr,
                'unreachable_count' => $unreachable_count,
            ],
        ]);
    }

    /**
     * AJAX: Return the unique-visitor count for a single funnel step rule.
     * Powers the builder's per-step "Test" affordance. A single step IS the
     * same shape as a goal, so we forward to get_goal_results().
     *
     * @since 5.5.0
     */
    public static function ajax_test_funnel_step()
    {
        check_ajax_referer('slimstat_goals_nonce', 'security');

        // Builder-only action: it runs an arbitrary admin-supplied rule (including
        // REGEXP) against slim_stats and each distinct rule misses the cache, so it
        // is gated on the admin capability rather than the broader view capability.
        if (!current_user_can(wp_slimstat::$settings['capability_can_admin'])) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-slimstat')], 403);
        }

        $step = self::sanitize_goal($_POST, true);
        if (!$step) {
            wp_send_json_error(['message' => __('Step is missing required fields', 'wp-slimstat')]);
        }

        // Force a stable id derived from the rule so repeat Test-clicks on the
        // same rule hit the get_goal_results() transient. sanitize_goal() default
        // id is microtime-based (unique per call), which defeats caching here.
        $step['id'] = crc32($step['dimension'] . '|' . $step['operator'] . '|' . (string) $step['value']);

        // Same as ajax_load_funnel_data: initialize the DB layer + date range so
        // the rule is tested against the selected window instead of
        // `dt BETWEEN 0 AND 0` (which returned "0 matches" for pages that clearly
        // exist, e.g. /contact). (#6)
        self::ensure_goals_db_initialized();

        $data = wp_slimstat_db::get_goal_results($step);

        wp_send_json_success([
            'visitors' => (int) ($data['uniques'] ?? 0),
            'total'    => (int) ($data['total'] ?? 0),
        ]);
    }

    /**
     * Resolve the report date-picker's POSTed window into [start, end] Unix
     * timestamps. Shared by get_filter_options() and ensure_goals_db_initialized()
     * so autosuggest, the funnel-step Test, and funnel lazy-load all read the
     * same range. Falls back to the last 28 days when nothing valid is supplied.
     *
     * @return array{0:?int,1:?int} [start, end] timestamps (null when unresolved).
     */
    private static function resolve_requested_date_range()
    {
        $type = sanitize_text_field(wp_unslash($_POST['time_range_type'] ?? 'last_28_days'));
        $from = sanitize_text_field(wp_unslash($_POST['time_range_from'] ?? ''));
        $to   = sanitize_text_field(wp_unslash($_POST['time_range_to'] ?? ''));

        $start = null;
        $end   = null;
        if ('custom' === $type && '' !== $from && '' !== $to) {
            $start = strtotime($from);
            $end   = strtotime($to . ' 23:59:59');
        } else {
            $range = DateRangeHelper::get_range_by_preset($type);
            if ($range) {
                $start = $range['start'];
                $end   = $range['end'];
            }
        }

        // Fallback to last 28 days when no valid range was supplied/parsed.
        if (empty($start) || empty($end)) {
            $range = DateRangeHelper::get_range_by_preset('last_28_days');
            if ($range) {
                $start = $range['start'];
                $end   = $range['end'];
            }
        }

        // Clamp the end to "now", mirroring the SSR funnel render (wp-slimstat-db.php:852):
        // presets return "today 23:59:59" (a future time), so without this the active
        // (SSR) funnel queries [start..now] while an AJAX-loaded twin queries
        // [start..23:59:59]. That gives different counts AND different cache-key hour
        // buckets, so two identical funnels disagree. Clamping makes every goals/funnels
        // AJAX window end at the same "now" as the SSR render. A custom past range is
        // unaffected (its end is already < now). (#1)
        $now = (int) date_i18n('U');
        return [$start ? (int) $start : null, $end ? min((int) $end, $now) : null];
    }

    /**
     * Hydrate wp_slimstat_db for the goals/funnels Test + lazy-load AJAX actions.
     *
     * These actions are NOT covered by the admin bootstrap that normally calls
     * wp_slimstat_db::init() (only slimview pages + slimstat_load_report are —
     * see the $is_slimstat_ajax guard). Without init(), $columns_names and
     * $filters_normalized['utime'] stay empty, so get_combined_where() builds
     * `dt BETWEEN 0 AND 0` (matching nothing) and emits undefined-array-key
     * notices. We init() to populate columns + defaults, then pin the date
     * window to the report's selected range (mirrors get_filter_options). (#6/#8)
     */
    private static function ensure_goals_db_initialized()
    {
        if (!class_exists('wp_slimstat_db')) {
            include_once plugin_dir_path(__FILE__) . 'view/wp-slimstat-db.php';
        }

        // Prefer the exact window the server-rendered funnel already used, posted back
        // as gf_utime_start/end. Reusing it verbatim makes the AJAX funnel/Test query
        // the IDENTICAL [start,end] (and funnel cache key) as the SSR render, so two
        // identical funnels share one result instead of re-resolving the preset in the
        // site timezone while the SSR path used legacy UTC day boundaries. (#1)
        $pinned_start = isset($_POST['gf_utime_start']) ? (int) $_POST['gf_utime_start'] : 0;
        $pinned_end   = isset($_POST['gf_utime_end']) ? (int) $_POST['gf_utime_end'] : 0;
        if ($pinned_start > 0 && $pinned_end > 0) {
            $start = $pinned_start;
            $end   = $pinned_end;
        } else {
            list($start, $end) = self::resolve_requested_date_range();
        }

        // init() populates $columns_names/$operator_names plus a default
        // $filters_normalized; then pin utime to the requested range.
        wp_slimstat_db::init();
        if (!isset(wp_slimstat_db::$filters_normalized['utime']) || !is_array(wp_slimstat_db::$filters_normalized['utime'])) {
            wp_slimstat_db::$filters_normalized['utime'] = [];
        }
        if (!empty($start) && !empty($end)) {
            wp_slimstat_db::$filters_normalized['utime']['start'] = (int) $start;
            wp_slimstat_db::$filters_normalized['utime']['end']   = (int) $end;
        }
    }

    // END: Goals & Funnels CRUD

    /**
     * Deletes a given pageview from the database
     */
    public static function delete_pageview()
    {
        $my_wpdb     = apply_filters('slimstat_custom_wpdb', $GLOBALS['wpdb']);
        $pageview_id = intval($_POST['pageview_id']);

        // Delete page view if user has enough access
        $current_user_can_delete = (current_user_can(wp_slimstat::$settings['capability_can_admin']) && !is_network_admin());
        if (!$current_user_can_delete || !wp_verify_nonce($_POST['security'], 'meta-box-order')) {
            return;
        }
        $my_wpdb->query(sprintf('DELETE ts FROM %sslim_stats ts WHERE ts.id = %d', $GLOBALS['wpdb']->prefix, $pageview_id));
        exit();
    }

    // END: delete_pageview

    /**
     * Deletes a given pageview from the database
     */
    public static function rmdir($path)
    {
        if (!file_exists($path)) {
            return true;
        }

        if (!is_dir($path)) {
            return unlink($path);
        }

        foreach (scandir($path) as $a_item) {
            if ('.' === $a_item || '..' === $a_item) {
                continue;
            }

            if (!wp_slimstat_admin::rmdir($path . DIRECTORY_SEPARATOR . $a_item)) {
                return false;
            }
        }

        return rmdir($path);
    }

    // END: delete_pageview

    /**
     * Handles the Ajax requests to load, save or delete existing filters
     */
    public static function manage_filters()
    {
        check_ajax_referer('meta-box-order', 'security');

        if (!self::can_view_stats()) {
            return;
        }

        // Initialize the new Reports system FIRST before legacy system loads
        \SlimStat\Reports\Bootstrap::get_instance()->init();

        include_once(plugin_dir_path(__FILE__) . 'view/wp-slimstat-reports.php');
        wp_slimstat_reports::init();

        $saved_filters = get_option('slimstat_filters', []);

        switch (sanitize_key(wp_unslash($_POST['type'] ?? ''))) {
            case 'save':
                $new_filter = json_decode(stripslashes_deep(sanitize_text_field($_POST['filter_array'])), true);

                // Check if this filter is already saved
                foreach ($saved_filters as $a_saved_filter) {
                    $filter_found = 0;

                    if (count($a_saved_filter) !== count($new_filter) || count(array_intersect_key($a_saved_filter, $new_filter)) !== count($new_filter)) {
                        $filter_found = 1;
                        continue;
                    }

                    foreach ($a_saved_filter as $a_key => $a_value) {
                        $filter_found += ($a_value == $new_filter[$a_key]) ? 0 : 1;
                    }

                    if (0 == $filter_found) {
                        echo __('Already saved', 'wp-slimstat');
                        break;
                    }
                }

                if (empty($saved_filters) || $filter_found > 0) {
                    $saved_filters[] = $new_filter;
                    update_option('slimstat_filters', $saved_filters);
                    echo __('Saved', 'wp-slimstat');
                }

                break;

            case 'delete':
                unset($saved_filters[intval($_POST['filter_id'])]);
                update_option('slimstat_filters', $saved_filters);

                // no break here - We want to return the new list of filters!

            default:
                echo '<div id="slim_filters_overlay">';
                foreach ($saved_filters as $a_filter_id => $a_filter_data) {

                    $filter_html    = [];
                    $filter_strings = [];
                    foreach ($a_filter_data as $a_filter_label => $a_filter_details) {
                        $filter_value_no_slashes = htmlentities(str_replace('\\', '', $a_filter_details[1]), ENT_QUOTES, 'UTF-8');
                        $filter_html[]           = strtolower(wp_slimstat_db::$columns_names[$a_filter_label][0]) . ' ' . __(str_replace('_', ' ', $a_filter_details[0]), 'wp-slimstat') . ' ' . $filter_value_no_slashes;
                        $filter_strings[]        = sprintf('%s %s %s', $a_filter_label, $a_filter_details[0], $filter_value_no_slashes);
                    }

                    echo '<p><a class="slimstat-font-cancel slimstat-delete-filter" data-filter-id="' . esc_attr($a_filter_id) . '" title="' . __('Delete this filter', 'wp-slimstat') . '" href="#"></a> <a class="slimstat-filter-link" data-reset-filters="true" href="' . wp_slimstat_reports::fs_url(implode('&&&', $filter_strings)) . '">' . implode(', ', $filter_html) . '</a></p>';
                }

                echo '</div>';
                break;
        }

        exit();
    }

    // END: manage_filters

    /**
     * Check AJAX capability for view access.
     * Returns true if user has permission, sends JSON error and returns false otherwise.
     *
     * @since 5.4.3
     * @return bool
     */
    private static function check_ajax_view_capability()
    {
        // Same predicate the menu and its assets use — this carried its own copy, which
        // had already drifted (it omitted the network-admin branch).
        if (!self::can_view_stats()) {
            wp_send_json_error(['message' => esc_html__('Insufficient permissions', 'wp-slimstat')], 403);
            return false;
        }

        return true;
    }

    /**
     * Today/yesterday figures for the admin bar, behind a 60-second transient.
     *
     * The single source of truth for both the render path (add_menu_to_adminbar,
     * which runs on every logged-in FRONTEND pageview via admin_bar_menu) and the
     * AJAX refresh (get_adminbar_stats).
     *
     * The render path used to compute these itself with six separate queries,
     * including two unindexable `referer NOT LIKE '%host%'` scans — 886,726 rows
     * read per frontend request on a 443k-row table, paid by every logged-in
     * visitor on every page of the site. It also derived midnight from
     * mktime(0,0,0) (server timezone) while this path uses current_time() (site
     * timezone), so the two disagreed about when "today" began on any site whose
     * WordPress timezone differs from its server's.
     *
     * Two conditional-aggregate queries at most once a minute, shared.
     *
     * @since 5.6.0
     * @return array{sessions:int,sessions_yesterday:int,views:int,views_yesterday:int,referrals:int,referrals_yesterday:int}
     */
    private static function adminbar_today_stats()
    {
        $transient_key = 'slimstat_adminbar_today_' . get_current_blog_id();
        $today_stats   = get_transient($transient_key);

        if (is_array($today_stats)) {
            return $today_stats;
        }

        $wpdb  = wp_slimstat::$wpdb;
        $table = "{$GLOBALS['wpdb']->prefix}slim_stats";

        $today_start     = strtotime('today', current_time('timestamp'));
        $yesterday_start = $today_start - DAY_IN_SECONDS;
        $yesterday_end   = $today_start - 1;
        $site_host       = parse_url(home_url(), PHP_URL_HOST);
        $referer_like    = '%' . $wpdb->esc_like((string) $site_host) . '%';

        // Sessions + views: 1 query instead of 4, using conditional aggregates.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN dt >= %d AND visit_id > 0 THEN visit_id END) AS sessions_today,
                COUNT(DISTINCT CASE WHEN dt BETWEEN %d AND %d AND visit_id > 0 THEN visit_id END) AS sessions_yesterday,
                SUM(CASE WHEN dt >= %d THEN 1 ELSE 0 END) AS views_today,
                SUM(CASE WHEN dt BETWEEN %d AND %d THEN 1 ELSE 0 END) AS views_yesterday
            FROM {$table}
            WHERE dt >= %d",
            $today_start,
            $yesterday_start, $yesterday_end,
            $today_start,
            $yesterday_start, $yesterday_end,
            $yesterday_start
        ));

        // Referrals: 1 query instead of 2 — and skipped entirely without Pro.
        // Both consumers discard these on free installs (the render path
        // substitutes placeholder literals behind a blur, the AJAX path only
        // emits them inside an is_pro branch), so on a free site this was an
        // unindexable `referer NOT LIKE '%host%'` scan running every minute and
        // throwing the result away.
        $ref_row = null;
        if (wp_slimstat::pro_is_installed()) {
            $ref_row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                    SUM(CASE WHEN dt >= %d THEN 1 ELSE 0 END) AS referrals_today,
                    SUM(CASE WHEN dt BETWEEN %d AND %d THEN 1 ELSE 0 END) AS referrals_yesterday
                FROM {$table}
                WHERE dt >= %d AND referer IS NOT NULL AND referer NOT LIKE %s",
                $today_start,
                $yesterday_start, $yesterday_end,
                $yesterday_start,
                $referer_like
            ));
        }

        $today_stats = [
            'sessions'            => (int) ($row->sessions_today ?? 0),
            'sessions_yesterday'  => (int) ($row->sessions_yesterday ?? 0),
            'views'               => (int) ($row->views_today ?? 0),
            'views_yesterday'     => (int) ($row->views_yesterday ?? 0),
            'referrals'           => (int) ($ref_row->referrals_today ?? 0),
            'referrals_yesterday' => (int) ($ref_row->referrals_yesterday ?? 0),
        ];

        // Align expiry to the next minute boundary, so the cache turns over in
        // step with the minute-granular chart rather than drifting against it.
        set_transient($transient_key, $today_stats, max(60 - (current_time('timestamp') % 60), 1));

        return $today_stats;
    }

    /**
     * Visitors currently online, cached for the remainder of the current minute.
     *
     * Left uncached when the rest of the admin bar was fixed, on the grounds that it is
     * the one figure labelled "right now" — but it ran on every admin render for every
     * logged-in user, on top of the per-minute poll, and it is not cheap on a busy site.
     * The figure already counts activity over a 30-minute window, so being up to a
     * minute behind is a small change to how true it is. Signed off 2026-07-28; the
     * measurements live in tests/adminbar-query-budget-test.php.
     *
     * Expiry is aligned to the minute boundary for the same reason adminbar_today_stats()
     * aligns its own: adminbar-realtime.js refreshes at :00 of each wall-clock minute, so
     * a flat 60-second TTL seeded mid-minute would still be live at the next poll and this
     * figure would sit a minute behind the chart beside it.
     *
     * @since 5.6.0
     * @return int
     */
    private static function online_count()
    {
        $transient_key = 'slimstat_adminbar_online_' . get_current_blog_id();
        $cached        = get_transient($transient_key);

        // Strict check: a legitimate count of 0 must not read as a cache miss, or an
        // idle site recomputes on every single render — the case this exists to fix.
        if (false !== $cached) {
            return (int) $cached;
        }

        $count = self::query_online_count();
        set_transient($transient_key, $count, max(60 - (current_time('timestamp') % 60), 1));

        return $count;
    }

    /**
     * The uncached query behind online_count().
     *
     * Kept separate so the cache cannot become part of the query's contract, and so the
     * two are independently testable. A derived-table GROUP BY over visit_id with a
     * post-aggregation HAVING, filtered by an OR spanning `dt` and `dt_out` — it needs an
     * index_merge to avoid a scan, and cannot get one on installs where the lazy
     * `idx_dt_out` migration has not run.
     *
     * @since 5.4.3
     * @return int
     */
    private static function query_online_count()
    {
        $wpdb = wp_slimstat::$wpdb;
        $table = "{$GLOBALS['wpdb']->prefix}slim_stats";
        $current_minute_start = (int) floor(wp_slimstat::now() / 60) * 60;
        $window_start = $current_minute_start - (29 * 60); // 30-minute window

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM (
                SELECT visit_id, MAX(
                    CASE
                        WHEN dt_out IS NOT NULL AND dt_out > 0 AND dt_out >= dt THEN dt_out
                        ELSE dt
                    END
                ) AS last_activity
                FROM {$table}
                WHERE visit_id > 0
                    AND (dt >= %d OR (dt_out IS NOT NULL AND dt_out >= %d))
                GROUP BY visit_id
                HAVING (FLOOR(last_activity / 60) * 60 + 59) >= %d
            ) live_sessions",
            $window_start, $window_start, $window_start
        ));

        return max(0, $count);
    }

    /**
     * AJAX handler to get current online visitors count
     */
    public static function get_online_visitors()
    {
        check_ajax_referer('meta-box-order', 'security');
        if (!self::check_ajax_view_capability()) {
            return;
        }

        $online_visitors = self::online_count();

        wp_send_json_success([
            'count' => $online_visitors,
            'formatted' => number_format_i18n($online_visitors)
        ]);
    }

    // END: get_online_visitors

    /**
     * AJAX handler: Returns all admin bar modal stats in a single request.
     * Called every minute by adminbar-realtime.js and admin.js.
     *
     * @since 5.4.3
     */
    public static function get_adminbar_stats()
    {
        check_ajax_referer('meta-box-order', 'security');
        if (!self::check_ajax_view_capability()) {
            return;
        }

        $is_pro = wp_slimstat::pro_is_installed();

        // --- Online count (cached for the current minute; see online_count()) ---
        $online_count = self::online_count();

        // --- Today stats (transient-cached, 60s TTL) ---
        $today_stats = self::adminbar_today_stats();

        // --- Chart data (uses LiveAnalyticsReport's own 60s transient) ---
        $chart_data = null;
        if ($is_pro) {
            try {
                $live_report = new \SlimStat\Reports\Types\Analytics\LiveAnalyticsReport();
                $chart_result = $live_report->get_users_chart_data();
                $chart_data = [
                    'data'       => $chart_result['data'],
                    'max_value'  => $chart_result['max_value'],
                    'peak_index' => $chart_result['peak_index'],
                ];
            } catch (\Exception $e) {
                // Graceful degradation — return stats without chart data
                $chart_data = null;
            }
        }

        // --- Build response ---
        $response = [
            'online' => [
                'count'     => $online_count,
                'formatted' => number_format_i18n($online_count),
            ],
            'sessions' => [
                'count'     => $today_stats['sessions'],
                'formatted' => number_format_i18n($today_stats['sessions']),
                'yesterday' => number_format_i18n($today_stats['sessions_yesterday']),
            ],
            'is_pro' => $is_pro,
        ];

        if ($is_pro) {
            $response['views'] = [
                'count'     => $today_stats['views'],
                'formatted' => number_format_i18n($today_stats['views']),
                'yesterday' => number_format_i18n($today_stats['views_yesterday']),
            ];
            $response['referrals'] = [
                'count'     => $today_stats['referrals'],
                'formatted' => number_format_i18n($today_stats['referrals']),
                'yesterday' => number_format_i18n($today_stats['referrals_yesterday']),
            ];
            $response['chart'] = $chart_data;
        }

        wp_send_json_success($response);
    }

    // END: get_adminbar_stats

    /**
     * Helper function to get icon URL for filter options
     */
    private static function get_filter_icon_url($dimension, $value)
    {
        $icon_url = '';

        switch ($dimension) {
            case 'country':
                // Country flags are SVG files named by country code (lowercase)
                $country_code = strtolower($value);
                $flag_rel = '/admin/assets/images/flags/' . $country_code . '.svg';
                $flag_path = SLIMSTAT_ANALYTICS_DIR . $flag_rel;
                if (is_readable($flag_path)) {
                    $icon_url = SLIMSTAT_ANALYTICS_URL . $flag_rel;
                }
                break;

            case 'browser':
                // Browser icons are PNG files named by browser name (lowercase)
                $browser_name = strtolower($value);
                $browser_rel = '/admin/assets/images/browsers/' . $browser_name . '.png';
                $browser_path = SLIMSTAT_ANALYTICS_DIR . $browser_rel;
                if (is_readable($browser_path)) {
                    $icon_url = SLIMSTAT_ANALYTICS_URL . $browser_rel;
                }
                break;

            case 'language':
                // Language flags use the last part of the language code (e.g., en-US -> us)
                $language_parts = explode('-', $value);
                $last_part = strtolower(end($language_parts));
                $flag_rel = '/admin/assets/images/flags/' . $last_part . '.svg';
                $flag_path = SLIMSTAT_ANALYTICS_DIR . $flag_rel;
                if (is_readable($flag_path)) {
                    $icon_url = SLIMSTAT_ANALYTICS_URL . $flag_rel;
                }
                break;

            case 'platform':
                // Platform/OS icons are WEBP files with abbreviated names
                $os_map = [
                    'win' => 'win',
                    'windows' => 'win',
                    'mac' => 'mac',
                    'macosx' => 'mac',
                    'linux' => 'lin',
                    'ubuntu' => 'ubu',
                    'android' => 'and',
                    'ios' => 'ios',
                    'chrome os' => 'chr',
                    'chromeos' => 'chr',
                ];

                $os_lower = strtolower($value);
                $os_icon = null;

                // Check if exact match exists in map
                if (isset($os_map[$os_lower])) {
                    $os_icon = $os_map[$os_lower];
                } else {
                    // Check if value contains any of the keys
                    foreach ($os_map as $key => $icon) {
                        if (strpos($os_lower, $key) !== false) {
                            $os_icon = $icon;
                            break;
                        }
                    }
                }

                if ($os_icon) {
                    $os_rel = '/admin/assets/images/os/' . $os_icon . '.webp';
                    $os_path = SLIMSTAT_ANALYTICS_DIR . $os_rel;
                    if (is_readable($os_path)) {
                        $icon_url = SLIMSTAT_ANALYTICS_URL . $os_rel;
                    }
                }
                break;

            case 'username':
                // For users, we'll use WordPress gravatar
                // This will be handled separately in the JavaScript
                break;
        }

        return $icon_url;
    }

    /**
     * AJAX handler to get distinct filter options for a selected dimension
     */
    public static function get_filter_options()
    {
        check_ajax_referer('meta-box-order', 'security');

        if (!self::can_view_stats()) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $dimension = sanitize_text_field($_POST['dimension'] ?? '');

        // Validate dimension exists in columns_names
        include_once(plugin_dir_path(__FILE__) . 'view/wp-slimstat-db.php');

        // We only need the columns_names array, not the full init with filters
        if (empty(wp_slimstat_db::$columns_names)) {
            wp_slimstat_db::$columns_names = [
                'id' => ['ID', 'number'],
                'ip' => ['IP', 'varchar'],
                'other_ip' => ['Other IP', 'varchar'],
                'username' => ['Username', 'varchar'],
                'email' => ['Email', 'varchar'],
                'country' => ['Country', 'varchar'],
                'location' => ['Location', 'varchar'],
                'city' => ['City', 'varchar'],
                'referer' => ['Referer', 'varchar'],
                'resource' => ['Resource', 'varchar'],
                'searchterms' => ['Search Terms', 'varchar'],
                'notes' => ['Notes', 'varchar'],
                'visit_id' => ['Visit ID', 'number'],
                'server_latency' => ['Server Latency', 'number'],
                'page_performance' => ['Page Performance', 'number'],
                'browser' => ['Browser', 'varchar'],
                'browser_version' => ['Browser Version', 'varchar'],
                'browser_type' => ['Browser Type', 'number'],
                'platform' => ['Platform', 'varchar'],
                'language' => ['Language', 'varchar'],
                'fingerprint' => ['Fingerprint', 'varchar'],
                'user_agent' => ['User Agent', 'varchar'],
                'resolution' => ['Resolution', 'varchar'],
                'screen_width' => ['Screen Width', 'number'],
                'screen_height' => ['Screen Height', 'number'],
                'content_type' => ['Content Type', 'varchar'],
                'category' => ['Category', 'varchar'],
                'author' => ['Author', 'varchar'],
                'content_id' => ['Content ID', 'number'],
                'outbound_resource' => ['Outbound Resource', 'varchar'],
                'tz_offset' => ['Timezone Offset', 'number'],
                'dt_out' => ['Date Time Out', 'number'],
                'dt' => ['Date Time', 'number'],
            ];
        }

        if (empty($dimension) || !isset(wp_slimstat_db::$columns_names[$dimension])) {
            wp_send_json_error('Invalid dimension');
            return;
        }

        // Resolve the report date picker's window (shared with the goals/funnels
        // AJAX handlers so autosuggest, Test, and lazy-load all read one range).
        list($time_start, $time_end) = self::resolve_requested_date_range();

        // Get distinct values for this dimension via SlimStat\Utils\Query abstraction
        $table_name = $GLOBALS['wpdb']->prefix . 'slim_stats';

        // Limit results to prevent overwhelming the dropdown (filterable for customization)
        $limit = apply_filters('slimstat_filter_options_limit', 500, $dimension);
        $limit = absint($limit); // Ensure it's a positive integer

        // Enforce reasonable bounds to prevent abuse
        if ($limit < 1 || $limit > 5000) {
            $limit = 500; // Reset to default if out of reasonable range
        }

        // Sanitize column name to prevent SQL injection (only allow known columns)
        $allowed_columns = array_keys(wp_slimstat_db::$columns_names);
        if (!in_array($dimension, $allowed_columns, true)) {
            wp_send_json_error('Invalid column');
            return;
        }

        // Additional sanitization layer for column name (defense in depth)
        $safe_dimension = esc_sql($dimension);

        // Get distinct non-empty values
        $column_type = wp_slimstat_db::$columns_names[$dimension][1];

        // Optional server-side search (layer 2 of #298). Only applies to varchar
        // columns — searching numeric dimensions falls back to the legacy DISTINCT.
        $search_raw = $_POST['search'] ?? '';
        $search = '';
        if (is_string($search_raw)) {
            $search = trim(sanitize_text_field($search_raw));
            if (strlen($search) < 2 || strlen($search) > 64) {
                $search = '';
            }
        }
        if ($column_type !== 'varchar') {
            $search = '';
        }

        // Cache lookup (layer 3 of #298). Key must account for anything that
        // changes the result set: blog, DB host (External DB addon), capability
        // gate, dimension, hour-bucketed time range, effective limit, and search.
        $cache_key = self::build_filter_options_cache_key(
            $dimension,
            $time_start,
            $time_end,
            $search,
            $limit
        );
        $cached = self::filter_options_cache_get($cache_key);
        if (is_array($cached)) {
            wp_send_json_success($cached);
            exit();
        }

        // Build SQL query directly to avoid Query class interference with global filters
        $where_clauses = [];

        // Apply time range filter
        if (!empty($time_start) && !empty($time_end)) {
            $where_clauses[] = $GLOBALS['wpdb']->prepare('dt BETWEEN %d AND %d', intval($time_start), intval($time_end));
        }

        if ($column_type === 'varchar') {
            // Exclude NULLs and empty strings for varchar columns
            $where_clauses[] = $safe_dimension . ' IS NOT NULL';
            $where_clauses[] = $safe_dimension . " <> ''";
        } else {
            // Exclude NULLs and zeros for numeric columns
            $where_clauses[] = $safe_dimension . ' IS NOT NULL';
            $where_clauses[] = $safe_dimension . ' <> 0';
        }

        // Append LIKE filter when a server-side search term was supplied.
        if ($search !== '') {
            $like_pattern    = self::build_filter_search_like($dimension, $search);
            $where_clauses[] = wp_slimstat::$wpdb->prepare($safe_dimension . ' LIKE %s', $like_pattern);
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Rank matches by relevance (exact, then prefix, then contains) so the LIMIT
        // keeps and orders the values the user actually typed first. (#21)
        $order_sql = self::build_filter_search_order($safe_dimension, $search);

        $sql = sprintf(
            'SELECT DISTINCT %s as value FROM %s %s ORDER BY %s LIMIT %d',
            $safe_dimension,
            $table_name,
            $where_sql,
            $order_sql,
            $limit
        );

        // Execute query — use wp_slimstat::$wpdb so External DB addon
        // queries the correct database.
        $results = wp_slimstat::$wpdb->get_results($sql, ARRAY_A);

        // Check for database errors
        if (wp_slimstat::$wpdb->last_error) {
            wp_send_json_error('Database query failed');
            return;
        }

        // Ensure results is an array
        if (!is_array($results)) {
            $results = [];
        }

        // Split multi-value columns into individual values.
        // These columns store multiple entries in a single DB field:
        //   outbound_resource: "url1;;;url2;;;url3"
        //   notes:             "[tag1][tag2][tag3]"
        //   category:          "1,5,12"
        $multi_value_separators = [
            'outbound_resource' => ';;;',
            'category'          => ',',
        ];

        if (isset($multi_value_separators[$dimension])) {
            $separator = $multi_value_separators[$dimension];
            $expanded = [];
            foreach ($results as $row) {
                if (empty($row['value'])) continue;
                foreach (explode($separator, $row['value']) as $val) {
                    $val = trim($val);
                    if ($val !== '') $expanded[] = ['value' => $val];
                }
            }
            $results = $expanded;
        } elseif ($dimension === 'notes') {
            $expanded = [];
            foreach ($results as $row) {
                if (empty($row['value'])) continue;
                preg_match_all('/\[([^\]]+)\]/', $row['value'], $matches);
                foreach ($matches[1] as $val) {
                    $val = trim($val);
                    if ($val !== '') $expanded[] = ['value' => $val];
                }
            }
            $results = $expanded;
        }

        // After splitting multi-value rows, re-filter split segments by the
        // server-side search term so the caller gets only matching segments,
        // not every segment from rows where any sibling matched.
        if ($search !== '' && (isset($multi_value_separators[$dimension]) || $dimension === 'notes')) {
            $has_mb       = function_exists('mb_strtolower');
            $needle       = $has_mb ? mb_strtolower($search) : strtolower($search);
            $is_substring = self::filter_search_is_substring($dimension);
            $results = array_values(array_filter($results, function ($row) use ($needle, $is_substring, $has_mb) {
                $haystack = $has_mb ? mb_strtolower($row['value']) : strtolower($row['value']);
                return $is_substring ? (strpos($haystack, $needle) !== false) : (strpos($haystack, $needle) === 0);
            }));
        }

        // Cap expanded results to prevent explosion from splitting
        if (count($results) > $limit) {
            $results = array_slice($results, 0, $limit);
        }

        $options = [];
        $seen_values = []; // Track values to prevent duplicates (case-insensitive)
        $dimensions_with_icons = ['country', 'browser', 'language', 'platform', 'username'];
        $has_icons = in_array($dimension, $dimensions_with_icons, true);

        foreach ($results as $row) {
            if (!empty($row['value'])) {
                // Sanitize output to prevent XSS
                $sanitized_value = sanitize_text_field($row['value']);

                // Trim whitespace
                $sanitized_value = trim($sanitized_value);

                // Skip empty values after trimming
                if (empty($sanitized_value)) {
                    continue;
                }

                // Check for duplicates using case-insensitive comparison
                $value_key = strtolower($sanitized_value);
                if (isset($seen_values[$value_key])) {
                    continue; // Skip duplicate
                }

                // Mark this value as seen
                $seen_values[$value_key] = true;

                // Limit individual option length to prevent DOM issues
                if (strlen($sanitized_value) > 255) {
                    $sanitized_value = substr($sanitized_value, 0, 255) . '...';
                }

                if ($has_icons) {
                    // Return object with value and icon
                    $icon_url = self::get_filter_icon_url($dimension, $sanitized_value);

                    // For username, get user gravatar
                    if ($dimension === 'username' && empty($icon_url)) {
                        $user = get_user_by('login', $sanitized_value);
                        if ($user) {
                            $icon_url = get_avatar_url($user->ID, ['size' => 32]);
                        } else {
                            $icon_url = get_avatar_url($sanitized_value, ['size' => 32]);
                        }
                    }

                    $options[] = [
                        'value' => $sanitized_value,
                        'label' => $sanitized_value,
                        'icon' => $icon_url
                    ];
                } else {
                    // Return simple string for backward compatibility
                    $options[] = $sanitized_value;
                }
            }
        }

        self::filter_options_cache_set(
            $cache_key,
            $options,
            self::filter_options_cache_ttl($time_end)
        );

        wp_send_json_success($options);
        exit();
    }

    /**
     * Whether the server-side filter search uses an unanchored (%needle%) LIKE for
     * this dimension instead of the default left-anchored prefix (needle%). Single
     * source of truth for the search-anchor decision (#298), shared by the SQL LIKE
     * builder and the post-split segment re-filter.
     */
    private static function filter_search_is_substring(string $dimension): bool
    {
        return in_array($dimension, self::FILTER_SEARCH_SUBSTRING_DIMENSIONS, true);
    }

    /**
     * Build the escaped, anchored LIKE pattern for a server-side filter search (#298).
     * The needle is run through wpdb::esc_like so SQL LIKE metacharacters (% _) are
     * matched literally; the caller still passes the result through wpdb::prepare.
     */
    private static function build_filter_search_like(string $dimension, string $search): string
    {
        $escaped = wp_slimstat::$wpdb->esc_like($search);
        return self::filter_search_is_substring($dimension) ? '%' . $escaped . '%' : $escaped . '%';
    }

    /**
     * Build the ORDER BY expression for a server-side filter search so matches rank
     * by relevance: exact first, then left-anchored prefix, then any other (substring)
     * match, alphabetical within each tier. Without this, ORDER BY column-only +
     * LIMIT could truncate or bury the exact/prefix values a user typed behind
     * incidental contains-matches (e.g. "/pricing" surfacing unrelated paths instead
     * of /pricing, /pricing/, /pricing?utm…). The column name is already validated
     * against the allowed-columns whitelist by the caller; the term is bound via
     * prepare(). (#21)
     */
    private static function build_filter_search_order(string $safe_dimension, string $search): string
    {
        if ('' === $search) {
            return $safe_dimension . ' ASC';
        }
        $prefix_like = wp_slimstat::$wpdb->esc_like($search) . '%';
        return wp_slimstat::$wpdb->prepare(
            'CASE WHEN ' . $safe_dimension . ' = %s THEN 0 WHEN ' . $safe_dimension . ' LIKE %s THEN 1 ELSE 2 END, ' . $safe_dimension . ' ASC',
            $search,
            $prefix_like
        );
    }

    /**
     * Composite cache key for get_filter_options(). Must include every variable
     * that changes the result set: blog (multisite), DB host (External DB addon
     * can point to another DB), capability gate (per-role visibility), dimension,
     * hour-bucketed time range (increases hit rate for rolling windows), the
     * effective limit (respects third-party `slimstat_filter_options_limit`
     * consumers), and the search term.
     */
    private static function build_filter_options_cache_key(
        string $dimension,
        ?int $time_start,
        ?int $time_end,
        string $search,
        int $limit
    ): string {
        $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        $dbhost  = '';
        if (isset(wp_slimstat::$wpdb) && is_object(wp_slimstat::$wpdb) && isset(wp_slimstat::$wpdb->dbhost)) {
            $dbhost = (string) wp_slimstat::$wpdb->dbhost;
        }
        $dbhost_hash = substr(md5($dbhost), 0, 8);
        $can_view        = (string) (wp_slimstat::$settings['can_view'] ?? '');
        $capability      = (string) (wp_slimstat::$settings['capability_can_view'] ?? '');
        $capability_hash = substr(md5($capability . '|' . $can_view), 0, 8);
        $ts_start_bucket = $time_start ? (int) floor((int) $time_start / 3600) : 0;
        $ts_end_bucket   = $time_end ? (int) floor((int) $time_end / 3600) : 0;
        $search_hash     = $search === '' ? '' : substr(md5($search), 0, 8);
        return sprintf(
            'fopts_%d_%s_%s_%s_%d_%d_%d_%s',
            $blog_id,
            $dbhost_hash,
            $capability_hash,
            $dimension,
            $ts_start_bucket,
            $ts_end_bucket,
            $limit,
            $search_hash
        );
    }

    private static function filter_options_cache_ttl(?int $time_end): int
    {
        // Historical data (range ends > 1h ago) never changes — cache it longer.
        if ($time_end && (int) $time_end < (time() - 3600)) {
            return 3600;
        }
        return 300;
    }

    private static function filter_options_cache_get(string $key)
    {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            $found = false;
            $data  = wp_cache_get($key, 'slimstat_filter_options', false, $found);
            return $found ? $data : null;
        }
        $data = get_transient('slimstat_' . $key);
        return $data === false ? null : $data;
    }

    private static function filter_options_cache_set(string $key, $data, int $ttl): void
    {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            wp_cache_set($key, $data, 'slimstat_filter_options', $ttl);
            return;
        }
        set_transient('slimstat_' . $key, $data, $ttl);
    }

    // END: get_filter_options

	public static function update_geoip_database()
	{
		check_ajax_referer('slimstat_geoip_action', 'security');

		if (!current_user_can(\wp_slimstat::$settings['capability_can_admin'])) {
			wp_send_json_error(__('Permission denied', 'wp-slimstat'));
			return;
		}

		try {
			$provider = \wp_slimstat::resolve_geolocation_provider();
			if (false === $provider) {
				wp_send_json_error(__('Geolocation is disabled.', 'wp-slimstat'));
				return;
			}
            if ('cloudflare' === $provider) {
                update_option('slimstat_last_geoip_dl', time());
                wp_send_json_success(__('Cloudflare geolocation does not require a database.', 'wp-slimstat'));
                return;
            }

            // License validation is handled by the MaxMind provider; do not pre-check here

            $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, []);
            $ok      = $service->updateDatabase();

			if ($ok) {
                update_option('slimstat_last_geoip_dl', time());
                wp_send_json_success(__('GeoIP Database Successfully Updated!', 'wp-slimstat'));
            } else {
                // Log the error for debugging
				$error_message = __('Failed to update GeoIP Database.', 'wp-slimstat');
				if ('maxmind' === $provider) {
					$error_message .= ' ' . __('Please check your MaxMind license key and try again.', 'wp-slimstat');
				}
				$geoip_error = get_option('slimstat_geoip_error', []);
				if (!empty($geoip_error) && !empty($geoip_error['error'])) {
					$error_message .= ' ' . sprintf(__('Details: %s', 'wp-slimstat'), $geoip_error['error']);
				}
				wp_send_json_error($error_message);
            }
        } catch (\Throwable $exception) {
            \wp_slimstat::log('GeoIP update AJAX error: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine(), 'error');
            wp_send_json_error(__('An unexpected error occurred while updating the GeoIP database.', 'wp-slimstat'));
        }
    }

	public static function check_geoip_database()
	{
		check_ajax_referer('slimstat_geoip_action', 'security');

		if (!current_user_can(\wp_slimstat::$settings['capability_can_admin'])) {
			wp_send_json_error(__('Permission denied', 'wp-slimstat'));
			return;
		}

		try {
			$provider = \wp_slimstat::resolve_geolocation_provider();
			if (false === $provider) {
				wp_send_json_error(__('Geolocation is disabled.', 'wp-slimstat'));
				return;
			}
            if ('cloudflare' === $provider) {
                wp_send_json_success(__('Cloudflare geolocation is active. No database to check.', 'wp-slimstat'));
                return;
            }
            $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, []);
            $exists  = file_exists($service->getProvider()->getDbPath());
            $result  = [ 'notice' => $exists ? __('GeoIP Database is present and ready.', 'wp-slimstat') : __('GeoIP Database not found.', 'wp-slimstat') ];

            wp_send_json_success($result['notice']);
        } catch (\Throwable $exception) {
            \wp_slimstat::log('GeoIP check AJAX error: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine(), 'error');
            wp_send_json_error(__('An unexpected error occurred while checking the GeoIP database.', 'wp-slimstat'));
        }
    }

    /**
     * Contextual help
     */
    public static function contextual_help()
    {
        $screen = get_current_screen();

        $screen->add_help_tab(
            [
                'id'      => 'wp-slimstat-definitions',
                'title'   => __('Definitions', 'wp-slimstat'),
                'content' => '<ul>
                    <li><b>' . __('Pageview', 'wp-slimstat') . '</b>: ' . __('A request to load a single HTML file ("page"). This should be contrasted with a "hit", which refers to a request for any file from a web server. Slimstat logs a pageview each time the tracking code is executed', 'wp-slimstat') . '</li>
                    <li><b>' . __('(Human) Visit', 'wp-slimstat') . '</b>: ' . __("A period of interaction between a visitor's browser and your website, ending when the browser is closed or when the user has been inactive on that site for 30 minutes", 'wp-slimstat') . '</li>
                    <li><b>' . __('Known Visitor', 'wp-slimstat') . '</b>: ' . __('Any user who has left a comment on your blog, and is thus identified by WordPress as a returning visitor', 'wp-slimstat') . '</li>
                    <li><b>' . __('Unique IP', 'wp-slimstat') . '</b>: ' . __('Used to differentiate between multiple requests to download a file from one internet address (IP) and requests originating from many distinct addresses; since this measurement looks only at the internet address a pageview came from, it is useful, but not perfect', 'wp-slimstat') . '</li>
                    <li><b>' . __('Originating IP', 'wp-slimstat') . '</b>: ' . __('the originating IP address of a client connecting to a web server through an HTTP proxy or load balancer', 'wp-slimstat') . '</li>
                    <li><b>' . __('Direct Traffic', 'wp-slimstat') . '</b>: ' . __('All those people showing up to your Web site by typing in the URL of your Web site coming or from a bookmark; some people also call this "default traffic" or "ambient traffic"', 'wp-slimstat') . '</li>
                    <li><b>' . __('Search Engine', 'wp-slimstat') . '</b>: ' . __('Google, Yahoo, MSN, Ask, others; this bucket will include both your organic as well as your paid (PPC/SEM) traffic, so be aware of that', 'wp-slimstat') . '</li>
                    <li><b>' . __('Search Terms', 'wp-slimstat') . '</b>: ' . __('Keywords used by your visitors to find your website on a search engine', 'wp-slimstat') . '</li>
                    <li><b>' . __('SERP', 'wp-slimstat') . '</b>: ' . __('Short for search engine results page, the Web page that a search engine returns with the results of its search. The value shown represents your rank (or position) within that list of results', 'wp-slimstat') . '</li>
                    <li><b>' . __('User Agent', 'wp-slimstat') . '</b>: ' . __('Any program used for accessing a website; this includes browsers, robots, spiders and any other program that was used to retrieve information from the site', 'wp-slimstat') . '</li>
                    <li><b>' . __('Outbound Link', 'wp-slimstat') . '</b>: ' . __('A link from one domain to another is said to be outbound from its source anchor and inbound to its target. This report lists all the links to other websites followed by your visitors.', 'wp-slimstat') . '</li>
                </ul>',
            ]
        );
        $screen->add_help_tab(
            [
                'id'      => 'wp-slimstat-basic-filters',
                'title'   => __('Basic Filters', 'wp-slimstat'),
                'content' => '<ul>
                    <li><b>' . __('Browser', 'wp-slimstat') . '</b>: ' . __('User agent (Firefox, Chrome, ...)', 'wp-slimstat') . '</li>
                    <li><b>' . __('Country Code', 'wp-slimstat') . '</b>: ' . __('2-letter code (us, ru, de, it, ...)', 'wp-slimstat') . '</li>
                    <li><b>' . __('IP', 'wp-slimstat') . '</b>: ' . __("Visitor's public IP address", 'wp-slimstat') . '</li>
                    <li><b>' . __('Search Terms', 'wp-slimstat') . '</b>: ' . __('Keywords used by your visitors to find your website on a search engine', 'wp-slimstat') . '</li>
                    <li><b>' . __('Language Code', 'wp-slimstat') . '</b>: ' . __('Please refer to this <a target="_blank" href="https://msdn.microsoft.com/en-us/library/ee825488(v=cs.20).aspx">language culture page</a> (first column) for more information', 'wp-slimstat') . '</li>
                    <li><b>' . __('Operating System', 'wp-slimstat') . '</b>: ' . __('Accepts identifiers like win7, win98, macosx, ...; please refer to <a target="_blank" href="https://php.net/manual/en/function.get-browser.php">this manual page</a> for more information', 'wp-slimstat') . '</li>
                    <li><b>' . __('Permalink', 'wp-slimstat') . '</b>: ' . __('URL accessed on your site', 'wp-slimstat') . '</li>
                    <li><b>' . __('Referer', 'wp-slimstat') . '</b>: ' . __('Complete address of the referrer page', 'wp-slimstat') . '</li>
                    <li><b>' . __("Visitor's Name", 'wp-slimstat') . '</b>: ' . __("Visitors' names according to the cookie set by WordPress after they leave a comment", 'wp-slimstat') . '</li>
                </ul>',
            ]
        );

        $screen->add_help_tab(
            [
                'id'      => 'wp-slimstat-advanced-filters',
                'title'   => __('Advanced Filters', 'wp-slimstat'),
                'content' => '<ul>
                        <li><b>' . __('Browser Version', 'wp-slimstat') . '</b>: ' . __('user agent version (9.0, 11, ...)', 'wp-slimstat') . '</li>
                        <li><b>' . __('Browser Type', 'wp-slimstat') . '</b>: ' . __('1 = search engine crawler, 2 = mobile device, 3 = syndication reader, 0 = all others', 'wp-slimstat') . '</li>
                        <li><b>' . __('Pageview Attributes', 'wp-slimstat') . '</b>: ' . __('this field is set to <em>[pre]</em> if the resource has been accessed through <a target="_blank" href="https://developer.mozilla.org/en/Link_prefetching_FAQ">Link Prefetching</a> or similar techniques', 'wp-slimstat') . '</li>
                        <li><b>' . __('Post Author', 'wp-slimstat') . '</b>: ' . __('author associated to that post/page when the resource was accessed', 'wp-slimstat') . '</li>
                        <li><b>' . __('Post Category ID', 'wp-slimstat') . '</b>: ' . __('ID of the category/term associated to the resource, when available', 'wp-slimstat') . '</li>
                        <li><b>' . __('Originating IP', 'wp-slimstat') . '</b>: ' . __("visitor's originating IP address, if available", 'wp-slimstat') . '</li>
                        <li><b>' . __('Resource Content Type', 'wp-slimstat') . '</b>: ' . __('post, page, cpt:<em>custom-post-type</em>, cpt:attachment, singular, post_type_archive, tag, taxonomy, category, date, author, archive, search, feed, home; please refer to the <a target="_blank" href="https://codex.wordpress.org/Conditional_Tags">Conditional Tags</a> manual page for more information', 'wp-slimstat') . '</li>
                        <li><b>' . __('Screen Resolution', 'wp-slimstat') . '</b>: ' . __('viewport width and height (1024x768, 800x600, ...)', 'wp-slimstat') . '</li>
                        <li><b>' . __('Visit ID', 'wp-slimstat') . '</b>: ' . __('generally used in conjunction with <em>is not empty</em>, identifies human visitors', 'wp-slimstat') . '</li>
                        <li><b>' . __('Date Filters', 'wp-slimstat') . '</b>: ' . __('you can specify the timeframe by entering a number in the <em>interval</em> field; use -1 to indicate <em>to date</em> (i.e., day=1, month=1, year=blank, interval=-1 will set a year-to-date filter)', 'wp-slimstat') . '</li>
                        <li><b>' . __('SERP Position', 'wp-slimstat') . '</b>: ' . __('set the filter to Referer contains cd=N&, where N is the position you are looking for', 'wp-slimstat') . '</li>
                </ul>',
            ]
        );
        return null;
    }

    // END: contextual_help

    public static function get_template($template, $args = [], $return = false)
    {
        // Push Args - use EXTR_SKIP to prevent variable overwriting for security
        if (is_array($args) && isset($args)) :
            extract($args, EXTR_SKIP);
        endif;

        // Check Load single file or array list
        if (is_string($template)) {
            $template = explode(' ', $template);
        }

        // Load File
        foreach ($template as $file) {
            $template_file = WP_PLUGIN_DIR . sprintf('/wp-slimstat/admin/view/partials/%s.php', $file);

            if (!file_exists($template_file)) {
                continue;
            }

            if ($return) {
                ob_start();
                require $template_file;

                return ob_get_clean();
            }

            // include File
            include $template_file;
        }

        return null;
    }

    public static function add_lock_export_button($_header_buttons = '', $_report_id = '')
    {
        // If the pro is active don't show it
        $pro_plugin_slug = 'wp-slimstat-pro/wp-slimstat-pro.php';
        if (is_plugin_active($pro_plugin_slug)) {
            return $_header_buttons;
        }

        // Define which reports get this new functionality
        $callback_args = \wp_slimstat_reports::$reports[$_report_id]['callback_args'] ?? [];
        if (empty($callback_args) || !array_key_exists('raw', $callback_args)) {
            return $_header_buttons;
        }

        // A report may declare itself non-exportable (a bool or a presence probe).
        // Don't offer an "Export" upgrade link where there's nothing to export —
        // a download-styled control that only routes to pricing is bait (FN-7/FN-17).
        if (array_key_exists('exportable', $callback_args)) {
            $exportable = is_callable($callback_args['exportable'])
                ? (bool) call_user_func($callback_args['exportable'])
                : (bool) $callback_args['exportable'];
            if (!$exportable) {
                return $_header_buttons;
            }
        }
        $utm_medium = empty($_report_id) ? 'report-unknown' : $_report_id;
        return '<a class="slimstat-upgrade-pro slimstat-filter-link slimstat-filter-temp button-export-to-xls slimstat-font-download is-not-pro noslimstat" title="' . __('Upgrade to Pro', 'wp-slimstat-pro') . '" href="https://wp-slimstat.com/pricing/?utm_source=admin&utm_medium=' . $utm_medium . '&utm_campaign=export" target="_blank"><span class="dashicons dashicons-download"></span>' . __('Export', 'wp-slimstat-pro') . '</a> ' . $_header_buttons;
    }

    /**
     * Goals & Funnels: surface the usage pill + CTA inside the postbox header
     * (left of the refresh / lock-export icons), and the subtitle directly under
     * the <h3>. Card partials no longer render this chrome themselves.
     */
    public static function register_goals_funnels_header_hooks(): void
    {
        add_filter('slimstat_report_header_buttons', [self::class, 'inject_goals_funnels_header_actions'], 20, 2);
        add_filter('slimstat_report_header_after_title', [self::class, 'inject_goals_funnels_header_subtitle'], 10, 2);
    }

    /**
     * Prepends the Goals/Funnels usage pill + "+ Add" CTA into the
     * .slimstat-header-buttons container so they sit on the LEFT side.
     * Empty for the Free locked Funnels branch (render helper returns '').
     */
    public static function inject_goals_funnels_header_actions($_header_buttons = '', $_report_id = '')
    {
        if ('slim_p9_01' === $_report_id) {
            $actions = \wp_slimstat_reports::render_goals_card_actions();
            return $actions . $_header_buttons;
        }
        if ('slim_p9_02' === $_report_id) {
            $actions = \wp_slimstat_reports::render_funnels_card_actions();
            return $actions . $_header_buttons;
        }
        return $_header_buttons;
    }

    /**
     * Renders the Goals/Funnels card subtitle directly under the postbox <h3>.
     * Strings match the previous in-card markup so existing translations carry over.
     */
    public static function inject_goals_funnels_header_subtitle($_html = '', $_report_id = '')
    {
        if ('slim_p9_01' === $_report_id) {
            return '<p class="slimstat-gf-postbox-subtitle">' . esc_html__('A Goal is one question you ask of your traffic.', 'wp-slimstat') . '</p>';
        }
        if ('slim_p9_02' === $_report_id) {
            return '<p class="slimstat-gf-postbox-subtitle">' . esc_html__('String 2 to 5 steps into a journey. A funnel shows the conversion rate and exact drop-off at each stage.', 'wp-slimstat') . '</p>';
        }
        return $_html;
    }

    public static function add_header()
    {
        if (isset($_GET['page']) && ('slimlayout' === $_GET['page'] || 'slimconfig' === $_GET['page'])) {
            return self::get_template('header', ['is_pro' => wp_slimstat::pro_is_installed()]);
        }

        return null;
    }

    /**
     * Index definitions for all AJAX-managed database indexes.
     * Each entry maps an AJAX action (nonce) to its index metadata.
     */
    private static function get_index_definitions(): array
    {
        $prefix = $GLOBALS['wpdb']->prefix;

        // The AJAX actions are a stable public contract — they are baked into nonces and into
        // the notice's markup — so the action names stay written out. Everything they DESCRIBE
        // comes from the manifest, because this was the sixth creator of the same six indexes
        // and the one furthest from the other five: an index whose columns changed anywhere else
        // left this UI quietly building the old shape on click.
        $actions = [
            'slimstat_add_country_dt_index'  => 'idx_country_dt',
            'slimstat_add_dt_screen_index'   => 'idx_dt_screen_width_screen_height',
            'slimstat_add_dt_browser_index'  => 'idx_dt_browser_browser_version',
            'slimstat_add_dt_platform_index' => 'idx_dt_platform',
            'slimstat_add_dt_out_index'      => 'idx_dt_out',
            'slimstat_add_dt_visit_index'    => '{prefix}stats_dt_visit_idx',
        ];

        $definitions = [];

        foreach ($actions as $action => $index) {
            $definitions[$action] = [
                'index'  => $index,
                'name'   => Schema::resolve($index, $prefix),
                'option' => Schema::indexOption($index),
            ];
        }

        return $definitions;
    }

    /**
     * Stamp any `slimstat_*_indexed` option whose index is present, in one query.
     *
     * Returns the manifest keys still MISSING, so the notice below does not repeat the probe.
     *
     * @return string[]
     */
    private static function sync_index_options(): array
    {
        $pending = [];

        foreach (self::get_index_definitions() as $def) {
            if ('yes' !== get_option($def['option'])) {
                $pending[] = $def['index'];
            }
        }

        if ([] === $pending) {
            return [];
        }

        $state   = Schema::indexState(wp_slimstat::$wpdb, 'slim_stats', $GLOBALS['wpdb']->prefix);
        $present = array_flip($state['present']);
        $missing = [];

        foreach ($pending as $index) {
            if (isset($present[Schema::resolve($index, $GLOBALS['wpdb']->prefix)])) {
                update_option(Schema::indexOption($index), 'yes');
                continue;
            }

            $missing[] = $index;
        }

        return $missing;
    }

    /**
     * Generic AJAX handler for ensuring a database index exists.
     */
    private static function ajax_ensure_index(string $nonce, string $index_key, string $index_name, string $option_key): void
    {
        check_ajax_referer($nonce);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'wp-slimstat'));
        }

        // The kill switch reaches THIS path too, and that is not belt-and-braces.
        // show_indexes_notice() suppresses itself whenever the modern runner is registered,
        // so disabling the modern runner UN-SUPPRESSES this notice — and its Apply buttons
        // land here, running CREATE INDEX on wp_slim_stats. An admin who set the constant to
        // stop a runaway index build would have been handed an unguarded UI offering the same
        // DDL, which is the outcome the switch exists to prevent.
        if (defined('SLIMSTAT_DISABLE_MIGRATIONS') && SLIMSTAT_DISABLE_MIGRATIONS) {
            wp_send_json_error(__('Migrations are disabled by SLIMSTAT_DISABLE_MIGRATIONS in wp-config.php.', 'wp-slimstat'));
        }

        $wpdb   = wp_slimstat::$wpdb;
        $prefix = $GLOBALS['wpdb']->prefix;
        $table  = $prefix . 'slim_stats';

        $exists = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index_name));
        if (!empty($exists)) {
            update_option($option_key, 'yes');
            wp_send_json_success(__('Index already exists.', 'wp-slimstat'));
        }

        // Built from the manifest, not from the columns this handler was handed. The button and
        // the reconciler have to create the same object or the retry silently builds a shape
        // nothing else expects.
        $result = $wpdb->query(Schema::createIndexSql('slim_stats', $index_key, $prefix));
        if (false !== $result) {
            update_option($option_key, 'yes');
            wp_send_json_success(__('Index added successfully.', 'wp-slimstat'));
        }
        wp_send_json_error(__('Unable to add index.', 'wp-slimstat'));
    }

    /**
     * Register AJAX hooks for all index management actions.
     */
    public static function register_index_hooks(): void
    {
        foreach (self::get_index_definitions() as $action => $def) {
            add_action('wp_ajax_' . $action, function () use ($action, $def) {
                self::ajax_ensure_index($action, $def['index'], $def['name'], $def['option']);
            });
        }
    }

    /**
     * Warn administrators when a SlimStat sub-feature is running degraded.
     *
     * The fail-soft guards added for issue #325 stop a class-load failure from
     * white-screening the site, but they used to leave no trace outside WP_DEBUG —
     * so a dead tracker or missing consent banner could go unnoticed for months.
     * wp_slimstat::record_degradation() persists what broke; this surfaces it.
     */
    public static function show_degradation_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $degradations = wp_slimstat::get_degradations();
        if (!$degradations) {
            return;
        }

        // Human-readable name per guarded step; unknown keys are humanised, never printed raw.
        $labels = [
            'browscap'           => __('Browser detection library', 'wp-slimstat'),
            'upload_directory'   => __('Upload directory setup', 'wp-slimstat'),
            'ip_hash_salt'       => __('Daily IP-hash salt (privacy)', 'wp-slimstat'),
            'adblock_bypass'     => __('Ad-blocker bypass tracking', 'wp-slimstat'),
            'rest_api'           => __('REST API tracking endpoints', 'wp-slimstat'),
            'consent_ajax'       => __('Consent AJAX handlers', 'wp-slimstat'),
            'gdpr_banner'          => __('GDPR consent banner setup', 'wp-slimstat'),
            'gdpr_banner_assets'   => __('GDPR consent banner assets', 'wp-slimstat'),
            'gdpr_banner_render'   => __('GDPR consent banner display', 'wp-slimstat'),
            'rest_controller'      => __('REST tracking controller', 'wp-slimstat'),
            'rest_routes'          => __('REST route registration', 'wp-slimstat'),
            'banner_consent_check' => __('Consent banner status check', 'wp-slimstat'),
            'activation'           => __('Plugin activation', 'wp-slimstat'),
            'deactivation'         => __('Plugin deactivation', 'wp-slimstat'),

            // Operational steps. Everything below kept working; none of it is a load failure,
            // and none of it is fixed by reinstalling.
            'schema column drift'  => __('Database columns differ from this version', 'wp-slimstat'),
            'schema upgrade'       => __('Database schema upgrade', 'wp-slimstat'),
            'schema repair from the tracking path' => __('Database repair during tracking', 'wp-slimstat'),
            'notes format migration' => __('Notes format migration', 'wp-slimstat'),
            'utf8mb4 conversion'   => __('Character-set conversion', 'wp-slimstat'),
            'migration_db_unreachable' => __('Database unreachable during migration', 'wp-slimstat'),
            'add_visit_identity'   => __('Migration: visit identity column', 'wp-slimstat'),
            'add_user_agent_dimension' => __('Migration: browser dimension column', 'wp-slimstat'),
            'event insert stored no row' => __('Event could not be recorded', 'wp-slimstat'),
            'anonymous visit reuse'      => __('Cookieless visit grouping', 'wp-slimstat'),
            'purge (archiving events)'    => __('Retention: archiving events', 'wp-slimstat'),
            'purge (deleting events)'     => __('Retention: deleting events', 'wp-slimstat'),
            'purge (archiving pageviews)' => __('Retention: archiving pageviews', 'wp-slimstat'),
            'purge (deleting pageviews)'  => __('Retention: deleting pageviews', 'wp-slimstat'),
            'purge (archive schema)'      => __('Retention: archive table columns', 'wp-slimstat'),
            // Synthesised inside get_degradations() from the last-success stamp, not recorded
            // by any call site — so a scan that derives its population from record_degradation()
            // arguments cannot see it, and it went unlabelled precisely because of that.
            'purge (no successful run)'   => __('Retention has not completed recently', 'wp-slimstat'),
        ];

        $load_items        = '';
        $operational_items = '';

        foreach ($degradations as $step => $record) {
            // Three step keys are BUILT AT RUNTIME — 'activation (blog N)', 'new subsite N' and
            // 'tracker write dropped columns absent from <table>' — so no exact-match map can
            // ever cover them. Humanising the fallback is not polish; it is the only thing that
            // covers those three, and it is why the map is not simply longer.
            $label   = $labels[$step] ?? ucfirst(str_replace('_', ' ', $step));
            $message = isset($record['message']) ? (string) $record['message'] : '';
            $item    = sprintf(
                '<li><strong>%s</strong><br><code>%s</code></li>',
                esc_html($label),
                esc_html($message)
            );

            // The KIND IS READ, NOT GUESSED. It was briefly reconstructed here from a
            // renderer-side list of step names — which has to be kept in sync with two dozen
            // call sites by discipline, and cannot cover the three keys built by concatenation
            // at runtime. record_degradation() is told which it is at the catch block that
            // knows. Legacy records written before that parameter existed default to LOAD,
            // which is what they all were; they age out within DEGRADATION_TTL anyway.
            $severity = isset($record['severity']) ? (string) $record['severity'] : wp_slimstat::DEGRADATION_LOAD;

            if (wp_slimstat::DEGRADATION_OPERATIONAL === $severity) {
                $operational_items .= $item;
            } else {
                $load_items .= $item;
            }
        }

        // TWO NOTICES, TWO SEVERITIES, because one sentence cannot be true of both.
        //
        // The original copy — "failed to load and were disabled … reinstalling the plugin and
        // flushing your PHP opcache normally clears it" — is exactly right for the #325 class it
        // was written for, and false for every operational record. Schema drift is not fixed by
        // reinstalling; a failed purge is not fixed by reinstalling. AbstractMigration.php's own
        // comment records rejecting this channel for that reason. So the copy stays where it is
        // true and the rest gets copy that is.
        if ('' !== $load_items) {
            self::show_message(
                '<strong>' . esc_html__('Slimstat Analytics is running with reduced functionality.', 'wp-slimstat') . '</strong><br>' .
                esc_html__('These features failed to load and were disabled so the rest of your site keeps working. This usually means an interrupted update or a stale server cache — reinstalling the plugin and flushing your PHP opcache normally clears it.', 'wp-slimstat') .
                '<ul class="ul-disc">' . $load_items . '</ul>',
                'error'
            );
        }

        if ('' !== $operational_items) {
            self::show_message(
                '<strong>' . esc_html__('Slimstat Analytics reported a problem while running.', 'wp-slimstat') . '</strong><br>' .
                esc_html__('Tracking and reports kept working. These are maintenance tasks that did not complete — some data may be missing or a column may be absent. Reinstalling the plugin does not clear these; check Slimstat > Migrations and Settings > Maintenance.', 'wp-slimstat') .
                '<ul class="ul-disc">' . $operational_items . '</ul>',
                'warning'
            );
        }
    }

    public static function show_indexes_notice()
    {
		// Suppress this legacy notice when the migration system is actually running, which
		// is what `has_action()` answers. The previous test was class_exists() on the
		// class file — always true under a classmap autoloader, so this returned here on
		// every install while the new system was not wired up at all. Two repair paths,
		// both dead, each because of the other. (D51)
		if (has_action('wp_ajax_slimstat_run_migrations')) {
			return;
		}

		// Offering no button beats refusing the click. Without this, throwing the kill switch
		// makes the test above false and renders this notice — the legacy repair UI — in
		// place of the guarded one.
		if (defined('SLIMSTAT_DISABLE_MIGRATIONS') && SLIMSTAT_DISABLE_MIGRATIONS) {
			return;
		}

        if (!current_user_can('manage_options')) {
            return;
        }
        // Human-readable copy only. The index key, its option, its AJAX action and the prefix
        // resolution all come from get_index_definitions(), which reads the manifest — this was
        // a seventh private restatement of that map, and the one place still hand-concatenating
        // the prefix onto an index name. An index renamed in Schema used to leave this notice
        // probing a key that no longer existed and offering a button forever.
        $copy = [
            'idx_dt_out' => [
                'id'    => 'dt-out',
                'label' => __('Currently Online Reports', 'wp-slimstat'),
                'desc'  => __('Index on <code>dt_out</code>', 'wp-slimstat'),
            ],
            'idx_country_dt' => [
                'id'    => 'country-dt',
                'label' => __('World Map & Country Reports', 'wp-slimstat'),
                'desc'  => __('Index on <code>country</code> and <code>dt</code>', 'wp-slimstat'),
            ],
            'idx_dt_screen_width_screen_height' => [
                'id'    => 'dt-screen',
                'label' => __('Screen Resolution Reports', 'wp-slimstat'),
                'desc'  => __('Index on <code>dt</code>, <code>screen_width</code>, <code>screen_height</code>', 'wp-slimstat'),
            ],
            'idx_dt_browser_browser_version' => [
                'id'    => 'dt-browser',
                'label' => __('Browser Reports', 'wp-slimstat'),
                'desc'  => __('Index on <code>dt</code>, <code>browser</code>, <code>browser_version</code>', 'wp-slimstat'),
            ],
            'idx_dt_platform' => [
                'id'    => 'dt-platform',
                'label' => __('Platform Reports', 'wp-slimstat'),
                'desc'  => __('Index on <code>dt</code>, <code>platform</code>', 'wp-slimstat'),
            ],
            '{prefix}stats_dt_visit_idx' => [
                'id'    => 'dt-visit',
                'label' => __('Visitor Counter Performance', 'wp-slimstat'),
                'desc'  => __('Index on <code>dt</code>, <code>visit_id</code>', 'wp-slimstat'),
            ],
        ];

        // Already computed on this request by init()'s sync, in one query rather than six.
        $missing = self::sync_index_options();
        if ([] === $missing) {
            return;
        }

        $pending = [];
        foreach (self::get_index_definitions() as $action => $def) {
            if (!in_array($def['index'], $missing, true) || !isset($copy[$def['index']])) {
                continue;
            }

            $pending[] = $copy[$def['index']] + [
                'option' => $def['option'],
                'key'    => $def['name'],
                'ajax'   => $action,
                'btn'    => __('Apply', 'wp-slimstat'),
            ];
        }

        if ([] === $pending) {
            return;
        }
        $ajax_url = admin_url('admin-ajax.php');

        // Generate nonces for each AJAX action
        $nonces = [];
        foreach ($pending as $idx) {
            $nonces[$idx['ajax']] = wp_create_nonce($idx['ajax']);
        }

        echo '<div class="notice slimstat-indexes-notice slimstat-notice" style="border-left: 6px solid #0073aa; background: #fff; box-shadow: 0 2px 8px #0001; padding: 24px 24px 16px 24px; margin-bottom: 24px; position: relative; min-width: 400px; max-width: 700px;">';
        echo '<h2 style="margin-top:0; font-size:1.3em; color:#0073aa;">' . __('Improve SlimStat Report Performance', 'wp-slimstat') . '</h2>';
        echo '<p style="margin-bottom:18px;">' . __('To speed up SlimStat reports, please apply the following database optimizations. These changes are safe and will not affect your data.', 'wp-slimstat') . '</p>';
        echo '<ul id="slimstat-index-list" style="list-style:none; margin:0 0 18px 0; padding:0;">';
        foreach ($pending as $idx) {
            echo '<li id="slimstat-index-' . $idx['id'] . '" style="margin-bottom:12px; display:flex; align-items:center;">'
                . '<div style="flex:1 1 0;">'
                . '<div class="slimstat-index-label" style="font-weight:600;">' . $idx['label'] . '</div>'
                . '<div class="slimstat-index-desc" style="color:#666; font-size:0.97em; margin-top:2px;">' . $idx['desc'] . '</div>'
                . '</div>'
                . '<span class="slimstat-index-lamp" style="margin-left:18px; min-width:30px; display:inline-block; font-size:1.5em; vertical-align:middle;">'
                . '<span class="dashicons dashicons-lightbulb" style="color:#ccc;"></span>'
                . '</span>'
                . '<span class="slimstat-index-status" style="margin-left:10px; min-width:120px; display:inline-block;"></span>'
                . '</li>';
        }
        echo '</ul>';
        echo '<div id="slimstat-index-progress-bar" style="height:8px; background:#e5e5e5; border-radius:4px; overflow:hidden; margin-bottom:10px;">'
            . '<div id="slimstat-index-progress" style="height:100%; width:0; background:linear-gradient(90deg,#0073aa,#00c3aa); transition:width 0.4s;"></div>'
            . '</div>';
        echo '<button class="button button-primary" id="slimstat-apply-all" style="margin-bottom:10px; min-width:120px; font-size:1.1em;">' . __('Apply All', 'wp-slimstat') . '</button>';
        echo '<div style="color:#888; font-size:0.95em;">' . __('Do not close this tab until all optimizations are complete.', 'wp-slimstat') . '</div>';
        echo '</div>';
        ?>
        <script>
        jQuery(function($){
            var indexes = <?php echo wp_json_encode($pending); ?>;
            var nonces = <?php echo wp_json_encode($nonces); ?>;
            var total = indexes.length, done = 0;
            function updateProgress() {
                // Multiply first, divide once — same contract as the PHP percentages in this
                // file. Inline JS, so the PHP scan in tests/rounding-contract-test.php sees this
                // as T_INLINE_HTML and cannot read it; the JS twin scan is what covers it.
                // ADR-17; PITFALLS 72.
                var percent = Math.round((100 * done) / total);
                $('#slimstat-index-progress').css('width', percent+'%');
                if (done === total) setTimeout(function(){ $('.slimstat-indexes-notice').fadeOut(); }, 2000);
            }
            function markDone(id) {
                var lamp = $('#slimstat-index-'+id+' .slimstat-index-lamp .dashicons');
                lamp.css('color','#ffc107'); // yellow lamp
                lamp.addClass('slimstat-lamp-on');
                $('#slimstat-index-'+id).css('opacity',0.9);
            }
            $('#slimstat-apply-all').on('click', function(e){
                e.preventDefault();
                var btn = $(this);
                btn.prop('disabled', true);
                window.onbeforeunload = function(){ return '<?php echo esc_js(__('Please wait for SlimStat optimizations to finish.', 'wp-slimstat')); ?>'; };
                function next(i) {
                    if (i >= indexes.length) {
                        window.onbeforeunload = null;
                        return;
                    }
                    var idx = indexes[i];
                    var li = $('#slimstat-index-'+idx.id);
                    li.find('.slimstat-index-status').html('<span style="color:#0073aa;">' + '<?php echo esc_js(__('In progress...', 'wp-slimstat')); ?>' + '</span> <span class="spinner is-active" style="float:none;display:inline-block;vertical-align:middle;"></span>');
                    $.post('<?php echo $ajax_url; ?>', {
                        action: idx.ajax,
                        _ajax_nonce: nonces[idx.ajax]
                    }, function(response){
                        if (response.success) {
                            markDone(idx.id);
                            done++;
                            li.find('.slimstat-index-status').html('<span style="color:green;">' + '<?php echo esc_js(__('Done!', 'wp-slimstat')); ?>' + '</span>');
                            updateProgress();
                            next(i+1);
                        } else {
                            li.find('.slimstat-index-status').html('<span style="color:red;">' + '<?php echo esc_js(__('Error: ', 'wp-slimstat')); ?>' + '</span>' + (response.data || ''));
                            btn.prop('disabled', false);
                            window.onbeforeunload = null;
                        }
                    });
                }
                next(0);
            });
        });
        </script>
        <?php
    }

}
// END: class declaration
