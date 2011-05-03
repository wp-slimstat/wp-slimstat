<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

if (!has_action('wp_slimstat_custom_report')){ ?>

<div class="postbox medium <?php echo $wp_locale->text_direction ?>">
	<h3><?php _e( 'Pluggable custom reports', 'wp-slimstat-view' ); ?></h3>
	<div class="container noscroll">
		<p class="last" style="padding:10px;line-height:2em;white-space:normal"><?php _e( 'Yes, you can! Create and view your personalized analytics for WP SlimStat. In order to do this, just write a new plugin that fetches the desired information from the database and then hook it to the action <code>wp_slimstat_custom_report</code>. A demo plugin comes with the package. It shows how to write custom reports in 5 minutes. You can find it inside the plugin&apos;s folder: <code>wp-slimstat-custom-report-demo.php</code>. For more information, visit my <a href="http://lab.duechiacchiere.it/" target="_blank">support forum</a>.', 'wp-slimstat-view' ); ?></p>
	</div>
</div>

<?php
}
else {
	do_action('wp_slimstat_custom_report');
}
?>