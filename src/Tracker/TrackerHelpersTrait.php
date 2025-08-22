<?php

namespace SlimStat\Tracker;

use SlimStat\Utils\Query;

trait TrackerHelpersTrait
{
    protected static function _log_error($_error_code = 0)
    {
        self::update_option('slimstat_tracker_error', [$_error_code, self::date_i18n('U')]);
        do_action('slimstat_track_exit_' . abs($_error_code), self::$stat);
        return -$_error_code;
    }

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

    protected static function _is_blacklisted($_needles = [], $_haystack_string = '')
    {
        foreach (self::string_to_array($_haystack_string) as $a_item) {
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

    protected static function _is_new_visitor($_fingerprint = '')
    {
        if ('on' == (self::$settings['hash_ip'] ?? 'off')) {
            return false;
        }

        if ('on' == self::$settings['anonymize_ip']) {
            return false;
        }

        $table = $GLOBALS['wpdb']->prefix . 'slim_stats';
        $query = Query::select('COUNT(id) as cnt')->from($table)->where('fingerprint', '=', $_fingerprint);
        $today = date('Y-m-d');
        if (!empty(self::$stat['dt']) && date('Y-m-d', self::$stat['dt']) < $today) {
            $query->allowCaching(true);
        }

        $count_fingerprint = $query->getVar();
        return 0 == $count_fingerprint;
    }

    protected static function _dtr_pton($_ip)
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

    protected static function _get_mask_length($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 32;
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 128;
        }

        return false;
    }

    protected static function _base64_url_encode($_input = '')
    {
        return strtr(base64_encode($_input), '+/=', '._-');
    }

    protected static function _base64_url_decode($_input = '')
    {
        return strip_tags(trim(base64_decode(strtr($_input, '._-', '+/='))));
    }
}
