<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// Load the options
$wp_slimstat_options = array();
$wp_slimstat_options['ignore_ip'] = get_option('slimstat_ignore_ip', array());
$wp_slimstat_options['ignore_resources'] = get_option('slimstat_ignore_resources', array());
$wp_slimstat_options['ignore_browsers'] = get_option('slimstat_ignore_browsers', array());

?>

<h3><?php _e('IP addresses to ignore','wp-slimstat-options') ?></h3>
<p><?php _e("Enter a list of networks you don't want to track, separated by commas. Each network <strong>must</strong> be defined using the <a href='http://en.wikipedia.org/wiki/Classless_Inter-Domain_Routing' target='_blank'>CIDR notation</a> (i.e. <em>192.168.1.1/24</em>). If the format is incorrect, WP SlimStat may not track pageviews properly.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_ip]"><?php
	$list_to_show = '';
	foreach($wp_slimstat_options['ignore_ip'] as $a_ip_range)
		$list_to_show .= $a_ip_range.', ';
	echo substr($list_to_show, 0, -2);
?></textarea></p>

<h3><?php _e('Pages and posts to ignore','wp-slimstat-options') ?></h3>
<p><?php _e("Enter a list of permalinks you don't want to track, separated by commas. You should omit the domain name from these resources: <em>/about, ?p=1, etc. WP SlimStat will ignore all the pageviews whose permalink <strong>starts</strong> with any of them.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_resources]"><?php
	$list_to_show = '';
	foreach($wp_slimstat_options['ignore_resources'] as $a_resource)
		$list_to_show .= $a_resource.', ';
	echo substr($list_to_show, 0, -2);
?></textarea></p>

<h3><?php _e('Browsers to ignore','wp-slimstat-options') ?></h3>
<p><?php _e("Enter a list of browsers you don't want to track, separated by commas. You can specify the browser's version adding a slash after the name  (i.e. <em>Firefox/3.6</em>). WP SlimStat will ignore all the browsers whose identification string <strong>starts</strong> with one of these.",'wp-slimstat-options') ?></p>
<p><textarea class="large-text code" cols="50" rows="1" name="options[ignore_browsers]"><?php
	$list_to_show = '';
	foreach($wp_slimstat_options['ignore_browsers'] as $a_browser)
		$list_to_show .= $a_browser.', ';
	echo substr($list_to_show, 0, -2);
?></textarea></p>