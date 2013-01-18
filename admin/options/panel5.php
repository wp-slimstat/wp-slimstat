<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

$options_on_this_page = array(
	'enable_javascript' => array('description' => __('Track Browser Capabilities','wp-slimstat-options'), 'type' => 'yesno', 'long_description' => __('Enables a client-side tracking code to collect data about screen resolutions, outbound links, downloads and other relevant information. If Javascript Mode is enabled, browers capabilities will be tracked regardless of which value you set for this option.','wp-slimstat-options')),
	'enable_outbound_tracking' => array('description' => __('Track Outbound Clicks','wp-slimstat-options'), 'type' => 'yesno', 'long_description' => __('Adds a javascript event handler to each external link on your site, to track when visitors click on them. If Browser Capabilities is disabled, outbound clicks <strong>will not</strong> be tracked regardless of which value you set for this option.','wp-slimstat-options')),
	'session_duration' => array('description' => __('Session Duration','wp-slimstat-options'), 'type' => 'integer', 'long_description' => __('Defines how many seconds a visit should last. Google Analytics sets its duration to 1800 seconds.','wp-slimstat-options'), 'after_input_field' => __('seconds','wp-slimstat-options')),
	'extend_session' => array('description' => __('Extend Session','wp-slimstat-options'), 'type' => 'yesno', 'long_description' => __('Extends the duration of a session each time the user visits a new page, by the number of seconds set here above.','wp-slimstat-options')),
	'enable_cdn' => array('description' => __('Enable CDN','wp-slimstat-options'), 'type' => 'yesno', 'long_description' => __("Enables <a href='http://www.jsdelivr.com/' target='_blank'>JSDelivr</a>'s CDN, by serving WP SlimStat's Javascript tracker from their fast and reliable network.",'wp-slimstat-options'))
);