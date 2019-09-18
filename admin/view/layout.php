<?php
	if ( !empty( $_GET[ 'action' ] ) && $_GET[ 'action' ] == 'restore-views' ) {
		$GLOBALS[ 'wpdb' ]->query( "DELETE FROM {$GLOBALS['wpdb']->prefix}usermeta WHERE meta_key LIKE '%meta-box-order_admin_page_slimlayout%'" );
		$GLOBALS[ 'wpdb' ]->query( "DELETE FROM {$GLOBALS['wpdb']->prefix}usermeta WHERE meta_key LIKE '%mmetaboxhidden_admin_page_slimview%'" );
		$GLOBALS[ 'wpdb' ]->query( "DELETE FROM {$GLOBALS['wpdb']->prefix}usermeta WHERE meta_key LIKE '%meta-box-order_slimstat%'" );
		$GLOBALS[ 'wpdb' ]->query( "DELETE FROM {$GLOBALS['wpdb']->prefix}usermeta WHERE meta_key LIKE '%metaboxhidden_slimstat%'" );
		$GLOBALS[ 'wpdb' ]->query( "DELETE FROM {$GLOBALS['wpdb']->prefix}usermeta WHERE meta_key LIKE '%closedpostboxes_slimstat%'" );
		
		// Reset the reports for the rest of this request
		wp_slimstat_reports::$reports = array();
		wp_slimstat_reports::$user_reports = array(
			'slimview1' => array(),
			'slimview2' => array(),
			'slimview3' => array(),
			'slimview4' => array(),
			'slimview5' => array(),
			'dashboard' => array(),
			'inactive' => array()
		);
		wp_slimstat_admin::$meta_user_reports = array();
		wp_slimstat_reports::init();
	}

	// Keep track of multiple occurrences of the same report, to allow users to delete duplicates
	$already_seen = array();
?>

<div class="wrap slimstat-layout">
<h2><?php _e( 'Customize and organize your reports','wp-slimstat' ) ?></h2>
<p><?php 
	_e( 'You can drag and drop the placeholders here below from one widget area to another, to customize the layout of each report screen. You can place multiple charts on the same view, clone reports or move them to the Inactive Reports if you are not interested in that specific metric.', 'wp-slimstat' );
	if ( is_network_admin() ) {
		echo ' ';
		_e( 'By using the network-wide customizer, all your users will see the same layout you define, and they will not be able to customize it further.', 'wp-slimstat' );
		echo ' ';
	}
?></p>

<form method="get" action=""><input type="hidden" id="meta-box-order-nonce" name="meta-box-order-nonce" value="<?php echo wp_create_nonce('meta-box-order') ?>" /></form>

<a href="admin.php?page=slimlayout&&amp;action=restore-views" class="button"><?php _e( 'Reset Layout', 'wp-slimstat' ) ?></a>

<?php foreach ( wp_slimstat_reports::$user_reports as $a_location_id => $a_location_list ): ?>

<div id="postbox-container-<?php echo $a_location_id ?>" class="postbox-container">
<h2 class="slimstat-options-section-header"><?php echo wp_slimstat_admin::$screens_info[ $a_location_id ][ 'title' ] ?></h2>
<div id="<?php echo $a_location_id ?>-sortables" class="meta-box-sortables"><?php
	foreach( $a_location_list as $a_report_id ) {
		if ( empty( wp_slimstat_reports::$reports[ $a_report_id ] ) ) {
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
		if ( is_array( wp_slimstat_reports::$reports[ $a_report_id ][ 'classes' ] ) ) {
			$placeholder_classes = ' ' . implode( ' ', wp_slimstat_reports::$reports[ $a_report_id ][ 'classes' ] );
		}

		echo "
			<div class='postbox$placeholder_classes' id='$a_report_id'>
				<div class='slimstat-header-buttons'>
					<a class='slimstat-font-$icon' href='#' title='$title'></a>
					" . ( ( $a_location_id != 'inactive' ) ? ' <a class="slimstat-font-minus-circled" href="#" title="' . __( 'Move to Inactive', 'wp-slimstat' ) . '"></a>' : '' ) . "
				</div>
				<h3 class='hndle'>" . wp_slimstat_reports::$reports[ $a_report_id ][ 'title' ] . "</h3>
			</div>";
	} ?>
</div>
</div>
<?php endforeach; ?>