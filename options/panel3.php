<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Load the options
$wp_slimstat_options = array();
$wp_slimstat_options['can_view'] = get_option('slimstat_can_view', array());
$wp_slimstat_options['can_admin'] = get_option('slimstat_can_admin', array());

?>

<h3><?php _e('Who can view the reports','wp-slimstat-options') ?></h3>
<p><?php _e("Enter a list of users who are allowed to view WP SlimStat reports, separated by commas. Admins are implicitly allowed, so you don't need to list them in here. If this field is empty, <strong>all</strong> users (but subscribers) will have access to the reports.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[can_view]"><?php
	$list_to_show = '';
	foreach($wp_slimstat_options['can_view'] as $a_user)
		$list_to_show .= $a_user.', ';
	echo substr($list_to_show, 0, -2);
?></textarea></p>

<h3><?php _e('Who can manage these options','wp-slimstat-options') ?></h3>
<p><?php _e("Enter a list of users who are allowed to update these options. Please be advised that admins <strong>are not</strong> implicitly allowed, so do not forget to include yourself! If this field is empty, <strong>all</strong> users (but subscribers) will have access to the options panel.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[can_admin]"><?php
	$list_to_show = '';
	foreach($wp_slimstat_options['can_admin'] as $a_user)
		$list_to_show .= $a_user.', ';
	echo substr($list_to_show, 0, -2);
?></textarea></p>