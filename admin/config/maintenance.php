<?php
// Avoid direct access to this piece of code
if ( !function_exists( 'add_action' ) || ( !empty( $_POST ) && !check_admin_referer( 'maintenance_wp_slimstat', 'maintenance_wp_slimstat_nonce' ) ) ) {
	exit( 0 );
}

require_once( dirname( dirname( __FILE__ ) ) . '/view/wp-slimstat-reports.php' );
wp_slimstat_reports::init();

if ( !empty( $_REQUEST[ 'action' ] ) ) {
	switch ( $_REQUEST[ 'action' ] ) {
		case 'activate-indexes':
			wp_slimstat::$wpdb->query( "ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ADD INDEX {$GLOBALS['wpdb']->prefix}stats_resource_idx( resource( 20 ) )" );
			wp_slimstat::$wpdb->query( "ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ADD INDEX {$GLOBALS['wpdb']->prefix}stats_browser_idx( browser( 10 ) )" );
			wp_slimstat::$wpdb->query( "ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ADD INDEX {$GLOBALS['wpdb']->prefix}stats_searchterms_idx( searchterms( 15 ) )" );
			wp_slimstat_admin::show_alert_message( __( 'Congratulations! Slimstat Analytics is now optimized for <a href="http://www.youtube.com/watch?v=ygE01sOhzz0" target="_blank">ludicrous speed</a>.', 'wp-slimstat' ) );
			break;

		case 'activate-sql-debug-mode':
			wp_slimstat::$settings[ 'show_sql_debug' ] = 'on';
			break;

		case 'deactivate-indexes':
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats DROP INDEX {$GLOBALS['wpdb']->prefix}stats_resource_idx");
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats DROP INDEX {$GLOBALS['wpdb']->prefix}stats_browser_idx");
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats DROP INDEX {$GLOBALS['wpdb']->prefix}stats_searchterms_idx");
			wp_slimstat_admin::show_alert_message( __( 'Indexing has been disabled. Enjoy the extra database space!', 'wp-slimstat' ) );
			break;

		case 'deactivate-sql-debug-mode':
			wp_slimstat::$settings[ 'show_sql_debug' ] = 'no';
			break;

		case 'delete-records':
			$rows_affected = 0;

			if (key_exists($_REQUEST['f'], wp_slimstat_db::$columns_names)){
				$rows_affected = wp_slimstat::$wpdb->query("
					DELETE t1.* 
					FROM {$GLOBALS['wpdb']->prefix}slim_stats t1
					WHERE ".wp_slimstat_db::get_combined_where('', '*', false));
			}
			wp_slimstat_admin::show_alert_message( intval( $rows_affected ) . ' ' . __( 'records deleted from your database.', 'wp-slimstat' ) );
			break;

		case 'delete-maxmind':
			$is_deleted = @unlink( wp_slimstat::$maxmind_path );
			
			if ( $is_deleted ) {
				wp_slimstat_admin::show_alert_message( __( 'The geolocation database has been uninstalled from your server.', 'wp-slimstat' ) );
			}
			else {
				// Some users have reported that a directory is created, instead of a file
				$is_deleted = @rmdir( wp_slimstat::$maxmind_path );

				if ( $is_deleted ) {
					wp_slimstat_admin::show_alert_message( __( 'The geolocation database has been uninstalled from your server.', 'wp-slimstat' ) );
				}
				else {
					wp_slimstat_admin::show_alert_message( __( "The geolocation database could not be removed from your server. Please check your folder's permissions and try again.", 'wp-slimstat' ) );	
				}
			}
			break;

		case 'download-maxmind':
			$error = wp_slimstat::download_maxmind_database();

			if (!empty($error)){
				wp_slimstat_admin::show_alert_message( $error, 'wp-ui-notification' );
			}
			else {
				wp_slimstat_admin::show_alert_message( __( 'The geolocation database has been installed on your server.', 'wp-slimstat') );
			}
			break;

		case 'delete-browscap':
			// Delete the existing folder, if there
			WP_Filesystem();
			if ( $GLOBALS[ 'wp_filesystem' ]->rmdir( wp_slimstat::$upload_dir . '/browscap-db/', true ) ) {
				wp_slimstat_admin::show_alert_message( __( 'The Browscap data file has been uninstalled from your server.', 'wp-slimstat' ) );
			}
			else {
				wp_slimstat_admin::show_alert_message( __( 'There was an error deleting the Browscap data folder on your server. Please check your permissions.', 'wp-slimstat' ) );
			}
			break;

		case 'download-browscap':
			$error = slim_browser::update_browscap_database( true );

			if ( is_array( $error ) ) {
				wp_slimstat_admin::show_alert_message( $error[ 1 ], ( empty( $error[ 0 ] ) ? 'wp-ui-highlight': 'wp-ui-notification' ) );
			}
			break;

		case 'import-settings':
			$new_settings = @json_decode( stripslashes( $_POST[ 'import-slimstat-settings' ] ), true );

			if ( is_array( $new_settings ) && !empty( $new_settings ) ) {
				foreach ( $new_settings as $a_setting_name => $a_setting_value ) {
					wp_slimstat::$settings[ $a_setting_name ] = $a_setting_value;
				}
				wp_slimstat_admin::show_alert_message( __( 'Your new Slimstat settings have been imported and installed.', 'wp-slimstat' ) );
			}
			else {
				wp_slimstat_admin::show_alert_message( __( 'There was an error decoding your settings string. Please verify that it is a valid serialized string.', 'wp-slimstat' ) );
			}
			break;

		case 'reset-tracker-error-status':
			wp_slimstat::$settings[ 'last_tracker_error' ] = array();
			break;

		case 'reset-tracker-notice-status':
			wp_slimstat::$settings[ 'last_tracker_notice' ] = array();
			break;

		case 'switch-engine':
			$have_innodb = wp_slimstat::$wpdb->get_results("SHOW VARIABLES LIKE 'have_innodb'", ARRAY_A);
			if ($have_innodb[0]['Value'] != 'YES') return;

			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ENGINE = InnoDB");
			wp_slimstat::$wpdb->query("ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_events ENGINE = InnoDB");

			wp_slimstat_admin::show_alert_message( __( 'Your Slimstat tables have been successfully converted to InnoDB.', 'wp-slimstat' ) );
			break;

		case 'truncate-archive':
			wp_slimstat::$wpdb->query( "DELETE tsa FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_stats_archive tsa" );
			wp_slimstat::$wpdb->query( "OPTIMIZE TABLE {$GLOBALS[ 'wpdb' ]->prefix}slim_stats_archive" );
			wp_slimstat_admin::show_alert_message( __( 'All the archived records were successfully deleted.', 'wp-slimstat' ) );
			break;

		case 'truncate-table':
			wp_slimstat::$wpdb->query( "DELETE te FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_events te" );
			wp_slimstat::$wpdb->query( "OPTIMIZE TABLE {$GLOBALS[ 'wpdb' ]->prefix}slim_events" );
			wp_slimstat::$wpdb->query( "DELETE t1 FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_stats t1" );
			wp_slimstat::$wpdb->query( "OPTIMIZE TABLE {$GLOBALS[ 'wpdb' ]->prefix}slim_stats" );
			wp_slimstat_admin::show_alert_message( __( 'All the records were successfully deleted.', 'wp-slimstat' ) );
			break;

		default:
			break;
	}
}

// Retrieve some information about the tables used by Slimstat
$check_index = wp_slimstat::$wpdb->get_results( "SHOW INDEX FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_stats WHERE Key_name = '{$GLOBALS[ 'wpdb' ]->prefix}stats_resource_idx'" );
$details_wp_slim_tables = array_merge(
	wp_slimstat::$wpdb->get_results( "SHOW TABLE STATUS LIKE '{$GLOBALS[ 'wpdb' ]->prefix}slim_stats'", ARRAY_A ),
	wp_slimstat::$wpdb->get_results( "SHOW TABLE STATUS LIKE '{$GLOBALS[ 'wpdb' ]->prefix}slim_events'", ARRAY_A ),
	wp_slimstat::$wpdb->get_results( "SHOW TABLE STATUS LIKE '{$GLOBALS[ 'wpdb' ]->prefix}slim_stats_archive'", ARRAY_A ),
	wp_slimstat::$wpdb->get_results( "SHOW TABLE STATUS LIKE '{$GLOBALS[ 'wpdb' ]->prefix}slim_events_archive'", ARRAY_A )
);
$have_innodb = wp_slimstat::$wpdb->get_results("SHOW VARIABLES LIKE 'have_innodb'", ARRAY_A);
$suffixes = array('bytes', 'KB', 'MB', 'GB', 'TB');
$slim_stats_4_exists = wp_slimstat::$wpdb->get_col( "SHOW TABLES LIKE '{$GLOBALS[ 'wpdb' ]->prefix}slim_stats_4'", 0 );
$slim_stats_3_exists = wp_slimstat::$wpdb->get_col( "SHOW TABLES LIKE '{$GLOBALS[ 'wpdb' ]->prefix}slim_stats_3'", 0 );
$slim_browsers_exists =wp_slimstat::$wpdb->get_col( "SHOW TABLES LIKE '{$GLOBALS[ 'wpdb' ]->prefix}slim_browsers'", 0 );
?>

<table class="form-table widefat">
<tbody>
	<tr>
		<td colspan="2" class="slimstat-options-section-header" id="wp-slimstat-troubleshooting"><?php _e('Troubleshooting','wp-slimstat') ?></td>
	</tr>
	<tr>
		<th scope="row"><?php _e( 'Tracker Error', 'wp-slimstat' ) ?></th>
		<td>
			<?php echo ( !empty( wp_slimstat::$settings[ 'last_tracker_error' ][ 1 ] ) && !empty( wp_slimstat::$settings[ 'last_tracker_error' ][ 2 ] ) ) ? '<strong>[' . date_i18n( wp_slimstat::$settings[ 'date_format' ], wp_slimstat::$settings[ 'last_tracker_error' ][ 2 ], true ) . ' ' . date_i18n( wp_slimstat::$settings[ 'time_format' ], wp_slimstat::$settings[ 'last_tracker_error' ][ 2 ], true ) . '] ' . wp_slimstat::$settings[ 'last_tracker_error' ][ 0 ] . ' ' . wp_slimstat::$settings[ 'last_tracker_error' ][ 1 ] . '</strong><a class="slimstat-font-cancel" title="' . htmlentities( __( 'Reset this error', 'wp-slimstat' ), ENT_QUOTES, 'UTF-8' ) . '" href="' . wp_slimstat_admin::$config_url.$current_tab . '&amp;action=reset-tracker-error-status"></a>' : __( 'So far so good.', 'wp-slimstat' ); ?>
			<span class="description"><?php _e( 'The information here above is useful to troubleshoot issues with the tracker. <strong>Errors</strong> are returned when the tracker could not record a page view for some reason, and are indicative of some kind of malfunction. Please include the message here above when sending a <a href="http://support.wp-slimstat.com" target="_blank">support request</a>.', 'wp-slimstat' ) ?></span>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php _e( 'Tracker Notice', 'wp-slimstat' ) ?></th>
		<td>
			<?php echo ( !empty( wp_slimstat::$settings[ 'last_tracker_notice' ][ 1 ] ) && !empty( wp_slimstat::$settings[ 'last_tracker_notice' ][ 2 ] ) ) ? '<strong>[' . date_i18n( wp_slimstat::$settings[ 'date_format' ], wp_slimstat::$settings[ 'last_tracker_notice' ][ 2 ], true ) . ' ' . date_i18n( wp_slimstat::$settings[ 'time_format' ], wp_slimstat::$settings[ 'last_tracker_notice' ][ 2 ], true ) . '] ' . wp_slimstat::$settings[ 'last_tracker_notice' ][ 0 ] . ' ' . wp_slimstat::$settings[ 'last_tracker_notice' ][ 1 ] . '</strong><a class="slimstat-font-cancel" title="' . htmlentities( __( 'Reset this notice', 'wp-slimstat' ), ENT_QUOTES, 'UTF-8' ) . '" href="' . wp_slimstat_admin::$config_url.$current_tab . '&amp;action=reset-tracker-notice-status"></a>' : __( 'So far so good.', 'wp-slimstat' ); ?>
			<span class="description"><?php _e( 'The message here above will indicate if a page view was not recorded because it matched at least one of the conditions you configured in your settings (filters, blackslists, etc).', 'wp-slimstat' ) ?></span>
		</td>
	</tr>
	<tr>
		<?php if ( wp_slimstat::$settings[ 'show_sql_debug' ] != 'on' ): ?>
		<th scope="row">
			<a class="button-secondary" href="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>&amp;action=activate-sql-debug-mode"><?php _e("Enable SQL Debug",'wp-slimstat'); ?></a>
		</th>
		<td>
			<span class="description"><?php _e("Display the SQL code used to retrieve the data from the database. Useful to troubleshoot issues with data consistency or missing pageviews.",'wp-slimstat') ?></span>
		</td>
		<?php else: ?>
		<th scope="row">
			<a class="button-secondary" href="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>&amp;action=deactivate-sql-debug-mode"><?php _e('Disable SQL Debug','wp-slimstat'); ?></a>
		</th>
		<td>
			<span class="description"><?php _e("Deactivate the SQL output on top of each report.",'wp-slimstat') ?></span>
		</td>
		<?php endif ?>
	</tr>
	<tr>
		<td colspan="2" class="slimstat-options-section-header" id="wp-slimstat-data-maintenance"><?php _e('Data Maintenance','wp-slimstat') ?></td>
	</tr>
	<tr>
		<th scope="row" style="padding-top: 20px"><?php _e('Delete pageviews where','wp-slimstat') ?></th>
		<td>
			<form action="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>" method="post">
				<?php wp_nonce_field( 'maintenance_wp_slimstat', 'maintenance_wp_slimstat_nonce', true, true ); ?>
				<input type="hidden" name="action" value="delete-records" />
				
				<select name="f" id="slimstat-filter-name">
					<?php 
						foreach (wp_slimstat_db::$columns_names as $a_filter_id => $a_filter_info){
							echo "<option value='$a_filter_id'>{$a_filter_info[0]}</option>";
						}
					?>
				</select> 
				<select name="o" id="slimstat-filter-operator">
					<option value="equals"><?php _e('equals','wp-slimstat') ?></option>
					<option value="is_not_equal_to"><?php _e('is not equal to','wp-slimstat') ?></option>
					<option value="contains"><?php _e('contains','wp-slimstat') ?></option>
					<option value="includes_in_set"><?php _e('is included in','wp-slimstat') ?></option>
					<option value="does_not_contain"><?php _e('does not contain','wp-slimstat') ?></option>
					<option value="starts_with"><?php _e('starts with','wp-slimstat') ?></option>
					<option value="ends_with"><?php _e('ends with','wp-slimstat') ?></option>
					<option value="sounds_like"><?php _e('sounds like','wp-slimstat') ?></option>
					<option value="is_greater_than"><?php _e('is greater than','wp-slimstat') ?></option>
					<option value="is_less_than"><?php _e('is less than','wp-slimstat') ?></option>
					<option value="matches"><?php _e('matches','wp-slimstat') ?></option>
					<option value="does_not_match"><?php _e('does not match','wp-slimstat') ?></option>
					<option value="is_empty"><?php _e('is empty','wp-slimstat') ?></option>
					<option value="is_not_empty"><?php _e('is not empty','wp-slimstat') ?></option>
				</select>
				<input type="text" name="v" id="slimstat-filter-value" value="" size="20">
				<input type="submit" value="<?php _e('Apply','wp-slimstat') ?>" class="button-secondary" name="Submit"
					onclick="return(confirm('<?php _e('Are you sure you want to PERMANENTLY delete these records from your database?','wp-slimstat'); ?>'))" />
			</form>
		</td>
	</tr>
	<tr class="alternate">
		<th scope="row">
			<a class="button-secondary" href="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>&amp;action=truncate-table"
				onclick="return(confirm('<?php _e('Are you sure you want to PERMANENTLY DELETE ALL the records from your database?','wp-slimstat'); ?>'))"><?php _e('Delete All Records','wp-slimstat'); ?></a>
		</th>
		<td>
			<span class="description"><?php _e('Erase all the information collected so far by Slimstat, but not the archived records (<code>wp_slim_stats_archive</code>). This operation <strong>does not</strong> reset your settings and it can be undone by manually copying your records from the archive table.','wp-slimstat') ?></span>
		</td>
	</tr>
	<tr >
		<th scope="row">
			<a class="button-secondary" href="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>&amp;action=truncate-archive"
				onclick="return(confirm('<?php _e('Are you sure you want to PERMANENTLY DELETE ALL the records from your archive?','wp-slimstat'); ?>'))"><?php _e('Delete Archive','wp-slimstat'); ?></a>
		</th>
		<td>
			<span class="description"><?php _e("Erase all the archived records. This operation cannot be undone.",'wp-slimstat') ?></span>
		</td>
	</tr>
	<tr class="alternate">
		<?php if (empty($check_index)): ?>
		<th scope="row">
			<a class="button-secondary" href="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>&amp;action=activate-indexes"><?php _e("Improve Performance",'wp-slimstat'); ?></a>
		</th>
		<td>
			
			<span class="description"><?php _e("Please note that you will need about 30% more DB space to store the extra information required.",'wp-slimstat') ?></span>
		</td>
		<?php else: ?>
		<th scope="row">
			<a class="button-secondary" href="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>&amp;action=deactivate-indexes"><?php _e('Save DB Space','wp-slimstat'); ?></a>
		</th>
		<td>
			<span class="description"><?php _e("Please note that by removing table indexes, Slimstat's performance will be affected.",'wp-slimstat') ?></span>
		</td>
		<?php endif ?>
	</tr>
	<tr>
		<td colspan="2" class="slimstat-options-section-header" id="wp-slimstat-external-data-files"><?php _e('External Data Files','wp-slimstat') ?></td>
	</tr>
	<tr>
		<th scope="row">
			<?php if (!file_exists(wp_slimstat::$maxmind_path)): ?>
			<a class="button-secondary" href="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>&amp;action=download-maxmind"
				onclick="return(confirm('<?php _e('Do you want to download and install the geolocation database from MaxMind\'s server?','wp-slimstat'); ?>'))"><?php _e("Install GeoLite DB",'wp-slimstat'); ?></a>
			<?php else: ?>
			<a class="button-secondary" href="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>&amp;action=delete-maxmind"
				onclick="return(confirm('<?php _e('Do you want to uninstall the geolocation database?','wp-slimstat'); ?>'))"><?php _e("Uninstall GeoLite DB",'wp-slimstat'); ?></a>
			<?php endif; ?>
		</th>
		<td>
			<span class="description"><?php _e("The <a href='https://dev.maxmind.com/geoip/geoip2/geolite2/' target='_blank'>MaxMind GeoLite2 library</a>, which Slimstat uses to geolocate visitors, is released under the Creative Commons BY-SA 3.0 license, and cannot be directly bundled with the plugin because of license incompatibility issues. We are mandated to have the user take an affirmative action in order to enable this functionality. If you're experiencing issues, please <a href='https://slimstat.freshdesk.com/solution/articles/12000039798-how-to-manually-install-the-maxmind-geolocation-data-file-' target='_blank'>take a look at our knowledge base</a> to learn how to install this file manually.", 'wp-slimstat' ) ?></span>
		</td>
	</tr>
	<tr class="alternate">
		<th scope="row">
			<?php if ( !file_exists( slim_browser::$browscap_autoload_path ) ) : ?>
			<a class="button-secondary" href="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>&amp;action=download-browscap"
				onclick="return( confirm( '<?php _e( 'Do you want to download and install the Browscap data file from our server?', 'wp-slimstat' ); ?>' ) )"><?php _e( 'Install Browscap', 'wp-slimstat' ); ?></a>
			<?php else: ?>
			<a class="button-secondary" href="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>&amp;action=delete-browscap"
				onclick="return( confirm( '<?php _e( 'Do you want to uninstall the Browscap data file?', 'wp-slimstat' ); ?>' ) )"><?php _e( 'Uninstall Browscap', 'wp-slimstat' ); ?></a>
			<?php endif; ?>
		</th>
		<td>
			<span class="description"><?php _e( "We are contributing to the <a href='http://browscap.org/' target='_blank'>Browscap Capabilities Project</a>, which we use to decode your visitors' user agent string into browser name and operating system. We use an optimized version of their data structure, for improved performance. After you enable this feature, Slimstat will use this data file instead of the built-in heuristic function, to accurately determine your visitors' browser information. It will also automatically check for updates and download the latest version for you. Please feel free to <a href='http://s3.amazonaws.com/browscap/terms-conditions.html' target='_blank'>review our terms and conditions</a>, and do not hesitate to <a href='http://support.wp-slimstat.com' target='_blank'>contact our support team</a> if you have any questions.", 'wp-slimstat' ) ?></span>
		</td>
	</tr>
	<tr>
		<td colspan="2" class="slimstat-options-section-header" id="wp-slimstat-configuration-string"><?php _e('Configuration String','wp-slimstat') ?></td>
	</tr>
	<tr>
		<td colspan="2">
			<strong><?php _e("Here below you can find the current configuration string for Slimstat. You can update your settings by pasting a new string inside the text area and clicking the Import button.",'wp-slimstat') ?></strong>
			<form action="<?php echo wp_slimstat_admin::$config_url.$current_tab ?>" method="post">
				<?php wp_nonce_field( 'maintenance_wp_slimstat', 'maintenance_wp_slimstat_nonce', true, true ) ?>
				<input type="hidden" name="action" value="import-settings" />
				<textarea name="import-slimstat-settings" style="width:100%" rows="10"><?php echo json_encode( wp_slimstat::$settings ) ?></textarea><br/>
				<input type="submit" value="<?php _e('Import','wp-slimstat') ?>" class="button-secondary"
					onclick="return(confirm('<?php _e('Are you sure you want to OVERWRITE your current settings?','wp-slimstat'); ?>'))">
			</form>
		</td>
	</tr>
	<tr>
		<td colspan="2" class="slimstat-options-section-header" id="wp-slimstat-database-information"><?php _e('Database Information','wp-slimstat') ?></td>
	</tr>
	<tr>
		<th scope="row"><?php _e('Engine','wp-slimstat') ?></th>
		<td><?php 
			echo $details_wp_slim_tables[0]['Engine']; 
			if (!empty($have_innodb) && $have_innodb[0]['Value'] == 'YES' && $details_wp_slim_tables[0]['Engine'] == 'MyISAM'){
				echo ' [<a class="noslimstat" href="'.wp_slimstat_admin::$config_url.$current_tab.'&amp;action=switch-engine">'.__('switch to InnoDB','wp-slimstat').'</a>]';
			}
		?></td>
	</tr>
	<?php
		foreach ($details_wp_slim_tables as $i => $a_table){
			$base = ($a_table['Data_length'] != 0)?(log($a_table['Data_length']) / log(1024)):0;
			$a_table['Data_length_with_suffix'] = round(pow(1024, $base - floor($base)), 2).' '.$suffixes[floor($base)];
			
			echo '<tr '.(($i%2==0)?'class="alternate"':'').">
					<th scope='row'>{$a_table['Name']}</th>
					<td>".$a_table['Data_length_with_suffix'].' ('.number_format($a_table['Rows'], 0).' '.__('records','wp-slimstat').')</td>
				  </tr>';
		}
		$i++;
		if ( !empty( $slim_stats_4_exists ) || !empty( $slim_stats_3_exists ) || !empty( $slim_browsers_exists ) ):
	?>
	<tr<?php echo (($i%2==0)?' class="alternate"':'') ?>>
		<th scope="row"><?php _e( 'Old Tables', 'wp-slimstat' ) ?></th>
		<td><?php printf( __( 'It looks like your database was upgraded from a version prior to 4.0. Our upgrade procedure follows a conservative approach, and does not automatically perform any garbage collection. In other words, the old tables, leftovers of the upgrade, are not deleted from the database. This allows our users to easily roll back to a working state in case of problems. However, if everything is working as expected (tracker and reports), you may want to log into phpMyAdmin and remove the following tables, if they exist: %s. When in doubt, do not hesitate to contact us for help.', 'wp-slimstat' ), "<code>{$GLOBALS['wpdb']->prefix}slim_browsers</code>, <code>{$GLOBALS['wpdb']->prefix}slim_content_info</code>, <code>{$GLOBALS['wpdb']->prefix}slim_outbound</code>, <code>{$GLOBALS['wpdb']->prefix}slim_screenres</code>, <code>{$GLOBALS['wpdb']->prefix}slim_stats_3</code>, <code>{$GLOBALS['wpdb']->prefix}slim_stats_4</code>, <code>{$GLOBALS['wpdb']->prefix}slim_stats_archive_3</code>, <code>{$GLOBALS['wpdb']->prefix}slim_stats_archive_4</code>" ) ?></td>
	</tr>
	<?php endif; ?>
</tbody>
</table>