<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Shortcodes;

use wp_slimstat;
use wp_slimstat_admin;
use wp_slimstat_db;
use wp_slimstat_reports;
use wp_slimstat_i18n;
use SlimStat\Reports\Types\Analytics\LiveAnalyticsReport;

if (!defined('ABSPATH')) { exit; }

/** The shortcode's SQL boundary, report catalog and rendering contract. */
final class Shortcode
{
    public static function allowed(string $w): bool
    {
        return in_array($w, ['*', 'count', 'display_name', 'hostname', 'post_link', 'post_link_no_qs', 'dt', 'username', 'ip', 'id', 'searchterms', 'resource', 'country', 'browser', 'platform', 'language', 'slim_p1_01', 'slim_p1_03', 'slim_p1_04', 'slim_p1_06', 'slim_p1_08', 'slim_p1_10', 'slim_p1_11', 'slim_p1_12', 'slim_p1_13', 'slim_p1_15', 'slim_p1_17', 'slim_p1_18', 'slim_p1_19_01', 'slim_p2_01', 'slim_p2_02', 'slim_p2_03', 'slim_p2_04', 'slim_p2_05', 'slim_p2_06', 'slim_p2_07', 'slim_p2_08', 'slim_p2_12', 'slim_p2_13', 'slim_p2_14', 'slim_p2_15', 'slim_p2_16', 'slim_p2_17', 'slim_p2_18', 'slim_p2_19', 'slim_p2_20', 'slim_p2_21', 'slim_p2_22_01', 'slim_p2_24', 'slim_p2_25', 'slim_p3_01', 'slim_p3_02', 'slim_p3_03', 'slim_p3_04', 'traffic_channel', 'traffic_source', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'slim_p4_01', 'slim_p4_02', 'slim_p4_04', 'slim_p4_05', 'slim_p4_06', 'slim_p4_07', 'slim_p4_09', 'slim_p4_10', 'slim_p4_11', 'slim_p4_12', 'slim_p4_13', 'slim_p4_15', 'slim_p4_16', 'slim_p4_18', 'slim_p4_19', 'slim_p4_20', 'slim_p4_21', 'slim_p4_22', 'slim_p4_23', 'slim_p4_24', 'slim_p4_25', 'slim_p4_26_01', 'slim_p4_27', 'slim_p6_01', 'slim_p9_01', 'slim_p9_02', 'slim_p2_23', 'other_ip', 'email', 'fingerprint', 'user_agent', 'visit_id', 'slim_p7_02', 'slim_p8_01', 'slim_p8_02', 'slim_heatmap_01', 'slim_p10_01', 'users', 'pages', 'countries'], true);
    }

    public static function canView(): bool
    {
        return class_exists('wp_slimstat_admin', false) && wp_slimstat_admin::can_view_stats();
    }

    /** Return a normalized request or an attribute-specific error, before any SQL. */
    public static function attributes($atts)
    {
        $atts = shortcode_atts(['f' => '', 'w' => '', 's' => ' ', 'o' => 0], is_array($atts) ? $atts : [], 'slimstat');
        if (!is_string($atts['f']) || !in_array($atts['f'], ['count', 'count-all', 'recent', 'recent-all', 'top', 'top-all', 'widget', 'live'], true)) {
            return new \WP_Error('slimstat_shortcode_f', __('Invalid shortcode attribute: f.', 'wp-slimstat'), ['status' => 400]);
        }
        $columns = is_string($atts['w']) ? array_map('trim', explode(',', $atts['w'])) : [];
        if (!$columns) { $columns = ['']; }
        foreach ($columns as $w) {
            if (!self::allowed($w)) { return self::invalidColumn(); }
            $report = 0 === strpos($w, 'slim_');
            $live = in_array($w, ['users', 'pages', 'countries'], true);
            if (('widget' === $atts['f']) !== $report || ('live' === $atts['f']) !== $live) { return self::invalidColumn(); }
        }
        if (in_array($atts['f'], ['widget', 'live', 'count', 'count-all'], true) && count($columns) !== 1) { return self::invalidColumn(); }
        $atts['columns'] = $columns;
        $atts['w'] = implode(',', $columns);
        $atts['o'] = is_scalar($atts['o']) ? (int) $atts['o'] : 0;
        $atts['s'] = wp_kses_post(is_string($atts['s']) ? $atts['s'] : '');
        return $atts;
    }

    private static function invalidColumn(): \WP_Error
    {
        return new \WP_Error('slimstat_shortcode_w', __('Invalid shortcode attribute: w.', 'wp-slimstat'), ['status' => 400]);
    }

    private static function init(): void
    {
        require_once dirname(__DIR__, 2) . '/admin/view/wp-slimstat-reports.php';
        wp_slimstat_reports::init(false);
    }

    /** All entries pass the same literal allowlist as rendered shortcodes. */
    public static function catalog(): array
    {
        self::init();
        $groups = [__('Real-time', 'wp-slimstat'), __('Traffic', 'wp-slimstat'), __('UTM & Channels', 'wp-slimstat'), __('Content', 'wp-slimstat'), __('Audience', 'wp-slimstat'), __('Ecommerce', 'wp-slimstat'), __('Goals & Funnels', 'wp-slimstat'), __('Heatmaps', 'wp-slimstat'), __('Visitors', 'wp-slimstat')];
        $labels = [
            'users' => __('Visitors online now', 'wp-slimstat'), 'pages' => __('Pages live now', 'wp-slimstat'), 'countries' => __('Countries live now', 'wp-slimstat'),
            'id' => __('Pageview count', 'wp-slimstat'), 'visit_id' => __('Visitor count', 'wp-slimstat'), '*' => __('Pageviews', 'wp-slimstat'), 'count' => __('Count', 'wp-slimstat'),
            'post_link' => __('Page links', 'wp-slimstat'), 'post_link_no_qs' => __('Page links without query strings', 'wp-slimstat'), 'display_name' => __('Display names', 'wp-slimstat'), 'hostname' => __('Hostnames', 'wp-slimstat'),
            'slim_heatmap_01' => __('Heatmap insights', 'wp-slimstat'), 'slim_p10_01' => __('Ecommerce KPIs', 'wp-slimstat'), 'slim_p9_02' => __('Funnels', 'wp-slimstat'),
            'slim_p8_01' => __('User Overview', 'wp-slimstat'), 'slim_p8_02' => __('Pages by User', 'wp-slimstat'),
        ];
        foreach (wp_slimstat_db::$all_columns_names as $id => $info) { $labels[$id] = $labels[$id] ?? $info[0]; }
        foreach (wp_slimstat_reports::$reports as $id => $info) { $labels[$id] = $labels[$id] ?? $info['title']; }
        $personal = ['ip', 'other_ip', 'hostname', 'username', 'display_name', 'email', 'fingerprint', 'user_agent'];
        $staffReports = ['slim_p7_02', 'slim_p1_04', 'slim_p1_11', 'slim_p1_18', 'slim_p2_20', 'slim_p2_21', 'slim_p4_27', 'slim_p6_01', 'slim_p8_01', 'slim_p8_02'];
        $pro = ['slim_p9_02', 'slim_heatmap_01', 'slim_p8_01', 'slim_p8_02'];
        $catalog = [];
        $order = [];
        foreach ($labels as $id => $label) {
            if (!self::allowed($id)) { continue; }
            $report = 0 === strpos($id, 'slim_');
            $live = in_array($id, ['users', 'pages', 'countries'], true);
            $args = wp_slimstat_reports::$reports[$id]['callback_args'] ?? [];
            $staff = in_array($id, $personal, true) || in_array($id, $staffReports, true) || 'slim_p10_01' === $id;
            // A registered report may expose a personal column even when its title sounds aggregate.
            $reportColumns = array_map('trim', explode(',', (string) ($args['columns'] ?? '')));
            $staff = $staff || (bool) array_intersect($personal, $reportColumns) || (bool) array_intersect(['*', 'id'], $reportColumns);
            $group = $live ? 0 : (0 === strpos($id, 'utm_') || 0 === strpos($id, 'traffic_') || 0 === strpos($id, 'slim_p3_') ? 2 : (0 === strpos($id, 'slim_p4_') || in_array($id, ['resource', 'post_link', 'post_link_no_qs', 'searchterms'], true) ? 3 : (0 === strpos($id, 'slim_p2_') || in_array($id, ['country', 'browser', 'platform', 'language'], true) ? 4 : 1)));
            if ($staff) { $group = 8; }
            if ('slim_p10_01' === $id) { $group = 5; }
            if (0 === strpos($id, 'slim_p9_')) { $group = 6; }
            if ('slim_heatmap_01' === $id) { $group = 7; }
            /* translators: %s: report or column name. */
            $description = sprintf(__('Show %s for your selected period and filters.', 'wp-slimstat'), wp_strip_all_tags($label));
            if ($live) { $description = __('Activity on your site in the last 30 minutes, refreshed each minute.', 'wp-slimstat'); }
            $catalog[$id] = ['id' => $id, 'label' => wp_strip_all_tags($label), 'description' => $description, 'group' => $groups[$group],
                'modes' => $live ? ['live'] : ($report ? ['widget'] : ['count', 'top', 'recent', 'count-all', 'top-all', 'recent-all']),
                'tier' => in_array($id, $pro, true) ? 'pro' : 'free', 'privacy' => $staff ? 'staff' : 'public',
                'available' => !$report || isset(wp_slimstat_reports::$reports[$id]),
            ];
            $order[$id] = $group;
        }
        $catalog['slim_heatmap_01']['benefit'] = __('Pro adds heatmaps that show where visitors click, rage-click and stop scrolling on each page.', 'wp-slimstat');
        $catalog['slim_heatmap_01']['sample'] = [['resource' => '/pricing/', 'addon_hm_clicks' => 124, 'addon_hm_pageviews' => 210, 'addon_hm_dead' => 8, 'addon_hm_rage' => 3, 'addon_hm_scroll' => 64]];
        $catalog['slim_p9_02']['benefit'] = __('Pro adds funnels that show the step where visitors leave before they convert.', 'wp-slimstat');
        $catalog['slim_p9_02']['sample'] = [['metric' => __('Landing page', 'wp-slimstat'), 'value' => 120], ['metric' => __('Checkout', 'wp-slimstat'), 'value' => 42]];
        foreach (['slim_p8_01', 'slim_p8_02'] as $id) {
            $catalog[$id]['benefit'] = __('Pro adds a per-user overview of visits and pages, visible only to your analytics team.', 'wp-slimstat');
            $catalog[$id]['sample'] = [['username' => __('Sample visitor', 'wp-slimstat'), 'resource' => '/about/', 'count' => 3]];
        }
        $hints = ['slim_p9_01' => __('Pro adds funnels: see which step visitors leave.', 'wp-slimstat'), 'resource' => __('Pro adds heatmaps for these pages.', 'wp-slimstat'), 'post_link' => __('Pro adds heatmaps for these pages.', 'wp-slimstat'), 'slim_p4_02' => __('Pro adds heatmaps for these pages.', 'wp-slimstat'), 'slim_p2_20' => __('Pro adds a per-user overview.', 'wp-slimstat'), 'slim_p2_21' => __('Pro adds a per-user overview.', 'wp-slimstat')];
        foreach ($hints as $id => $hint) { if (isset($catalog[$id])) { $catalog[$id]['hint'] = $hint; } }
        uksort($catalog, static function ($a, $b) use ($order) { return $order[$a] <=> $order[$b]; });
        return $catalog;
    }

    public static function pricingUrl(string $touchpoint): string
    {
        return add_query_arg(['utm_source' => 'wp-slimstat', 'utm_medium' => 'link', 'utm_campaign' => 'shortcodes', 'utm_content' => $touchpoint], 'https://wp-slimstat.com/pricing/');
    }

    private static function comment(string $message): string
    {
        return self::canView() ? '<!-- ' . esc_html(str_replace('--', '', $message)) . ' -->' : '';
    }

    /** Both raw reports and samples use this escaped, theme-friendly table. */
    public static function table(array $rows): string
    {
        if (!$rows) { return ''; }
        $labels = class_exists('wp_slimstat_db', false) ? wp_slimstat_db::$all_columns_names : [];
        $labels += ['metric' => [__('Metric', 'wp-slimstat')], 'value' => [__('Value', 'wp-slimstat')], 'count' => [__('Pageviews', 'wp-slimstat')], 'addon_hm_clicks' => [__('Clicks', 'wp-slimstat')], 'addon_hm_pageviews' => [__('Pageviews', 'wp-slimstat')], 'addon_hm_dead' => [__('Dead clicks', 'wp-slimstat')], 'addon_hm_rage' => [__('Rage clicks', 'wp-slimstat')], 'addon_hm_scroll' => [__('Average scroll depth (%)', 'wp-slimstat')]];
        $keys = array_keys(reset($rows));
        $html = '<table class="slimstat-shortcode"><thead><tr>';
        foreach ($keys as $key) { $html .= '<th scope="col">' . esc_html($labels[$key][0] ?? $key) . '</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($keys as $key) {
                $value = $row[$key] ?? '';
                $html .= '<td>' . esc_html(is_int($value) || is_float($value) ? number_format_i18n($value) : (is_scalar($value) ? (string) $value : '')) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    public static function render($atts = [], $content = ''): string
    {
        $atts = self::attributes($atts);
        if (is_wp_error($atts)) { return self::comment($atts->get_error_message()); }
        require_once dirname(__DIR__, 2) . '/admin/view/wp-slimstat-db.php';
        // Catalog initialization and report callbacks both mutate shared report state.
        $filters = wp_slimstat_db::$filters_normalized;
        $where = wp_slimstat_db::$sql_where;
        $pageviews = wp_slimstat_db::$pageviews;
        $agentTooltip = wp_slimstat::$settings['show_complete_user_agent_tooltip'] ?? 'off';
        wp_slimstat::$settings['show_complete_user_agent_tooltip'] = 'off';
        try {
            $catalog = self::catalog();
            foreach ($atts['columns'] as $column) {
                if (!isset($catalog[$column])) { return self::comment(__('Invalid shortcode attribute: w.', 'wp-slimstat')); }
                if ('staff' === $catalog[$column]['privacy'] && !in_array($atts['f'], ['count', 'count-all'], true) && !self::canView()) { return ''; }
            }
            $w = $atts['columns'][0];
            $item = $catalog[$w];
            if ('pro' === $item['tier'] && !wp_slimstat::pro_is_installed()) {
                if (!self::canView()) { return ''; }
                self::style();
                return '<aside class="slimstat-shortcode-notice">' . esc_html__("This SlimStat shortcode needs SlimStat Pro. Visitors don't see this note.", 'wp-slimstat') . ' <a target="_blank" rel="noopener" href="' . esc_url(self::pricingUrl('frontend')) . '">' . esc_html__('Learn about Pro', 'wp-slimstat') . '</a></aside>';
            }
            if (!$item['available']) {
                return self::comment('pro' === $item['tier'] ? __('Update SlimStat Pro to use this shortcode.', 'wp-slimstat') : __('This report is unavailable. Activate WooCommerce and set up reporting for Ecommerce.', 'wp-slimstat'));
            }
            if ('live' === $atts['f']) {
                $key = 'slimstat_shortcode_live_' . get_current_blog_id() . '_' . wp_slimstat::report_scope()['cache'];
                $counts = get_transient($key);
                if (false === $counts) {
                    $counts = (new LiveAnalyticsReport())->get_all_live_counts();
                    set_transient($key, $counts, max(60 - (wp_slimstat::now() % 60), 1));
                }
                return esc_html(number_format_i18n($counts[$w]));
            }
            wp_slimstat_db::init(html_entity_decode((string) $content, ENT_QUOTES, 'UTF-8'), false);
            return self::output($atts);
        } catch (\Throwable $e) {
            return self::comment(__('This report could not load. Check reporting setup and retry.', 'wp-slimstat'));
        } finally {
            wp_slimstat_db::$filters_normalized = $filters;
            wp_slimstat_db::$sql_where = $where;
            wp_slimstat_db::$pageviews = $pageviews;
            wp_slimstat::$settings['show_complete_user_agent_tooltip'] = $agentTooltip;
        }
    }

    public static function style(): void
    {
        wp_register_style('wp-slimstat-frontend', plugins_url('admin/assets/css/slimstat.css', dirname(__DIR__, 2) . '/wp-slimstat.php'), [], SLIMSTAT_ANALYTICS_VERSION);
        wp_enqueue_style('wp-slimstat-frontend');
    }

    private static function output(array $atts): string
    {
        $f = $atts['f'];
        $columns = $atts['columns'];
        $w = $columns[0];
        if ('widget' === $f) {
            self::style();
            $report = wp_slimstat_reports::$reports[$w];
            $args = ($report['callback_args'] ?? []);
            $args['is_widget'] = true;
            if (in_array($w, ['slim_heatmap_01', 'slim_p10_01'], true)) {
                $raw = $args['raw'] ?? null;
                return is_callable($raw) ? self::table(call_user_func($raw, $args)) : self::comment(__('Update SlimStat Pro to use this shortcode.', 'wp-slimstat'));
            }
            if (!is_callable($report['callback'] ?? null)) { return self::comment(__('This report is unavailable.', 'wp-slimstat')); }
            ob_start();
            try {
                wp_slimstat_reports::report_header($w);
                call_user_func($report['callback'], $args);
                wp_slimstat_reports::report_footer();
                return (string) ob_get_contents();
            } finally { ob_end_clean(); }
        }
        $map = ['*' => 'id', 'count' => 'id', 'display_name' => 'username', 'hostname' => 'ip', 'post_link' => 'resource', 'post_link_no_qs' => 'resource'];
        if (in_array($f, ['count', 'count-all'], true)) {
            return esc_html(number_format_i18n((int) wp_slimstat_db::count_records($map[$w] ?? $w, '', 'count' === $f) + $atts['o']));
        }
        $queryColumns = array_values(array_unique(array_map(static function ($column) use ($map) { return $map[$column] ?? $column; }, array_diff($columns, ['count']))));
        if (!$queryColumns) { $queryColumns = ['id']; }
        $function = 'get_' . str_replace('-all', '', $f);
        $query = ['columns' => implode(', ', $queryColumns), 'use_date_filters' => false === strpos($f, '-all')];
        if (in_array('post_link_no_qs', $columns, true) && !array_intersect(['resource', 'post_link'], $columns)) {
            // Group before LIMIT so tracking parameters do not split a popular page into rows.
            $expression = "SUBSTRING_INDEX(resource, '" . (get_option('permalink_structure') ? '?' : '&') . "', 1)";
            $queryColumns = array_map(static function ($column) use ($expression, $function) {
                return 'resource' === $column ? $expression . ('get_recent' === $function ? ' AS resource' : '') : $column;
            }, $queryColumns);
            $query['columns'] = implode(', ', $queryColumns);
            if ('get_top' === $function) { $query['more_select'] = $expression . ' AS resource'; }
        }
        $results = wp_slimstat_db::$function($query);
        if (!$results) { return ''; }
        $rows = [];
        foreach ($results as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $value = $row[$map[$column] ?? $column] ?? '';
                switch ($column) {
                    case 'count': $value = isset($row['counthits']) ? number_format_i18n($row['counthits']) : ''; break;
                    case 'display_name': $user = get_user_by('login', $row['username'] ?? ''); $value = $user ? $user->display_name : ($row['username'] ?? ''); break;
                    case 'hostname': $value = self::canView() ? wp_slimstat::gethostbyaddr($row['ip'] ?? '') : ''; break;
                    case 'country': $value = wp_slimstat_i18n::get_string('c-' . $value); break;
                    case 'language': $value = wp_slimstat_i18n::get_string('l-' . $value); break;
                    case 'platform': $value = wp_slimstat_i18n::get_string($value); break;
                    case 'dt': $value = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) ($row['dt'] ?? 0)); break;
                }
                // Acquisition tags are literal bytes: preserve encoded entities as text too.
                $text = 0 === strpos($column, 'utm_') || 'traffic_source' === $column ? htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : esc_html((string) $value);
                if (in_array($column, ['post_link', 'post_link_no_qs'], true)) {
                    $url = (string) $value;
                    if ('post_link_no_qs' === $column) { $url = explode(get_option('permalink_structure') ? '?' : '&', $url, 2)[0]; }
                    $id = url_to_postid($url);
                    $text = '<a href="' . esc_url($url) . '">' . esc_html($id ? get_the_title($id) : $url) . '</a>';
                }
                $cells[] = '<span class="col-' . esc_attr($column) . '">' . $text . '</span>';
            }
            $rows[] = '<li>' . implode('<span class="slimstat-item-separator">' . $atts['s'] . '</span>', $cells) . '</li>';
        }
        return '<ul class="slimstat-shortcode ' . esc_attr($f . ' ' . $f . implode('-', $columns)) . '">' . implode('', $rows) . '</ul>';
    }
}
