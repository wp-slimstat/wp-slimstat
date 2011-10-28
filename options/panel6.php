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
		<th scope="row"><form action="https://www.paypal.com/cgi-bin/webscr" method="post">
			<input type="hidden" name="cmd" value="_s-xclick">
			<input type="hidden" name="encrypted" value="-----BEGIN PKCS7-----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-----END PKCS7-----">
			<input type="image" src="https://www.paypal.com/en_US/i/btn/btn_donateCC_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
			<img alt="" border="0" src="https://www.paypal.com/en_US/i/scr/pixel.gif" width="1" height="1">
		</form></th>
		<td>
			<?php _e('How valuable is monitoring your visitors for your site? WP SlimStat is and will always be free, but consider supporting the author if this plugin made your web site better, especially if you are making money out of it. Any donation received will be reinvested in the development of WP SlimStat, and to buy some food for my hungry family.','wp-slimstat-options') ?>
		</td>
	</tr>
</tbody>
</table>

<h3><?php _e("Don't want or cannot donate? You can still help",'wp-slimstat-options') ?></h3>
<p><?php _e("If you cannot donate money, please consider blogging about WP SlimStat with a link to the plugin's page. Your users don't know you're using WP SlimStat, please let them know what makes your blog better. You can also contribute donating your time: do not hesitate to send me bug reports, your localization files, ideas on how to improve WP SlimStat and so on. Whatever you do, thanks for using WP SlimStat!",'wp-slimstat-options') ?></p>

<h3><?php _e("Vote and show your appreciation",'wp-slimstat-options') ?></h3>
<p><?php _e('Tell other people if WP SlimStat works for you and how good it is. <a href="http://wordpress.org/extend/plugins/wp-slimstat/">Rate it</a> on its Plugin Directory page.','wp-slimstat-options') ?></p>