<?php
/*
 * Plugin Name: SlimStat Analytics
 * Plugin URI: https://wp-slimstat.com/
 * Description: The leading web analytics plugin for WordPress
 * Version: 5.4.0
 * Author: Jason Crouse, VeronaLabs
 * Text Domain: wp-slimstat
 * Domain Path: /languages
 * Author URI: https://wp-slimstat.com/
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.6
 * Requires PHP: 7.4
*/

// check if composer autoloader exists
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    return;
}

// Set the plugin version and directory
define('SLIMSTAT_ANALYTICS_VERSION', '5.4.0');

define('SLIMSTAT_FILE', __FILE__);
define('SLIMSTAT_DIR', __DIR__);
define('SLIMSTAT_URL', plugins_url('', __FILE__));

// include the autoloader if it exists
require_once __DIR__ . '/vendor/autoload.php';


// Include Constants.php to make SLIMSTAT_ANALYTICS_DIR available to traits
require_once __DIR__ . '/src/Constants.php';

class wp_slimstat
{
    public static $settings = [];

    public static $wpdb;
    public static $upload_dir = '';

    public static $update_checker = [];
    public static $raw_post_array = [];

    public static $data_js           = ['id' => 0];
    public static $stat              = [];
    protected static $date_i18n_filters = [];

    /**
     * Initializes variables and actions
     */
    public static function init()
    {
        \SlimStat\Providers\RESTService::run();

        // Load all the settings
        if (is_network_admin() && (empty($_GET['page']) || false === strpos($_GET['page'], 'slimview'))) {
            self::$settings = get_site_option('slimstat_options', []);
        } else {
            self::$settings = get_option('slimstat_options', []);
        }

        if (empty(self::$settings)) {
            // Save the default values in the database
            self::update_option('slimstat_options', self::init_options());
        }

        self::$settings = array_merge(self::init_options(), self::$settings);

        // Allow third party tools to edit the options
        self::$settings = apply_filters('slimstat_init_options', self::$settings);

        // Allow third-party tools to use a custom database for Slimstat
        self::$wpdb = apply_filters('slimstat_custom_wpdb', $GLOBALS['wpdb']);

        // Define the folder where to store the geolocation database (shared among sites in a network, by default)
        if (defined('UPLOADS')) {
            self::$upload_dir = ABSPATH . UPLOADS . '/wp-slimstat';
        } else {
            $upload_dir_info  = wp_upload_dir();
            self::$upload_dir = $upload_dir_info['basedir'];

            // Handle multisite environment
            if (is_multisite() && !(is_main_network() && is_main_site() && defined('MULTISITE'))) {
                self::$upload_dir = str_replace('/sites/' . get_current_blog_id(), '', self::$upload_dir);
            }

            self::$upload_dir .= '/wp-slimstat';
        }

        // Apply filter to allow customization of the upload directory
        self::$upload_dir = apply_filters('slimstat_maxmind_path', self::$upload_dir);

        // Allow add-ons to turn off the tracker based on other conditions
        $is_tracking_filter    = apply_filters('slimstat_filter_pre_tracking', false === strpos(self::get_request_uri(), 'wp-admin/admin-ajax.php'));
        $is_tracking_filter_js = apply_filters('slimstat_filter_pre_tracking_js', true);

        // Enable the tracker (both server- and client-side)
        if ((!is_admin() || 'on' == self::$settings['track_admin_pages']) && 'on' == self::$settings['is_tracking'] && $is_tracking_filter) {

            // Is server-side tracking active?
            if ('on' != self::$settings['javascript_mode']) {
                add_action(is_admin() ? 'admin_init' : 'wp', [\SlimStat\Tracker\Tracker::class, 'slimtrack'], 5);

                if ('on' != self::$settings['ignore_wp_users']) {
                    add_action('login_init', [\SlimStat\Tracker\Tracker::class, 'slimtrack'], 10);
                }
            }

            // Slimstat tracks screen resolutions, outbound links and other client-side information using a client-side tracker
            add_action(is_admin() ? 'admin_enqueue_scripts' : 'wp_enqueue_scripts', [self::class, 'enqueue_tracker'], 15);
            if ('on' != self::$settings['ignore_wp_users']) {
                add_action('login_enqueue_scripts', [self::class, 'enqueue_tracker'], 10);
            }

            add_filter('script_loader_tag', [self::class, 'add_defer_to_script_tag'], 10, 2);
        }

        // Hook a DB clean-up routine to the daily cronjob
        add_action('wp_slimstat_purge', [self::class, 'wp_slimstat_purge']);

        // Hook a GeoIP database update routine to the daily cronjob
        add_action('wp_slimstat_update_geoip_database', [self::class, 'wp_slimstat_update_geoip_database']);

        // Allow external domains on CORS requests
        add_filter('allowed_http_origins', [self::class, 'open_cors_admin_ajax']);

                // GDPR: Opt-out Ajax Handler
        if (defined('DOING_AJAX') && DOING_AJAX) {
            add_action('wp_ajax_slimstat_optout_html', [self::class, 'get_optout_html']);
            add_action('wp_ajax_nopriv_slimstat_optout_html', [self::class, 'get_optout_html']);
        }

        // If this request was a redirect, we should update the content type accordingly
        add_filter('wp_redirect_status', [\SlimStat\Tracker\Tracker::class, 'update_content_type'], 10, 2);

        // Shortcodes
        add_shortcode('slimstat', [self::class, 'slimstat_shortcode'], 15);

        // Init the plugin functionality
        add_action('init', [self::class, 'init_plugin']);

        // REST API Support
        add_action('rest_api_init', [self::class, 'register_rest_route']);

        // Load the admin library
        if (is_user_logged_in()) {
            include_once(plugin_dir_path(__FILE__) . 'admin/index.php');
            add_action('init', ['wp_slimstat_admin', 'init'], 60);
        }
    }
    // end init

    /**
     * The main logging function
     *
     * @param string $message The message to be logged.
     * @param string $level   The log level (e.g., 'info', 'warning', 'error'). Default is 'info'.
     *
     * @uses error_log
     */
    public static function log($message, $level = 'info')
    {
        if (is_array($message)) {
            $message = wp_json_encode($message);
        }

        $log_level = strtoupper($level);

        // Log when debug is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[WP SLIMSTAT] [%s]: %s', $log_level, $message));
        }
    }

    /**
     * Decodes the permalink
     */
    public static function get_request_uri()
    {
        $request_url = '';

        if (isset($_SERVER['REQUEST_URI'])) {
            return urldecode(sanitize_url(wp_unslash($_SERVER['REQUEST_URI'])));
        } elseif (isset($_SERVER['SCRIPT_NAME'])) {
            $request_url = sanitize_text_field(wp_unslash($_SERVER['SCRIPT_NAME']));
        } elseif (isset($_SERVER['PHP_SELF'])) {
            $request_url = sanitize_text_field(wp_unslash($_SERVER['PHP_SELF']));
        }

        if (isset($_SERVER['QUERY_STRING'])) {
            $request_url .= '?' . sanitize_text_field(wp_unslash($_SERVER['QUERY_STRING']));
        }

        return $request_url;
    }

    // end get_request_uri

    public static function is_local_ip_address($ip_address = '')
    {
        return !filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * Implements the Slimstat Shortcode API
     */
    public static function slimstat_shortcode($_attributes = '', $_content = '')
    {
        shortcode_atts([
            'f' => '',    // recent, popular, count, widget
            'w' => '',    // column to use (for recent, popular and count) or widget to use
            's' => ' ',    // separator
            'o' => 0,    // offset for counters
        ], $_attributes);

        $f         = $_attributes['f'] ?? '';
        $w         = $_attributes['w'] ?? '';
        $s         = $_attributes['s'] ?? '';
        $o         = $_attributes['o'] ?? 0;
        $output    = '';
        $where     = '';
        $as_column = '';
        $s         = sprintf("<span class='slimstat-item-separator'>%s</span>", $s);

        // Load the localization files (for languages, operating systems, etc)
        load_plugin_textdomain('wp-slimstat', false, '/wp-slimstat/languages');

        // Look for required fields
        if (empty($f) || empty($w)) {
            return '<!-- Slimstat Shortcode Error: missing parameter -->';
        }

        // Validation the parameter w
        if (false == in_array($w, ['count', 'display_name', 'hostname', 'post_link', 'post_link_no_qs', 'dt', 'username', 'post_link', 'ip', 'id', 'searchterms', 'username', 'resource', 'slim_p1_01', 'slim_p1_03', 'slim_p1_04', 'slim_p1_06', 'slim_p1_08', 'slim_p1_10', 'slim_p1_11', 'slim_p1_12', 'slim_p1_13', 'slim_p1_15', 'slim_p1_17', 'slim_p1_18', 'slim_p1_19_01', 'slim_p2_01', 'slim_p2_02', 'slim_p2_03', 'slim_p2_04', 'slim_p2_05', 'slim_p2_06', 'slim_p2_07', 'slim_p2_08', 'slim_p2_12', 'slim_p2_13', 'slim_p2_14', 'slim_p2_15', 'slim_p2_16', 'slim_p2_17', 'slim_p2_18', 'slim_p2_19', 'slim_p2_20', 'slim_p2_21', 'slim_p2_22_01', 'slim_p2_24', 'slim_p2_25', 'slim_p3_01', 'slim_p3_02', 'slim_p4_01', 'slim_p4_02', 'slim_p4_04', 'slim_p4_05', 'slim_p4_06', 'slim_p4_07', 'slim_p4_09', 'slim_p4_10', 'slim_p4_11', 'slim_p4_12', 'slim_p4_13', 'slim_p4_15', 'slim_p4_16', 'slim_p4_18', 'slim_p4_19', 'slim_p4_20', 'slim_p4_21', 'slim_p4_22', 'slim_p4_23', 'slim_p4_24', 'slim_p4_25', 'slim_p4_26_01', 'slim_p4_27', 'slim_p6_01', 'slim_p2_23'])) {
            return '<!-- Slimstat Shortcode Error: invalid parameter for w -->';
        }

        // Include the Reports Library, but don't initialize the database, since we will do that separately later
        include_once(plugin_dir_path(__FILE__) . 'admin/view/wp-slimstat-reports.php');
        wp_slimstat_reports::init();

        /**
         * @SecurityProfile https://cve.mitre.org/cgi-bin/cvename.cgi?name=CVE-2023-0630
         * Disabled because of the report from WP Scan
         */
        // Init the database library with the appropriate filters
        /*if ( strpos ( $_content, 'WHERE:' ) !== false ) {
            $where = html_entity_decode( str_replace( 'WHERE:', '', $_content ), ENT_QUOTES, 'UTF-8' );
        }
        else{*/
        wp_slimstat_db::init(html_entity_decode($_content, ENT_QUOTES, 'UTF-8'));
        //}

        switch ($f) {
            case 'count':
            case 'count-all':
                $output = wp_slimstat_db::count_records($w, $where, false === strpos($f, 'all')) + $o;
                break;

            case 'widget':
                if (empty(wp_slimstat_reports::$reports[$w])) {
                    return __('Invalid Report ID', 'wp-slimstat');
                }

                wp_register_style('wp-slimstat-frontend', plugins_url('/admin/assets/css/slimstat.css', __FILE__), true, SLIMSTAT_ANALYTICS_VERSION);
                wp_enqueue_style('wp-slimstat-frontend');

                wp_slimstat_reports::$reports[$w]['callback_args']['is_widget'] = true;

                ob_start();
                echo wp_slimstat_reports::report_header($w);
                call_user_func(wp_slimstat_reports::$reports[$w]['callback'], wp_slimstat_reports::$reports[$w]['callback_args']);
                wp_slimstat_reports::report_footer();
                $output = ob_get_contents();
                ob_end_clean();
                break;

            case 'recent':
            case 'recent-all':
            case 'top':
            case 'top-all':
                $function = 'get_' . str_replace('-all', '', $f);

                if ('*' == $w) {
                    $w = 'id';
                }

                $w = esc_html($w);
                $w = self::string_to_array($w);

                // Some columns are 'special' and need be removed from the list
                $w_clean = array_diff($w, ['count', 'display_name', 'hostname', 'post_link', 'post_link_no_qs', 'dt']);

                // The special value 'display_name' requires the username to be retrieved
                if (in_array('display_name', $w)) {
                    $w_clean[] = 'username';
                }

                // The special value 'post_list' requires the resource to be retrieved
                if (in_array('post_link', $w)) {
                    $w_clean[] = 'resource';
                }

                // The special value 'post_list_no_qs' requires a substring to be calculated
                if (in_array('post_link_no_qs', $w)) {
                    $w_clean   = ['SUBSTRING_INDEX( resource, "' . (get_option('permalink_structure') ? '?' : '&') . '", 1 )'];
                    $as_column = 'resource';
                }

                // Retrieve the data
                $results = wp_slimstat_db::$function(implode(', ', $w_clean), $where, '', false === strpos($f, 'all'), $as_column);

                // No data? No problem!
                if (empty($results)) {
                    return '<!--  Slimstat Shortcode: No Data -->';
                }

                // Are nice permalinks enabled?
                $permalinks_enabled = get_option('permalink_structure');

                // Format results
                $output = [];

                foreach ($results as $result_idx => $a_result) {
                    foreach ($w as $a_column) {
                        $output[$result_idx][$a_column] = sprintf("<span class='col-%s'>", $a_column);

                        switch ($a_column) {
                            case 'count':
                                $output[$result_idx][$a_column] .= $a_result['counthits'];
                                break;

                            case 'country':
                                $output[$result_idx][$a_column] .= wp_slimstat_i18n::get_string('c-' . $a_result[$a_column]);
                                break;

                            case 'display_name':
                                $user_details = get_user_by('login', $a_result['username']);
                                if (!empty($user_details)) {
                                    $output[$result_idx][$a_column] .= $user_details->display_name;
                                } else {
                                    $output[$result_idx][$a_column] .= $a_result['username'];
                                }

                                break;

                            case 'dt':
                                $output[$result_idx][$a_column] .= date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $a_result['dt']);
                                break;

                            case 'hostname':
                                $output[$result_idx][$a_column] .= self::gethostbyaddr($a_result['ip']);
                                break;

                            case 'language':
                                $output[$result_idx][$a_column] .= wp_slimstat_i18n::get_string('l-' . $a_result[$a_column]);
                                break;

                            case 'platform':
                                $output[$result_idx][$a_column] .= wp_slimstat_i18n::get_string($a_result[$a_column]);
                                break;

                            case 'post_link':
                            case 'post_link_no_qs':
                                $post_id = url_to_postid($a_result['resource']);
                                if ($post_id > 0) {
                                    $output[$result_idx][$a_column] .= sprintf("<a href='%s'>", $a_result[ 'resource' ]) . get_the_title($post_id) . '</a>';
                                } else {
                                    $output[$result_idx][$a_column] .= sprintf("<a href='%s'>%s</a>", $a_result[ 'resource' ], $a_result[ 'resource' ]);
                                }
                                break;

                            default:
                                $output[$result_idx][$a_column] .= $a_result[$a_column] ?? '';
                                break;
                        }
                        $output[$result_idx][$a_column] .= '</span>';
                    }
                    $output[$result_idx] = '<li>' . implode($s, $output[$result_idx]) . '</li>';
                }

                $output = '<ul class="slimstat-shortcode ' . $f . implode('-', $w) . '">' . implode('', $output) . '</ul>';
                break;

            default:
                break;
        }

        return $output;
    }

    // end slimstat_shortcode

    public static function init_plugin()
    {
        // Include our browser detector library
        \SlimStat\Services\Browscap::init();

        // Make sure the upload directory is exist and is protected.
        self::create_upload_directory();

        // Initialize GDPR functionality
        if (class_exists('\SlimStat\Providers\GDPRProvider')) {
            try {
                $gdprProvider = new \SlimStat\Providers\GDPRProvider(self::$settings);
                $gdprProvider->init();

                // Add GDPR parameters to JavaScript
                add_filter('slimstat_js_params', function($params) use ($gdprProvider) {
                    $gdprProvider->addJavaScriptParams($params);
                    return $params;
                });
            } catch (Exception $e) {
                // Log error but don't break the plugin
                error_log('SlimStat GDPR initialization error: ' . $e->getMessage());
            }
        }

        // Initialize IP hash provider daily salt
        \SlimStat\Providers\IPHashProvider::generateDailySalt();

        // Initialize adblock bypass functionality
        \SlimStat\Tracker\Tracker::rewrite_rule_tracker();
        add_action('template_redirect', [\SlimStat\Tracker\Tracker::class, 'adblocker_javascript']);
        add_action('init', [\SlimStat\Tracker\Tracker::class, 'rewrite_rule_tracker']);
    }

    /**
     * Opens given domains during CORS requests to admin-ajax.php
     */
    public static function open_cors_admin_ajax($_allowed_origins = [])
    {
        $exploded_domains = self::string_to_array(self::$settings['external_domains']);

        if (!empty($exploded_domains) && !empty($exploded_domains[0])) {
            $_allowed_origins = array_merge($_allowed_origins, $exploded_domains);
        }

        return $_allowed_origins;
    }
    // end open_cors_admin_ajax

    /**
     * Implements a REST API interface to retrieve Slimstat reports and metrics
     */
    public static function rest_api_response($_request = [])
    {
        $filters = '';
        if (!empty($_request['filters'])) {
            $filters = $_request['filters'];
        }

        if (empty($_request['dimension'])) {
            return new WP_Error('rest_invalid', esc_html__('[REST API] The <code>dimension</code> parameter is required. Please review your request and try again.', 'wp-slimstat'), ['status' => 400]);
        }

        if (empty($_request['function'])) {
            return new WP_Error('rest_invalid', esc_html__('[REST API] The <code>function</code> parameter is required. Please review your request and try again.', 'wp-slimstat'), ['status' => 400]);
        }

        include_once(plugin_dir_path(__FILE__) . 'admin/view/wp-slimstat-db.php');
        wp_slimstat_db::init($filters);

        $response = [
            'function'  => htmlentities($_request['function'], ENT_QUOTES, 'UTF-8'),
            'dimension' => htmlentities($_request['dimension'], ENT_QUOTES, 'UTF-8'),

            'data' => 0,
        ];

        switch ($_request['function']) {
            case 'count':
            case 'count-all':
                $response['data'] = wp_slimstat_db::count_records($_request['dimension'], '', false === strpos($_request['function'], '-all'));
                break;

            case 'recent':
            case 'recent-all':
            case 'top':
            case 'top-all':
                $function = 'get_' . str_replace('-all', '', $_request['function']);

                // Retrieve the data
                $response['data'] = array_values(wp_slimstat_db::$function($_request['dimension'], '', '', false === strpos($_request['function'], '-all')));
                break;

            default:
                // This should never happen, because of the 'enum' condition for this parameter. But never say never...
                $response['data'] = new WP_Error('rest_invalid', esc_html__('[REST API] You sent an invalid request. Accepted function values include: <code>count, count-all, recent, recent-all, top and top-all</code>. Please review your request and try again.', 'wp-slimstat'), ['status' => 400]);
                break;
        }

        return rest_ensure_response($response);
    }
    // end rest_api_response

    /**
     * Implements a REST API authentication mechanism via token
     */
    public static function rest_api_authorization($_request = [])
    {
        if (empty($_request['token'])) {
            return new WP_Error('rest_invalid', esc_html__('[REST API] Please use a valid token in order to access the REST API endpoint at this URL.', 'wp-slimstat'), ['status' => 400]);
        }
        return in_array($_request['token'], self::string_to_array(self::$settings['rest_api_tokens']));
    }
    // end rest_api_authorization

    /**
     * Registers a new REST API route for the Slimstat endpoint
     */
    public static function register_rest_route()
    {
        register_rest_route('slimstat/v1', '/get', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'rest_api_response'],
            'permission_callback' => [self::class, 'rest_api_authorization'],
            'args'                => [
                'token' => [
                    'description' => __('You will need to specify a valid token to be able to query the data. Tokens are defined in Slimstat > Settings > Access Control.', 'wp-slimstat'),
                    'type'        => 'string',
                ],
                'function' => [
                    'description' => __('This parameter specifies the type of QUERY you would like to perform. Accepted funciton values include: count, count-all, recent, recent-all, top and top-all.', 'wp-slimstat'),
                    'type'        => 'string',
                    'enum'        => ['count', 'count-all', 'recent', 'recent-all', 'top', 'top-all'],
                ],
                'dimension' => [
                    'description' => __('This parameter indicates what dimension to return: * (all data), ip, resource, browser, operating system, etc. You can only specify one dimension at a time.', 'wp-slimstat'),
                    'type'        => 'string',
                    'enum'        => ['*', 'id', 'ip', 'username', 'email', 'country', 'referer', 'resource', 'searchterms', 'browser', 'platform', 'language', 'resolution', 'content_type', 'content_id', 'tz_offset', 'outbound_resource'],
                ],
                'filters' => [
                    'description' => __('This parameter is used to filter a given dimension (resources, browsers, operating systems, etc) so that it satisfies certain conditions (i.e.: browser contains Chrome). Please make sure to urlencode this value, and to use the usual filter format: browser contains Chrome&&&referer contains slim (encoded: browser%20contains%20Chrome%26%26%26referer%20contains%20slim)', 'wp-slimstat'),
                    'type'        => 'string',
                ],
            ],
        ]);
    }
    // end register_rest_route

    /**
     * Converts a series of comma separated values into an array
     */
    public static function string_to_array($_option = '')
    {
        if (empty($_option) || !is_string($_option)) {
            return [];
        } else {
            return array_filter(array_map('trim', explode(',', $_option)));
        }
    }
    // end string_to_array

    /**
     * Returns Matomo search engine mapping JSON, cached.
     */
    public static function get_search_engines()
    {
        static $cached_search_engines = null;
        if (null !== $cached_search_engines) {
            return $cached_search_engines;
        }

        $data = get_transient('slimstat_matomo_searchengine');
        if (false === $data) {
            $json_path = plugin_dir_path(__FILE__) . 'admin/assets/data/matomo-searchengine.json';
            $json      = @file_get_contents($json_path);
            $data      = json_decode($json, true);
            if (!is_array($data)) {
                $data = [];
            }
            set_transient('slimstat_matomo_searchengine', $data, WEEK_IN_SECONDS);
        }

        $cached_search_engines = $data;
        return $cached_search_engines;
    }
    // end get_search_engines

    /**
     * Toggles WordPress filters on date_i18n function
     */
    public static function toggle_date_i18n_filters($_turn_on = true)
    {
        if ($_turn_on && !empty(self::$date_i18n_filters) && is_array(self::$date_i18n_filters)) {
            foreach (self::$date_i18n_filters as $i18n_priority => $i18n_func_list) {
                foreach ($i18n_func_list as $func_args) {
                    if (!empty($func_args['function']) && is_string($func_args['function'])) {
                        add_filter('date_i8n', $func_args['function'], $i18n_priority, intval($func_args['accepted_args']));
                    }
                }
            }
        } elseif (!empty($GLOBALS['wp_filter']['date_i18n']['callbacks']) && is_array($GLOBALS['wp_filter']['date_i18n']['callbacks'])) {
            self::$date_i18n_filters = $GLOBALS['wp_filter']['date_i18n']['callbacks'];
            remove_all_filters('date_i18n');
        }
    }
    // end toggle_date_i18n_filters

    /**
     * Calls the date_i18n function without filters
     */
    public static function date_i18n($_format)
    {
        self::toggle_date_i18n_filters(false);
        $date = date_i18n($_format);
        self::toggle_date_i18n_filters(true);

        return $date;
    }
    // end date_i18n

    /**
     * Sets the default values for all the options
     */
    public static function init_options()
    {
        return [
            'version'                => SLIMSTAT_ANALYTICS_VERSION,
            'secret'                 => wp_hash(uniqid(time(), true)),
            'browscap_last_modified' => 0,

            // General
            // -----------------------------------------------------------------------

            // General - Tracker
            'is_tracking'       => 'on',
            'track_admin_pages' => 'no',
            'javascript_mode'   => 'on',

            // General - WordPress Integration
            'add_dashboard_widgets'  => 'on',
            'use_separate_menu'      => 'no',
            'add_posts_column'       => 'no',
            'posts_column_pageviews' => 'on',

            // General - Database
            'auto_purge'        => 0,
            'auto_purge_delete' => 'on',

            // Tracker
            // -----------------------------------------------------------------------

            // Tracker - Data Protection
            // anonymize_ip: mask IP before storing; hash_ip: generate daily visitor_id based on masked IP + UA
            'anonymize_ip'         => 'no',
            'hash_ip'              => 'no',
            'set_tracker_cookie'   => 'on',
            'display_opt_out'      => 'no',
            'opt_out_cookie_names' => '',
            'opt_in_cookie_names'  => '',
            'opt_out_message'      => '<p style="display:block;position:fixed;left:0;bottom:0;margin:0;padding:1em 2em;background-color:#eee;width:100%;z-index:99999;">This website stores cookies on your computer. These cookies are used to provide a more personalized experience and to track your whereabouts around our website in compliance with the European General Data Protection Regulation. If you decide to to opt-out of any future tracking, a cookie will be setup in your browser to remember this choice for one year.<br><br><a href="#" onclick="javascript:SlimStat.optout(event, false);">Accept</a> or <a href="#" onclick="javascript:SlimStat.optout(event, true);">Deny</a></p>',

            // GDPR Consent Banner
            'enable_gdpr_consent'              => 'no',
            'gdpr_consent_message'             => '<p>This website uses cookies and similar technologies to collect information about your browsing activities and to understand how you use our website. This helps us to provide you with a good experience when you browse our website and also allows us to improve our site.</p><p>By clicking Accept, you consent to our use of cookies and similar technologies for analytics purposes. You can change your mind at any time by clicking the link in the footer.</p>',
            'gdpr_consent_accept_text'         => 'Accept',
            'gdpr_consent_deny_text'           => 'Deny',
            'gdpr_consent_cookie_duration'     => 365,

            // Tracker - Link Tracking
            'track_same_domain_referers'             => 'no',
            'do_not_track_outbound_classes_rel_href' => 'noslimstat,ab-item',
            'extensions_to_track'                    => 'pdf,doc,xls,zip',

            // Tracker - Advanced Options
            'geolocation_country' => 'on',
            'session_duration'    => 1800,
            'extend_session'      => 'no',
            'enable_cdn'          => 'no',
            'ajax_relative_path'  => 'no',

            // Tracker - External Pages
            'external_domains' => '',

            // Reports
            // -----------------------------------------------------------------------

            // Reports - Functionality
            'use_current_month_timespan'      => 'no',
            'posts_column_day_interval'       => 28,
            'rows_to_show'                    => '20',
            'show_hits'                       => 'no',
            'ip_lookup_service'               => 'https://ip-api.com/#',
            'comparison_chart'                => 'on',
            'show_display_name'               => 'no',
            'convert_resource_urls_to_titles' => 'on',
            'convert_ip_addresses'            => 'no',

            // Reports - Access Log and World Map
            'refresh_interval'        => '60',
            'number_results_raw_data' => '50',
            'max_dots_on_map'         => '50',

            // Reports - Miscellaneous
            'custom_css'                       => '',
            'chart_colors'                     => '',
            'mozcom_access_id'                 => '',
            'mozcom_secret_key'                => '',
            'show_complete_user_agent_tooltip' => 'no',
            'async_load'                       => 'no',
            'limit_results'                    => '200',
            'enable_sov'                       => 'no',

            // Exclusions
            // -----------------------------------------------------------------------

            // Exclusions - User Properties
            'ignore_wp_users'     => 'no',
            'ignore_spammers'     => 'on',
            'ignore_bots'         => 'no',
            'ignore_prefetch'     => 'on',
            'ignore_users'        => '',
            'ignore_ip'           => '',
            'ignore_countries'    => '',
            'ignore_languages'    => '',
            'ignore_browsers'     => '',
            'ignore_platforms'    => '',
            'ignore_capabilities' => '',

            // Exclusions - Page Properties
            'ignore_resources'     => '',
            'ignore_referers'      => '',
            'ignore_content_types' => '',

            // Access Control
            // -----------------------------------------------------------------------

            // Access Control - Reports
            'restrict_authors_view' => 'on',
            'capability_can_view'   => 'manage_options',
            'can_view'              => '',

            // Access Control - Reports
            'tracking_request_method' => 'ajax',

            // Access Control - Customizer
            'capability_can_customize' => 'manage_options',
            'can_customize'            => '',

            // Access Control - Settings
            'capability_can_admin' => 'manage_options',
            'can_admin'            => '',

            // Access Control - REST API
            'rest_api_tokens' => wp_hash(uniqid(time() - 3600, true)),

            // Maintenance
            // -----------------------------------------------------------------------
            'last_tracker_error'  => [0, '', 0],
            'show_sql_debug'      => 'no',
            'db_indexes'          => 'on',
            'enable_maxmind'      => 'disable',
            'maxmind_license_key' => '',
            'enable_browscap'     => 'no',

            // Notices
            // -----------------------------------------------------------------------
            'notice_latest_news' => 'on',
            'notice_browscap'    => 'on',
            'notice_geolite'     => 'on',
            'notice_caching'     => 'on',

            // Network-wide Settings
            'locked_options' => '',
        ];
    }
    // end init_options

    /**
     * Saves a given option in the database
     */
    public static function update_option($_key = '', $_value = '')
    {
        if (!is_network_admin()) {
            update_option($_key, $_value);
        } else {
            update_site_option($_key, $_value);
        }
    }
    // end update_option

    /**
     * Attach a script to every page to track visitors' screen resolution and other browser-based information
     */
    public static function enqueue_tracker()
    {
        // Use the new unified tracking method setting
        $method = self::$settings['tracking_request_method'] ?? 'rest';

        // Prepare URLs for all methods
        $rest_url          = rest_url('slimstat/v1/hit');
        $ajax_url          = admin_url('admin-ajax.php');
        $ajax_url_relative = admin_url('admin-ajax.php', 'relative');
        $adblock_hash      = md5(site_url() . 'slimstat_request' . SLIMSTAT_ANALYTICS_VERSION);
        $adblock_url       = home_url(sprintf('request/%s/', $adblock_hash));

        // Always provide all possible endpoints for fallback logic
        $params = [
            'transport'       => $method,
            'ajaxurl_rest'    => $rest_url,
            'ajaxurl_ajax'    => ('on' == self::$settings['ajax_relative_path']) ? $ajax_url_relative : $ajax_url,
            'ajaxurl_adblock' => $adblock_url,
        ];

        // Set the primary ajaxurl based on the selected method
        if ('rest' === $method) {
            $params['ajaxurl'] = $rest_url;
        } elseif ('ajax' === $method) {
            $params['ajaxurl'] = ('on' == self::$settings['ajax_relative_path']) ? $ajax_url_relative : $ajax_url;
        } elseif ('adblock_bypass' === $method) {
            $params['ajaxurl'] = $adblock_url;
            // Also set transport to 'adblock' for JS clarity
            $params['transport'] = 'adblock';
        } else {
            $params['ajaxurl'] = $rest_url;
        }

        $baseurl           = parse_url(get_home_url());
        $params['baseurl'] = empty($baseurl['path']) ? '/' : $baseurl['path'];

        if (!empty(self::$settings['do_not_track_outbound_classes_rel_href'])) {
            $params['dnt'] = str_replace(' ', '', self::$settings['do_not_track_outbound_classes_rel_href']);
        }

        if ('on' == self::$settings['display_opt_out']) {
            $params['oc'] = ['slimstat_optout_tracking'];
            if (!empty(self::$settings['opt_out_cookie_names'])) {
                foreach (self::string_to_array(self::$settings['opt_out_cookie_names']) as $a_cookie_pair) {
                    $params['oc'][] = substr($a_cookie_pair, 0, strpos($a_cookie_pair, '='));
                }
            }
            $params['oc'] = implode(',', $params['oc']);
        }

                // GDPR Consent Banner - handled by GDPRProvider

        if ('on' != self::$settings['javascript_mode']) {
            if (empty(self::$stat['id']) || intval(self::$stat['id']) < 0) {
                return false;
            }
            $params['id'] = \SlimStat\Tracker\Utils::getValueWithChecksum(intval(self::$stat['id']));
        } else {
            $params['ci'] = \SlimStat\Tracker\Utils::getValueWithChecksum(\SlimStat\Tracker\Utils::base64UrlEncode(serialize(\SlimStat\Tracker\Utils::getContentInfo())));
        }

        $params['wp_rest_nonce'] = wp_create_nonce('wp_rest');

        $params = apply_filters('slimstat_js_params', $params);

        // Register the correct script for adblock bypass, CDN, or default
        if ('adblock_bypass' === $method) {
            $hash = md5(site_url() . 'slimstat');
            wp_register_script('wp_slimstat', home_url(sprintf('/%s.js/', $hash)), [], SLIMSTAT_ANALYTICS_VERSION, true);
        } elseif ('on' == self::$settings['enable_cdn']) {
            wp_register_script('wp_slimstat', 'https://cdn.jsdelivr.net/wp/wp-slimstat/tags/' . SLIMSTAT_ANALYTICS_VERSION . '/wp-slimstat.min.js', [], null, true);
        } else {
            wp_register_script('wp_slimstat', plugins_url('/wp-slimstat.min.js', __FILE__), [], SLIMSTAT_ANALYTICS_VERSION, true);
        }

        wp_enqueue_script('wp_slimstat');

        /**
         * Registers the 'wp_slimstat' script as an interactivity module if the registration function exists.
         *
         * Ensures compatibility with WordPress Interactivity API by registering the script module and its dependencies.
         */
        if (function_exists('wp_interactivity_register_script_module')) {
            wp_interactivity_register_script_module('wp_slimstat', [
                'name'         => 'wp_slimstat',
                'dependencies' => [],
            ]);
        }

        wp_localize_script('wp_slimstat', 'SlimStatParams', $params);
        return null;
    }

    // end enqueue_tracker

    public static function add_defer_to_script_tag($_tag, $_handle)
    {
        if ('wp_slimstat' === $_handle && false === stripos($_tag, 'defer')) {
            $_tag = str_replace('<script ', '<script defer ', $_tag);
        }

        return $_tag;
    }

    /**
     * Removes old entries from the main table and performs other daily tasks
     */
    public static function wp_slimstat_purge()
    {
        $autopurge_interval = intval(self::$settings['auto_purge']);

        if ($autopurge_interval <= 0) {
            return;
        }

        $days_ago             = strtotime(self::date_i18n('Y-m-d H:i:s') . sprintf(' -%d days', $autopurge_interval));
        $table_stats          = $GLOBALS['wpdb']->prefix . 'slim_stats';
        $table_stats_archive  = $GLOBALS['wpdb']->prefix . 'slim_stats_archive';
        $table_events         = $GLOBALS['wpdb']->prefix . 'slim_events';
        $table_events_archive = $GLOBALS['wpdb']->prefix . 'slim_events_archive';

        // Copy entries to the archive table, if needed
        if ('no' != self::$settings['auto_purge_delete']) {
            // Use Query builder for INSERT INTO ... SELECT ...
            $insert_sql   = sprintf('INSERT INTO %s (id, ip, other_ip, username, email, country, location, city, referer, resource, searchterms, notes, visit_id, server_latency, page_performance, browser, browser_version, browser_type, platform, language, fingerprint, user_agent, resolution, screen_width, screen_height, content_type, category, author, content_id, tz_offset, outbound_resource, dt_out, dt) SELECT id, ip, other_ip, username, email, country, location, city, referer, resource, searchterms, notes, visit_id, server_latency, page_performance, browser, browser_version, browser_type, platform, language, fingerprint, user_agent, resolution, screen_width, screen_height, content_type, category, author, content_id, tz_offset, outbound_resource, dt_out, dt FROM %s WHERE dt < %s', $table_stats_archive, $table_stats, $days_ago);
            $is_copy_done = self::$wpdb->query($insert_sql);
            if (false !== $is_copy_done) {
                \SlimStat\Utils\Query::delete($table_stats)->where('dt', '<', $days_ago)->execute();
            }
            $insert_sql_events = sprintf('INSERT INTO %s (type, event_description, notes, position, id, dt) SELECT type, event_description, notes, position, id, dt FROM %s WHERE dt < %s', $table_events_archive, $table_events, $days_ago);
            $is_copy_done      = self::$wpdb->query($insert_sql_events);
            if (false !== $is_copy_done) {
                \SlimStat\Utils\Query::delete($table_events)->where('dt', '<', $days_ago)->execute();
            }
        } else {
            // Delete old entries
            \SlimStat\Utils\Query::delete($table_stats)->where('dt', '<', $days_ago)->execute();
            \SlimStat\Utils\Query::delete($table_events)->where('dt', '<', $days_ago)->execute();
        }

        // Optimize tables (keep as direct queries)
        self::$wpdb->query('OPTIMIZE TABLE ' . $table_stats);
        self::$wpdb->query('OPTIMIZE TABLE ' . $table_stats_archive);
        self::$wpdb->query('OPTIMIZE TABLE ' . $table_events);
        self::$wpdb->query('OPTIMIZE TABLE ' . $table_events_archive);
    }

    public static function wp_slimstat_update_geoip_database()
    {
        $this_update = strtotime('first Tuesday of this month') + (86400 * 2);
        $last_update = get_option('slimstat_last_geoip_dl', 0);
        if ($last_update < $this_update) {

            $geographicProvider = new \SlimStat\Services\GeoService();

            try {
                $geographicProvider
                    ->setEnableMaxmind(wp_slimstat::$settings['enable_maxmind'])
                    ->setUpdate(true)
                    ->setMaxmindLicense(wp_slimstat::$settings['maxmind_license_key'])
                    ->download();

                // Set the last update time
                $geographicProvider->updateLastUpdateTime(time());

            } catch (\Exception $e) {
                $geographicProvider->logError($e->getMessage());
            }
        }
    }

    /**
     * Displays the opt-out box via Ajax request
     */
    public static function get_optout_html()
    {
        die(stripslashes(self::$settings['opt_out_message']));
    }

    // end get_optout_html



    public static function add_plugin_manual_download_link($_links = [], $_plugin_file = '')
    {
        $a_clean_slug = str_replace(['wp-slimstat-', '/index.php'], ['', ''], $_plugin_file);

        if (false !== ($download_url = get_transient('wp-slimstat-download-link-' . $a_clean_slug))) {
            $_links[] = '<a href="' . $download_url . '">Download ZIP</a>';
        } else {
            $url      = 'https://www.wp-slimstat.com/update-checker/?slug=' . $a_clean_slug . '&key=' . urlencode(self::$settings['addon_licenses']['wp-slimstat-' . $a_clean_slug]);
            $response = wp_safe_remote_get($url, ['timeout' => 300, 'user-agent' => 'Slimstat Analytics/' . SLIMSTAT_ANALYTICS_VERSION . '; ' . home_url()]);

            if (!is_wp_error($response) && 200 == wp_remote_retrieve_response_code($response)) {
                $data = @json_decode($response['body']);

                if (is_object($data)) {
                    $_links[] = '<a href="' . $data->download_url . '">Download ZIP</a>';
                    set_transient('wp-slimstat-download-link-' . $a_clean_slug, $data->download_url, 172800); // 48 hours
                }
            }
        }

        return $_links;
    }

    /**
     * Resolves a given IP address, by keeping a local cache of hostnames to avoid multiple requests to the DNS server
     */
    public static function gethostbyaddr($_ip = '')
    {
        $hostname = get_transient('slimstat_' . $_ip);

        if (empty($hostname)) {
            $hostname = gethostbyaddr($_ip);
            set_transient('slimstat_' . $_ip, $hostname, HOUR_IN_SECONDS);
        }

        return $hostname;
    }
    // end gethostbyaddr

    /**
     * Registers the Slimstat widget
     */
    public static function register_widget()
    {
        return register_widget('slimstat_widget');
    }
    // end register_widget

    /**
     * Generates the key to see if a given host is listed as a search engine in the corresponding Json data file
     */
    public static function get_lossy_url($_url = '')
    {
        return preg_replace(
            [
                '/^(w+\d*|search)\./',
                '/(^|\.)m\./',
                '/(\.(com|org|net|co|it|edu))?\.(ad|ae|af|ag|ai|al|am|ao|aq|ar|as|at|au|aw|ax|az|ba|bb|bd|be|bf|bg|bh|bi|bj|bl|bm|bn|bo|bq|br|bs|bt|bv|bw|by|bz|ca|cc|cd|cf|cg|ch|ci|ck|cl|cm|cn|co|cr|cu|cv|cw|cx|cy|cz|de|dj|dk|dm|do|dz|ec|ee|eg|eh|er|es|et|fi|fj|fk|fm|fo|fr|ga|gb|gd|ge|gf|gg|gh|gi|gl|gm|gn|gp|gq|gr|gs|gt|gu|gw|gy|hk|hm|hn|hr|ht|hu|id|ie|il|im|in|io|iq|ir|is|it|je|jm|jo|jp|ke|kg|kh|ki|km|kn|kp|kr|kw|ky|kz|la|lb|lc|li|lk|lr|ls|lt|lu|lv|ly|ma|mc|md|me|mf|mg|mh|mk|ml|mm|mn|mo|mp|mq|mr|ms|mt|mu|mv|mw|mx|my|mz|na|nc|ne|nf|ng|ni|nl|no|np|nr|nu|nz|om|pa|pe|pf|pg|ph|pk|pl|pm|pn|pr|ps|pt|pw|py|qa|re|ro|rs|ru|rw|sa|sb|sc|sd|se|sg|sh|si|sj|sk|sl|sm|sn|so|sr|ss|st|sv|sx|sy|sz|tc|td|tf|tg|th|tj|tk|tl|tm|tn|to|tr|tt|tv|tw|tz|ua|ug|um|us|uy|uz|va|vc|ve|vg|vi|vn|vu|wf|ws|ye|yt|za|zm|zw)(\/|$)/',
                '/(^|\.)(ad|ae|af|ag|ai|al|am|ao|aq|ar|as|at|au|aw|ax|az|ba|bb|bd|be|bf|bg|bh|bi|bj|bl|bm|bn|bo|bq|br|bs|bt|bv|bw|by|bz|ca|cc|cd|cf|cg|ch|ci|ck|cl|cm|cn|co|cr|cu|cv|cw|cx|cy|cz|de|dj|dk|dm|do|dz|ec|ee|eg|eh|er|es|et|fi|fj|fk|fm|fo|fr|ga|gb|gd|ge|gf|gg|gh|gi|gl|gm|gn|gp|gq|gr|gs|gt|gu|gw|gy|hk|hm|hn|hr|ht|hu|id|ie|il|im|in|io|iq|ir|is|it|je|jm|jo|jp|ke|kg|kh|ki|km|kn|kp|kr|kw|ky|kz|la|lb|lc|li|lk|lr|ls|lt|lu|lv|ly|ma|mc|md|me|mf|mg|mh|mk|ml|mm|mn|mo|mp|mq|mr|ms|mt|mu|mv|mw|mx|my|mz|na|nc|ne|nf|ng|ni|nl|no|np|nr|nu|nz|om|pa|pe|pf|pg|ph|pk|pl|pm|pn|pr|ps|pt|pw|py|qa|re|ro|rs|ru|rw|sa|sb|sc|sd|se|sg|sh|si|sj|sk|sl|sm|sn|so|sr|ss|st|sv|sx|sy|sz|tc|td|tf|tg|th|tj|tk|tl|tm|tn|to|tr|tt|tv|tw|tz|ua|ug|um|us|uy|uz|va|vc|ve|vg|vi|vn|vu|wf|ws|ye|yt|za|zm|zw)\./',
            ],
            [
                '',
                '$1',
                '.{}$4',
                '$1{}.',
            ],
            $_url
        );
    }
    // end get_lossy_url

    /**
     * Check if slimstat pro plugin is installed
     */
    public static function pro_is_installed($pluginSlug = 'wp-slimstat-pro/wp-slimstat-pro.php')
    {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        return (bool) is_plugin_active($pluginSlug);
    }

    /**
     * create upload directory
     */
    public static function create_upload_directory()
    {
        $upload_dir = self::$upload_dir;
        wp_mkdir_p($upload_dir);

        /**
         * Create .htaccess to avoid public access.
         */
        if (is_dir($upload_dir) && is_writable($upload_dir)) {
            $htaccess_file = path_join($upload_dir, '.htaccess');

            if (!file_exists($htaccess_file) && $handle = @fopen($htaccess_file, 'w')) {
                fwrite($handle, "Deny from all\n");
                fclose($handle);
            }
        }
    }

    public static function get_schedule_interval($schedule)
    {
        $schedulesInterval = wp_get_schedules();
        $timeInterval      = 86400;
        if (isset($schedulesInterval[$schedule]['interval'])) {
            $timeInterval = $schedulesInterval[$schedule]['interval'];
        }
        return $timeInterval;
    }
}

// end of class declaration

class slimstat_widget extends WP_Widget
{
    /**
     * Sets up the widgets name etc
     */
    public function __construct()
    {
        parent::__construct('slimstat_widget', 'Slimstat', [
            'classname'   => 'slimstat_widget',
            'description' => 'Add a Slimstat report to your sidebar',
        ]);
    }

    /**
     * Outputs the content of the widget
     *
     * @param array $args
     * @param array $instance
     */
    public function widget($_args = [], $_instance = [])
    {
        extract(shortcode_atts([
            'slimstat_widget_id'      => '',
            'slimstat_widget_title'   => '',
            'slimstat_widget_filters' => '',
        ], $_instance));

        if (!empty($slimstat_widget_title)) {
            echo (empty($_args['before_title']) ? '<h2 class="widget-title">' : $_args['before_title']) . $slimstat_widget_title . (empty($_args['after_title']) ? '</h2>' : $_args['after_title']);
        }
        if (!empty($slimstat_widget_id)) {
            echo do_shortcode(sprintf("[slimstat f='widget' w='%s']%s[/slimstat]", $slimstat_widget_id, $slimstat_widget_filters));
        } else {
            echo '';
        }
    }

    /**
     * Outputs the options form on admin
     *
     * @param array $instance The widget options
     */
    public function form($_instance)
    {
        extract(shortcode_atts([
            'slimstat_widget_id'      => '',
            'slimstat_widget_title'   => '',
            'slimstat_widget_filters' => '',
        ], $_instance));

        // Let's build the dropdown
        include_once(plugin_dir_path(__FILE__) . 'admin/view/wp-slimstat-reports.php');
        wp_slimstat_reports::init();
        $select_options = '';

        foreach (wp_slimstat_reports::$reports as $a_report_id => $a_report_info) {
            $select_options .= sprintf("<option value='%s' ", $a_report_id) . (($slimstat_widget_id == $a_report_id) ? 'selected="selected"' : '') . sprintf('>%s</option>', $a_report_info[ 'title' ]);
        }
        ?>

        <p>
            <label for="<?php echo esc_attr($this->get_field_id('slimstat_widget_id')); ?>"><?php _e('Report', 'wp-slimstat') ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('slimstat_widget_id')); ?>" name="<?php echo esc_attr($this->get_field_name('slimstat_widget_id')); ?>">
                <option value="">Select a widget</option>
                <?php echo $select_options ?>
            </select>
        </p>

        <p>
            <label for="<?php echo esc_attr($this->get_field_id('slimstat_widget_title')); ?>"><?php _e('Title', 'wp-slimstat') ?></label>
            <input type="text" class="widefat" id="<?php echo esc_attr($this->get_field_id('slimstat_widget_title')); ?>" name="<?php echo esc_attr($this->get_field_name('slimstat_widget_title')); ?>" value="<?php echo trim(strip_tags($slimstat_widget_title)) ?>">
        </p>

        <p>
            <label for="<?php echo esc_attr($this->get_field_id('slimstat_widget_filters')); ?>"><?php _e('Optional filters', 'wp-slimstat'); ?></label>
            <a href="https://wp-slimstat.com/resources/what-is-the-syntax-of-a-slimstat-shortcode-#slimstat-operators" target="_blank">[?]</a>
            <textarea class="widefat" id="<?php echo esc_attr($this->get_field_id('slimstat_widget_filters')); ?>" name="<?php echo esc_attr($this->get_field_name('slimstat_widget_filters')); ?>"><?php echo trim(strip_tags($slimstat_widget_filters)) ?></textarea>
        </p>
        <?php
    }

    /**
     * Processing widget options on save
     *
     * @param array $new_instance The new options
     * @param array $old_instance The previous options
     */
    public function update($_new_instance, $_old_instance)
    {
        $instance = $_old_instance;

        $instance['slimstat_widget_id']      = $_new_instance['slimstat_widget_id'];
        $instance['slimstat_widget_title']   = $_new_instance['slimstat_widget_title'];
        $instance['slimstat_widget_filters'] = $_new_instance['slimstat_widget_filters'];
        return $instance;
    }
}

// Early initialize DB handle for add-ons that may access wp_slimstat::$wpdb before init() runs
if (empty(wp_slimstat::$wpdb) && isset($GLOBALS['wpdb'])) {
    wp_slimstat::$wpdb = $GLOBALS['wpdb'];
}

// Ok, let's go, Sparky!
if (function_exists('add_action')) {
    // Since we use sendBeacon, this function sends raw POST data, which does not populate the $_POST variable automatically
    if ((!empty($_SERVER['HTTP_CONTENT_TYPE']) || !empty($_SERVER['CONTENT_TYPE'])) && [] === $_POST) {
        $raw_post_string = file_get_contents('php://input');
        parse_str($raw_post_string, wp_slimstat::$raw_post_array);
    } elseif ([] !== $_POST) {
        wp_slimstat::$raw_post_array = $_POST;
    }

    // Init the Ajax listener
    if (!empty(wp_slimstat::$raw_post_array['action']) && 'slimtrack' == wp_slimstat::$raw_post_array['action']) {

        // This is needed because admin-ajax.php is reading $_REQUEST to fire the corresponding action
        if (empty($_POST['action'])) {
            $_POST['action'] = wp_slimstat::$raw_post_array['action'];
        }

        add_action('wp_ajax_nopriv_slimtrack', ['SlimStat\Tracker\Tracker', 'slimtrack_ajax']);
        add_action('wp_ajax_slimtrack', ['SlimStat\Tracker\Tracker', 'slimtrack_ajax']);
    }


    // From the codex: You can't call register_activation_hook() inside a function hooked to the 'plugins_loaded' or 'init' hooks (or any other hook). These hooks are called before the plugin is loaded or activated.
    if (is_admin()) {
        include_once(plugin_dir_path(__FILE__) . 'admin/index.php');
        register_activation_hook(__FILE__, ['wp_slimstat_admin', 'init_environment']);
        register_deactivation_hook(__FILE__, ['wp_slimstat_admin', 'deactivate']);
    }

    add_action('widgets_init', ['wp_slimstat', 'register_widget']);

    // Add the appropriate actions
    add_action('plugins_loaded', ['wp_slimstat', 'init'], 20);
    // Add the action to fetch chart data
    add_action('wp_ajax_slimstat_fetch_chart_data', [\SlimStat\Modules\Chart::class, 'ajaxFetchChartData']);
}

add_action('wp_ajax_slimstat_clear_cache', 'wp_slimstat_clear_cache_handler');

function wp_slimstat_clear_cache_handler()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'wp-slimstat'));
    }
    // Optional: check nonce if you add it to JS
    if (empty($_POST['security']) || !wp_verify_nonce($_POST['security'], 'slimstat_clear_cache')) {
        wp_send_json_error(__('Invalid nonce', 'wp-slimstat'));
    }

    global $wpdb;
    $transients = $wpdb->get_col(
        sprintf("SELECT option_name FROM %s WHERE option_name LIKE '_transient_wp_slimstat_query_%%' OR option_name LIKE '_transient_timeout_wp_slimstat_query_%%'", $wpdb->options)
    );
    $count = 0;
    foreach ($transients as $transient) {
        delete_option($transient);
        $count++;
    }
    wp_send_json_success(sprintf(__('Slimstat cache cleared (%d items)', 'wp-slimstat'), $count));
}
