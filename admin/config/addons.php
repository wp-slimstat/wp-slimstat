<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Update license keys, if needed
if (!empty($_POST['licenses'])){
	wp_slimstat::$options['addon_licenses'] = $_POST['licenses'];
}

$response = get_transient('wp_slimstat_addon_list');
if (!empty($_GET['force_refresh']) || false === $response){
	$response = wp_remote_get('http://www.wp-slimstat.com/update-checker/', array('headers' => array('referer' => get_site_url())));
	if(is_wp_error($response) || $response['response']['code'] != 200){
		$error_message = is_wp_error($response)?$response->get_error_message():$response['response']['code'].' '. $response['response']['message'];
		echo '<p>'.__('There was an error retrieving the add-ons list from the server. Please try again later. Error Message:','wp-slimstat').' '.$error_message.'</p></div>';
		return;
	}

	set_transient('wp_slimstat_addon_list', $response, 86400);
}

$at_least_one_add_on_active = false;
$list_addons = maybe_unserialize($response['body']);
if (!is_array($list_addons)){
	echo '<p>'.__('There was an error decoding the add-ons list from the server. Please try again later.','wp-slimstat').'</p></div>';
	return;
}

?>

<div class="wrap slimstat">
<h2><?php _e('Add-ons','wp-slimstat') ?></h2>
<p><?php _e('Add-ons extend the functionality of Slimstat in many interesting ways. We offer both free and premium (paid) extensions. Each add-on can be installed as a separate plugin, which will receive regular updates via the WordPress Plugins panel. In order to be notified when a new version of a premium add-on is available, please enter the <strong>license key</strong> you received when you purchased it.','wp-slimstat') ?>
<?php
	if (empty($_GET['force_refresh'])){
		echo ' ';
		printf(__('This list is refreshed once daily: <a href="%s&amp;force_refresh=true">click here</a> to clear the cache.','wp-slimstat'), $_SERVER['REQUEST_URI']);
	}
?>
</p>

<form method="post" id="form-slimstat-options-tab-addons">
<table class="wp-list-table widefat plugins slimstat-addons" cellspacing="0">
	<thead>
	<tr>
		<th scope="col" id="name" class="manage-column column-name"><?php _e('Add-on','wp-slimstat') ?></th><th scope="col" id="description" class="manage-column column-description" style=""><?php _e('Description','wp-slimstat') ?></th>
	</tr>
	</thead>

	<tbody id="the-list">
		<?php foreach ($list_addons as $a_addon): $is_active = is_plugin_active($a_addon['slug'].'/index.php') || is_plugin_active($a_addon['slug'].'/'.$a_addon['slug'].'.php'); ?>
		<tr id="<?php echo $a_addon['slug'] ?>" <?php echo $is_active?'class="active"':'' ?>>
			<th scope="row" class="plugin-title">
				<strong><a target="_blank" href="<?php echo $a_addon['download_url'] ?>"><?php echo $a_addon['name'] ?></a></strong>
				<div class="row-actions-visible"><?php 
					if ( !empty( $a_addon['version'] ) ) {
						echo ( $is_active ? __( 'Repo Version', 'wp-slimstat' ) : __( 'Version', 'wp-slimstat' ) ) . ': ' . $a_addon[ 'version' ].'<br/>';
					}

					if ( $is_active ){
						if ( is_plugin_active($a_addon['slug'].'/index.php') ) {
							$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $a_addon['slug'] . '/index.php' );
						}
						else {
							$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $a_addon['slug'] . '/' . $a_addon['slug'] );
						}

						if ( !empty( $plugin_data[ 'Version' ] ) ) {
							echo __( 'Your Version:', 'wp-slimstat' ) . ' ' . $plugin_data[ 'Version' ];
						}
						else{
							_e( 'Installed and Active', 'wp-slimstat' );
						}
						$at_least_one_add_on_active = true;
					}
					else{
						echo 'Price: '.(is_numeric($a_addon['price'])?'$'.$a_addon['price']:$a_addon['price']);
					}  ?>
				</div>
			</th>
			<td class="column-description desc">
				<div class="plugin-description"><p><?php echo $a_addon['description'] ?></p></div>
				<?php if ((is_plugin_active($a_addon['slug'].'/index.php') || is_plugin_active($a_addon['slug'].'/'.$a_addon['slug'].'.php'))): ?>
				<div class="active second">
					License Key: <input type="text" name="licenses[<?php echo $a_addon['slug'] ?>]" value="<?php echo !empty(wp_slimstat::$options['addon_licenses'][$a_addon['slug']])?wp_slimstat::$options['addon_licenses'][$a_addon['slug']]:'' ?>" size="50"/>
				</div>
				<?php endif; ?>
			</td>
		</tr>
		<?php endforeach ?>
	</tbody>
</table>
<?php if ( $at_least_one_add_on_active ): ?><input type="submit" value="Save License Keys" class="button-primary" name="Submit"><?php endif ?>

</form>
</div>