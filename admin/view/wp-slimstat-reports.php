<?php

class wp_slimstat_reports {
	public static $reports = array(); // Structures to store all the information about what screens and reports are available
	public static $user_reports = array(
		'slimview1' => array(),
		'slimview2' => array(),
		'slimview3' => array(),
		'slimview4' => array(),
		'slimview5' => array(),
		'dashboard' => array(),
		'inactive' => array()
	);
	public static $resource_titles = array();

	/**
	 * Initalize class properties
	 */
	public static function init() {
		// Has the class already been initialized?
		if ( !empty( self::$reports ) ) {
			return true;
		}

		// Include and initialize the API to interact with the database
		include_once( 'wp-slimstat-db.php' );
		wp_slimstat_db::init();

		// Include the localization library
		include_once( plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'languages/index.php' );

		// Define all the reports
		//
		// Parameters
		// - title : report name
		// - callback : function to use to render the report
		// - callback_args : parameters to pass to the function
		// - classes : determine the look and feel of this report ( tall, large, extralarge, full-width )
		// - locations : where should the report appear ( slimview1, .., slimview4, dashboard )
		// - tooltip : contextual help to be displayed on hover

		$chart_tooltip = '<strong>' . __( 'Chart Controls', 'wp-slimstat' ) . '</strong><ul><li>' . __( 'Use your mouse wheel to zoom in and out', 'wp-slimstat' ) . '</li><li>' . __( 'While zooming in, drag the chart to move to a different area', 'wp-slimstat' ) . '</li></ul>';

		self::$reports = array(
			'slim_p7_02' => array(
				'title' => __( 'Access Log', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_access_log' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => '*',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'full-width', 'tall' ),
				'locations' => array( 'slimview1', 'dashboard' ),
				'tooltip' => __( 'Color Codes', 'wp-slimstat' ) . '</strong><p><span class="little-color-box is-search-engine"></span> ' . __( 'From search result page', 'wp-slimstat' ) . '</p><p><span class="little-color-box is-known-visitor"></span> ' . __( 'Has Left Comments', 'wp-slimstat' ) . '</p><p><span class="little-color-box is-known-user"></span> ' . __( 'WP User', 'wp-slimstat' ) . '</p><p><span class="little-color-box is-direct"></span> ' . __( 'Other Human', 'wp-slimstat' ) . '</p><p><span class="little-color-box"></span> ' . __( 'Bot or Crawler', 'wp-slimstat' ) . '</p>'
			),

			'slim_p1_01' => array(
				'title' => __( 'Pageviews', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_chart' ),
				'callback_args' => array(
					'id' => 'slim_p1_01',
					'chart_data' => array(
						'data1' => 'COUNT( ip )',
						'data2' => 'COUNT( DISTINCT ip )'
					),
					'chart_labels' => array(
						__( 'Pageviews', 'wp-slimstat' ),
						__( 'Unique IPs', 'wp-slimstat' )
					)
				),
				'classes' => array( 'extralarge', 'chart' ),
				'locations' => array( 'slimview2', 'dashboard' ),
				'tooltip' => $chart_tooltip
			),
			'slim_p1_03' => array(
				'title' => __( 'At a Glance', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'raw' => array( 'wp_slimstat_db', 'get_overview_summary' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview2', 'dashboard' )
			),
			'slim_p1_04' => array(
				'title' => __( 'Currently Online', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'ip',
					'where' => 'dt_out > '. ( date_i18n( 'U' ) - 300 ) . ' OR dt > '. ( date_i18n( 'U' ) - 300 ),
					'use_date_filters' => false,
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview2', 'dashboard' )
			),
			'slim_p1_06' => array(
				'title' => __( 'Recent Search Terms', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'searchterms',
					'where' => 'searchterms <> "_" AND searchterms <> "" AND searchterms IS NOT NULL',
					'more_columns' => 'referer, resource',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview2', 'slimview5' ),
				'tooltip' => __( 'Keywords used by your visitors to find your website on a search engine.', 'wp-slimstat' )
			),
			'slim_p1_08' => array(
				'title' => __( 'Top Web Pages', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'resource',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview2', 'dashboard' ),
				'tooltip' => __( 'Here a "page" is not just a WordPress page type, but any webpage on your site, including posts, products, categories, and any other custom post type. For example, you can set the corresponding filter where Resource Content Type equals cpt:you_cpt_slug_here to get top web pages for a specific custom post type you have.', 'wp-slimstat' )
			),
			'slim_p1_10' => array(
				'title' => __( 'Top Referring Domains', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'REPLACE( SUBSTRING_INDEX( ( SUBSTRING_INDEX( ( SUBSTRING_INDEX( referer, "://", -1 ) ), "/", 1 ) ), ".", -5 ), "www.", "" )',
					'as_column' => 'referer',
					'filter_op' => 'contains',
					'where' => 'referer NOT LIKE "%' . str_replace( 'www.', '', parse_url( home_url(), PHP_URL_HOST ) ) . '%"',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview2', 'slimview5', 'dashboard' )
			),
			'slim_p1_11' => array(
				'title' => __( 'Top Known Visitors', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'username',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview2', 'dashboard' )
			),
			'slim_p1_12' => array(
				'title' => __( 'Top Search Terms', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'searchterms',
					'where' => 'searchterms <> "_" AND searchterms <> "" AND searchterms IS NOT NULL',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview2', 'slimview4', 'slimview5', 'dashboard' )
			),
			'slim_p1_13' => array(
				'title' => __( 'Top Countries', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'country',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview2', 'slimview3', 'slimview5', 'dashboard' ),
				'tooltip' => __( 'You can configure Slimstat to not track specific Countries by setting the corresponding filter in Slimstat > Settings > Exclusions.', 'wp-slimstat' )
			),
			'slim_p1_15' => array(
				'title' => __( 'Rankings', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_rankings' ),
				'callback_args' => array(
					'id' => 'slim_p1_15'
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' ),
				'tooltip' => __( "Slimstat retrieves live information from Alexa, Facebook and Mozscape, to measures your site's rankings. Values are updated every 12 hours. Please enter your personal access ID in the settings to access your personalized Mozscape data.", 'wp-slimstat' )
			),
			'slim_p1_17' => array(
				'title' => __( 'Top Language Families', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'SUBSTRING( language, 1, 2 )',
					'as_column' => 'language',
					'filter_op' => 'contains',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' )
			),
			'slim_p1_18' => array(
				'title' => __( 'Users Currently Online', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'username',
					'where' => 'dt_out > '. ( date_i18n( 'U' ) - 300 ) . ' OR dt > '. ( date_i18n( 'U' ) - 300 ),
					'use_date_filters' => false,
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview2', 'dashboard' ),
				'tooltip' => __( 'When visitors leave a comment on your blog, WordPress assigns them a cookie. Slimstat leverages this information to identify returning visitors. Please note that visitors also include registered users.', 'wp-slimstat' )
			),
			'slim_p1_19_01' => array(
				'title' => __( 'Search Terms', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_chart' ),
				'callback_args' => array(
					'id' => 'slim_p1_19_01',
					'chart_data' => array(
						'data1' => 'COUNT( searchterms )',
						'data2' => 'COUNT( DISTINCT searchterms )',
						'where' => 'searchterms <> "_" AND searchterms IS NOT NULL AND searchterms <> ""'
					),
					'chart_labels' => array(
						__( 'Search Terms', 'wp-slimstat' ),
						__( 'Unique Terms', 'wp-slimstat' )
					)
				),
				'classes' => array( 'extralarge', 'chart' ),
				'locations' => array( 'slimview2' ),
				'tooltip' => $chart_tooltip
			),
			'slim_p1_20' => array(
				'title' => __('Top Referring URLs', 'wp-slimstat'),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'referer',
					'where' => 'referer NOT LIKE "%' . str_replace( 'www.', '', parse_url( home_url(), PHP_URL_HOST ) ) . '%"',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview2', 'slimview5', 'dashboard' )
			),

			'slim_p2_01' => array(
				'title' => __( 'Human Visits', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_chart' ),
				'callback_args' => array(
					'id' => 'slim_p2_01',
					'chart_data' => array(
						'data1' => 'COUNT( DISTINCT visit_id )',
						'data2' => 'COUNT( DISTINCT ip )',
						'where' => '(visit_id > 0 AND browser_type <> 1)'
					),
					'chart_labels' => array(
						__( 'Visits', 'wp-slimstat' ),
						__( 'Unique IPs', 'wp-slimstat' )
					)
				),
				'classes' => array( 'extralarge', 'chart' ),
				'locations' => array( 'slimview3' ),
				'tooltip' => $chart_tooltip
			),
			'slim_p2_02' => array(
				'title' => __( 'Audience Overview', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'raw' => array( 'wp_slimstat_db', 'get_visitors_summary' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview3', 'dashboard' ),
				'tooltip' => __( 'Where not otherwise specified, the metrics in this report are referred to human visitors.', 'wp-slimstat' )
			),
			'slim_p2_03' => array(
				'title' => __( 'Top Languages', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'language',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview3' )
			),
			'slim_p2_04' => array(
				'title' => __( 'Top User Agents', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'browser, browser_version',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview3', 'dashboard' ),
				'tooltip' => __( 'This report includes all types of clients, both bots and humans.', 'wp-slimstat' )
			),
			'slim_p2_05' => array(
				'title' => __( 'Top Service Providers', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'ip',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'extralarge' ),
				'locations' => array( 'inactive' ),
				'tooltip' => __( 'Internet Service Provider: a company which provides other companies or individuals with access to the Internet. Your DSL or cable internet service is provided to you by your ISP.<br><br>You can ignore specific IP addresses by setting the corresponding filter under Settings > Slimstat > Filters.', 'wp-slimstat' )
			),
			'slim_p2_06' => array(
				'title' => __( 'Top Operating Systems', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'platform',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' ),
				'tooltip' => __( 'Internet Service Provider: a company which provides other companies or individuals with access to the Internet. Your DSL or cable internet service is provided to you by your ISP.<br><br>You can ignore specific IP addresses by setting the corresponding filter under Settings > Slimstat > Filters.', 'wp-slimstat' )
			),
			'slim_p2_07' => array(
				'title' => __( 'Top Screen Resolutions', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'screen_width, screen_height',
					'where' => 'screen_width <> 0 AND screen_height <> 0',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview3', 'dashboard' )
			),
			'slim_p2_08' => array(
				'title' => __( 'Top Viewport Sizes', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'resolution',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal', ),
				'locations' => array( 'inactive' )
			),
			'slim_p2_09' => array(
				'title' => __( 'Browser Capabilities', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'raw' => array( 'wp_slimstat_db', 'get_plugins' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' )
			),
			'slim_p2_12' => array(
				'title' => __( 'Visit Duration', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'raw' => array( 'wp_slimstat_db', 'get_visits_duration' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview3' ),
				'tooltip' => __( 'All values represent the percentages of pageviews within the corresponding time range.', 'wp-slimstat' )
			),
			'slim_p2_13' => array(
				'title' => __( 'Recent Countries', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'country',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' )
			),
			'slim_p2_14' => array(
				'title' => __( 'Recent Viewport Sizes', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'resolution',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' )
			),
			'slim_p2_15' => array(
				'title' => __( 'Recent Operating Systems', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'platform',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' )
			),
			'slim_p2_16' => array(
				'title' => __( 'Recent Browsers', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'browser, browser_version',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' )
			),
			'slim_p2_17' => array(
				'title' => __( 'Recent Languages', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'language',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' )
			),
			'slim_p2_18' => array(
				'title' => __( 'Top Browser Families', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'browser',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' ),
				'tooltip' => __( 'This report shows you what user agent families (no version considered) are popular among your visitors.', 'wp-slimstat' )
			),
			'slim_p2_19' => array(
				'title' => __( 'Top OS Families', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'CONCAT( "p-", SUBSTRING( platform, 1, 3 ) )',
					'as_column' => 'platform',
					'filter_op' => 'contains',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview3' ),
				'tooltip' => __( 'This report shows you what operating system families (no version considered) are popular among your visitors.', 'wp-slimstat' )
			),
			'slim_p2_20' => array(
				'title' => __( 'Recent Users', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'username',
					'where' => 'notes LIKE "%user:%"',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview3' )
			),
			'slim_p2_21' => array(
				'title' => __( 'Top Users', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'username',
					'where' => 'notes LIKE "%user:%"',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview3', 'dashboard' )
			),
			'slim_p2_22_01' => array(
				'title' => __( 'Users', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_chart' ),
				'callback_args' => array(
					'id' => 'slim_p2_22_01',
					'chart_data' => array(
						'data1' => 'COUNT( username )',
						'data2' => 'COUNT( DISTINCT username )'
					),
					'chart_labels' => array(
						__( 'Users', 'wp-slimstat' ),
						__( 'Unique Users', 'wp-slimstat' )
					)
				),
				'classes' => array( 'extralarge', 'chart' ),
				'locations' => array( 'slimview3' ),
				'tooltip' => $chart_tooltip
			),
			'slim_p2_24' => array(
				'title' => __( 'Top Bots', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'browser, browser_version',
					'where' => 'browser_type = 1',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview3' )
			),
			'slim_p2_25' => array(
				'title' => __( 'Top Human Browsers', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'browser, browser_version',
					'where' => 'browser_type != 1',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview3' )
			),

			'slim_p3_01' => array(
				'title' => __( 'Traffic Sources', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_chart' ),
				'callback_args' => array(
					'id' => 'slim_p3_01',
					'chart_data' => array(
						'data1' => 'COUNT( DISTINCT referer )',
						'data2' => 'COUNT( DISTINCT ip )',
						'where' => '(referer IS NOT NULL AND referer NOT LIKE "%' . home_url() . '%")'
					),
					'chart_labels' => array(
						__( 'Domains', 'wp-slimstat' ),
						__( 'Unique IPs', 'wp-slimstat' )
					)
				),
				'classes' => array( 'extralarge', 'chart' ),
				'locations' => array( 'slimview5' ),
				'tooltip' => $chart_tooltip
			),
			'slim_p3_02' => array(
				'title' => __( 'Traffic Summary', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'raw' => array( 'wp_slimstat_db', 'get_traffic_sources_summary' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview5' )
			),
			'slim_p3_06' => array(
				'title' => __( 'Top Referring Search Engines', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'REPLACE( SUBSTRING_INDEX( SUBSTRING_INDEX( SUBSTRING_INDEX( referer, "://", -1 ), "/", 1 ), ".", -5 ), "www.", "" )',
					'as_column' => 'referer',
					'filter_op' => 'contains',
					'where' => 'searchterms IS NOT NULL AND searchterms <> "" AND searchterms <> "_" AND referer NOT LIKE "%' . str_replace( 'www.', '', parse_url( home_url(), PHP_URL_HOST ) ) . '%"',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview5', 'dashboard' )
			),

			'slim_p4_01' => array(
				'title' => __( 'Recent Outbound Links', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'outbound_resource',
					'raw' => array( 'wp_slimstat_db', 'get_recent_outbound' )
				),
				'classes' => array( 'large' ),
				'locations' => array( 'slimview4' ),
				'tooltip' => ''
			),
			'slim_p4_02' => array(
				'title' => __( 'Recent Posts', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'TRIM( TRAILING "/" FROM resource )',
					'as_column' => 'resource',
					'where' => 'content_type = "post"',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview4' )
			),
			'slim_p4_04' => array(
				'title' => __( 'Recent Feeds', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'resource',
					'where' => '(resource LIKE "%/feed%" OR resource LIKE "%?feed=>%" OR resource LIKE "%&feed=>%" OR content_type LIKE "%feed%")',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' )
			),
			'slim_p4_05' => array(
				'title' => __( 'Recent Pages Not Found', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'resource',
					'where' => '(resource LIKE "[404]%" OR content_type LIKE "%404%")',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview4' )
			),
			'slim_p4_06' => array(
				'title' => __( 'Recent Internal Searches', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'searchterms',
					'where' => 'content_type LIKE "%search%" AND searchterms <> "" AND searchterms IS NOT NULL',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview4' ),
				'tooltip' => __( "Searches performed using WordPress' built-in search functionality.", 'wp-slimstat' )
			),
			'slim_p4_07' => array(
				'title' => __( 'Top Categories', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'category',
					'where' => 'content_type LIKE "%category%"',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview4', 'dashboard' )
			),
			'slim_p4_09' => array(
				'title' => __( 'Top Downloads', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'resource',
					'where' => 'content_type = "download"',
					'raw' => array( 'wp_slimstat_db', 'get_top' ),
					'criteria' => 'swap'
				),
				'classes' => array( 'large' ),
				'locations' => array( 'slimview4' ),
				'tooltip' => __( 'You can configure Slimstat to track specific file extensions as downloads.', 'wp-slimstat' )
			),
			'slim_p4_10' => array(
				'title' => __( 'Recent Custom Events', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_events' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'notes',
					'raw' => array( 'wp_slimstat_db', 'get_recent_events' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' ),
				'tooltip' => __( 'This report lists any <em>event</em> occurred on your website. Please refer to the FAQ for more information on how to use this functionality.', 'wp-slimstat' )
			),
			'slim_p4_11' => array(
				'title' => __( 'Top Posts', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'resource',
					'where' => 'content_type = "post"',
					'raw' => array( 'wp_slimstat_db', 'get_top' ),
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview4' )
			),
			'slim_p4_12' => array(
				'title' => __( 'Top Custom Events', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_events' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'notes',
					'raw' => array( 'wp_slimstat_db', 'get_top_events' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview4' ),
				'tooltip' => __( 'This report lists any <em>event</em> occurred on your website. Please refer to the FAQ for more information on how to use this functionality.', 'wp-slimstat' )
			),
			'slim_p4_13' => array(
				'title' => __( 'Top Internal Searches', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'searchterms',
					'where' => 'content_type LIKE "%search%" AND searchterms <> "" AND searchterms IS NOT NULL',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview4' )
			),
			'slim_p4_15' => array(
				'title' => __( 'Recent Categories', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'TRIM( TRAILING "/" FROM resource )',
					'as_column' => 'resource',
					'where' => '(content_type = "category" OR content_type = "tag")',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' )
			),
			'slim_p4_16' => array(
				'title' => __( 'Top Pages Not Found', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'resource',
					'where' => 'content_type LIKE "%404%"',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview4' )
			),
			'slim_p4_18' => array(
				'title' => __( 'Top Authors', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'author',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview4', 'dashboard' )
			),
			'slim_p4_19' => array(
				'title' => __( 'Top Tags', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'category',
					'where' => '(content_type LIKE "%tag%")',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' )
			),
			'slim_p4_20' => array(
				'title' => __( 'Recent Downloads', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'resource',
					'where' => 'content_type = "download"',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'large' ),
				'locations' => array( 'inactive' )
			),
			'slim_p4_21' => array(
				'title' => __( 'Top Outbound Links', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'outbound_resource',
					'raw' => array( 'wp_slimstat_db', 'get_top_outbound' ),
					'criteria' => 'swap'
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview4', 'dashboard' ),
			),
			'slim_p4_22' => array(
				'title' => __( 'Your Website', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'raw' => array( 'wp_slimstat_db', 'get_your_blog' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'inactive' ),
				'tooltip' => __( 'Your content at a glance: posts, comments, pingbacks, etc. Please note that this report is not affected by the filters set here above.', 'wp-slimstat' )
			),
			'slim_p4_23' => array(
				'title' => __( 'Top Bounce Pages', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'TRIM( TRAILING "/" FROM resource )',
					'as_column' => 'resource',
					'where' => 'content_type <> "404"',
					'having' => 'HAVING COUNT(visit_id) = 1',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview4' )
			),
			'slim_p4_24' => array(
				'title' => __( 'Top Exit Pages', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'visit_id',
					'outer_select_column' => 'resource',
					'aggr_function' => 'MAX',
					'raw' => array( 'wp_slimstat_db', 'get_top_aggr' )
				),
				'classes' => array( 'large' ),
				'locations' => array( 'slimview4', 'dashboard' )
			),
			'slim_p4_25' => array(
				'title' => __( 'Top Entry Pages', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'visit_id',
					'outer_select_column' => 'resource',
					'aggr_function' => 'MIN',
					'raw' => array( 'wp_slimstat_db', 'get_top_aggr' )
				),
				'classes' => array( 'large' ),
				'locations' => array( 'slimview4' )
			),
			'slim_p4_26_01' => array(
				'title' => __( 'Pages with Outbound Links', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_chart' ),
				'callback_args' => array(
					'id' => 'slim_p4_26_01',
					'chart_data' => array(
						'data1' => 'COUNT( outbound_resource )',
						'data2' => 'COUNT( DISTINCT outbound_resource )'
					),
					'chart_labels' => array(
						__( 'Outbound Links', 'wp-slimstat' ),
						__( 'Unique Outbound', 'wp-slimstat' )
					)
				),
				'classes' => array( 'extralarge', 'chart' ),
				'locations' => array( 'slimview4' ),
				'tooltip' => $chart_tooltip
			),
			'slim_p4_27' => array(
				'title' => __( 'Users by Page', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_group_by' ),
				'callback_args' => array(
					'column_group' => 'username',
					'group_by' => 'resource',
					'raw' => array( 'wp_slimstat_db', 'get_group_by' )
				),
				'classes' => array( 'large' ),
				'locations' => array( 'slimview4' )
			),
			'slim_p6_01' => array(
				'title' => __( 'World Map', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_world_map' ),
				'callback_args' => array(
					'id' => 'slim_p6_01'
				),
				'classes' => array( 'full-width', 'tall' ),
				'locations' => array( 'slimview1' ),
				'tooltip' => __( 'Dots on the map represent the most recent pageviews geolocated by City. This feature is only available by enabling the corresponding precision level in the settings.', 'wp-slimstat' )
			)
		);

		if ( wp_slimstat::$settings[ 'geolocation_country' ] != 'on' ) {
			self::$reports[ 'slim_p2_23' ] = array(
				'title' => __( 'Top Cities', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'city',

					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'locations' => array( 'slimview3' )
			);
		}

		// Allow third party tools to manipulate this list here above: please use unique report IDs that don't interfere with built-in ones, if you add your own custom report
		self::$reports = apply_filters( 'slimstat_reports_info', self::$reports );
		$merge_reports = array_keys( self::$reports );

		// Do we have any new reports not listed in this user's settings?
		if ( class_exists( 'wp_slimstat_admin' ) && !empty( wp_slimstat_admin::$meta_user_reports ) && is_array( wp_slimstat_admin::$meta_user_reports ) ) {
			$flat_user_reports = array_filter( explode( ',', implode( ',', wp_slimstat_admin::$meta_user_reports ) ) );
			$merge_reports = array_diff( array_filter( array_keys( self::$reports ) ), $flat_user_reports );

			// Now let's explode all the lists
			foreach ( wp_slimstat_admin::$meta_user_reports as $a_location => $a_report_list ) {
				self::$user_reports[ $a_location ] = explode( ',', $a_report_list );
			}
		}

		foreach ( $merge_reports as $a_report_id ) {
			if ( !empty( self::$reports[ $a_report_id ][ 'locations' ] ) && is_array( self::$reports[ $a_report_id ][ 'locations' ] ) ) {
				foreach ( self::$reports[ $a_report_id ][ 'locations' ] as $a_report_location ) {
					if ( !in_array( $a_report_id, self::$user_reports[ $a_report_location ] ) ) {
						self::$user_reports[ $a_report_location ][] = $a_report_id;
					}
				}
			}
		}

		// We store page titles in a transient for improved performance
		if ( empty( $_REQUEST[ 'page' ] ) || !in_array( $_REQUEST[ 'page' ], array( 'slimlayout', 'slimadddons' ) ) ) {
			self::$resource_titles = get_transient( 'slimstat_resource_titles' );
			if ( self::$resource_titles === false ) {
				self::$resource_titles = array();
			}
		}
	}
	// end init

	public static function report_header( $_report_id = '' ) {
		if ( empty( self::$reports[ $_report_id ] ) ) {
			return false;
		}

		$header_classes =  !empty( self::$reports[ $_report_id ][ 'classes' ] ) ? implode( ' ', self::$reports[ $_report_id ][ 'classes' ] ) : '';
		$header_buttons = '';
		$header_tooltip = '';
		$widget_title = '';

		// Don't show the header buttons on the frontend
		if ( is_admin() ) {
			// Show the refresh button only if the time range is not in the past
			if ( wp_slimstat_db::$filters_normalized[ 'utime' ][ 'end' ] >= date_i18n( 'U' ) - 300 ) {
				$header_buttons = '<a class="noslimstat refresh slimstat-font-arrows-cw" title="'.__('Refresh','wp-slimstat').'" href="'.self::fs_url().'"></a>';
			}

			// Allow third-party code to add more buttons 
			$header_buttons = apply_filters( 'slimstat_report_header_buttons', $header_buttons, $_report_id );
			$header_buttons = '<div class="slimstat-header-buttons">' . $header_buttons . '</div>';
			$header_tooltip = !empty( self::$reports[ $_report_id ][ 'tooltip' ] ) ? '<i class="slimstat-tooltip-trigger corner"><span class="slimstat-tooltip-content">' . self::$reports[ $_report_id ][ 'tooltip' ] . '</span></i>' : '';
			$widget_title = "<h3 data-report-id='{$_report_id}'>" . self::$reports[ $_report_id ][ 'title' ] . "{$header_tooltip}</h3>";
		}

		echo "<div class='postbox $header_classes' id='$_report_id'>{$header_buttons} $widget_title <div class='inside'>";
	}

	public static function report_footer(){
		echo '</div></div>';
	}

	public static function report_pagination( $_count_page_results = 0, $_count_all_results = 0, $_show_refresh_countdown = false, $_results_per_page = -1 ) {
		if ( !is_admin() ) {
			return '';
		}

		$_results_per_page = ( $_results_per_page < 0 ) ? wp_slimstat::$settings[ 'rows_to_show' ] : $_results_per_page;

		$endpoint = min( $_count_all_results, wp_slimstat_db::$filters_normalized[ 'misc' ][ 'start_from' ] + $_results_per_page );
		$pagination_buttons = '';
		$direction_prev = is_rtl() ? 'right' : 'left';
		$direction_next = is_rtl() ? 'left' : 'right';

		if ( $endpoint + $_results_per_page < $_count_all_results && $_count_page_results > 0 ) {
			$startpoint = $_count_all_results - $_count_all_results % $_results_per_page;
			if ( $startpoint == $_count_all_results ) {
				$startpoint -= $_results_per_page;
			}
			$pagination_buttons .= '<a class="refresh slimstat-font-angle-double-' . $direction_next . '" href="' . wp_slimstat_reports::fs_url( 'start_from equals ' . $startpoint ) . '"></a> ';
		}
		if ( $endpoint < $_count_all_results && $_count_page_results > 0 ) {
			$startpoint = wp_slimstat_db::$filters_normalized[ 'misc' ][ 'start_from' ] + $_results_per_page;
			$pagination_buttons .= '<a class="refresh slimstat-font-angle-' . $direction_next . '" href="' . wp_slimstat_reports::fs_url( 'start_from equals ' . $startpoint ) . '"></a> ';
		}
		if ( wp_slimstat_db::$filters_normalized[ 'misc' ][ 'start_from' ] > 0 ) {
			$startpoint = ( wp_slimstat_db::$filters_normalized[ 'misc' ][ 'start_from' ] > $_results_per_page ) ? wp_slimstat_db::$filters_normalized[ 'misc' ][ 'start_from' ] - $_results_per_page : 0;
			$pagination_buttons .= '<a class="refresh slimstat-font-angle-' . $direction_prev . '" href="' . wp_slimstat_reports::fs_url( 'start_from equals ' . $startpoint ) . '"></a> ';
		}
		if ( wp_slimstat_db::$filters_normalized[ 'misc' ][ 'start_from' ] - $_results_per_page > 0 ) {
			$pagination_buttons .= '<a class="refresh slimstat-font-angle-double-' . $direction_prev . '" href="' . wp_slimstat_reports::fs_url( 'start_from equals 0' ) . '"></a> ';
		}

		$pagination = '<p class="pagination">' . sprintf( __( 'Results %s - %s of %s', 'wp-slimstat' ), number_format_i18n( wp_slimstat_db::$filters_normalized[ 'misc' ][ 'start_from' ] + 1 ), number_format_i18n( $endpoint ), number_format_i18n( $_count_all_results ) . ( ( $_count_all_results == wp_slimstat::$settings[ 'limit_results' ] ) ? '+' : '' ) );

		if ( $_show_refresh_countdown && wp_slimstat::$settings[ 'refresh_interval' ] > 0 && wp_slimstat_db::$filters_normalized[ 'utime' ][ 'end' ] >= date_i18n( 'U' ) - 300 ) {
			$pagination .= ' [' . __( 'Refresh in', 'wp-slimstat' ) . ' <i class="refresh-timer"></i>]';
		}
		$pagination .= $pagination_buttons.'</p>';

		return $pagination;
	}

	public static function callback_wrapper() {
		$_args = self::_check_args( func_get_args() );
		if ( !empty( $_args ) && !empty( $_args[ 'callback' ] ) ) {
			call_user_func( $_args[ 'callback' ] , $_args[ 'callback_args' ] );
		}
	}

	public static function raw_results_to_html( $_args = array() ) {
		if ( wp_slimstat::$settings[ 'async_load' ] == 'on' && ( !defined( 'DOING_AJAX' ) || !DOING_AJAX ) && empty( $_args[ 'is_widget' ] ) ) {
			return '';
		}

		wp_slimstat_db::$debug_message = '';

		$all_results = call_user_func( $_args[ 'raw' ] , $_args );

		echo wp_slimstat_db::$debug_message;

		// Some reports don't need any kind of pre/post-processing, we just display the data contained in the array
		if ( empty( $_args[ 'columns' ] ) ) {
			foreach ( $all_results as $a_result ) {
				echo '<p>';

				if ( !empty( $a_result[ 'tooltip' ] ) ) {
					self::inline_help( $a_result[ 'tooltip' ] );
				}

				echo "{$a_result[ 'metric' ]} <span>{$a_result[ 'value' ]}</span>";

				if ( !empty( $a_result[ 'details' ] ) ) {
					echo "<b class='slimstat-tooltip-content'>{$a_result[ 'details' ]}</b>";
				}

				echo '</p>';
			}
		}
		else {
			$results = array_slice(
				$all_results,
				wp_slimstat_db::$filters_normalized[ 'misc' ][ 'start_from' ],
				wp_slimstat::$settings[ 'rows_to_show' ]
			);

			// Count the results
			$count_page_results = count( $results );

			if ( $count_page_results == 0 ) {
				echo '<p class="nodata">' . __( 'No data to display', 'wp-slimstat' ) . '</p>';

				if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
					die();
				}
				else{
					return array();
				}
			}

			// Some reports use aliases for column names
			if ( !empty( $_args[ 'as_column' ] ) ) {
				$_args[ 'columns' ] = $_args[ 'as_column' ];
			}
			else if ( !empty( $_args[ 'outer_select_column' ] ) ) {
				$_args[ 'columns' ] = $_args[ 'outer_select_column' ];
			}

			// Some reports query more than one column
			if ( strpos( $_args[ 'columns' ], ',' ) !== false ) {
				$_args[ 'columns' ] = explode( ',', $_args[ 'columns' ] );
				$_args[ 'columns' ] = trim( $_args[ 'columns' ][ 0 ] );
			}

			echo self::report_pagination( $count_page_results, count( $all_results ) );

			$permalinks_enabled = get_option( 'permalink_structure' );

			for ( $i=0; $i<$count_page_results; $i++ ) {
				$row_details = $percentage = '';
				$element_pre_value = '';
				$element_value = $results[ $i ][ $_args[ 'columns' ] ];

				// Some columns require a special pre-treatment
				switch ( $_args[ 'columns' ] ){
					case 'browser':
						if ( !empty( $results[ $i ][ 'user_agent' ] ) && wp_slimstat::$settings[ 'show_complete_user_agent_tooltip' ] == 'on' ) {
							$element_pre_value = self::inline_help( $results[ $i ][ 'user_agent' ], false );
						}
						$element_value = $results[ $i ][ 'browser' ] . ( ( isset( $results[ $i ][ 'browser_version' ] ) && intval( $results[ $i ][ 'browser_version' ] ) != 0 ) ? ' ' . $results[ $i ][ 'browser_version' ] : '' );
						break;

					case 'category':
						$row_details = __( 'Category ID', 'wp-slimstat' ) . ": {$results[ $i ][ $_args[ 'columns' ] ]}";
						$element_value = get_cat_name( $results[ $i ][ $_args[ 'columns' ] ] );
						break;

					case 'country':
						$row_details .= __( 'Code', 'wp-slimstat' ) . ": {$results[ $i ][ 'country' ]}";
						$element_value = wp_slimstat_i18n::get_string( 'c-' . $results[ $i ][ 'country' ] );
						break;

					case 'id':
					case 'ip':
						if ( wp_slimstat::$settings[ 'convert_ip_addresses' ] == 'on' ) {
							$element_value = wp_slimstat::gethostbyaddr( $results[ $i ][ 'ip' ] );
						}
						else{
							$element_value = $results[ $i ][ 'ip' ];
						}
						break;

					case 'language':
						$row_details = __( 'Code', 'wp-slimstat' ) . ": {$results[ $i ][ $_args[ 'columns' ] ]}";
						$element_value = wp_slimstat_i18n::get_string( 'l-' . $results[ $i ][ $_args[ 'columns' ] ] );
						break;

					case 'platform':
						$row_details = __( 'Code', 'wp-slimstat' ).": {$results[ $i ][ $_args[ 'columns' ] ]}";
						$element_value = wp_slimstat_i18n::get_string( $results[ $i ][ $_args[ 'columns' ] ] );
						$results[ $i ][ $_args[ 'columns' ] ] = str_replace( 'p-', '', $results[ $i ][ $_args[ 'columns' ] ] );
						break;

					case 'referer':
						$element_value = str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), urldecode( $results[ $i ][ $_args[ 'columns' ] ] ) );
						break;

					case 'resource':
						$resource_title = self::get_resource_title( $results[ $i ][ $_args[ 'columns' ] ] );
						if ( $resource_title != $results[ $i ][ $_args[ 'columns' ] ] ) {
							$row_details = __( 'URL', 'wp-slimstat' ) . ': ' . htmlentities( $results[ $i ][ $_args[ 'columns' ] ], ENT_QUOTES, 'UTF-8' );
						}
						if ( !empty( $_args[ 'where' ] ) && strpos( $_args[ 'where' ], 'download' ) !== false ) {
							$clean_extension = pathinfo( strtolower( parse_url( $results[ $i ][ $_args[ 'columns' ] ], PHP_URL_PATH ) ), PATHINFO_EXTENSION );
							if ( in_array( $clean_extension, array( 'jpg', 'gif', 'png', 'jpeg', 'bmp' ) ) ) {
								$row_details = '<br><img src="' . $results[ $i ][ $_args[ 'columns' ] ] . '" style="width:100px">';
							}
						}
						$element_value = $resource_title;
						break;

					case 'screen_width':
						$element_value = "{$results[ $i ][ $_args[ 'columns' ] ]} x {$results[ $i ][ 'screen_height' ]}";
						break;

					case 'searchterms':
						if ( $_args[ 'type' ] == 'recent' ) {
							$domain = parse_url( $results[ $i ][ 'referer' ], PHP_URL_HOST );

							$row_details = __( 'Referrer', 'wp-slimstat' ) . ": $domain";
							$element_value = self::get_search_terms_info( $results[ $i ][ 'searchterms' ], $results[ $i ][ 'referer' ], true );
						}
						else{
							$element_value = htmlentities( $results[ $i ][ 'searchterms' ], ENT_QUOTES, 'UTF-8' );
						}
						break;

					case 'username':
						$element_value = $results[ $i ][ 'username' ];
						if ( wp_slimstat::$settings[ 'show_display_name' ] == 'on' ) {
							$element_custom_value = get_user_by( 'login', $results[ $i ][ 'username' ] );
							if ( is_object( $element_custom_value ) ) {
								$element_value = $element_custom_value->display_name;
							}
						}
						break;

					case 'visit_id':
						$resource_title = self::get_resource_title( $results[ $i ][ 'resource' ] );
						if ( $resource_title != $results[ $i ][ 'resource' ] ) {
							$row_details = htmlentities( $results[ $i ][ 'resource' ], ENT_QUOTES, 'UTF-8' );
						}
						$element_value = $resource_title;
						break;
					default:
				}

				if ( is_admin() ) {
					$element_value = "<a class='slimstat-filter-link' href='" . self::fs_url( $_args[ 'columns' ] . ' ' . $_args[ 'filter_op' ] . ' ' . htmlentities( $results[ $i ][ $_args[ 'columns' ] ], ENT_QUOTES, 'UTF-8' ) ) . "'>$element_value</a>";
				}

				if ( !empty( $_args['type'] ) && $_args['type'] == 'recent' ) {
					$row_details = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $results[ $i ][ 'dt' ], true ) . ( !empty( $row_details ) ? '<br>' : '' ) . $row_details;
				}

				if ( !empty($_args[ 'type' ] ) && $_args[ 'type' ] == 'top' ) {
					$percentage_value = ( ( wp_slimstat_db::$pageviews > 0 ) ? number_format_i18n( sprintf( "%01.2f", ( 100 * $results[ $i ][ 'counthits' ] / wp_slimstat_db::$pageviews ) ), 2 ) : 0 );
					$counthits = number_format_i18n( $results[ $i ][ 'counthits' ] );

					if ( ( !empty( $_args[ 'criteria' ] ) && $_args[ 'criteria' ] == 'swap' ) || wp_slimstat::$settings[ 'show_hits' ] == 'on' ) {
						$percentage = ' <span>' . $counthits . '</span>';
						$row_details = __('Hits','wp-slimstat') . ': ' . ( ( $_args[ 'columns' ] != 'outbound_resource' ) ? $percentage_value . '%' . ( !empty( $row_details ) ? '<br>' : '' ) . $row_details : '' );
					}
					else {
						$percentage = ' <span>' . $percentage_value . '%</span>';
						$row_details = __('Hits','wp-slimstat') . ': ' . $counthits . ( !empty( $row_details ) ? '<br>' : '' ) . $row_details;
					}
				}

				// Some columns require a special post-treatment
				if ( $_args[ 'columns' ] == 'resource' && !empty( $_args[ 'where' ] ) && strpos( $_args[ 'where' ], '404' ) === false ) {
					$base_url = '';
					if (isset($results[$i]['blog_id'])){
						$base_url = parse_url(get_site_url($results[$i]['blog_id']));
						$base_url = $base_url['scheme'].'://'.$base_url['host'];
					}
					$element_value = '<a target="_blank" class="slimstat-font-logout" title="'.__('Open this URL in a new window','wp-slimstat').'" href="'.$base_url.htmlentities($results[$i]['resource'], ENT_QUOTES, 'UTF-8').'"></a> '.$base_url.$element_value;
				}

				if ( $_args[ 'columns' ] == 'referer' && !empty( $_args[ 'type' ] ) && $_args[ 'type' ] == 'top' ) {
					$element_url = htmlentities( $results[ $i ][ 'referer' ], ENT_QUOTES, 'UTF-8' );
					$element_value = '<a target="_blank" class="slimstat-font-logout" title="'.__('Open this URL in a new window','wp-slimstat').'" href="'.$element_url.'"></a> '.$element_value;
				}

				if ( is_admin() && !empty( $results[ $i ][ 'ip' ]) && $_args[ 'columns' ] != 'ip' && wp_slimstat::$settings[ 'convert_ip_addresses' ] != 'on' ) {
					$row_details .= '<br> IP: <a class="slimstat-filter-link" href="'.self::fs_url( 'ip equals ' . $results[ $i ][ 'ip' ] ) . '">' . $results[ $i ][ 'ip' ] . '</a>' . ( !empty( $results[ $i ][ 'other_ip' ] ) ? ' / ' . $results[ $i ][ 'other_ip' ] : '' ) . '<a title="WHOIS: ' . $results[ $i ][ 'ip' ] . '" class="slimstat-font-location-1 whois" href="' . wp_slimstat::$settings[ 'ip_lookup_service' ] . $results[ $i ][ 'ip' ] . '"></a>';
				}
				if ( !empty( $row_details ) ) {
					$row_details = "<b class='slimstat-tooltip-content'>$row_details</b>";
				}

				$row_output = "<p class='slimstat-tooltip-trigger'>$element_pre_value$element_value$percentage $row_details</p>";

				// Strip all the filter links, if this information is shown on the frontend
				if ( !is_admin() ) {
					$row_output = preg_replace('/<a (.*?)>(.*?)<\/a>/', "\\2", $row_output);
				}

				echo $row_output;
			}
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die();
		}
	}

	public static function show_access_log( $_args = array() ) {
		// This function is too long, so it was moved to a separate file
		include( WP_PLUGIN_DIR . '/wp-slimstat/admin/view/right-now.php' );

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die();
		}
	}

	public static function show_chart( $_args = array() ) {
		$data = wp_slimstat_db::get_data_for_chart( $_args[ 'chart_data' ] );

		if ( empty( $data[ 'keys' ] ) ) {
			echo '<p class="nodata">' . __( 'No data to display', 'wp-slimstat') . '</p>';

			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				die();
			}
			else {
				return 0;
			}
		}

		// Enqueue all the Javascript and styles
		$path_slimstat = dirname( dirname( __FILE__ ) );
		wp_enqueue_script( 'slimstat_amcharts_core', plugins_url( '/admin/assets/js/amcharts/core.js', $path_slimstat ), array(), null, false );
		wp_enqueue_script( 'slimstat_amcharts', plugins_url( '/admin/assets/js/amcharts/charts.js', $path_slimstat ), array(), null, false );
		
		$chart_colors = !empty( wp_slimstat::$settings[ 'chart_colors' ] ) ? wp_slimstat::string_to_array( wp_slimstat::$settings[ 'chart_colors' ] ) : array( '#bbcc44', '#21759b', '#ccc', '#999' );

		?>
		<div class="chart-placeholder" id="chart_<?php echo $_args[ 'id' ]; ?>" style="min-height: 280px"></div>

		<script type="text/javascript">
<?php if ( !defined( 'DOING_AJAX' ) || !DOING_AJAX ): ?>
			jQuery(function() {
<?php endif; ?>
				// am4core.useTheme(am4themes_material);
				var chart_<?php echo $_args[ 'id' ]; ?> = am4core.create( "chart_<?php echo $_args[ 'id' ]; ?>", am4charts.XYChart );

				// Add data
				chart_<?php echo $_args[ 'id' ]; ?>.data = <?php unset( $data[ 'keys' ] ); echo json_encode( $data ) ?>;

				// Create axes
				var categoryAxis = chart_<?php echo $_args[ 'id' ]; ?>.xAxes.push( new am4charts.CategoryAxis() );
				categoryAxis.dataFields.category = "v1_label";
				categoryAxis.renderer.minGridDistance = 50;
				categoryAxis.startLocation = 0;
				categoryAxis.endLocation = 1;
				categoryAxis.renderer.grid.template.disabled = true;

				// Create value axis
				var valueAxis = chart_<?php echo $_args[ 'id' ]; ?>.yAxes.push( new am4charts.ValueAxis() );
				valueAxis.baseValue = 0;

				// Colors
				chart_<?php echo $_args[ 'id' ]; ?>.colors.list = [
				am4core.color("<?php echo $chart_colors[ 0 ] ?>"),
				am4core.color("<?php echo $chart_colors[ 1 ] ?>"),
				am4core.color("<?php echo $chart_colors[ 2 ] ?>"),
				am4core.color("<?php echo $chart_colors[ 3 ] ?>")
				];

				// Create series
				var series1 = chart_<?php echo $_args[ 'id' ]; ?>.series.push(new am4charts.LineSeries());
				series1.name = "<?php echo htmlspecialchars( $_args[ 'chart_labels' ][ 0 ], ENT_QUOTES, 'UTF-8' ); ?>";
				series1.dataFields.valueY = "v1";
				series1.dataFields.categoryX = "v1_label";
				series1.strokeWidth = 2;
				series1.tooltipText = "{name} {v1_label}: [bold]{valueY}[/]";
				series1.tensionX = 0.9;

				var series2 = chart_<?php echo $_args[ 'id' ]; ?>.series.push(new am4charts.LineSeries());
				series2.name = "<?php echo htmlspecialchars( $_args[ 'chart_labels' ][ 1 ], ENT_QUOTES, 'UTF-8' ); ?>";
				series2.dataFields.valueY = "v2";
				series2.dataFields.categoryX = "v1_label";
				series2.strokeWidth = 2;
				series2.tooltipText = "{name} {v1_label}: [bold]{valueY}[/]";
				series2.tensionX = 0.9;

<?php if ( wp_slimstat::$settings[ 'comparison_chart' ] == 'on' ): ?>
				var series3 = chart_<?php echo $_args[ 'id' ]; ?>.series.push(new am4charts.LineSeries());
				series3.name = "<?php echo htmlspecialchars( $_args[ 'chart_labels' ][ 0 ], ENT_QUOTES, 'UTF-8' ) . ' ' . __( '(previous)', 'wp-slimstat' ); ?>";
				series3.dataFields.valueY = "v3";
				series3.dataFields.categoryX = "v1_label";
				series3.strokeWidth = 2;
				series3.tooltipText = "<?php echo htmlspecialchars( $_args[ 'chart_labels' ][ 0 ], ENT_QUOTES, 'UTF-8' ); ?> {v3_label}: [bold]{valueY}[/]";
				series3.tensionX = 0.9;

				var series4 = chart_<?php echo $_args[ 'id' ]; ?>.series.push(new am4charts.LineSeries());
				series4.name = "<?php echo htmlspecialchars( $_args[ 'chart_labels' ][ 1 ], ENT_QUOTES, 'UTF-8' ) . ' ' . __( '(previous)', 'wp-slimstat' ); ?>";
				series4.dataFields.valueY = "v4";
				series4.dataFields.categoryX = "v1_label";
				series4.strokeWidth = 2;
				series4.tooltipText = "<?php echo htmlspecialchars( $_args[ 'chart_labels' ][ 1 ], ENT_QUOTES, 'UTF-8' ); ?> {v3_label}: [bold]{valueY}[/]";
				series4.tensionX = 0.9;
<?php endif; ?>
				// Export
				chart_<?php echo $_args[ 'id' ]; ?>.exporting.menu = new am4core.ExportMenu();

				// Legend
				chart_<?php echo $_args[ 'id' ]; ?>.legend = new am4charts.Legend();

				// Cursor
				chart_<?php echo $_args[ 'id' ]; ?>.cursor = new am4charts.XYCursor();
				chart_<?php echo $_args[ 'id' ]; ?>.cursor.lineX.disabled = true;

				chart_<?php echo $_args[ 'id' ]; ?>.cursor.lineY.stroke = am4core.color("#444");
				chart_<?php echo $_args[ 'id' ]; ?>.cursor.lineY.strokeWidth = 2;
				chart_<?php echo $_args[ 'id' ]; ?>.cursor.lineY.strokeOpacity = 0.2;
				chart_<?php echo $_args[ 'id' ]; ?>.cursor.lineY.strokeDasharray = "";

<?php if ( !defined( 'DOING_AJAX' ) || !DOING_AJAX ): ?>
			});
<?php endif; ?>
		</script>
	<?php
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die();
		}
	}

	public static function show_events( $_args = array() ) {
		$all_results = call_user_func( $_args[ 'raw' ] , $_args );

		$results = array_slice(
			$all_results,
			wp_slimstat_db::$filters_normalized[ 'misc' ][ 'start_from' ],
			wp_slimstat::$settings[ 'rows_to_show' ]
		);

		// Count the results
		$count_page_results = count( $results );

		if ( $count_page_results == 0 ) {
			echo '<p class="nodata">' . __( 'No data to display', 'wp-slimstat' ) . '</p>';

			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				die();
			}
			else{
				return array();
			}
		}

		echo self::report_pagination( $count_page_results, count( $all_results ) );

		$blog_url = '';
		if ( isset( $results[ 0 ][ 'blog_id' ] ) ) {
			$blog_url = get_site_url( $results[ 0 ][ 'blog_id' ] );
		}

		foreach ( $results as $a_result ) {
			echo "<p class='slimstat-tooltip-trigger'>{$a_result[ 'notes' ]}";

			if ( !empty( $a_result[ 'counthits' ] ) ) {
				echo "<span>{$a_result[ 'counthits' ]}</span>";
			}

			if ( !empty( $a_result[ 'dt' ] ) ) {
				$date_time = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $a_result[ 'dt' ], true );
				echo  '<b class="slimstat-tooltip-content">' .  __( 'Page', 'wp-slimstat' ) . ": <a href='{$blog_url}{$a_result[ 'resource' ]}'>{$blog_url}{$a_result[ 'resource' ]}</a><br>" . __( 'Coordinates', 'wp-slimstat' ) . ": {$a_result[ 'position' ]}<br>" . __( 'Date', 'wp-slimstat' ) . ": $date_time";
			}

			echo "</b></p>";
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die();
		}
	}

	public static function show_group_by( $_args = array() ) {
		$all_results = call_user_func( $_args[ 'raw' ], $_args );

		$results = array_slice(
			$all_results,
			wp_slimstat_db::$filters_normalized[ 'misc' ][ 'start_from' ],
			wp_slimstat::$settings[ 'rows_to_show' ]
		);

		// Count the results
		$count_page_results = count( $results );

		if ( $count_page_results == 0 ) {
			echo '<p class="nodata">' . __( 'No data to display', 'wp-slimstat' ) . '</p>';

			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				die();
			}
			else{
				return 0;
			}
		}

		echo wp_slimstat_db::$debug_message;
		echo self::report_pagination( $count_page_results, count( $all_results ) );

		foreach ( $results as $a_result ) {
			if ( empty( $a_result[ 'counthits' ] ) ) {
				$a_result[ 'counthits' ] = 0;
			}

			$a_result[ 'resource' ] = "<a class='slimstat-font-logout slimstat-tooltip-trigger' target='_blank' title='" . htmlentities( __( 'Open this URL in a new window', 'wp-slimstat' ), ENT_QUOTES, 'UTF-8' ) . "' href='" . htmlentities( $a_result[ 'resource' ], ENT_QUOTES, 'UTF-8' ) . "'></a> <a class='slimstat-filter-link' href='" . wp_slimstat_reports::fs_url( 'resource equals ' . htmlentities( $a_result[ 'resource' ], ENT_QUOTES, 'UTF-8' ) ) . "'>" . self::get_resource_title( $a_result[ 'resource' ] ) . '</a>';

			$group_markup = array();
			if ( !empty( $a_result[ 'column_group' ] ) ) {
				$exploded_group = explode( ';;;', $a_result[ 'column_group' ] );
				$group_markup = array();
				foreach ( $exploded_group as $a_item ) {
					$group_markup[] = '<a class="slimstat-filter-link" title="' . __( 'Filter by element in a group', 'wp-slimstat' ) . '" href="' . self::fs_url( $_args[ 'column_group' ] . ' equals ' . $a_item ) . '">' . $a_item . '</a>';
				}
			}

			echo "<p>{$a_result[ 'resource' ]} <span>{$a_result[ 'counthits' ]}</span><br/>" . implode( ', ', $group_markup ) . "</p>";
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die();
		}
	}

	public static function show_rankings(){
		$options = array( 'timeout' => 30, 'headers' => array( 'Accept' => 'application/json' ) );
		$site_url = parse_url( home_url(), PHP_URL_HOST );
		if ( !empty( wp_slimstat_db::$filters_normalized[ 'resource' ] ) && wp_slimstat_db::$filters_normalized[ 'resource' ][ 0 ] == 'equals' ) {
			$site_url .= wp_slimstat_db::$filters_normalized[ 'resource' ][ 1 ];
		}
		$site_url = urlencode( $site_url );

		// Check if we have a valied transient
		if ( false === ( $rankings = get_transient( 'slimstat_ranking_values' ) ) ) {
			$rankings = array(
				'seomoz_domain_authority' => array(
					0,
					__( 'Moz Domain Authority', 'wp-slimstat' ),
					__( 'A normalized 100-point score representing the likelihood of a domain to rank well in search engine results.', 'wp-slimstat' )
				),
				'seomoz_equity_backlinks' => array(
					0,
					__( 'Moz Backlinks', 'wp-slimstat' ),
					__( 'Number of external equity links to your website.', 'wp-slimstat' )
				),
				'seomoz_links' => array(
					0,
					__( 'Moz Links', 'wp-slimstat' ),
					__( 'The number of links (external, equity or nonequity or not) to your homepage.', 'wp-slimstat' )
				),
				'alexa_world_rank' => array(
					0,
					__( 'Alexa World Rank', 'wp-slimstat' ),
					__( 'Alexa is a subsidiary company of Amazon.com which provides commercial web traffic data.', 'wp-slimstat' )
				),
				'alexa_country_rank' => array(
					0,
					__( 'Alexa Country Rank', 'wp-slimstat' ),
					''
				),
				'alexa_popularity' => array(
					0,
					__( 'Alexa Popularity', 'wp-slimstat' ),
					''
				)
			);

			if ( !empty( wp_slimstat::$settings[ 'mozcom_access_id' ] ) && !empty( wp_slimstat::$settings[ 'mozcom_secret_key' ] ) ) {
				$expiration_token = time() + 300;
				$binary_signature = @hash_hmac( 'sha1', wp_slimstat::$settings[ 'mozcom_access_id' ] . "\n" . $expiration_token, wp_slimstat::$settings[ 'mozcom_secret_key' ], true );
				$binary_signature = urlencode( base64_encode( $binary_signature ) );

				// SeoMoz Equity Links (Backlinks) and MozRank
				$response = @wp_remote_get( 'https://lsapi.seomoz.com/linkscape/url-metrics/' . $site_url . '?Cols=68719478816&AccessID=' . wp_slimstat::$settings[ 'mozcom_access_id' ] . '&Expires=' . $expiration_token . '&Signature=' . $binary_signature, $options );

				if ( !is_wp_error( $response ) && isset( $response[ 'response' ][ 'code' ] ) && ( $response[ 'response' ][ 'code' ] == 200 ) && !empty( $response[ 'body' ] ) ) {
					$response = @json_decode( $response[ 'body' ] );
					if ( is_object( $response ) ) {
						if ( !empty( $response->pda ) ) {
							$rankings[ 'seomoz_domain_authority' ][ 0 ] = number_format_i18n( intval( $response->pda ) );
						}

						if ( !empty( $response->ueid ) ) {
							$rankings[ 'seomoz_equity_backlinks' ][ 0 ] = number_format_i18n( intval( $response->ueid ) );
						}

						if ( !empty( $response->uid ) ) {
							$rankings[ 'seomoz_links' ][ 0 ] = number_format_i18n( floatval( $response->uid ) );
						}
					}
				}
			}

			// Alexa
			$response = @wp_remote_get( 'http://data.alexa.com/data?cli=10&dat=snbamz&url=' . $site_url, $options );
			if ( !is_wp_error( $response ) && isset( $response[ 'response' ][ 'code' ] ) && ( $response[ 'response' ][ 'code' ] == 200 ) && !empty( $response[ 'body' ] ) ) {
				$response = @simplexml_load_string( $response[ 'body' ] );
				if ( is_object( $response->SD[ 1 ] ) ) {
					if ( $response->SD[ 1 ]->POPULARITY && $response->SD[ 1 ]->POPULARITY->attributes() ) {
						$popularity = $response->SD[ 1 ]->POPULARITY->attributes();
						if ( !empty( $popularity ) ) {
							$rankings[ 'alexa_popularity' ][ 0 ] = number_format_i18n( floatval( $popularity[ 'TEXT' ] ) );
						}
					}

					if ( $response->SD[ 1 ]->REACH && $response->SD[ 1 ]->REACH->attributes() ) {
						$reach = $response->SD[ 1 ]->REACH->attributes();
						if ( !empty( $reach ) ) {
							$rankings[ 'alexa_world_rank' ][ 0 ] = number_format_i18n( floatval( $reach[ 'RANK' ] ) );
						}
					}

					if ( $response->SD[ 1 ]->COUNTRY && $response->SD[ 1 ]->COUNTRY->attributes() ) {
						$country = $response->SD[ 1 ]->COUNTRY->attributes();
						if ( !empty( $country ) ) {
							$rankings[ 'alexa_country_rank' ][ 0 ] = number_format_i18n( floatval( $country[ 'RANK' ] ) );
						}
					}
					else if ( $response->SD[ 1 ]->RANK && $response->SD[ 1 ]->RANK->attributes() ) {
						$rank = $response->SD[ 1 ]->RANK->attributes();
						if ( !empty( $rank ) ) {
							$rankings[ 'alexa_country_rank' ][ 0 ] = number_format_i18n( floatval( $rank[ 'DELTA' ] ) );
							$rankings[ 'alexa_country_rank' ][ 1 ] = __( 'Alexa Delta', 'wp-slimstat' );
						}
					}
				}
			}
		}

		foreach ( $rankings as $a_ranking ) {
			echo '<p>' . self::inline_help( $a_ranking[ 2 ], false ) . $a_ranking[ 1 ] . '<span>' . $a_ranking[ 0 ] . '</span></p>';
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die();
		}
	}

	public static function show_world_map() {
		$countries = wp_slimstat_db::get_top( 'country' );
		$recent_visits = wp_slimstat_db::get_recent( 'location', '', '', true, '', 'city' );

		$data_points = array();
		if ( !empty( $recent_visits ) ) {
			$recent_visits = array_slice( $recent_visits, 0, wp_slimstat::$settings[ 'max_dots_on_map' ] );

			foreach ( $recent_visits as $a_recent_visit ) {
				if ( !empty( $a_recent_visit[ 'city' ] ) &&  !empty( $a_recent_visit[ 'location' ] ) ) {
					list( $latitude, $longitude ) = explode( ',', $a_recent_visit[ 'location' ] );
					$clean_city_name = htmlentities( $a_recent_visit[ 'city' ], ENT_QUOTES, 'UTF-8' );
					$date_time = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $a_recent_visit[ 'dt' ], true );
					$data_points[] = "{zoomLevel:7,type:'circle',title:'{$clean_city_name}<br>{$date_time}',latitude:$latitude,longitude:$longitude}";
				}
			}
		}

		$data_areas = array();
		
		foreach ( wp_slimstat_i18n::get_country_codes() as $a_code => $a_string ) {
			$data_areas[ $a_code ] = '{id:"' . $a_code . '",balloonText:"' . $a_string . ': 0",value:0,color:"#ededed"}';
		}

		$max = 0;

		foreach ( $countries as $a_country ) {
			$current_country_code = strtolower( $a_country[ 'country' ] );
			if ( !array_key_exists( $current_country_code, $data_areas ) ) {
				continue;
			}

			$percentage = ( wp_slimstat_db::$pageviews > 0 ) ? sprintf( "%01.2f", ( 100 * $a_country[ 'counthits' ] / wp_slimstat_db::$pageviews ) ) : 0;
			$percentage_format = number_format_i18n( $percentage, 2 );
			$balloon_text = wp_slimstat_i18n::get_string( 'c-' . $a_country[ 'country' ], 'wp-slimstat' ) . ': ' . $percentage_format . '% (' . number_format_i18n( $a_country[ 'counthits' ] ) . ')';
			$data_areas[ $current_country_code ] = '{id:"' . strtoupper( $a_country[ 'country' ] ) . '",balloonText:"' . $balloon_text . '",value:' . $percentage . '}';

			if ( $percentage > $max ) {
				$max = $percentage;
			}
		}

		$path_slimstat = dirname( dirname( __FILE__ ) );
		wp_enqueue_script( 'slimstat_ammap', plugins_url( '/admin/assets/js/ammap/ammap.js', $path_slimstat ), array(), null, false );
		wp_enqueue_script( 'slimstat_ammap_world', plugins_url( '/admin/assets/js/ammap/world.js', $path_slimstat ), array(), null, false );

		?>

		<div id="map_slim_p6_01" style="height: 100%"></div>

		<script type="text/javascript">
			jQuery( function() {
				var targetSVG = "M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z";

				var dataProvider = {
					map: "worldLow",
					getAreasFromMap: false,
					areas:[ <?php echo implode( ',', $data_areas ) ?> ],
					images: [ <?php if ( !empty( $data_points ) ) echo implode( ',', array_reverse( $data_points ) ) ?> ]
				};

				// Create AmMap object
				var map = new AmCharts.AmMap();

				<?php if ($max != 0): ?>
				var legend = new AmCharts.ValueLegend();
				legend.height = 20;
				legend.minValue = "0.01";
				legend.maxValue = "<?php echo $max ?>%";
				legend.right = 20;
				legend.showAsGradient = true;
				legend.width = 300;
				map.valueLegend = legend;
				<?php endif; ?>

				// Configuration
				map.areasSettings = {
					autoZoom: false,
					color: "#9dff98",
					colorSolid: "#fa8a50",
					outlineColor: "#888888",
					selectedColor: "#ffb739"
				};
				map.imagesSettings = {
					rollOverColor: "#089282",
					rollOverScale: 2,
					selectedScale: 2,
					selectedColor: "#089282",
					color: "#13564e"
				};
				map.zoomControl = {
					zoomControlEnabled: true,
					zoomFactor: 3
				};
				map.export = {
					"enabled": true,
					"libs": {
						"path": "<?php echo plugins_url( '/assets/js/amcharts/plugins/export/libs/', dirname(__FILE__) ) ?>"
					},
					"menu": [ {
						"class": "export-main",
						"menu": [ {
							"label": "Download as...",
							"menu": [ "PNG", "PDF" ]
						} ]
					} ]
				};
				map.backgroundAlpha = .9;
				map.backgroundColor = "#7adafd";
				map.backgroundZoomsToTop = false;
				map.balloon.color = "#000000";
				map.colorSteps = 5;
				map.mouseWheelZoomEnabled = true;
				map.pathToImages = "<?php echo plugins_url( '/assets/js/ammap/images/', dirname(__FILE__) ) ?>";

				// Init Data
				map.dataProvider = dataProvider;

				// Display Map
				map.write( "map_slim_p6_01" );
			});
		</script><?php

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die();
		}
	}

	public static function get_search_terms_info( $_searchterms = '', $_referer = '', $_serp_only = false ) {
		$query_details = '';
		$search_terms_info = '';
		parse_str( $_referer, $query_parse_str );

		if ( !empty( $query_parse_str[ 'source' ] ) && !$_serp_only ) {
			$query_details = __( 'src', 'wp-slimstat' ) . ": {$query_parse_str[ 'source' ]}";
		}

		if ( !empty( $query_parse_str[ 'cd' ] ) ) {
			$query_details = __( 'serp', 'wp-slimstat') . ": {$query_parse_str[ 'cd' ]}";
		}

		if ( !empty( $query_details ) ) {
			$query_details = "($query_details)";
		}

		if ( !empty( $_searchterms ) && $_searchterms != '_' ) {
			$search_terms_info = htmlentities( $_searchterms, ENT_QUOTES, 'UTF-8' ) . ' ' . $query_details;
		}
		return $search_terms_info;
	}

	/**
	 * Generate the HTML that lists all the filters currently used
	 */
	public static function get_filters_html( $_filters_array = array() ) {
		$filters_html = '';

		if ( !empty( $_filters_array ) ) {
			foreach( $_filters_array as $a_filter_label => $a_filter_details ) {
				if ( !array_key_exists( $a_filter_label, wp_slimstat_db::$columns_names ) || strpos( $a_filter_label, 'no_filter' ) !== false ) {
					continue;
				}

				$a_filter_value_no_slashes = ( $a_filter_details[ 0 ] == 'is_empty' || $a_filter_details[ 0 ] == 'is_not_empty' ) ? '' : htmlentities( str_replace( '\\','', $a_filter_details[ 1 ] ), ENT_QUOTES, 'UTF-8' );
				$filters_html .= '<li>' . strtolower( wp_slimstat_db::$columns_names[ $a_filter_label ][ 0 ] ) . ' ' . __( str_replace( '_', ' ', $a_filter_details[ 0 ] ), 'wp-slimstat' ) . " $a_filter_value_no_slashes <a class='slimstat-filter-link slimstat-font-cancel' title='" . htmlentities( __( 'Remove filter for', 'wp-slimstat' ), ENT_QUOTES, 'UTF-8' ) . ' ' . wp_slimstat_db::$columns_names[ $a_filter_label ][ 0 ] . "' href='" . self::fs_url( "$a_filter_label equals " ) . "'></a></li>";
			}
		}

		if ( !empty( $filters_html ) ) {
			$filters_html = "<ul class='slimstat-filter-list'>$filters_html</ul><a href='#' id='slimstat-save-filter' class='slimstat-filter-action-button button-secondary noslimstat' data-filter-array='" . htmlentities( json_encode( $_filters_array ), ENT_QUOTES, 'UTF-8' ) . "'>" . __( 'Save', 'wp-slimstat' ) . '</a>';
		}

		if ( !empty( $filters_html ) ) {
			$filters_html .= '<a href="' . self::fs_url() . '" id="slimstat-remove-all-filters" class="button-secondary slimstat-filter-action-button noslimstat">' . __( 'Reset All', 'wp-slimstat' ) . '</a>';
		}

		return $filters_html;
	}

	public static function fs_url( $_filters_string = '' ) {
		// Allow only legitimate requests
		if ( !is_admin() ) {
			return '';
		}

		$request_uri = admin_url( 'admin.php' );
		$request_uri .= '?page=' . wp_slimstat_admin::$current_screen;

		// Avoid XSS attacks ( why would the owner try to hack into his/her own website though? )
		if ( !empty( $_SERVER[ 'HTTP_REFERER' ] ) ) {
			$parsed_referer = parse_url( $_SERVER[ 'HTTP_REFERER' ] );
			if ( !$parsed_referer || ( !empty( $parsed_referer[ 'scheme' ] ) && !in_array( strtolower( $parsed_referer[ 'scheme' ] ), array( 'http', 'https' ) ) ) ) {
				return '';
			}
		}

		$fn = wp_slimstat_db::parse_filters( $_filters_string );

		// Columns
		if ( !empty( $fn[ 'columns' ] ) ) {
			foreach ( $fn[ 'columns' ] as $a_key => $a_filter ) {
				$request_uri .= "&amp;fs%5B$a_key%5D=" . urlencode( $a_filter[ 0 ] . ' ' . str_replace( '=', '%3D', $a_filter[ 1 ] ) );
			}
		}

		// Date ranges
		if ( !empty( $fn[ 'date' ] ) ) {
			foreach ( $fn[ 'date' ] as $a_key => $a_filter ) {
				if ( isset( $a_filter ) ) {
					$request_uri .= "&amp;fs%5B$a_key%5D=" . urlencode( 'equals ' . $a_filter );
				}
			}
		}

		// Misc filters
		if ( !empty( $fn[ 'misc' ] ) ) {
			foreach ( $fn[ 'misc' ] as $a_key => $a_filter ) {
				$request_uri .= "&amp;fs%5B$a_key%5D=" . urlencode( 'equals ' . $a_filter );
			}
		}

		return $request_uri;
	}

	/**
	 * Attempts to convert a permalink into a post title
	 */
	public static function get_resource_title( $_resource = '' ) {
		if  ( wp_slimstat::$settings[ 'convert_resource_urls_to_titles' ] != 'on' ) {
			return htmlentities( urldecode( $_resource ), ENT_QUOTES, 'UTF-8' );
		}

		// Do we already have this value in our transient cache?
		$cache_index = md5( $_resource );
		if ( !empty( self::$resource_titles ) && !empty( self::$resource_titles[ $cache_index ] ) ) {
			return self::$resource_titles[ $cache_index ];
		}

		self::$resource_titles[ $cache_index ] = $_resource;

		// Is this a post or a page?
		$post_id = url_to_postid( $_resource );

		if ( $post_id > 0 ) {
			self::$resource_titles[ $cache_index ] = the_title_attribute( array( 'post' => $post_id, 'echo' => false ) );

			// Encode URLs to avoid XSS attacks
			if ( self::$resource_titles[ $cache_index ] == $_resource ) {
				self::$resource_titles[ $cache_index ] = htmlspecialchars( self::$resource_titles[ $cache_index ], ENT_QUOTES, 'UTF-8' );
			}
		}

		// Is this a category or tag permalink?
		else {
			$term_names = array();
			$home_url = get_home_url();
			$relative_home = parse_url( $home_url, PHP_URL_PATH );

			$all_terms = get_terms( 'category' );
			foreach ( $all_terms as $a_term ) {
				$term_link = get_term_link( $a_term, 'category' );
				if ( !is_wp_error( $term_link ) && str_replace( $home_url, $relative_home, $term_link ) == $_resource ) {
					$term_names[] = $a_term->name;
				}
			}

			$all_terms = get_terms( 'tag' );
			foreach ( $all_terms as $a_term ) {
				$term_link = get_term_link( $a_term, 'tag' );
				if ( !is_wp_error( $term_link ) && str_replace( $home_url, $relative_home, $term_link ) == $_resource ) {
					$term_names[] = $a_term->name;
				}
			}

			if ( !empty( $term_names ) ) {
				self::$resource_titles[ $cache_index ] = implode( ',', $term_names );
			}
			else {
				self::$resource_titles[ $cache_index ] = htmlspecialchars( self::$resource_titles[ $cache_index ], ENT_QUOTES, 'UTF-8' );
			}
		}

		// Save new value in cache
		set_transient( 'slimstat_resource_titles', self::$resource_titles, 1800 );

		return self::$resource_titles[ $cache_index ];
	}

	public static function inline_help( $_text = '', $_echo = true ) {
		if ( is_admin() && !empty( $_text ) ) {
			$wrapped_text = "<i class='slimstat-tooltip-trigger corner'><span class='slimstat-tooltip-content'>$_text</span></i>";
		}
		else {
			$wrapped_text = '';
		}

		if ($_echo)
			echo $wrapped_text;
		else
			return $wrapped_text;
	}

	protected static function _check_args( $_args = array() ) {

		// When called from the WP Dashboard, the action passes 2 arguments: post_id (empty) and array of arguments
		if ( !empty( $_args[ 1 ] ) ) {
			$_args = $_args[ 1 ];
		}
		// When called from within Slimstat, the first argument is the array of arguments for the callback
		else {
			$_args = $_args[ 0 ];
		}

		if ( !is_array( $_args ) ) {
			$_args = array();
		}

		$report_id = 0;

		// Is this an Ajax request?
		if ( !empty( $_POST[ 'report_id' ] ) ) {
			check_ajax_referer( 'meta-box-order', 'security' ); // Let's make sure the request is coming from an authorized source
			$report_id = $_POST[ 'report_id' ];
		}

		// When on the WP Dashboard, the action sets the 'id' key of the $_args array
		else if ( !empty( $_args[ 'id' ] ) ){
			$report_id = $_args[ 'id' ];
		}

		if ( !empty( self::$reports[ $report_id ] ) && is_array( self::$reports[ $report_id ] ) ) {
			// Default values
			$_args = array_merge( array(
				'title' => '',
				'callback' => '',
				'callback_args' => array(),
				'classes' => array(),
				'locations' => array(),
				'tooltip' => ''
			), self::$reports[ $report_id ] );
		}

		// Default callback args
		if ( !empty( $_args[ 'callback_args' ] ) ) {
			$_args[ 'callback_args' ] = array_merge( array(
				'type' => '',
				'columns' => '',
				'where' => '',
				'having' => '',
				'as_column' => '',
				'filter_op' => 'equals',
				'outer_select_column' => '',
				'aggr_function' => 'MAX',
				'use_date_filters' => true,
				'results_per_page' => wp_slimstat::$settings[ 'rows_to_show' ],
				'criteria' => ''
			), $_args[ 'callback_args' ] );
		}

		return $_args;
	}
}
