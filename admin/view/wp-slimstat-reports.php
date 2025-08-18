<?php

class wp_slimstat_reports
{
    public static $reports      = []; // Structures to store all the information about what screens and reports are available
    public static $user_reports = [
        'slimview1' => [],
        'slimview2' => [],
        'slimview3' => [],
        'slimview4' => [],
        'slimview5' => [],
        'dashboard' => [],
        'inactive'  => [],
    ];
    public static $resource_titles = [];

    /**
     * Initalize class properties
     */
    public static function init()
    {
        // Has the class already been initialized?
        if (!empty(self::$reports)) {
            return true;
        }

        // Include and initialize the API to interact with the database
        include_once(__DIR__ . '/wp-slimstat-db.php');
        wp_slimstat_db::init();

        // Include the localization library
        include_once(plugin_dir_path(dirname(__FILE__, 2)) . 'languages/index.php');

        // Define all the reports
        //
        // Parameters
        // - title : report name
        // - callback : function to use to render the report
        // - callback_args : parameters to pass to the function
        // - classes : determine the look and feel of this report ( tall, large, extralarge, full-width )
        // - locations : where should the report appear ( slimview1, .., slimview4, dashboard )
        // - tooltip : contextual help to be displayed on hover

        $chart_tooltip = '<strong>' . __('Chart Controls', 'wp-slimstat') . '</strong><ul><li>' . __('Use your mouse wheel to zoom in and out', 'wp-slimstat') . '</li><li>' . __('While zooming in, drag the chart to move to a different area', 'wp-slimstat') . '</li></ul>';

        $pageviews_chart_tooltip = '<strong>' . __('Pageviews', 'wp-slimstat') . '</strong><br>'
            . __('Shows how many times your site’s pages have been viewed.', 'wp-slimstat')
            . '<ul style="margin-top: 8px; margin-bottom: 8px;">'
            . '<li>' . __('— Solid line: current period', 'wp-slimstat') . '</li>'
            . '<li>' . __('-- Dashed line: previous period', 'wp-slimstat') . '</li>'
            . '<li>' . __('Tap “Pageviews” or “Unique IPs” to toggle each line.', 'wp-slimstat') . '</li>'
            . '<li>' . __('Use the dropdown (Hourly, Daily, Weekly, Monthly, Yearly) to adjust the chart’s interval.', 'wp-slimstat') . '</li>'
            . '</ul>';

        $human_visits_chart_tooltip = '<strong>' . __('Human Visits', 'wp-slimstat') . '</strong><br>'
            . __('Track the number of real human visitors.', 'wp-slimstat')
            . '<ul style="margin-top: 8px; margin-bottom: 8px;">'
            . '<li>' . __('— Solid line: current period', 'wp-slimstat') . '</li>'
            . '<li>' . __('-- Dashed line: previous period', 'wp-slimstat') . '</li>'
            . '<li>' . __('Tap “Visits” or “Unique IPs” to toggle each line.', 'wp-slimstat') . '</li>'
            . '<li>' . __('Use the dropdown (Hourly, Daily, Weekly, Monthly, Yearly) to adjust the chart’s interval.', 'wp-slimstat') . '</li>'
            . '</ul>';

        $pages_with_outbound_links_chart_tooltip = '<strong>' . __('Pages with Outbound Links', 'wp-slimstat') . '</strong><br>'
            . __('Track how often visitors click outbound links on your site.', 'wp-slimstat')
            . '<ul style="margin-top: 8px; margin-bottom: 8px;">'
            . '<li>' . __('— Solid line: current period', 'wp-slimstat') . '</li>'
            . '<li>' . __('-- Dashed line: previous period', 'wp-slimstat') . '</li>'
            . '<li>' . __('Tap “Outbound Links” or “Unique Outbound” to toggle each line.', 'wp-slimstat') . '</li>'
            . '<li>' . __('Use the dropdown (Hourly, Daily, Weekly, Monthly, Yearly) to adjust the chart’s interval.', 'wp-slimstat') . '</li>'
            . '</ul>';

        $search_terms_chart_tooltip = '<strong>' . __('Search Terms', 'wp-slimstat') . '</strong><br>'
            . __('See how many times users find your site via search engines.', 'wp-slimstat')
            . '<ul style="margin-top: 8px; margin-bottom: 8px;">'
            . '<li>' . __('— Solid line: current period', 'wp-slimstat') . '</li>'
            . '<li>' . __('-- Dashed line: previous period', 'wp-slimstat') . '</li>'
            . '<li>' . __('Tap “Search Terms” or “Unique Terms” to toggle each line.', 'wp-slimstat') . '</li>'
            . '<li>' . __('Use the dropdown (Hourly, Daily, Weekly, Monthly, Yearly) to adjust the chart’s interval.', 'wp-slimstat') . '</li>'
            . '</ul>';

        $traffic_sources_chart_tooltip = '<strong>' . __('Traffic Sources', 'wp-slimstat') . '</strong><br>'
            . __('See how many visits come from each traffic source.', 'wp-slimstat')
            . '<ul style="margin-top: 8px; margin-bottom: 8px;">'
            . '<li>' . __('— Solid line: current period', 'wp-slimstat') . '</li>'
            . '<li>' . __('-- Dashed line: previous period', 'wp-slimstat') . '</li>'
            . '<li>' . __('Tap “Domains” or “Unique IPs” to toggle each line.', 'wp-slimstat') . '</li>'
            . '<li>' . __('Use the dropdown (Hourly, Daily, Weekly, Monthly, Yearly) to adjust the chart’s interval.', 'wp-slimstat') . '</li>'
            . '</ul>';

        $users_chart_tooltip = '<strong>' . __('Users', 'wp-slimstat') . '</strong><br>'
            . __('See how many users come to your site.', 'wp-slimstat')
            . '<ul style="margin-top: 8px; margin-bottom: 8px;">'
            . '<li>' . __('— Solid line: current period', 'wp-slimstat') . '</li>'
            . '<li>' . __('-- Dashed line: previous period', 'wp-slimstat') . '</li>'
            . '<li>' . __('Tap “Users” or “Unique Users” to toggle each line.', 'wp-slimstat') . '</li>'
            . '<li>' . __('Use the dropdown (Hourly, Daily, Weekly, Monthly, Yearly) to adjust the chart’s interval.', 'wp-slimstat') . '</li>'
            . '</ul>';

        self::$reports = [
            'slim_p7_02' => [
                'title'         => __('Access Log', 'wp-slimstat'),
                'callback'      => [self::class, 'show_access_log'],
                'callback_args' => [
                    'type'    => 'recent',
                    'columns' => '*',
                    'raw'     => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['full-width', 'tall'],
                'locations' => ['slimview1', 'dashboard'],
                'tooltip'   => __('Color Codes', 'wp-slimstat') . '</strong><p><span class="little-color-box is-search-engine"></span> ' . __('From search result page', 'wp-slimstat') . '</p><p><span class="little-color-box is-known-visitor"></span> ' . __('Has Left Comments', 'wp-slimstat') . '</p><p><span class="little-color-box is-known-user"></span> ' . __('WP User', 'wp-slimstat') . '</p><p><span class="little-color-box is-direct"></span> ' . __('Other Human', 'wp-slimstat') . '</p><p><span class="little-color-box"></span> ' . __('Bot or Crawler', 'wp-slimstat') . '</p>',
            ],

            'slim_p1_01' => [
                'title'         => __('Pageviews', 'wp-slimstat'),
                'callback'      => [self::class, 'show_chart'],
                'callback_args' => [
                    'id'         => 'slim_p1_01',
                    'chart_data' => [
                        'data1' => 'COUNT( ip )',
                        'data2' => 'COUNT( DISTINCT ip )',
                    ],
                    'chart_labels' => [
                        __('Pageviews', 'wp-slimstat'),
                        __('Unique IPs', 'wp-slimstat'),
                    ],
                ],
                'classes'   => ['extralarge', 'chart'],
                'locations' => ['slimview2', 'dashboard'],
                'tooltip'   => $pageviews_chart_tooltip,
            ],
            'slim_p1_03' => [
                'title'         => __('At a Glance', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'raw' => ['wp_slimstat_db', 'get_overview_summary'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview2', 'dashboard'],
            ],
            'slim_p1_04' => [
                'title'         => __('Currently Online', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'             => 'recent',
                    'columns'          => 'ip',
                    'where'            => '(dt_out > ' . (date_i18n('U') - 300) . ' OR dt > ' . (date_i18n('U') - 300) . ')',
                    'use_date_filters' => false,
                    'raw'              => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview2', 'dashboard'],
            ],
            'slim_p1_06' => [
                'title'         => __('Recent Search Terms', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'         => 'recent',
                    'columns'      => 'searchterms',
                    'where'        => 'searchterms <> "_" AND searchterms <> "" AND searchterms IS NOT NULL',
                    'more_columns' => 'referer, resource',
                    'raw'          => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview2', 'slimview5'],
                'tooltip'   => __('Keywords used by your visitors to find your website on a search engine.', 'wp-slimstat'),
            ],
            'slim_p1_08' => [
                'title'         => __('Top Web Pages', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'resource',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview2', 'dashboard'],
                'tooltip'   => __('Here a "page" is not just a WordPress page type, but any webpage on your site, including posts, products, categories, and any other custom post type. For example, you can set the corresponding filter where Resource Content Type equals cpt:you_cpt_slug_here to get top web pages for a specific custom post type you have.', 'wp-slimstat'),
            ],
            'slim_p1_10' => [
                'title'         => __('Top Referring Domains', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'      => 'top',
                    'columns'   => 'REPLACE( SUBSTRING_INDEX( ( SUBSTRING_INDEX( ( SUBSTRING_INDEX( referer, "://", -1 ) ), "/", 1 ) ), ".", -5 ), "www.", "" )',
                    'as_column' => 'referer',
                    'filter_op' => 'contains',
                    'where'     => 'referer NOT LIKE "%' . str_replace('www.', '', parse_url(home_url(), PHP_URL_HOST)) . '%"',
                    'raw'       => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview2', 'slimview5', 'dashboard'],
            ],
            'slim_p1_11' => [
                'title'         => __('Top Known Visitors', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'username',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview2', 'dashboard'],
            ],
            'slim_p1_12' => [
                'title'         => __('Top Search Terms', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'searchterms',
                    'where'   => 'searchterms <> "_" AND searchterms <> "" AND searchterms IS NOT NULL',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview2', 'slimview4', 'slimview5', 'dashboard'],
            ],
            'slim_p1_13' => [
                'title'         => __('Top Countries', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'country',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes' => ['normal'],
                // 'color'         => '#fff7ed',
                'locations' => ['slimview2', 'slimview3', 'slimview5', 'dashboard'],
                'tooltip'   => __('You can configure Slimstat to not track specific Countries by setting the corresponding filter in Slimstat > Settings > Exclusions.', 'wp-slimstat'),
            ],
            'slim_p1_15' => [
                'title'         => __('Rankings', 'wp-slimstat'),
                'callback'      => [self::class, 'show_rankings'],
                'callback_args' => [
                    'id' => 'slim_p1_15',
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
                'tooltip'   => __("Slimstat retrieves live information from Alexa, Facebook and Mozscape, to measures your site's rankings. Values are updated every 12 hours. Please enter your personal access ID in the settings to access your personalized Mozscape data.", 'wp-slimstat'),
            ],
            'slim_p1_17' => [
                'title'         => __('Top Language Families', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'      => 'top',
                    'columns'   => 'SUBSTRING( language, 1, 2 )',
                    'as_column' => 'language',
                    'filter_op' => 'contains',
                    'raw'       => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
            ],
            'slim_p1_18' => [
                'title'         => __('Users Currently Online', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'             => 'recent',
                    'columns'          => 'username',
                    'where'            => 'dt_out > ' . (date_i18n('U') - 300) . ' OR dt > ' . (date_i18n('U') - 300),
                    'use_date_filters' => false,
                    'raw'              => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview2', 'dashboard'],
                'tooltip'   => __('When visitors leave a comment on your blog, WordPress assigns them a cookie. Slimstat leverages this information to identify returning visitors. Please note that visitors also include registered users.', 'wp-slimstat'),
            ],
            'slim_p1_19_01' => [
                'title'         => __('Search Terms', 'wp-slimstat'),
                'callback'      => [self::class, 'show_chart'],
                'callback_args' => [
                    'id'         => 'slim_p1_19_01',
                    'chart_data' => [
                        'data1' => 'COUNT( searchterms )',
                        'data2' => 'COUNT( DISTINCT searchterms )',
                        'where' => 'searchterms <> "_" AND searchterms IS NOT NULL AND searchterms <> ""',
                    ],
                    'chart_labels' => [
                        __('Search Terms', 'wp-slimstat'),
                        __('Unique Terms', 'wp-slimstat'),
                    ],
                ],
                'classes'   => ['extralarge', 'chart'],
                'locations' => ['slimview2'],
                'tooltip'   => $search_terms_chart_tooltip,
            ],

            'slim_p2_01' => [
                'title'         => __('Human Visits', 'wp-slimstat'),
                'callback'      => [self::class, 'show_chart'],
                'callback_args' => [
                    'id'         => 'slim_p2_01',
                    'chart_data' => [
                        'data1' => 'COUNT( DISTINCT visit_id )',
                        'data2' => 'COUNT( DISTINCT ip )',
                        'where' => '(visit_id > 0 AND browser_type <> 1)',
                    ],
                    'chart_labels' => [
                        __('Visits', 'wp-slimstat'),
                        __('Unique IPs', 'wp-slimstat'),
                    ],
                ],
                'classes'   => ['extralarge', 'chart'],
                'locations' => ['slimview3'],
                'tooltip'   => $human_visits_chart_tooltip,
            ],
            'slim_p2_02' => [
                'title'         => __('Audience Overview', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'raw' => ['wp_slimstat_db', 'get_visitors_summary'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview3', 'dashboard'],
                'tooltip'   => __('Where not otherwise specified, the metrics in this report are referred to human visitors.', 'wp-slimstat'),
            ],
            'slim_p2_03' => [
                'title'         => __('Top Languages', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'language',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview3'],
            ],
            'slim_p2_04' => [
                'title'         => __('Top User Agents', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'browser, browser_version',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview3', 'dashboard'],
                'tooltip'   => __('This report includes all types of clients, both bots and humans.', 'wp-slimstat'),
            ],
            'slim_p2_05' => [
                'title'         => __('Top Service Providers', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'ip',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['extralarge'],
                'locations' => ['inactive'],
                'tooltip'   => __('Internet Service Provider: a company which provides other companies or individuals with access to the Internet. Your DSL or cable internet service is provided to you by your ISP.<br><br>You can ignore specific IP addresses by setting the corresponding filter under Settings > Slimstat > Filters.', 'wp-slimstat'),
            ],
            'slim_p2_06' => [
                'title'         => __('Top Operating Systems', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'platform',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
                'tooltip'   => __('Internet Service Provider: a company which provides other companies or individuals with access to the Internet. Your DSL or cable internet service is provided to you by your ISP.<br><br>You can ignore specific IP addresses by setting the corresponding filter under Settings > Slimstat > Filters.', 'wp-slimstat'),
            ],
            'slim_p2_07' => [
                'title'         => __('Top Screen Resolutions', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'screen_width, screen_height',
                    'where'   => 'screen_width <> 0 AND screen_height <> 0',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview3', 'dashboard'],
            ],
            'slim_p2_08' => [
                'title'         => __('Top Viewport Sizes', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'resolution',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
            ],
            'slim_p2_12' => [
                'title'         => __('Visit Duration', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'raw' => ['wp_slimstat_db', 'get_visits_duration'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview3'],
                'tooltip'   => __('All values represent the percentages of pageviews within the corresponding time range.', 'wp-slimstat'),
            ],
            'slim_p2_13' => [
                'title'         => __('Recent Countries', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'recent',
                    'columns' => 'country',
                    'raw'     => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
            ],
            'slim_p2_14' => [
                'title'         => __('Recent Viewport Sizes', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'recent',
                    'columns' => 'resolution',
                    'raw'     => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
            ],
            'slim_p2_15' => [
                'title'         => __('Recent Operating Systems', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'recent',
                    'columns' => 'platform',
                    'raw'     => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
            ],
            'slim_p2_16' => [
                'title'         => __('Recent Browsers', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'recent',
                    'columns' => 'browser, browser_version',
                    'raw'     => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
            ],
            'slim_p2_17' => [
                'title'         => __('Recent Languages', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'recent',
                    'columns' => 'language',
                    'raw'     => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
            ],
            'slim_p2_18' => [
                'title'         => __('Top Browser Families', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'browser',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
                'tooltip'   => __('This report shows you what user agent families (no version considered) are popular among your visitors.', 'wp-slimstat'),
            ],
            'slim_p2_19' => [
                'title'         => __('Top OS Families', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'      => 'top',
                    'columns'   => 'CONCAT( "p-", SUBSTRING( platform, 1, 3 ) )',
                    'as_column' => 'platform',
                    'filter_op' => 'contains',
                    'raw'       => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview3'],
                'tooltip'   => __('This report shows you what operating system families (no version considered) are popular among your visitors.', 'wp-slimstat'),
            ],
            'slim_p2_20' => [
                'title'         => __('Recent Users', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'recent',
                    'columns' => 'username',
                    'where'   => 'notes LIKE "%user:%"',
                    'raw'     => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview3'],
            ],
            'slim_p2_21' => [
                'title'         => __('Top Users', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'username',
                    'where'   => 'notes LIKE "%user:%"',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview3', 'dashboard'],
            ],
            'slim_p2_22_01' => [
                'title'         => __('Users', 'wp-slimstat'),
                'callback'      => [self::class, 'show_chart'],
                'callback_args' => [
                    'id'         => 'slim_p2_22_01',
                    'chart_data' => [
                        'data1' => 'COUNT( username )',
                        'data2' => 'COUNT( DISTINCT username )',
                    ],
                    'chart_labels' => [
                        __('Users', 'wp-slimstat'),
                        __('Unique Users', 'wp-slimstat'),
                    ],
                ],
                'classes'   => ['extralarge', 'chart'],
                'locations' => ['slimview3'],
                'tooltip'   => $users_chart_tooltip,
            ],
            'slim_p2_24' => [
                'title'         => __('Top Bots', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'browser, browser_version',
                    'where'   => 'browser_type = 1',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview3'],
            ],
            'slim_p2_25' => [
                'title'         => __('Top Human Browsers', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'browser, browser_version',
                    'where'   => 'browser_type != 1',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview3'],
            ],

            'slim_p3_01' => [
                'title'         => __('Traffic Sources', 'wp-slimstat'),
                'callback'      => [self::class, 'show_chart'],
                'callback_args' => [
                    'id'         => 'slim_p3_01',
                    'chart_data' => [
                        'data1' => 'COUNT( DISTINCT referer )',
                        'data2' => 'COUNT( DISTINCT ip )',
                        'where' => '(referer IS NOT NULL AND referer NOT LIKE "%' . home_url() . '%")',
                    ],
                    'chart_labels' => [
                        __('Domains', 'wp-slimstat'),
                        __('Unique IPs', 'wp-slimstat'),
                    ],
                ],
                'classes'   => ['extralarge', 'chart'],
                'locations' => ['slimview5'],
                'tooltip'   => $traffic_sources_chart_tooltip,
            ],
            'slim_p3_02' => [
                'title'         => __('Traffic Summary', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'raw' => ['wp_slimstat_db', 'get_traffic_sources_summary'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview5'],
            ],

            'slim_p4_01' => [
                'title'         => __('Recent Outbound Links', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'recent',
                    'columns' => 'outbound_resource',
                    'raw'     => ['wp_slimstat_db', 'get_recent_outbound'],
                ],
                'classes'   => ['large'],
                'locations' => ['slimview4'],
                'tooltip'   => '',
            ],
            'slim_p4_02' => [
                'title'         => __('Recent Posts', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'      => 'recent',
                    'columns'   => 'TRIM( TRAILING "/" FROM resource )',
                    'as_column' => 'resource',
                    'where'     => 'content_type = "post"',
                    'raw'       => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview4'],
            ],
            'slim_p4_04' => [
                'title'         => __('Recent Feeds', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'recent',
                    'columns' => 'resource',
                    'where'   => '(resource LIKE "%/feed%" OR resource LIKE "%?feed=>%" OR resource LIKE "%&feed=>%" OR content_type LIKE "%feed%")',
                    'raw'     => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
            ],
            'slim_p4_05' => [
                'title'         => __('Recent Pages Not Found', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'recent',
                    'columns' => 'resource',
                    'where'   => '(resource LIKE "[404]%" OR content_type LIKE "%404%")',
                    'raw'     => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview4'],
            ],
            'slim_p4_06' => [
                'title'         => __('Recent Internal Searches', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'recent',
                    'columns' => 'searchterms',
                    'where'   => 'content_type LIKE "%%search%%" AND searchterms <> "" AND searchterms IS NOT NULL',
                    'raw'     => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview4'],
                'tooltip'   => __("Searches performed using WordPress' built-in search functionality.", 'wp-slimstat'),
            ],
            'slim_p4_07' => [
                'title'         => __('Top Categories', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'category',
                    'where'   => 'content_type LIKE "%category%"',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview4', 'dashboard'],
            ],
            'slim_p4_09' => [
                'title'         => __('Top Downloads', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'     => 'top',
                    'columns'  => 'resource',
                    'where'    => 'content_type = "download"',
                    'raw'      => ['wp_slimstat_db', 'get_top'],
                    'criteria' => 'swap',
                ],
                'classes'   => ['large'],
                'locations' => ['slimview4'],
                'tooltip'   => __('You can configure Slimstat to track specific file extensions as downloads.', 'wp-slimstat'),
            ],
            'slim_p4_10' => [
                'title'         => __('Recent Custom Events', 'wp-slimstat'),
                'callback'      => [self::class, 'show_events'],
                'callback_args' => [
                    'type'    => 'recent',
                    'columns' => 'notes',
                    'raw'     => ['wp_slimstat_db', 'get_recent_events'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
                'tooltip'   => __('This report lists any <em>event</em> occurred on your website. Please refer to the FAQ for more information on how to use this functionality.', 'wp-slimstat'),
            ],
            'slim_p4_11' => [
                'title'         => __('Top Posts', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'resource',
                    'where'   => 'content_type = "post"',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview4'],
            ],
            'slim_p4_12' => [
                'title'         => __('Top Custom Events', 'wp-slimstat'),
                'callback'      => [self::class, 'show_events'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'notes',
                    'raw'     => ['wp_slimstat_db', 'get_top_events'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview4'],
                'tooltip'   => __('This report lists any <em>event</em> occurred on your website. Please refer to the FAQ for more information on how to use this functionality.', 'wp-slimstat'),
            ],
            'slim_p4_13' => [
                'title'         => __('Top Internal Searches', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'searchterms',
                    'where'   => 'content_type LIKE "%%search%%" AND searchterms <> "" AND searchterms IS NOT NULL',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview4'],
            ],
            'slim_p4_15' => [
                'title'         => __('Recent Categories', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'      => 'recent',
                    'columns'   => 'TRIM( TRAILING "/" FROM resource )',
                    'as_column' => 'resource',
                    'where'     => '(content_type = "category")',
                    'raw'       => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
            ],
            'slim_p4_152' => [
                'title'         => __('Recent Tags', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'      => 'recent',
                    'columns'   => 'TRIM( TRAILING "/" FROM resource )',
                    'as_column' => 'resource',
                    'where'     => '(content_type = "tag")',
                    'raw'       => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
            ],
            'slim_p4_16' => [
                'title'         => __('Top Pages Not Found', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'resource',
                    'where'   => 'content_type LIKE "%404%"',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview4'],
            ],
            'slim_p4_18' => [
                'title'         => __('Top Authors', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'author',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview4', 'dashboard'],
            ],
            'slim_p4_19' => [
                'title'         => __('Top Tags', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'category',
                    'where'   => '(content_type LIKE "%tag%")',
                    'raw'     => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
            ],
            'slim_p4_20' => [
                'title'         => __('Recent Downloads', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'recent',
                    'columns' => 'resource',
                    'where'   => 'content_type = "download"',
                    'raw'     => ['wp_slimstat_db', 'get_recent'],
                ],
                'classes'   => ['large'],
                'locations' => ['inactive'],
            ],
            'slim_p4_21' => [
                'title'         => __('Top Outbound Links', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'     => 'top',
                    'columns'  => 'outbound_resource',
                    'raw'      => ['wp_slimstat_db', 'get_top_outbound'],
                    'criteria' => 'swap',
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview4', 'dashboard'],
            ],
            'slim_p4_22' => [
                'title'         => __('Your Website', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'raw' => ['wp_slimstat_db', 'get_your_blog'],
                ],
                'classes'   => ['normal'],
                'locations' => ['inactive'],
                'tooltip'   => __('Your content at a glance: posts, comments, pingbacks, etc. Please note that this report is not affected by the filters set here above.', 'wp-slimstat'),
            ],
            'slim_p4_23' => [
                'title'         => __('Top Bounce Pages', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'      => 'top',
                    'columns'   => 'TRIM( TRAILING "/" FROM resource )',
                    'as_column' => 'resource',
                    'where'     => 'content_type <> "404"',
                    'having'    => 'HAVING COUNT(visit_id) = 1',
                    'raw'       => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview4'],
            ],
            'slim_p4_24' => [
                'title'         => __('Top Exit Pages', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'                => 'top',
                    'columns'             => 'visit_id',
                    'outer_select_column' => 'resource',
                    'aggr_function'       => 'MAX',
                    'raw'                 => ['wp_slimstat_db', 'get_top_aggr'],
                ],
                'classes'   => ['large'],
                'locations' => ['slimview4', 'dashboard'],
            ],
            'slim_p4_25' => [
                'title'         => __('Top Entry Pages', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'                => 'top',
                    'columns'             => 'visit_id',
                    'outer_select_column' => 'resource',
                    'aggr_function'       => 'MIN',
                    'raw'                 => ['wp_slimstat_db', 'get_top_aggr'],
                ],
                // 'color'         => '#EFF6FF',
                'classes'   => ['large'],
                'locations' => ['slimview4'],
            ],
            'slim_p4_26_01' => [
                'title'         => __('Pages with Outbound Links', 'wp-slimstat'),
                'callback'      => [self::class, 'show_chart'],
                'callback_args' => [
                    'id'         => 'slim_p4_26_01',
                    'chart_data' => [
                        'data1' => 'COUNT( outbound_resource )',
                        'data2' => 'COUNT( DISTINCT outbound_resource )',
                    ],
                    'chart_labels' => [
                        __('Outbound Links', 'wp-slimstat'),
                        __('Unique Outbound', 'wp-slimstat'),
                    ],
                ],
                'classes'   => ['extralarge', 'chart'],
                'locations' => ['slimview4'],
                'tooltip'   => $pages_with_outbound_links_chart_tooltip,
            ],
            'slim_p4_27' => [
                'title'         => __('Users by Page', 'wp-slimstat'),
                'callback'      => [self::class, 'show_group_by'],
                'callback_args' => [
                    'column_group' => 'username',
                    'group_by'     => 'resource',
                    'raw'          => ['wp_slimstat_db', 'get_group_by'],
                ],
                'classes'   => ['large'],
                'locations' => ['slimview4'],
            ],
            'slim_p6_01' => [
                'title'         => __('Audience Location', 'wp-slimstat'),
                'callback'      => [self::class, 'show_world_map'],
                'callback_args' => [
                    'id' => 'slim_p6_01',
                ],
                'classes'   => ['extralarge', 'map-wrap'],
                'locations' => ['slimview1'],
                'tooltip'   => __('Dots on the map represent the most recent pageviews geolocated by City. This feature is only available by enabling the corresponding precision level in the settings.', 'wp-slimstat'),
            ],
        ];

        if ('on' != wp_slimstat::$settings['geolocation_country']) {
            self::$reports['slim_p2_23'] = [
                'title'         => __('Top Cities', 'wp-slimstat'),
                'callback'      => [self::class, 'raw_results_to_html'],
                'callback_args' => [
                    'type'    => 'top',
                    'columns' => 'city',

                    'raw' => ['wp_slimstat_db', 'get_top'],
                ],
                'classes'   => ['normal'],
                'locations' => ['slimview3'],
            ];
        }

        // Allow third party tools to manipulate this list here above: please use unique report IDs that don't interfere with built-in ones, if you add your own custom report
        self::$reports = apply_filters('slimstat_reports_info', self::$reports);
        $merge_reports = array_keys(self::$reports);

        // Do we have any new reports not listed in this user's settings?
        if (class_exists('wp_slimstat_admin') && !empty(wp_slimstat_admin::$meta_user_reports) && is_array(wp_slimstat_admin::$meta_user_reports)) {
            $flat_user_reports = array_filter(explode(',', implode(',', wp_slimstat_admin::$meta_user_reports)));
            $merge_reports     = array_diff(array_filter(array_keys(self::$reports)), $flat_user_reports);

            // Now let's explode all the lists
            foreach (wp_slimstat_admin::$meta_user_reports as $a_location => $a_report_list) {
                if (!array_key_exists($a_location, self::$user_reports)) {
                    continue;
                }
                self::$user_reports[$a_location] = explode(',', $a_report_list);
            }
        }

        foreach ($merge_reports as $a_report_id) {
            if (!empty(self::$reports[$a_report_id]['locations']) && is_array(self::$reports[$a_report_id]['locations'])) {
                foreach (self::$reports[$a_report_id]['locations'] as $a_report_location) {
                    if (!in_array($a_report_id, self::$user_reports[$a_report_location])) {
                        self::$user_reports[$a_report_location][] = $a_report_id;
                    }
                }
            }
        }

        // We store page titles in a transient for improved performance
        if (empty($_REQUEST['page']) || !in_array($_REQUEST['page'], ['slimlayout', 'slimadddons'])) {
            self::$resource_titles = get_transient('slimstat_resource_titles');
            if (false === self::$resource_titles) {
                self::$resource_titles = [];
            }
        }
        return null;
    }

    // end init

    public static function report_header($_report_id = '')
    {
        if (empty(self::$reports[$_report_id])) {
            return false;
        }

        $header_classes = empty(self::$reports[$_report_id]['classes']) ? '' : implode(' ', self::$reports[$_report_id]['classes']);
        $fixed_title    = str_replace(['-', '_', '"', "'", ')', '('], '', strtolower(self::$reports[$_report_id]['title']));
        $header_classes .= ' report-' . implode('-', explode(' ', esc_attr($fixed_title)));
        $header_buttons = '';
        $header_tooltip = '';
        $widget_title   = '';

        // Don't show the header buttons on the frontend
        if (is_admin()) {
            // Show the refresh button only if the time range is not in the past
            if (wp_slimstat_db::$filters_normalized['utime']['end'] >= date_i18n('U') - 300) {
                $header_buttons = '<a class="noslimstat refresh" title="' . __('Refresh', 'wp-slimstat') . '" href="' . self::fs_url() . '"><svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M2.44215 9.33359C2.50187 5.19973 5.89666 1.875 10.0656 1.875C12.8226 1.875 15.239 3.32856 16.5777 5.50601C16.7584 5.80006 16.6666 6.18499 16.3726 6.36576C16.0785 6.54654 15.6936 6.45471 15.5128 6.16066C14.3937 4.34037 12.3735 3.125 10.0656 3.125C6.57859 3.125 3.75293 5.89808 3.69234 9.33181L4.02599 9.00077C4.27102 8.75765 4.66675 8.75921 4.90986 9.00424C5.15298 9.24928 5.15143 9.645 4.90639 9.88812L3.50655 11.277C3.26288 11.5188 2.86982 11.5188 2.62614 11.277L1.2263 9.88812C0.981267 9.645 0.979713 9.24928 1.22283 9.00424C1.46595 8.75921 1.86167 8.75765 2.10671 9.00077L2.44215 9.33359ZM16.4885 8.72215C16.732 8.4815 17.1238 8.4815 17.3672 8.72215L18.7724 10.111C19.0179 10.3537 19.0202 10.7494 18.7776 10.9949C18.5349 11.2404 18.1392 11.2427 17.8937 11.0001L17.5521 10.6624C17.4943 14.8003 14.0846 18.125 9.90191 18.125C7.13633 18.125 4.71134 16.6725 3.3675 14.4949C3.18622 14.2012 3.2774 13.8161 3.57114 13.6348C3.86489 13.4535 4.24997 13.5447 4.43125 13.8384C5.5545 15.6586 7.58316 16.875 9.90191 16.875C13.4071 16.875 16.2433 14.0976 16.302 10.6641L15.962 11.0001C15.7165 11.2427 15.3208 11.2404 15.0782 10.9949C14.8355 10.7494 14.8378 10.3537 15.0833 10.111L16.4885 8.72215Z" fill="#676E74"/></svg></a>';
            }

            $tooltip_base = '<span class="header-tooltip slimstat-tooltip-trigger corner"><svg width="17" height="18" viewBox="0 0 17 18" fill="none" xmlns="http://www.w3.org/2000/svg"> <path d="M8.6665 13.3125C8.97716 13.3125 9.229 13.0607 9.229 12.75V8.25C9.229 7.93934 8.97716 7.6875 8.6665 7.6875C8.35584 7.6875 8.104 7.93934 8.104 8.25V12.75C8.104 13.0607 8.35584 13.3125 8.6665 13.3125Z" fill="#9BA1A6"/> <path d="M8.6665 5.25C9.08072 5.25 9.4165 5.58579 9.4165 6C9.4165 6.41421 9.08072 6.75 8.6665 6.75C8.25229 6.75 7.9165 6.41421 7.9165 6C7.9165 5.58579 8.25229 5.25 8.6665 5.25Z" fill="#9BA1A6"/> <path fill-rule="evenodd" clip-rule="evenodd" d="M0.604004 9C0.604004 4.5472 4.21371 0.9375 8.6665 0.9375C13.1193 0.9375 16.729 4.5472 16.729 9C16.729 13.4528 13.1193 17.0625 8.6665 17.0625C4.21371 17.0625 0.604004 13.4528 0.604004 9ZM8.6665 2.0625C4.83503 2.0625 1.729 5.16852 1.729 9C1.729 12.8315 4.83503 15.9375 8.6665 15.9375C12.498 15.9375 15.604 12.8315 15.604 9C15.604 5.16852 12.498 2.0625 8.6665 2.0625Z" fill="#9BA1A6"/></svg><span class="slimstat-tooltip-content">';
            // $tooltip_base   = '<span class="header-tooltip dashicons dashicons-editor-help slimstat-tooltip-trigger corner"><span class="slimstat-tooltip-content">';
            $header_tooltip = $tooltip_base . (empty(self::$reports[$_report_id]['tooltip']) ? esc_html($_report_id) : self::$reports[$_report_id]['tooltip'] . '<br /><br />' . esc_html($_report_id)) . '</span></span>';

            // Allow third-party code to add more buttons
            $header_buttons = apply_filters('slimstat_report_header_buttons', $header_buttons, $_report_id);
            $header_buttons = '<div class="slimstat-header-buttons">' . $header_buttons . '</div>';

            $widget_title = '<h3>' . esc_html(self::$reports[$_report_id]['title']) . $header_tooltip . '</h3>';
        }

        $bar_color = (empty(self::$reports[$_report_id]['color'])) ? '#EFF6FF' : self::$reports[$_report_id]['color'];

        echo "<div class='postbox " . esc_attr($header_classes) . "' style='--box-bar-color: " . esc_attr($bar_color) . ";' id='" . esc_attr($_report_id) . sprintf("'>%s %s <div class='inside'>", $header_buttons, $widget_title);
        return null;
    }

    public static function report_footer()
    {
        echo '</div></div>';
    }

    public static function report_pagination($_count_page_results = 0, $_count_all_results = 0, $_show_refresh_countdown = false, $_results_per_page = -1)
    {
        if (!is_admin()) {
            return '';
        }

        $_results_per_page = ($_results_per_page < 0) ? wp_slimstat::$settings['rows_to_show'] : $_results_per_page;

        $endpoint           = min($_count_all_results, wp_slimstat_db::$filters_normalized['misc']['start_from'] + $_results_per_page);
        $pagination_buttons = '';
        $direction_prev     = is_rtl() ? 'right' : 'left';
        $direction_next     = is_rtl() ? 'left' : 'right';

        if ($endpoint + $_results_per_page < $_count_all_results && $_count_page_results > 0) {
            $startpoint = $_count_all_results - $_count_all_results % $_results_per_page;
            if ($startpoint == $_count_all_results) {
                $startpoint -= $_results_per_page;
            }
            $pagination_buttons .= '<a class="refresh slimstat-font-angle-double-' . $direction_next . '" href="' . wp_slimstat_reports::fs_url('start_from equals ' . $startpoint) . '"></a> ';
        }
        if ($endpoint < $_count_all_results && $_count_page_results > 0) {
            $startpoint = wp_slimstat_db::$filters_normalized['misc']['start_from'] + $_results_per_page;
            $pagination_buttons .= '<a class="refresh slimstat-font-angle-' . $direction_next . '" href="' . wp_slimstat_reports::fs_url('start_from equals ' . $startpoint) . '"></a> ';
        }
        if (wp_slimstat_db::$filters_normalized['misc']['start_from'] > 0) {
            $startpoint = (wp_slimstat_db::$filters_normalized['misc']['start_from'] > $_results_per_page) ? wp_slimstat_db::$filters_normalized['misc']['start_from'] - $_results_per_page : 0;
            $pagination_buttons .= '<a class="refresh slimstat-font-angle-' . $direction_prev . '" href="' . wp_slimstat_reports::fs_url('start_from equals ' . $startpoint) . '"></a> ';
        }
        if (wp_slimstat_db::$filters_normalized['misc']['start_from'] - $_results_per_page > 0) {
            $pagination_buttons .= '<a class="refresh slimstat-font-angle-double-' . $direction_prev . '" href="' . wp_slimstat_reports::fs_url('start_from equals 0') . '"></a> ';
        }

        $pagination = '<p class="pagination">' . sprintf(__('Showing %s - %s of %s', 'wp-slimstat'), number_format_i18n(wp_slimstat_db::$filters_normalized['misc']['start_from'] + 1), number_format_i18n($endpoint), number_format_i18n($_count_all_results) . (($_count_all_results == wp_slimstat::$settings['limit_results']) ? '+' : ''));

        if ($_show_refresh_countdown && wp_slimstat::$settings['refresh_interval'] > 0 && wp_slimstat_db::$filters_normalized['utime']['end'] >= date_i18n('U') - 300) {
            $pagination .= ' [' . __('Refresh in', 'wp-slimstat') . ' <i class="refresh-timer"></i>]';
        }

        return $pagination . ($pagination_buttons . '</p>');
    }

    public static function callback_wrapper()
    {
        // If this user is whitelisted, we use the minimum capability
        $minimum_capability = 'read';
        if (false === strpos(wp_slimstat::$settings['can_view'], (string) $GLOBALS['current_user']->user_login) && !empty(wp_slimstat::$settings['capability_can_view'])) {
            $minimum_capability = wp_slimstat::$settings['capability_can_view'];
        }

        if (!current_user_can($minimum_capability)) {
            return;
        }

        $_args = self::_check_args(func_get_args());
        if (!empty($_args) && !empty($_args['callback'])) {
            call_user_func($_args['callback'], $_args['callback_args']);
        }
    }

    public static function raw_results_to_html($_args = [])
    {
        if ('on' == wp_slimstat::$settings['async_load'] && (!defined('DOING_AJAX') || !DOING_AJAX) && empty($_args['is_widget'])) {
            return '';
        }

        wp_slimstat_db::$debug_message = '';

        $all_results = call_user_func($_args['raw'], $_args);

        // Backward compatibility
        if (!is_array($all_results)) {
            $all_results = [];
        }

        echo wp_kses_post(wp_slimstat_db::$debug_message);

        // Some reports don't need any kind of pre/post-processing, we just display the data contained in the array
        if (empty($_args['columns'])) {
            foreach ($all_results as $a_result) {
                echo '<p>';

                echo sprintf('%s <span>%s</span>', $a_result[ 'metric' ], $a_result[ 'value' ]);

                if (!empty($a_result['tooltip'])) {
                    self::inline_help($a_result['tooltip']);
                }

                if (!empty($a_result['details'])) {
                    echo sprintf("<b class='slimstat-tooltip-content'>%s</b>", $a_result[ 'details' ]);
                }

                echo '</p>';
            }
        } else {
            $results = array_slice(
                $all_results,
                0,
                wp_slimstat::$settings['rows_to_show']
            );

            // Count the results
            $count_page_results = count($results);

            if (0 == $count_page_results) {
                echo '<p class="nodata">' . __('No data to display', 'wp-slimstat') . '</p>';

                if (defined('DOING_AJAX') && DOING_AJAX) {
                    die();
                } else {
                    return [];
                }
            }

            // Some reports use aliases for column names
            if (!empty($_args['as_column'])) {
                $_args['columns'] = $_args['as_column'];
            } elseif (!empty($_args['outer_select_column'])) {
                $_args['columns'] = $_args['outer_select_column'];
            }

            // Some reports query more than one column
            if (false !== strpos($_args['columns'], ',')) {
                $_args['columns'] = explode(',', $_args['columns']);
                $_args['columns'] = trim($_args['columns'][0]);
            }

            $permalinks_enabled = get_option('permalink_structure');

            for ($i = 0; $i < $count_page_results; $i++) {
                $row_details       = '';
                $percentage        = '';
                $element_pre_value = '';
                $element_value     = $results[$i][$_args['columns']];

                // Some columns require a special pre-treatment
                switch ($_args['columns']) {
                    case 'browser':
                        if (!empty($results[$i]['user_agent']) && 'on' == wp_slimstat::$settings['show_complete_user_agent_tooltip']) {
                            $element_pre_value = self::inline_help($results[$i]['user_agent'], false);
                        }

                        if (realpath(SLIMSTAT_ANALYTICS_DIR . ('/admin/assets/images/browsers/' . strtolower($results[$i]['browser']) . '.png'))) {
                            $image_url = SLIMSTAT_ANALYTICS_URL . ('/admin/assets/images/browsers/' . strtolower($results[$i]['browser']) . '.png');
                        } else {
                            $image_url = SLIMSTAT_ANALYTICS_URL . ('/admin/assets/images/unk.png');
                        }

                        $element_value = '<img class="slimstat-browser-icon" src="' . $image_url . '" width="16" height="16" alt="' . $results[$i]['browser'] . '" /> ';
                        $element_value .= $results[$i]['browser'] . ((isset($results[$i]['browser_version']) && 0 != intval($results[$i]['browser_version'])) ? ' ' . $results[$i]['browser_version'] : '');
                        break;

                    case 'category':
                        // Only show tag name for Top Tags, and only category name for Recent Categories
                        $term_id  = $results[$i][$_args['columns']];
                        $show_tag = false;
                        if (!empty($_args['where']) && false !== strpos($_args['where'], 'tag')) {
                            $show_tag = true;
                        }
                        if ($show_tag) {
                            $term_obj = get_term($term_id, 'post_tag');
                            if ($term_obj && !is_wp_error($term_obj)) {
                                $element_value = $term_obj->name;
                                $row_details   = __('Tag', 'wp-slimstat') . ': ' . $term_obj->name;
                            } else {
                                $element_value = esc_html($term_id);
                                $row_details   = __('ID', 'wp-slimstat') . ': ' . esc_html($term_id);
                            }
                        } else {
                            $term_obj = get_term($term_id, 'category');
                            if ($term_obj && !is_wp_error($term_obj)) {
                                $element_value = $term_obj->name;
                                $row_details   = __('Category', 'wp-slimstat') . ': ' . $term_obj->name;
                            } else {
                                $element_value = esc_html($term_id);
                                $row_details   = __('ID', 'wp-slimstat') . ': ' . esc_html($term_id);
                            }
                        }
                        break;

                    case 'country':

                        if (realpath(SLIMSTAT_ANALYTICS_DIR . ('/admin/assets/images/flags/' . strtolower($results[$i]['country']) . '.svg'))) {
                            $svg_path      = realpath(SLIMSTAT_ANALYTICS_DIR . ('/admin/assets/images/flags/' . strtolower($results[$i]['country']) . '.svg'));
                            $svg_content   = file_get_contents($svg_path);
                            $element_value = '<span class="slimstat-flag-container">' . $svg_content . '</span>';
                        } else {
                            $image_url     = SLIMSTAT_ANALYTICS_URL . ('/admin/assets/images/unk.png');
                            $element_value = '<img class="slimstat-browser-icon" src="' . $image_url . '" width="16" height="16" alt="' . $results[$i]['country'] . '" />';
                        }

                        $row_details .= __('Code', 'wp-slimstat') . (': ' . $results[ $i ][ 'country' ]);
                        $element_value .= wp_slimstat_i18n::get_string('c-' . $results[$i]['country']);
                        break;

                    case 'id':
                    case 'ip':
                        if ('on' == wp_slimstat::$settings['convert_ip_addresses']) {
                            $element_value = wp_slimstat::gethostbyaddr($results[$i]['ip']);
                        } else {
                            $element_value = $results[$i]['ip'];
                        }
                        break;

                    case 'language':
                        $language_parts     = explode('-', $results[$i][$_args['columns']]);
                        $last_language_part = end($language_parts);
                        if (realpath(SLIMSTAT_ANALYTICS_DIR . ('/admin/assets/images/flags/' . $last_language_part . '.svg'))) {
                            $svg_path      = realpath(SLIMSTAT_ANALYTICS_DIR . ('/admin/assets/images/flags/' . $last_language_part . '.svg'));
                            $svg_content   = file_get_contents($svg_path);
                            $element_value = '<span class="slimstat-flag-container">' . $svg_content . '</span>';
                        } else {
                            $image_url     = SLIMSTAT_ANALYTICS_URL . ('/admin/assets/images/unk.png');
                            $element_value = '<img class="slimstat-browser-icon" src="' . $image_url . '" width="16" height="16" alt="' . $results[$i][$_args['columns']] . '" />';
                        }

                        $row_details = __('Code', 'wp-slimstat') . (': ' . $results[$i][$_args[ 'columns' ]]);
                        $element_value .= wp_slimstat_i18n::get_string('l-' . $results[$i][$_args['columns']]);
                        break;

                    case 'platform':

                        $row_details = __('Code', 'wp-slimstat') . (': ' . $results[$i][$_args[ 'columns' ]]);
                        $icons       = [
                            'android'  => 'and',
                            'chromeos' => 'chr',
                            'ios'      => 'ios',
                            'linux'    => 'lin',
                            'ubuntu'   => 'ubu',
                            'windows'  => 'win',
                            'win7'     => 'win',
                            'win8.1'   => 'win',
                            'win10'    => 'win',
                            'win11'    => 'win',
                            'macos'    => 'mac',
                            'macosx'   => 'mac',
                        ];

                        $platform_parts     = explode('-', $results[$i][$_args['columns']]);
                        $last_platform_part = strtolower(end($platform_parts));

                        if (realpath(SLIMSTAT_ANALYTICS_DIR . ('/admin/assets/images/os/' . $last_platform_part . '.webp'))) {
                            $image_url = SLIMSTAT_ANALYTICS_URL . ('/admin/assets/images/os/' . $last_platform_part . '.webp');
                        } elseif (isset($icons[$last_platform_part]) && realpath(SLIMSTAT_ANALYTICS_DIR . ('/admin/assets/images/os/' . $icons[$last_platform_part] . '.webp'))) {
                            $image_url = SLIMSTAT_ANALYTICS_URL . ('/admin/assets/images/os/' . $icons[$last_platform_part] . '.webp');
                        } else {
                            $image_url = SLIMSTAT_ANALYTICS_URL . ('/admin/assets/images/unk.png');
                        }

                        $element_value = '<img class="slimstat-browser-icon" src="' . $image_url . '" width="16" height="16" alt="' . strtolower($last_platform_part) . '" /> ';
                        $element_value .= wp_slimstat_i18n::get_string($results[$i][$_args['columns']]);
                        $results[$i][$_args['columns']] = str_replace('p-', '', $results[$i][$_args['columns']]);
                        break;

                    case 'referer':
                        $element_value = str_replace(['<', '>'], ['&lt;', '&gt;'], urldecode($results[$i][$_args['columns']]));
                        break;

                    case 'resource':
                        $resource_title = self::get_resource_title($results[$i][$_args['columns']]);
                        if ($resource_title != $results[$i][$_args['columns']]) {
                            $row_details = __('URL', 'wp-slimstat') . ': ' . htmlentities($results[$i][$_args['columns']], ENT_QUOTES, 'UTF-8');
                        }
                        if (!empty($_args['where']) && false !== strpos($_args['where'], 'download')) {
                            $clean_extension = pathinfo(strtolower(parse_url($results[$i][$_args['columns']], PHP_URL_PATH)), PATHINFO_EXTENSION);
                            if (in_array($clean_extension, ['jpg', 'gif', 'png', 'jpeg', 'bmp'])) {
                                $row_details = '<br><img src="' . $results[$i][$_args['columns']] . '" style="width:100px">';
                            }
                        }
                        $element_value = $resource_title;
                        break;

                    case 'screen_width':
                        $element_value = sprintf('%s x %s', $results[ $i ][ $_args[ 'columns' ] ], $results[ $i ][ 'screen_height' ]);
                        break;

                    case 'searchterms':
                        if ('recent' == $_args['type']) {
                            if (isset($results[$i]['referer']) && $results[$i]['referer']) {
                                $domain = parse_url($results[$i]['referer'], PHP_URL_HOST);
                            } else {
                                $domain = __('No referrer', 'wp-slimstat');
                            }

                            $row_details   = __('Referrer', 'wp-slimstat') . (': ' . $domain);
                            $element_value = self::get_search_terms_info($results[$i]['searchterms'], $results[$i]['referer'] ?? '', true);
                        } else {
                            $element_value = htmlentities($results[$i]['searchterms'], ENT_QUOTES, 'UTF-8');
                        }
                        break;

                    case 'username':
                        if (!empty($results[$i]['username'])) {
                            $element_custom_value = get_user_by('login', $results[$i]['username']);
                            if ($element_custom_value) {
                                $element_value = "<a href='" . get_author_posts_url($element_custom_value->ID) . "' class=\"slimstat-author-link\" title='" . esc_attr($element_custom_value->user_login) . "'>";
                                $element_value .= get_avatar($element_custom_value->ID, 18);
                                $element_value .= $results[$i]['username'];
                                $element_value .= '</a>';
                            } else {
                                $image_url     = SLIMSTAT_ANALYTICS_URL . ('/admin/assets/images/unk.png');
                                $element_value = "<a href=\"#\" class='slimstat-author-link'><img src='" . $image_url . sprintf("' class=\"avatar avatar-16 photo\" alt='Unknown'>%s (", $results[$i]['username']) . __('Unknown', 'wp-slimstat') . ')</a>';
                            }
                        } else {
                            $image_url     = SLIMSTAT_ANALYTICS_URL . ('/admin/assets/images/unk.png');
                            $element_value = "<a href=\"#\" class='slimstat-author-link'><img src='" . $image_url . "' class=\"avatar avatar-16 photo\" alt='Unknown'>" . __('Guest', 'wp-slimstat') . '</a>';
                        }

                        if ('on' == wp_slimstat::$settings['show_display_name']) {
                            $element_custom_value = get_user_by('login', $results[$i]['username']);
                            if (is_object($element_custom_value)) {
                                $element_value = $element_custom_value->display_name;
                            }
                        }
                        break;
                    case 'author': // Backward compatibility
                        $author_username = $results[$i]['author'];
                        if ($author_username) {
                            $author = get_user_by('login', $author_username);
                            if ($author) {
                                $author_id     = $author->ID;
                                $element_value = "<a href='" . get_author_posts_url($author_id) . "' class=\"slimstat-author-link\" title='" . esc_attr($author->user_login) . "'>";
                                $element_value .= get_avatar($author_id, 18);
                                $element_value .= $author ? empty($author->display_name) ? $author->user_login : $author->display_name : $results[$i]['author'];
                                $element_value .= '</a>';
                            } else {
                                $image_url     = SLIMSTAT_ANALYTICS_URL . ('/admin/assets/images/unk.png');
                                $element_value = "<a href=\"#\" class='slimstat-author-link'><img src='" . $image_url . sprintf("' class=\"avatar avatar-16 photo\" alt='Unknown'>%s (", $results[$i]['author']) . __('Unknown', 'wp-slimstat') . ')</a>';
                            }
                        } else {
                            $image_url     = SLIMSTAT_ANALYTICS_URL . ('/admin/assets/images/unk.png');
                            $element_value = "<a href=\"#\" class='slimstat-author-link'><img src='" . $image_url . "' class=\"avatar avatar-16 photo\" alt='Unknown'>" . __('Guest', 'wp-slimstat') . '</a>';
                        }
                        break;
                    case 'visit_id':
                        $resource_title = self::get_resource_title($results[$i]['resource']);
                        if ($resource_title != $results[$i]['resource']) {
                            $row_details = htmlentities($results[$i]['resource'], ENT_QUOTES, 'UTF-8');
                        }
                        $element_value = $resource_title;
                        break;
                    default:
                }

                if (is_admin()) {
                    $element_value = "<a class='slimstat-filter-link' href='" . self::fs_url($_args['columns'] . ' ' . $_args['filter_op'] . ' ' . htmlentities(strval($results[$i][$_args['columns']]), ENT_QUOTES, 'UTF-8')) . sprintf("'>%s</a>", $element_value);
                }

                if (!empty($_args['type']) && 'recent' == $_args['type']) {
                    $row_details = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $results[$i]['dt'], true) . ('' === $row_details || '0' === $row_details ? '' : '<br>') . $row_details;
                }

                if (!empty($_args['type']) && 'top' == $_args['type']) {
                    $percentage_value = ((wp_slimstat_db::$pageviews > 0) ? sprintf('%01.2f', (100 * $results[$i]['counthits'] / wp_slimstat_db::$pageviews)) : 0);
                    $counthits        = number_format_i18n($results[$i]['counthits']);
                    $percentage_value = number_format_i18n((float)$percentage_value, 2);

                    if ((!empty($_args['criteria']) && 'swap' == $_args['criteria']) || 'on' == wp_slimstat::$settings['show_hits']) {
                        $percentage  = ' <span>' . $counthits . '</span>';
                        $row_details = __('Hits', 'wp-slimstat') . ': ' . (('outbound_resource' != $_args['columns']) ? $percentage_value . '%' . ('' === $row_details || '0' === $row_details ? '' : '<br>') . $row_details : '');
                    } else {
                        $percentage  = ' <span>' . $percentage_value . '%</span>';
                        $row_details = __('Hits', 'wp-slimstat') . ': ' . $counthits . ('' === $row_details || '0' === $row_details ? '' : '<br>') . $row_details;
                    }
                }

                // Some columns require a special post-treatment
                if ('resource' == $_args['columns'] && !empty($_args['where']) && false === strpos($_args['where'], '404')) {
                    $base_url = '';
                    if (isset($results[$i]['blog_id'])) {
                        $base_url = parse_url(get_site_url($results[$i]['blog_id']));
                        $base_url = $base_url['scheme'] . '://' . $base_url['host'];
                    }
                    $element_value = '<a target="_blank" class="slimstat-font-logout" title="' . __('Open this URL in a new window', 'wp-slimstat') . '" href="' . $base_url . htmlentities($results[$i]['resource'], ENT_QUOTES, 'UTF-8') . '"></a> ' . $base_url . $element_value;
                }

                if ('referer' == $_args['columns'] && !empty($_args['type']) && 'top' == $_args['type']) {
                    $element_url = htmlentities($results[$i]['referer'], ENT_QUOTES, 'UTF-8');
                    if (false === strpos($element_url, 'http')) {
                        $element_url = 'https://' . $element_url;
                    }
                    $element_value = '<a target="_blank" class="slimstat-font-logout" title="' . __('Open this URL in a new window', 'wp-slimstat') . '" href="' . $element_url . '"></a> ' . $element_value;
                }

                if (is_admin() && !empty($results[$i]['ip']) && 'ip' != $_args['columns'] && 'on' != wp_slimstat::$settings['convert_ip_addresses']) {
                    $row_details .= '<br> IP: <a class="slimstat-filter-link" href="' . self::fs_url('ip equals ' . $results[$i]['ip']) . '">' . $results[$i]['ip'] . '</a>' . (empty($results[$i]['other_ip']) ? '' : ' / ' . $results[$i]['other_ip']) . '<a title="WHOIS: ' . $results[$i]['ip'] . '" class="slimstat-font-location-1 whois" href="' . wp_slimstat::$settings['ip_lookup_service'] . $results[$i]['ip'] . '"></a>';
                }
                if ('' !== $row_details && '0' !== $row_details) {
                    $row_details = sprintf("<b class='slimstat-tooltip-content'>%s</b>", $row_details);
                }

                $bar              = '';
                $strip_percentage = trim(strip_tags($percentage));
                if (false !== strpos($strip_percentage, '%')) {
                    $strip_percentage = str_replace('%', '', $strip_percentage);
                }
                if (!empty($strip_percentage)) {
                    $bar = '<span class="slimstat-tooltip-bar-wrap"><span class="slimstat-tooltip-bar" style="width:' . $strip_percentage . '%"></span></span>';
                }
                $row_output = sprintf("<p class='slimstat-tooltip-trigger'>%s%s%s%s %s</p>", $bar, $element_pre_value, $element_value, $percentage, $row_details);

                // Strip all the filter links, if this information is shown on the frontend
                if (!is_admin()) {
                    $row_output = preg_replace('/<a (.*?)>(.*?)<\/a>/', '\\2', $row_output);
                }

                echo $row_output;

            }
            if (!defined('DOING_AJAX') || !DOING_AJAX) {
                echo '</div>';
            }
            echo self::report_pagination($count_page_results, count($all_results));
            if (!defined('DOING_AJAX') || !DOING_AJAX) {
                echo '<div>';
            }
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            die();
        }
        return null;
    }

    public static function show_access_log($_args = [])
    {
        // This function is too long, so it was moved to a separate file
        include(__DIR__ . '/right-now.php');

        if (defined('DOING_AJAX') && DOING_AJAX) {
            die();
        }
    }

    public static function show_chart($args)
    {
        $chart = new \SlimStat\Modules\Chart();
        $chart->showChart($args);
        if (defined('DOING_AJAX') && DOING_AJAX) {
            die();
        }
    }

    public static function show_events($_args = [])
    {
        $all_results = call_user_func($_args['raw'], $_args);

        $results = array_slice(
            $all_results,
            0,
            wp_slimstat::$settings['rows_to_show']
        );

        // Count the results
        $count_page_results = count($results);

        if (0 == $count_page_results) {
            echo '<p class="nodata">' . __('No data to display', 'wp-slimstat') . '</p>';

            if (defined('DOING_AJAX') && DOING_AJAX) {
                die();
            } else {
                return [];
            }
        }

        $blog_url = '';
        if (isset($results[0]['blog_id'])) {
            $blog_url = get_site_url($results[0]['blog_id']);
        }

        foreach ($results as $a_result) {
            echo "<p class='slimstat-tooltip-trigger'>" . $a_result[ 'notes' ];

            if (!empty($a_result['counthits'])) {
                echo sprintf('<span>%s</span>', $a_result[ 'counthits' ]);
            }

            if (!empty($a_result['dt'])) {
                $date_time = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $a_result['dt'], true);
                echo '<b class="slimstat-tooltip-content">' . __('IP', 'wp-slimstat') . ': ' . $a_result['ip'] . '<br/>' . __('Page', 'wp-slimstat') . sprintf(": <a href='%s%s'>%s%s</a><br>", $blog_url, $a_result[ 'resource' ], $blog_url, $a_result[ 'resource' ]) . __('Coordinates', 'wp-slimstat') . sprintf(': %s<br>', $a_result[ 'position' ]) . __('Date', 'wp-slimstat') . (': ' . $date_time);
            }

            echo '</b></p>';
        }
        if (! defined('DOING_AJAX') || ! DOING_AJAX) {
            echo '</div>';
        }
        echo self::report_pagination($count_page_results, count($all_results));
        if (! defined('DOING_AJAX') || ! DOING_AJAX) {
            echo '<div>';
        }
        if (defined('DOING_AJAX') && DOING_AJAX) {
            die();
        }
        return null;
    }

    public static function show_group_by($_args = [])
    {
        $all_results = call_user_func($_args['raw'], $_args);

        $results = [];
        if (is_array($all_results) && count($all_results)) {
            $results = array_slice(
                $all_results,
                0,
                wp_slimstat::$settings['rows_to_show']
            );
        }

        // Count the results
        $count_page_results = count($results);

        if (0 == $count_page_results) {
            echo '<p class="nodata">' . __('No data to display', 'wp-slimstat') . '</p>';

            if (defined('DOING_AJAX') && DOING_AJAX) {
                die();
            } else {
                return 0;
            }
        }

        echo wp_kses_post(wp_slimstat_db::$debug_message);

        foreach ($results as $a_result) {
            if (empty($a_result['counthits'])) {
                $a_result['counthits'] = 0;
            }

            $a_result['resource'] = "<a class='slimstat-font-logout slimstat-tooltip-trigger' target='_blank' title='" . htmlentities(__('Open this URL in a new window', 'wp-slimstat'), ENT_QUOTES, 'UTF-8') . "' href='" . htmlentities($a_result['resource'], ENT_QUOTES, 'UTF-8') . "'></a> <a class='slimstat-filter-link' href='" . wp_slimstat_reports::fs_url('resource equals ' . htmlentities($a_result['resource'], ENT_QUOTES, 'UTF-8')) . "'>" . self::get_resource_title($a_result['resource']) . '</a>';

            $group_markup = [];
            if (!empty($a_result['column_group'])) {
                $exploded_group = explode(';;;', $a_result['column_group']);
                $group_markup   = [];
                foreach ($exploded_group as $a_item) {
                    $user = get_user_by('login', $a_item);
                    if ($user) {
                        $group_markup[] = '<a class="slimstat-filter-link" title="' . __('Filter by element in a group', 'wp-slimstat') . '" href="' . self::fs_url($_args['column_group'] . ' equals ' . $a_item) . '">' . get_avatar($user->ID, 16) . $user->display_name . '</a>';
                    } else {
                        $group_markup[] = '<a class="slimstat-filter-link" title="' . __('Filter by element in a group', 'wp-slimstat') . '" href="' . self::fs_url($_args['column_group'] . ' equals ' . $a_item) . '">' . $a_item . '</a>';
                    }
                }
            }

            echo sprintf('<p>%s <span>%s</span><br/>', $a_result[ 'resource' ], $a_result[ 'counthits' ]) . implode(', ', $group_markup) . '</p>';
        }

        if (! defined('DOING_AJAX') || ! DOING_AJAX) {
            echo '</div>';
        }
        echo self::report_pagination($count_page_results, count($all_results));
        if (! defined('DOING_AJAX') || ! DOING_AJAX) {
            echo '<div>';
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            die();
        }
        return null;
    }

    public static function show_rankings()
    {
        $options  = ['timeout' => 30, 'headers' => ['Accept' => 'application/json']];
        $site_url = parse_url(home_url(), PHP_URL_HOST);
        if (!empty(wp_slimstat_db::$filters_normalized['resource']) && 'equals' == wp_slimstat_db::$filters_normalized['resource'][0]) {
            $site_url .= wp_slimstat_db::$filters_normalized['resource'][1];
        }
        $site_url = urlencode($site_url);

        // Check if we have a valied transient
        if (false === ($rankings = get_transient('slimstat_ranking_values'))) {
            $rankings = [
                'seomoz_domain_authority' => [
                    0,
                    __('Moz Domain Authority', 'wp-slimstat'),
                    __('A normalized 100-point score representing the likelihood of a domain to rank well in search engine results.', 'wp-slimstat'),
                ],
                'seomoz_equity_backlinks' => [
                    0,
                    __('Moz Backlinks', 'wp-slimstat'),
                    __('Number of external equity links to your website.', 'wp-slimstat'),
                ],
                'seomoz_links' => [
                    0,
                    __('Moz Links', 'wp-slimstat'),
                    __('The number of links (external, equity or nonequity or not) to your homepage.', 'wp-slimstat'),
                ],
                'alexa_world_rank' => [
                    0,
                    __('Alexa World Rank', 'wp-slimstat'),
                    __('Alexa is a subsidiary company of Amazon.com which provides commercial web traffic data.', 'wp-slimstat'),
                ],
                'alexa_country_rank' => [
                    0,
                    __('Alexa Country Rank', 'wp-slimstat'),
                    '',
                ],
                'alexa_popularity' => [
                    0,
                    __('Alexa Popularity', 'wp-slimstat'),
                    '',
                ],
            ];

            if (!empty(wp_slimstat::$settings['mozcom_access_id']) && !empty(wp_slimstat::$settings['mozcom_secret_key'])) {
                $expiration_token = time() + 300;
                $binary_signature = @hash_hmac('sha1', wp_slimstat::$settings['mozcom_access_id'] . "\n" . $expiration_token, wp_slimstat::$settings['mozcom_secret_key'], true);
                $binary_signature = urlencode(base64_encode($binary_signature));

                // SeoMoz Equity Links (Backlinks) and MozRank
                $response = @wp_remote_get('https://lsapi.seomoz.com/linkscape/url-metrics/' . $site_url . '?Cols=68719478816&AccessID=' . wp_slimstat::$settings['mozcom_access_id'] . '&Expires=' . $expiration_token . '&Signature=' . $binary_signature, $options);

                if (!is_wp_error($response) && isset($response['response']['code']) && (200 == $response['response']['code']) && !empty($response['body'])) {
                    $response = @json_decode($response['body']);
                    if (is_object($response)) {
                        if (!empty($response->pda)) {
                            $rankings['seomoz_domain_authority'][0] = number_format_i18n(intval($response->pda));
                        }

                        if (!empty($response->ueid)) {
                            $rankings['seomoz_equity_backlinks'][0] = number_format_i18n(intval($response->ueid));
                        }

                        if (!empty($response->uid)) {
                            $rankings['seomoz_links'][0] = number_format_i18n(floatval($response->uid));
                        }
                    }
                }
            }

            // Alexa
            $response = @wp_remote_get('http://data.alexa.com/data?cli=10&dat=snbamz&url=' . $site_url, $options);
            if (!is_wp_error($response) && isset($response['response']['code']) && (200 == $response['response']['code']) && !empty($response['body'])) {
                $response = @simplexml_load_string($response['body']);
                if (is_object($response->SD[1])) {
                    if ($response->SD[1]->POPULARITY && $response->SD[1]->POPULARITY->attributes()) {
                        $popularity = $response->SD[1]->POPULARITY->attributes();
                        if (!empty($popularity)) {
                            $rankings['alexa_popularity'][0] = number_format_i18n(floatval($popularity['TEXT']));
                        }
                    }

                    if ($response->SD[1]->REACH && $response->SD[1]->REACH->attributes()) {
                        $reach = $response->SD[1]->REACH->attributes();
                        if (!empty($reach)) {
                            $rankings['alexa_world_rank'][0] = number_format_i18n(floatval($reach['RANK']));
                        }
                    }

                    if ($response->SD[1]->COUNTRY && $response->SD[1]->COUNTRY->attributes()) {
                        $country = $response->SD[1]->COUNTRY->attributes();
                        if (!empty($country)) {
                            $rankings['alexa_country_rank'][0] = number_format_i18n(floatval($country['RANK']));
                        }
                    } elseif ($response->SD[1]->RANK && $response->SD[1]->RANK->attributes()) {
                        $rank = $response->SD[1]->RANK->attributes();
                        if (!empty($rank)) {
                            $rankings['alexa_country_rank'][0] = number_format_i18n(floatval($rank['DELTA']));
                            $rankings['alexa_country_rank'][1] = __('Alexa Delta', 'wp-slimstat');
                        }
                    }
                }
            }
        }

        foreach ($rankings as $a_ranking) {
            echo '<p>' . self::inline_help($a_ranking[2], false) . $a_ranking[1] . '<span>' . $a_ranking[0] . '</span></p>';
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            die();
        }
    }

    public static function show_world_map()
    {
        $countries     = wp_slimstat_db::get_top('country') ?: [];
        $recent_visits = wp_slimstat_db::get_recent('location', '', '', true, '', 'city');
        $data_points   = [];

        if (!empty($recent_visits)) {
            $recent_visits = array_slice($recent_visits, 0, wp_slimstat::$settings['max_dots_on_map']);
            foreach ($recent_visits as $a_recent_visit) {
                if (!empty($a_recent_visit['city']) && !empty($a_recent_visit['location'])) {
                    [$latitude, $longitude] = explode(',', $a_recent_visit['location']);
                    $clean_city_name        = htmlentities($a_recent_visit['city'], ENT_QUOTES, 'UTF-8');
                    $date_time              = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $a_recent_visit['dt'], true);
                    $data_points[]          = sprintf("{zoomLevel:7,type:'circle',title:'%s<br>%s',latitude:%s,longitude:%s}", $clean_city_name, $date_time, $latitude, $longitude);
                }
            }
        }

        $data_areas    = [];
        $country_stats = [];
        $max           = 0;

        foreach ($countries as $a_country) {
            $code         = strtolower($a_country['country']);
            $visits       = (int) $a_country['counthits'];
            $percent      = (wp_slimstat_db::$pageviews > 0) ? round((100 * $visits / wp_slimstat_db::$pageviews), 2) : 0;
            $country_name = wp_slimstat_i18n::get_string('c-' . $a_country['country'], 'wp-slimstat');

            $data_areas[$code] = $visits;
            $country_stats[]   = [
                'code'    => $code,
                'name'    => $country_name,
                'visits'  => $visits,
                'percent' => $percent,
            ];

            if ($percent > $max) {
                $max = $percent;
            }
        }

        usort($country_stats, fn ($a, $b) => $b['visits'] <=> $a['visits']);
        $top_countries = array_slice($country_stats, 0, 5);

        $path_slimstat = dirname(__FILE__, 2);
        wp_enqueue_script('slimstat_jqvmap', plugins_url('/admin/assets/js/jqvmap/jquery.vmap.min.js', $path_slimstat), ['jquery'], '1.5.1', false);
        wp_enqueue_script('slimstat_jqvmap_world', plugins_url('/admin/assets/js/jqvmap/jquery.vmap.world.min.js', $path_slimstat), ['jquery'], '1.5.1', false);
        ?>

        <div class="map-container">
            <div id="map_slim_p6_01"></div>
            <div class="top-countries-wrap">
                <div class="top-countries">
                    <?php if ([] !== $top_countries): ?>
                        <h4><?php esc_html_e('Top Countries', 'wp-slimstat'); ?></h4>
                    <?php endif; ?>
                    <?php
                    // Settings URL
                    if (!is_network_admin()) {
                        $settings_url = get_admin_url($GLOBALS['blog_id'], 'admin.php?page=slimconfig&amp;tab=');
                    } else {
                        $settings_url = network_admin_url('admin.php?page=slimconfig&amp;tab=');
                    }
        if (('disable' == wp_slimstat::$settings['enable_maxmind'] || !\SlimStat\Services\GeoIP::database_exists())) {
            echo sprintf(__("GeoIP collection is not enabled. Please go to <a href='%s' class='noslimstat'>setting page</a> to enable GeoIP for getting more information and location (country) from the visitor.", 'wp-slimstat'), $settings_url . '2#wp-slimstat-third-party-libraries');
            echo '<br>';
        }
        ?>
                    <?php foreach ($top_countries as $country): ?>
                        <div class="country-bar">
                            <div class="country-flag-container">
                                <?php
                    if (realpath(SLIMSTAT_ANALYTICS_DIR . ('/admin/assets/images/flags/' . strtolower($country['code']) . '.svg'))) {
                        $image_url = SLIMSTAT_ANALYTICS_URL . ('/admin/assets/images/flags/' . strtolower($country['code']) . '.svg');
                        echo '<img class="country-flag" src="' . $image_url . '" width="32" height="32" alt="' . $country['code'] . '" />';
                    } else {
                        $image_url = SLIMSTAT_ANALYTICS_URL . ('/admin/assets/images/unk.png');
                        echo '<img class="country-flag" src="' . $image_url . '" width="32" height="32" alt="' . $country['code'] . '" />';
                    }
                        ?>
                            </div>
                            <strong><?php echo esc_html($country['name']) ?></strong>
                            <div class="bar-container">
                                <div class="bar-fill" style="width: <?php echo $country['percent'] ?>%;"></div>
                            </div>
                            <span><?php echo $country['percent']; ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <script type="text/javascript">
            jQuery(function () {
                jQuery('#map_slim_p6_01').vectorMap({
                    map: 'world_en',
                    backgroundColor: '#fff',
                    hoverOpacity: 0.7,
                    showTooltip: true,
                    normalizeFunction: 'polynomial',
                    values: <?php echo json_encode($data_areas); ?>,
                    enableZoom: true,
                    onLabelShow: function (event, label, code) {
                        const data = <?php echo json_encode($country_stats); ?>;
                        const country = data.find(c => c.code === code);
                        if (country) {
                            label.html( '<canvas></canvas><h3>'  + country.name  + '</h3><p>' + country.visits.toLocaleString() + ' Visitors</p>');
                        } else {
                            label.html( '<canvas></canvas><h3>'  + label.text()  + '</h3><p>0 Visitors</p>');
                        }
                    },
                    scaleColors: ['#fcd7dc', '#E7294B'],
                    borderColor: '#ffffff',
                    color: '#D4D7E2',
                });
            });
        </script>

        <?php
        if (defined('DOING_AJAX') && DOING_AJAX) {
            die();
        }
    }

    public static function get_search_terms_info($_searchterms = '', $_referer = '', $_serp_only = false)
    {
        $query_details     = '';
        $search_terms_info = '';

        if (!$_referer) {
            $_referer = '';
        }

        parse_str($_referer, $query_parse_str);

        if (isset($query_parse_str['source']) && ([] !== $query_parse_str['source'] && ('' !== $query_parse_str['source'] && '0' !== $query_parse_str['source'])) && !$_serp_only) {
            $query_details = __('src', 'wp-slimstat') . (': ' . $query_parse_str[ 'source' ]);
        }

        if (isset($query_parse_str['cd']) && ('' !== $query_parse_str['cd'] && '0' !== $query_parse_str['cd'] && [] !== $query_parse_str['cd'])) {
            $query_details = __('serp', 'wp-slimstat') . (': ' . $query_parse_str[ 'cd' ]);
        }

        if ('' !== $query_details && '0' !== $query_details) {
            $query_details = sprintf('(%s)', $query_details);
        }

        if (!empty($_searchterms) && '_' != $_searchterms) {
            $search_terms_info = htmlentities($_searchterms, ENT_QUOTES, 'UTF-8') . ' ' . $query_details;
        }
        return $search_terms_info;
    }

    /**
     * Generate the HTML that lists all the filters currently used
     */
    public static function get_filters_html($_filters_array = [])
    {
        $filters_html = '';

        if (!empty($_filters_array)) {
            foreach ($_filters_array as $a_filter_label => $a_filter_details) {
                if (!array_key_exists($a_filter_label, wp_slimstat_db::$columns_names) || false !== strpos($a_filter_label, 'no_filter')) {
                    continue;
                }

                $a_filter_value_no_slashes = ('is_empty' == $a_filter_details[0] || 'is_not_empty' == $a_filter_details[0]) ? '' : htmlentities(str_replace('\\', '', $a_filter_details[1]), ENT_QUOTES, 'UTF-8');
                $filters_html .= '<li>' . strtolower(wp_slimstat_db::$columns_names[$a_filter_label][0]) . ' ' . __(str_replace('_', ' ', $a_filter_details[0]), 'wp-slimstat') . sprintf(" %s <a class='slimstat-filter-link slimstat-font-cancel' title='", $a_filter_value_no_slashes) . htmlentities(__('Remove filter for', 'wp-slimstat'), ENT_QUOTES, 'UTF-8') . ' ' . wp_slimstat_db::$columns_names[$a_filter_label][0] . "' href='" . self::fs_url($a_filter_label . ' equals ') . "'></a></li>";
            }
        }

        if ('' !== $filters_html && '0' !== $filters_html) {
            $filters_html = sprintf("<ul class='slimstat-filter-list'>%s</ul><a href='#' id='slimstat-save-filter' class='slimstat-filter-action-button button-secondary noslimstat' data-filter-array='", $filters_html) . htmlentities(json_encode($_filters_array), ENT_QUOTES, 'UTF-8') . "'>" . __('Save', 'wp-slimstat') . '</a>';
        }

        if ('' !== $filters_html && '0' !== $filters_html) {
            $filters_html .= '<a href="' . self::fs_url() . '" id="slimstat-remove-all-filters" class="button-secondary slimstat-filter-action-button noslimstat">' . __('Reset All', 'wp-slimstat') . '</a>';
        }

        return $filters_html;
    }

    public static function fs_url($_filters_string = '')
    {
        // Allow only legitimate requests
        if (!is_admin()) {
            return '';
        }

        $request_uri = admin_url('admin.php');
        $request_uri .= '?page=' . wp_slimstat_admin::$current_screen;

        // Avoid XSS attacks ( why would the owner try to hack into his/her own website though? )
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $parsed_referer = parse_url(sanitize_url(wp_unslash($_SERVER['HTTP_REFERER'])));
            if (!$parsed_referer || (isset($parsed_referer['scheme']) && ('' !== $parsed_referer['scheme'] && '0' !== $parsed_referer['scheme']) && !in_array(strtolower($parsed_referer['scheme']), ['http', 'https']))) {
                return '';
            }
        }

        $fn = wp_slimstat_db::parse_filters($_filters_string);

        // Columns
        if (!empty($fn['columns'])) {
            foreach ($fn['columns'] as $a_key => $a_filter) {
                $request_uri .= sprintf('&amp;fs%%5B%s%%5D=', $a_key) . urlencode($a_filter[0] . ' ' . str_replace('=', '%3D', $a_filter[1]));
            }
        }

        // Date ranges
        if (!empty($fn['date'])) {
            foreach ($fn['date'] as $a_key => $a_filter) {
                if (isset($a_filter)) {
                    $request_uri .= sprintf('&amp;fs%%5B%s%%5D=', $a_key) . urlencode('equals ' . $a_filter);
                }
            }
        }

        // Misc filters
        if (!empty($fn['misc'])) {
            foreach ($fn['misc'] as $a_key => $a_filter) {
                $request_uri .= sprintf('&amp;fs%%5B%s%%5D=', $a_key) . urlencode('equals ' . $a_filter);
            }
        }

        return esc_url($request_uri);
    }

    /**
     * Attempts to convert a permalink into a post title
     */
    public static function get_resource_title($_resource = '')
    {
        if ('on' != wp_slimstat::$settings['convert_resource_urls_to_titles']) {
            return htmlentities(urldecode($_resource), ENT_QUOTES, 'UTF-8');
        }

        // Do we already have this value in our transient cache?
        $cache_index = md5($_resource);
        if (!empty(self::$resource_titles) && !empty(self::$resource_titles[$cache_index])) {
            return self::$resource_titles[$cache_index];
        }

        self::$resource_titles[$cache_index] = $_resource;

        // Is this a post or a page?
        $post_id = url_to_postid($_resource);

        if ($post_id > 0) {
            self::$resource_titles[$cache_index] = the_title_attribute(['post' => $post_id, 'echo' => false]);

            // Encode URLs to avoid XSS attacks
            if (self::$resource_titles[$cache_index] == $_resource) {
                self::$resource_titles[$cache_index] = htmlspecialchars(self::$resource_titles[$cache_index], ENT_QUOTES, 'UTF-8');
            }
        } // Is this a category or tag permalink?
        else {
            $term_names    = [];
            $home_url      = get_home_url();
            $relative_home = parse_url($home_url, PHP_URL_PATH);

            // PHP ^v8 compatibility
            if (!$relative_home) {
                $relative_home = '';
            }

            $all_terms = get_terms('category');
            foreach ($all_terms as $a_term) {
                $term_link = get_term_link($a_term, 'category');
                if (!is_wp_error($term_link) && str_replace($home_url, $relative_home, $term_link) == $_resource) {
                    $term_names[] = $a_term->name;
                }
            }

            $all_terms = get_terms('tag');
            foreach ($all_terms as $a_term) {
                $term_link = get_term_link($a_term, 'tag');
                if (!is_wp_error($term_link) && str_replace($home_url, $relative_home, $term_link) == $_resource) {
                    $term_names[] = $a_term->name;
                }
            }

            if ([] !== $term_names) {
                self::$resource_titles[$cache_index] = implode(',', $term_names);
            } else {
                self::$resource_titles[$cache_index] = htmlspecialchars(self::$resource_titles[$cache_index], ENT_QUOTES, 'UTF-8');
            }
        }

        // Save new value in cache
        set_transient('slimstat_resource_titles', self::$resource_titles, 1800);

        return self::$resource_titles[$cache_index];
    }

    public static function inline_help($_text = '', $_echo = true)
    {
        if (is_admin() && !empty($_text)) {
            $wrapped_text = sprintf("<span class='dashicons dashicons-editor-help slimstat-tooltip-trigger corner'><span class='slimstat-tooltip-content'>%s</span></span>", $_text);
        } else {
            $wrapped_text = '';
        }
        if ($_echo) {
            echo $wrapped_text;
        } else {
            return $wrapped_text;
        }
        return null;
    }

    protected static function _check_args($_args = [])
    {

        // When called from the WP Dashboard, the action passes 2 arguments: post_id (empty) and array of arguments
        $_args = empty($_args[1]) ? $_args[0] : $_args[1];

        if (!is_array($_args)) {
            $_args = [];
        }

        $report_id = 0;

        // Is this an Ajax request?
        if (!empty($_POST['report_id'])) {
            check_ajax_referer('meta-box-order', 'security');
            // Let's make sure the request is coming from an authorized source
            $report_id = $_POST['report_id'];
        } elseif (!empty($_args['id'])) {
            $report_id = $_args['id'];
        }

        if (!empty(self::$reports[$report_id]) && is_array(self::$reports[$report_id])) {
            // Default values
            $_args = array_merge([
                'title'         => '',
                'callback'      => '',
                'callback_args' => [],
                'classes'       => [],
                'locations'     => [],
                'tooltip'       => '',
            ], self::$reports[$report_id]);
        }

        // Default callback args
        if (!empty($_args['callback_args'])) {
            $_args['callback_args'] = array_merge([
                'type'                => '',
                'columns'             => '',
                'where'               => '',
                'having'              => '',
                'as_column'           => '',
                'filter_op'           => 'equals',
                'outer_select_column' => '',
                'aggr_function'       => 'MAX',
                'use_date_filters'    => true,
                'results_per_page'    => wp_slimstat::$settings['rows_to_show'],
                'criteria'            => '',
            ], $_args['callback_args']);
        }

        return $_args;
    }
}
