<?php
// Avoid direct access to this piece of code
if (!function_exists('add_action')) exit(0);

$rss = fetch_feed('http://wordpress.org/support/rss/plugin/wp-slimstat');
if (!is_wp_error( $rss ) ){
    // Figure out how many total items there are, but limit it to 5. 
    $maxitems = $rss->get_item_quantity(20); 

    // Build an array of all the items, starting with element 0 (first element).
    $rss_items = $rss->get_items(0, $maxitems); 
}
?>

<table class="form-table">
<tbody>
	<tr valign="top">
		<th scope="row" style="padding-top:10px">
			<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38">
				<img src="https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif" width="147" height="47" alt="Donate Now" /></a></th>
		<td>
			<?php _e('How valuable is monitoring your visitors for your site? WP SlimStat is and will always be free, but consider supporting the author if this plugin made your web site better, especially if you are making money out of it. Any donation received will be reinvested in the development of WP SlimStat, and to buy some food for my hungry family.','wp-slimstat') ?>
		</td>
	</tr>
</tbody>
</table>

<h3><?php _e("Need help?",'wp-slimstat') ?></h3>
<p><?php _e("Please visit our official <a href='http://wordpress.org/support/plugin/wp-slimstat' target='_blank'>support forum</a> to see if your question has already been answered. If not, feel free to post your request there, I'll do my best to address your concerns as soon as possible.",'wp-slimstat') ?></p>

<div class='postbox wide slimstat'>
	<h3 class='hndle'><?php _e('Recent messages from the support forum', 'wp-slimstat'); ?></h3>
	<div class="inside">
		<?php foreach ( $rss_items as $item ) : ?>
		<p><a target="_blank" href="<?php echo esc_url( $item->get_permalink() ); ?>"><?php echo esc_html( $item->get_title() ).' ('.$item->get_date('j F Y | g:i a').')'; ?></a></p>
    <?php endforeach; ?>
	</div>
</div>

<div style="clear:both"></div>

<h3><?php _e("Don't want or cannot donate?",'wp-slimstat') ?></h3>
<p><?php _e("If you cannot donate money, please consider blogging about WP SlimStat, your visitors may not know you're using it! You can also contribute by donating some of your spare time: send me bug reports, localization files and ideas on how to improve WP SlimStat.",'wp-slimstat') ?></p>

<h3><?php _e("Show your appreciation",'wp-slimstat') ?></h3>
<p><?php _e('Tell other people if WP SlimStat works for you and how good it is. <a href="http://wordpress.org/extend/plugins/wp-slimstat/">Rate it</a> on its Plugin Directory page.','wp-slimstat') ?></p>