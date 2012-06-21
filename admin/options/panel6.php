<?php
// Avoid direct access to this piece of code
if (strpos($_SERVER['SCRIPT_FILENAME'], basename(__FILE__))){
	header('Location: /');
	exit;
}
?>

<table class="form-table">
<tbody>
	<tr valign="top">
		<th scope="row" style="padding-top:10px">
			<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=Z732JS7KQ6RRL&lc=US&item_name=WP%20SlimStat&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donateCC_LG%2egif%3aNonHosted">
				<img src="https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif" width="147" height="47" alt="Donate Now" /></a></th>
		<td>
			<?php _e('How valuable is monitoring your visitors for your site? WP SlimStat is and will always be free, but consider supporting the author if this plugin made your web site better, especially if you are making money out of it. Any donation received will be reinvested in the development of WP SlimStat, and to buy some food for my hungry family.','wp-slimstat-options') ?>
		</td>
	</tr>
</tbody>
</table>

<h3><?php _e("Don't want or cannot donate?",'wp-slimstat-options') ?></h3>
<p><?php _e("If you cannot donate money, please consider blogging about WP SlimStat, Your visitors don't know you're using WP SlimStat! You can also contribute by donating your spare time: send me bug reports, localization files and ideas on how to improve WP SlimStat.",'wp-slimstat-options') ?></p>

<h3><?php _e("Show your appreciation",'wp-slimstat-options') ?></h3>
<p><?php _e('Tell other people if WP SlimStat works for you and how good it is. <a href="http://wordpress.org/extend/plugins/wp-slimstat/">Rate it</a> on its Plugin Directory page.','wp-slimstat-options') ?></p>