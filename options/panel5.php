<?php
// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

$hide_submit = true;

?>

<table class="form-table">
<tbody>
	<tr valign="top">
		<th scope="row"><form style="margin-top: 30px; text-align: center; width: 100%;" action="https://www.paypal.com/cgi-bin/webscr" method="post">
<input name="cmd" type="hidden" value="_s-xclick" />
<input name="encrypted" type="hidden" value="-----BEGIN PKCS7-----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-----END PKCS7-----" />
<input alt="PayPal - The safer, easier way to pay online!" name="submit" src="https://www.paypal.com/en_US/i/btn/btn_donateCC_LG.gif" type="image" /> <img src="https://www.paypal.com/en_US/i/scr/pixel.gif" border="0" alt="" width="1" height="1" /><br />
</form></th>
		<td>
			<?php _e('How valuable is monitoring your visitors for your site? WP SlimStat is and will always be free, but consider supporting the author if this plugin made your web site better. Any money received will be reinvested in the development of WP SlimStat, and to buy some food for my hungry family.','wp-slimstat-options') ?>
		</td>
	</tr>
</tbody>
</table>

<h3><?php _e("Don't want to donate? You can still help",'wp-slimstat-options') ?></h3>
<p><?php _e("If you don't like donating money, please consider blogging about WP SlimStat with a link to the plugin page. Your users don't know you're using WP SlimStat, please let them know what makes your blog better. You can also contribute donating your time: do not hesitate to send me bug reports, your localization files, ideas on how to improve WP SlimStat and so on. Whatever you do, thanks for using WP SlimStat!",'wp-slimstat-options') ?></p>
