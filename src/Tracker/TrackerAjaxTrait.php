<?php

namespace SlimStat\Tracker;

/**
 * Ajax / routing related tracker methods
 */
trait TrackerAjaxTrait
{
    /**
     * Reads and processes the data received by the XHR tracker
     */
    public static function slimtrack_ajax()
    {
        // Simple IP-based rate limiter (transient)
        $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($remote_ip)) {
            $key        = 'slimstat_rl_' . md5($remote_ip);
            $hits_in_5s = (int) get_transient($key);
            if ($hits_in_5s >= 10) {
                exit(self::_log_error(429));
            }
            set_transient($key, $hits_in_5s + 1, 5);
        }

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

        if (!empty(self::$data_js['id'])) {
            self::$data_js['id'] = self::_get_value_without_checksum(self::$data_js['id']);

            if (false === self::$data_js['id']) {
                exit(self::_log_error(101));
            }

            self::$stat['id'] = intval(self::$data_js['id']);
            if (self::$stat['id'] < 0) {
                do_action('slimstat_track_exit_' . abs(self::$stat['id']));
                exit(self::_get_value_with_checksum(self::$stat['id']));
            }

            if (empty(self::$data_js['pos'])) {
                self::_set_visit_id(true);
                self::$stat = self::_get_client_info(self::$data_js, self::$stat);

                if (empty(self::$stat['resolution'])) {
                    self::$stat['dt_out'] = self::date_i18n('U');
                }

                if (!empty(self::$stat['fingerprint']) && self::_is_new_visitor(self::$stat['fingerprint'])) {
                    self::$stat['notes'] = ['new:yes'];
                }

                $id = self::_update_row(self::$stat);
            } else {
                $event_info = [
                    'position' => strip_tags(trim(self::$data_js['pos'])),
                    'id'       => self::$stat['id'],
                    'dt'       => self::date_i18n('U'),
                ];

                if (!empty(self::$data_js['no'])) {
                    $event_info['notes'] = self::_base64_url_decode(self::$data_js['no']);
                }

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

                    if (!empty($parsed_resource['path']) && in_array(pathinfo($parsed_resource['path'], PATHINFO_EXTENSION), self::string_to_array(self::$settings['extensions_to_track']))) {
                        self::$stat['resource']     = $parsed_resource['path'] . (empty($parsed_resource['query']) ? '' : '?' . $parsed_resource['query']);
                        self::$stat['content_type'] = 'download';

                        if (!empty(self::$data_js['fh'])) {
                            self::$stat['fingerprint'] = sanitize_text_field(self::$data_js['fh']);
                        }

                        $id = self::slimtrack();
                    } elseif ($parsed_resource['host'] != $site_host) {
                        self::$stat['outbound_resource'] = $resource;
                        self::$stat['dt_out'] = self::date_i18n('U');

                        $id = self::_update_row(self::$stat);
                    }
                } else {
                    self::$stat['dt_out'] = self::date_i18n('U');
                    $id = self::_update_row(self::$stat);
                }
            }
        } else {
            self::$stat['resource'] = '';
            if (!empty(self::$data_js['res'])) {
                self::$stat['resource'] = self::_base64_url_decode(self::$data_js['res']);

                if (false === parse_url(self::$stat['resource'])) {
                    exit(self::_log_error(203));
                }
            }

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
            } else {
                self::$stat['content_type'] = 'external';
            }

            if (!empty(self::$stat['fingerprint']) && self::_is_new_visitor(self::$stat['fingerprint'])) {
                self::$stat['notes'] = ['new:yes'];
            }

            $id = self::slimtrack();
        }

        if (empty($id)) {
            exit(0);
        }

        do_action('slimstat_track_success');
        exit(self::_get_value_with_checksum($id));
    }

    /**
     * Rewrite rule for static tracker
     */
    public static function rewrite_rule_tracker()
    {
        // Always register the tracker rewrite rule for adblock bypass and fallback
        add_rewrite_tag('%slimstat_tracker%', '([a-f0-9]{32})');
        add_rewrite_rule(
            '^([a-f0-9]{32})\\.js$',
            'index.php?slimstat_tracker=$matches[1]',
            'top'
        );
    }

    /**
     * Function to detect if Adblock is enabled and serve the JS tracker
     */
    public static function adblocker_javascript()
    {
        // Always handle the tracker JS endpoint for fallback
        $tracker_hash = get_query_var('slimstat_tracker');
        if ($tracker_hash && $tracker_hash === md5(site_url() . 'slimstat')) {
            // Set the content type to JavaScript
            header('Content-Type: application/javascript');

            // Set caching headers for one year
            header('Cache-Control: public, max-age=31536000');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');

            $js_path = SLIMSTAT_ANALYTICS_DIR . 'wp-slimstat.min.js';

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
}
