<?php
	include_once( dirname(__FILE__) . '/wp-slimstat-reports.php' );
	wp_slimstat_reports::init();

	// Get default report placements
	$report_locations = array(
		'inactive' => array(),
		'dashboard' => array(),
		'slimview1' => array(),
		'slimview2' => array(),
		'slimview3' => array(),
		'slimview4' => array(),
		'slimview5' => array()
	);

	$is_report_reset = false;
	if ( !empty( $_GET[ 'action' ] ) && $_GET[ 'action' ] == 'restore-views' ) {
		$GLOBALS[ 'wpdb' ]->query( "DELETE FROM {$GLOBALS['wpdb']->prefix}usermeta WHERE meta_key LIKE '%meta-box-order_admin_page_slimlayout%'" );
		$GLOBALS[ 'wpdb' ]->query( "DELETE FROM {$GLOBALS['wpdb']->prefix}usermeta WHERE meta_key LIKE '%mmetaboxhidden_admin_page_slimview%'" );
		$GLOBALS[ 'wpdb' ]->query( "DELETE FROM {$GLOBALS['wpdb']->prefix}usermeta WHERE meta_key LIKE '%meta-box-order_slimstat%'" );
		$GLOBALS[ 'wpdb' ]->query( "DELETE FROM {$GLOBALS['wpdb']->prefix}usermeta WHERE meta_key LIKE '%metaboxhidden_slimstat%'" );
		$GLOBALS[ 'wpdb' ]->query( "DELETE FROM {$GLOBALS['wpdb']->prefix}usermeta WHERE meta_key LIKE '%closedpostboxes_slimstat%'" );
		$is_report_reset = true;
	}

	if ( empty( wp_slimstat_reports::$user_reports ) || $is_report_reset ) {
		foreach ( wp_slimstat_reports::$reports_info as $a_report_id => $a_report_info ) {
			if ( !empty( $a_report_info[ 'screens' ] ) ) {
				foreach ( $a_report_info[ 'screens' ] as $a_report_screen ) {
					if ( isset( $report_locations[ $a_report_screen ] ) ) {
						$report_locations[ $a_report_screen ][] = $a_report_id;
					}
				}
			}
		}
	}
	else {
		foreach ( $report_locations as $a_location_id => $a_location_list ) {
			if ( !empty( wp_slimstat_reports::$user_reports[ $a_location_id ] ) ) {
				$report_locations[ $a_location_id ] = explode( ',', wp_slimstat_reports::$user_reports[ $a_location_id ] );
			}
			else {
				$report_locations[ $a_location_id ] = array();
			}
		}
	}

	// Keep track of multiple occurrences of the same report, to allow users to delete duplicates
	$already_seen = array();

	$current_user = wp_get_current_user();
	$page_location = ( wp_slimstat::$settings[ 'use_separate_menu' ] == 'on' ) ? 'slimstat' : 'admin';
?>

<div class="wrap slimstat-layout">
<h2><?php _e( 'Customize and organize your reports','wp-slimstat' ) ?></h2>
<p><?php 
	_e( 'Drag and drop report placeholders from one container to another, to customize the information you want to see right away when you open Slimstat. Place two or more charts on the same view, clone reports or move them to the Inactive Reports container for improved performance. It is your website, and you know how metrics should be combined to get a clear picture of the traffic it generates.', 'wp-slimstat' );
	echo ' ';
	if ( is_network_admin() ) {
		_e( 'By using the network-wide customizer, all your users will be seeing the same layout and will not be able to further customize it.', 'wp-slimstat' );
		echo ' ';
	}
	_e( '<strong>Note</strong>: if a placeholder is greyed out, it means that the corresponding report is currently hidden (Screen Options tab).', 'wp-slimstat');
?></p>

<form method="get" action=""><input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo wp_create_nonce('meta-box-order') ?>" /></form>

<a href="admin.php?page=slimlayout&&amp;action=restore-views" class="button"><?php _e( 'Reset All', 'wp-slimstat' ) ?></a>

<?php foreach ( $report_locations as $a_location_id => $a_location_list ): $hidden_reports = get_user_option( "metaboxhidden_{$page_location}_page_{$a_location_id}", $current_user->ID ); if ( !is_array( $hidden_reports ) ) $hidden_reports = array(); ?>
<div id="postbox-container-<?php echo $a_location_id ?>" class="postbox-container">
<h2 class="slimstat-options-section-header"><?php echo wp_slimstat_admin::$screens_info[ $a_location_id ][ 'title' ] ?></h2>
<div id="<?php echo $a_location_id ?>-sortables" class="meta-box-sortables"><?php
	if ( $a_location_id != 'inactive' ) {
		$move_to_inactive = ' <a class="slimstat-font-minus-circled" href="#" title="' . __( 'Move to Inactive', 'wp-slimstat' ) . '"></a>';
	}
	else {
		$move_to_inactive = '';
	}

	foreach( $a_location_list as $a_report_id ) {
		if ( empty( wp_slimstat_reports::$reports_info[ $a_report_id ] ) ) {
			continue;
		}

		if ( !in_array( $a_report_id, $already_seen ) ) {
			$already_seen[] = $a_report_id;
			$icon = 'docs';
			$title = __( 'Clone', 'wp-slimstat' );
		}
		else{
			$icon = 'trash';
			$title = __( 'Delete', 'wp-slimstat' );
		}

		$placeholder_classes = '';
		if ( ( in_array( 'hidden', wp_slimstat_reports::$reports_info[ $a_report_id ][ 'classes' ] ) && empty( $hidden_reports ) ) || in_array( $a_report_id, $hidden_reports ) ) {
			$placeholder_classes = ' invisible';
		}

		echo "
			<div class='postbox$placeholder_classes' id='$a_report_id'>
				<div class='slimstat-header-buttons'>
					<a class='slimstat-font-$icon' href='#' title='$title'></a>
					$move_to_inactive
				</div>
				<h3 class='hndle'>" . wp_slimstat_reports::$reports_info[ $a_report_id ][ 'title' ] . "</h3>
			</div>";
	} ?>
</div>
</div>
<?php endforeach; ?>