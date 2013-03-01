<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

$options_on_this_page = array(
	'convert_ip_addresses' => array('description' => __('Convert IP Addresses','wp-slimstat-options'), 'type' => 'yesno', 'long_description' => __('View hostnames instead of IP addresses. It slows down the rendering of your metrics.','wp-slimstat-options')),
	'async_load' => array('description' => __('Asynchronous Views','wp-slimstat-options'), 'type' => 'yesno', 'long_description' => __('Use Ajax to load all the stats at runtime. It makes the panels show up faster, but it increases the load on your server.','wp-slimstat-options')),
	'use_european_separators' => array('description' => __('Number Format','wp-slimstat-options'), 'type' => 'yesno', 'long_description' => __('Choose what number format you want to use, European or American.','wp-slimstat-options'), 'custom_label_yes' => '1.234,56', 'custom_label_no' => '1,234.56'),
	'rows_to_show' => array('description' => __('Limit Results to','wp-slimstat-options'), 'type' => 'integer', 'long_description' => __('Specify the number of results to return for each module. Please use a <strong>positive</strong> value.','wp-slimstat-options')),
	'number_results_raw_data' => array('description' => __('Right Now','wp-slimstat-options'), 'type' => 'integer', 'long_description' => __('Specify the number of rows per page to show in the Right Now screen. Please use a <strong>positive</strong> value.','wp-slimstat-options') ),
	'ip_lookup_service' => array('description' => __('IP Lookup','wp-slimstat-options'), 'type' => 'text', 'long_description' => __('Customize the IP lookup service URL.','wp-slimstat-options')),
	'refresh_interval' => array('description' => __('Refresh Every','wp-slimstat-options'), 'type' => 'integer', 'long_description' => __('Refresh the Right Now screen every X seconds. Zero disables this feature.','wp-slimstat-options')),
	'hide_stats_link_edit_posts' => array('description' => __('Hide Stats Link','wp-slimstat-options'), 'type' => 'yesno', 'long_description' => __('Enable this option if your users are confused by the Stats link associate to each post in the Edit Posts page.','wp-slimstat-options')),
	'markings' => array('description' => __('Chart Annotations','wp-slimstat-options'), 'type' => 'textarea', 'long_description' => __("Add <em>markings</em> to each chart by specifying a date and its description in the field below. Useful to keep track of special events and correlate them to your analytics. Please use the following format:<code>YYYY MM DD HH:mm=Description 1,YYYY MM DD HH:mm=Description 2</code>. For example: 2012 12 31 23:55=New Year's Eve.",'wp-slimstat-options'))
);
