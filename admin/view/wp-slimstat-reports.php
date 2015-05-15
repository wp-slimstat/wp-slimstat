<?php

class wp_slimstat_reports {

	// Structures to store all the information about what screens and reports are available
	public static $screens_info = array();
	public static $reports_info = array();

	// Hidden filters are not displayed to the user, but are applied to the reports
	public static $hidden_filters = array();

	// Useful data for the reports
	protected static $pageviews = 0;

	/**
	 * Initalize class properties
	 */
	public static function init(){
		self::$screens_info = array(
			'wp-slim-view-1' => __( 'Real-Time Log', 'wp-slimstat' ),
			'wp-slim-view-2' => __( 'Overview', 'wp-slimstat' ),
			'wp-slim-view-3' => __( 'Audience', 'wp-slimstat' ),
			'wp-slim-view-4' => __( 'Site Analysis', 'wp-slimstat' ),
			'wp-slim-view-5' => __( 'Traffic Sources', 'wp-slimstat' ),
			'wp-slim-view-6' => __( 'Map Overlay', 'wp-slimstat' )
		);

		// Define all the reports
		$chart_tooltip = '<strong>'.__( 'Chart controls', 'wp-slimstat' ).'</strong><ul><li>'.__( 'Use your mouse wheel to zoom in and out', 'wp-slimstat' ).'</li><li>'.__( 'While zooming in, drag the chart to move to a different area', 'wp-slimstat' ).'</li><li>'.__( 'Double click on an empty region to reset the zoom level', 'wp-slimstat' ).'</li></ul>';
		self::$reports_info = array(
			'slim_p7_02' => array(
				'title' => __( 'Activity', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'tall' ),
				'screens' => array( 'wp-slim-view-1' ),
				'tooltip' => __( 'Color codes', 'wp-slimstat' ).'</strong><p><span class="little-color-box is-search-engine"></span> '.__( 'From search result page', 'wp-slimstat' ).'</p><p><span class="little-color-box is-known-visitor"></span> '.__( 'Known Visitor', 'wp-slimstat' ).'</p><p><span class="little-color-box is-known-user"></span> '.__( 'Known Users', 'wp-slimstat' ).'</p><p><span class="little-color-box is-direct"></span> '.__( 'Other Humans', 'wp-slimstat' ).'</p><p><span class="little-color-box"></span> '.__( 'Bot or Crawler', 'wp-slimstat' ).'</p>'
			),

			'slim_p1_01' => array(
				'title' => __( 'Pageviews', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'wide', 'chart' ),
				'screens' => array( 'wp-slim-view-2', 'dashboard' ),
				'tooltip' => $chart_tooltip
			),
			'slim_p1_02' => array(
				'title' => __( 'About Slimstat', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-2' ),
				'tooltip' => ''
			),
			'slim_p1_03' => array(
				'title' => __( 'At a Glance', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-2', 'dashboard' ),
				'tooltip' => ''
			),
			'slim_p1_04' => array(
				'title' => __( 'Currently Online', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-2', 'dashboard' ),
				'tooltip' => __( 'When visitors leave a comment on your blog, WordPress assigns them a cookie. Slimstat leverages this information to identify returning visitors. Please note that visitors also include registered users.', 'wp-slimstat' )
			),
			'slim_p1_06' => array(
				'title' => __( 'Recent Search Terms', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-2', 'wp-slim-view-5' ),
				'tooltip' => __( 'Keywords used by your visitors to find your website on a search engine.', 'wp-slimstat' )
			),
			'slim_p1_08' => array(
				'title' => __( 'Top Pages', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-2', 'dashboard' ),
				'tooltip' => __( 'Here a "page" is not just a WordPress page type, but any web page on your website, including posts, products, categories, and so on.', 'wp-slimstat' )
			),
			'slim_p1_10' => array(
				'title' => __('Top Traffic Sources', 'wp-slimstat'),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-2', 'wp-slim-view-5', 'dashboard' ),
				'tooltip' => ''
			),
			'slim_p1_11' => array(
				'title' => __( 'Top Known Visitors', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-2', 'dashboard' ),
				'tooltip' => ''
			),
			'slim_p1_12' => array(
				'title' => __( 'Top Search Terms', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-2', 'wp-slim-view-4', 'wp-slim-view-5', 'dashboard' ),
				'tooltip' => ''
			),
			'slim_p1_13' => array(
				'title' => __( 'Top Countries', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-2', 'wp-slim-view-3', 'wp-slim-view-5', 'dashboard' ),
				'tooltip' => __( 'You can configure Slimstat to ignore a specific Country by setting the corresponding filter under Settings > Slimstat > Filters.', 'wp-slimstat' )
			),
			'slim_p1_15' => array(
				'title' => __( 'Rankings', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-2' ),
				'tooltip' => __( "Slimstat retrieves live information from Alexa, Facebook and Google, to measures your site's rankings. Values are updated every 12 hours. Filters set above don't apply to this report.", 'wp-slimstat' )
			),
			'slim_p1_17' => array(
				'title' => __( 'Top Language Families', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-2' ),
				'tooltip' => ''
			),

			'slim_p2_01' => array(
				'title' => __( 'Human Visits', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'wide', 'chart' ),
				'screens' => array( 'wp-slim-view-3' ),
				'tooltip' => $chart_tooltip
			),
			'slim_p2_02' => array(
				'title' => __( 'Audience Overview', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-3', 'dashboard' ),
				'tooltip' => __( 'Where not otherwise specified, the metrics in this report are referred to human visitors.', 'wp-slimstat' )
			),
			'slim_p2_03' => array(
				'title' => __( 'Top Languages', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-3' ),
				'tooltip' => ''
			),
			'slim_p2_04' => array(
				'title' => __( 'Top Browsers', 'wp-slimstat', 'dashboard' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-3' ),
				'tooltip' => ''
			),
			'slim_p2_05' => array(
				'title' => __( 'Top Service Providers', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'wide', 'hidden' ),
				'screens' => array( 'wp-slim-view-3' ),
				'tooltip' => __( 'Internet Service Provider: a company which provides other companies or individuals with access to the Internet. Your DSL or cable internet service is provided to you by your ISP.<br><br>You can ignore specific IP addresses by setting the corresponding filter under Settings > Slimstat > Filters.', 'wp-slimstat' )
			),
			'slim_p2_06' => array(
				'title' => __( 'Top Operating Systems', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-3' ),
				'tooltip' => __( 'Internet Service Provider: a company which provides other companies or individuals with access to the Internet. Your DSL or cable internet service is provided to you by your ISP.<br><br>You can ignore specific IP addresses by setting the corresponding filter under Settings > Slimstat > Filters.', 'wp-slimstat' )
			),
			'slim_p2_07' => array(
				'title' => __( 'Top Screen Resolutions', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-3', 'dashboard' ),
				'tooltip' => ''
			),
			'slim_p2_09' => array(
				'title' => __( 'Browser Capabilities', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-3' ),
				'tooltip' => ''
			),
			'slim_p2_12' => array(
				'title' => __( 'Visit Duration', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-3' ),
				'tooltip' => ''
			),
			'slim_p2_13' => array(
				'title' => __( 'Recent Countries', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-3', 'wp-slim-view-5' ),
				'tooltip' => ''
			),
			'slim_p2_14' => array(
				'title' => __( 'Recent Screen Resolutions', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-3' ),
				'tooltip' => ''
			),
			'slim_p2_15' => array(
				'title' => __( 'Recent Operating Systems', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-3' ),
				'tooltip' => ''
			),
			'slim_p2_16' => array(
				'title' => __( 'Recent Browsers', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-3' ),
				'tooltip' => ''
			),
			'slim_p2_17' => array(
				'title' => __( 'Recent Languages', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-3' ),
				'tooltip' => ''
			),
			'slim_p2_18' => array(
				'title' => __( 'Top Browser Families', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-3' ),
				'tooltip' => __( 'This report shows you what user agent families (no version considered) are popular among your visitors.', 'wp-slimstat' )
			),
			'slim_p2_19' => array(
				'title' => __( 'Top OS Families', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-3' ),
				'tooltip' => __( 'This report shows you what operating system families (no version considered) are popular among your visitors.', 'wp-slimstat' )
			),
			'slim_p2_20' => array(
				'title' => __( 'Recent Users', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-3' ),
				'tooltip' => ''
			),
			'slim_p2_21' => array(
				'title' => __( 'Top Users', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-3', 'dashboard' ),
				'tooltip' => ''
			),

			'slim_p3_01' => array(
				'title' => __( 'Traffic Sources', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'wide', 'chart' ),
				'screens' => array( 'wp-slim-view-5' ),
				'tooltip' => $chart_tooltip
			),
			'slim_p3_02' => array(
				'title' => __( 'Summary', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-5' ),
				'tooltip' => ''
			),
			'slim_p3_06' => array(
				'title' => __( 'Top Referring Search Engines', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-5', 'dashboard' ),
				'tooltip' => ''
			),
			'slim_p3_11' => array(
				'title' => __( 'Recent Exit Pages', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-5' ),
				'tooltip' => ''
			),

			/*
			'slim_p4_01' => array(
				'title' => __( 'Recent Outbound Links', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'wide' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => ''
			),
			*/
			'slim_p4_02' => array(
				'title' => __( 'Recent Posts', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => ''
			),
			'slim_p4_03' => array(
				'title' => __( 'Recent Bounce Pages', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => __( 'A <em>bounce page</em> is a single-page visit, or visit in which the person left your site from the entrance (landing) page.', 'wp-slimstat' )
			),
			'slim_p4_04' => array(
				'title' => __( 'Recent Feeds', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => __( 'A <em>bounce page</em> is a single-page visit, or visit in which the person left your site from the entrance (landing) page.', 'wp-slimstat' )
			),
			'slim_p4_05' => array(
				'title' => __( 'Recent Pages Not Found', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => ''
			),
			'slim_p4_06' => array(
				'title' => __( 'Recent Internal Searches', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => __( "Searches performed using WordPress' built-in search functionality.", 'wp-slimstat' )
			),
			'slim_p4_07' => array(
				'title' => __( 'Top Categories', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-4', 'dashboard' ),
				'tooltip' => ''
			),
			/*
			NOTE TO SELF: don't forget to remove from deprecated, when implemented
			'slim_p4_09' => array(
				'title' => __( 'Top Downloads', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => __( 'You can configure Slimstat to track specific file extensions as downloads.', 'wp-slimstat' )
			),
			'slim_p4_10' => array(
				'title' => __( 'Recent Events', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => __( 'This report lists any <em>event</em> occurred on your website. Please refer to the FAQ for more information on how to use this functionality.', 'wp-slimstat' )
			),
			*/
			'slim_p4_11' => array(
				'title' => __( 'Top Posts', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => ''
			),
			'slim_p4_13' => array(
				'title' => __( 'Top Internal Searches', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => ''
			),
			'slim_p4_15' => array(
				'title' => __( 'Recent Categories', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => ''
			),
			'slim_p4_16' => array(
				'title' => __( 'Top Pages Not Found', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => ''
			),
			'slim_p4_18' => array(
				'title' => __( 'Top Authors', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal' ),
				'screens' => array( 'wp-slim-view-4', 'dashboard' ),
				'tooltip' => ''
			),
			'slim_p4_19' => array(
				'title' => __( 'Top Tags', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => ''
			),
			'slim_p4_20' => array(
				'title' => __( 'Recent Downloads', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'wide', 'hidden' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => ''
			),
			'slim_p4_21' => array(
				'title' => __( 'Top Outbound Links', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => ''
			),
			'slim_p4_22' => array(
				'title' => __( 'Your Website', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => __( 'Your content at a glance: posts, comments, pingbacks, etc. Please note that this report is not affected by the filters set here above.', 'wp-slimstat' )
			),
			'slim_p4_23' => array(
				'title' => __( 'Top Bounce Pages', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => ''
			),
			/* NOTE TO SELF: remove them from deprecated, when reimplemented
			'slim_p4_24' => array(
				'title' => __( 'Top Exit Pages', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => ''
			),
			'slim_p4_25' => array(
				'title' => __( 'Top Entry Pages', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'normal', 'hidden' ),
				'screens' => array( 'wp-slim-view-4' ),
				'tooltip' => ''
			),
			*/

			'slim_p6_01' => array(
				'title' => __( 'World Map', 'wp-slimstat' ),
				'callback' => 'report_body',
				'classes' => array( 'tall' ),
				'screens' => array( 'wp-slim-view-6' ),
				'tooltip' => ''
			)
		);

		// Allow third party tools to manipulate this list here above: please use unique report IDs that don't interfere with built-in ones, if you add your own custom report
		self::$reports_info = apply_filters( 'slimstat_reports_info', self::$reports_info );

		// Define what reports have been deprecated over time, to remove them from the user's settings
		$deprecated_reports = array(
			'slim_p1_05',
			'slim_p1_18',
			'slim_p2_10',
			'slim_p3_03',
			'slim_p3_04',
			'slim_p3_05',
			'slim_p3_08',
			'slim_p3_09',
			'slim_p3_10',
			'slim_p4_01',
			'slim_p4_08',
			'slim_p4_09',
			'slim_p4_10',
			'slim_p4_12',
			'slim_p4_14',
			'slim_p4_17',
			'slim_p4_20',
			'slim_p4_24',
			'slim_p4_25'
		);

		// Retrieve this user's list of active reports, 
		$user = wp_get_current_user();
		$page_location = ( wp_slimstat::$options[ 'use_separate_menu' ] == 'yes' ) ? 'slimstat' : 'admin';
		$user_reports = get_user_option( "meta-box-order_{$page_location}_page_{$_REQUEST['page']}", $user->ID );

		// If this list is not empty, we rearrange the order of our reports
		if ( !empty( $user_reports[ 0 ] ) ) {
			$user_reports = array_flip( explode( ',', $user_reports[ 0 ] ) );
			self::$reports_info = array_intersect_key( array_merge( $user_reports, self::$reports_info ), $user_reports );
		}

		// Remove deprecated reports
		self::$reports_info = array_diff_key( self::$reports_info, $deprecated_reports );

		// Update the visibility of the remaining boxes 
		if ( !empty( $_REQUEST[ 'page' ] ) && strpos( $_REQUEST[ 'page' ], 'wp-slim-view-' ) !== false ) {
			$hidden_reports = get_user_option( "metaboxhidden_{$page_location}_page_{$_REQUEST['page']}", $user->ID );
		}
		else { // the script is being called from the dashboard widgets plugin
			$hidden_reports = get_user_option( "metaboxhidden_{$page_location}", $user->ID );
		}

		// If this list is not empty, use it instead of the predefined visibility
		if ( !empty( $hidden_reports ) && is_array( $hidden_reports ) ) {
			foreach ( self::$reports_info as $a_report_id => $a_report_info ){
				if ( in_array( $a_report_id, $hidden_reports ) ) {
					if ( !in_array( 'hidden', $a_report_info[ 'classes' ] ) ) {
						self::$reports_info[ $a_report_id ][ 'classes' ][] = 'hidden';
					}
				}
				else if ( is_array( self::$reports_info[ $a_report_id ][ 'classes' ] ) ) {
					self::$reports_info[ $a_report_id ][ 'classes' ] = array_diff( self::$reports_info[ $a_report_id ][ 'classes' ], array( 'hidden' ) );
				}
			}
		}

		// Filters use the following format: browser equals Firefox|country contains gb
		$filters = array();
		if (!empty($_REQUEST['fs']) && is_array($_REQUEST['fs'])){
			foreach($_REQUEST['fs'] as $a_request_filter_name => $a_request_filter_value){
				$filters[] = "$a_request_filter_name $a_request_filter_value";
			}
		}

		// Fields and drop downs 
		if (!empty($_POST['f']) && !empty($_POST['o'])){
			$filters[] = "{$_POST['f']} {$_POST['o']} ".(isset($_POST['v'])?$_POST['v']:'');
		}

		$date_time_filters = array('minute', 'hour', 'day', 'month', 'year', 'interval_direction', 'interval', 'interval_hours', 'interval_minutes');
		foreach ($date_time_filters as $a_date_time_filter_name){
			if (!empty($_POST[$a_date_time_filter_name])){
				$filters[] = "$a_date_time_filter_name equals {$_POST[$a_date_time_filter_name]}";
			}
		}

		// Hidden Filters
		if (wp_slimstat::$options['restrict_authors_view'] == 'yes' && !current_user_can('manage_options')){
			$filters[] = 'author equals '.$GLOBALS['current_user']->user_login;
			self::$hidden_filters['author'] = 1;
		}

		// Allow third-party add-ons to modify filters before they are used
		$filters = apply_filters('slimstat_modify_admin_filters', $filters);
		if (!empty($filters)){
			$filters = implode('&&&', $filters);
		}

		// Include and initialize the API to interact with the database
		include_once( 'wp-slimstat-db.php' );
		wp_slimstat_db::init( $filters );

		// Retrieve data that will be used by multiple reports
		self::$pageviews = wp_slimstat_db::count_records();
	}
	// end init

	public static function report_body( $_report_id = '', $_args = false ) {
		$is_ajax = false;

		if ( !empty( $_POST[ 'report_id' ] ) ) {
			// Let's make sure the request is coming from the right place
			check_ajax_referer( 'meta-box-order', 'security' );
			$_report_id = $_POST[ 'report_id' ];
			$is_ajax = true;
		}
		else if ( !empty( $_args[ 'id' ] ) ){
			$_report_id = $_args[ 'id' ];
		}

		if ( !$is_ajax && ( in_array( 'hidden', self::$reports_info[ $_report_id ][ 'classes' ] ) || wp_slimstat::$options['async_load'] == 'yes' ) ) {
			return 0;
		}

		switch( $_report_id ) {

			// Pageviews
			case 'slim_p1_01':
				$chart_data = wp_slimstat_db::get_data_for_chart( 'COUNT(ip)', 'COUNT(DISTINCT(ip))' );
				$chart_labels = array( __( 'Pageviews', 'wp-slimstat' ), __( 'Unique IPs', 'wp-slimstat' ) );
				self::show_chart( $chart_data, $chart_labels );
				break;

			// About Slimstat
			case 'slim_p1_02':
				self::show_about_wpslimstat();
				break;

			// At a Glance
			case 'slim_p1_03':
				self::show_overview_summary();
				break;

			// Currently Online
			case 'slim_p1_04':
				self::show_results( 'recent', 'username', array( 'where' => 'dt > '. ( date_i18n( 'U' ) - 300 ), 'use_date_filters' => false ) );
				break;

			// Recent Search Terms
			case 'slim_p1_06':
				self::show_results( 'recent', 'searchterms' );
				break;

			// Top Pages
			case 'slim_p1_08':
				$separator = '?';

				// Pretty permalinks are not active
				if ( !get_option( 'permalink_structure' ) ) {
					$separator = '&';
				}
				self::show_results( 'popular', 'SUBSTRING_INDEX(resource, "' . $separator . '", 1)', array( 'as_column' => 'resource', 'filter_op' => 'contains' ) );
				break;

			// Top Traffic Sources
			case 'slim_p1_10':
				self::show_results( 'popular', 'referer', array( 'where' => 'referer NOT LIKE "%' . home_url() . '%"' ) );
				break;

			// Top Known Visitors
			case 'slim_p1_11':
				self::show_results( 'popular', 'username', array( 'where' => 'username IS NOT NULL' ) );
				break;

			// Top Search Terms
			case 'slim_p1_12':
				self::show_results( 'popular', 'searchterms' );
				break;

			// Top Countries
			case 'slim_p1_13':
				self::show_results( 'popular', 'country' );
				break;

			// Rankings
			case 'slim_p1_15':
				self::show_rankings();
				break;

			// Top Language Families
			case 'slim_p1_17':
				self::show_results( 'popular', 'SUBSTRING(language, 1, 2)', array( 'as_column' => 'language', 'filter_op' => 'contains' ) );
				break;

			// Human Visits
			case 'slim_p2_01':
				$chart_data = wp_slimstat_db::get_data_for_chart( 'COUNT(DISTINCT visit_id)', 'COUNT(DISTINCT ip)', '(visit_id > 0 AND browser_type <> 1)' );
				$chart_labels = array( __( 'Visits', 'wp-slimstat' ), __( 'Unique IPs', 'wp-slimstat' ) );
				self::show_chart( $chart_data, $chart_labels );
				break;

			// Audience Overview
			case 'slim_p2_02':
				self::show_visitors_summary( wp_slimstat_db::count_records( 'id', 'visit_id > 0 AND browser_type <> 1'), wp_slimstat_db::count_records('visit_id', 'visit_id > 0 AND browser_type <> 1' ) );
				break;

			// Top Languages
			case 'slim_p2_03':
				self::show_results( 'popular', 'language' );
				break;

			// Top Browsers and versions
			case 'slim_p2_04':
				self::show_results( 'popular', 'browser, browser_version' );
				break;

			// Top Service Providers
			case 'slim_p2_05':
				self::show_results( 'popular', 'ip' );
				break;

			// Top Operating Systems
			case 'slim_p2_06':
				self::show_results( 'popular', 'platform' );
				break;

			// Top Screen Resolutions
			case 'slim_p2_07':
				self::show_results( 'popular', 'resolution' );
				break;

			// Browser Capabilities
			case 'slim_p2_09':
				self::show_plugins();
				break;

			// Visit Duration
			case 'slim_p2_12':
				self::show_visit_duration();
				break;

			// Recent Countries
			case 'slim_p2_13':
				self::show_results( 'recent', 'country' );
				break;

			// Recent Screen Resolutions
			case 'slim_p2_14':
				self::show_results( 'recent', 'resolution' );
				break;

			// Recent Operating Systems
			case 'slim_p2_15':
				self::show_results( 'recent', 'platform' );
				break;

			// Recent Browsers
			case 'slim_p2_16':
				self::show_results( 'recent', 'browser, browser_version' );
				break;

			// Recent Languages
			case 'slim_p2_17':
				self::show_results( 'recent', 'language' );
				break;

			// Top Browser Families
			case 'slim_p2_18':
				self::show_results( 'popular', 'browser' );
				break;

			// Top OS Families
			case 'slim_p2_19':
				self::show_results( 'popular', 'CONCAT("p-", SUBSTRING(platform, 1, 3))', array( 'as_column' => 'platform', 'filter_op' => 'contains' ) );
				break;

			// Recent Users
			case 'slim_p2_20':
				self::show_results( 'recent', 'username', array( 'where' => 'notes LIKE "%user:%"' ) );
				break;

			// Top Users
			case 'slim_p2_21':
				self::show_results( 'popular', 'username', array( 'where' => 'notes LIKE "%user:%"' ) );
				break;

			// Traffic Sources
			case 'slim_p3_01':
				$chart_data = wp_slimstat_db::get_data_for_chart( 'COUNT(DISTINCT(referer))', 'COUNT(DISTINCT(ip))', '(referer IS NOT NULL AND referer NOT LIKE "%' . home_url() . '%")' );
				$chart_labels = array( __( 'Domains', 'wp-slimstat' ), __( 'Unique IPs', 'wp-slimstat' ) );
				self::show_chart( $chart_data, $chart_labels );
				break;

			// Summary
			case 'slim_p3_02':
				self::show_traffic_sources_summary();
				break;

			// Top Referring Search Engines
			case 'slim_p3_06':
				self::show_results( 'popular', 'referer', array( 'where' => "searchterms IS NOT NULL AND referer NOT LIKE '%".home_url()."%'" ) );
				break;

			// Recent Exit Pages
			case 'slim_p3_11':
				self::show_results( 'recent', 'visit_id' ); // show_results knows to display the resource, when the column is visit_id
				break;

			// Recent Posts
			case 'slim_p4_02':
				self::show_results( 'recent', 'resource', array( 'where' => 'content_type = "post"' ) );
				break;

			// Recent Bounce Pages
			case 'slim_p4_03':
				self::show_results( 'recent', 'resource', array( 'where' => 'content_type <> "404"', 'having' => 'HAVING COUNT(visit_id) = 1' ) );
				break;

			// Recent Feeds
			case 'slim_p4_04':
				self::show_results( 'recent', 'resource', array( 'where' => '(resource LIKE "%/feed%" OR resource LIKE "%?feed=%" OR resource LIKE "%&feed=%" OR content_type LIKE "%feed%")' ) );
				break;

			// Recent Pages Not Found
			case 'slim_p4_05':
				self::show_results( 'recent', 'resource', array( 'where' => '(resource LIKE "[404]%" OR content_type LIKE "%404%")' ) );
				break;

			// Recent Internal Searches
			case 'slim_p4_06':
				self::show_results( 'recent', 'searchterms', array( 'where' => 'content_type LIKE "%search%"' ) );
				break;

			// Top Categories
			case 'slim_p4_07':
				self::show_results( 'popular', 'category', array( 'where' => 'content_type LIKE "%category%"' ) );
				break;

			// Top Posts
			case 'slim_p4_11':
				self::show_results( 'popular', 'resource', array( 'where' => 'content_type = "post"' ) );
				break;

			// Top Internal Searches
			case 'slim_p4_13':
				self::show_results( 'popular', 'searchterms', array( 'where' => 'content_type LIKE "%search%")' ) );
				break;

			// Recent Categories
			case 'slim_p4_15':
				self::show_results( 'recent', 'resource', array( 'where' => '(content_type = "category" OR content_type = "tag")' ) );
				break;

			// Top Pages Not Found
			case 'slim_p4_16':
				self::show_results( 'popular', 'resource', array( 'where' => 'content_type LIKE "%404%")' ) );
				break;

			// Top Authors
			case 'slim_p4_18':
				self::show_results( 'popular', 'author' );
				break;

			// Top Tags
			case 'slim_p4_19':
				self::show_results( 'popular', 'category', array( 'where' => '(content_type LIKE "%tag%")' ) );
				break;

			// Top Outbound Links
			case 'slim_p4_21':
				self::show_results( 'popular', 'outbound_resource' );
				break;

			// Your Website
			case 'slim_p4_22':
				self::show_your_blog();
				break;

			// Top Bounce Pages
			case 'slim_p4_23':
				self::show_results( 'popular', 'resource', array( 'where' => 'content_type <> "404"', 'having' => 'HAVING COUNT(visit_id) = 1' ) );
				break;

			// Top Exit Pages
			case 'slim_p4_24':
				self::show_results('popular_complete', 'visit_id', array('outer_select_column' => 'resource', 'aggr_function' => 'MAX'));
				break;

			// Top Entry Pages
			case 'slim_p4_25':
				self::show_results('popular_complete', 'visit_id', array('outer_select_column' => 'resource', 'aggr_function' => 'MIN'));
				break;

			// World Map
			case 'slim_p6_01':
				self::show_world_map();
				break;

			// Activity Log
			case 'slim_p7_02':
				include_once(WP_PLUGIN_DIR."/wp-slimstat/admin/view/right-now.php");
				break;

			default:
				break;
		}
		if (!empty($_POST['report_id'])) die();
	}

	public static function report_header( $_report_id = '' ) {
		$header_classes =  !empty( self::$reports_info[ $_report_id ][ 'classes' ] ) ? implode( ' ', self::$reports_info[ $_report_id ][ 'classes' ] ) : '';
		$header_buttons = '<div class="slimstat-header-buttons">'.apply_filters('slimstat_report_header_buttons', '<a class="button-ajax refresh slimstat-font-spin3" title="'.__('Refresh','wp-slimstat').'" href="'.self::fs_url().'"></a>', $_report_id).'</div>';
		$header_tooltip = !empty( self::$reports_info[ $_report_id ][ 'tooltip' ] ) ? "<i class='slimstat-tooltip-trigger corner'></i><span class='slimstat-tooltip-content'>".self::$reports_info[ $_report_id ][ 'tooltip' ]."</span>" : '';

		echo "<div class='postbox $header_classes' id='$_report_id'>$header_buttons<h3 class='hndle'>".self::$reports_info[ $_report_id ][ 'title' ]." $header_tooltip</h3><div class='inside' id='{$_report_id}_inside'>";
		if (wp_slimstat::$options['async_load'] == 'yes') {
			echo '<p class="loading"></p>';
		}
	}

	public static function report_footer(){
		echo '</div></div>';
	}

	public static function report_pagination( $_count_page_results = 0, $_count_all_results = 0, $_show_refresh = false ) {
		$endpoint = min($_count_all_results, wp_slimstat_db::$filters_normalized['misc']['start_from'] + wp_slimstat_db::$filters_normalized['misc']['limit_results']);
		$pagination_buttons = '';
		$direction_prev = is_rtl()?'right':'left';
		$direction_next = is_rtl()?'left':'right';

		if ($endpoint + wp_slimstat_db::$filters_normalized['misc']['limit_results'] < $_count_all_results && $_count_page_results > 0){
			$startpoint = $_count_all_results - $_count_all_results%wp_slimstat_db::$filters_normalized['misc']['limit_results'];
			if ($startpoint == $_count_all_results) $startpoint -= wp_slimstat_db::$filters_normalized['misc']['limit_results'];
			$pagination_buttons .= '<a class="button-ajax slimstat-font-angle-double-'.$direction_next.'" href="'.wp_slimstat_reports::fs_url('start_from equals '.$startpoint).'"></a> ';
		}
		if ($endpoint < $_count_all_results && $_count_page_results > 0){
			$startpoint = wp_slimstat_db::$filters_normalized['misc']['start_from'] + wp_slimstat_db::$filters_normalized['misc']['limit_results'];
			$pagination_buttons .= '<a class="button-ajax slimstat-font-angle-'.$direction_next.'" href="'.wp_slimstat_reports::fs_url('start_from equals '.$startpoint).'"></a> ';
		}
		if (wp_slimstat_db::$filters_normalized['misc']['start_from'] > 0){
			$startpoint = (wp_slimstat_db::$filters_normalized['misc']['start_from'] > wp_slimstat_db::$filters_normalized['misc']['limit_results'])?wp_slimstat_db::$filters_normalized['misc']['start_from']-wp_slimstat_db::$filters_normalized['misc']['limit_results']:0;
			$pagination_buttons .= '<a class="button-ajax slimstat-font-angle-'.$direction_prev.'" href="'.wp_slimstat_reports::fs_url('start_from equals '.$startpoint).'"></a> ';
		}
		if (wp_slimstat_db::$filters_normalized['misc']['start_from'] - wp_slimstat_db::$filters_normalized['misc']['limit_results'] > 0){
			$pagination_buttons .= '<a class="button-ajax slimstat-font-angle-double-'.$direction_prev.'" href="'.wp_slimstat_reports::fs_url('start_from equals 0').'"></a> ';
		}

		$pagination = '<p class="pagination">'.sprintf(__('Results %s - %s of %s', 'wp-slimstat'), number_format(wp_slimstat_db::$filters_normalized['misc']['start_from']+1, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']), number_format($endpoint, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']), number_format($_count_all_results, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']).(($_count_all_results == 1000)?'+':''));
		if ($_show_refresh && wp_slimstat::$options['refresh_interval'] > 0 && !wp_slimstat_db::$filters_normalized['date']['is_past']){
			$pagination .= ' &ndash; '.__('Refresh in','wp-slimstat').' <i class="refresh-timer"></i>';
		}
		$pagination .= $pagination_buttons.'</p>';

		echo $pagination;
	}

	public static function show_results($_type = 'recent', $_columns = 'id', $_args = array()){
		// Initialize default values, if not specified
		$_args = shortcode_atts(
			array(
				'where' => '',
				'having' => '', 
				'as_column' => '',
				'filter_op' => 'equals',
				'outer_select_column' => '',
				'aggr_function' => 'MAX',
				'use_date_filters' => true,
				'show_refresh' => false
			),
			$_args
		);

		// Get ALL the results
		$temp_starting = wp_slimstat_db::$filters_normalized['misc']['start_from'];
		$temp_limit_results = wp_slimstat_db::$filters_normalized['misc']['limit_results'];
		wp_slimstat_db::$filters_normalized['misc']['start_from'] = 0;
		wp_slimstat_db::$filters_normalized['misc']['limit_results'] = 1000;

		switch($_type){
			case 'recent':
				$all_results = wp_slimstat_db::get_recent($_columns, $_args['where'], $_args['having'], $_args['use_date_filters']);
				break;

			case 'popular':
				$all_results = wp_slimstat_db::get_popular($_columns, $_args['where'], $_args['having'], $_args['as_column']);
				break;

			case 'popular_complete':
				$all_results = wp_slimstat_db::get_popular_complete($_columns, $_args['where'], $_args['outer_select_column'], $_args['aggr_function']);
				break;

			default:
				break;
		}

		// Restore the original filters
		wp_slimstat_db::$filters_normalized['misc']['start_from'] = $temp_starting;
		wp_slimstat_db::$filters_normalized['misc']['limit_results'] = $temp_limit_results;

		// Slice the array
		$results = array_slice($all_results, wp_slimstat_db::$filters_normalized['misc']['start_from'], wp_slimstat_db::$filters_normalized['misc']['limit_results']);

		// Count the results
		$count_page_results = count($results);

		if ($count_page_results == 0){
			echo '<p class="nodata">'.__('No data to display','wp-slimstat').'</p>';
			return true;
		}

		// Some reports use aliases for column names
		if (!empty($_args['as_column'])){
			$_columns = $_args['as_column'];
		}
		// Some reports query more than one column
		else if ( strpos($_columns, ',') !== false ) {
			$_columns = explode(',', $_columns);
			$_columns = trim( $_columns[0] );
		}

		self::report_pagination( $count_page_results, count( $all_results ), $_args[ 'show_refresh' ] );
		$is_expanded = (wp_slimstat::$options['expand_details'] == 'yes')?' expanded':'';

		for($i=0; $i<$count_page_results; $i++){
			$row_details = $percentage = '';
			$element_pre_value = '';
			$element_value = $results[$i][$_columns];

			// Convert the IP address
			if (!empty($results[$i]['ip'])){
				$results[$i]['ip'] = long2ip($results[$i]['ip']);
			}

			// Some columns require a special pre-treatment
			switch ( $_columns ){

				case 'browser':
					if (!empty($results[$i]['user_agent']) && wp_slimstat::$options['show_complete_user_agent_tooltip'] == 'yes'){
						$element_pre_value = self::inline_help($results[$i]['user_agent'], false);
					}
					$element_value = $results[$i]['browser'].((isset($results[$i]['browser_version']) && intval($results[$i]['browser_version']) != 0)?' '.$results[$i]['browser_version']:'');
					break;

				case 'category':
					$row_details .= '<br>'.__('Category ID','wp-slimstat').": {$results[$i]['category']}";
					$cat_ids = explode(',', $results[$i]['category']);
					if (!empty($cat_ids)){
						$element_value = '';
						foreach ($cat_ids as $a_cat_id){
							if (empty($a_cat_id)) continue;
							$cat_name = get_cat_name($a_cat_id);
							if (empty($cat_name)) {
								$tag = get_term($a_cat_id, 'post_tag');
								if (!empty($tag->name)) $cat_name = $tag->name;
							}
							$element_value .= ', '.(!empty($cat_name)?$cat_name:$a_cat_id);
						}
						$element_value = substr($element_value, 2);
					}
					break;
				case 'country':
					$row_details .= '<br>'.__('Country Code','wp-slimstat').": {$results[$i]['country']}";
					$element_value = __('c-'.$results[$i]['country'], 'wp-slimstat');
					break;
				case 'ip':
					if (wp_slimstat::$options['convert_ip_addresses'] == 'yes'){
						$element_value = gethostbyaddr($results[$i]['ip']);
					}
					else{
						$element_value = $results[$i]['ip'];
					}
					break;
				case 'language':
					$row_details = '<br>'.__('Language Code','wp-slimstat').": {$results[$i]['language']}";
					$element_value = __('l-'.$results[$i]['language'], 'wp-slimstat');
					break;
				case 'platform':
					$row_details = '<br>'.__('OS Code','wp-slimstat').": {$results[$i]['platform']}";
					$element_value = __($results[$i]['platform'], 'wp-slimstat');
					break;
				case 'resource':
					// FIX ME: do strok only if nice permalinks are not enabled
					$post_id = url_to_postid(strtok($results[$i]['resource'], '?'));
					
					if ($post_id > 0) $row_details = '<br>'.htmlentities($results[$i]['resource'], ENT_QUOTES, 'UTF-8');
					$element_value = self::get_resource_title($results[$i]['resource']);
					break;
				case 'searchterms':
					if ($_type == 'recent'){
						$domain = parse_url( $results[$i]['domain'] );
						$domain = !empty( $domain['host'] ) ? $domain['host'] : '';
						
						$row_details = '<br>'.__('Referrer','wp-slimstat').": $domain";
						$element_value = self::get_search_terms_info($results[$i]['searchterms'], $results[$i]['referer'], true);
					}
					else{
						$element_value = htmlentities($results[$i]['searchterms'], ENT_QUOTES, 'UTF-8');
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
					$element_value = $results[$i]['resource'];
					break;
				default:
			}
			
			$element_value = "<a class='slimstat-filter-link' href='".self::fs_url($_columns.' '.$_args['filter_op'].' '.$results[$i][$_columns])."'>$element_value</a>";

			if ($_type == 'recent'){
				$row_details = date_i18n(wp_slimstat::$options['date_format'].' '.wp_slimstat::$options['time_format'], $results[$i]['dt'], true).$row_details;
			}
			else{
				$percentage = ' <span>'.((self::$pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['counthits']/self::$pageviews)), 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']):0).'%</span>';
				$row_details = __('Hits','wp-slimstat').': '.number_format($results[$i]['counthits'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']).$row_details;
			}

			// Some columns require a special post-treatment
			if ($_columns == 'resource' && strpos($_args['where'], '404') === false){
				$base_url = '';
				if (isset($results[$i]['blog_id'])){
					$base_url = parse_url(get_site_url($results[$i]['blog_id']));
					$base_url = $base_url['scheme'].'://'.$base_url['host'];
				}
				$element_value = '<a target="_blank" class="slimstat-font-logout" title="'.__('Open this URL in a new window','wp-slimstat').'" href="'.$base_url.htmlentities($results[$i]['resource'], ENT_QUOTES, 'UTF-8').'"></a> '.$base_url.$element_value;
			}
			if ($_columns == 'referer'){
				$element_url = htmlentities($results[$i]['referer'], ENT_QUOTES, 'UTF-8');
				$element_value = '<a target="_blank" class="slimstat-font-logout" title="'.__('Open this URL in a new window','wp-slimstat').'" href="'.$element_url.'"></a> '.$element_value;
			}
			if (!empty($results[$i]['ip']) && $_columns != 'ip' && wp_slimstat::$options['convert_ip_addresses'] != 'yes'){
				$row_details .= '<br> IP: <a class="slimstat-filter-link" href="'.self::fs_url('ip equals '.$results[$i]['ip']).'">'.$results[$i]['ip'].'</a>'.(!empty($results[$i]['other_ip'])?' / '.long2ip($results[$i]['other_ip']):'').'<a title="WHOIS: '.$results[$i]['ip'].'" class="slimstat-font-location-1 whois" href="'.wp_slimstat::$options['ip_lookup_service'].$results[$i]['ip'].'"></a>';
			}
			if (!empty($row_details)){
				$row_details = "<b class='slimstat-row-details$is_expanded'>$row_details</b>";
			}

			echo "<p>$element_pre_value$element_value$percentage $row_details</p>";
		}
	}

	public static function show_chart( $_chart_data = array(), $_chart_labels = array() ){
		/* $rtl_filler_current = $rtl_filler_previous = 0;
		if ($GLOBALS['wp_locale']->text_direction == 'rtl' && !wp_slimstat_db::$filters_normalized['selected']['day']){
			$rtl_filler_current = 31-((date_i18n('Ym') == wp_slimstat_db::$filters_normalized['date']['year'].wp_slimstat_db::$filters_normalized['date']['month'])?wp_slimstat_db::$filters_normalized['date']['day']:cal_days_in_month(CAL_GREGORIAN, wp_slimstat_db::$filters_normalized['date']['month'], wp_slimstat_db::$filters_normalized['date']['year']));
			$rtl_filler_previous = 31-cal_days_in_month(CAL_GREGORIAN, date_i18n('m', self::$filters_normalized['utime']['previous_start']), date_i18n('Y', self::$filters_normalized['utime']['previous_start']));
		} */ ?>
		<div id="chart-placeholder"></div><div id="chart-legend"></div>

		<script type="text/javascript">
			SlimStatAdmin.chart_data = [];

			<?php if (!empty($_chart_data['previous']['label'])): ?>
			SlimStatAdmin.chart_data.push({
				label: '<?php echo $_chart_labels[0].' '.$_chart_data['previous']['label'] ?>',
				data: [<?php 
					$tmp_serialize = array();
					$j = 0;
					foreach($_chart_data['previous']['first_metric'] as $a_value){
						$tmp_serialize[] = "[$j, $a_value]";
						$j++;
					}
					echo implode(',', $tmp_serialize); 
				?>],
				points: {
					show: true,
					symbol: function(ctx, x, y, radius, shadow){
						ctx.arc(x, y, 2, 0, Math.PI * 2, false)
					}
				}
			});
			SlimStatAdmin.chart_data.push({
				label: '<?php echo $_chart_labels[1].' '.$_chart_data['previous']['label'] ?>',
				data: [<?php 
					$tmp_serialize = array();
					$j = 0;
					foreach($_chart_data['previous']['second_metric'] as $a_value){
						$tmp_serialize[] = "[$j, $a_value]";
						$j++;
					}
					echo implode(',', $tmp_serialize); 
				?>],
				points: {
					show: true,
					symbol: function(ctx, x, y, radius, shadow){
						ctx.arc(x, y, 2, 0, Math.PI * 2, false)
					}
				}
			});
			<?php endif ?>

			SlimStatAdmin.chart_data.push({
				label: '<?php echo $_chart_labels[0].' '.$_chart_data['current']['label'] ?>',
				data: [<?php 
					$tmp_serialize = array();
					$j = 0;
					foreach($_chart_data['current']['first_metric'] as $a_value){
						$tmp_serialize[] = "[$j, $a_value]";
						$j++;
					}
					echo implode(',', $tmp_serialize); 
				?>],
				points: {
					show: true,
					symbol: function(ctx, x, y, radius, shadow){
						ctx.arc(x, y, 2, 0, Math.PI * 2, false)
					}
				}
			});
			SlimStatAdmin.chart_data.push({
				label: '<?php echo $_chart_labels[1].' '.$_chart_data['current']['label'] ?>',
				data: [<?php 
					$tmp_serialize = array();
					$j = 0;
					foreach($_chart_data['current']['second_metric'] as $a_value){
						$tmp_serialize[] = "[$j, $a_value]";
						$j++;
					}
					echo implode(',', $tmp_serialize); 
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
				$max_ticks = max(count($_chart_data['current']['first_metric']), count($_chart_data['previous']['first_metric']));
				if (!empty(wp_slimstat_db::$filters_normalized['date']['interval'])){
					for ($i = 0; $i < $max_ticks; $i++){
						$tmp_serialize[] = "[$i,'".date('d/m', wp_slimstat_db::$filters_normalized['utime']['start'] + ( $i * 86400) )."']";
					}
				}
				else{
					$min_idx = min(array_keys($_chart_data['current']['first_metric']));
					for ($i = $min_idx; $i < $max_ticks+$min_idx; $i++){
						$tmp_serialize[] = '['.($i-$min_idx).',"'.$i.'"]';
					}
				}
				echo implode(',', $tmp_serialize); 
			?>];
		</script>
		<?php 
	}

	public static function show_about_wpslimstat() { ?>
		<p><?php _e('All Pageviews', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('id', '1=1', false), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('DB Size', 'wp-slimstat') ?> <span><?php echo wp_slimstat_db::get_data_size() ?></span></p>
		<p><?php _e('Tracking Active', 'wp-slimstat') ?> <span><?php _e(ucfirst(wp_slimstat::$options['is_tracking']), 'wp-slimstat') ?></span></p>
		<p><?php _e('Javascript Mode', 'wp-slimstat') ?> <span><?php _e(ucfirst(wp_slimstat::$options['javascript_mode']), 'wp-slimstat') ?></span></p>
		<p><?php _e('Tracking Browser Caps', 'wp-slimstat') ?> <span><?php _e(ucfirst(wp_slimstat::$options['enable_javascript']), 'wp-slimstat') ?></span></p>
		<p><?php _e('Auto purge', 'wp-slimstat') ?> <span><?php echo (wp_slimstat::$options['auto_purge'] > 0)?wp_slimstat::$options['auto_purge'].' '.__('days','wp-slimstat'):__('No','wp-slimstat') ?></span></p>
		<p><?php _e('Oldest pageview', 'wp-slimstat') ?> <span><?php $dt = wp_slimstat_db::get_oldest_visit('1=1', false); echo ($dt == null)?__('No visits','wp-slimstat'):date_i18n(wp_slimstat::$options['date_format'], $dt) ?></span></p>
		<p>Geo IP <span><?php echo date_i18n(wp_slimstat::$options['date_format'], @filemtime(wp_slimstat::$maxmind_path)) ?></span></p><?php
	}

	public static function show_overview_summary(){
		$days_in_range = ceil( ( wp_slimstat_db::$filters_normalized[ 'utime' ][ 'end' ] - wp_slimstat_db::$filters_normalized[ 'utime' ][ 'start' ] ) / 86400 );
		?>

		<p><?php self::inline_help(__('A request to load a single HTML file. Slimstat logs a "pageview" each time the tracking code is executed.','wp-slimstat'));
			_e('Pageviews', 'wp-slimstat'); ?> <span><?php echo number_format(self::$pageviews, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Days in Range', 'wp-slimstat') ?> <span><?php echo $days_in_range ?></span></p>
		<p><?php self::inline_help(__('How many pages have been visited on average every day during the current period.','wp-slimstat'));
			_e('Average Daily Pageviews', 'wp-slimstat') ?> <span><?php echo number_format( intval( self::$pageviews/$days_in_range ), 0, '', wp_slimstat_db::$formats['thousand'] ) ?></span></p>
		<p><?php self::inline_help(__('Visitors who landed on your site after searching for a keyword on Google, Yahoo, etc.','wp-slimstat'));
			_e('From Search Results', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('id', 'searchterms IS NOT NULL'), 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__('Used to differentiate between multiple requests to download a file from one internet address (IP) and requests originating from many distinct addresses','wp-slimstat'));
			_e('Unique IPs', 'wp-slimstat'); ?> <span><?php echo number_format(wp_slimstat_db::count_records('ip'), 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Last 30 minutes', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('id', 'dt > '.(date_i18n('U')-1800), false), 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Today', 'wp-slimstat'); ?> <span><?php echo number_format(wp_slimstat_db::count_records('id', 'dt > '.(date_i18n('U', mktime(0, 0, 0, date_i18n('m'), date_i18n('d'), date_i18n('Y')))), false), 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Yesterday', 'wp-slimstat'); ?> <span><?php echo number_format(wp_slimstat_db::count_records('id', 'dt BETWEEN '.(date_i18n('U', mktime(0, 0, 0, date_i18n('m'), date_i18n('d')-1, date_i18n('Y')))).' AND '.(date_i18n('U', mktime(23, 59, 59, date_i18n('m'), date_i18n('d')-1, date_i18n('Y')))), false), 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p><?php
	}

	public static function show_plugins() {
		$wp_slim_plugins = array( 'flash', 'silverlight', 'acrobat', 'java', 'mediaplayer', 'director', 'real', 'quicktime' );
		$total_human_hits = wp_slimstat_db::count_records( 'id', 'visit_id > 0 AND browser_type <> 1' );

		foreach( $wp_slim_plugins as $i => $a_plugin ){
			$count_results = wp_slimstat_db::count_records( 'id', "plugins LIKE '%{$a_plugin}%'" );
			echo "<p title='".__('Hits','wp-slimstat').": $count_results'>".ucfirst($a_plugin).' <span>';
			echo ($total_human_hits > 0)?number_format(sprintf("%01.2f", (100*$count_results/$total_human_hits)), 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']):0;
			echo '%</span></p>';
		}
	}

	public static function show_visitors_summary($_total_human_hits = 0, $_total_human_visits = 0){
		$new_visitors = wp_slimstat_db::count_records_having( 'ip', 'visit_id > 0 AND browser_type <> 1', 'COUNT(visit_id) = 1' );
		$new_visitors_rate = ($_total_human_hits > 0)?sprintf("%01.2f", (100*$new_visitors/$_total_human_hits)):0;
		if (intval($new_visitors_rate) > 99) $new_visitors_rate = '100';
		$metrics_per_visit = wp_slimstat_db::get_max_and_average_pages_per_visit(); ?>
		
		<p><?php self::inline_help( __( 'A visit is a session of at most 30 minutes. Returning visitors are counted multiple times if they perform multiple visits.', 'wp-slimstat' ) ) ?>
			<?php _e( 'Visits', 'wp-slimstat' ) ?> <span><?php echo number_format( $_total_human_visits, 0, '', wp_slimstat_db::$formats['thousand'] ) ?></span></p>
		<p><?php self::inline_help( __( 'It includes only traffic generated by human visitors.', 'wp-slimstat' ) ) ?>
			<?php _e( 'Unique IPs', 'wp-slimstat' ) ?> <span><?php echo number_format( wp_slimstat_db::count_records('ip', 'visit_id > 0 AND browser_type <> 1'), 0, '', wp_slimstat_db::$formats['thousand'] ) ?></span></p>
		<p><?php self::inline_help( __( 'Percentage of single-page visits, i.e. visits in which the person left your site from the entrance page.', 'wp-slimstat' ) ) ?>
			<?php _e( 'Bounce rate', 'wp-slimstat' ) ?> <span><?php echo number_format( $new_visitors_rate, 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand'] ) ?>%</span></p>
		<p><?php self::inline_help( __( 'Visitors who had previously left a comment on your blog.', 'wp-slimstat' ) ) ?>
			<?php _e( 'Known visitors', 'wp-slimstat') ?> <span><?php echo wp_slimstat_db::count_records( 'username' ) ?></span></p>
		<p><?php self::inline_help( __( 'Human users who visited your site only once.', 'wp-slimstat' ) ) ?>
			<?php _e( 'New visitors', 'wp-slimstat' ) ?> <span><?php echo number_format( $new_visitors, 0, '', wp_slimstat_db::$formats['thousand'] ) ?></span></p>
		<p><?php _e( 'Bots', 'wp-slimstat' ) ?> <span><?php echo number_format( wp_slimstat_db::count_records( 'id', 'browser_type = 1' ), 0, '', wp_slimstat_db::$formats['thousand'] ) ?></span></p>
		<p><?php _e( 'Pages per visit', 'wp-slimstat' ) ?> <span><?php echo number_format( $metrics_per_visit[0]['avghits'], 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand'] ) ?></span></p>
		<p><?php _e( 'Longest visit', 'wp-slimstat' ) ?> <span><?php echo number_format( $metrics_per_visit[0]['maxhits'], 0, '', wp_slimstat_db::$formats['thousand'] ).' '.__('hits','wp-slimstat') ?></span></p><?php
	}

	public static function show_visit_duration(){
		$total_human_visits = wp_slimstat_db::count_records('visit_id', 'visit_id > 0 AND browser_type <> 1');
		$count = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'max(dt) - min(dt) >= 0 AND max(dt) - min(dt) <= 30' );
		$percentage = ($total_human_visits > 0)?sprintf("%01.2f", (100*$count/$total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		$average_time = 30 * $count;
		echo "<p $extra_info>".__('0 - 30 seconds','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'max(dt) - min(dt) > 30 AND max(dt) - min(dt) <= 60' );
		$percentage = ($total_human_visits > 0)?sprintf("%01.2f", (100*$count/$total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		$average_time += 60 * $count;
		echo "<p $extra_info>".__('31 - 60 seconds','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'max(dt) - min(dt) > 60 AND max(dt) - min(dt) <= 180' );
		$percentage = ($total_human_visits > 0)?sprintf("%01.2f", (100*$count/$total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		$average_time += 180 * $count;
		echo "<p $extra_info>".__('1 - 3 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'max(dt) - min(dt) > 180 AND max(dt) - min(dt) <= 300' );
		$percentage = ($total_human_visits > 0)?sprintf("%01.2f", (100*$count/$total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		$average_time += 300 * $count;
		echo "<p $extra_info>".__('3 - 5 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'max(dt) - min(dt) > 300 AND max(dt) - min(dt) <= 420' );
		$percentage = ($total_human_visits > 0)?sprintf("%01.2f", (100*$count/$total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		$average_time += 420 * $count;
		echo "<p $extra_info>".__('5 - 7 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'max(dt) - min(dt) > 420 AND max(dt) - min(dt) <= 600' );
		$percentage = ($total_human_visits > 0)?sprintf("%01.2f", (100*$count/$total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		$average_time += 600* $count;
		echo "<p $extra_info>".__('7 - 10 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having( 'visit_id', 'visit_id > 0 AND browser_type <> 1', 'max(dt) - min(dt) > 600' );
		$percentage = ($total_human_visits > 0)?sprintf("%01.2f", (100*$count/$total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		$average_time += 900 * $count;
		echo "<p $extra_info>".__('More than 10 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		if ($total_human_visits > 0){
			$average_time /= $total_human_visits;
			$average_time = date('m:s', intval($average_time));
		}
		else{
			$average_time = '0:00';
		}
		echo '<p>'.__('Average time on site','wp-slimstat')." <span>$average_time </span></p>";
	}
	
	public static function show_traffic_sources_summary(){
		$total_human_hits = wp_slimstat_db::count_records('id', 'visit_id > 0 AND browser_type <> 1');
		$new_visitors = wp_slimstat_db::count_records_having( 'ip', 'visit_id > 0', 'COUNT(visit_id) = 1' );
		$new_visitors_rate = ($total_human_hits > 0)?sprintf("%01.2f", (100*$new_visitors/$total_human_hits)):0;
		if (intval($new_visitors_rate) > 99) $new_visitors_rate = '100'; ?>

		<p><?php self::inline_help(__('A request to load a single HTML file. Slimstat logs a "pageview" each time the tracking code is executed.','wp-slimstat')) ?>
			<?php _e('Pageviews', 'wp-slimstat') ?> <span><?php echo number_format(self::$pageviews, 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__('A referrer (or referring site) is the site that a visitor previously visited before following a link to your site.','wp-slimstat')) ?>
			<?php _e('Unique Referrers', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('referer', "referer NOT LIKE '%{$_SERVER['SERVER_NAME']}%'"), 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("Visitors who visited the site by typing the URL directly into their browser. <em>Direct</em> can also refer to the visitors who clicked on the links from their bookmarks/favorites, untagged links within emails, or links from documents that don't include tracking variables.",'wp-slimstat')) ?>
			<?php _e('Direct Pageviews', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('id', 'resource IS NULL'), 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("Visitors who came to your site via searches on Google or some other search engine.",'wp-slimstat')) ?>
			<?php _e('From a search result', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('id', "searchterms IS NOT NULL AND referer IS NOT NULL AND referer NOT LIKE '%".home_url()."%'"), 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("The first page that a user views during a session. This is also known as the <em>entrance page</em>. For example, if they search for 'Brooklyn Office Space,' and they land on your home page, it gets counted (for that visit) as a landing page.",'wp-slimstat')) ?>
			<?php _e('Unique Landing Pages', 'wp-slimstat') ?> <span><?php echo number_format (wp_slimstat_db::count_records( 'resource' ), 0, '', wp_slimstat_db::$formats[ 'thousand' ] ) ?></span></p>
		<p><?php self::inline_help(__("Number of single-page visits to your site over the selected period.",'wp-slimstat')) ?>
			<?php _e('Bounce Pages', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_bouncing_pages(), 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__('Percentage of single-page visits, i.e. visits in which the person left your site from the entrance page.','wp-slimstat')) ?>
			<?php _e('New Visitors Rate', 'wp-slimstat') ?> <span><?php echo number_format($new_visitors_rate, 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?>%</span></p>
		<p><?php self::inline_help(__("Visitors who visited the site in the last 5 minutes coming from a search engine.",'wp-slimstat')) ?>
			<?php _e('Currently from search engines', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('id', "searchterms IS NOT NULL  AND referer IS NOT NULL AND referer NOT LIKE '%".home_url()."%' AND dt > UNIX_TIMESTAMP()-300", false), 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p><?php
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
	}

	public static function show_world_map(){
		wp_slimstat_db::$filters_normalized['misc']['limit_results'] = 9999;
		$countries = wp_slimstat_db::get_popular('country');
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
	}
	
	public static function show_your_blog(){
		if (false === ($your_content = get_transient( 'slimstat_your_content' ))){
			$your_content = array();
			$your_content['content_items'] = $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type <> 'revision' AND post_status <> 'auto-draft'");
			$your_content['posts'] = $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'post'");
			$your_content['comments'] = $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->comments}");
			$your_content['pingbacks'] = $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->comments} WHERE comment_type = 'pingback'");
			$your_content['trackbacks'] = $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->comments} WHERE comment_type = 'trackback'");
			$your_content['oldest_post_timestamp'] = $GLOBALS['wpdb']->get_var("SELECT UNIX_TIMESTAMP(post_date) FROM {$GLOBALS['wpdb']->posts} WHERE post_status = 'publish' AND post_type = 'post' ORDER BY post_date ASC LIMIT 0,1");
			$your_content['avg_comments_per_post'] = !empty($your_content['posts'])?$your_content['comments']/$your_content['posts']:0;
			$days_in_interval = floor((date_i18n('U')-$your_content['oldest_post_timestamp'])/86400);
			$your_content['avg_posts_per_day'] = ($days_in_interval > 0)?$your_content['posts']/$days_in_interval:$your_content['posts'];

			$your_content['avg_server_latency'] = $GLOBALS['wpdb']->get_var("SELECT AVG(server_latency) FROM {$GLOBALS['wpdb']->prefix}slim_stats WHERE server_latency <> 0");
			$your_content['avg_page_speed'] = $GLOBALS['wpdb']->get_var("SELECT AVG(page_performance) FROM {$GLOBALS['wpdb']->prefix}slim_stats WHERE page_performance <> 0");

			// Store values as transients for 30 minutes
			set_transient('slimstat_your_content', $your_content, 1800);
		}
		?>
		
		<p><?php self::inline_help(__("This value includes not only posts, but also custom post types, regardless of their status",'wp-slimstat')) ?>
			<?php _e('Content Items', 'wp-slimstat') ?> <span><?php echo number_format($your_content['content_items'], 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Total Comments', 'wp-slimstat') ?> <span><?php echo number_format($your_content['comments'], 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Pingbacks', 'wp-slimstat') ?> <span><?php echo number_format($your_content['pingbacks'], 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Trackbacks', 'wp-slimstat') ?> <span><?php echo number_format($your_content['trackbacks'], 0, '', wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Avg Comments Per Post', 'wp-slimstat') ?> <span><?php echo number_format($your_content['avg_comments_per_post'], 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Avg Posts Per Day', 'wp-slimstat') ?> <span><?php echo number_format($your_content['avg_posts_per_day'], 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Avg Server Latency', 'wp-slimstat') ?> <span><?php echo number_format($your_content['avg_server_latency'], 0, '', wp_slimstat_db::$formats['thousand']) ?> ms</span></p>
		<p><?php _e('Avg Page Load Time', 'wp-slimstat') ?> <span><?php echo number_format($your_content['avg_page_speed'], 0, '', wp_slimstat_db::$formats['thousand']) ?> ms</span></p><?php
	}

	public static function get_search_terms_info($_searchterms = '', $_referer = '', $_serp_only = false){
		$query_details = '';
		$search_terms_info = '';
		$domain = parse_url($_referer);
		$domain = !empty( $domain['host'] ) ? $domain['host'] : '';

		parse_str("daum=search?q&naver=search.naver?query&google=search?q&yahoo=search?p&bing=search?q&aol=search?query&lycos=web?q&ask=web?q&cnn=search/?query&about=?q&mamma=result.php?q&voila=S/voila?rdata&virgilio=ricerca?qs&baidu=s?wd&yandex=yandsearch?text&najdi=search.jsp?q&seznam=?q&onet=wyniki.html?qt&yam=Search/Web/DefaultCSA.aspx?k&pchome=/search/?q&kvasir=alle?q&arama.mynet=web/goal/1/?q&nova_rambler=search?query", $query_formats);
		preg_match("/(daum|naver|google|yahoo|bing|aol|lycos|ask|cnn|about|mamma|voila|virgilio|baidu|yandex|najdi|seznam|onet|szukacz|yam|pchome|kvasir|mynet|ekolay|rambler)./", $domain, $matches);
		parse_str($_referer, $query_parse_str);

		if (!empty($query_parse_str['source']) && !$_serp_only){
			$query_details = __('src','wp-slimstat').": {$query_parse_str['source']}";
		}
		if (!empty($query_parse_str['cd'])){
			$query_details = __('serp','wp-slimstat').": {$query_parse_str['cd']}";
		}
		if (!empty($query_details)){
			$query_details = "($query_details)";
		}

		if (!empty($_searchterms)){		
			$search_terms_info = htmlentities($_searchterms, ENT_QUOTES, 'UTF-8').'<a class="slimstat-font-logout" target="_blank" title="'.htmlentities(__('Go to the referring page','wp-slimstat'), ENT_QUOTES, 'UTF-8').'" href="'.$_referer.'"></a>';
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
				if (!array_key_exists($a_filter_label, wp_slimstat_db::$filters_names) || strpos($a_filter_label, 'no_filter') !== false){
					continue;
				}

				$a_filter_value_no_slashes = htmlentities(str_replace('\\','', $a_filter_details[1]), ENT_QUOTES, 'UTF-8');
				$filters_html .= "<li>".strtolower(wp_slimstat_db::$filters_names[$a_filter_label][ 0 ]).' '.__(str_replace('_', ' ', $a_filter_details[0]),'wp-slimstat')." $a_filter_value_no_slashes <a class='slimstat-remove-filter slimstat-font-cancel' title='".htmlentities(__('Remove filter for','wp-slimstat'), ENT_QUOTES, 'UTF-8').' '.wp_slimstat_db::$filters_names[ $a_filter_label ][ 0 ]."' href='".self::fs_url("$a_filter_label equals ")."'></a></li>";
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
		$request_page = 'wp-slim-view-1';

		// Are we on the Dashboard?
		if ( empty( $_REQUEST[ 'page' ] ) ) {
			$request_uri = str_replace( 'index.php', 'admin.php', $request_uri );
		}
		else if ( array_key_exists( $_REQUEST[ 'page' ], self::$screens_info ) ) {
			$request_page = $_REQUEST[ 'page' ];
		}
		else {
			return '';
		}

		$filtered_url = ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ? explode( '?', $_SERVER["HTTP_REFERER"] ) : explode( '?', $request_uri );
		$filtered_url = $filtered_url[ 0 ] . '?page=' . $request_page;

		// Columns
		$filters_normalized = wp_slimstat_db::parse_filters( $_filters, false );
//var_dump( $_filters );
		if (!empty($filters_normalized['columns'])){
			foreach($filters_normalized['columns'] as $a_key => $a_filter){
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
	public static function get_resource_title($_resource = ''){
		if (wp_slimstat::$options['convert_resource_urls_to_titles'] == 'yes'){	
			$post_id = url_to_postid(strtok($_resource, '?'));
			if ($post_id > 0){
				return get_the_title($post_id);
			}
		}
		return htmlentities(urldecode($_resource), ENT_QUOTES, 'UTF-8');
	}
	
	public static function inline_help($_text = '', $_echo = true){
		$wrapped_text = "<i class='slimstat-tooltip-trigger corner'></i><span class='slimstat-tooltip-content'>$_text</span>";
		if ($_echo)
			echo $wrapped_text;
		else
			return $wrapped_text;
	}
}