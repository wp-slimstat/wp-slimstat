<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Utils;

/** Fixed purchase destinations; report filters never become marketing parameters. */
final class ProLinks
{
    public static function url(string $campaign, string $content, bool $internal = false, string $anchor = ''): string
    {
        $args = ['utm_campaign' => sanitize_key($campaign), 'utm_content' => sanitize_key($content)];
        if (!$internal) {
            $args = ['utm_source' => 'wp-slimstat', 'utm_medium' => 'link'] + $args;
        }
        $url = add_query_arg($args, $internal ? admin_url('admin.php?page=slimpro') : 'https://wp-slimstat.com/pricing/');
        return $url . ('' !== $anchor ? '#' . sanitize_key($anchor) : '');
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
        if (!\wp_slimstat::pro_is_installed() && current_user_can('activate_plugins') && file_exists(WP_PLUGIN_DIR . '/wp-slimstat-pro/wp-slimstat-pro.php')) {
            return admin_url('plugins.php');
        }
        return '';
    }
}
