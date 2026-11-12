<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Utils;

/** Fixed purchase destinations; report filters never become marketing parameters. */
final class ProLinks
{
    public static function url(string $campaign, string $content, bool $internal = false, string $anchor = ''): string
    {
        $url = $internal
            ? add_query_arg(['utm_campaign' => sanitize_key($campaign), 'utm_content' => sanitize_key($content)], admin_url('admin.php?page=slimpro'))
            : self::tag('https://wp-slimstat.com/pricing/', $campaign, $content);
        return $url . ('' !== $anchor ? '#' . sanitize_key($anchor) : '');
    }

    /** Tag fixed destinations, or fill only missing attribution on website links supplied by a feed. */
    public static function tag(string $url, string $campaign, string $content, bool $preserveExisting = false): string
    {
        $params = ['utm_source' => 'wp-slimstat', 'utm_medium' => 'link', 'utm_campaign' => sanitize_key($campaign), 'utm_content' => sanitize_key($content)];
        if ($preserveExisting) {
            // Feed links may point to the customer's site or another service. Only tag our website.
            $parts = wp_parse_url($url);
            if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || !in_array(strtolower($parts['host'] ?? ''), ['wp-slimstat.com', 'www.wp-slimstat.com', 'my.wp-slimstat.com'], true)
                || isset($parts['user']) || isset($parts['pass'])) return $url;
            wp_parse_str($parts['query'] ?? '', $query);
            foreach ($params as $key => $value) {
                if (isset($query[$key]) && is_string($query[$key]) && '' !== trim($query[$key])) unset($params[$key]);
            }
        }
        return add_query_arg($params, $url);
    }

    /** Only an explicit publisher campaign associates independently numbered Horizon feeds. */
    public static function campaign(string $url): string
    {
        wp_parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        $campaign = $query['utm_campaign'] ?? '';
        return is_string($campaign) && strlen($campaign) <= 160 ? trim($campaign) : '';
    }

    /** Only the Pro information page forwards an origin supplied by an internal link. */
    public static function fromRequest(bool $checkout = false, string $placement = 'purchase'): string
    {
        $origin = [];
        foreach (['utm_campaign' => 'pro-overview', 'utm_content' => $placement] as $key => $fallback) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only link attribution, never a setting or redirect destination.
            $value = isset($_GET[$key]) && is_string($_GET[$key]) ? sanitize_key(wp_unslash($_GET[$key])) : '';
            $origin[] = '' !== $value && strlen($value) <= 80 ? $value : $fallback;
        }
        $url = self::url($origin[0], $origin[1]);
        return $checkout ? str_replace('https://wp-slimstat.com/pricing/?', 'https://my.wp-slimstat.com/checkout/wp-slimstat-pro?tier=1-site&', $url) : $url;
    }

    public static function activation(): string
    {
        if (!\wp_slimstat::pro_plugin_active() && current_user_can('activate_plugins') && file_exists(WP_PLUGIN_DIR . '/wp-slimstat-pro/wp-slimstat-pro.php')) {
            return admin_url('plugins.php');
        }
        return '';
    }
}
