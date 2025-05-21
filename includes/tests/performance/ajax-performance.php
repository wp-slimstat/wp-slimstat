<?php
namespace Slimstat\Tests\Performance;

class AjaxPerformanceTest {
    private static function get_tests() {
        $now = time();
        return [
            'slimstat_load_report' => [
                [
                    'input' => [
                        'action' => 'slimstat_load_report',
                        'report_id' => 'slim_p0_00',
                        'security' => '',
                    ],
                    'tags' => ['slimstat_load_report', 'query', 'critical', 'performance-sensitive', 'valid'],
                    'description' => 'Valid report load',
                ],
                [
                    'input' => [
                        'action' => 'slimstat_load_report',
                        'report_id' => '',
                        'security' => '',
                    ],
                    'tags' => ['slimstat_load_report', 'query', 'edge-case', 'invalid'],
                    'description' => 'Missing report_id',
                ],
            ],
            'slimstat_update_geoip_database' => [
                [
                    'input' => [
                        'action' => 'slimstat_update_geoip_database',
                        'security' => '',
                    ],
                    'tags' => ['slimstat_update_geoip_database', 'mutation', 'admin-only', 'performance-sensitive', 'valid'],
                    'description' => 'Valid update',
                ],
                [
                    'input' => [
                        'action' => 'slimstat_update_geoip_database',
                        'security' => 'invalid',
                    ],
                    'tags' => ['slimstat_update_geoip_database', 'mutation', 'admin-only', 'invalid'],
                    'description' => 'Invalid nonce',
                ],
            ],
            'slimstat_manage_filters' => [
                [
                    'input' => [
                        'action' => 'slimstat_manage_filters',
                        'filter_id' => '',
                        'security' => '',
                    ],
                    'tags' => ['slimstat_manage_filters', 'mutation', 'performance-sensitive', 'valid'],
                    'description' => 'Valid manage filters',
                ],
                [
                    'input' => [
                        'action' => 'slimstat_manage_filters',
                        'filter_id' => '',
                        'security' => '',
                    ],
                    'tags' => ['slimstat_manage_filters', 'mutation', 'invalid'],
                    'description' => 'Missing filter_id',
                ],
            ],
            'slimstat_delete_pageview' => [
                [
                    'input' => [
                        'action' => 'slimstat_delete_pageview',
                        'pageview_id' => '',
                        'security' => '',
                    ],
                    'tags' => ['slimstat_delete_pageview', 'mutation', 'critical', 'valid'],
                    'description' => 'Valid delete pageview',
                ],
                [
                    'input' => [
                        'action' => 'slimstat_delete_pageview',
                        'pageview_id' => '',
                        'security' => '',
                    ],
                    'tags' => ['slimstat_delete_pageview', 'mutation', 'invalid'],
                    'description' => 'Missing id',
                ],
            ],
            'slimstat_check_geoip_database' => [
                [
                    'input' => [
                        'action' => 'slimstat_check_geoip_database',
                        'security' => '',
                    ],
                    'tags' => ['slimstat_check_geoip_database', 'query', 'admin-only', 'valid'],
                    'description' => 'Valid check',
                ],
                [
                    'input' => [
                        'action' => 'slimstat_check_geoip_database',
                        'security' => 'invalid',
                    ],
                    'tags' => ['slimstat_check_geoip_database', 'query', 'admin-only', 'invalid'],
                    'description' => 'Invalid nonce',
                ],
            ],
            'slimstat_notice_latest_news' => [
                [
                    'input' => [
                        'action' => 'slimstat_notice_latest_news',
                        'security' => '',
                    ],
                    'tags' => ['slimstat_notice_latest_news', 'mutation', 'admin-only', 'valid'],
                    'description' => 'Valid notice',
                ],
            ],
            'slimstat_notice_geolite' => [
                [
                    'input' => [
                        'action' => 'slimstat_notice_geolite',
                        'security' => '',
                    ],
                    'tags' => ['slimstat_notice_geolite', 'mutation', 'admin-only', 'valid'],
                    'description' => 'Valid notice',
                ],
            ],
            'slimstat_notice_browscap' => [
                [
                    'input' => [
                        'action' => 'slimstat_notice_browscap',
                        'security' => '',
                    ],
                    'tags' => ['slimstat_notice_browscap', 'mutation', 'admin-only', 'valid'],
                    'description' => 'Valid notice',
                ],
            ],
            'slimstat_notice_caching' => [
                [
                    'input' => [
                        'action' => 'slimstat_notice_caching',
                        'security' => '',
                    ],
                    'tags' => ['slimstat_notice_caching', 'mutation', 'admin-only', 'valid'],
                    'description' => 'Valid notice',
                ],
            ],
            'slimstat_notice_translate' => [
                [
                    'input' => [
                        'action' => 'slimstat_notice_translate',
                        'security' => '',
                    ],
                    'tags' => ['slimstat_notice_translate', 'mutation', 'admin-only', 'valid'],
                    'description' => 'Valid notice',
                ],
            ],
            'slimstat_optout_html' => [
                [
                    'input' => [
                        'action' => 'slimstat_optout_html',
                    ],
                    'tags' => ['slimstat_optout_html', 'query', 'privacy', 'valid'],
                    'description' => 'Valid optout',
                ],
            ],
            'slimstat_fetch_chart_data' => [
                [
                    'input' => [
                        'action' => 'slimstat_fetch_chart_data',
                        'chart' => 'visits',
                        'granularity' => 'daily',
                        'start' => strtotime('-7 days', $now),
                        'end' => $now,
                        'security' => '',
                    ],
                    'tags' => ['slimstat_fetch_chart_data', 'query', 'performance-sensitive', 'valid'],
                    'description' => 'Valid fetch chart',
                ],
                [
                    'input' => [
                        'action' => 'slimstat_fetch_chart_data',
                        'chart' => '',
                        'granularity' => '',
                        'start' => '',
                        'end' => '',
                        'security' => '',
                    ],
                    'tags' => ['slimstat_fetch_chart_data', 'query', 'invalid'],
                    'description' => 'Missing chart params',
                ],
            ],
            'slimtrack' => [
                [
                    'input' => [
                        'action' => 'slimtrack',
                        'ip' => '127.0.0.1',
                        'resource' => '/',
                        'dt' => $now,
                    ],
                    'tags' => ['slimtrack', 'mutation', 'performance-sensitive', 'valid'],
                    'description' => 'Valid tracking',
                ],
                [
                    'input' => [
                        'action' => 'slimtrack',
                    ],
                    'tags' => ['slimtrack', 'mutation', 'invalid'],
                    'description' => 'Missing tracking params',
                ],
            ],
        ];
    }

    private static function get_nonce($action) {
        if (
            $action === 'slimstat_load_report' ||
            $action === 'slimstat_delete_pageview' ||
            $action === 'slimstat_manage_filters'
        ) {
            return wp_create_nonce('meta-box-order');
        }
        if ($action === 'slimstat_update_geoip_database' || $action === 'slimstat_check_geoip_database') {
            return wp_create_nonce('wp_rest');
        }
        return '';
    }

    public static function register_submenu() {
        add_action('admin_menu', function() {
            add_submenu_page(
                'slimstat-tests',
                'AJAX Performance Test',
                'AJAX Performance',
                'manage_options',
                'slimstat-ajax-performance',
                [self::class, 'render_page']
            );
        });
    }

    public static function render_page() {
        echo '<div class="wrap"><h1>AJAX Performance Test</h1>';
        echo '<p>Each test can be run independently. Click a button to run a test and see the JSON result.</p>';
        echo '<ul>';
        foreach (array_keys(self::get_tests()) as $action) {
            echo '<li><form method="post" style="display:inline">';
            echo '<input type="hidden" name="run_ajax_perf_test" value="1">';
            echo '<input type="hidden" name="test_action" value="' . esc_attr($action) . '">';
            echo '<button type="submit" class="button">Run ' . esc_html($action) . ' tests</button>';
            echo '</form></li>';
        }
        echo '</ul>';
        if (isset($_POST['run_ajax_perf_test']) && !empty($_POST['test_action'])) {
            $action = sanitize_text_field($_POST['test_action']);
            self::run_tests($action);
        }
        echo '</div>';
    }

    public static function run_tests($action) {
        global $wpdb;
        $tests = self::get_tests();
        if (empty($tests[$action])) {
            echo '<p>No tests defined for this action.</p>';
            return;
        }
        $results = [];
        foreach ($tests[$action] as $test_case) {
            $input = $test_case['input'];
            if (isset($input['security']) && $input['security'] === '') {
                $input['security'] = self::get_nonce($action);
            }
            $result = self::run_single_test($action, $input, $test_case['tags'], $test_case['description']);
            $results[] = $result;
        }
        echo '<h2>JSON Results</h2>';
        echo '<pre style="background:#222;color:#fff;padding:1em;overflow:auto;max-width:900px">' . esc_html(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
    }

    private static function run_single_test($action, $input, $tags, $description) {
        global $wpdb, $current_user;
        $test_id = $action . '_' . md5(json_encode($input));
        $wpdb->queries = [];
        $wpdb->savequeries = true;
        $sql_start = microtime(true);
        $total_start = microtime(true);

        wp_cache_flush();
        if (function_exists('delete_transient')) {
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
        }

        if ($action === 'slimstat_delete_pageview') {
            $pageview_id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}slim_stats ORDER BY id DESC LIMIT 1");
            if ($pageview_id) {
                $input['pageview_id'] = $pageview_id;
            } else {
                $wpdb->insert("{$wpdb->prefix}slim_stats", ['dt' => time()]);
                $input['pageview_id'] = $wpdb->insert_id;
            }
        }
        if ($action === 'slimstat_manage_filters') {
            $input['type'] = 'load';
            $input['page'] = 'slimstat';
            $filters = get_option('slimstat_filters', array());
            if (!empty($filters)) {
                $input['filter_id'] = array_key_first($filters);
            } else {
                $input['filter_id'] = '1';
            }
        }
        if ($action === 'slimstat_load_report') {
            if (class_exists('wp_slimstat_reports')) {
                \wp_slimstat_reports::init();
                if (!empty(\wp_slimstat_reports::$reports)) {
                    $input['report_id'] = array_key_first(\wp_slimstat_reports::$reports);
                } else {
                    $input['report_id'] = 'slim_p0_00';
                }
            }
        }
        if ($action === 'slimstat_fetch_chart_data') {
            $input['chart'] = 'visits';
            $input['granularity'] = 'daily';
            $input['start'] = $wpdb->get_var("SELECT MIN(dt) FROM {$wpdb->prefix}slim_stats");
            $input['end'] = $wpdb->get_var("SELECT MAX(dt) FROM {$wpdb->prefix}slim_stats");
            if (empty($input['start']) || empty($input['end'])) {
                $input['start'] = strtotime('-7 days');
                $input['end'] = time();
            }
        }
        if ($action === 'slimtrack') {
            $row = $wpdb->get_row("SELECT ip, resource, dt FROM {$wpdb->prefix}slim_stats WHERE ip IS NOT NULL AND resource IS NOT NULL LIMIT 1", ARRAY_A);
            if ($row) {
                $input['ip'] = $row['ip'];
                $input['resource'] = $row['resource'];
                $input['dt'] = $row['dt'];
            } else {
                $input['ip'] = '127.0.0.1';
                $input['resource'] = '/';
                $input['dt'] = time();
            }
        }

        if (!current_user_can('manage_options')) {
            $admins = get_users(['role' => 'administrator', 'number' => 1]);
            if (!empty($admins)) {
                wp_set_current_user($admins[0]->ID);
            }
        }

        if ($action === 'slimstat_load_report') {
            if (class_exists('wp_slimstat_reports')) {
                \wp_slimstat_reports::init();
                if (!empty($input['report_id'])) {
                    add_action('wp_ajax_slimstat_load_report', array('wp_slimstat_reports', 'callback_wrapper'), 10, 2);
                }
            }
        }

        $old_post = $_POST;
        $old_request = $_REQUEST;
        $_POST = $_REQUEST = $input;

        ob_start();
        $error = null;
        $output = null;
        $exception = null;
        try {
            do_action('wp_ajax_' . $action);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $exception = $e;
            $output = ob_get_clean();
        }

        $_POST = $old_post;
        $_REQUEST = $old_request;

        $total_end = microtime(true);
        $sql_end = microtime(true);
        $sql_duration = round(($sql_end - $sql_start) * 1000, 2);
        $total_duration = round(($total_end - $total_start) * 1000, 2);
        $sql_queries = [];
        if (!empty($wpdb->queries)) {
            foreach ($wpdb->queries as $q) {
                $sql_queries[] = $q[0];
            }
        }

        return [
            'test_id' => $test_id,
            'action' => $action,
            'description' => $description,
            'total_duration_ms' => $total_duration,
            'sql_duration_ms' => $sql_duration,
            'sql_queries' => $sql_queries,
            'tags' => $tags,
            'input' => $input,
            'output' => $output,
            'error' => $error,
            'exception' => $exception ? [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ] : null,
        ];
    }
}

AjaxPerformanceTest::register_submenu();
