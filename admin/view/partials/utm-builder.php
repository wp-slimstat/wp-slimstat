<?php
if (!defined('ABSPATH')) {
    exit;
}
$fields = [
    'utm_source' => [__('Campaign source', 'wp-slimstat'), __('The referrer, such as google or newsletter.', 'wp-slimstat'), 'newsletter'],
    'utm_medium' => [__('Campaign medium', 'wp-slimstat'), __('Marketing medium, such as cpc, banner or email.', 'wp-slimstat'), 'email'],
    'utm_campaign' => [__('Campaign name', 'wp-slimstat'), __('Product, promotion or slogan, such as spring_sale.', 'wp-slimstat'), 'spring_sale'],
    'utm_id' => [__('Campaign ID', 'wp-slimstat'), __('The advertising campaign ID. Can be used instead of a campaign name.', 'wp-slimstat'), ''],
    'utm_term' => [__('Campaign term', 'wp-slimstat'), __('Identify paid search keywords.', 'wp-slimstat'), ''],
    'utm_content' => [__('Campaign content', 'wp-slimstat'), __('Differentiate ads or links, such as header_button or text_link.', 'wp-slimstat'), ''],
];
?>
<details id="slimstat-utm-builder" class="slimstat-utm-builder">
    <summary><?php esc_html_e('UTM link builder', 'wp-slimstat'); ?></summary>
    <form class="slimstat-utm-builder__form" autocomplete="off" novalidate>
        <p><?php esc_html_e('Enter your website URL and campaign details. Your link updates as you type. Fields marked * are required; enter a campaign name or campaign ID.', 'wp-slimstat'); ?></p>
        <div class="slimstat-utm-builder__fields">
            <div class="slimstat-utm-builder__website">
                <label for="slimstat-utm-website"><?php esc_html_e('Website URL', 'wp-slimstat'); ?> <span aria-hidden="true">*</span></label>
                <input id="slimstat-utm-website" name="website" type="url" required value="<?php echo esc_attr(home_url('/')); ?>" placeholder="https://www.example.com" aria-describedby="slimstat-utm-website-help" spellcheck="false" dir="ltr">
                <p id="slimstat-utm-website-help"><?php esc_html_e('The full destination URL, starting with https:// or http://.', 'wp-slimstat'); ?></p>
            </div>
            <?php foreach ($fields as $key => [$label, $help, $placeholder]) :
                $required = in_array($key, ['utm_source', 'utm_medium'], true); ?>
                <div>
                    <label for="slimstat-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?><?php if ($required) : ?> <span aria-hidden="true">*</span><?php endif; ?></label>
                    <input id="slimstat-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" type="text" maxlength="191" <?php echo $required ? 'required' : ''; ?> placeholder="<?php echo esc_attr($placeholder); ?>" aria-describedby="slimstat-<?php echo esc_attr($key); ?>-help<?php echo in_array($key, ['utm_campaign', 'utm_id'], true) ? ' slimstat-utm-campaign-help' : ''; ?>" spellcheck="false">
                    <p id="slimstat-<?php echo esc_attr($key); ?>-help"><?php echo esc_html($help); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
        <p id="slimstat-utm-campaign-help"><?php esc_html_e('Campaign name or campaign ID is required. Tags are case-sensitive; use consistent names and avoid personal information.', 'wp-slimstat'); ?></p>
        <div class="slimstat-utm-builder__result">
            <label for="slimstat-utm-result"><?php esc_html_e('Campaign URL', 'wp-slimstat'); ?></label>
            <textarea id="slimstat-utm-result" readonly rows="3" dir="ltr" spellcheck="false" placeholder="<?php esc_attr_e('Complete the required fields to generate your link.', 'wp-slimstat'); ?>" aria-describedby="slimstat-utm-result-help"></textarea>
            <p id="slimstat-utm-result-help"><?php esc_html_e('Existing UTM tags are replaced. Other URL parameters and fragments are preserved. Share this link in your campaigns; visits appear in UTM Campaigns once tracking is enabled.', 'wp-slimstat'); ?></p>
            <div class="slimstat-utm-builder__actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Copy campaign URL', 'wp-slimstat'); ?></button>
                <button type="reset" class="button"><?php esc_html_e('Reset', 'wp-slimstat'); ?></button>
                <span role="status" aria-live="polite" data-utm-status></span>
            </div>
        </div>
        <noscript><p><?php esc_html_e('Enable JavaScript to generate campaign links.', 'wp-slimstat'); ?></p></noscript>
    </form>
</details>
