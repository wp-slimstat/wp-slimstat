<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

$options_on_this_page = array(
	'is_tracking' => array( 'description' => __('Activate tracking','wp-slimstat-options'), 'type' => 'yesno', 'long_description' => __('You may want to prevent WP SlimStat from tracking users, but still be able to access your stats.','wp-slimstat-options') ),
	'javascript_mode' => array( 'description' => __('Javascript Mode','wp-slimstat-options'), 'type' => 'yesno', 'long_description' => __('Turn this feature on if you are using a caching plugin (W3 Total Cache and friends). WP SlimStat will behave pretty much like Google Analytics, and visitors whose browser does not support Javascript will be ignored. A nice side effect is that <strong>most</strong> spammers, search engines and other crawlers will not be tracked.','wp-slimstat-options') ),
	'custom_js_path' => array( 'description' => __('Custom path','wp-slimstat-options'), 'type' => 'text', 'long_description' => __('If you moved <code>wp-slimstat-js.php</code> out of its default folder, specify the new path here.','wp-slimstat-option').' <br> '.__('Default:','wp-slimstat-options').' <code>'.str_replace(home_url(), '', WP_PLUGIN_URL.'/wp-slimstat').'</code> '.__('The appropriate protocol (http or https) will be chosen by the system.','wp-slimstat-options'), 'before_input_field' => home_url() ),
	'auto_purge' => array( 'description' => __('Store Data For','wp-slimstat-options'), 'type' => 'integer', 'long_description' => __('Automatically deletes pageviews older than <strong>X</strong> days (uses Wordpress cron jobs). Zero disables this feature.','wp-slimstat-options').(wp_get_schedule('wp_slimstat_purge')?' <br> '.__('Next clean-up on','wp-slimstat-options').' '.date_i18n(get_option('date_format').', '.get_option('time_format'), wp_next_scheduled('wp_slimstat_purge')).'. '.sprintf(__('Entries recorded on or before %s will be permanently deleted.','wp-slimstat-view'), date_i18n(get_option('date_format'), strtotime('-'.wp_slimstat::$options['auto_purge'].' days'))):''), 'after_input_field' => __('days','wp-slimstat-options') ),
	'add_posts_column' => array( 'description' => __('Add Column to Posts','wp-slimstat-options'), 'type' => 'yesno', 'long_description' => __('Adds a new column to the Edit Posts screen, with the number of hits per post (may slow down page rendering).','wp-slimstat-options') ),
	'use_separate_menu' => array( 'description' => __('Use standalone menu','wp-slimstat-options'), 'type' => 'yesno', 'long_description' => __('Lets you decide if you want to have a standalone admin menu for WP SlimStat or not.','wp-slimstat-options') )
);

// If autopurge = 0, we can unschedule our cron job. If autopurge > 0 and the hook was not scheduled, we schedule it
if (isset($_POST['options']['auto_purge'])){
	if ($_POST['options']['auto_purge'] == 0){
		wp_clear_scheduled_hook('wp_slimstat_purge');
	}
	else if (wp_next_scheduled( 'my_schedule_hook' ) == 0){
		wp_schedule_event(time(), 'daily', 'wp_slimstat_purge');
	}
}
