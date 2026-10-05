<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Controllers\Rest;

use SlimStat\Interfaces\RestControllerInterface;
use SlimStat\Shortcodes\Shortcode;

if (!defined('ABSPATH')) { exit; }

final class ShortcodeRestController implements RestControllerInterface
{
    public function register_routes(): void
    {
        register_rest_route('slimstat/v1', '/shortcode/preview', [
            'methods' => 'POST',
            'callback' => [$this, 'preview'],
            'permission_callback' => [Shortcode::class, 'canView'],
            'args' => ['shortcode' => ['type' => 'string', 'required' => true, 'maxLength' => 2048]],
        ]);
    }

    public function preview(\WP_REST_Request $request)
    {
        $code = $request['shortcode'];
        if (!is_string($code) || strlen($code) > 2048 || !preg_match('/\A' . get_shortcode_regex(['slimstat']) . '\z/s', trim($code), $match)
            || $match[1] || $match[6] || preg_match('/[<>\[\]]/', $match[5])) {
            return new \WP_Error('slimstat_shortcode_syntax', __('Enter one [slimstat] shortcode, without other markup, up to 2 KB.', 'wp-slimstat'), ['status' => 400]);
        }
        $atts = shortcode_parse_atts($match[3]);
        $normalized = Shortcode::attributes($atts);
        if (is_wp_error($normalized)) { return $normalized; }
        try {
            $catalog = Shortcode::catalog();
            $item = $catalog[$normalized['columns'][0]] ?? null;
            if (!$item) { return new \WP_Error('slimstat_shortcode_w', __('Invalid shortcode attribute: w.', 'wp-slimstat'), ['status' => 400]); }
            $staff = false;
            foreach ($normalized['columns'] as $column) { $staff = $staff || 'staff' === ($catalog[$column]['privacy'] ?? 'staff'); }
            $sample = 'pro' === $item['tier'] && !\wp_slimstat::pro_is_installed();
            return rest_ensure_response([
                'html' => $sample ? Shortcode::table($item['sample'] ?? []) : Shortcode::render($atts, $match[5]),
                'sample' => $sample, 'staff_only' => $staff,
            ]);
        } catch (\Throwable $e) {
            return new \WP_Error('slimstat_shortcode_preview', __('The preview could not load its report data. Check reporting setup and your database connection, then retry.', 'wp-slimstat'), ['status' => 500]);
        }
    }
}
