<?php
/*
 * Plugin Name: SlimStat Analytics
 * Plugin URI: https://wp-slimstat.com/
 * Description: The leading web analytics plugin for WordPress
 * Version: 5.3.1
 * Author: Jason Crouse, VeronaLabs
 * Text Domain: wp-slimstat
 * Domain Path: /languages
 * Author URI: https://wp-slimstat.com/
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.6
 * Requires PHP: 7.4
*/

if (!empty(wp_slimstat::$settings)) {
    return true;
}

// check if composer autoloader exists
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    return;
}

// Set the plugin version and directory
define('SLIMSTAT_ANALYTICS_VERSION', '5.3.1');
define('SLIMSTAT_FILE', __FILE__);
define('SLIMSTAT_DIR', __DIR__);
define('SLIMSTAT_URL', plugins_url('', __FILE__));

// include the autoloader if it exists
require_once __DIR__ . '/vendor/autoload.php';

class wp_slimstat
{
    public static $settings = [];

    public static $wpdb;
    public static $upload_dir = '';

    public static $update_checker = [];
    public static $raw_post_array = [];

    protected static $data_js           = ['id' => 0];
    protected static $stat              = [];
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
                add_action(is_admin() ? 'admin_init' : 'wp', [self::class, 'slimtrack'], 5);

                if ('on' != self::$settings['ignore_wp_users']) {
                    add_action('login_init', [self::class, 'slimtrack'], 10);
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
        add_action('wp_ajax_slimstat_optout_html', [self::class, 'get_optout_html']);
        add_action('wp_ajax_nopriv_slimstat_optout_html', [self::class, 'get_optout_html']);

        // If this request was a redirect, we should update the content type accordingly
        add_filter('wp_redirect_status', [self::class, 'update_content_type'], 10, 2);

        // Shortcodes
        add_shortcode('slimstat', [self::class, 'slimstat_shortcode'], 15);

        // Init the plugin functionality
        add_action('init', [self::class, 'init_plugin']);

        // REST API Support
        add_action('rest_api_init', [self::class, 'register_rest_route']);

        // Rewrite rule for static tracker
        add_action('init', [self::class, 'rewrite_rule_tracker']);
        add_action('template_redirect', [self::class, 'adblocker_javascript']);

        // Load the admin library
        if (is_user_logged_in()) {
            include_once(plugin_dir_path(__FILE__) . 'src/Constants.php');
            include_once(plugin_dir_path(__FILE__) . 'admin/index.php');
            add_action('init', ['wp_slimstat_admin', 'init'], 60);
        }
    }
    // end init

    /**
     * Reads and processes the data received by the XHR tracker
     */
    public static function slimtrack_ajax()
    {
        // If the website is using a caching plugin, the tracking code might still be there, even if the user turned off tracking
        if ('on' != self::$settings['is_tracking']) {
            exit(self::_log_error(204));
        }

        $id = 0;

        self::$data_js = apply_filters('slimstat_filter_pageview_data_js', self::$raw_post_array);
        $site_host     = parse_url(get_site_url(), PHP_URL_HOST);

        self::$stat['referer'] = '';
        if (!empty(self::$data_js['ref'])) {
            self::$stat['referer'] = self::_base64_url_decode(self::$data_js['ref']);

            $parsed_ref = parse_url(self::$stat['referer'], PHP_URL_HOST);
            if (false === $parsed_ref) {
                exit(self::_log_error(201));
            }
        }

        // Do we have an id for this request? If we do, we are either updating an existing pageview, or recording an event on the page
        if (!empty(self::$data_js['id'])) {

            // Make sure that the control code is valid
            self::$data_js['id'] = self::_get_value_without_checksum(self::$data_js['id']);

            if (false === self::$data_js['id']) {
                exit(self::_log_error(101));
            }

            self::$stat['id'] = intval(self::$data_js['id']);
            if (self::$stat['id'] < 0) {
                do_action('slimstat_track_exit_' . abs(self::$stat['id']));
                exit(self::_get_value_with_checksum(self::$stat['id']));
            }

            // If self::$data_js[ 'pos' ] is empty, update an existing pageview with client-based information (resolution, server latency, etc)
            if (empty(self::$data_js['pos'])) {
                self::_set_visit_id(true);

                // Retrieves all the client-side info (screen resolution, server latency, etc) and sets the corresponding entries in self::$stat
                self::$stat = self::_get_client_info(self::$data_js, self::$stat);

                // Visitor is still on this page, record the timestamp in the corresponding field if this WAS NOT a request to update a "server-side" pageview with client-side info
                if (empty(self::$stat['resolution'])) {
                    // Heartbeat / finalize update of dt_out
                    if (!empty(self::$data_js['hb'])) {
                        // Use provided ts if valid, else current time
                        $heartbeat_ts = 0;
                        if (!empty(self::$data_js['ts'])) {
                            $heartbeat_ts = intval(self::$data_js['ts']);
                        }
                        if ($heartbeat_ts > 0 && $heartbeat_ts <= (time() + 300)) { // sanity: not too far future
                            self::$stat['dt_out'] = $heartbeat_ts;
                        } else {
                            self::$stat['dt_out'] = self::date_i18n('U');
                        }
                    } else {
                        self::$stat['dt_out'] = self::date_i18n('U');
                    }
                }

                // Is this a new visitor, based on his fingerprint?
                if (!empty(self::$stat['fingerprint']) && self::_is_new_visitor(self::$stat['fingerprint'])) {
                    self::$stat['notes'] = ['new:yes'];
                }

                $id = self::_update_row(self::$stat);
            } // ... otherwise, is this an event: a click on a link (maybe a 'download'?) or other user action
            else {
                // Record the event
                $event_info = [
                    'position' => strip_tags(trim(self::$data_js['pos'])),
                    'id'       => self::$stat['id'],
                    'dt'       => self::date_i18n('U'),
                ];

                if (!empty(self::$data_js['no'])) {
                    $event_info['notes'] = self::_base64_url_decode(self::$data_js['no']);
                }

                /**
                 * Allow third-party tools to decide whether to track this event or not
                 *
                 * @param bool  $shouldEventBeTracked
                 * @param array $event_info
                 *
                 * @return bool
                 *
                 * @since 5.2.6
                 */
                $shouldEventBeTracked = apply_filters('slimstat_track_event_enabled', true, $event_info);

                if ($shouldEventBeTracked) {
                    self::_insert_row($event_info, $GLOBALS['wpdb']->prefix . 'slim_events');
                }

                if (!empty(self::$data_js['res'])) {
                    $resource        = self::_base64_url_decode(self::$data_js['res']);
                    $parsed_resource = parse_url($resource);

                    if (false === $parsed_resource || empty($parsed_resource['host'])) {
                        exit(self::_log_error(203));
                    }

                    // Is this a download? If it is, add a new record to the database
                    if (!empty($parsed_resource['path']) && in_array(pathinfo($parsed_resource['path'], PATHINFO_EXTENSION), self::string_to_array(self::$settings['extensions_to_track']))) {
                        self::$stat['resource']     = $parsed_resource['path'] . (empty($parsed_resource['query']) ? '' : '?' . $parsed_resource['query']);
                        self::$stat['content_type'] = 'download';

                        if (!empty(self::$data_js['fh'])) {
                            self::$stat['fingerprint'] = sanitize_text_field(self::$data_js['fh']);
                        }

                        $id = self::slimtrack();
                    } // .. or outbound link? If so, update the pageview with the new info
                    elseif ($parsed_resource['host'] != $site_host) {
                        self::$stat['outbound_resource'] = $resource;

                        // Visitor is still on this page, record the timestamp in the corresponding field
                        self::$stat['dt_out'] = self::date_i18n('U');

                        $id = self::_update_row(self::$stat);
                    }
                } else {
                    // Visitor is still on this page, record the timestamp in the corresponding field
                    self::$stat['dt_out'] = self::date_i18n('U');

                    $id = self::_update_row(self::$stat);
                }
            }
        } // If self::$data_js[ 'id' ] is empty, we are tracking a new pageview
        else {
            self::$stat['resource'] = '';
            if (!empty(self::$data_js['res'])) {
                self::$stat['resource'] = self::_base64_url_decode(self::$data_js['res']);

                if (false === parse_url(self::$stat['resource'])) {
                    exit(self::_log_error(203));
                }
            }

            // Retrieves all the client-side info (screen resolution, server latency, etc) and sets the corresponding entries in self::$stat
            self::$stat = self::_get_client_info(self::$data_js, self::$stat);

            if (!empty(self::$data_js['ci'])) {
                self::$data_js['ci'] = self::_get_value_without_checksum(self::$data_js['ci']);

                if (false === self::$data_js['ci']) {
                    exit(self::_log_error(102));
                }

                $content_info = @unserialize(self::_base64_url_decode(self::$data_js['ci']));

                if (empty($content_info) || !is_array($content_info)) {
                    exit(self::_log_error(103));
                }

                foreach (['content_type', 'category', 'content_id', 'author'] as $a_key) {
                    if (!empty($content_info[$a_key]) && 'content_id' !== $a_key) {
                        self::$stat[$a_key] = sanitize_text_field($content_info[$a_key]);
                    } elseif (!empty($content_info[$a_key])) {
                        self::$stat[$a_key] = absint($content_info[$a_key]);
                    }
                }
            } // ... otherwise we'll track this as an external page
            else {
                self::$stat['content_type'] = 'external';
            }

            // Is this a new visitor, based on his fingerprint?
            if (!empty(self::$stat['fingerprint']) && self::_is_new_visitor(self::$stat['fingerprint'])) {
                self::$stat['notes'] = ['new:yes'];
            }

            // Track the rest of the information related to this pageview
            $id = self::slimtrack();
        }

        // Was this pageview tracked?
        if (empty($id)) {
            exit(0);
        }

        // Send the ID back to Javascript to track future interactions
        do_action('slimstat_track_success');
        exit(self::_get_value_with_checksum($id));
    }
    // end slimtrack_ajax

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
     * Rewrite rule for static tracker
     */
    public static function rewrite_rule_tracker()
    {
        if ('adblock_bypass' === (self::$settings['tracking_request_method'] ?? 'rest')) {
            add_rewrite_tag('%slimstat_tracker%', '([a-f0-9]{32})');
            add_rewrite_rule(
                '^([a-f0-9]{32})\\.js$',
                'index.php?slimstat_tracker=$matches[1]',
                'top'
            );
        }
    }

    /**
     * Function to detect if Adblock is enabled and serve the JS tracker
     */
    public static function adblocker_javascript()
    {
        // Only handle the tracker JS endpoint if adblock bypass is enabled
        if ('adblock_bypass' !== (self::$settings['tracking_request_method'] ?? 'rest')) {
            return;
        }

        $tracker_hash = get_query_var('slimstat_tracker');
        if ($tracker_hash && $tracker_hash === md5(site_url() . 'slimstat')) {
            // Set the content type to JavaScript
            header('Content-Type: application/javascript');

            // Set caching headers for one year
            header('Cache-Control: public, max-age=31536000');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');

            $js_path          = plugin_dir_path(__FILE__) . '/wp-slimstat.min.js';

            if (file_exists($js_path)) {
                readfile($js_path);
                exit;
            } else {
                status_header(404);
                echo '// Tracker not found';
                exit;
            }
        }
    }

    /**
     * THE Slimstat tracker
     */
    public static function slimtrack()
    {
        self::$stat['dt'] = self::date_i18n('U');

        if (empty(self::$stat['notes'])) {
            self::$stat['notes'] = [];
        }

        // Allow third-party tools to initialize the stat array
        self::$stat = apply_filters('slimstat_filter_pageview_stat_init', self::$stat);

        // Third-party tools can decide that this pageview should not be tracked, by setting its datestamp to zero
        if (empty(self::$stat) || empty(self::$stat['dt'])) {
            return false;
        }

        // Reset the pageview ID, if it's set for some obscure reason
        unset(self::$stat['id']);

        // Opt-out of tracking via cookie
        if ('on' == self::$settings['display_opt_out']) {
            $cookie_names = ['slimstat_optout_tracking' => 'true'];

            if (!empty(self::$settings['opt_out_cookie_names'])) {
                $cookie_names = [];

                foreach (self::string_to_array(self::$settings['opt_out_cookie_names']) as $a_cookie_pair) {
                    [$name, $value] = explode('=', $a_cookie_pair);

                    if ('' !== $name && '0' !== $name && ('' !== $value && '0' !== $value)) {
                        $cookie_names[$name] = $value;
                    }
                }
            }

            foreach ($cookie_names as $a_name => $a_value) {
                if (isset($_COOKIE[$a_name]) && false !== strpos($_COOKIE[$a_name], $a_value)) {
                    // remove slimstat cookie
                    unset($_COOKIE['slimstat_tracking_code']);
                    @setcookie(
                        'slimstat_tracking_code',
                        '',
                        ['expires' => time() - (15 * 60), 'path' => COOKIEPATH]
                    );
                    return false;
                }
            }
        }

        // Opt-in tracking via cookie (only those who have a cookie will be tracked)
        if (!empty(self::$settings['opt_in_cookie_names'])) {
            $cookie_names        = [];
            $opt_in_cookie_names = self::string_to_array(self::$settings['opt_in_cookie_names']);

            foreach ($opt_in_cookie_names as $a_cookie_pair) {
                [$name, $value] = explode('=', $a_cookie_pair);

                if ('' !== $name && '0' !== $name && ('' !== $value && '0' !== $value)) {
                    $cookie_names[$name] = $value;
                }
            }

            $cookie_found = false;
            foreach ($cookie_names as $a_name => $a_value) {
                if (isset($_COOKIE[$a_name]) && false !== strpos($_COOKIE[$a_name], $a_value)) {
                    $cookie_found = true;
                }
            }

            if (!$cookie_found) {
                // remove slimstat cookie
                unset($_COOKIE['slimstat_tracking_code']);
                @setcookie(
                    'slimstat_tracking_code',
                    '',
                    ['expires' => time() - (15 * 60), 'path' => COOKIEPATH]
                );
                return false;
            }
        }

        // IP address
        [self::$stat['ip'], self::$stat['other_ip']] = self::_get_remote_ip();

        if (empty(self::$stat['ip']) || '0.0.0.0' == self::$stat['ip']) {
            $error = self::_log_error(202);
            return false;
        }

        // Should we ignore this IP address?
        foreach (self::string_to_array(self::$settings['ignore_ip']) as $a_ip_range) {
            $ip_to_ignore = $a_ip_range;

            if (false !== strpos($ip_to_ignore, '/')) {
                [$ip_to_ignore, $cidr_mask] = explode('/', trim($ip_to_ignore));
            } else {
                $cidr_mask = self::_get_mask_length($ip_to_ignore);
            }

            $long_masked_ip_to_ignore  = substr(self::_dtr_pton($ip_to_ignore), 0, $cidr_mask);
            $long_masked_user_ip       = substr(self::_dtr_pton(self::$stat['ip']), 0, $cidr_mask);
            $long_masked_user_other_ip = substr(self::_dtr_pton(self::$stat['other_ip']), 0, $cidr_mask);

            if ($long_masked_user_ip === $long_masked_ip_to_ignore || $long_masked_user_other_ip === $long_masked_ip_to_ignore) {
                return false;
            }
        }

        // Do we need to anonymize this IP address?
        if ('on' == self::$settings['anonymize_ip']) {
            self::$stat['ip'] = wp_privacy_anonymize_ip(self::$stat['ip']);

            if (!empty(self::$stat['other_ip'])) {
                self::$stat['other_ip'] = wp_privacy_anonymize_ip(self::$stat['other_ip']);
            }
        }

        // Resource URL
        if (!isset(self::$stat['resource'])) {
            self::$stat['resource'] = self::get_request_uri();
        }

        // Decode the URL and sanitize it to ensure it's safe and properly formatted
        self::$stat['resource'] = sanitize_text_field(urldecode(self::$stat['resource']));

        // Re-encode non-ASCII chars, preserving ASCII and slashes for backwards compatibility
        self::$stat['resource'] = preg_replace_callback('/[^\x20-\x7E]/', fn ($match) => '%' . bin2hex($match[0]), self::$stat['resource']);

        // Is this a 'seriously malformed' URL?
        $parsed_url = parse_url(self::$stat['resource']);
        if (!$parsed_url) {
            $error = self::_log_error(203);
            return false;
        }

        // Don't store the domain name in the database
        self::$stat['resource'] = $parsed_url['path'] . (empty($parsed_url['query']) ? '' : '?' . $parsed_url['query']) . (empty($parsed_url['fragment']) ? '' : '#' . $parsed_url['fragment']);

        // Is this resource blacklisted?
        if (!empty(self::$settings['ignore_resources']) && self::_is_blacklisted(self::$stat['resource'], self::$settings['ignore_resources'])) {
            return false;
        }

        // Referrer URL
        if (empty(self::$stat['referer']) && !empty($_SERVER['HTTP_REFERER'])) {
            self::$stat['referer'] = sanitize_url(wp_unslash($_SERVER['HTTP_REFERER']));
        }

        if (!empty(self::$stat['referer'])) {
            // Is this a 'seriously malformed' URL?
            $parsed_url = parse_url(self::$stat['referer']);
            if (!$parsed_url) {
                $error = self::_log_error(201);
                return false;
            }

            if (isset($parsed_url['scheme']) && ('' !== $parsed_url['scheme'] && '0' !== $parsed_url['scheme']) && !in_array(strtolower($parsed_url['scheme']), ['http', 'https', 'android-app'])) {
                self::$stat['notes'][] = sprintf(__('Attempted XSS Injection: %s', 'wp-slimstat'), self::$stat['referer']);
                unset(self::$stat['referer']);
            }

            // Is this referer blacklisted?
            if (!empty(self::$settings['ignore_referers']) && self::_is_blacklisted(self::$stat['referer'], self::$settings['ignore_referers'])) {
                return false;
            }

            // Search terms
            self::$stat['searchterms'] = self::_get_search_terms(self::$stat['referer']);

            // Are we storing internal referrers in the database?
            $parsed_site_url = parse_url(get_site_url(), PHP_URL_HOST);
            if (isset($parsed_url['host']) && ('' !== $parsed_url['host'] && '0' !== $parsed_url['host']) && $parsed_url['host'] == $parsed_site_url && 'on' != self::$settings['track_same_domain_referers']) {
                unset(self::$stat['referer']);
            }
        }

        // Internal WP search?
        if (empty(self::$stat['searchterms']) && !empty($_POST['s'])) {
            self::$stat['searchterms'] = sanitize_text_field(str_replace('\\', '', $_REQUEST['s']));
        }

        // If this function was called by the js tracker (client mode), we've already determined this pageview's content information
        if (!isset(self::$stat['content_type'])) {
            $content_info = self::_get_content_info();

            // Is this content type blacklisted?
            if (!empty(self::$settings['ignore_content_types']) && self::_is_blacklisted($content_info['content_type'], self::$settings['ignore_content_types'])) {
                return false;
            }

            if (is_array($content_info)) {
                self::$stat += $content_info;
            }
        }

        // Number of results from query_posts
        if ((is_archive() || is_search()) && !empty($GLOBALS['wp_query']->found_posts)) {
            self::$stat['notes'][] = 'results:' . intval($GLOBALS['wp_query']->found_posts);
        }

        // Do not track report pages in the admin
        if ((!empty(self::$stat['resource']) && false !== strpos(self::$stat['resource'], 'wp-admin/admin-ajax.php')) || (!empty($_GET['page']) && false !== strpos($_GET['page'], 'slimview'))) {
            return false;
        }

        // Should we ignore this user?
        if (!empty($GLOBALS['current_user']->ID)) {
            // Don't track logged-in users, if the corresponding option is enabled
            if ('on' == self::$settings['ignore_wp_users']) {
                return false;
            }

            // Don't track users with given capabilities
            foreach ($GLOBALS['current_user']->roles as $a_capability) {
                if (self::_is_blacklisted($a_capability, self::$settings['ignore_capabilities'])) {
                    return false;
                }
            }

            // Is this user blacklisted?
            if (!empty(self::$settings['ignore_users']) && self::_is_blacklisted($GLOBALS['current_user']->data->user_login, self::$settings['ignore_users'])) {
                return false;
            }

            self::$stat['username'] = $GLOBALS['current_user']->data->user_login;
            self::$stat['email']    = $GLOBALS['current_user']->data->user_email;
            self::$stat['notes'][]  = 'user:' . $GLOBALS['current_user']->data->ID;
            $not_spam               = true;
        } elseif (isset($_COOKIE['comment_author_' . COOKIEHASH])) {
            // Is this a spammer?
            $spam_comment = self::$wpdb->get_row(self::$wpdb->prepare('
                SELECT comment_author, comment_author_email, COUNT(*) comment_count
                FROM `' . DB_NAME . "`.{$GLOBALS['wpdb']->comments}
                WHERE comment_author_IP = %s AND comment_approved = 'spam'
                GROUP BY comment_author
                LIMIT 0,1", self::$stat['ip']), ARRAY_A);

            if (!empty($spam_comment['comment_count'])) {
                if ('on' == self::$settings['ignore_spammers']) {
                    return false;
                } else {
                    self::$stat['notes'][]  = 'spam:yes';
                    self::$stat['username'] = $spam_comment['comment_author'];
                    self::$stat['email']    = $spam_comment['comment_author_email'];
                }
            } else {
                if (!empty($_COOKIE['comment_author_' . COOKIEHASH])) {
                    self::$stat['username'] = sanitize_user($_COOKIE['comment_author_' . COOKIEHASH]);
                }
                if (!empty($_COOKIE['comment_author_email_' . COOKIEHASH])) {
                    self::$stat['email'] = sanitize_email($_COOKIE['comment_author_email_' . COOKIEHASH]);
                }
            }
        }

        // Language
        self::$stat['language'] = self::_get_language();

        // Is this language blacklisted?
        if (!empty(self::$stat['language']) && !empty(self::$settings['ignore_languages']) && false !== stripos(self::$settings['ignore_languages'], (string) self::$stat['language'])) {
            return false;
        }

        // Geolocation
        $geographicProvider = new \SlimStat\Services\GeoService();
        if ($geographicProvider->isGeoIPEnabled()) {
            try {
                $geolocation_data = \SlimStat\Services\GeoIP::loader(self::$stat['ip']);
            } catch (Exception $e) {
                self::_log_error(205);
                return false;
            }

            if (!empty($geolocation_data['country']['iso_code']) && 'xx' != $geolocation_data['country']['iso_code']) {
                self::$stat['country'] = strtolower($geolocation_data['country']['iso_code']);

                if (!empty($geolocation_data['city']['names']['en'])) {
                    self::$stat['city'] = $geolocation_data['city']['names']['en'];
                }

                if (!empty($geolocation_data['subdivisions'][0]['iso_code']) && !empty(self::$stat['city'])) {
                    self::$stat['city'] .= ' (' . $geolocation_data['subdivisions'][0]['iso_code'] . ')';
                }

                if (!empty($geolocation_data['location']['latitude']) && !empty($geolocation_data['location']['longitude'])) {
                    self::$stat['location'] = $geolocation_data['location']['latitude'] . ',' . $geolocation_data['location']['longitude'];
                }
            }

            // Is this country blacklisted?
            if (!empty(self::$stat['country']) && !empty(self::$settings['ignore_countries']) && false !== stripos(self::$settings['ignore_countries'], (string) self::$stat['country'])) {
                return false;
            }
        }

        // Mark or ignore Firefox/Safari prefetching requests (X-Moz: Prefetch and X-purpose: Preview)
        if ((isset($_SERVER['HTTP_X_MOZ']) && ('prefetch' === strtolower($_SERVER['HTTP_X_MOZ']))) || (isset($_SERVER['HTTP_X_PURPOSE']) && ('preview' === strtolower($_SERVER['HTTP_X_PURPOSE'])))) {
            if ('on' == self::$settings['ignore_prefetch']) {
                return false;
            } else {
                self::$stat['notes'][] = 'pre:yes';
            }
        }

        // User Agent
        $browser = \SlimStat\Services\Browscap::get_browser();

        // Are we ignoring bots?
        if ('on' == self::$settings['ignore_bots'] && 1 == $browser['browser_type']) {
            return false;
        }

        // Is this browser blacklisted?
        if (!empty(self::$settings['ignore_browsers']) && self::_is_blacklisted([$browser['browser'], $browser['user_agent']], self::$settings['ignore_browsers'])) {
            return false;
        }

        // Is this operating system blacklisted?
        if (!empty(self::$settings['ignore_platforms']) && self::_is_blacklisted($browser['platform'], self::$settings['ignore_platforms'])) {
            return false;
        }

        self::$stat += $browser;

        // Do we need to assign a visit_id to this user?
        $cookie_has_been_set = self::_set_visit_id(false);

        // Allow third-party tools to modify all the data we've gathered so far
        self::$stat = apply_filters('slimstat_filter_pageview_stat', self::$stat);
        do_action('slimstat_track_pageview', self::$stat);

        // Third-party tools can decide that this pageview should not be tracked, by setting its datestamp to zero
        if (empty(self::$stat) || empty(self::$stat['dt'])) {
            return false;
        }

        // Implode the notes
        if (!empty(self::$stat['notes'])) {
            self::$stat['notes'] = '[' . implode('][', self::$stat['notes']) . ']';
        }

        // Remove empty values
        self::$stat = array_filter(self::$stat);

        // Save this information in the database
        self::$stat['id'] = self::_insert_row(self::$stat, $GLOBALS['wpdb']->prefix . 'slim_stats');

        // Did something go wrong during the insert?
        if (empty(self::$stat['id'])) {

            // Attempt to init the environment (plugin just activated on a blog in a MU network?)
            include_once(plugin_dir_path(__FILE__) . 'admin/index.php');
            wp_slimstat_admin::init_environment();

            // Now let's try again
            self::$stat['id'] = self::_insert_row(self::$stat, $GLOBALS['wpdb']->prefix . 'slim_stats');

            if (empty(self::$stat['id'])) {
                $error = self::_log_error(200);
                return false;
            }
        }

        // Does this visitor have a visit_id cookie?
        $set_cookie = apply_filters('slimstat_set_visit_cookie', (!empty(self::$settings['set_tracker_cookie']) && 'on' == self::$settings['set_tracker_cookie']));
        if ($set_cookie) {
            if (empty(self::$stat['visit_id']) && !empty(self::$stat['id'])) {
                // Set a cookie to track this visit (Google and other non-human engines will just ignore it)
                @setcookie(
                    'slimstat_tracking_code',
                    self::_get_value_with_checksum(self::$stat['id'] . 'id'),
                    ['expires' => time() + 2678400, 'path' => COOKIEPATH]
                );
            } elseif (!$cookie_has_been_set && 'on' == self::$settings['extend_session'] && self::$stat['visit_id'] > 0) {
                @setcookie(
                    'slimstat_tracking_code',
                    self::_get_value_with_checksum(self::$stat['visit_id']),
                    ['expires' => time() + self::$settings['session_duration'], 'path' => COOKIEPATH]
                );
            }
        }

        return self::$stat['id'];
    }
    // end slimtrack

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
            'anonymize_ip'         => 'no',
            'set_tracker_cookie'   => 'on',
            'display_opt_out'      => 'no',
            'opt_out_cookie_names' => '',
            'opt_in_cookie_names'  => '',
            'opt_out_message'      => '<p style="display:block;position:fixed;left:0;bottom:0;margin:0;padding:1em 2em;background-color:#eee;width:100%;z-index:99999;">This website stores cookies on your computer. These cookies are used to provide a more personalized experience and to track your whereabouts around our website in compliance with the European General Data Protection Regulation. If you decide to to opt-out of any future tracking, a cookie will be setup in your browser to remember this choice for one year.<br><br><a href="#" onclick="javascript:SlimStat.optout(event, false);">Accept</a> or <a href="#" onclick="javascript:SlimStat.optout(event, true);">Deny</a></p>',

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
            'limit_results'                    => '1000',
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
            'notice_translate'   => 'on',

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

        if ('on' != self::$settings['javascript_mode']) {
            if (empty(self::$stat['id']) || intval(self::$stat['id']) < 0) {
                return false;
            }
            $params['id'] = self::_get_value_with_checksum(intval(self::$stat['id']));
        } else {
            $params['ci'] = self::_get_value_with_checksum(self::_base64_url_encode(serialize(self::_get_content_info())));
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

        $days_ago = strtotime(self::date_i18n('Y-m-d H:i:s') . sprintf(' -%d days', $autopurge_interval));

        // Copy entries to the archive table, if needed
        if ('no' != self::$settings['auto_purge_delete']) {
            $is_copy_done = self::$wpdb->query("
                INSERT INTO {$GLOBALS['wpdb']->prefix}slim_stats_archive (id, ip, other_ip, username, email, country, location, city, referer, resource, searchterms, notes, visit_id, server_latency, page_performance, browser, browser_version, browser_type, platform, language, fingerprint, user_agent, resolution, screen_width, screen_height, content_type, category, author, content_id, tz_offset, outbound_resource, dt_out, dt)
                SELECT id, ip, other_ip, username, email, country, location, city, referer, resource, searchterms, notes, visit_id, server_latency, page_performance, browser, browser_version, browser_type, platform, language, fingerprint, user_agent, resolution, screen_width, screen_height, content_type, category, author, content_id, tz_offset, outbound_resource, dt_out, dt
                FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_stats
                WHERE dt < {$days_ago}");

            if (false !== $is_copy_done) {
                self::$wpdb->query(sprintf('DELETE ts FROM %sslim_stats ts WHERE ts.dt < %s', $GLOBALS[ 'wpdb' ]->prefix, $days_ago));
            }

            $is_copy_done = self::$wpdb->query(
                "
                INSERT INTO {$GLOBALS['wpdb']->prefix}slim_events_archive (type, event_description, notes, position, id, dt)
                SELECT type, event_description, notes, position, id, dt
                FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_events
                WHERE dt < {$days_ago}"
            );

            if (false !== $is_copy_done) {
                self::$wpdb->query(sprintf('DELETE te FROM %sslim_events te WHERE te.dt < %s', $GLOBALS[ 'wpdb' ]->prefix, $days_ago));
            }
        } else {
            // Delete old entries
            self::$wpdb->query(sprintf('DELETE ts FROM %sslim_stats ts WHERE ts.dt < %s', $GLOBALS[ 'wpdb' ]->prefix, $days_ago));
            self::$wpdb->query(sprintf('DELETE te FROM %sslim_events te WHERE te.dt < %s', $GLOBALS[ 'wpdb' ]->prefix, $days_ago));
        }

        // Optimize tables
        self::$wpdb->query(sprintf('OPTIMIZE TABLE %sslim_stats', $GLOBALS[ 'wpdb' ]->prefix));
        self::$wpdb->query(sprintf('OPTIMIZE TABLE %sslim_stats_archive', $GLOBALS[ 'wpdb' ]->prefix));
        self::$wpdb->query(sprintf('OPTIMIZE TABLE %sslim_events', $GLOBALS[ 'wpdb' ]->prefix));
        self::$wpdb->query(sprintf('OPTIMIZE TABLE %sslim_events_archive', $GLOBALS[ 'wpdb' ]->prefix));
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
     * Update content type as needed
     */
    public static function update_content_type($_status = 301, $_location = '')
    {
        if ($_status >= 300 && $_status < 400) {
            // SEE WHY THIS DOESN'T WORK?!
            self::$stat['content_type'] = 'redirect:' . intval($_status);
            self::_update_row(self::$stat);
        }

        return $_status;
    }
    // end update_content_type

    /**
     * Stores the pageview information in the database and returns the ID associated to the new entry
     */
    protected static function _insert_row($_data = [], $_table = '')
    {
        if (empty($_data) || empty($_table)) {
            return -1;
        }

        // Remove unwanted characters from keys (SQL injections, anyone?)
        $data_keys = [];
        foreach (array_keys($_data) as $a_key) {
            $data_keys[] = sanitize_key($a_key);
        }

        // Remove unwanted characters from data (SQL injections, anyone?)
        foreach ($_data as $key => $value) {
            $_data[$key] = 'resource' == $key ? sanitize_url($value) : sanitize_text_field($value);
        }

        self::$wpdb->query(self::$wpdb->prepare("
            INSERT IGNORE INTO {$_table} (" . implode(', ', $data_keys) . ')
            VALUES (' . substr(str_repeat('%s,', count($_data)), 0, -1) . ')', $_data));

        return intval(self::$wpdb->insert_id);
    }
    // end _insert_row

    /**
     * Updates an existing row
     */
    protected static function _update_row($_data = [])
    {
        if (empty($_data) || empty($_data['id'])) {
            return false;
        }

        // Extract the ID from the array
        $id = abs(intval($_data['id']));
        unset($_data['id']);

        // Sanitize column names (SQL/XSS injections, anyone?)
        $_data = array_filter($_data);

        // The 'notes' column stores multiple comma-separated values: we need to append the new value to the existing ones
        // Also, values are organized in an array, which we need to implode as a string
        $notes = '';
        if (!empty($_data['notes']) && is_array($_data['notes'])) {
            $notes = (count($_data) > 1 ? ',' : '') . "notes=CONCAT( IFNULL( notes, '' ), '[" . esc_sql(implode('][', $_data['notes'])) . "]' )";
            unset($_data['notes']);
        }

        $prepared_query = self::$wpdb->prepare("
            UPDATE IGNORE {$GLOBALS[ 'wpdb' ]->prefix}slim_stats
            SET " . implode('=%s,', array_keys($_data)) . "=%s
            WHERE id = {$id}
        ", $_data);

        // Add the notes
        if ('' !== $notes && '0' !== $notes) {
            $prepared_query = str_replace('WHERE id =', $notes . ' WHERE id =', $prepared_query);
        }

        // Save the data in the database
        self::$wpdb->query($prepared_query);

        return $id;
    }
    // end _update_row

    /**
     * Tries to find the user's REAL IP address
     */
    protected static function _get_remote_ip()
    {
        $ip_array = ['', ''];

        if (!empty($_SERVER['REMOTE_ADDR']) && false !== filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
            $ip_array[0] = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        $originating_ip_headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR', 'HTTP_CLIENT_IP', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_INCAP_CLIENT_IP'];
        foreach ($originating_ip_headers as $a_header) {
            if (!empty($_SERVER[$a_header])) {
                foreach (explode(',', $_SERVER[$a_header]) as $a_ip) {
                    if (false !== filter_var($a_ip, FILTER_VALIDATE_IP) && $a_ip != $ip_array[0]) {
                        $ip_array[1] = $a_ip;
                        break;
                    }
                }
            }
        }

        return apply_filters('slimstat_filter_ip_address', $ip_array);
    }
    // end _get_remote_ip

    /**
     * Extracts the accepted language from browser headers
     */
    protected static function _get_language()
    {
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {

            // Capture up to the first delimiter (, found in Safari)
            preg_match('/([^,;]*)/', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $array_languages);

            // Fix some codes, the correct syntax is with minus (-) not underscore (_)
            return str_replace('_', '-', strtolower($array_languages[0]));
        }
        return '';  // Indeterminable language
    }
    // end _get_language

    /**
     * Sniffs out referrals from search engines and tries to determine the query string
     */
    protected static function _get_search_terms($_url = '')
    {
        if (empty($_url)) {
            return '';
        }

        $searchterms = '';

        // Load the search engines list to mark pageviews accordingly
        // Each entry contains the following attributes
        // - params: which query string params is associated to the search keyword
        // - backlink: format of the URL point to the search engine result page
        // - charsets: list of charset used to encode the keywords
        //
        $search_engines = file_get_contents(plugin_dir_path(__FILE__) . 'admin/assets/data/matomo-searchengine.json');
        $search_engines = json_decode($search_engines, true);

        $parsed_url = @parse_url($_url);

        if (empty($search_engines) || empty($parsed_url) || empty($parsed_url['host'])) {
            return '';
        }

        $sek = self::get_lossy_url($parsed_url['host']);

        if (!empty($search_engines[$sek])) {
            if (empty($search_engines[$sek]['params'])) {
                $search_engines[$sek]['params'] = ['q'];
            }

            foreach ($search_engines[$sek]['params'] as $a_param) {
                if (!empty($parsed_url['query'])) {
                    $searchterms = self::_get_param_from_query_string($parsed_url['query'], $a_param);
                    if (!empty($searchterms)) {
                        break;
                    }
                }
            }

            // Make sure to use the appropriate charset, if specified
            if (!empty($searchterms) && (!empty($search_engines['charsets']) && function_exists('iconv'))) {
                $charset = $search_engines['charsets'][0];
                if (count($search_engines['charsets']) > 1 && function_exists('mb_detect_encoding')) {
                    $charset = mb_detect_encoding($searchterms, $search_engines['charsets']);
                    if (false === $charset) {
                        $charset = $search_engines['charsets'][0];
                    }
                }
                $new_searchterms = @iconv($charset, 'UTF-8//IGNORE', $searchterms);
                if (!('' === $new_searchterms || '0' === $new_searchterms || false === $new_searchterms)) {
                    $searchterms = $new_searchterms;
                }
            }
        } elseif (!empty($parsed_url['query'])) {
            // We weren't lucky, but there's still hope
            foreach (['ask', 'k', 'q', 'qs', 'qt', 'query', 's', 'string'] as $a_param) {
                $searchterms = self::_get_param_from_query_string($parsed_url['query'], $a_param);
                if (!empty($searchterms)) {
                    break;
                }
            }
        }

        return sanitize_text_field($searchterms);
    }
    // end _get_search_terms

    /**
     * Retrieves a param value from a string treated as a URL query string
     */
    protected static function _get_param_from_query_string($_query = '', $_parameter = '')
    {
        if (empty($_query)) {
            return '';
        }

        @parse_str($_query, $values);

        return empty($values[$_parameter]) ? '' : $values[$_parameter];
    }
    // end _get_param_from_query_string

    /**
     * Returns details about the resource being accessed
     */
    protected static function _get_content_info()
    {
        $content_info = ['content_type' => ''];

        // Mark 404 pages
        if (is_404()) {
            $content_info['content_type'] = '404';
        } // Type
        elseif (is_single()) {
            if (($post_type = get_post_type()) != 'post') {
                $post_type = 'cpt:' . $post_type;
            }

            $content_info['content_type'] = $post_type;
            $category_ids                 = [];
            foreach (get_object_taxonomies($GLOBALS['post']) as $a_taxonomy) {
                $terms = get_the_terms($GLOBALS['post']->ID, $a_taxonomy);
                if (is_array($terms)) {
                    foreach ($terms as $a_term) {
                        $category_ids[] = $a_term->term_id;
                    }
                    $content_info['category'] = implode(',', $category_ids);
                }
            }
            $content_info['content_id'] = $GLOBALS['post']->ID;
        } elseif (is_page()) {
            $content_info['content_type'] = 'page';
            $content_info['content_id']   = $GLOBALS['post']->ID;
        } elseif (is_attachment()) {
            $content_info['content_type'] = 'attachment';
        } elseif (is_singular()) {
            $content_info['content_type'] = 'singular';
        } elseif (is_post_type_archive()) {
            $content_info['content_type'] = 'post_type_archive';
        } elseif (is_tag()) {
            $content_info['content_type'] = 'tag';
            $list_tags                    = get_the_tags();
            if (is_array($list_tags)) {
                $tag_info = array_pop($list_tags);
                if (!empty($tag_info)) {
                    $content_info['category'] = $tag_info->term_id;
                }
            }
        } elseif (is_tax()) {
            $content_info['content_type'] = 'taxonomy';
        } elseif (is_category()) {
            $content_info['content_type'] = 'category';
            $list_categories              = get_the_category();
            if (is_array($list_categories)) {
                $cat_info = array_pop($list_categories);
                if (!empty($cat_info)) {
                    $content_info['category'] = $cat_info->term_id;
                }
            }
        } elseif (is_date()) {
            $content_info['content_type'] = 'date';
        } elseif (is_author()) {
            $content_info['content_type'] = 'author';
        } elseif (is_archive()) {
            $content_info['content_type'] = 'archive';
        } elseif (is_search()) {
            $content_info['content_type'] = 'search';
        } elseif (is_feed()) {
            $content_info['content_type'] = 'feed';
        } elseif (is_home() || is_front_page()) {
            $content_info['content_type'] = 'home';
        } elseif (!empty($GLOBALS['pagenow']) && 'wp-login.php' == $GLOBALS['pagenow']) {
            $content_info['content_type'] = 'login';
        } elseif (!empty($GLOBALS['pagenow']) && 'wp-register.php' == $GLOBALS['pagenow']) {
            $content_info['content_type'] = 'registration';
        } // WordPress sets is_admin() to true for all ajax requests ( front-end or admin-side )
        elseif (is_admin() && (!defined('DOING_AJAX') || !DOING_AJAX)) {
            $content_info['content_type'] = 'admin';
        }

        if (is_paged()) {
            $content_info['content_type'] .= ':paged';
        }

        // Author
        if (is_singular()) {
            $author = get_the_author_meta('user_login', $GLOBALS['post']->post_author);
            if (!empty($author)) {
                $content_info['author'] = $author;
            }
        }

        return $content_info;
    }
    // end _get_content_info

    /**
     * Reads the information sent by the Javascript tracker and adds it to the $_stat array
     */
    protected static function _get_client_info($_data_js = [], $_stat = [])
    {
        if (!empty($_data_js['bw'])) {
            $_stat['resolution'] = strip_tags(trim($_data_js['bw'] . 'x' . $_data_js['bh']));
        }
        if (!empty($_data_js['sw'])) {
            $_stat['screen_width'] = intval($_data_js['sw']);
        }
        if (!empty($_data_js['sh'])) {
            $_stat['screen_height'] = intval($_data_js['sh']);
        }
        if (!empty($_data_js['sl']) && $_data_js['sl'] > 0 && $_data_js['sl'] < 60000) {
            $_stat['server_latency'] = intval($_data_js['sl']);
        }
        if (!empty($_data_js['pp']) && $_data_js['pp'] > 0 && $_data_js['pp'] < 60000) {
            $_stat['page_performance'] = intval($_data_js['pp']);
        }
        if (!empty($_data_js['fh']) && 'on' != self::$settings['anonymize_ip']) {
            $_stat['fingerprint'] = sanitize_text_field($_data_js['fh']);
        }
        if (!empty($_data_js['tz'])) {
            $_stat['tz_offset'] = intval($_data_js['tz']);
        }

        return $_stat;
    }
    // end _get_client_info

    /**
     * Reads the cookie to get the visit_id and sets the variable accordingly
     */
    protected static function _set_visit_id($_force_assign = false)
    {
        $is_new_session = true;
        $identifier     = 0;

        if (isset($_COOKIE['slimstat_tracking_code'])) {
            // Make sure only authorized information is recorded
            $identifier = self::_get_value_without_checksum($_COOKIE['slimstat_tracking_code']);
            if (false === $identifier) {
                return false;
            }

            $is_new_session = (false !== strpos($identifier, 'id'));
            $identifier     = intval($identifier);
        }

        // User doesn't have an active session
        if ($is_new_session && ($_force_assign || 'on' == self::$settings['javascript_mode'])) {
            if (empty(self::$settings['session_duration'])) {
                self::$settings['session_duration'] = 1800;
            }

            self::$stat['visit_id'] = get_transient('slimstat_visit_id');
            if (false === self::$stat['visit_id']) {
                self::$stat['visit_id'] = intval(self::$wpdb->get_var(sprintf('SELECT MAX( visit_id ) FROM %sslim_stats', $GLOBALS[ 'wpdb' ]->prefix)));
            }
            self::$stat['visit_id']++;
            set_transient('slimstat_visit_id', self::$stat['visit_id']);

            $set_cookie = apply_filters('slimstat_set_visit_cookie', (!empty(self::$settings['set_tracker_cookie']) && 'on' == self::$settings['set_tracker_cookie']));
            if ($set_cookie) {
                @setcookie(
                    'slimstat_tracking_code',
                    self::_get_value_with_checksum(self::$stat['visit_id']),
                    ['expires' => time() + self::$settings['session_duration'], 'path' => COOKIEPATH]
                );
            }

        } elseif ($identifier > 0) {
            self::$stat['visit_id'] = $identifier;
        }

        if ($is_new_session && $identifier > 0) {
            self::$wpdb->query(self::$wpdb->prepare(
                "
                UPDATE {$GLOBALS['wpdb' ]->prefix}slim_stats
                SET visit_id = %d
                WHERE id = %d AND visit_id = 0",
                self::$stat['visit_id'],
                $identifier
            ));
        }
        return ($is_new_session && ($_force_assign || 'on' == self::$settings['javascript_mode']));
    }
    // end _set_visit_id

    /**
     * Saves an error detected by the tracker in the database
     */
    protected static function _log_error($_error_code = 0)
    {
        // Save this error in the database
        self::update_option('slimstat_tracker_error', [$_error_code, self::date_i18n('U')]);

        // Allow third-party code to trigger actions based on this error
        do_action('slimstat_track_exit_' . abs($_error_code), self::$stat);

        return -$_error_code;
    }

    // end _log_error

    protected static function _get_value_with_checksum($_value = 0)
    {
        return $_value . '.' . md5($_value . self::$settings['secret']);
    }

    protected static function _get_value_without_checksum($_value_with_checksum = '')
    {
        [$value, $checksum] = explode('.', $_value_with_checksum);

        if ($checksum === md5($value . self::$settings['secret'])) {
            return $value;
        }

        return false;
    }

    /**
     * Determines if a given string is listed in the corresponding 'exclusion' field
     */
    protected static function _is_blacklisted($_needles = [], $_haystack_string = '')
    {
        foreach (self::string_to_array($_haystack_string) as $a_item) {
            $pattern = str_replace(['\*', '\!'], ['(.*)', '.'], preg_quote($a_item, '@'));

            if (!is_array($_needles)) {
                $_needles = [$_needles];
            }

            foreach ($_needles as $a_needle) {
                if (preg_match(sprintf('@^%s$@i', $pattern), $a_needle)) {
                    return true;
                }
            }
        }

        return false;
    }
    // end _is_blacklisted

    /**
     * Determines if this is a new visitor, meaning that we've never seen this fingerprint before
     */
    protected static function _is_new_visitor($_fingerprint = '')
    {
        // If the privacy option is enabled, all visitors would be considered "new"...
        if ('on' == self::$settings['anonymize_ip']) {
            return false;
        }

        $count_fingerprint = self::$wpdb->get_var(self::$wpdb->prepare(
            "
            SELECT COUNT( id )
            FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_stats
            WHERE fingerprint = %s",
            $_fingerprint
        ));

        return 0 == $count_fingerprint;
    }
    // end _is_new_visitor

    /**
     * Validates and unpacks an IP Address
     */
    protected static function _dtr_pton($_ip)
    {
        $unpacked = false;

        if (filter_var($_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $unpacked = unpack('A4', inet_pton($_ip));
        } elseif (filter_var($_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && defined('AF_INET6')) {
            $unpacked = unpack('A16', inet_pton($_ip));
        }

        $binary_ip = '';
        if ([] !== $unpacked && false !== $unpacked && isset($unpacked[1])) {
            $unpacked = str_split($unpacked[1]);
            foreach ($unpacked as $char) {
                $binary_ip .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
            }
        }

        return $binary_ip;
    }
    // end _dtr_pton

    /**
     * Helper function to determine if we should ignore visits coming from this IP address
     */
    protected static function _get_mask_length($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 32;
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 128;
        }

        return false;
    }
    // end _get_mask_length

    /**
     * These two functions here implement an URL-safe base64 string
     */
    protected static function _base64_url_encode($_input = '')
    {
        return strtr(base64_encode($_input), '+/=', '._-');
    }

    protected static function _base64_url_decode($_input = '')
    {
        return strip_tags(trim(base64_decode(strtr($_input, '._-', '+/='))));
    }
    // end _base64_url_encode/decode

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

        add_action('wp_ajax_nopriv_slimtrack', ['wp_slimstat', 'slimtrack_ajax']);
        add_action('wp_ajax_slimtrack', ['wp_slimstat', 'slimtrack_ajax']);
    }

    include_once(plugin_dir_path(__FILE__) . 'src/Constants.php');

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
