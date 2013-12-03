<?php

// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

// Update license keys, if needed
if (!empty($_POST['licenses'])){
	wp_slimstat::$options['addon_licenses'] = $_POST['licenses'];
}

echo '<div class="wrap"><h2>WP SlimStat Add-ons</h2>';
echo '<p>'.__('Add-ons extend the functionality of WP SlimStat in many interesting ways. We offer both free and premium (paid) extensions. Each add-on can be installed as a separate plugin, which will receive regular updates via the WordPress Plugins panel. In order to be notified when a new version of a premium add-on is available, please enter the <strong>license key</strong> you received when you purchased it.','wp-slimstat').'</p>';

if (false === ($response = get_transient('wp_slimstat_addon_list'))){
	$response = wp_remote_get('http://slimstat.getused.to.it/update-checker/', array('headers' => array('referer' => get_site_url())));
	if(is_wp_error($response) || $response['response']['code'] != 200){
		$error_message = is_wp_error($response)?$response->get_error_message():$response['response']['code'].' '. $response['response']['message'];
		echo '<p>'.__('There was an error retrieving the add-ons list from the server. Please try again later. Error Message:','wp-slimstat').' '.$error_message.'</p></div>';
		return;
	}
	set_transient('wp_slimstat_addon_list', $response, 3600);
}

$list_addons = maybe_unserialize($response['body']);
if (!is_array($list_addons)){
	echo '<p>'.__('There was an error decoding the add-ons list from the server. Please try again later.','wp-slimstat').'</p></div>';
	return;
}

$license_key_field = false;

?>

<form action="<?php echo wp_slimstat_admin::$view_url ?>addons" method="post" id="form-slimstat-options-tab-addons">
<table class="wp-list-table widefat plugins" cellspacing="0">
	<thead>
	<tr>
		<th scope="col" id="name" class="manage-column column-name"><?php _e('Add-on','wp-slimstat') ?></th><th scope="col" id="description" class="manage-column column-description" style=""><?php _e('Description','wp-slimstat') ?></th>
	</tr>
	</thead>

	<tbody id="the-list">
		<?php foreach ($list_addons as $a_addon): ?>
		<tr id="<?php echo $a_addon['slug'] ?>">
			<td class="plugin-title">
				<strong><a target="_blank" href="<?php echo $a_addon['download_url'] ?>"><?php echo $a_addon['name'] ?></a></strong>
				<div class="row-actions-visible"><?php 
					if (is_plugin_active($a_addon['slug'].'/index.php') || is_plugin_active($a_addon['slug'].'/'.$a_addon['slug'].'.php')){
						echo 'Installed and Active';
					}
					else{
						echo 'Version '.$a_addon['version'].'<br/>Price: '.(is_numeric($a_addon['price'])?'$'.$a_addon['price']:$a_addon['price']);
					}  ?>
				</div>
			</td>
			<td class="column-description desc">
				<div class="plugin-description"><p><?php echo $a_addon['description'] ?></p></div>
				<?php if ((is_plugin_active($a_addon['slug'].'/index.php') || is_plugin_active($a_addon['slug'].'/'.$a_addon['slug'].'.php')) && intval($a_addon['price']) > 0): $license_key_field = true; ?>
				<div class="active second">
					License Key: <input type="text" name="licenses[<?php echo $a_addon['slug'] ?>]" value="<?php echo !empty(wp_slimstat::$options['addon_licenses'][$a_addon['slug']])?wp_slimstat::$options['addon_licenses'][$a_addon['slug']]:'' ?>" size="50"/>
				</div>
				<?php endif ?>
			</td>
		</tr>
		<?php endforeach ?>
	</tbody>
	
	<?php if ($license_key_field): ?>
	<tfoot>
	<tr>
		<th scope="col" class="manage-column column-name" colspan="2"><input type="submit" value="Save Changes" class="button-primary" name="Submit">
	</tr>
	</tfoot>
	<?php endif ?>
</table>
</form>