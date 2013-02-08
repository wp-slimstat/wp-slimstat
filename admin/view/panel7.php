<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

if (!has_action('wp_slimstat_custom_report')){ ?>

<div class="postbox medium">
	<h3 class="hndle"><?php _e('Your report here', 'wp-slimstat-view'); ?></h3>
	<div class="container noscroll">
		<p style="padding:10px;line-height:2em;white-space:normal"><?php _e( 'Yes, you can! Create and view your personalized analytics for WP SlimStat. Just write a new plugin that retrieves the desired information from the database and then hook it to the action <code>wp_slimstat_custom_report</code>. A demo plugin comes with the package. It shows how to write custom reports in 5 minutes: <code>wp-slimstat-custom-report-demo.php.txt</code>. For more information, visit my <a href="http://wordpress.org/tags/wp-slimstat?forum_id=10" target="_blank">support forum</a>.', 'wp-slimstat-view' ); ?></p>
	</div>
</div>

<?php
}
else {
	do_action('wp_slimstat_custom_report');
}
?>