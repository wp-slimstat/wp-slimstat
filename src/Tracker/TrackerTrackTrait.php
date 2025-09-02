<?php

namespace SlimStat\Tracker;

use SlimStat\Services\Privacy;
use SlimStat\Services\GeoService;
use SlimStat\Services\GeoIP;
use SlimStat\Services\Browscap;
use SlimStat\Utils\Query;

trait TrackerTrackTrait
{
    /**
     * THE Slimstat tracker
     */
    public static function slimtrack()
    {
        self::$stat['dt'] = self::date_i18n('U');

        if (empty(self::$stat['notes'])) {
            self::$stat['notes'] = [];
        }

        self::$stat = apply_filters('slimstat_filter_pageview_stat_init', self::$stat);

        if (self::$stat === [] || empty(self::$stat['dt'])) {
            return false;
        }

        unset(self::$stat['id']);

        // Opt-out via cookie
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
                    unset($_COOKIE['slimstat_tracking_code']);
                    @setcookie('slimstat_tracking_code', '', ['expires' => time() - (15 * 60), 'path' => COOKIEPATH]);
                    return false;
                }
            }
        }

        // Opt-in cookie handling
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
                unset($_COOKIE['slimstat_tracking_code']);
                @setcookie('slimstat_tracking_code', '', ['expires' => time() - (15 * 60), 'path' => COOKIEPATH]);
                return false;
            }
        }

        // IP address
        [self::$stat['ip'], self::$stat['other_ip']] = self::_get_remote_ip();

        if (empty(self::$stat['ip']) || '0.0.0.0' == self::$stat['ip']) {
            $error = self::_log_error(202);
            return false;
        }

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

        $original_ip = self::$stat['ip'];
        if ('on' == self::$settings['anonymize_ip']) {
            self::$stat['ip'] = wp_privacy_anonymize_ip(self::$stat['ip']);
            if (!empty(self::$stat['other_ip'])) {
                self::$stat['other_ip'] = wp_privacy_anonymize_ip(self::$stat['other_ip']);
            }
        }

        if ('on' == (self::$settings['hash_ip'] ?? 'off')) {
            $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $secret = self::$settings['secret'] ?? wp_hash('slimstat');

            // Prefer a plugin option `slimstat_daily_salt` if available. This
            // allows admins to rotate a per-day salt and still compute a
            // non-reversible identifier using HMAC.
            $daily_salt = get_option('slimstat_daily_salt');
            if (!empty($daily_salt)) {
                // Use HMAC-SHA256 with secret as key. We include UA here to
                $data = $daily_salt . '|' . $original_ip . '|' . $ua;
                $hash = hash_hmac('sha256', $data, $secret);

                if ('on' != self::$settings['anonymize_ip']) {
                    self::$stat['other_ip'] = '';
                }

                self::$stat['ip'] = $hash;
            } else {
                // Fallback to existing Privacy helper if no daily salt is set.
                if (!class_exists(Privacy::class)) {
                    @include_once plugin_dir_path(__FILE__) . 'src/Services/Privacy.php';
                }

                if (class_exists(Privacy::class)) {
                    $hash = Privacy::computeVisitorId($original_ip, $ua, time(), $secret);
                    if ('on' != self::$settings['anonymize_ip']) {
                        self::$stat['other_ip'] = '';
                    }

                    self::$stat['ip'] = $hash;
                }
            }
        }

        if (!isset(self::$stat['resource'])) {
            self::$stat['resource'] = self::get_request_uri();
        }

        self::$stat['resource'] = sanitize_text_field(urldecode(self::$stat['resource']));
        self::$stat['resource'] = preg_replace_callback('/[^\x20-\x7E]/', fn ($match) => '%' . bin2hex($match[0]), self::$stat['resource']);

        $parsed_url = parse_url(self::$stat['resource']);
        if (!$parsed_url) {
            $error = self::_log_error(203);
            return false;
        }

        self::$stat['resource'] = $parsed_url['path'] . (empty($parsed_url['query']) ? '' : '?' . $parsed_url['query']) . (empty($parsed_url['fragment']) ? '' : '#' . $parsed_url['fragment']);

        if (!empty(self::$settings['ignore_resources']) && self::_is_blacklisted(self::$stat['resource'], self::$settings['ignore_resources'])) {
            return false;
        }

        if (empty(self::$stat['referer']) && !empty($_SERVER['HTTP_REFERER'])) {
            self::$stat['referer'] = sanitize_url(wp_unslash($_SERVER['HTTP_REFERER']));
        }

        if (!empty(self::$stat['referer'])) {
            $parsed_url = parse_url(self::$stat['referer']);
            if (!$parsed_url) {
                $error = self::_log_error(201);
                return false;
            }

            if (isset($parsed_url['scheme']) && ('' !== $parsed_url['scheme'] && '0' !== $parsed_url['scheme']) && !in_array(strtolower($parsed_url['scheme']), ['http', 'https', 'android-app'])) {
                self::$stat['notes'][] = sprintf(__('Attempted XSS Injection: %s', 'wp-slimstat'), self::$stat['referer']);
                unset(self::$stat['referer']);
            }

            if (!empty(self::$settings['ignore_referers']) && self::_is_blacklisted(self::$stat['referer'], self::$settings['ignore_referers'])) {
                return false;
            }

            self::$stat['searchterms'] = self::_get_search_terms(self::$stat['referer']);

            $parsed_site_url = parse_url(get_site_url(), PHP_URL_HOST);
            if (isset($parsed_url['host']) && ('' !== $parsed_url['host'] && '0' !== $parsed_url['host']) && $parsed_url['host'] == $parsed_site_url && 'on' != self::$settings['track_same_domain_referers']) {
                unset(self::$stat['referer']);
            }
        }

        if (empty(self::$stat['searchterms']) && !empty($_POST['s'])) {
            self::$stat['searchterms'] = sanitize_text_field(str_replace('\\', '', $_REQUEST['s']));
        }

        if (!isset(self::$stat['content_type'])) {
            $content_info = self::_get_content_info();

            if (!empty(self::$settings['ignore_content_types']) && self::_is_blacklisted($content_info['content_type'], self::$settings['ignore_content_types'])) {
                return false;
            }

            if (is_array($content_info)) {
                self::$stat += $content_info;
            }
        }

        if ((is_archive() || is_search()) && !empty($GLOBALS['wp_query']->found_posts)) {
            self::$stat['notes'][] = 'results:' . intval($GLOBALS['wp_query']->found_posts);
        }

        if ((isset(self::$stat['resource']) && (self::$stat['resource'] !== '' && self::$stat['resource'] !== '0') && false !== strpos(self::$stat['resource'], 'wp-admin/admin-ajax.php')) || (!empty($_GET['page']) && false !== strpos($_GET['page'], 'slimview'))) {
            return false;
        }

        if (!empty($GLOBALS['current_user']->ID)) {
            if ('on' == self::$settings['ignore_wp_users']) {
                return false;
            }

            foreach ($GLOBALS['current_user']->roles as $a_capability) {
                if (self::_is_blacklisted($a_capability, self::$settings['ignore_capabilities'])) {
                    return false;
                }
            }

            if (!empty(self::$settings['ignore_users']) && self::_is_blacklisted($GLOBALS['current_user']->data->user_login, self::$settings['ignore_users'])) {
                return false;
            }

            self::$stat['username'] = $GLOBALS['current_user']->data->user_login;
            self::$stat['email']    = $GLOBALS['current_user']->data->user_email;
            self::$stat['notes'][]  = 'user:' . $GLOBALS['current_user']->data->ID;
            $not_spam               = true;
        } elseif (isset($_COOKIE['comment_author_' . COOKIEHASH])) {
            $spam_comment = self::$wpdb->get_row(self::$wpdb->prepare('\n                SELECT comment_author, comment_author_email, COUNT(*) comment_count\n                FROM `' . DB_NAME . "`.{$GLOBALS['wpdb']->comments}\n                WHERE comment_author_IP = %s AND comment_approved = 'spam'\n                GROUP BY comment_author\n                LIMIT 0,1", self::$stat['ip']), ARRAY_A);

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

        self::$stat['language'] = self::_get_language();

        if (!empty(self::$stat['language']) && !empty(self::$settings['ignore_languages']) && false !== stripos(self::$settings['ignore_languages'], (string) self::$stat['language'])) {
            return false;
        }

        $geographicProvider = new GeoService();
        if ($geographicProvider->isGeoIPEnabled()) {
            try {
                $geolocation_data = GeoIP::loader(self::$stat['ip']);
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

            if (isset(self::$stat['country']) && (self::$stat['country'] !== '' && self::$stat['country'] !== '0') && !empty(self::$settings['ignore_countries']) && false !== stripos(self::$settings['ignore_countries'], self::$stat['country'])) {
                return false;
            }
        }

        if ((isset($_SERVER['HTTP_X_MOZ']) && ('prefetch' === strtolower($_SERVER['HTTP_X_MOZ']))) || (isset($_SERVER['HTTP_X_PURPOSE']) && ('preview' === strtolower($_SERVER['HTTP_X_PURPOSE'])))) {
            if ('on' == self::$settings['ignore_prefetch']) {
                return false;
            } else {
                self::$stat['notes'][] = 'pre:yes';
            }
        }

        $browser = Browscap::get_browser();

        if ('on' == self::$settings['ignore_bots'] && 1 == $browser['browser_type']) {
            return false;
        }

        if (!empty(self::$settings['ignore_browsers']) && self::_is_blacklisted([$browser['browser'], $browser['user_agent']], self::$settings['ignore_browsers'])) {
            return false;
        }

        if (!empty(self::$settings['ignore_platforms']) && self::_is_blacklisted($browser['platform'], self::$settings['ignore_platforms'])) {
            return false;
        }

        self::$stat += $browser;

        $cookie_has_been_set = self::_set_visit_id(false);

        self::$stat = apply_filters('slimstat_filter_pageview_stat', self::$stat);
        do_action('slimstat_track_pageview', self::$stat);

        if (self::$stat === [] || empty(self::$stat['dt'])) {
            return false;
        }

        if (isset(self::$stat['notes']) && self::$stat['notes'] !== []) {
            self::$stat['notes'] = '[' . implode('][', self::$stat['notes']) . ']';
        }

        self::$stat = array_filter(self::$stat);

        self::$stat['id'] = self::_insert_row(self::$stat, $GLOBALS['wpdb']->prefix . 'slim_stats');

        if (empty(self::$stat['id'])) {
            include_once(plugin_dir_path(__FILE__) . 'admin/index.php');
            wp_slimstat_admin::init_environment();

            self::$stat['id'] = self::_insert_row(self::$stat, $GLOBALS['wpdb']->prefix . 'slim_stats');

            if (empty(self::$stat['id'])) {
                $error = self::_log_error(200);
                return false;
            }
        }

        $set_cookie = apply_filters('slimstat_set_visit_cookie', (!empty(self::$settings['set_tracker_cookie']) && 'on' == self::$settings['set_tracker_cookie']));
        if ($set_cookie) {
            if (empty(self::$stat['visit_id']) && !empty(self::$stat['id'])) {
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

    public static function update_content_type($_status = 301, $_location = '')
    {
        if ($_status >= 300 && $_status < 400) {
            self::$stat['content_type'] = 'redirect:' . intval($_status);
            self::_update_row(self::$stat);
        }

        return $_status;
    }
}
