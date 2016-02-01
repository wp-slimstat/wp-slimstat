<?php

class wp_slimstat_reports {

	// Structures to store all the information about what screens and reports are available
	public static $reports_info = array();
	public static $user_reports = array();

	// Hidden filters are not displayed to the user, but are applied to the reports
	public static $hidden_filters = array();

	// Useful data for the reports
	protected static $pageviews = 0;

	/**
	 * Initalize class properties
	 */
	public static function init(){
		// Filters use the following format: browser equals Firefox&&&country contains gb
		$filters = array();
		if ( !empty( $_REQUEST[ 'fs' ] ) && is_array( $_REQUEST[ 'fs' ] ) ) {
			foreach( $_REQUEST[ 'fs' ] as $a_request_filter_name => $a_request_filter_value ) {
				$filters[] = "$a_request_filter_name $a_request_filter_value";
			}
		}

		// Fields and drop downs 
		if ( !empty( $_POST[ 'f' ] ) && !empty( $_POST[ 'o' ] ) ) {
			$filters[] = "{$_POST[ 'f' ]} {$_POST[ 'o' ]} " . ( isset( $_POST[ 'v' ] ) ? $_POST[ 'v' ] : '' );
		}

		foreach ( array( 'minute', 'hour', 'day', 'month', 'year', 'interval_direction', 'interval', 'interval_hours', 'interval_minutes' ) as $a_date_time_filter_name ) {
			if ( !empty( $_POST[ $a_date_time_filter_name ] ) ) {
				$filters[] = "$a_date_time_filter_name equals {$_POST[ $a_date_time_filter_name ]}";
			}
		}

		// Hidden Filters
		if ( wp_slimstat::$options[ 'restrict_authors_view' ] == 'yes' && !current_user_can( 'manage_options' ) ) {
			$filters[] = 'author equals ' . $GLOBALS[ 'current_user' ]->user_login;
			self::$hidden_filters[ 'author' ] = 1;
		}

		if ( !empty( $filters ) ) {
			$filters = implode( '&&&', $filters );
		}

		// Include and initialize the API to interact with the database
		include_once( 'wp-slimstat-db.php' );
		wp_slimstat_db::init( $filters );

		// Retrieve data that will be used by multiple reports
		self::$pageviews = wp_slimstat_db::count_records();

		// Define all the reports
		//
		// Parameters
		// - title : report name
		// - callback : function to use to render the report
		// - callback_args : parameters to pass to the function
		// - classes : determine the look and feel of this report ( tall, normal, wide, hidden )
		// - screens : where should the report appear ( slimview1, .., slimview4, dashboard )
		// - tooltip : contextual help to be displayed on hover
		
		$chart_tooltip = '<strong>' . __( 'Chart controls', 'wp-slimstat' ) . '</strong><ul><li>' . __( 'Use your mouse wheel to zoom in and out', 'wp-slimstat' ) . '</li><li>' . __( 'While zooming in, drag the chart to move to a different area', 'wp-slimstat' ) . '</li></ul>';
		self::$reports_info = array(
			'slim_p7_02' => array(
				'title' => __( 'Visitors Activity', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_activity_log' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => '*',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'full-width', 'tall' ),
				'screens' => array( 'slimview1', 'dashboard' ),
				'tooltip' => __( 'Color codes', 'wp-slimstat' ).'</strong><p><span class="little-color-box is-search-engine"></span> '.__( 'From search result page', 'wp-slimstat' ).'</p><p><span class="little-color-box is-known-visitor"></span> '.__( 'Known Visitor', 'wp-slimstat' ).'</p><p><span class="little-color-box is-known-user"></span> '.__( 'Known Users', 'wp-slimstat' ).'</p><p><span class="little-color-box is-direct"></span> '.__( 'Other Humans', 'wp-slimstat' ).'</p><p><span class="little-color-box"></span> '.__( 'Bot or Crawler', 'wp-slimstat' ).'</p>'
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
				'classes' => array( 'wide', 'chart' ),
				'screens' => array( 'slimview2', 'dashboard' ),
				'tooltip' => $chart_tooltip
			),
			'slim_p1_02' => array(
				'title' => __( 'About Slimstat', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'raw' => array( __CLASS__, 'get_about_wpslimstat' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview2' )
			),
			'slim_p1_03' => array(
				'title' => __( 'Traffic at a Glance', 'wp-slimstat' ),
				// 'callback' => array( __CLASS__, 'show_overview_summary' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'raw' => array( __CLASS__, 'get_overview_summary' )
				),
				'classes' => array( 'normal' ),
				'screens' => array( 'slimview2', 'dashboard' )
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
				'screens' => array( 'slimview2', 'dashboard' )
			),
			'slim_p1_06' => array(
				'title' => __( 'Recent Search Terms', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'searchterms',
					'where' => 'searchterms <> "_"',
					'more_columns' => 'referer, resource',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'screens' => array( 'slimview2', 'slimview5' ),
				'tooltip' => __( 'Keywords used by your visitors to find your website on a search engine.', 'wp-slimstat' )
			),
			'slim_p1_08' => array(
				'title' => __( 'Top Web Pages', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'SUBSTRING_INDEX(resource, "' . ( !get_option( 'permalink_structure' ) ? '&' : '?' ) . '", 1)',
					'as_column' => 'resource_calculated',
					'filter_op' => 'contains',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'screens' => array( 'slimview2', 'dashboard' ),
				'tooltip' => __( 'Here a "page" is not just a WordPress page type, but any webpage on your site, including posts, products, categories, and so on. You can set the corresponding filter where Resource Content Type equals cpt:you_cpt_slug_here to get top web pages for a specific custom post type you have.', 'wp-slimstat' )
			),
			'slim_p1_10' => array(
				'title' => __('Top Referring Domains', 'wp-slimstat'),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'REPLACE( SUBSTRING_INDEX( ( SUBSTRING_INDEX( ( SUBSTRING_INDEX( referer, "://", -1 ) ), "/", 1 ) ), ".", -5 ), "www.", "" )',
					'as_column' => 'referer_calculated',
					'filter_op' => 'contains',
					'where' => 'referer NOT LIKE "%' . str_replace( 'www.', '', parse_url( home_url(), PHP_URL_HOST ) ) . '%"',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'screens' => array( 'slimview2', 'slimview5', 'dashboard' )
			),
			'slim_p1_11' => array(
				'title' => __( 'Top Known Visitors', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'username',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview2', 'dashboard' )
			),
			'slim_p1_12' => array(
				'title' => __( 'Top Search Terms', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'searchterms',
					'where' => 'searchterms <> "_"',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'screens' => array( 'slimview2', 'slimview4', 'slimview5', 'dashboard' )
			),
			'slim_p1_13' => array(
				'title' => __( 'Top Countries', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'country',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview2', 'slimview3', 'slimview5', 'dashboard' ),
				'tooltip' => __( 'You can configure Slimstat to ignore a specific Country by setting the corresponding filter under Settings > Slimstat > Filters.', 'wp-slimstat' )
			),
			'slim_p1_15' => array(
				'title' => __( 'Rankings', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_rankings' ),
				'classes' => array( 'normal' ),
				'screens' => array( 'slimview2' ),
				'tooltip' => __( "Slimstat retrieves live information from Alexa, Facebook and Google, to measures your site's rankings. Values are updated every 12 hours. Filters set above don't apply to this report.", 'wp-slimstat' )
			),
			'slim_p1_17' => array(
				'title' => __( 'Top Language Families', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'SUBSTRING(language, 1, 2)',
					'as_column' => 'language_calculated',
					'filter_op' => 'contains',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview3' )
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
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview2', 'dashboard' ),
				'tooltip' => __( 'When visitors leave a comment on your blog, WordPress assigns them a cookie. Slimstat leverages this information to identify returning visitors. Please note that visitors also include registered users.', 'wp-slimstat' )
			),
			'slim_p1_19_01' => array( // Chart Reports need to always have a _01 suffix to tell our custom "refresh" code to avoid fading the chart, which apparently doesn't work
				'title' => __( 'Search Terms', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_chart' ),
				'callback_args' => array(
					'id' => 'slim_p1_19_01',
					'chart_data' => array(
						'data1' => 'COUNT( searchterms )',
						'data2' => 'COUNT( DISTINCT searchterms )',
						'where' => 'searchterms <> "_"'
					),
					'chart_labels' => array(
						__( 'Search Terms', 'wp-slimstat' ),
						__( 'Unique Terms', 'wp-slimstat' )
					)
				),
				'classes' => array( 'wide', 'chart' ),
				'screens' => array( 'slimview2' ),
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
				'screens' => array( 'slimview2', 'slimview5', 'dashboard' )
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
				'classes' => array( 'wide', 'chart' ),
				'screens' => array( 'slimview3' ),
				'tooltip' => $chart_tooltip
			),
			'slim_p2_02' => array(
				'title' => __( 'Audience Overview', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'raw' => array( __CLASS__, 'get_visitors_summary' )
				),
				'classes' => array( 'normal' ),
				'screens' => array( 'slimview3', 'dashboard' ),
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
				'screens' => array( 'slimview3' )
			),
			'slim_p2_04' => array(
				'title' => __( 'Top Browsers', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'browser, browser_version',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'screens' => array( 'slimview3', 'dashboard' )
			),
			'slim_p2_05' => array(
				'title' => __( 'Top Service Providers', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'ip',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'wide', 'hidden' ),
				'screens' => array( 'slimview3' ),
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
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview3' ),
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
				'screens' => array( 'slimview3', 'dashboard' )
			),
			'slim_p2_08' => array(
				'title' => __( 'Top Viewport Sizes', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'resolution',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview3' )
			),
			'slim_p2_09' => array(
				'title' => __( 'Browser Capabilities', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'raw' => array( __CLASS__, 'get_plugins' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview3' )
			),
			'slim_p2_12' => array(
				'title' => __( 'Visit Duration', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'raw' => array( __CLASS__, 'get_visits_duration' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview3' ),
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
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview3', 'slimview5' )
			),
			'slim_p2_14' => array(
				'title' => __( 'Recent Viewport Sizes', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'resolution',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview3' )
			),
			'slim_p2_15' => array(
				'title' => __( 'Recent Operating Systems', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'platform',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview3' )
			),
			'slim_p2_16' => array(
				'title' => __( 'Recent Browsers', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'browser, browser_version',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview3' )
			),
			'slim_p2_17' => array(
				'title' => __( 'Recent Languages', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'language',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview3' )
			),
			'slim_p2_18' => array(
				'title' => __( 'Top Browser Families', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'browser',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview3' ),
				'tooltip' => __( 'This report shows you what user agent families (no version considered) are popular among your visitors.', 'wp-slimstat' )
			),
			'slim_p2_19' => array(
				'title' => __( 'Top OS Families', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'CONCAT("p-", SUBSTRING(platform, 1, 3))',
					'as_column' => 'platform_calculated',
					'filter_op' => 'contains',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'screens' => array( 'slimview3' ),
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
				'screens' => array( 'slimview3' )
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
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview3', 'dashboard' )
			),
			'slim_p2_22_01' => array( // Chart Reports need to always have a _01 suffix to tell our custom "refresh" code to avoid fading the chart, which apparently doesn't work
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
				'classes' => array( 'wide', 'chart' ),
				'screens' => array( 'slimview3' ),
				'tooltip' => $chart_tooltip
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
				'classes' => array( 'wide', 'chart' ),
				'screens' => array( 'slimview5' ),
				'tooltip' => $chart_tooltip
			),
			'slim_p3_02' => array(
				'title' => __( 'Traffic Summary', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'raw' => array( __CLASS__, 'get_traffic_sources_summary' )
				),
				'classes' => array( 'normal' ),
				'screens' => array( 'slimview5' )
			),
			'slim_p3_06' => array(
				'title' => __( 'Top Referring Search Engines', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'REPLACE( SUBSTRING_INDEX( SUBSTRING_INDEX( SUBSTRING_INDEX( referer, "://", -1 ), "/", 1 ), ".", -5 ), "www.", "" )',
					'as_column' => 'referer_calculated',
					'filter_op' => 'contains',
					'where' => 'searchterms IS NOT NULL AND referer NOT LIKE "%' . str_replace( 'www.', '', parse_url( home_url(), PHP_URL_HOST ) ) . '%"',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'screens' => array( 'slimview5', 'dashboard' )
			),
			
			/*
			'slim_p3_11' => array(
				'title' => __( 'Recent Exit Pages', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'visit_id, resource', // raw_results_to_html knows to display the resource, when the column is visit_id
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'screens' => array( 'slimview5' )
			),
			*/

			'slim_p4_01' => array(
				'title' => __( 'Recent Outbound Links', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'outbound_resource',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'wide' ),
				'screens' => array( 'slimview4' ),
				'tooltip' => ''
			),
			'slim_p4_02' => array(
				'title' => __( 'Recent Posts', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'resource',
					'where' => 'content_type = "post"',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal' ),
				'screens' => array( 'slimview4' )
			),
			/*
			'slim_p4_03' => array(
				'title' => __( 'Recent Bounce Pages', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'resource',
					'where' => 'content_type <> "404"',
					'having' => 'HAVING COUNT(visit_id) = 1',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview4' ),
				'tooltip' => __( 'A <em>bounce page</em> is a single-page visit, or visit in which the person left your site from the entrance (landing) page.', 'wp-slimstat' )
			),
			*/
			'slim_p4_04' => array(
				'title' => __( 'Recent Feeds', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'resource',
					'where' => '(resource LIKE "%/feed%" OR resource LIKE "%?feed=>%" OR resource LIKE "%&feed=>%" OR content_type LIKE "%feed%")',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview4' )
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
				'screens' => array( 'slimview4' )
			),
			'slim_p4_06' => array(
				'title' => __( 'Recent Internal Searches', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'searchterms',
					'where' => 'content_type LIKE "%search%"',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview4' ),
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
				'screens' => array( 'slimview4', 'dashboard' )
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
				'classes' => array( 'wide', 'hidden' ),
				'screens' => array( 'slimview4' ),
				'tooltip' => __( 'You can configure Slimstat to track specific file extensions as downloads.', 'wp-slimstat' )
			),
			'slim_p4_10' => array(
				'title' => __( 'Recent Events', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_events' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'notes',
					'raw' => array( 'wp_slimstat_db', 'get_recent_events' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview4' ),
				'tooltip' => __( 'This report lists any <em>event</em> occurred on your website. Please refer to the FAQ for more information on how to use this functionality.', 'wp-slimstat' )
			),
			'slim_p4_11' => array(
				'title' => __( 'Top Posts', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'resource',
					'where' => 'content_type = "post"',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal' ),
				'screens' => array( 'slimview4' )
			),
			'slim_p4_12' => array(
				'title' => __( 'Top Events', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_events' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'notes',
					'raw' => array( 'wp_slimstat_db', 'get_top_events' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview4' ),
				'tooltip' => __( 'This report lists any <em>event</em> occurred on your website. Please refer to the FAQ for more information on how to use this functionality.', 'wp-slimstat' )
			),
			'slim_p4_13' => array(
				'title' => __( 'Top Internal Searches', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'searchterms',
					'where' => 'content_type LIKE "%search%"',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview4' )
			),
			'slim_p4_15' => array(
				'title' => __( 'Recent Categories', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'recent',
					'columns' => 'resource',
					'where' => '(content_type = "category" OR content_type = "tag")',
					'raw' => array( 'wp_slimstat_db', 'get_recent' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview4' )
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
				'screens' => array( 'slimview4' )
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
				'screens' => array( 'slimview4', 'dashboard' )
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
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview4' )
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
				'classes' => array( 'wide', 'hidden' ),
				'screens' => array( 'slimview4' )
			),
			'slim_p4_21' => array(
				'title' => __( 'Top Outbound Links', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'outbound_resource',
					'raw' => array( 'wp_slimstat_db', 'get_top' ),
					'criteria' => 'swap'
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview4', 'dashboard' ),
			),
			'slim_p4_22' => array(
				'title' => __( 'Your Website', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'raw' => array( __CLASS__, 'get_your_blog' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview4' ),
				'tooltip' => __( 'Your content at a glance: posts, comments, pingbacks, etc. Please note that this report is not affected by the filters set here above.', 'wp-slimstat' )
			),
			'slim_p4_23' => array(
				'title' => __( 'Top Bounce Pages', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'raw_results_to_html' ),
				'callback_args' => array(
					'type' => 'top',
					'columns' => 'resource',
					'where' => 'content_type <> "404"',
					'having' => 'HAVING COUNT(visit_id) = 1',
					'raw' => array( 'wp_slimstat_db', 'get_top' )
				),
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview4' )
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
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview4', 'dashboard' )
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
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'slimview4' )
			),
			'slim_p4_26_01' => array( // Chart Reports need to always have a _01 suffix to tell our custom "refresh" code to avoid fading the chart, which apparently doesn't work
				'title' => __( 'Outbound Links', 'wp-slimstat' ),
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
				'classes' => array( 'wide', 'chart' ),
				'screens' => array( 'slimview4' ),
				'tooltip' => $chart_tooltip
			),

			'slim_p6_01' => array(
				'title' => __( 'World Map', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'show_world_map' ),
				'classes' => array( 'full-width tall' ),
				'screens' => array( 'slimview6' ),
				'tooltip' => ''
			)
		);

		// Allow third party tools to manipulate this list here above: please use unique report IDs that don't interfere with built-in ones, if you add your own custom report
		self::$reports_info = apply_filters( 'slimstat_reports_info', self::$reports_info );

		// Define what reports have been deprecated over time, to remove them from the user's settings
		$deprecated_reports = array(
			'slim_p1_05' => 1,
			'slim_p1_18' => 1,
			'slim_p2_10' => 1,
			'slim_p3_03' => 1,
			'slim_p3_04' => 1,
			'slim_p3_05' => 1,
			'slim_p3_08' => 1,
			'slim_p3_09' => 1,
			'slim_p3_10' => 1,
			'slim_p4_08' => 1,
			'slim_p4_14' => 1,
			'slim_p4_16' => 1,
			'slim_p4_17' => 1,
			'slim_getsocial' => 1
		);

		// Retrieve this user's list of active reports, 
		$current_user = wp_get_current_user();
		$page_location = ( wp_slimstat::$options[ 'use_separate_menu' ] == 'yes' ) ? 'slimstat' : 'admin';
		self::$user_reports = get_user_option( "meta-box-order_{$page_location}_page_slimlayout", $current_user->ID );

		// Do this only if we are in one of our screens (no dashboard!)
		if ( !empty( $_REQUEST['page'] ) && strpos( $_REQUEST['page'], 'slimview' ) !== false ) {

			// If this list is not empty, we rearrange the order of our reports
			if ( !empty( self::$user_reports[ $_REQUEST[ 'page' ] ] ) ) {
				$user_reports_intersect = array_flip( explode( ',', self::$user_reports[ $_REQUEST[ 'page' ] ] ) );
				self::$reports_info = array_intersect_key( array_merge( $user_reports_intersect, self::$reports_info ), $user_reports_intersect );
			}
			else {
				foreach ( self::$reports_info as $a_report_id => $a_report_info ) {
					if ( !in_array( $_REQUEST[ 'page' ], $a_report_info[ 'screens' ] ) ) {
						unset( self::$reports_info[ $a_report_id ] );
					}
				}
			}

			// Remove deprecated reports
			self::$reports_info = array_diff_key( self::$reports_info, $deprecated_reports );

			$hidden_reports = get_user_option( "metaboxhidden_{$page_location}_page_{$_REQUEST['page']}", $current_user->ID );

			// If this list is not empty, use it instead of the predefined visibility
			if ( is_array( $hidden_reports ) ) {
				foreach ( self::$reports_info as $a_report_id => $a_report_info ) {
					if ( in_array( $a_report_id, $hidden_reports ) ) {
						if ( is_array( self::$reports_info[ $a_report_id ][ 'classes' ] ) && !in_array( 'hidden', $a_report_info[ 'classes' ] ) ) {
							self::$reports_info[ $a_report_id ][ 'classes' ][] = 'hidden';
						}
					}
					else if ( is_array( self::$reports_info[ $a_report_id ][ 'classes' ] ) ) {
						self::$reports_info[ $a_report_id ][ 'classes' ] = array_diff( self::$reports_info[ $a_report_id ][ 'classes' ], array( 'hidden' ) );
					}
				}
			}
		}
		// If we are on the WP Dashboard page, all the reports are 'visible': WP will take care of honoring the Screen Options settings for that page
		else if ( !empty( $_REQUEST['page'] ) && strpos( $_REQUEST['page'], 'slimlayout' ) === false  ) {
			foreach ( self::$reports_info as $a_report_id => $a_report_info ) {
				if ( is_array( self::$reports_info[ $a_report_id ][ 'classes' ] ) ) {
					self::$reports_info[ $a_report_id ][ 'classes' ] = array_diff( self::$reports_info[ $a_report_id ][ 'classes' ], array( 'hidden' ) );
				}
			}
		}
	}
	// end init

	public static function report_header( $_report_id = '' ) {
		$header_classes =  !empty( self::$reports_info[ $_report_id ][ 'classes' ] ) ? implode( ' ', self::$reports_info[ $_report_id ][ 'classes' ] ) : '';
		$header_buttons = '<div class="slimstat-header-buttons">'.apply_filters('slimstat_report_header_buttons', '<a class="button-ajax noslimstat refresh slimstat-font-arrows-cw" title="'.__('Refresh','wp-slimstat').'" href="'.self::fs_url().'"></a>', $_report_id).'</div>';
		$header_tooltip = !empty( self::$reports_info[ $_report_id ][ 'tooltip' ] ) ? "<i class='slimstat-tooltip-trigger corner'></i><span class='slimstat-tooltip-content'>".self::$reports_info[ $_report_id ][ 'tooltip' ]."</span>" : '';

		echo "<div class='postbox $header_classes' id='$_report_id'>$header_buttons<h3>".self::$reports_info[ $_report_id ][ 'title' ]." $header_tooltip</h3><div class='inside' id='{$_report_id}_inside'>";
	}

	public static function report_footer(){
		echo '</div></div>';
	}

	public static function report_pagination( $_count_page_results = 0, $_count_all_results = 0, $_show_refresh_countdown = false, $_results_per_page = -1 ) {
		$_results_per_page = ( $_results_per_page < 0 ) ? wp_slimstat::$options[ 'rows_to_show' ] : $_results_per_page;

		$endpoint = min($_count_all_results, wp_slimstat_db::$filters_normalized['misc']['start_from'] + $_results_per_page);
		$pagination_buttons = '';
		$direction_prev = is_rtl() ? 'right' : 'left';
		$direction_next = is_rtl() ? 'left' : 'right';

		if ($endpoint + $_results_per_page < $_count_all_results && $_count_page_results > 0){
			$startpoint = $_count_all_results - $_count_all_results % $_results_per_page;
			if ($startpoint == $_count_all_results) {
				$startpoint -= $_results_per_page;
			}
			$pagination_buttons .= '<a class="button-ajax slimstat-font-angle-double-'.$direction_next.'" href="'.wp_slimstat_reports::fs_url('start_from equals '.$startpoint).'"></a> ';
		}
		if ($endpoint < $_count_all_results && $_count_page_results > 0){
			$startpoint = wp_slimstat_db::$filters_normalized['misc']['start_from'] + $_results_per_page;
			$pagination_buttons .= '<a class="button-ajax slimstat-font-angle-'.$direction_next.'" href="'.wp_slimstat_reports::fs_url('start_from equals '.$startpoint).'"></a> ';
		}
		if (wp_slimstat_db::$filters_normalized['misc']['start_from'] > 0){
			$startpoint = (wp_slimstat_db::$filters_normalized['misc']['start_from'] > $_results_per_page)?wp_slimstat_db::$filters_normalized['misc']['start_from'] - $_results_per_page : 0;
			$pagination_buttons .= '<a class="button-ajax slimstat-font-angle-'.$direction_prev.'" href="'.wp_slimstat_reports::fs_url('start_from equals '.$startpoint).'"></a> ';
		}
		if (wp_slimstat_db::$filters_normalized['misc']['start_from'] - $_results_per_page > 0){
			$pagination_buttons .= '<a class="button-ajax slimstat-font-angle-double-'.$direction_prev.'" href="'.wp_slimstat_reports::fs_url('start_from equals 0').'"></a> ';
		}

		$pagination = '<p class="pagination">'.sprintf(__('Results %s - %s of %s', 'wp-slimstat'), number_format(wp_slimstat_db::$filters_normalized['misc']['start_from'] + 1, 0, '', wp_slimstat_db::$formats['thousand']), number_format($endpoint, 0, '', wp_slimstat_db::$formats['thousand']), number_format($_count_all_results, 0, '', wp_slimstat_db::$formats['thousand']) . ( ( $_count_all_results == wp_slimstat::$options[ 'limit_results' ] ) ? '+' : '') );
		if ($_show_refresh_countdown && wp_slimstat::$options['refresh_interval'] > 0 && !wp_slimstat_db::$filters_normalized['date']['is_past']){
			$pagination .= ' ['.__('Refresh in','wp-slimstat').' <i class="refresh-timer"></i>]';
		}
		$pagination .= $pagination_buttons.'</p>';

		echo $pagination;
	}

	public static function callback_wrapper() {
		$_args = self::_check_args( func_get_args() );
		call_user_func( $_args[ 'callback' ] , $_args[ 'callback_args' ] );
	}

	public static function raw_results_to_html( $_args = array() ) {
		if ( wp_slimstat::$options[ 'async_load' ] == 'yes' && ( !defined( 'DOING_AJAX' ) || !DOING_AJAX ) ) {
			return '';
		}

		wp_slimstat_db::$debug_message = '';

		$all_results = call_user_func( $_args[ 'raw' ] , $_args );

		echo wp_slimstat_db::$debug_message;

		// Some reports don't need any kind of pre/post-processing, we just display the data contained in the array
		if ( empty( $_args[ 'columns' ] ) ) {
			foreach ( $all_results as $a_result ) {
				echo '<p class="slimstat-tooltip-trigger">';

				if ( !empty( $a_result[ 'tooltip' ] ) ) {
					self::inline_help( $a_result[ 'tooltip' ] ); 
				}

				echo "{$a_result[ 'metric' ]} <span>{$a_result[ 'value' ]}</span>";

				if ( !empty( $a_result[ 'details' ] ) ) {
					$is_expanded = ( wp_slimstat::$options[ 'expand_details' ] == 'yes' ) ? ' expanded' : '';
					echo "<b class='slimstat-tooltip-content$is_expanded'>{$a_result[ 'details' ]}</b>";
				}

				echo '</p>';
			}
		}
		else {
			$results = array_slice(
				$all_results,
				wp_slimstat_db::$filters_normalized[ 'misc' ][ 'start_from' ],
				wp_slimstat::$options[ 'rows_to_show' ]
			);

			// Count the results
			$count_page_results = count( $results );

			if ($count_page_results == 0){
				echo '<p class="nodata">'.__('No data to display','wp-slimstat').'</p>';
				
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

			self::report_pagination( $count_page_results, count( $all_results ) );
			
			$is_expanded = ( wp_slimstat::$options['expand_details'] == 'yes' ) ? ' expanded' : '';
			$permalinks_enabled = get_option( 'permalink_structure' );
			$column_not_calculated = str_replace( '_calculated', '', $_args[ 'columns' ] );

			for($i=0; $i<$count_page_results; $i++){
				$row_details = $percentage = '';
				$element_pre_value = '';
				$element_value = $results[ $i ][ $_args[ 'columns' ] ];

				// Some columns require a special pre-treatment
				switch ( $column_not_calculated ){

					case 'browser':
						if (!empty($results[$i]['user_agent']) && wp_slimstat::$options['show_complete_user_agent_tooltip'] == 'yes'){
							$element_pre_value = self::inline_help($results[$i]['user_agent'], false);
						}
						$element_value = $results[$i]['browser'].((isset($results[$i]['browser_version']) && intval($results[$i]['browser_version']) != 0)?' '.$results[$i]['browser_version']:'');
						break;

					case 'category':
						$row_details = __( 'Category ID', 'wp-slimstat' ) . ": {$results[ $i ][ $_args[ 'columns' ] ]}";
						$element_value = get_cat_name( $results[ $i ][ $_args[ 'columns' ] ] );
						break;

					case 'country':
						$row_details .= __('Code','wp-slimstat').": {$results[$i]['country']}";
						$element_value = __('c-'.$results[$i]['country'], 'wp-slimstat');
						break;

					case 'ip':
						if ( wp_slimstat::$options['convert_ip_addresses'] == 'yes' ) {
							$element_value = gethostbyaddr( $results[ $i ][ $_args[ 'columns' ] ] );
						}
						else{
							$element_value = $results[ $i ][ $_args[ 'columns' ] ];
						}
						break;

					case 'language':
						$row_details = __( 'Code', 'wp-slimstat' ) . ": {$results[ $i ][ $_args[ 'columns' ] ]}";
						$element_value = __( 'l-' . $results[ $i ][ $_args[ 'columns' ] ], 'wp-slimstat' );
						break;

					case 'platform':
						$row_details = __( 'Code', 'wp-slimstat' ).": {$results[ $i ][ $_args[ 'columns' ] ]}";
						$element_value = __( $results[ $i ][ $_args[ 'columns' ] ], 'wp-slimstat' );
						$results[ $i ][ $_args[ 'columns' ] ] = str_replace( 'p-', '', $results[ $i ][ $_args[ 'columns' ] ] );
						break;

					case 'referer':
						$element_value = str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), urldecode( $results[ $i ][ $_args[ 'columns' ] ] ) );
						//$element_value = parse_url( $element_value, PHP_URL_HOST );
						break;

					case 'resource':
						$resource_title = self::get_resource_title( $results[ $i ][ $_args[ 'columns' ] ] );
						if ( $resource_title != $results[ $i ][ $_args[ 'columns' ] ] ) {
							$row_details = __( 'URL', 'wp-slimstat' ) . ': ' . htmlentities( $results[ $i ][ $_args[ 'columns' ] ], ENT_QUOTES, 'UTF-8' );
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
						$element_value = $results[$i]['username'];
						if (wp_slimstat::$options['show_display_name'] == 'yes'){
							$element_custom_value = get_user_by('login', $results[$i]['username']);
							if (is_object($element_custom_value)) $element_value = $element_custom_value->display_name;
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
				
				$element_value = "<a class='slimstat-filter-link' href='" . self::fs_url( $column_not_calculated. ' ' . $_args[ 'filter_op' ] . ' ' . $results[ $i ][ $_args[ 'columns' ] ] ) . "'>$element_value</a>";

				if ( !empty( $_args['type'] ) && $_args['type'] == 'recent' ) {
					$row_details = date_i18n(wp_slimstat::$options['date_format'].' '.wp_slimstat::$options['time_format'], $results[$i]['dt'], true) . ( !empty( $row_details ) ? '<br>' : '' ) . $row_details;
				}

				if ( !empty($_args[ 'type' ] ) && $_args[ 'type' ] == 'top' ) {
					$percentage_value = ( ( self::$pageviews > 0 ) ? number_format( sprintf( "%01.2f", ( 100 * $results[ $i ][ 'counthits' ] / self::$pageviews ) ), 2, wp_slimstat_db::$formats[ 'decimal' ], wp_slimstat_db::$formats[ 'thousand' ] ) : 0 );
					$counthits = number_format( $results[ $i ][ 'counthits' ], 0, '', wp_slimstat_db::$formats[ 'thousand' ] );

					if ( $_args[ 'criteria' ] == 'swap' ) {
						$percentage = ' <span>' . $counthits . '</span>';
						$row_details = $percentage_value . '%' . ( !empty( $row_details ) ? '<br>' : '' ) . $row_details;
					}
					else {
						$percentage = ' <span>' . $percentage_value . '%</span>';
						$row_details = __('Hits','wp-slimstat') . ': ' . $counthits . ( !empty( $row_details ) ? '<br>' : '' ) . $row_details;
					}
				}

				// Some columns require a special post-treatment
				if ( $_args[ 'columns' ] == 'resource' && strpos( $_args['where'], '404' ) === false ) {
					$base_url = '';
					if (isset($results[$i]['blog_id'])){
						$base_url = parse_url(get_site_url($results[$i]['blog_id']));
						$base_url = $base_url['scheme'].'://'.$base_url['host'];
					}
					$element_value = '<a target="_blank" class="slimstat-font-logout" title="'.__('Open this URL in a new window','wp-slimstat').'" href="'.$base_url.htmlentities($results[$i]['resource'], ENT_QUOTES, 'UTF-8').'"></a> '.$base_url.$element_value;
				}
				
				if ( $_args[ 'columns' ] == 'referer_calculated' && !empty( $_args[ 'type' ] ) && $_args[ 'type' ] == 'top' ) {
					$element_url = 'http://' . htmlentities( $results[ $i ][ 'referer_calculated' ], ENT_QUOTES, 'UTF-8' );
					$element_value = '<a target="_blank" class="slimstat-font-logout" title="'.__('Open this URL in a new window','wp-slimstat').'" href="'.$element_url.'"></a> '.$element_value;
				}

				if ( $_args[ 'columns' ] == 'referer' && !empty( $_args[ 'type' ] ) && $_args[ 'type' ] == 'top' ) {
					$element_url = htmlentities( $results[ $i ][ 'referer' ], ENT_QUOTES, 'UTF-8' );
					$element_value = '<a target="_blank" class="slimstat-font-logout" title="'.__('Open this URL in a new window','wp-slimstat').'" href="'.$element_url.'"></a> '.$element_value;
				}
				
				if (!empty($results[$i]['ip']) && $_args[ 'columns' ] != 'ip' && wp_slimstat::$options['convert_ip_addresses'] != 'yes'){
					$row_details .= '<br> IP: <a class="slimstat-filter-link" href="'.self::fs_url('ip equals '.$results[$i]['ip']).'">'.$results[$i]['ip'].'</a>'.(!empty($results[$i]['other_ip'])?' / '.$results[$i]['other_ip']:'').'<a title="WHOIS: '.$results[$i]['ip'].'" class="slimstat-font-location-1 whois" href="'.wp_slimstat::$options['ip_lookup_service'].$results[$i]['ip'].'"></a>';
				}
				if (!empty($row_details)){
					$row_details = "<b class='slimstat-tooltip-content$is_expanded'>$row_details</b>";
				}

				echo "<p class='slimstat-tooltip-trigger'>$element_pre_value$element_value$percentage $row_details</p>";
			}
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die();
		}
	}

	public static function show_activity_log( $_args = array() ) {
		// This function is too long, so it was moved to a separate file
		include_once( WP_PLUGIN_DIR."/wp-slimstat/admin/view/right-now.php" );

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die();
		}
	}

	public static function show_chart( $_args = array() ){ 
		$chart_data = wp_slimstat_db::get_data_for_chart( $_args[ 'chart_data' ] );

		// switch ( $_args[ 'type' ] ) {
		// 	case 'slim_p1_01':
		// 		$chart_data = wp_slimstat_db::get_data_for_chart( 'COUNT(ip)', 'COUNT(DISTINCT(ip))' );
		// 		$chart_labels = array( __( 'Pageviews', 'wp-slimstat' ), __( 'Unique IPs', 'wp-slimstat' ) );
		// 		break;

		// 	case 'slim_p2_01':
		// 		$chart_data = wp_slimstat_db::get_data_for_chart( 'COUNT(DISTINCT visit_id)', 'COUNT(DISTINCT ip)', '(visit_id > 0 AND browser_type <> 1)' );
		// 		$chart_labels = array( __( 'Visits', 'wp-slimstat' ), __( 'Unique IPs', 'wp-slimstat' ) );
		// 		break;

		// 	case 'slim_p3_01':
		// 		$chart_data = wp_slimstat_db::get_data_for_chart( 'COUNT(DISTINCT(referer))', 'COUNT(DISTINCT(ip))', '(referer IS NOT NULL AND referer NOT LIKE "%' . home_url() . '%")' );
		// 		$chart_labels = array( __( 'Domains', 'wp-slimstat' ), __( 'Unique IPs', 'wp-slimstat' ) );
		// 		break;

		// 	default:
		// 		$chart_data = array();
		// 		$chart_labels = array( '', '' );
		// 		break;
		// }
	?>
		<div class="chart-placeholder"></div><div class="chart-legend"></div>
		<script type="text/javascript">
			SlimStatAdmin.chart_data[ '<?php echo  $_args[ 'id' ] ?>' ] = [];

			<?php if ( !empty( $chart_data[ 'previous' ][ 'label' ] ) ) : ?>
			SlimStatAdmin.chart_data[ '<?php echo  $_args[ 'id' ] ?>' ].push({
				label: '<?php echo $_args[ 'chart_labels' ][ 0 ] . ' ' . $chart_data[ 'previous' ][ 'label' ] ?>',
				data: [<?php 
					$tmp_serialize = array();
					$j = 0;
					foreach( $chart_data[ 'previous' ][ 'first_metric' ] as $a_value ) {
						$tmp_serialize[] = "[$j, $a_value]";
						$j++;
					}
					echo implode( ',', $tmp_serialize ); 
				?>],
				points: {
					show: true,
					symbol: function(ctx, x, y, radius, shadow){
						ctx.arc(x, y, 2, 0, Math.PI * 2, false)
					}
				}
			});
			SlimStatAdmin.chart_data[ '<?php echo  $_args[ 'id' ] ?>' ].push({
				label: '<?php echo $_args[ 'chart_labels' ][ 1 ] . ' ' . $chart_data[ 'previous' ][ 'label' ] ?>',
				data: [<?php 
					$tmp_serialize = array();
					$j = 0;
					foreach( $chart_data[ 'previous' ][ 'second_metric' ] as $a_value ) {
						$tmp_serialize[] = "[$j, $a_value]";
						$j++;
					}
					echo implode( ',', $tmp_serialize ); 
				?>],
				points: {
					show: true,
					symbol: function(ctx, x, y, radius, shadow){
						ctx.arc(x, y, 2, 0, Math.PI * 2, false)
					}
				}
			});
			<?php endif ?>

			SlimStatAdmin.chart_data[ '<?php echo  $_args[ 'id' ] ?>'  ].push({
				label: '<?php echo $_args[ 'chart_labels' ][ 0 ] . ' ' . $chart_data[ 'current' ][ 'label' ] ?>',
				data: [<?php 
					$tmp_serialize = array();
					$j = 0;
					foreach( $chart_data[ 'current' ][ 'first_metric' ] as $a_value ) {
						$tmp_serialize[] = "[$j, $a_value]";
						$j++;
					}
					echo implode( ',', $tmp_serialize ); 
				?>],
				points: {
					show: true,
					symbol: function(ctx, x, y, radius, shadow){
						ctx.arc(x, y, 2, 0, Math.PI * 2, false)
					}
				}
			});
			SlimStatAdmin.chart_data[ '<?php echo  $_args[ 'id' ] ?>'  ].push({
				label: '<?php echo $_args[ 'chart_labels' ][ 1 ] . ' ' . $chart_data[ 'current' ][ 'label' ] ?>',
				data: [<?php 
					$tmp_serialize = array();
					$j = 0;
					foreach( $chart_data[ 'current' ][ 'second_metric' ] as $a_value ) {
						$tmp_serialize[] = "[$j, $a_value]";
						$j++;
					}
					echo implode( ',', $tmp_serialize );
				?>],
				points: {
					show: true,
					symbol: function(ctx, x, y, radius, shadow){
						ctx.arc(x, y, 2, 0, Math.PI * 2, false)
					}
				}
			});

			SlimStatAdmin.ticks = [<?php
				$tmp_serialize = array();
				$max_ticks = max( count( $chart_data[ 'current' ][ 'first_metric' ]), count( $chart_data[ 'previous' ][ 'first_metric' ] ) );
				if ( !empty( wp_slimstat_db::$filters_normalized[ 'date' ][ 'interval' ] ) ) {
					for ( $i = 0; $i < $max_ticks; $i++ ) {
						$tmp_serialize[] = "[$i,'".date('d/m', wp_slimstat_db::$filters_normalized['utime']['start'] + ( $i * 86400) )."']";
					}
				}
				else{
					$min_idx = min( array_keys( $chart_data[ 'current' ][ 'first_metric' ] ) );
					for ($i = $min_idx; $i < $max_ticks+$min_idx; $i++){
						$tmp_serialize[] = '['.($i-$min_idx).',"'.$i.'"]';
					}
				}
				echo implode( ',', $tmp_serialize ); 
			?>];
		</script> <?php

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die();
		}
	}

	public static function get_about_wpslimstat() { 
		$dt = wp_slimstat_db::get_oldest_visit( '1=1', false );
		$results = array();

		$results[ 0 ][ 'metric' ] = __( 'Dataset Size', 'wp-slimstat' );
		$results[ 0 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'id', '1=1', false ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 0 ][ 'tooltip' ] = __( 'Total number of records stored in the database.', 'wp-slimstat' );

		$results[ 1 ][ 'metric' ] = __( 'DB Size', 'wp-slimstat' );
		$results[ 1 ][ 'value' ] = wp_slimstat_db::get_data_size();

		$results[ 2 ][ 'metric' ] = __( 'Tracking Enabled', 'wp-slimstat' );
		$results[ 2 ][ 'value' ] = __( ucfirst( wp_slimstat::$options[ 'is_tracking' ] ), 'wp-slimstat' );

		$results[ 3 ][ 'metric' ] = __( 'Javascript Mode', 'wp-slimstat' );
		$results[ 3 ][ 'value' ] = __( ucfirst( wp_slimstat::$options[ 'javascript_mode' ] ), 'wp-slimstat' );

		$results[ 4 ][ 'metric' ] = __( 'Tracking Browser Caps', 'wp-slimstat' );
		$results[ 4 ][ 'value' ] = __( ucfirst( wp_slimstat::$options[ 'enable_javascript' ] ), 'wp-slimstat' );

		$results[ 5 ][ 'metric' ] = __( 'Auto purge', 'wp-slimstat' );
		$results[ 5 ][ 'value' ] = ( wp_slimstat::$options[ 'auto_purge' ] > 0 ) ? wp_slimstat::$options[ 'auto_purge' ] . ' ' . __( 'days', 'wp-slimstat' ) : __( 'Off', 'wp-slimstat' );

		$results[ 6 ][ 'metric' ] = __( 'Oldest pageview', 'wp-slimstat' );
		$results[ 6 ][ 'value' ] = ( $dt == null ) ? __( 'No visits', 'wp-slimstat' ) : date_i18n( wp_slimstat::$options[ 'date_format' ], $dt );

		$results[ 7 ][ 'metric' ] = __( 'Geolocation', 'wp-slimstat' );
		$results[ 7 ][ 'value' ] = date_i18n( wp_slimstat::$options[ 'date_format' ], @filemtime( wp_slimstat::$maxmind_path ) );
		$results[ 7 ][ 'tooltip' ] = __( 'Date when the MaxMind Geolocation database was last updated.', 'wp-slimstat' );

		return $results;
	}

	public static function get_overview_summary() {
		$days_in_range = ceil( ( wp_slimstat_db::$filters_normalized[ 'utime' ][ 'end' ] - wp_slimstat_db::$filters_normalized[ 'utime' ][ 'start' ] ) / 86400 ); 
		$results = array();

		$results[ 0 ][ 'metric' ] = __( 'Pageviews', 'wp-slimstat' );
		$results[ 0 ][ 'value' ] = number_format( self::$pageviews, 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 0 ][ 'tooltip' ] = __( 'A request to load a single HTML file. Slimstat logs a "pageview" each time the tracking code is executed.', 'wp-slimstat' );

		$results[ 1 ][ 'metric' ] = __( 'Days in Range', 'wp-slimstat' );
		$results[ 1 ][ 'value' ] = $days_in_range;

		$results[ 2 ][ 'metric' ] = __( 'Average Daily Pageviews', 'wp-slimstat' );
		$results[ 2 ][ 'value' ] = number_format( intval( self::$pageviews/$days_in_range ), 0, '', wp_slimstat_db::$formats['thousand'] );
		$results[ 2 ][ 'tooltip' ] = __( 'How many pages have been visited on average every day during the current period.', 'wp-slimstat' );

		$results[ 3 ][ 'metric' ] = __( 'From Search Results', 'wp-slimstat' );
		$results[ 3 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'id', 'searchterms IS NOT NULL' ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 3 ][ 'tooltip' ] = __( 'Visitors who landed on your site after searching for a keyword on Google, Yahoo, etc.', 'wp-slimstat' );

		$results[ 4 ][ 'metric' ] = __( 'Unique IPs', 'wp-slimstat' );
		$results[ 4 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'ip' ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 4 ][ 'tooltip' ] = __( 'Used to differentiate between multiple requests to download a file from one internet address (IP) and requests originating from many distinct addresses', 'wp-slimstat' );

		$results[ 5 ][ 'metric' ] = __( 'Last 30 minutes', 'wp-slimstat' );
		$results[ 5 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'id', 'dt > ' . ( date_i18n( 'U' ) - 1800 ), false ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );

		$results[ 6 ][ 'metric' ] = __( 'Today', 'wp-slimstat' );
		$results[ 6 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'id', 'dt > ' . ( date_i18n( 'U', mktime( 0, 0, 0, date_i18n( 'm' ), date_i18n( 'd' ), date_i18n( 'Y' ) ) ) ), false ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );

		$results[ 7 ][ 'metric' ] = __( 'Yesterday', 'wp-slimstat' );
		$results[ 7 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'id', 'dt BETWEEN ' . ( date_i18n( 'U', mktime( 0, 0, 0, date_i18n( 'm' ), date_i18n( 'd' ) - 1, date_i18n( 'Y' ) ) ) ) . ' AND ' . ( date_i18n( 'U', mktime( 23, 59, 59, date_i18n( 'm' ), date_i18n( 'd' ) - 1, date_i18n( 'Y' ) ) ) ), false ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );

		return $results;
	}

	public static function get_plugins() {
		$wp_slim_plugins = array( 'flash', 'silverlight', 'acrobat', 'java', 'mediaplayer', 'director', 'real', 'quicktime' );
		$total_human_hits = wp_slimstat_db::count_records( 'id', 'visit_id > 0 AND browser_type <> 1' );
		$results = array();

		foreach ( $wp_slim_plugins as $i => $a_plugin ) {
			$count_results = wp_slimstat_db::count_records( 'id', "plugins LIKE '%{$a_plugin}%'" );
			$results[ $i ][ 'metric' ] = ucfirst( $a_plugin );
			$results[ $i ][ 'value' ] = ( $total_human_hits > 0 ) ? number_format( ( 100 * $count_results / $total_human_hits ), 2, wp_slimstat_db::$formats[ 'decimal' ], wp_slimstat_db::$formats[ 'thousand' ] ) : 0;
			$results[ $i ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";
		}

		return $results;
	}

	public static function get_visitors_summary() {
		$results = array();
		$total_human_hits = wp_slimstat_db::count_records( 'id', 'visit_id > 0 AND browser_type <> 1');
		$new_visitors = wp_slimstat_db::count_records_having( 'ip', 'visit_id > 0 AND browser_type <> 1', 'COUNT(visit_id) = 1' );
		$new_visitors_rate = ( $total_human_hits > 0) ? ( 100 * $new_visitors / $total_human_hits ) : 0;
		$metrics_per_visit = wp_slimstat_db::get_max_and_average_pages_per_visit();
		if ( intval( $new_visitors_rate ) > 99 ) {
			$new_visitors_rate = '100';
		}
		
		$results[ 0 ][ 'metric' ] = __( 'Visits', 'wp-slimstat' );
		$results[ 0 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'visit_id', 'visit_id > 0 AND browser_type <> 1' ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 0 ][ 'tooltip' ] = __( 'A visit is a session of at most 30 minutes. Returning visitors are counted multiple times if they perform multiple visits.', 'wp-slimstat' );

		$results[ 1 ][ 'metric' ] = __( 'Unique IPs', 'wp-slimstat' );
		$results[ 1 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'ip', 'visit_id > 0 AND browser_type <> 1' ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 1 ][ 'tooltip' ] = __( 'It includes only traffic generated by human visitors.', 'wp-slimstat' );

		$results[ 2 ][ 'metric' ] = __( 'Bounce rate', 'wp-slimstat' );
		$results[ 2 ][ 'value' ] = number_format( $new_visitors_rate, 2, wp_slimstat_db::$formats[ 'decimal' ], wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 2 ][ 'tooltip' ] = __( 'Percentage of single-page visits, i.e. visits in which the person left your site from the entrance page.', 'wp-slimstat' );

		$results[ 3 ][ 'metric' ] = __( 'Known visitors', 'wp-slimstat' );
		$results[ 3 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'username' ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 3 ][ 'tooltip' ] = __( 'Visitors who had previously left a comment on your blog.', 'wp-slimstat' );

		$results[ 4 ][ 'metric' ] = __( 'New visitors', 'wp-slimstat' );
		$results[ 4 ][ 'value' ] = number_format( $new_visitors, 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 4 ][ 'tooltip' ] = __( 'Human users who visited your site only once.', 'wp-slimstat' );

		$results[ 5 ][ 'metric' ] = __( 'Bots', 'wp-slimstat' );
		$results[ 5 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'id', 'browser_type = 1' ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );

		$results[ 6 ][ 'metric' ] = __( 'Pageviews per visit', 'wp-slimstat' );
		$results[ 6 ][ 'value' ] = number_format( $metrics_per_visit[ 0 ][ 'avghits' ], 2, wp_slimstat_db::$formats[ 'decimal' ], wp_slimstat_db::$formats[ 'thousand' ] );

		$results[ 7 ][ 'metric' ] = __( 'Longest visit', 'wp-slimstat' );
		$results[ 7 ][ 'value' ] = number_format( $metrics_per_visit[ 0 ][ 'maxhits' ], 0, '', wp_slimstat_db::$formats[ 'thousand' ] ) . ' ' . __( 'hits', 'wp-slimstat' );

		return $results;
	}

	public static function get_visits_duration() {
		$total_human_visits = wp_slimstat_db::count_records( 'visit_id', 'visit_id > 0 AND browser_type <> 1' );
		$results = array();

		$count_results = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', '	GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) >= 0 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 30' );
		$average_time = 30 * $count_results;
		$results[ 0 ][ 'metric' ] = __( '0 - 30 seconds', 'wp-slimstat' );
		$results[ 0 ][ 'value' ] = ( ( $total_human_visits > 0 ) ? number_format( ( 100 * $count_results / $total_human_visits ), 2, wp_slimstat_db::$formats[ 'decimal' ], wp_slimstat_db::$formats[ 'thousand' ] ) : 0 ) . '%';
		$results[ 0 ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";

		$count_results = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 30 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 60' );
		$average_time += 60 * $count_results;
		$results[ 1 ][ 'metric' ] = __( '31 - 60 seconds', 'wp-slimstat' );
		$results[ 1 ][ 'value' ] = ( ( $total_human_visits > 0 ) ? number_format( ( 100 * $count_results / $total_human_visits ), 2, wp_slimstat_db::$formats[ 'decimal' ], wp_slimstat_db::$formats[ 'thousand' ] ) : 0 ) . '%';
		$results[ 1 ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";

		$count_results = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 60 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 180' );
		$average_time += 180 * $count_results;
		$results[ 2 ][ 'metric' ] = __( '1 - 3 minutes', 'wp-slimstat' );
		$results[ 2 ][ 'value' ] = ( ( $total_human_visits > 0 ) ? number_format( ( 100 * $count_results / $total_human_visits ), 2, wp_slimstat_db::$formats[ 'decimal' ], wp_slimstat_db::$formats[ 'thousand' ] ) : 0 ) . '%';
		$results[ 2 ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";

		$count_results = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 180 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 300' );
		$average_time += 300 * $count_results;
		$results[ 3 ][ 'metric' ] = __( '3 - 5 minutes', 'wp-slimstat' );
		$results[ 3 ][ 'value' ] = ( ( $total_human_visits > 0 ) ? number_format( ( 100 * $count_results / $total_human_visits ), 2, wp_slimstat_db::$formats[ 'decimal' ], wp_slimstat_db::$formats[ 'thousand' ] ) : 0 ) . '%';
		$results[ 3 ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";

		$count_results = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 300 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 420' );
		$average_time += 420 * $count_results;
		$results[ 4 ][ 'metric' ] = __( '5 - 7 minutes', 'wp-slimstat' );
		$results[ 4 ][ 'value' ] = ( ( $total_human_visits > 0 ) ? number_format( ( 100 * $count_results / $total_human_visits ), 2, wp_slimstat_db::$formats[ 'decimal' ], wp_slimstat_db::$formats[ 'thousand' ] ) : 0 ) . '%';
		$results[ 4 ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";

		$count_results = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 420 AND GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) <= 600' );
		$average_time += 600* $count_results;
		$results[ 5 ][ 'metric' ] = __( '7 - 10 minutes', 'wp-slimstat' );
		$results[ 5 ][ 'value' ] = ( ( $total_human_visits > 0 ) ? number_format( ( 100 * $count_results / $total_human_visits ), 2, wp_slimstat_db::$formats[ 'decimal' ], wp_slimstat_db::$formats[ 'thousand' ] ) : 0 ) . '%';
		$results[ 5 ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";

		$count_results = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'GREATEST( MAX( dt ), MAX( dt_out ) ) - MIN( dt ) > 600' );
		$average_time += 900 * $count_results;
		$results[ 6 ][ 'metric' ] = __( 'More than 10 minutes', 'wp-slimstat' );
		$results[ 6 ][ 'value' ] = ( ( $total_human_visits > 0 ) ? number_format( ( 100 * $count_results / $total_human_visits ), 2, wp_slimstat_db::$formats[ 'decimal' ], wp_slimstat_db::$formats[ 'thousand' ] ) : 0 ) . '%';
		$results[ 6 ][ 'details' ] = __( 'Hits', 'wp-slimstat' ) . ": $count_results";

		if ( $total_human_visits > 0 ) {
			$average_time /= $total_human_visits;
			$average_time = date('m:s', intval($average_time));
		}
		else{
			$average_time = '0:00';
		}

		$results[ 7 ][ 'metric' ] = __( 'Average visit duration', 'wp-slimstat' );
		$results[ 7 ][ 'value' ] = $average_time;
		$results[ 7 ][ 'details' ] = '';

		return $results;
	}
	
	public static function get_traffic_sources_summary() {
		$results = array();
		$total_human_hits = wp_slimstat_db::count_records( 'id', 'visit_id > 0 AND browser_type <> 1' );
		$new_visitors = wp_slimstat_db::count_records_having( 'ip', 'visit_id > 0', 'COUNT(visit_id) = 1' );
		$new_visitors_rate = ($total_human_hits > 0)?sprintf("%01.2f", (100*$new_visitors/$total_human_hits)):0;
		if (intval($new_visitors_rate) > 99) {
			$new_visitors_rate = '100';
		}

		$results[ 0 ][ 'metric' ] = __( 'Pageviews', 'wp-slimstat' );
		$results[ 0 ][ 'value' ] = number_format( self::$pageviews, 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 0 ][ 'tooltip' ] = __( 'A request to load a single HTML file. Slimstat logs a "pageview" each time the tracking code is executed.', 'wp-slimstat' );

		$results[ 1 ][ 'metric' ] = __( 'Unique Referrers', 'wp-slimstat' );
		$results[ 1 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'referer', "referer NOT LIKE '%{$_SERVER['SERVER_NAME']}%'" ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 1 ][ 'tooltip' ] = __( 'A referrer (or referring site) is the site that a visitor previously visited before following a link to your site.', 'wp-slimstat' );

		$results[ 2 ][ 'metric' ] = __( 'Direct Pageviews', 'wp-slimstat' );
		$results[ 2 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'id', 'resource IS NULL' ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 2 ][ 'tooltip' ] = __( "Visitors who visited the site by typing the URL directly into their browser. <em>Direct</em> can also refer to the visitors who clicked on the links from their bookmarks/favorites, untagged links within emails, or links from documents that don't include tracking variables.", 'wp-slimstat' );

		$results[ 3 ][ 'metric' ] = __( 'From a search result', 'wp-slimstat' );
		$results[ 3 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'id', "searchterms IS NOT NULL AND referer IS NOT NULL AND referer NOT LIKE '%" . home_url() . "%'" ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 3 ][ 'tooltip' ] = __( "Visitors who came to your site via searches on Google or some other search engine.", 'wp-slimstat' );

		$results[ 4 ][ 'metric' ] = __( 'Unique Landing Pages', 'wp-slimstat' );
		$results[ 4 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'resource' ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 4 ][ 'tooltip' ] = __( "The first page that a user views during a session. This is also known as the <em>entrance page</em>. For example, if they search for 'Brooklyn Office Space,' and they land on your home page, it gets counted (for that visit) as a landing page.", 'wp-slimstat' );

		$results[ 5 ][ 'metric' ] = __( 'Bounce Pages', 'wp-slimstat' );
		$results[ 5 ][ 'value' ] = number_format( wp_slimstat_db::count_bouncing_pages(), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 5 ][ 'tooltip' ] = __( 'Number of single page visits to your site over the selected period.', 'wp-slimstat' );

		$results[ 6 ][ 'metric' ] = __( 'New Visitors Rate', 'wp-slimstat' );
		$results[ 6 ][ 'value' ] = number_format( $new_visitors_rate, 2, wp_slimstat_db::$formats[ 'decimal' ], wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 6 ][ 'tooltip' ] = __( 'Percentage of single page visits, i.e. visits in which the person left your site from the entrance page.', 'wp-slimstat' );

		$results[ 7 ][ 'metric' ] = __( 'Currently from search engines', 'wp-slimstat' );
		$results[ 7 ][ 'value' ] = number_format( wp_slimstat_db::count_records( 'id', "searchterms IS NOT NULL  AND referer IS NOT NULL AND referer NOT LIKE '%" . home_url() . "%' AND dt > UNIX_TIMESTAMP() - 300", false ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
		$results[ 7 ][ 'tooltip' ] = __( 'Visitors who visited the site in the last 5 minutes coming from a search engine.', 'wp-slimstat' );

		return $results;
	}

	public static function show_rankings(){
		$options = array('timeout' => 1, 'headers' => array('Accept' => 'application/json'));
		$site_url_array = parse_url(home_url());
		
		// Check if we have a valied transient
		if (false === ($rankings = get_transient( 'slimstat_ranking_values' ))){
			$rankings = array('google_index' => 0, 'google_backlinks' => 0, 'facebook_likes' => 0, 'facebook_shares' => 0, 'facebook_clicks' => 0, 'alexa_world_rank' => 0, 'alexa_country_rank' => 0, 'alexa_popularity' => 0);

			// Google Index
			$response = @wp_remote_get('https://ajax.googleapis.com/ajax/services/search/web?v=1.0&q=site:'.$site_url_array['host'], $options);
			if (!is_wp_error($response) && isset($response['response']['code']) && ($response['response']['code'] == 200) && !empty($response['body'])){
				$response = @json_decode($response['body']);
				if (is_object($response) && !empty($response->responseData->cursor->resultCount)){
					$rankings['google_index'] = (int)$response->responseData->cursor->resultCount;
				}
			}
			
			// Google Backlinks
			$response = @wp_remote_get('https://ajax.googleapis.com/ajax/services/search/web?v=1.0&q=link:'.$site_url_array['host'], $options);
			if (!is_wp_error($response) && isset($response['response']['code']) && ($response['response']['code'] == 200) && !empty($response['body'])){
				$response = @json_decode($response['body']);
				if (is_object($response) && !empty($response->responseData->cursor->resultCount)){
					$rankings['google_backlinks'] = (int)$response->responseData->cursor->resultCount;
				}
			}
			
			// Facebook
			$options['headers']['Accept'] = 'text/xml';
			$response = @wp_remote_get("https://api.facebook.com/method/fql.query?query=select%20%20like_count,%20total_count,%20share_count,%20click_count%20from%20link_stat%20where%20url='".$site_url_array['host']."'", $options);
			if (!is_wp_error($response) && isset($response['response']['code']) && ($response['response']['code'] == 200) && !empty($response['body'])){
				$response = new SimpleXMLElement($response['body']);
				if (is_object($response) && is_object($response->link_stat) && !empty($response->link_stat->like_count)){
					$rankings['facebook_likes'] = (int)$response->link_stat->like_count;
					$rankings['facebook_shares'] = (int)$response->link_stat->share_count;
					$rankings['facebook_clicks'] = (int)$response->link_stat->click_count;
				}
			}

			// Alexa
			$response = @wp_remote_get("http://data.alexa.com/data?cli=10&dat=snbamz&url=".$site_url_array['host'], $options);
			if (!is_wp_error($response) && isset($response['response']['code']) && ($response['response']['code'] == 200) && !empty($response['body'])){
				$response = new SimpleXMLElement($response['body']);
				if (is_object($response->SD[1]) && is_object($response->SD[1]->POPULARITY)){
					if ($response->SD[1]->POPULARITY && $response->SD[1]->POPULARITY->attributes()){
						$attributes = $response->SD[1]->POPULARITY->attributes();
						$rankings['alexa_popularity'] = (int)$attributes['TEXT'];
					}

					if ($response->SD[1]->REACH && $response->SD[1]->REACH->attributes()){
						$attributes = $response->SD[1]->REACH->attributes();
						$rankings['alexa_world_rank'] = (int)$attributes['RANK'];
					}

					if ($response->SD[1]->COUNTRY && $response->SD[1]->COUNTRY->attributes()){
						$attributes = $response->SD[1]->COUNTRY->attributes();
						$rankings['alexa_country_rank'] = (int)$attributes['RANK'];
					}
				}
			}

			// Store rankings as transients for 12 hours
			set_transient('slimstat_ranking_values', $rankings, 43200);
		}
		?>
		
		<p><?php self::inline_help(__("Number of pages in your site included in Google's index.",'wp-slimstat')) ?>
			<?php _e('Google Index', 'wp-slimstat') ?> <span><?php echo number_format($rankings['google_index'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("Number of pages, according to Google, that link back to your site.",'wp-slimstat')) ?>
			<?php _e('Google Backlinks', 'wp-slimstat') ?> <span><?php echo number_format($rankings['google_backlinks'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("How many times the Facebook Like button has been approximately clicked on your site.",'wp-slimstat')) ?>
			<?php _e('Facebook Likes', 'wp-slimstat') ?> <span><?php echo number_format($rankings['facebook_likes'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("How many times your site has been shared by someone on the social network.",'wp-slimstat')) ?>
			<?php _e('Facebook Shares', 'wp-slimstat') ?> <span><?php echo number_format($rankings['facebook_shares'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("How many times links to your website have been clicked on Facebook.",'wp-slimstat')) ?>
			<?php _e('Facebook Clicks', 'wp-slimstat') ?> <span><?php echo number_format($rankings['facebook_clicks'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("Alexa is a subsidiary company of Amazon.com which provides commercial web traffic data.",'wp-slimstat')) ?>
			<?php _e('Alexa World Rank', 'wp-slimstat') ?> <span><?php echo number_format($rankings['alexa_world_rank'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Alexa Country Rank', 'wp-slimstat') ?> <span><?php echo number_format($rankings['alexa_country_rank'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Alexa Popularity', 'wp-slimstat') ?> <span><?php echo number_format($rankings['alexa_popularity'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p><?php

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die();
		}
	}

	public static function get_your_blog(){
		if ( false === ( $results = get_transient( 'slimstat_your_content' ) ) ) {
			$results = array();

			$results[ 0 ][ 'metric' ] = __( 'Content Items', 'wp-slimstat' );
			$results[ 0 ][ 'value' ] = number_format( $GLOBALS[ 'wpdb' ]->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type <> 'revision' AND post_status <> 'auto-draft'" ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
			$results[ 0 ][ 'tooltip' ] = __( 'This value includes not only posts, but also custom post types, regardless of their status', 'wp-slimstat' );

			$results[ 1 ][ 'metric' ] = __( 'Posts', 'wp-slimstat' );
			$results[ 1 ][ 'value' ] = $GLOBALS[ 'wpdb' ]->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'post'" );

			$results[ 2 ][ 'metric' ] = __( 'Pages', 'wp-slimstat' );
			$results[ 2 ][ 'value' ] = number_format( $GLOBALS[ 'wpdb' ]->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'page'" ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );

			$results[ 3 ][ 'metric' ] = __( 'Attachments', 'wp-slimstat' );
			$results[ 3 ][ 'value' ] = number_format( $GLOBALS[ 'wpdb' ]->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'attachment'" ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );

			$results[ 4 ][ 'metric' ] = __( 'Revisions', 'wp-slimstat' );
			$results[ 4 ][ 'value' ] = number_format( $GLOBALS[ 'wpdb' ]->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'revision'" ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );

			$results[ 5 ][ 'metric' ] = __( 'Comments', 'wp-slimstat' );
			$results[ 5 ][ 'value' ] = $GLOBALS[ 'wpdb' ]->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->comments}" );
			
			$results[ 6 ][ 'metric' ] = __( 'Avg Comments per Post', 'wp-slimstat' );
			$results[ 6 ][ 'value' ] = number_format( !empty( $results[ 1 ][ 'value' ] ) ? $results[ 5 ][ 'value' ] / $results[ 1 ][ 'value' ] : 0, 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
			
			$results[ 7 ][ 'metric' ] = __( 'Avg Server Latency', 'wp-slimstat' );
			$results[ 7 ][ 'value' ] = number_format( wp_slimstat::$wpdb->get_var( "SELECT AVG(server_latency) FROM {$GLOBALS[ 'wpdb' ]->prefix }slim_stats WHERE server_latency <> 0" ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
			$results[ 7 ][ 'tooltip' ] = __( 'Latency is the amount of time it takes for the host server to receive and process a request for a page object. The amount of latency depends largely on how far away the user is from the server.', 'wp-slimstat' );

			$results[ 1 ][ 'value' ] = number_format( $results[ 1 ][ 'value' ], 0, '', wp_slimstat_db::$formats[ 'thousand' ] );
			$results[ 5 ][ 'value' ] = number_format( $results[ 5 ][ 'value' ], 0, '', wp_slimstat_db::$formats[ 'thousand' ] );

			// Store values as transients for 30 minutes
			set_transient( 'slimstat_your_content', $results, 1800 );
		}

		return $results;
	}

	public static function show_events( $_args = array() ) {
		$all_results = call_user_func( $_args[ 'raw' ] , $_args );

		$results = array_slice(
			$all_results,
			wp_slimstat_db::$filters_normalized[ 'misc' ][ 'start_from' ],
			wp_slimstat::$options[ 'rows_to_show' ]
		);

		// Count the results
		$count_page_results = count( $results );

		if ($count_page_results == 0){
			echo '<p class="nodata">'.__('No data to display','wp-slimstat').'</p>';
			
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				die();
			}
			else{
				return array();
			}
		}

		self::report_pagination( $count_page_results, count( $all_results ) );
		$is_expanded = ( wp_slimstat::$options['expand_details'] == 'yes' ) ? ' expanded' : '';

		foreach ( $results as $a_result ) {
			echo "<p class='slimstat-tooltip-trigger'>{$a_result[ 'notes' ]} <b class='slimstat-tooltip-content$is_expanded'>" . __( 'Type', 'wp-slimstat' ) . ": {$a_result[ 'type' ]}";

			if ( !empty( $a_result[ 'dt' ] ) ) {
				$date_time = date_i18n( wp_slimstat::$options[ 'date_format' ] . ' ' . wp_slimstat::$options[ 'time_format' ], $a_result[ 'dt' ], true );
				echo '<br/>' . __( 'Coordinates', 'wp-slimstat' ) . ": {$a_result[ 'position' ]}<br/>" . __( 'Date', 'wp-slimstat' ) . ": $date_time";
			}
			if ( !empty( $a_result[ 'counthits' ] ) ) {
				echo '<br/>' . __( 'Hits', 'wp-slimstat' ) . ": {$a_result[ 'counthits' ]}";
			}

			echo "</b></p>";
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die();
		}
	}

	public static function show_world_map() {
		$countries = wp_slimstat_db::get_top('country');
		$data_areas = array('xx'=>'{id:"XX",balloonText:"'.__('c-xx','wp-slimstat').': 0",value:0,color:"#ededed"}','af'=>'{id:"AF",balloonText:"'.__('c-af','wp-slimstat').': 0",value:0,color:"#ededed"}','ax'=>'{id:"AX",balloonText:"'.__('c-ax','wp-slimstat').': 0",value:0,color:"#ededed"}','al'=>'{id:"AL",balloonText:"'.__('c-al','wp-slimstat').': 0",value:0,color:"#ededed"}','dz'=>'{id:"DZ",balloonText:"'.__('c-dz','wp-slimstat').': 0",value:0,color:"#ededed"}','ad'=>'{id:"AD",balloonText:"'.__('c-ad','wp-slimstat').': 0",value:0,color:"#ededed"}','ao'=>'{id:"AO",balloonText:"'.__('c-ao','wp-slimstat').': 0",value:0,color:"#ededed"}','ai'=>'{id:"AI",balloonText:"'.__('c-ai','wp-slimstat').': 0",value:0,color:"#ededed"}','ag'=>'{id:"AG",balloonText:"'.__('c-ag','wp-slimstat').': 0",value:0,color:"#ededed"}','ar'=>'{id:"AR",balloonText:"'.__('c-ar','wp-slimstat').': 0",value:0,color:"#ededed"}','am'=>'{id:"AM",balloonText:"'.__('c-am','wp-slimstat').': 0",value:0,color:"#ededed"}','aw'=>'{id:"AW",balloonText:"'.__('c-aw','wp-slimstat').': 0",value:0,color:"#ededed"}','au'=>'{id:"AU",balloonText:"'.__('c-au','wp-slimstat').': 0",value:0,color:"#ededed"}','at'=>'{id:"AT",balloonText:"'.__('c-at','wp-slimstat').': 0",value:0,color:"#ededed"}','az'=>'{id:"AZ",balloonText:"'.__('c-az','wp-slimstat').': 0",value:0,color:"#ededed"}','bs'=>'{id:"BS",balloonText:"'.__('c-bs','wp-slimstat').': 0",value:0,color:"#ededed"}','bh'=>'{id:"BH",balloonText:"'.__('c-bh','wp-slimstat').': 0",value:0,color:"#ededed"}','bd'=>'{id:"BD",balloonText:"'.__('c-bd','wp-slimstat').': 0",value:0,color:"#ededed"}','bb'=>'{id:"BB",balloonText:"'.__('c-bb','wp-slimstat').': 0",value:0,color:"#ededed"}','by'=>'{id:"BY",balloonText:"'.__('c-by','wp-slimstat').': 0",value:0,color:"#ededed"}','be'=>'{id:"BE",balloonText:"'.__('c-be','wp-slimstat').': 0",value:0,color:"#ededed"}','bz'=>'{id:"BZ",balloonText:"'.__('c-bz','wp-slimstat').': 0",value:0,color:"#ededed"}','bj'=>'{id:"BJ",balloonText:"'.__('c-bj','wp-slimstat').': 0",value:0,color:"#ededed"}','bm'=>'{id:"BM",balloonText:"'.__('c-bm','wp-slimstat').': 0",value:0,color:"#ededed"}','bt'=>'{id:"BT",balloonText:"'.__('c-bt','wp-slimstat').': 0",value:0,color:"#ededed"}','bo'=>'{id:"BO",balloonText:"'.__('c-bo','wp-slimstat').': 0",value:0,color:"#ededed"}','ba'=>'{id:"BA",balloonText:"'.__('c-ba','wp-slimstat').': 0",value:0,color:"#ededed"}','bw'=>'{id:"BW",balloonText:"'.__('c-bw','wp-slimstat').': 0",value:0,color:"#ededed"}','br'=>'{id:"BR",balloonText:"'.__('c-br','wp-slimstat').': 0",value:0,color:"#ededed"}','bn'=>'{id:"BN",balloonText:"'.__('c-bn','wp-slimstat').': 0",value:0,color:"#ededed"}','bg'=>'{id:"BG",balloonText:"'.__('c-bg','wp-slimstat').': 0",value:0,color:"#ededed"}','bf'=>'{id:"BF",balloonText:"'.__('c-bf','wp-slimstat').': 0",value:0,color:"#ededed"}','bi'=>'{id:"BI",balloonText:"'.__('c-bi','wp-slimstat').': 0",value:0,color:"#ededed"}','kh'=>'{id:"KH",balloonText:"'.__('c-kh','wp-slimstat').': 0",value:0,color:"#ededed"}','cm'=>'{id:"CM",balloonText:"'.__('c-cm','wp-slimstat').': 0",value:0,color:"#ededed"}','ca'=>'{id:"CA",balloonText:"'.__('c-ca','wp-slimstat').': 0",value:0,color:"#ededed"}','cv'=>'{id:"CV",balloonText:"'.__('c-cv','wp-slimstat').': 0",value:0,color:"#ededed"}','ky'=>'{id:"KY",balloonText:"'.__('c-ky','wp-slimstat').': 0",value:0,color:"#ededed"}','cf'=>'{id:"CF",balloonText:"'.__('c-cf','wp-slimstat').': 0",value:0,color:"#ededed"}','td'=>'{id:"TD",balloonText:"'.__('c-td','wp-slimstat').': 0",value:0,color:"#ededed"}','cl'=>'{id:"CL",balloonText:"'.__('c-cl','wp-slimstat').': 0",value:0,color:"#ededed"}','cn'=>'{id:"CN",balloonText:"'.__('c-cn','wp-slimstat').': 0",value:0,color:"#ededed"}','co'=>'{id:"CO",balloonText:"'.__('c-co','wp-slimstat').': 0",value:0,color:"#ededed"}','km'=>'{id:"KM",balloonText:"'.__('c-km','wp-slimstat').': 0",value:0,color:"#ededed"}','cg'=>'{id:"CG",balloonText:"'.__('c-cg','wp-slimstat').': 0",value:0,color:"#ededed"}','cd'=>'{id:"CD",balloonText:"'.__('c-cd','wp-slimstat').': 0",value:0,color:"#ededed"}','cr'=>'{id:"CR",balloonText:"'.__('c-cr','wp-slimstat').': 0",value:0,color:"#ededed"}','ci'=>'{id:"CI",balloonText:"'.__('c-ci','wp-slimstat').': 0",value:0,color:"#ededed"}','hr'=>'{id:"HR",balloonText:"'.__('c-hr','wp-slimstat').': 0",value:0,color:"#ededed"}','cu'=>'{id:"CU",balloonText:"'.__('c-cu','wp-slimstat').': 0",value:0,color:"#ededed"}','cy'=>'{id:"CY",balloonText:"'.__('c-cy','wp-slimstat').': 0",value:0,color:"#ededed"}','cz'=>'{id:"CZ",balloonText:"'.__('c-cz','wp-slimstat').': 0",value:0,color:"#ededed"}','dk'=>'{id:"DK",balloonText:"'.__('c-dk','wp-slimstat').': 0",value:0,color:"#ededed"}','dj'=>'{id:"DJ",balloonText:"'.__('c-dj','wp-slimstat').': 0",value:0,color:"#ededed"}','dm'=>'{id:"DM",balloonText:"'.__('c-dm','wp-slimstat').': 0",value:0,color:"#ededed"}','do'=>'{id:"DO",balloonText:"'.__('c-do','wp-slimstat').': 0",value:0,color:"#ededed"}','ec'=>'{id:"EC",balloonText:"'.__('c-ec','wp-slimstat').': 0",value:0,color:"#ededed"}','eg'=>'{id:"EG",balloonText:"'.__('c-eg','wp-slimstat').': 0",value:0,color:"#ededed"}','sv'=>'{id:"SV",balloonText:"'.__('c-sv','wp-slimstat').': 0",value:0,color:"#ededed"}','gq'=>'{id:"GQ",balloonText:"'.__('c-gq','wp-slimstat').': 0",value:0,color:"#ededed"}','er'=>'{id:"ER",balloonText:"'.__('c-er','wp-slimstat').': 0",value:0,color:"#ededed"}','ee'=>'{id:"EE",balloonText:"'.__('c-ee','wp-slimstat').': 0",value:0,color:"#ededed"}','et'=>'{id:"ET",balloonText:"'.__('c-et','wp-slimstat').': 0",value:0,color:"#ededed"}','fo'=>'{id:"FO",balloonText:"'.__('c-fo','wp-slimstat').': 0",value:0,color:"#ededed"}','fk'=>'{id:"FK",balloonText:"'.__('c-fk','wp-slimstat').': 0",value:0,color:"#ededed"}','fj'=>'{id:"FJ",balloonText:"'.__('c-fj','wp-slimstat').': 0",value:0,color:"#ededed"}','fi'=>'{id:"FI",balloonText:"'.__('c-fi','wp-slimstat').': 0",value:0,color:"#ededed"}','fr'=>'{id:"FR",balloonText:"'.__('c-fr','wp-slimstat').': 0",value:0,color:"#ededed"}','gf'=>'{id:"GF",balloonText:"'.__('c-gf','wp-slimstat').': 0",value:0,color:"#ededed"}','ga'=>'{id:"GA",balloonText:"'.__('c-ga','wp-slimstat').': 0",value:0,color:"#ededed"}','gm'=>'{id:"GM",balloonText:"'.__('c-gm','wp-slimstat').': 0",value:0,color:"#ededed"}','ge'=>'{id:"GE",balloonText:"'.__('c-ge','wp-slimstat').': 0",value:0,color:"#ededed"}','de'=>'{id:"DE",balloonText:"'.__('c-de','wp-slimstat').': 0",value:0,color:"#ededed"}','gh'=>'{id:"GH",balloonText:"'.__('c-gh','wp-slimstat').': 0",value:0,color:"#ededed"}','gr'=>'{id:"GR",balloonText:"'.__('c-gr','wp-slimstat').': 0",value:0,color:"#ededed"}','gl'=>'{id:"GL",balloonText:"'.__('c-gl','wp-slimstat').': 0",value:0,color:"#ededed"}','gd'=>'{id:"GD",balloonText:"'.__('c-gd','wp-slimstat').': 0",value:0,color:"#ededed"}','gp'=>'{id:"GP",balloonText:"'.__('c-gp','wp-slimstat').': 0",value:0,color:"#ededed"}','gt'=>'{id:"GT",balloonText:"'.__('c-gt','wp-slimstat').': 0",value:0,color:"#ededed"}','gn'=>'{id:"GN",balloonText:"'.__('c-gn','wp-slimstat').': 0",value:0,color:"#ededed"}','gw'=>'{id:"GW",balloonText:"'.__('c-gw','wp-slimstat').': 0",value:0,color:"#ededed"}','gy'=>'{id:"GY",balloonText:"'.__('c-gy','wp-slimstat').': 0",value:0,color:"#ededed"}','ht'=>'{id:"HT",balloonText:"'.__('c-ht','wp-slimstat').': 0",value:0,color:"#ededed"}','hn'=>'{id:"HN",balloonText:"'.__('c-hn','wp-slimstat').': 0",value:0,color:"#ededed"}','hk'=>'{id:"HK",balloonText:"'.__('c-hk','wp-slimstat').': 0",value:0,color:"#ededed"}','hu'=>'{id:"HU",balloonText:"'.__('c-hu','wp-slimstat').': 0",value:0,color:"#ededed"}','is'=>'{id:"IS",balloonText:"'.__('c-is','wp-slimstat').': 0",value:0,color:"#ededed"}','in'=>'{id:"IN",balloonText:"'.__('c-in','wp-slimstat').': 0",value:0,color:"#ededed"}','id'=>'{id:"ID",balloonText:"'.__('c-id','wp-slimstat').': 0",value:0,color:"#ededed"}','ir'=>'{id:"IR",balloonText:"'.__('c-ir','wp-slimstat').': 0",value:0,color:"#ededed"}','iq'=>'{id:"IQ",balloonText:"'.__('c-iq','wp-slimstat').': 0",value:0,color:"#ededed"}','ie'=>'{id:"IE",balloonText:"'.__('c-ie','wp-slimstat').': 0",value:0,color:"#ededed"}','il'=>'{id:"IL",balloonText:"'.__('c-il','wp-slimstat').': 0",value:0,color:"#ededed"}','it'=>'{id:"IT",balloonText:"'.__('c-it','wp-slimstat').': 0",value:0,color:"#ededed"}','jm'=>'{id:"JM",balloonText:"'.__('c-jm','wp-slimstat').': 0",value:0,color:"#ededed"}','jp'=>'{id:"JP",balloonText:"'.__('c-jp','wp-slimstat').': 0",value:0,color:"#ededed"}','jo'=>'{id:"JO",balloonText:"'.__('c-jo','wp-slimstat').': 0",value:0,color:"#ededed"}','kz'=>'{id:"KZ",balloonText:"'.__('c-kz','wp-slimstat').': 0",value:0,color:"#ededed"}','ke'=>'{id:"KE",balloonText:"'.__('c-ke','wp-slimstat').': 0",value:0,color:"#ededed"}','nr'=>'{id:"NR",balloonText:"'.__('c-nr','wp-slimstat').': 0",value:0,color:"#ededed"}','kp'=>'{id:"KP",balloonText:"'.__('c-kp','wp-slimstat').': 0",value:0,color:"#ededed"}','kr'=>'{id:"KR",balloonText:"'.__('c-kr','wp-slimstat').': 0",value:0,color:"#ededed"}','kv'=>'{id:"KV",balloonText:"'.__('c-kv','wp-slimstat').': 0",value:0,color:"#ededed"}','kw'=>'{id:"KW",balloonText:"'.__('c-kw','wp-slimstat').': 0",value:0,color:"#ededed"}','kg'=>'{id:"KG",balloonText:"'.__('c-kg','wp-slimstat').': 0",value:0,color:"#ededed"}','la'=>'{id:"LA",balloonText:"'.__('c-la','wp-slimstat').': 0",value:0,color:"#ededed"}','lv'=>'{id:"LV",balloonText:"'.__('c-lv','wp-slimstat').': 0",value:0,color:"#ededed"}','lb'=>'{id:"LB",balloonText:"'.__('c-lb','wp-slimstat').': 0",value:0,color:"#ededed"}','ls'=>'{id:"LS",balloonText:"'.__('c-ls','wp-slimstat').': 0",value:0,color:"#ededed"}','lr'=>'{id:"LR",balloonText:"'.__('c-lr','wp-slimstat').': 0",value:0,color:"#ededed"}','ly'=>'{id:"LY",balloonText:"'.__('c-ly','wp-slimstat').': 0",value:0,color:"#ededed"}','li'=>'{id:"LI",balloonText:"'.__('c-li','wp-slimstat').': 0",value:0,color:"#ededed"}','lt'=>'{id:"LT",balloonText:"'.__('c-lt','wp-slimstat').': 0",value:0,color:"#ededed"}','lu'=>'{id:"LU",balloonText:"'.__('c-lu','wp-slimstat').': 0",value:0,color:"#ededed"}','mk'=>'{id:"MK",balloonText:"'.__('c-mk','wp-slimstat').': 0",value:0,color:"#ededed"}','mg'=>'{id:"MG",balloonText:"'.__('c-mg','wp-slimstat').': 0",value:0,color:"#ededed"}','mw'=>'{id:"MW",balloonText:"'.__('c-mw','wp-slimstat').': 0",value:0,color:"#ededed"}','my'=>'{id:"MY",balloonText:"'.__('c-my','wp-slimstat').': 0",value:0,color:"#ededed"}','ml'=>'{id:"ML",balloonText:"'.__('c-ml','wp-slimstat').': 0",value:0,color:"#ededed"}','mt'=>'{id:"MT",balloonText:"'.__('c-mt','wp-slimstat').': 0",value:0,color:"#ededed"}','mq'=>'{id:"MQ",balloonText:"'.__('c-mq','wp-slimstat').': 0",value:0,color:"#ededed"}','mr'=>'{id:"MR",balloonText:"'.__('c-mr','wp-slimstat').': 0",value:0,color:"#ededed"}','mu'=>'{id:"MU",balloonText:"'.__('c-mu','wp-slimstat').': 0",value:0,color:"#ededed"}','mx'=>'{id:"MX",balloonText:"'.__('c-mx','wp-slimstat').': 0",value:0,color:"#ededed"}','md'=>'{id:"MD",balloonText:"'.__('c-md','wp-slimstat').': 0",value:0,color:"#ededed"}','mn'=>'{id:"MN",balloonText:"'.__('c-mn','wp-slimstat').': 0",value:0,color:"#ededed"}','me'=>'{id:"ME",balloonText:"'.__('c-me','wp-slimstat').': 0",value:0,color:"#ededed"}','ms'=>'{id:"MS",balloonText:"'.__('c-ms','wp-slimstat').': 0",value:0,color:"#ededed"}','ma'=>'{id:"MA",balloonText:"'.__('c-ma','wp-slimstat').': 0",value:0,color:"#ededed"}','mz'=>'{id:"MZ",balloonText:"'.__('c-mz','wp-slimstat').': 0",value:0,color:"#ededed"}','mm'=>'{id:"MM",balloonText:"'.__('c-mm','wp-slimstat').': 0",value:0,color:"#ededed"}','na'=>'{id:"NA",balloonText:"'.__('c-na','wp-slimstat').': 0",value:0,color:"#ededed"}','np'=>'{id:"NP",balloonText:"'.__('c-np','wp-slimstat').': 0",value:0,color:"#ededed"}','nl'=>'{id:"NL",balloonText:"'.__('c-nl','wp-slimstat').': 0",value:0,color:"#ededed"}','nc'=>'{id:"NC",balloonText:"'.__('c-nc','wp-slimstat').': 0",value:0,color:"#ededed"}','nz'=>'{id:"NZ",balloonText:"'.__('c-nz','wp-slimstat').': 0",value:0,color:"#ededed"}','ni'=>'{id:"NI",balloonText:"'.__('c-ni','wp-slimstat').': 0",value:0,color:"#ededed"}','ne'=>'{id:"NE",balloonText:"'.__('c-ne','wp-slimstat').': 0",value:0,color:"#ededed"}','ng'=>'{id:"NG",balloonText:"'.__('c-ng','wp-slimstat').': 0",value:0,color:"#ededed"}','no'=>'{id:"NO",balloonText:"'.__('c-no','wp-slimstat').': 0",value:0,color:"#ededed"}','om'=>'{id:"OM",balloonText:"'.__('c-om','wp-slimstat').': 0",value:0,color:"#ededed"}','pk'=>'{id:"PK",balloonText:"'.__('c-pk','wp-slimstat').': 0",value:0,color:"#ededed"}','pw'=>'{id:"PW",balloonText:"'.__('c-pw','wp-slimstat').': 0",value:0,color:"#ededed"}','ps'=>'{id:"PS",balloonText:"'.__('c-ps','wp-slimstat').': 0",value:0,color:"#ededed"}','pa'=>'{id:"PA",balloonText:"'.__('c-pa','wp-slimstat').': 0",value:0,color:"#ededed"}','pg'=>'{id:"PG",balloonText:"'.__('c-pg','wp-slimstat').': 0",value:0,color:"#ededed"}','py'=>'{id:"PY",balloonText:"'.__('c-py','wp-slimstat').': 0",value:0,color:"#ededed"}','pe'=>'{id:"PE",balloonText:"'.__('c-pe','wp-slimstat').': 0",value:0,color:"#ededed"}','ph'=>'{id:"PH",balloonText:"'.__('c-ph','wp-slimstat').': 0",value:0,color:"#ededed"}','pl'=>'{id:"PL",balloonText:"'.__('c-pl','wp-slimstat').': 0",value:0,color:"#ededed"}','pt'=>'{id:"PT",balloonText:"'.__('c-pt','wp-slimstat').': 0",value:0,color:"#ededed"}','pr'=>'{id:"PR",balloonText:"'.__('c-pr','wp-slimstat').': 0",value:0,color:"#ededed"}','qa'=>'{id:"QA",balloonText:"'.__('c-qa','wp-slimstat').': 0",value:0,color:"#ededed"}','re'=>'{id:"RE",balloonText:"'.__('c-re','wp-slimstat').': 0",value:0,color:"#ededed"}','ro'=>'{id:"RO",balloonText:"'.__('c-ro','wp-slimstat').': 0",value:0,color:"#ededed"}','ru'=>'{id:"RU",balloonText:"'.__('c-ru','wp-slimstat').': 0",value:0,color:"#ededed"}','rw'=>'{id:"RW",balloonText:"'.__('c-rw','wp-slimstat').': 0",value:0,color:"#ededed"}','kn'=>'{id:"KN",balloonText:"'.__('c-kn','wp-slimstat').': 0",value:0,color:"#ededed"}','lc'=>'{id:"LC",balloonText:"'.__('c-lc','wp-slimstat').': 0",value:0,color:"#ededed"}','mf'=>'{id:"MF",balloonText:"'.__('c-mf','wp-slimstat').': 0",value:0,color:"#ededed"}','vc'=>'{id:"VC",balloonText:"'.__('c-vc','wp-slimstat').': 0",value:0,color:"#ededed"}','ws'=>'{id:"WS",balloonText:"'.__('c-ws','wp-slimstat').': 0",value:0,color:"#ededed"}','st'=>'{id:"ST",balloonText:"'.__('c-st','wp-slimstat').': 0",value:0,color:"#ededed"}','sa'=>'{id:"SA",balloonText:"'.__('c-sa','wp-slimstat').': 0",value:0,color:"#ededed"}','sn'=>'{id:"SN",balloonText:"'.__('c-sn','wp-slimstat').': 0",value:0,color:"#ededed"}','rs'=>'{id:"RS",balloonText:"'.__('c-rs','wp-slimstat').': 0",value:0,color:"#ededed"}','sl'=>'{id:"SL",balloonText:"'.__('c-sl','wp-slimstat').': 0",value:0,color:"#ededed"}','sg'=>'{id:"SG",balloonText:"'.__('c-sg','wp-slimstat').': 0",value:0,color:"#ededed"}','sk'=>'{id:"SK",balloonText:"'.__('c-sk','wp-slimstat').': 0",value:0,color:"#ededed"}','si'=>'{id:"SI",balloonText:"'.__('c-si','wp-slimstat').': 0",value:0,color:"#ededed"}','sb'=>'{id:"SB",balloonText:"'.__('c-sb','wp-slimstat').': 0",value:0,color:"#ededed"}','so'=>'{id:"SO",balloonText:"'.__('c-so','wp-slimstat').': 0",value:0,color:"#ededed"}','za'=>'{id:"ZA",balloonText:"'.__('c-za','wp-slimstat').': 0",value:0,color:"#ededed"}','gs'=>'{id:"GS",balloonText:"'.__('c-gs','wp-slimstat').': 0",value:0,color:"#ededed"}','es'=>'{id:"ES",balloonText:"'.__('c-es','wp-slimstat').': 0",value:0,color:"#ededed"}','lk'=>'{id:"LK",balloonText:"'.__('c-lk','wp-slimstat').': 0",value:0,color:"#ededed"}','sc'=>'{id:"SC",balloonText:"'.__('c-sc','wp-slimstat').': 0",value:0,color:"#ededed"}','sd'=>'{id:"SD",balloonText:"'.__('c-sd','wp-slimstat').': 0",value:0,color:"#ededed"}','ss'=>'{id:"SS",balloonText:"'.__('c-ss','wp-slimstat').': 0",value:0,color:"#ededed"}','sr'=>'{id:"SR",balloonText:"'.__('c-sr','wp-slimstat').': 0",value:0,color:"#ededed"}','sj'=>'{id:"SJ",balloonText:"'.__('c-sj','wp-slimstat').': 0",value:0,color:"#ededed"}','sz'=>'{id:"SZ",balloonText:"'.__('c-sz','wp-slimstat').': 0",value:0,color:"#ededed"}','se'=>'{id:"SE",balloonText:"'.__('c-se','wp-slimstat').': 0",value:0,color:"#ededed"}','ch'=>'{id:"CH",balloonText:"'.__('c-ch','wp-slimstat').': 0",value:0,color:"#ededed"}','sy'=>'{id:"SY",balloonText:"'.__('c-sy','wp-slimstat').': 0",value:0,color:"#ededed"}','tw'=>'{id:"TW",balloonText:"'.__('c-tw','wp-slimstat').': 0",value:0,color:"#ededed"}','tj'=>'{id:"TJ",balloonText:"'.__('c-tj','wp-slimstat').': 0",value:0,color:"#ededed"}','tz'=>'{id:"TZ",balloonText:"'.__('c-tz','wp-slimstat').': 0",value:0,color:"#ededed"}','th'=>'{id:"TH",balloonText:"'.__('c-th','wp-slimstat').': 0",value:0,color:"#ededed"}','tl'=>'{id:"TL",balloonText:"'.__('c-tl','wp-slimstat').': 0",value:0,color:"#ededed"}','tg'=>'{id:"TG",balloonText:"'.__('c-tg','wp-slimstat').': 0",value:0,color:"#ededed"}','to'=>'{id:"TO",balloonText:"'.__('c-to','wp-slimstat').': 0",value:0,color:"#ededed"}','tt'=>'{id:"TT",balloonText:"'.__('c-tt','wp-slimstat').': 0",value:0,color:"#ededed"}','tn'=>'{id:"TN",balloonText:"'.__('c-tn','wp-slimstat').': 0",value:0,color:"#ededed"}','tr'=>'{id:"TR",balloonText:"'.__('c-tr','wp-slimstat').': 0",value:0,color:"#ededed"}','tm'=>'{id:"TM",balloonText:"'.__('c-tm','wp-slimstat').': 0",value:0,color:"#ededed"}','tc'=>'{id:"TC",balloonText:"'.__('c-tc','wp-slimstat').': 0",value:0,color:"#ededed"}','ug'=>'{id:"UG",balloonText:"'.__('c-ug','wp-slimstat').': 0",value:0,color:"#ededed"}','ua'=>'{id:"UA",balloonText:"'.__('c-ua','wp-slimstat').': 0",value:0,color:"#ededed"}','ae'=>'{id:"AE",balloonText:"'.__('c-ae','wp-slimstat').': 0",value:0,color:"#ededed"}','gb'=>'{id:"GB",balloonText:"'.__('c-gb','wp-slimstat').': 0",value:0,color:"#ededed"}','us'=>'{id:"US",balloonText:"'.__('c-us','wp-slimstat').': 0",value:0,color:"#ededed"}','uy'=>'{id:"UY",balloonText:"'.__('c-uy','wp-slimstat').': 0",value:0,color:"#ededed"}','uz'=>'{id:"UZ",balloonText:"'.__('c-uz','wp-slimstat').': 0",value:0,color:"#ededed"}','vu'=>'{id:"VU",balloonText:"'.__('c-vu','wp-slimstat').': 0",value:0,color:"#ededed"}','ve'=>'{id:"VE",balloonText:"'.__('c-ve','wp-slimstat').': 0",value:0,color:"#ededed"}','vn'=>'{id:"VN",balloonText:"'.__('c-vn','wp-slimstat').': 0",value:0,color:"#ededed"}','vg'=>'{id:"VG",balloonText:"'.__('c-vg','wp-slimstat').': 0",value:0,color:"#ededed"}','vi'=>'{id:"VI",balloonText:"'.__('c-vi','wp-slimstat').': 0",value:0,color:"#ededed"}','eh'=>'{id:"EH",balloonText:"'.__('c-eh','wp-slimstat').': 0",value:0,color:"#ededed"}','ye'=>'{id:"YE",balloonText:"'.__('c-ye','wp-slimstat').': 0",value:0,color:"#ededed"}','zm'=>'{id:"ZM",balloonText:"'.__('c-zm','wp-slimstat').': 0",value:0,color:"#ededed"}','zw'=>'{id:"ZW",balloonText:"'.__('c-zw','wp-slimstat').': 0",value:0,color:"#ededed"}','gg'=>'{id:"GG",balloonText:"'.__('c-gg','wp-slimstat').': 0",value:0,color:"#ededed"}','je'=>'{id:"JE",balloonText:"'.__('c-je','wp-slimstat').': 0",value:0,color:"#ededed"}','im'=>'{id:"IM",balloonText:"'.__('c-im','wp-slimstat').': 0",value:0,color:"#ededed"}','mv'=>'{id:"MV",balloonText:"'.__('c-mv','wp-slimstat').': 0",value:0,color:"#ededed"}');
		$countries_not_represented = array( __('c-eu','wp-slimstat') );
		$max = 0;

		foreach($countries as $a_country){
			if (!array_key_exists($a_country['country'], $data_areas)) continue;

			$percentage = (self::$pageviews > 0)?sprintf("%01.2f", (100*$a_country['counthits']/self::$pageviews)) : 0;
			$percentage_format = number_format($percentage, 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']);
			$balloon_text = __('c-'.$a_country['country'], 'wp-slimstat').': '.$percentage_format.'% ('.number_format($a_country['counthits'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']).')';
			$data_areas[$a_country['country']] = '{id:"'.strtoupper($a_country['country']).'",balloonText:"'.$balloon_text.'",value:'.$percentage.'}';

			if ($percentage > $max){
				$max = $percentage;
			}
		}
		?>

		<script src="<?php echo plugins_url('/js/ammap/ammap.js', dirname(__FILE__)) ?>" type="text/javascript"></script>
		<script src="<?php echo plugins_url('/js/ammap/world.js', dirname(__FILE__)) ?>" type="text/javascript"></script>
		<script type="text/javascript">
		//AmCharts.ready(function(){
			var dataProvider = {
				mapVar: AmCharts.maps.worldLow,
				getAreasFromMap:true,
				areas:[<?php echo implode(',', $data_areas) ?>]
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
				autoZoom: true,
				color: "#9dff98",
				colorSolid: "#fa8a50",
				outlineColor: "#888888",
				selectedColor: "#ffb739"
			};
			map.backgroundAlpha = .9;
			map.backgroundColor = "#7adafd";
			map.backgroundZoomsToTop = true;
			map.balloon.color = "#000000";
			map.colorSteps = 5;
			map.mouseWheelZoomEnabled = true;
			map.pathToImages = "<?php echo plugins_url('/js/ammap/images/', dirname(__FILE__)) ?>";
			
			
			// Init Data
			map.dataProvider = dataProvider;

			// Display Map
			map.write("slim_p6_01_inside");
		//});
		</script><?php

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die();
		}
	}

	public static function get_search_terms_info($_searchterms = '', $_referer = '', $_serp_only = false){
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
			$search_terms_info = '<a class="slimstat-font-logout" target="_blank" title="' . htmlentities( __( 'Go to the referring page', 'wp-slimstat' ), ENT_QUOTES, 'UTF-8' ) . '" href="' . $_referer . '"></a>' . htmlentities( $_searchterms, ENT_QUOTES, 'UTF-8' );
			$search_terms_info = "$search_terms_info $query_details";
		}
		return $search_terms_info;
	}

	/**
	 * Generate the HTML that lists all the filters currently used
	 */
	public static function get_filters_html($_filters_array = array()){
		$filters_html = '';

		// Don't display direction and limit results
		$filters_dropdown = array_diff_key($_filters_array, self::$hidden_filters);

		if (!empty($filters_dropdown)){
			foreach($filters_dropdown as $a_filter_label => $a_filter_details){
				if (!array_key_exists($a_filter_label, wp_slimstat_db::$columns_names) || strpos($a_filter_label, 'no_filter') !== false){
					continue;
				}

				$a_filter_value_no_slashes = htmlentities(str_replace('\\','', $a_filter_details[1]), ENT_QUOTES, 'UTF-8');
				$filters_html .= "<li>".strtolower(wp_slimstat_db::$columns_names[$a_filter_label][ 0 ]).' '.__(str_replace('_', ' ', $a_filter_details[0]),'wp-slimstat')." $a_filter_value_no_slashes <a class='slimstat-remove-filter slimstat-font-cancel' title='".htmlentities(__('Remove filter for','wp-slimstat'), ENT_QUOTES, 'UTF-8').' '.wp_slimstat_db::$columns_names[ $a_filter_label ][ 0 ]."' href='".self::fs_url("$a_filter_label equals ")."'></a></li>";
			}
		}
		if (!empty($filters_html)){
			$filters_html = "<ul class='slimstat-filter-list'>$filters_html</ul><a href='#' id='slimstat-save-filter' class='slimstat-filter-action-button button-secondary' data-filter-array='".htmlentities(serialize($_filters_array), ENT_QUOTES, 'UTF-8')."'>".__('Save','wp-slimstat')."</a>";
		}
		if(count($filters_dropdown) > 1){
			$filters_html .= '<a href="'.self::fs_url().'" id="slimstat-remove-all-filters" class="button-secondary slimstat-filter-action-button">'.__('Reset All','wp-slimstat').'</a>';
		}
		$filters_html .= '';

		return ($filters_html != "<span class='filters-title'>".__('Current filters:','wp-slimstat').'</span> ')?$filters_html:'';
	}

	public static function fs_url( $_filters = '' ){

		// Allow only legitimate requests
		$request_uri = $_SERVER['REQUEST_URI'];
		$request_page = 'slimview1';

		// Are we on the Dashboard?
		if ( empty( $_REQUEST[ 'page' ] ) ) {
			$request_uri = str_replace( 'index.php', 'admin.php', $request_uri );
		}
		else if ( array_key_exists( $_REQUEST[ 'page' ], wp_slimstat_admin::$screens_info ) ) {
			$request_page = $_REQUEST[ 'page' ];
		}
		else {
			return '';
		}

		$filtered_url = ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ? explode( '?', $_SERVER["HTTP_REFERER"] ) : explode( '?', $request_uri );
		$filtered_url = $filtered_url[ 0 ] . '?page=' . $request_page;

		// Columns
		$filters_normalized = wp_slimstat_db::parse_filters( $_filters, false );

		if (!empty($filters_normalized['columns'])){
			foreach($filters_normalized['columns'] as $a_key => $a_filter){
				$a_key = str_replace( '_calculated', '', $a_key );
				$filtered_url .= "&amp;fs%5B$a_key%5D=".urlencode($a_filter[0].' '.$a_filter[1]);
			}
		}

		// Date ranges
		if (!empty($filters_normalized['date'])){
			foreach($filters_normalized['date'] as $a_key => $a_filter){
				if (!empty($a_filter) || $a_filter === 0){
					$filtered_url .= "&amp;fs%5B$a_key%5D=".urlencode('equals '.$a_filter);
				}
			}
		}

		// Misc filters
		if (!empty($filters_normalized['misc'])){
			foreach($filters_normalized['misc'] as $a_key => $a_filter){
				$filtered_url .= "&amp;fs%5B$a_key%5D=".urlencode('equals '.$a_filter);
			}
		}

		return $filtered_url;
	}

	/**
	 * Attempts to convert a permalink into a post title
	 */
	public static function get_resource_title( $_resource = '' ) {
		$resource_title = $_resource;

		if  ( wp_slimstat::$options[ 'convert_resource_urls_to_titles' ] != 'yes' ) {
			return htmlentities( urldecode( $resource_title ), ENT_QUOTES, 'UTF-8' );
		}

		// Is this a post or a page?
		$post_id = url_to_postid( $_resource );

		if ( $post_id > 0 ) {
			$resource_title = the_title_attribute( array( 'post' => $post_id, 'echo' => false ) );

			// Encode URLs to avoid XSS attacks
			if ( $resource_title == $_resource ) {
				$resource_title = htmlspecialchars( $resource_title, ENT_QUOTES, 'UTF-8' );
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
				$resource_title = implode( ',', $term_names );
			}
			else {
				$resource_title = htmlspecialchars( $resource_title, ENT_QUOTES, 'UTF-8' );
			}
		}

		return $resource_title;
	}
	
	public static function inline_help( $_text = '', $_echo = true ) {
		$wrapped_text = "<i class='slimstat-tooltip-trigger corner'></i><span class='slimstat-tooltip-content'>$_text</span>";
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

		// Honor the 'hidden' attribute, but not on the WP Dashboard ( empty( $_args[ 'id' ] ) )
		// if ( empty( $report_id ) || in_array( 'hidden', self::$reports_info[ $report_id ][ 'classes' ] ) ) {
		// 	return array();
		// }

		if ( !empty( self::$reports_info[ $report_id ] ) && is_array( self::$reports_info[ $report_id ] ) ) {
			// Default values
			$_args = array_merge( array(
				'title' => '',
				'callback' => '',
				'callback_args' => array(),
				'classes' => array(), 
				'screens' => array(),
				'tooltip' => ''
			), self::$reports_info[ $report_id ] );
		}

		// Default callback args
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
			'results_per_page' => wp_slimstat::$options[ 'rows_to_show' ],
			'criteria' => ''
		), $_args[ 'callback_args' ] );

		return $_args;
	}
}