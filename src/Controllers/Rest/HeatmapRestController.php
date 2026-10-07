<?php
/** @license GPL-2.0-or-later */

declare(strict_types=1);

namespace SlimStat\Controllers\Rest;

use SlimStat\Heatmap\Query;
use SlimStat\Interfaces\RestControllerInterface;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GET /slimstat/v1/heatmap/pages: the Heatmaps page list, for anyone who may view reports.
 * GET /slimstat/v1/heatmap/targets: one page's most clicked links and buttons.
 *
 * @since 6.1.0
 */
class HeatmapRestController implements RestControllerInterface
{
    public function register_routes(): void
    {
        register_rest_route('slimstat/v1', '/heatmap/pages', [
            'methods'             => 'GET',
            'callback'            => [$this, 'pages'],
            'permission_callback' => [self::class, 'canView'],
            'args'                => self::rangeArgs() + ['refresh' => ['type' => 'boolean', 'default' => false]],
        ]);
        register_rest_route('slimstat/v1', '/heatmap/targets', [
            'methods'             => 'GET',
            'callback'            => [$this, 'targets'],
            'permission_callback' => [self::class, 'canView'],
            'args'                => self::rangeArgs() + ['page' => ['type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 2048, 'pattern' => '^/']],
        ]);
    }

    /** Date range and device arguments, shared with Pro's page viewer route. */
    public static function rangeArgs(): array
    {
        $date = ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'];
        return [
            'from'   => $date,
            'to'     => $date,
            'days'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3660, 'default' => 30],
            'device' => ['type' => 'string', 'enum' => ['', 'desktop', 'tablet', 'mobile'], 'default' => ''],
        ];
    }

    /**
     * dt holds local wall-clock seconds, so day bounds are taken in UTC.
     * A custom range sends from and to; a preset sends days, ending today.
     *
     * @return array{from:string,to:string,start:int,end:int}|\WP_Error
     */
    public static function range(\WP_REST_Request $request)
    {
        $to    = (string) ($request['to'] ?: gmdate('Y-m-d', \wp_slimstat::now()));
        $from  = (string) ($request['from'] ?: gmdate('Y-m-d', strtotime($to . ' UTC') - ((int) $request['days'] - 1) * DAY_IN_SECONDS));
        $start = strtotime($from . ' 00:00:00 UTC');
        $end   = strtotime($to . ' 23:59:59 UTC');
        if (false === $start || false === $end || $start > $end) {
            return new \WP_Error('slimstat_heatmap_range', __('Choose a start date on or before the end date.', 'wp-slimstat'), ['status' => 400]);
        }
        return ['from' => $from, 'to' => $to, 'start' => $start, 'end' => $end];
    }

    /** The same gate as every SlimStat report screen. */
    public static function canView(): bool
    {
        return class_exists('wp_slimstat_admin', false) && \wp_slimstat_admin::can_view_stats();
    }

    public function pages(\WP_REST_Request $request)
    {
        $range = self::range($request);
        if (is_wp_error($range)) {
            return $range;
        }

        try {
            $data = Query::cachedPages($range['start'], $range['end'], (string) $request['device'], (bool) $request['refresh']);
            // Empty list: "never recorded a click" gets its own message.
            $ever = $data['rows'] || Query::anyClicks();
        } catch (\Throwable $e) {
            return new \WP_Error('slimstat_heatmap_read', $e->getMessage(), ['status' => 500]);
        }

        $ids = array_filter(array_column($data['rows'], 'content_id'));
        if ($ids && function_exists('_prime_post_caches')) {
            _prime_post_caches($ids, false, false);
        }
        $format = (string) get_option('date_format');
        $rows   = [];
        foreach ($data['rows'] as $row) {
            $url    = (string) apply_filters('slimstat_heatmap_row_url', '', $row['page']);
            $rows[] = [
                'page'      => $row['page'],
                'title'     => $row['content_id'] ? wp_strip_all_tags(get_the_title($row['content_id'])) : '',
                'clicks'    => $row['clicks'],
                'pageviews' => $row['pageviews'],
                'devices'   => [$row['desktop'], $row['tablet'], $row['mobile']],
                'scroll'    => $row['scroll'],
                'dead'      => $row['dead'],
                'rage'      => $row['rage'],
                'last'      => $row['last'],
                'lastText'  => \wp_slimstat::date_i18n($format, $row['last']),
                'full'      => $row['full'],
                // Pro's viewer URL for this page, on the list's range; empty in Free, where the row opens a preview of its clicks.
                'url'       => '' === $url ? '' : esc_url_raw(add_query_arg(['from' => $range['from'], 'to' => $range['to']], $url)),
            ];
        }

        return rest_ensure_response(['from' => $range['from'], 'to' => $range['to'], 'updated' => $data['updated'], 'rows' => $rows, 'ever' => $ever]);
    }

    /** Free's preview of a page's heatmap: its five most clicked links and buttons, by their text. */
    public function targets(\WP_REST_Request $request)
    {
        $range = self::range($request);
        if (is_wp_error($range)) {
            return $range;
        }

        try {
            $points = Query::legacyPoints(Query::pageKey((string) $request['page']), (string) $request['device'], Query::db()->prepare('t1.dt BETWEEN %d AND %d', $range['start'], $range['end']));
        } catch (\Throwable $e) {
            return new \WP_Error('slimstat_heatmap_read', $e->getMessage(), ['status' => 500]);
        }

        // One element clicked at several spots is one target. No text and no id: '' (the browser names it).
        $clicks = [];
        foreach ($points as $point) {
            $label          = '' !== $point['text'] ? $point['text'] : ('' !== $point['id'] ? '#' . $point['id'] : '');
            $clicks[$label] = ($clicks[$label] ?? 0) + $point['n'];
        }
        arsort($clicks);
        $targets = [];
        foreach (array_slice($clicks, 0, 5, true) as $label => $n) {
            $targets[] = ['label' => (string) $label, 'clicks' => $n];
        }
        return rest_ensure_response($targets);
    }
}
