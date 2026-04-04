<?php
/**
 * WAF Detection Service
 *
 * Probes the REST API settings endpoint to detect if a server-level WAF
 * (ModSecurity, LiteSpeed, Cloudflare, etc.) is blocking requests.
 *
 * @package   SlimStat\Services
 * @since     5.4.10
 * @see       https://github.com/wp-slimstat/wp-slimstat/issues/285
 */

declare(strict_types=1);

namespace SlimStat\Services;

if (!defined('ABSPATH')) {
    exit;
}

class WafDetectionService
{
    private const TRANSIENT_KEY = 'slimstat_waf_probe';
    private const CACHE_TTL     = DAY_IN_SECONDS;

    /**
     * Probe the REST API to detect WAF blocking.
     *
     * Sends a POST with "suspicious" test content to the settings-probe endpoint.
     * If the server returns 403/406/503, a WAF is likely blocking requests.
     *
     * Results are cached in a transient for 24 hours.
     *
     * @param bool $force_refresh Skip transient cache.
     * @return array{blocked: bool, waf: string}
     */
    public static function probe(bool $force_refresh = false): array
    {
        if (!$force_refresh) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = ['blocked' => false, 'waf' => 'unknown'];

        $probe_url = rest_url('slimstat/v1/settings-probe');
        if (empty($probe_url)) {
            return $result;
        }

        $response = wp_remote_post($probe_url, [
            'body'      => wp_json_encode(['test' => '<script>alert(1)</script> SELECT * FROM']),
            'headers'   => [
                'Content-Type' => 'application/json',
                'X-WP-Nonce'   => wp_create_nonce('wp_rest'),
            ],
            'timeout'   => 10,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'cookies'   => wp_unslash($_COOKIE),
        ]);

        if (is_wp_error($response)) {
            // Network error — can't determine WAF status, don't cache failure
            return $result;
        }

        $code   = wp_remote_retrieve_response_code($response);
        $server = wp_remote_retrieve_header($response, 'server');
        $body   = wp_remote_retrieve_body($response);

        if (in_array($code, [403, 406, 503], true)) {
            $result['blocked'] = true;

            // Detect specific WAF from response
            if (is_string($server) && stripos($server, 'LiteSpeed') !== false) {
                $result['waf'] = 'litespeed';
            } elseif (is_string($server) && stripos($server, 'cloudflare') !== false) {
                $result['waf'] = 'cloudflare';
            } elseif (wp_remote_retrieve_header($response, 'x-sucuri-id')) {
                $result['waf'] = 'sucuri';
            } elseif (is_string($server) && stripos($server, 'imunify360') !== false) {
                $result['waf'] = 'imunify360';
            } elseif (is_string($body) && (stripos($body, 'ModSecurity') !== false || stripos($body, 'Mod_Security') !== false)) {
                $result['waf'] = 'modsecurity';
            } elseif (is_string($body) && stripos($body, 'Wordfence') !== false) {
                $result['waf'] = 'wordfence';
            }
        }

        set_transient(self::TRANSIENT_KEY, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Clear the cached probe result.
     */
    public static function clear_cache(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }
}
