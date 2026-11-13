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
            'args'                => self::rangeArgs() + ['refresh' => ['type' => 'boolean', 'default' => false], 'q' => ['type' => 'string', 'maxLength' => 200, 'default' => ''], 'sort' => ['type' => 'string', 'enum' => ['page', 'clicks', 'pageviews', 'cpp', 'scroll', 'dead', 'last'], 'default' => 'clicks'], 'dir' => ['type' => 'integer', 'enum' => [-1, 1], 'default' => -1]],
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
            'fs'     => ['type' => 'string', 'default' => '', 'maxLength' => 8192],
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

    /** Return the authorized page list, preserving shared filters and Free result limits. */
    public function pages(\WP_REST_Request $request)
    {
        $range = self::range($request);
        if (is_wp_error($range)) {
            return $range;
        }
        if (!\wp_slimstat::pro_is_installed() && !empty($request['device'])) {
            return new \WP_Error('slimstat_heatmap_device_pro', __('Device insights are available with Pro.', 'wp-slimstat'), ['status' => 403]);
        }

        $filters = self::filters((string) $request['fs']);
        if (is_wp_error($filters)) { return $filters; }
        try {
            $data = Query::cachedPages($range['start'], $range['end'], (string) $request['device'], (bool) $request['refresh'], $filters['scope']);
            // Empty list: "never recorded a click" gets its own message.
            $ever = $data['rows'] || Query::anyClicks();
        } catch (\Throwable $e) {
            return new \WP_Error('slimstat_heatmap_read', $e->getMessage(), ['status' => 500]);
        }

        $pro = \wp_slimstat::pro_is_installed();
        $matches = $data['rows'];
        if (!$pro) {
            $q = (string) $request['q'];
            $matches = array_values(array_filter($matches, static function ($row) use ($q) { return '' === $q || false !== stripos($row['page'], $q); }));
            $sort = (string) ($request['sort'] ?: 'clicks');
            if (!in_array($sort, ['page', 'clicks', 'pageviews', 'cpp', 'last'], true)) { $sort = 'clicks'; }
            $dir = 1 === (int) $request['dir'] ? 1 : -1;
            $value = static function ($row) use ($sort) {
                if ('cpp' === $sort) { return $row['pageviews'] ? $row['clicks'] / $row['pageviews'] : 0; }
                return $row[$sort] ?? 0;
            };
            usort($matches, static function ($a, $b) use ($value, $dir) { return ($value($a) <=> $value($b)) * $dir ?: strcmp($a['page'], $b['page']); });
        }
        $total = count($matches);
        $data['rows'] = $pro ? $matches : array_slice($matches, 0, 2);
        $ids = array_filter(array_column($data['rows'], 'content_id'));
        if ($ids && function_exists('_prime_post_caches')) {
            _prime_post_caches($ids, false, false);
        }
        $format = (string) get_option('date_format');
        $link_filters = [];
        foreach ('' === (string) $request['fs'] ? [] : explode('&&&', (string) $request['fs']) as $filter) {
            $parts = preg_split('/\s+/', trim($filter), 3);
            $link_filters[$parts[0]] = $parts[1] . ' ' . rawurldecode($parts[2] ?? '');
        }
        $rows   = [];
        foreach ($data['rows'] as $row) {
            $url    = (string) apply_filters('slimstat_heatmap_row_url', '', $row['page']);
            $rows[] = [
                'page'      => $row['page'],
                'title'     => $row['content_id'] ? wp_strip_all_tags(html_entity_decode(get_the_title($row['content_id']), ENT_QUOTES, 'UTF-8')) : '',
                'clicks'    => $row['clicks'],
                'pageviews' => $row['pageviews'],
                'devices'   => $pro ? [$row['desktop'], $row['tablet'], $row['mobile']] : null,
                'scroll'    => $pro ? $row['scroll'] : null,
                'dead'      => $pro ? $row['dead'] : null,
                'rage'      => $pro ? $row['rage'] : null,
                'last'      => $row['last'],
                'lastText'  => \wp_slimstat::date_i18n($format, $row['last']),
                'full'      => $row['full'],
                // Pro's viewer URL for this page, on the list's range; empty in Free, where the row opens a preview of its clicks.
                'url'       => '' === $url ? '' : esc_url_raw(add_query_arg(['from' => $range['from'], 'to' => $range['to'], 'fs' => $link_filters], $url)),
            ];
        }

        return rest_ensure_response(['from' => $range['from'], 'to' => $range['to'], 'updated' => $data['updated'], 'rows' => $rows, 'total' => $total, 'locked' => !$pro, 'ever' => $ever, 'ignored_filters' => $filters['ignored']]);
    }

    /** Free's preview of a page's heatmap: its five most clicked links and buttons, by their text. */
    public function targets(\WP_REST_Request $request)
    {
        $range = self::range($request);
        if (is_wp_error($range)) {
            return $range;
        }
        if (!\wp_slimstat::pro_is_installed() && !empty($request['device'])) {
            return new \WP_Error('slimstat_heatmap_device_pro', __('Device insights are available with Pro.', 'wp-slimstat'), ['status' => 403]);
        }

        $filters = self::filters((string) $request['fs']);
        if (is_wp_error($filters)) { return $filters; }
        try {
            $points = Query::legacyPoints(Query::pageKey((string) $request['page']), (string) $request['device'], Query::db()->prepare('t1.dt BETWEEN %d AND %d', $range['start'], $range['end']) . ' AND (' . $filters['scope'] . ')');
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

    /** Validate before using the shared bound-query filter parser; disclose unsupported dimensions. */
    public static function filters(string $fs)
    {
        if ('' === $fs) { return ['scope' => '1=1', 'ignored' => []]; }
        if (!class_exists('wp_slimstat_db')) { require_once dirname(SLIMSTAT_FILE) . '/admin/view/wp-slimstat-db.php'; }
        $old = [\wp_slimstat_db::$filters_normalized, \wp_slimstat_db::$sql_where];
        try {
            \wp_slimstat_db::init('', false, false);
            $parts = explode('&&&', $fs);
            if (strlen($fs) > 8192 || count($parts) > 20) {
                return new \WP_Error('slimstat_heatmap_filters', __('Too many filters for Heatmaps.', 'wp-slimstat'), ['status' => 400]);
            }
            $supported = []; $ignored = [];
            foreach ($parts as $part) {
                $filter = preg_split('/\s+/', trim($part), 3);
                if (count($filter) < 2 || !preg_match('/\A[a-z_]+\z/', $filter[0]) || !isset(\wp_slimstat_db::$operator_names[$filter[1]])) {
                    return new \WP_Error('slimstat_heatmap_filters', __('The Heatmaps filter is invalid. Review the selected filters.', 'wp-slimstat'), ['status' => 400]);
                }
                if (!isset(\wp_slimstat_db::$columns_names[$filter[0]]) || false !== strpos($filter[0], 'addon_') || false !== strpos($filter[0], 'no_filter')) {
                    $ignored[] = $filter[0];
                } else {
                    $supported[] = $part;
                }
            }
            \wp_slimstat_db::init(implode('&&&', $supported), false, false);
            return ['scope' => \wp_slimstat_db::get_combined_where('', '', false, 't1'), 'ignored' => $ignored];
        } finally {
            [\wp_slimstat_db::$filters_normalized, \wp_slimstat_db::$sql_where] = $old;
        }
    }
}
