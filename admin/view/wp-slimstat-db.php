<?php

// Let's define the main class with all the methods that we need
class wp_slimstat_db {
	// Filters
	public static $columns_names = array();
	public static $operator_names = array();
	public static $filters_normalized = array();

	// Number and date formats
	public static $formats = array( 'decimal' => ',', 'thousand' => '.' );

	// Structure that maps filters to SQL information (table names, clauses, lookup tables, etc)
	public static $sql_where = array( 'columns' => '', 'time_range' => '' );

	// Filters that are not visible in the dropdown
	public static $all_columns_names = array();

	// Debug message
	public static $debug_message = '';

	/*
	 * Sets the filters and other structures needed to store the data retrieved from the DB
	 */
	public static function init( $_filters = '' ) {
		// Decimal and thousand separators
		if ( wp_slimstat::$settings[ 'use_european_separators' ] == 'no' ){
			self::$formats[ 'decimal' ] = '.';
			self::$formats[ 'thousand' ] = ',';
		}

		// List of supported filters and their friendly names
		self::$columns_names = array(
			'browser' => array( __( 'Browser', 'wp-slimstat' ), 'varchar' ),
			'country' => array( __( 'Country Code', 'wp-slimstat' ), 'varchar' ),
			'ip' => array( __( 'IP Address', 'wp-slimstat' ), 'varchar' ),
			'searchterms' => array( __( 'Search Terms', 'wp-slimstat' ), 'varchar' ),
			'language' => array( __( 'Language Code', 'wp-slimstat' ), 'varchar' ),
			'platform' => array( __( 'Operating System', 'wp-slimstat' ), 'varchar' ),
			'resource' => array( __( 'Permalink', 'wp-slimstat' ), 'varchar' ),
			'referer' => array( __( 'Referer', 'wp-slimstat' ), 'varchar' ),
			'username' => array( __( 'Visitor\'s Username', 'wp-slimstat' ), 'varchar' ),
			'outbound_resource' => array( __( 'Outbound Link', 'wp-slimstat' ), 'varchar' ),
			'page_performance' => array( __( 'Page Speed', 'wp-slimstat' ), 'int' ),
			'no_filter_selected_2' => array( '', 'none' ),
			'no_filter_selected_3' => array( __( '-- Advanced filters --', 'wp-slimstat' ), 'none' ),
			'plugins' => array( __( 'Browser Capabilities', 'wp-slimstat' ), 'varchar' ),
			'browser_version' => array( __( 'Browser Version', 'wp-slimstat' ), 'varchar' ),
			'browser_type' => array( __( 'Browser Type', 'wp-slimstat' ), 'int' ),
			'user_agent' => array( __( 'User Agent', 'wp-slimstat' ), 'varchar' ),
			'city' => array( __( 'City', 'wp-slimstat' ), 'varchar' ),
			'location' => array( __( 'Coordinates', 'wp-slimstat' ), 'varchar' ),
			'notes' => array( __( 'Annotations', 'wp-slimstat' ), 'varchar' ),
			'server_latency' => array( __( 'Server Latency', 'wp-slimstat' ), 'int' ),
			'author' => array( __( 'Post Author', 'wp-slimstat' ), 'varchar' ),
			'category' => array( __( 'Post Category ID', 'wp-slimstat' ), 'varchar' ),
			'other_ip' => array( __( 'Originating IP', 'wp-slimstat' ), 'varchar' ),
			'content_type' => array( __( 'Resource Content Type', 'wp-slimstat' ), 'varchar' ),
			'content_id' => array( __( 'Resource ID', 'wp-slimstat' ), 'int' ),
			'screen_width' => array( __( 'Screen Width', 'wp-slimstat' ), 'int' ),
			'screen_height' => array( __( 'Screen Height', 'wp-slimstat' ), 'int' ),
			'resolution' => array( __( 'Viewport Size', 'wp-slimstat' ), 'varchar' ),
			'visit_id' => array( __( 'Visit ID', 'wp-slimstat' ), 'int' )
		);

		if ( wp_slimstat::$settings[ 'geolocation_country' ] == 'on' ) {
			unset( self::$columns_names[ 'city' ] );
			unset( self::$columns_names[ 'location' ] );
		}

		// List of supported filters and their friendly names
		self::$operator_names = array(
			'equals' => __( 'equals', 'wp-slimstat' ),
			'is_not_equal_to' => __( 'is not equal to', 'wp-slimstat' ),
			'contains' => __( 'contains', 'wp-slimstat' ),
			'includes_in_set' => __( 'is included in', 'wp-slimstat' ),
			'does_not_contain' => __( 'does not contain', 'wp-slimstat' ),
			'starts_with' => __( 'starts with', 'wp-slimstat' ),
			'ends_with' => __( 'ends with', 'wp-slimstat' ),
			'sounds_like' => __( 'sounds like', 'wp-slimstat' ),
			'is_greater_than' => __( 'is greater than', 'wp-slimstat' ),
			'is_less_than' => __( 'is less than', 'wp-slimstat' ),
			'between' => __( 'is between (x,y)', 'wp-slimstat' ),
			'matches' => __( 'matches', 'wp-slimstat' ),
			'does_not_match' => __( 'does not match', 'wp-slimstat' ),
			'is_empty' => __( 'is empty', 'wp-slimstat' ),
			'is_not_empty' => __( 'is not empty', 'wp-slimstat' ),
		);

		// The following filters will not be displayed in the dropdown
		self::$all_columns_names = array_merge( array(

			// Date and Time
			'hour' => array( __( 'Hour', 'wp-slimstat' ), 'int' ),
			'day' => array( __( 'Day', 'wp-slimstat' ), 'int' ),
			'month' => array( __( 'Month', 'wp-slimstat' ), 'int' ),
			'year' => array( __( 'Year', 'wp-slimstat' ), 'int' ),
			'interval' => array( __( 'days', 'wp-slimstat' ), 'int' ),
			'interval_hours' => array( __( 'hours', 'wp-slimstat' ), 'int' ),
			'dt' => array( __( 'Timestamp', 'wp-slimstat' ), 'int' ),
			'dt_out' => array( __( 'Exit Timestamp', 'wp-slimstat' ), 'int' ),

			// Other columns
			'language_calculated' => array( __( 'Language', 'wp-slimstat' ), 'varchar' ),
			'platform_calculated' => array( __( 'Operating System', 'wp-slimstat' ), 'varchar' ),
			'resource_calculated' => array( __( 'Permalink', 'wp-slimstat' ), 'varchar' ),
			'referer_calculated' => array( __( 'Referer', 'wp-slimstat' ), 'varchar' ),
			'metric' => array( __( 'Metric', 'wp-slimstat' ), 'varchar' ),
			'value' => array( __( 'Value', 'wp-slimstat' ), 'varchar' ),
			'counthits' => array( __( 'Hits', 'wp-slimstat' ), 'int' ),
			'percentage' => array( __( 'Percentage', 'wp-slimstat' ), 'int' ),
			'tooltip' => array( __( 'Notes', 'wp-slimstat' ), 'varchar' ),
			'details' => array( __( 'Notes', 'wp-slimstat' ), 'varchar' ),

			// Events
			'event_id' => array( __( 'Event ID', 'wp-slimstat' ), 'int' ),
			'type' => array( __( 'Type', 'wp-slimstat' ), 'int' ),
			'event_description' => array( __( 'Event Description', 'wp-slimstat' ), 'varchar' ),
			'position' => array( __( 'Event Coordinates', 'wp-slimstat' ), 'int' ),

			'limit_results' => array( __( 'Max Results', 'wp-slimstat' ), 'int' ),
			'start_from' => array( __( 'Offset', 'wp-slimstat' ), 'int' ),

			// Misc Filters
			'strtotime' => array( 0, 'int' )
		), self::$columns_names );

		// Allow third party plugins to add even more column names to the array
		self::$all_columns_names = apply_filters( 'slimstat_column_names', self::$all_columns_names );

		// Filters use the following format: browser equals Firefox&&&country contains gb
		$filters_array = array();

		// Filters are set via javascript as hidden fields and submitted as a POST request. They override anything passed through the regular input fields
		if ( !empty( $_POST[ 'fs' ] ) && is_array( $_POST[ 'fs' ] ) ) {
			foreach( $_POST[ 'fs' ] as $a_request_filter_name => $a_request_filter_value ) {
				$filters_array[ htmlspecialchars( $a_request_filter_name ) ] = "$a_request_filter_name $a_request_filter_value";
			}
		}

		// Date filters (input fields)
		foreach ( array( 'hour', 'day', 'month', 'year', 'interval', 'interval_hours' ) as $a_date_time_filter_name ) {
			if ( isset( $_POST[ $a_date_time_filter_name ] ) && strlen( $_POST[ $a_date_time_filter_name ] ) > 0 ) { // here we use isset instead of !empty to handle ZERO as a valid input value
				$filters_array[ $a_date_time_filter_name ] = "$a_date_time_filter_name equals " . intval( $_POST[ $a_date_time_filter_name ] );
			}
		}

		// Fields and drop downs
		if ( !empty( $_POST[ 'f' ] ) && !empty( $_POST[ 'o' ] ) ) {
			$filters_array[ htmlspecialchars( $_POST[ 'f' ] ) ] = "{$_POST[ 'f' ]} {$_POST[ 'o' ]} " . ( isset( $_POST[ 'v' ] ) ? $_POST[ 'v' ] : '' );
		}

		// Filters set via the plugin options
		if ( wp_slimstat::$settings[ 'restrict_authors_view' ] == 'on' && !current_user_can( 'manage_options' ) && !empty( $GLOBALS[ 'current_user' ]->user_login ) ) {
			$filters_array[ 'author' ] = 'author equals ' . $GLOBALS[ 'current_user' ]->user_login;
		}

		if ( !empty( $filters_array ) ) {
			$filters_raw = implode( '&&&', $filters_array );
		}

		// Filters are defined as: browser equals Chrome&&&country starts_with en
		if ( !isset( $filters_raw ) || !is_string( $filters_raw ) ) {
			$filters_raw = '';
		}

		if ( !empty( $_filters ) && is_string( $_filters ) ) {
			if ( !empty( $filters_raw ) ) {
				$filters_raw = empty( $filters_raw ) ? $_filters : $_filters . '&&&' . $filters_raw;
			}
			else {
				$filters_raw = $_filters;
			}
		}

		// Hook for the... filters
		$filters_raw = apply_filters( 'slimstat_db_pre_filters', $filters_raw );

		// Normalize the filters
		self::$filters_normalized = self::init_filters( $filters_raw );
	}
	// end init

	/**
	 * Builds the array of WHERE clauses to be used later in our SQL queries
	 */
	protected static function _get_sql_where( $_filters_normalized = array(), $_slim_stats_table_alias = '' ) {
		$sql_array = array();

		foreach ( $_filters_normalized as $a_filter_column => $a_filter_data ) {
			// Add-ons can set their own custom filters, which are ignored here
			if ( strpos( $a_filter_column, 'addon_' ) !== false ) {
				continue;
			}

			$sql_array[] = self::get_single_where_clause( $a_filter_column, $a_filter_data[ 0 ], $a_filter_data[ 1 ], $_slim_stats_table_alias );
		}

		// Flatten array
		if ( !empty( $sql_array ) ) {
			return implode( ' AND ', $sql_array );
		}

		return '';
	}

	public static function get_combined_where( $_where = '', $_column = '*', $_use_date_filters = true, $_slim_stats_table_alias = '' ) {
		$dt_with_alias = 'dt';
		if ( !empty( $_slim_stats_table_alias ) ) {
			$dt_with_alias = $_slim_stats_table_alias . '.' . $dt_with_alias;
		}

		$time_range_condition = '';
		if ( empty( $_where ) ) {
			if ( !empty( self::$filters_normalized[ 'columns' ] ) ) {
				$_where = self::_get_sql_where( self::$filters_normalized[ 'columns' ], $_slim_stats_table_alias );

				if ($_use_date_filters) {
					$time_range_condition = "$dt_with_alias BETWEEN " . self::$filters_normalized[ 'utime' ][ 'start' ] . ' AND ' . self::$filters_normalized[ 'utime' ][ 'end' ];
				}
			}
			elseif ( $_use_date_filters ) {
				$time_range_condition = "$dt_with_alias BETWEEN " . self::$filters_normalized[ 'utime' ][ 'start' ] . ' AND ' . self::$filters_normalized[ 'utime' ][ 'end' ];
			}

			// This could happen if we have custom filters (add-ons, third party tools)
			if ( empty( $_where ) ) {
				$_where = '1=1';
			}
		}
		else {
			if ( $_where != '1=1' && !empty( self::$filters_normalized[ 'columns' ] ) ) {
				$new_clause = self::_get_sql_where( self::$filters_normalized[ 'columns' ], $_slim_stats_table_alias );

				// This condition could be empty if it's related to a custom column
				if ( !empty( $new_clause ) ) {
					$_where .= ' AND ' . $new_clause;
				}
			}
			if ( $_use_date_filters ) {
				$time_range_condition = "$dt_with_alias BETWEEN " . self::$filters_normalized[ 'utime' ][ 'start' ] . ' AND ' . self::$filters_normalized[ 'utime' ][ 'end' ];
			}
		}

		if ( !empty( $_where ) && !empty( $time_range_condition ) ) {
			$_where = "$_where AND $time_range_condition";
		}
		else {
			$_where = trim( "$_where $time_range_condition" );
		}

		if ( !empty( $_column ) && !empty( self::$columns_names[ $_column ] ) ) {
			$_column = str_replace( '_calculated', '', $_column );
			$column_with_alias = $_column;
			if ( !empty( $_slim_stats_table_alias ) ) {
				$column_with_alias = $_slim_stats_table_alias . '.' . $column_with_alias;
			}

			$filter_empty = "$column_with_alias " . ( ( self::$columns_names[ $_column ] [ 1 ] == 'varchar' ) ? 'IS NULL' : '= 0' );
			$filter_not_empty = "$column_with_alias " . ( ( self::$columns_names[ $_column ] [ 1 ] == 'varchar' ) ? 'IS NOT NULL' : '<> 0' );

			if ( strpos( $_where, $filter_empty ) === false && strpos( $_where, $filter_not_empty) === false) {
				$_where = "$filter_not_empty AND $_where";
			}
		}

		return $_where;
	}

	/**
	 * Translates user-friendly operators into SQL conditions
	 */
	public static function get_single_where_clause( $_column = 'id', $_operator = 'equals', $_value = '', $_slim_stats_table_alias = '' ) {
		$filter_empty = ( !empty( self::$columns_names[ $_column ] ) && self::$columns_names[ $_column ] [ 1 ] == 'varchar' ) ? 'IS NULL' : '= 0';
		$filter_not_empty = ( !empty( self::$columns_names[ $_column ] ) && self::$columns_names[ $_column ] [ 1 ] == 'varchar' ) ? 'IS NOT NULL' : '<> 0';

		$_column = str_replace( '_calculated', '', $_column );

		$column_with_alias = $_column;
		if ( !empty( $_slim_stats_table_alias ) ) {
			$column_with_alias = $_slim_stats_table_alias . '.' . $_column;
		}

		switch( $_column ) {
			case 'ip':
			case 'other_ip':
				$filter_empty = '= "0.0.0.0"';
				break;
			default:
				break;
		}

		$where = array( '', $_value );
		switch ( $_operator ) {
			case 'is_not_equal_to':
				$where[ 0 ] = "$column_with_alias <> %s";
				break;

			case 'contains':
				$where = array( "$column_with_alias LIKE %s", '%'.$_value.'%' );
				break;

			case 'includes_in_set':
			case 'included_in_set':
				$where[ 0 ] = "FIND_IN_SET($column_with_alias, %s) > 0";
				break;

			case 'does_not_contain':
				$where = array( "$column_with_alias NOT LIKE %s", '%'.$_value.'%' );
				break;

			case 'starts_with':
				$where = array( "$column_with_alias LIKE %s", $_value.'%' );
				break;

			case 'ends_with':
				$where = array( "$column_with_alias LIKE %s", '%'.$_value );
				break;

			case 'sounds_like':
				$where[ 0 ] = "SOUNDEX($column_with_alias) = SOUNDEX(%s)";
				break;

			case 'is_empty':
				$where = array( "$column_with_alias $filter_empty", '' );
				break;

			case 'is_not_empty':
				$where = array( "$column_with_alias $filter_not_empty", '' );
				break;

			case 'is_greater_than':
				$where[ 0 ] = "$column_with_alias > %d";
				break;

			case 'is_less_than':
				$where[ 0 ] = "$column_with_alias < %d";
				break;

			case 'between':
				$range = explode( ',', $_value );
				$where = array( "$column_with_alias BETWEEN %d AND %d", array( $range[ 0 ], $range[ 1 ] ) );
				break;

			case 'matches':
				$where[ 0 ] = "$column_with_alias REGEXP %s";
				break;

			case 'does_not_match':
				$where[ 0 ] = "$column_with_alias NOT REGEXP %s";
				break;

			default:
				$where[ 0 ] = "$column_with_alias = %s";
				break;
		}

		if ( isset( $where[ 1 ] ) && $where[ 1 ] != '' ) {
			return $GLOBALS[ 'wpdb' ]->prepare( $where[ 0 ], $where[ 1 ] );
		}
		else {
			return $where[ 0 ];
		}
	}

	public static function get_results( $_sql = '', $_select_no_aggregate_values = '', $_order_by = '', $_group_by = '', $_aggregate_values_add = '' ) {
		$_sql = apply_filters( 'slimstat_get_results_sql', $_sql, $_select_no_aggregate_values, $_order_by, $_group_by, $_aggregate_values_add );

		if ( wp_slimstat::$settings[ 'show_sql_debug' ] == 'on' ) {
			self::$debug_message .= "<p class='debug'>$_sql</p>";
		}

		return wp_slimstat::$wpdb->get_results( $_sql, ARRAY_A );
	}

	public static function get_var( $_sql = '', $_aggregate_value = '' ) {
		$_sql = apply_filters( 'slimstat_get_var_sql', $_sql, $_aggregate_value );

		if ( wp_slimstat::$settings[ 'show_sql_debug' ] == 'on' ) {
			self::$debug_message .= "<p class='debug'>$_sql</p>";
		}

		return wp_slimstat::$wpdb->get_var( $_sql );
	}

	public static function parse_filters( $_filters_raw ) {
		$filters_parsed = array(
			'columns' => array(),
			'date' => array()
		);

		if ( !empty( $_filters_raw ) ) {
			$matches = explode( '&&&', $_filters_raw );

			foreach( $matches as $idx => $a_match ) {
				preg_match( '/([^\s]+)\s([^\s]+)\s(.+)?/', urldecode( $a_match ), $a_filter );

				if ( empty( $a_filter ) || ( ( !array_key_exists( $a_filter[ 1 ], self::$all_columns_names ) || strpos( $a_filter[ 1 ], 'no_filter' ) !== false ) && strpos( $a_filter[ 1 ], 'addon_' ) === false ) ) {
					continue;
				}

				switch( $a_filter[ 1 ] ) {
					case 'strtotime':
						$custom_date = strtotime( $a_filter[ 3 ], date_i18n( 'U' ) );

						$filters_parsed[ 'date' ][ 'hour' ] = intval( date( 'H', $custom_date ) );
						$filters_parsed[ 'date' ][ 'day' ] = intval( date( 'j', $custom_date ) );
						$filters_parsed[ 'date' ][ 'month' ] = intval( date( 'n', $custom_date ) );
						$filters_parsed[ 'date' ][ 'year' ] = intval( date( 'Y', $custom_date ) );
						break;

					case 'hour':
					case 'day':
					case 'month':
					case 'year':
						if ( is_numeric( $a_filter[ 3 ] ) ) {
							$filters_parsed[ 'date' ][ $a_filter[ 1 ] ] = intval( $a_filter[ 3 ] );
						}
						else{
							// Try to apply strtotime to value
							switch( $a_filter[ 1 ] ) {
								case 'hour':
									$filters_parsed[ 'date' ][ 'hour' ] = intval( date( 'H', strtotime( $a_filter[ 3 ], date_i18n( 'U' ) ) ) );
									break;

								case 'day':
									$filters_parsed[ 'date' ][ 'day' ] = intval( date( 'j', strtotime( $a_filter[ 3 ], date_i18n( 'U' ) ) ) );
									break;

								case 'month':
									$filters_parsed[ 'date' ][ 'month' ] = intval( date( 'n', strtotime( $a_filter[ 3 ], date_i18n( 'U' ) ) ) );
									break;

								case 'year':
									$filters_parsed[ 'date' ][ 'year' ] = intval( date( 'Y', strtotime( $a_filter[ 3 ], date_i18n( 'U' ) ) ) );
									break;

								default:
									break;
							}

							if ( $filters_parsed[ 'date' ][ $a_filter[ 1 ] ] === false ) {
								unset( $filters_parsed[ 'date' ][ $a_filter[ 1 ] ] );
							}
						}
						break;

					case 'interval':
					case 'interval_hours':
						$intval_filter = intval( $a_filter[ 3 ] );
						$filters_parsed[ 'date' ][ $a_filter[ 1 ] ] = $intval_filter;
						break;

					case 'limit_results':
					case 'start_from':
						$filters_parsed[ 'misc' ][ $a_filter[ 1 ] ] = str_replace( '\\', '', htmlspecialchars_decode( $a_filter[ 3 ] ) );
						break;

					case 'content_id':
						if ( !empty( $a_filter[ 3 ] ) ) {
							$content_id = ( $a_filter[ 3 ] == 'current' && !empty( $GLOBALS[ 'post' ]->ID ) ) ? $GLOBALS[ 'post' ]->ID : $a_filter[ 3 ];
							$filters_parsed[ 'columns' ][ $a_filter[ 1 ] ] = array( $a_filter[ 2 ], $content_id );
							break;
						}
						// no break here: if value IS numeric, go to the default parser here below

					default:
						$filters_parsed[ 'columns' ][ $a_filter[ 1 ] ] = array( $a_filter[ 2 ], isset( $a_filter[ 3 ] ) ? str_replace( '\\', '', htmlspecialchars_decode( $a_filter[ 3 ] ) ) : '' );
						break;
				}
			}
		}

		return $filters_parsed;
	}

	public static function init_filters( $_filters_raw = '' ) {
		$fn = self::parse_filters( $_filters_raw );

		// Initialize default values
		if ( empty( $fn[ 'misc' ][ 'limit_results' ] ) ) {
			$fn[ 'misc' ][ 'limit_results' ] = wp_slimstat::$settings[ 'limit_results' ];
		}
		if ( empty( $fn[ 'misc' ][ 'start_from' ] ) ) {
			$fn[ 'misc' ][ 'start_from' ] = 0;
		}

		$fn[ 'utime' ] = array(
			'start' => 0,
			'end' => 0
		);

		// Temporarily disable any filters on date_i18n
		wp_slimstat::toggle_date_i18n_filters( false );

		// Normalize the various date values

		// Intervals
		// If neither an interval nor interval_hours were specified...
		if ( !isset( $fn[ 'date' ][ 'interval_hours' ] ) && empty( $fn[ 'date' ][ 'interval' ] ) ) {
			$fn[ 'date' ][ 'interval_hours' ] = 0;

			// If a day has been specified, then interval = 1 (show only that day)
			if ( !empty( $fn[ 'date' ][ 'day' ] ) ) {
				$fn[ 'date' ][ 'interval' ] = -1;
			}
			// Show last X days, if the corresponding setting is enabled
			else if ( empty( wp_slimstat::$settings[ 'use_current_month_timespan' ] ) || wp_slimstat::$settings[ 'use_current_month_timespan' ] != 'on' ) {
				$fn[ 'date' ][ 'interval' ] = - abs( wp_slimstat::$settings[ 'posts_column_day_interval' ] );
			}
			// Otherwise, the interval is the number of days from the beginning of the month (current month view)
			else {
				$fn[ 'date' ][ 'interval' ] = - intval( date_i18n( 'j' ) );
			}
		}
		else if ( empty( $fn[ 'date' ][ 'interval_hours' ] ) ) {
			// interval was set, but not interval_hours
			$fn[ 'date' ][ 'interval_hours' ] = 0;
		}
		else if ( empty( $fn[ 'date' ][ 'interval' ] ) ) {
			// interval_hours was set, but not interval
			$fn[ 'date' ][ 'interval' ] = 0;
		}

		$fn[ 'utime' ][ 'range' ] = $fn[ 'date' ][ 'interval' ] * 86400 + $fn[ 'date' ][ 'interval_hours' ] * 3600;

		// Day
		if ( empty( $fn[ 'date' ][ 'day' ] ) ) {
			$fn[ 'date' ][ 'day' ] = intval( date_i18n( 'j' ) );
		}

		// Month
		if ( empty( $fn[ 'date' ][ 'month' ] ) ) {
			$fn[ 'date' ][ 'month' ] = intval( date_i18n( 'n' ) );
		}

		// Year
		if ( empty( $fn[ 'date' ][ 'year' ] ) ) {
			$fn[ 'date' ][ 'year' ] = intval( date_i18n( 'Y' ) );
		}

		if ( $fn[ 'utime' ][ 'range' ] < 0 ) {
			$fn[ 'utime' ][ 'end' ] = mktime(
				!empty( $fn[ 'date' ][ 'hour' ] ) ? $fn[ 'date' ][ 'hour' ] : 23,
				59,
				59,
				$fn[ 'date' ][ 'month' ],
				$fn[ 'date' ][ 'day' ],
				$fn[ 'date' ][ 'year' ]
			);

			// If end is in the future and the level of granularity is hours, set it to now
			if ( !empty( $fn[ 'date' ][ 'interval_hours' ] ) && $fn[ 'utime' ][ 'end' ] > date_i18n( 'U' ) ) {
				$fn[ 'utime' ][ 'end' ] = intval( date_i18n( 'U' ) );
			}

			$fn[ 'utime' ][ 'range' ] = $fn[ 'utime' ][ 'range' ] + 1;
			$fn[ 'utime' ][ 'start' ] = $fn[ 'utime' ][ 'end' ] + $fn[ 'utime' ][ 'range' ];

			// Store the absolute value for later (chart)
			$fn[ 'utime' ][ 'range' ] = - $fn[ 'utime' ][ 'range' ];
		}
		else {
			$fn[ 'utime' ][ 'start' ] = mktime(
				!empty( $fn[ 'date' ][ 'hour' ] ) ? $fn[ 'date' ][ 'hour' ] : 0,
				0,
				0,
				$fn[ 'date' ][ 'month' ],
				$fn[ 'date' ][ 'day' ],
				$fn[ 'date' ][ 'year' ]
			);

			$fn[ 'utime' ][ 'range' ] = $fn[ 'utime' ][ 'range' ] - 1;
			$fn[ 'utime' ][ 'end' ] = $fn[ 'utime' ][ 'start' ] + $fn[ 'utime' ][ 'range' ];
		}

		// If end is in the future, set it to now
		if ( $fn[ 'utime' ][ 'end' ] > date_i18n( 'U' ) ) {
			$fn[ 'utime' ][ 'end' ] = intval( date_i18n( 'U' ) );
		}

		// Restore filters on date_i18n
		wp_slimstat::toggle_date_i18n_filters( true );

		// Apply third-party filters
		$fn = apply_filters( 'slimstat_db_filters_normalized', $fn, $_filters_raw );

		return $fn;
	}

	// The following methods retrieve the information from the database

	public static function count_bouncing_pages() {
		$where = self::get_combined_where( 'visit_id > 0 AND content_type <> "404"', 'resource' );

		return intval( self::get_var( "
			SELECT COUNT(*) counthits
				FROM (
					SELECT resource, visit_id
					FROM {$GLOBALS['wpdb']->prefix}slim_stats
					WHERE $where
					GROUP BY resource
					HAVING COUNT(visit_id) = 1
				) as ts1",
			'SUM(counthits) AS counthits' ) );
	}

	public static function count_exit_pages() {
		$where = self::get_combined_where( 'visit_id > 0', 'resource' );

		return intval( self::get_var( "
			SELECT COUNT(*) counthits
				FROM (
					SELECT resource, dt
					FROM {$GLOBALS['wpdb']->prefix}slim_stats
					WHERE $where
					GROUP BY resource
					HAVING dt = MAX(dt)
				) AS ts1",
			'SUM(counthits) AS counthits' ) );
	}

	public static function count_records( $_column = 'id', $_where = '', $_use_date_filters = true ) {
		$distinct_column = ( $_column != 'id' ) ? "DISTINCT $_column" : $_column;
		$_where = self::get_combined_where( $_where, $_column, $_use_date_filters );

		return intval( self::get_var( "
			SELECT COUNT($distinct_column) counthits
			FROM {$GLOBALS['wpdb']->prefix}slim_stats
			WHERE $_where",
			'SUM(counthits) AS counthits' ) );
	}

	public static function count_records_having( $_column = 'id', $_where = '', $_having = '' ) {
		$_where = self::get_combined_where( $_where, $_column );

		return intval( self::get_var( "
			SELECT COUNT(*) counthits FROM (
				SELECT $_column
				FROM {$GLOBALS['wpdb']->prefix}slim_stats
				WHERE $_where
				GROUP BY $_column
				HAVING $_having
			) AS ts1",
			'SUM(counthits) AS counthits' ) );
	}

	public static function get_data_for_chart( $_args = array() ) {
		// Determine the chart granularity based on the date range
		// - Up to 24 hours (86400 seconds): HOURLY
		// - Up to 120 days (10368000 seconds): DAILY
		// - Otherwise: MONTHLY
		$params = array();

		if ( self::$filters_normalized[ 'utime' ][ 'range' ] < 86400 ) {
			$params[ 'group_by' ] = "DAY(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), HOUR(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
			$params[ 'data_points_label' ] = ( self::$formats[ 'decimal' ] == '.' ) ? 'm/d - h a' : 'd/m - H';
			$params[ 'data_points_count' ] = ceil( self::$filters_normalized[ 'utime' ][ 'range' ] / 3600 );
			$params[ 'granularity' ] = 'HOUR';
		}
		else if ( self::$filters_normalized[ 'utime' ][ 'range' ] < 10368000 ) {
			$params[ 'group_by' ] = "MONTH(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), DAY(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
			$params[ 'data_points_label' ] = ( self::$formats[ 'decimal' ] == '.' ) ? 'm/d' : 'd/m';
			$params[ 'data_points_count' ] = ceil( self::$filters_normalized[ 'utime' ][ 'range' ] / 86400 );
			$params[ 'granularity' ] = 'DAY';
		}
		else {
			$params[ 'group_by' ] = "YEAR(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00')), MONTH(CONVERT_TZ(FROM_UNIXTIME(dt), @@session.time_zone, '+00:00'))";
			$params[ 'data_points_label' ] = 'm/y';
			$params[ 'data_points_count' ] = self::count_months_between( self::$filters_normalized[ 'utime' ][ 'start' ], self::$filters_normalized[ 'utime' ][ 'end' ] );
			$params[ 'granularity' ] = 'MONTH';
		}

		// Calculate the "previous/comparison" time range
		$params[ 'previous_end' ] = self::$filters_normalized[ 'utime' ][ 'start' ] - 1;
		$params[ 'previous_start' ] = $params[ 'previous_end' ] - self::$filters_normalized[ 'utime' ][ 'range' ];

		// Build the SQL query
		if ( empty( $_args[ 'where' ] ) ) {
			$_args[ 'where' ] = '';
		}

		$sql = "
			SELECT MIN(dt) AS dt, {$_args[ 'data1' ]} AS v1, {$_args[ 'data2' ]} AS v2
			FROM {$GLOBALS['wpdb']->prefix}slim_stats
			WHERE " . self::get_combined_where( $_args[ 'where' ], '*', false ) . " AND (dt BETWEEN {$params[ 'previous_start' ]} AND {$params[ 'previous_end' ]} OR dt BETWEEN " . self::$filters_normalized[ 'utime' ][ 'start' ] . ' AND ' . self::$filters_normalized[ 'utime' ][ 'end' ] . ")
			GROUP BY {$params[ 'group_by' ]}";

		// Get the data
		$results = self::get_results(
			$sql,
			'dt',
			'',
			$params[ 'group_by' ], 'SUM(v1) AS v1, SUM(v2) AS v2'
		);

		$output = array(
			'keys' => array()
		);

		// No data? No problem!
		if ( !is_array( $results ) || empty( $results ) ) {
			return $output;
		}

		// Generate the output array (sent to the chart library) by combining all the data collected so far
		
		// Let's start by initializing all the data points to zero
		for ( $i = 0; $i < $params[ 'data_points_count' ]; $i++ ) {
			$v1_label = date( $params[ 'data_points_label' ], strtotime( "+$i {$params[ 'granularity' ]}", self::$filters_normalized[ 'utime' ][ 'start' ] ) );
			$v3_label = date( $params[ 'data_points_label' ], strtotime( "+$i {$params[ 'granularity' ]}", $params[ 'previous_start' ] ) );

			$output[ 'keys' ][ $v1_label ] = $i;
			$output[ 'keys' ][ $v3_label ] = $i;

			// This is how AmCharts expects the data to be formatted
			$output[ $i ][ 'v1_label' ] = $v1_label;
			$output[ $i ][ 'v3_label' ] = $v3_label;
			$output[ $i ][ 'v4' ] = $output[ $i ][ 'v3' ] = $output[ $i ][ 'v2' ] = $output[ $i ][ 'v1' ] = 0;
		}

		

		// Now populate all the data points
		foreach ( $results as $a_result ) {
			$label = date( $params[ 'data_points_label' ], $a_result[ 'dt' ] );

			// Data out of range?
			if ( !isset( $output[ 'keys' ][ $label ] ) ) {
				continue;
			}

			// Does this value belong to the "current" range?
			if ( $a_result[ 'dt' ] >= self::$filters_normalized[ 'utime' ][ 'start' ] && $a_result[ 'dt' ] <= self::$filters_normalized[ 'utime' ][ 'end' ] ) {
				$output[ $output[ 'keys' ][ $label ] ][ 'v1' ] = intval( $a_result[ 'v1' ] );
				$output[ $output[ 'keys' ][ $label ] ][ 'v2' ] = intval( $a_result[ 'v2' ] );
			}
			else {
				$output[ $output[ 'keys' ][ $label ] ][ 'v3' ] = intval( $a_result[ 'v1' ] );
				$output[ $output[ 'keys' ][ $label ] ][ 'v4' ] = intval( $a_result[ 'v2' ] );
			}
		}

		return $output;
	}

	public static function get_data_size() {
		$suffix = 'KB';

		$sql = 'SHOW TABLE STATUS LIKE "'.$GLOBALS[ 'wpdb' ]->prefix.'slim_stats"';
		$table_details = wp_slimstat::$wpdb->get_row( $sql, 'ARRAY_A', 0 );

		$table_size = ( $table_details[ 'Data_length' ] / 1024 ) + ( $table_details[ 'Index_length' ] / 1024 );

		if ( $table_size > 1024 ) {
			$table_size /= 1024;
			$suffix = 'MB';
		}
		return number_format( $table_size, 2, self::$formats[ 'decimal' ], self::$formats[ 'thousand' ] ).' '.$suffix;
	}

	public static function get_max_and_average_pages_per_visit() {
		$where = self::get_combined_where( 'visit_id > 0' );

		return self::get_results( "
			SELECT AVG(ts1.counthits) AS avghits, MAX(ts1.counthits) AS maxhits FROM (
				SELECT count(ip) counthits, visit_id
				FROM {$GLOBALS['wpdb']->prefix}slim_stats
				WHERE $where
				GROUP BY visit_id
			) AS ts1",
			'blog_id',
			'',
			'',
			'AVG(avghits) AS avghits, MAX(maxhits) AS maxhits' );
	}

	public static function get_oldest_visit() {
		return self::get_var( "
			SELECT dt
			FROM {$GLOBALS['wpdb']->prefix}slim_stats
			ORDER BY dt ASC
			LIMIT 0, 1",
			'MIN(dt)' );
	}

	public static function get_recent( $_column = 'id', $_where = '', $_having = '', $_use_date_filters = true, $_as_column = '', $_more_columns = '' ) {
		// This function can be passed individual arguments, or an array of arguments
		if ( is_array( $_column ) ) {
			$_where = !empty( $_column[ 'where' ] ) ? $_column[ 'where' ] : '';
			$_having = !empty( $_column[ 'having' ] ) ? $_column[ 'having' ] : '';
			$_use_date_filters = !empty( $_column[ 'use_date_filters' ] ) ? $_column[ 'use_date_filters' ] : true;
			$_as_column = !empty( $_column[ 'as_column' ] ) ? $_column[ 'as_column' ] : '';
			$_more_columns = !empty( $_column[ 'more_columns' ] ) ? $_column[ 'more_columns' ] : '';
			$_column = $_column[ 'columns' ];
		}

		$columns = $_column;
		if ( !empty( $_as_column ) ) {
			$columns = "$_column AS $_as_column";
		}

		if ( $_column != '*' ) {
			$columns .= ', ip, dt';
		}
		else {
			$columns = 'id, ip, other_ip, username, country, city, location, referer, resource, searchterms, plugins, notes, visit_id, server_latency, page_performance, browser, browser_version, browser_type, platform, language, user_agent, resolution, screen_width, screen_height, content_type, category, author, content_id, outbound_resource, dt_out, dt';
		}

		if ( !empty( $_more_columns ) ) {
			$columns .= ', ' . $_more_columns;
		}

		$_where = self::get_combined_where( $_where, $_column, $_use_date_filters );

		$results = self::get_results( "
			SELECT $columns
			FROM {$GLOBALS['wpdb']->prefix}slim_stats
			WHERE $_where
			ORDER BY dt DESC
			LIMIT 0, " . self::$filters_normalized[ 'misc' ][ 'limit_results' ],
			$columns,
			'dt DESC' );

		if ( $_column != '*' ) {
			$column_values = array_map( 'unserialize', array_unique( array_map( 'serialize', self::array_column( $results, explode( ',', $_column ) ) ) ) );
			$results = array_intersect_key( $results, $column_values );
		}

		return $results;
	}

	public static function get_recent_events() {
		if ( empty( self::$filters_normalized[ 'columns' ] ) ) {
			$from = "{$GLOBALS['wpdb']->prefix}slim_events te";
			$where = wp_slimstat_db::get_combined_where( 'te.type > 1', 'notes' );
		}
		else {
			$from = "{$GLOBALS['wpdb']->prefix}slim_events te INNER JOIN {$GLOBALS['wpdb']->prefix}slim_stats t1 ON te.id = t1.id";
			$where = wp_slimstat_db::get_combined_where( 'te.type > 1', 'notes', true, 't1' );
		}

		return self::get_results( "
			SELECT *
			FROM $from
			WHERE $where
			ORDER BY te.dt DESC"
		);
	}

	public static function get_recent_outbound() {
		$mixed_outbound_resources = self::get_recent( 'outbound_resource' );
		$clean_outbound_resources = array();

		foreach ( $mixed_outbound_resources as $a_mixed_resource ) {
			$exploded_resources = explode( ';;;', $a_mixed_resource[ 'outbound_resource' ] );
			foreach ( $exploded_resources as $a_exploded_resource ) {
				$a_mixed_resource[ 'outbound_resource' ] = $a_exploded_resource;
				$clean_outbound_resources[] = $a_mixed_resource;
			}
		}

		return $clean_outbound_resources;
	}

	public static function get_top( $_column = 'id', $_where = '', $_having = '', $_use_date_filters = true, $_as_column = '' ){
		// This function can be passed individual arguments, or an array of arguments
		if ( is_array( $_column ) ) {
			$_where = !empty( $_column[ 'where' ] ) ? $_column[ 'where' ] : '';
			$_having = !empty( $_column[ 'having' ] ) ? $_column[ 'having' ] : '';
			$_use_date_filters = !empty( $_column[ 'use_date_filters' ] ) ? $_column[ 'use_date_filters' ] : true;
			$_as_column = !empty( $_column[ 'as_column' ] ) ? $_column[ 'as_column' ] : '';
			$_column = $_column[ 'columns' ];
		}

		if ( !empty( $_as_column ) ) {
			$_column = "$_column AS $_as_column";
		}
		else {
			$_as_column = $_column;
		}

		$_where = self::get_combined_where( $_where, $_as_column, $_use_date_filters );

		return self::get_results( "
			SELECT $_column, COUNT(*) counthits
			FROM {$GLOBALS['wpdb']->prefix}slim_stats
			WHERE $_where
			GROUP BY $_as_column $_having
			ORDER BY counthits DESC
			LIMIT 0, " . self::$filters_normalized[ 'misc' ][ 'limit_results' ],
			( ( !empty( $_as_column ) && $_as_column != $_column ) ? $_as_column : $_column ),
			'counthits DESC',
			( ( !empty( $_as_column ) && $_as_column != $_column ) ? $_as_column : $_column ),
			'SUM(counthits) AS counthits' );
	}

	public static function get_top_aggr( $_column = 'id', $_where = '', $_outer_select_column = '', $_aggr_function = 'MAX' ) {
		if ( is_array( $_column ) ) {
			$_where = !empty( $_column[ 'where' ] ) ? $_column[ 'where' ] : '';
			$_having = !empty( $_column[ 'having' ] ) ? $_column[ 'having' ] : '';
			$_use_date_filters = !empty( $_column[ 'use_date_filters' ] ) ? $_column[ 'use_date_filters' ] : true;
			$_as_column = !empty( $_column[ 'as_column' ] ) ? $_column[ 'as_column' ] : '';
			$_outer_select_column = !empty( $_column[ 'outer_select_column' ] ) ? $_column[ 'outer_select_column' ] : '';
			$_aggr_function = !empty( $_column[ 'aggr_function' ] ) ? $_column[ 'aggr_function' ] : '';
			$_column = $_column[ 'columns' ];
		}

		if ( !empty( $_as_column ) ) {
			$_column = "$_column AS $_as_column";
		}
		else {
			$_as_column = $_column;
		}

		$_where = self::get_combined_where( $_where, $_column );

		return self::get_results( "
			SELECT $_outer_select_column, ts1.aggrid as $_column, COUNT(*) counthits
			FROM (
				SELECT $_column, $_aggr_function(id) aggrid
				FROM {$GLOBALS['wpdb']->prefix}slim_stats
				WHERE $_where
				GROUP BY $_column
			) AS ts1 JOIN {$GLOBALS['wpdb']->prefix}slim_stats t1 ON ts1.aggrid = t1.id
			GROUP BY $_outer_select_column
			ORDER BY counthits DESC
			LIMIT 0, " . self::$filters_normalized[ 'misc' ][ 'limit_results' ],
			$_outer_select_column,
			'counthits DESC',
			$_outer_select_column,
			"$_aggr_function(aggrid), SUM(counthits)" );
	}

	public static function get_top_events() {
		if ( empty( self::$filters_normalized[ 'columns' ] ) ) {
			$from = "{$GLOBALS['wpdb']->prefix}slim_events te";
			$where = wp_slimstat_db::get_combined_where( 'te.type > 1', 'notes' );
		}
		else {
			$from = "{$GLOBALS['wpdb']->prefix}slim_events te INNER JOIN {$GLOBALS['wpdb']->prefix}slim_stats t1 ON te.id = t1.id";
			$where = wp_slimstat_db::get_combined_where( 'te.type > 1', 'notes', true, 't1' );
		}

		return self::get_results( "
			SELECT te.notes, te.type, COUNT(*) counthits
			FROM $from
			WHERE $where
			GROUP BY te.notes, te.type
			ORDER BY counthits DESC"
		);
	}

	public static function get_top_outbound() {
		$mixed_outbound_resources = self::get_recent( 'outbound_resource' );
		$clean_outbound_resources = array();

		foreach ( $mixed_outbound_resources as $a_mixed_resource ) {
			$exploded_resources = explode( ';;;', $a_mixed_resource[ 'outbound_resource' ] );
			foreach ( $exploded_resources as $a_exploded_resource ) {
				$clean_outbound_resources[] = $a_exploded_resource;
			}
		}

		$clean_outbound_resources = array_count_values( $clean_outbound_resources );
		arsort( $clean_outbound_resources );

		$sorted_outbound_resources = array();
		foreach ( $clean_outbound_resources as $a_resource => $a_count ) {
			$sorted_outbound_resources[] = array(
				'outbound_resource' => $a_resource,
				'counthits' => $a_count
			);
		}

		return $sorted_outbound_resources;
	}

	protected static function array_column( $input = array(), $columns = array() ) {
		$output = array();

		foreach ( $input as $a_key => $a_row ) {
			foreach ( $columns as $a_column ) {
				$a_column = trim( $a_column );
				if ( $a_row[ $a_column ] != NULL ) {
					$output[ $a_key ][ $a_column ] = $a_row[ $a_column ];
				}
			}
		}

		return $output;
	}

	protected static function count_months_between( $min_timestamp = 0, $max_timestamp = 0 ) {
		$i = 0;
		$min_month = date( 'Ym', $min_timestamp );
		$max_month = date( 'Ym', $max_timestamp );

		while ( $min_month <= $max_month ) {
			$min_timestamp = strtotime( "+1 month", $min_timestamp );
			$min_month = date( 'Ym', $min_timestamp );
			$i++;
		}

		return $i;
	}
}
