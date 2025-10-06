<?php

namespace SlimStat\Tracker;

use SlimStat\Services\Privacy;
use SlimStat\Services\GeoService;
use SlimStat\Services\GeoIP;
use SlimStat\Utils\Query;

class Tracker
{
    public static function slimtrack_ajax()
    {
        Ajax::handle();
    }

    public static function rewrite_rule_tracker()
    {
        Routing::addRewriteRules();
    }

    public static function adblocker_javascript()
    {
        Routing::outputTrackerJsIfRequested();
    }

    public static function slimtrack()
    {
        return Processor::process();
    }

    public static function update_content_type($_status = 301, $_location = '')
    {
        return Processor::updateContentType($_status, $_location);
    }

    public static function _insert_row($_data = [], $_table = '')
    {
        if (empty($_data) || empty($_table)) {
            return -1;
        }

        $data_keys = [];
        foreach (array_keys($_data) as $a_key) {
            $data_keys[] = sanitize_key($a_key);
        }

        foreach ($_data as $key => $value) {
            $_data[$key] = 'resource' == $key ? sanitize_url($value) : sanitize_text_field($value);
        }

        \wp_slimstat::$wpdb->query(\wp_slimstat::$wpdb->prepare(
            sprintf('INSERT IGNORE INTO %s (', $_table) . implode(', ', $data_keys) . ') VALUES (' . substr(str_repeat('%s,', count($_data)), 0, -1) . ')',
            $_data
        ));

        return intval(\wp_slimstat::$wpdb->insert_id);
    }

    public static function _update_row($_data = [])
    {
        if (empty($_data) || empty($_data['id'])) {
            return false;
        }

        $id = abs(intval($_data['id']));
        unset($_data['id']);

        $_data = array_filter($_data);

        $notes = '';
        if (!empty($_data['notes']) && is_array($_data['notes'])) {
            $notes = (count($_data) > 1 ? ',' : '') . "notes=CONCAT( IFNULL( notes, '' ), '[" . esc_sql(implode('][', $_data['notes'])) . "]' )";
            unset($_data['notes']);
        }

        $prepared_query = \wp_slimstat::$wpdb->prepare(
            sprintf('UPDATE IGNORE %sslim_stats SET ', $GLOBALS[ 'wpdb' ]->prefix) . implode('=%s,', array_keys($_data)) . ('=%s WHERE id = ' . $id),
            $_data
        );

        if ('' !== $notes && '0' !== $notes) {
            $prepared_query = str_replace('WHERE id =', $notes . ' WHERE id =', $prepared_query);
        }

        \wp_slimstat::$wpdb->query($prepared_query);

        return $id;
    }

    public static function _set_visit_id($_force_assign = false)
    {
        $is_new_session = true;
        $identifier     = 0;

        if (isset($_COOKIE['slimstat_tracking_code'])) {
            $identifier = self::_get_value_without_checksum($_COOKIE['slimstat_tracking_code']);
            if (false === $identifier) {
                return false;
            }

            $is_new_session = (false !== strpos($identifier, 'id'));
            $identifier     = intval($identifier);
        }

        if ($is_new_session && ($_force_assign || 'on' == \wp_slimstat::$settings['javascript_mode'])) {
            if (empty(\wp_slimstat::$settings['session_duration'])) {
                \wp_slimstat::$settings['session_duration'] = 1800;
            }

            $table = $GLOBALS['wpdb']->prefix . 'slim_stats';

            $next_visit_id = \wp_slimstat::$wpdb->get_var(
                "SELECT AUTO_INCREMENT FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = '{$table}'"
            );

            if ($next_visit_id === null || $next_visit_id <= 0) {
                $max_visit_id  = \wp_slimstat::$wpdb->get_var('SELECT COALESCE(MAX(visit_id), 0) FROM ' . $table);
                $next_visit_id = intval($max_visit_id) + 1;
            }

            if ($next_visit_id <= 0) {
                $next_visit_id = time();
            }

            $existing_visit_id = \wp_slimstat::$wpdb->get_var(
                \wp_slimstat::$wpdb->prepare('SELECT visit_id FROM %s WHERE visit_id = ' . $table, $next_visit_id)
            );

            if ($existing_visit_id !== null) {
                do {
                    $next_visit_id++;
                    $existing_visit_id = \wp_slimstat::$wpdb->get_var(
                        \wp_slimstat::$wpdb->prepare('SELECT visit_id FROM %s WHERE visit_id = ' . $table, $next_visit_id)
                    );
                } while ($existing_visit_id !== null);
            }

            \wp_slimstat::$stat['visit_id'] = intval($next_visit_id);

            $set_cookie = apply_filters('slimstat_set_visit_cookie', (!empty(\wp_slimstat::$settings['set_tracker_cookie']) && 'on' == \wp_slimstat::$settings['set_tracker_cookie']));
            if ($set_cookie) {
                @setcookie('slimstat_tracking_code', self::_get_value_with_checksum(\wp_slimstat::$stat['visit_id']), ['expires' => time() + \wp_slimstat::$settings['session_duration'], 'path' => COOKIEPATH]);
            }

        } elseif ($identifier > 0) {
            \wp_slimstat::$stat['visit_id'] = $identifier;
        }

        if ($is_new_session && $identifier > 0) {
            \wp_slimstat::$wpdb->query(\wp_slimstat::$wpdb->prepare(
                sprintf('UPDATE %sslim_stats SET visit_id = %%d WHERE id = %%d AND visit_id = 0', $GLOBALS['wpdb' ]->prefix),
                \wp_slimstat::$stat['visit_id'],
                $identifier
            ));
        }

        return ($is_new_session && ($_force_assign || 'on' == \wp_slimstat::$settings['javascript_mode']));
    }

    public static function _get_remote_ip()
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

    public static function _get_language()
    {
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            preg_match('/([^,;]*)/', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $array_languages);
            return str_replace('_', '-', strtolower($array_languages[0]));
        }

        return '';
    }

    public static function _get_search_terms($_url = '')
    {
        if (empty($_url)) {
            return '';
        }

        $searchterms = '';

        $search_engines = file_get_contents(SLIMSTAT_ANALYTICS_DIR . 'admin/assets/data/matomo-searchengine.json');
        $search_engines = json_decode($search_engines, true);

        $parsed_url = @parse_url($_url);

        if (empty($search_engines) || empty($parsed_url) || empty($parsed_url['host'])) {
            return '';
        }

        $sek = \wp_slimstat::get_lossy_url($parsed_url['host']);

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
            foreach (['ask', 'k', 'q', 'qs', 'qt', 'query', 's', 'string'] as $a_param) {
                $searchterms = self::_get_param_from_query_string($parsed_url['query'], $a_param);
                if (!empty($searchterms)) {
                    break;
                }
            }
        }

        return sanitize_text_field($searchterms);
    }

    public static function _get_param_from_query_string($_query = '', $_parameter = '')
    {
        if (empty($_query)) {
            return '';
        }

        @parse_str($_query, $values);

        return empty($values[$_parameter]) ? '' : $values[$_parameter];
    }

    public static function _get_content_info()
    {
        $content_info = ['content_type' => ''];

        if (is_404()) {
            $content_info['content_type'] = '404';
        } elseif (is_single()) {
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
        } elseif (is_admin() && (!defined('DOING_AJAX') || !DOING_AJAX)) {
            $content_info['content_type'] = 'admin';
        }

        if (is_paged()) {
            $content_info['content_type'] .= ':paged';
        }

        if (is_singular()) {
            $author = get_the_author_meta('user_login', $GLOBALS['post']->post_author);
            if (!empty($author)) {
                $content_info['author'] = $author;
            }
        }

        return $content_info;
    }

    public static function _get_client_info($_data_js = [], $_stat = [])
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

        if (!empty($_data_js['fh']) && 'on' != \wp_slimstat::$settings['anonymize_ip']) {
            $_stat['fingerprint'] = sanitize_text_field($_data_js['fh']);
        }

        if (!empty($_data_js['tz'])) {
            $_stat['tz_offset'] = intval($_data_js['tz']);
        }

        return $_stat;
    }

    public static function _log_error($_error_code = 0)
    {
        \wp_slimstat::update_option('slimstat_tracker_error', [$_error_code, \wp_slimstat::date_i18n('U')]);
        do_action('slimstat_track_exit_' . abs($_error_code), \wp_slimstat::$stat);
        return -$_error_code;
    }

    public static function _get_value_with_checksum($_value = 0)
    {
        return $_value . '.' . md5($_value . (\wp_slimstat::$settings['secret'] ?? ''));
    }

    public static function _get_value_without_checksum($_value_with_checksum = '')
    {
        [$value, $checksum] = explode('.', $_value_with_checksum);
        if ($checksum === md5($value . (\wp_slimstat::$settings['secret'] ?? ''))) {
            return $value;
        }
        
        return false;
    }

    public static function _is_blacklisted($_needles = [], $_haystack_string = '')
    {
        foreach (\wp_slimstat::string_to_array($_haystack_string) as $a_item) {
            $pattern = str_replace(['\\*', '\\!'], ['(.*)', '.'], preg_quote($a_item, '@'));
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

    public static function _is_new_visitor($_fingerprint = '')
    {
        if ('on' == (\wp_slimstat::$settings['hash_ip'] ?? 'off')) {
            return false;
        }
        
        if ('on' == \wp_slimstat::$settings['anonymize_ip']) {
            return false;
        }
        
        $table = $GLOBALS['wpdb']->prefix . 'slim_stats';
        $query = Query::select('COUNT(id) as cnt')->from($table)->where('fingerprint', '=', $_fingerprint);
        $today = date('Y-m-d');
        if (!empty(\wp_slimstat::$stat['dt']) && date('Y-m-d', \wp_slimstat::$stat['dt']) < $today) {
            $query->allowCaching(true);
        }
        
        $count_fingerprint = $query->getVar();
        return 0 == $count_fingerprint;
    }

    public static function _dtr_pton($_ip)
    {
        if (filter_var($_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $unpacked = unpack('A4', inet_pton($_ip));
        } elseif (filter_var($_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && defined('AF_INET6')) {
            $unpacked = unpack('A16', inet_pton($_ip));
        }

        $binary_ip = '';
        if ([] !== $unpacked && false !== $unpacked) {
            $unpacked = str_split($unpacked[1]);
            foreach ($unpacked as $char) {
                $binary_ip .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
            }
        }

        return $binary_ip;
    }

    public static function _get_mask_length($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 32;
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 128;
        }
        
        return false;
    }

    public static function _base64_url_encode($_input = '')
    {
        return strtr(base64_encode($_input), '+/=', '._-');
    }

    public static function _base64_url_decode($_input = '')
    {
        return strip_tags(trim(base64_decode(strtr($_input, '._-', '+/='))));
    }
}
