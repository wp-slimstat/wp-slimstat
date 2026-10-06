<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included by a report/admin rendering method; these are local template variables, not plugin globals.
if (!defined('ABSPATH')) {
    exit;
}
$fields = [
    'main' => [
        'utm_source' => [__('Campaign source', 'wp-slimstat'), __('Where you will share the link, such as google or newsletter.', 'wp-slimstat'), 'newsletter'],
        'utm_medium' => [__('Campaign medium', 'wp-slimstat'), __('The marketing channel, such as email, cpc (paid search) or social.', 'wp-slimstat'), 'email'],
        'utm_campaign' => [__('Campaign name', 'wp-slimstat'), __('A name for your promotion, such as spring_sale. Or use a campaign ID under Additional campaign details.', 'wp-slimstat'), 'spring_sale'],
    ],
    'extra' => [
        'utm_id' => [__('Campaign ID', 'wp-slimstat'), __('An ID from your ad platform, used with or instead of a campaign name.', 'wp-slimstat'), ''],
        'utm_term' => [__('Campaign term', 'wp-slimstat'), __('The paid search keyword, such as running shoes.', 'wp-slimstat'), 'running shoes'],
        'utm_content' => [__('Campaign content', 'wp-slimstat'), __('Identify a specific ad or link, such as header_button.', 'wp-slimstat'), 'header_button'],
    ],
];
?>
<details id="slimstat-utm-builder" class="slimstat-utm-builder">
    <summary><?php esc_html_e('UTM link builder', 'wp-slimstat'); ?></summary>
    <form class="slimstat-utm-builder__form" autocomplete="off" novalidate>
        <p><?php esc_html_e('Create a link to see which campaigns bring visitors to your site. Fields marked * are required.', 'wp-slimstat'); ?></p>
        <div class="slimstat-utm-builder__website">
            <label for="slimstat-utm-website"><?php esc_html_e('Website URL', 'wp-slimstat'); ?> <span aria-hidden="true">*</span></label>
            <input id="slimstat-utm-website" name="website" type="url" required value="<?php echo esc_attr(home_url('/')); ?>" placeholder="https://www.example.com" aria-describedby="slimstat-utm-website-help" spellcheck="false" dir="ltr">
            <p id="slimstat-utm-website-help"><?php esc_html_e('The page visitors should land on. Include https:// or http://. Existing UTM tags will be replaced.', 'wp-slimstat'); ?></p>
        </div>
        <?php foreach ($fields as $group => $group_fields) : ?>
            <?php if ($group === 'extra') : ?>
                <details class="slimstat-utm-builder__extra">
                    <summary><?php esc_html_e('Additional campaign details', 'wp-slimstat'); ?></summary>
            <?php endif; ?>
            <div class="slimstat-utm-builder__fields">
                <?php foreach ($group_fields as $key => [$label, $help, $placeholder]) :
                    $required = in_array($key, ['utm_source', 'utm_medium'], true); ?>
                    <div<?php echo $key === 'utm_campaign' ? ' class="slimstat-utm-builder__wide"' : ''; ?>>
                        <label for="slimstat-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?><?php if ($required || $key === 'utm_campaign') : ?> <span aria-hidden="true">*</span><?php elseif (in_array($key, ['utm_term', 'utm_content'], true)) : ?> <span class="slimstat-utm-builder__optional"><?php esc_html_e('(optional)', 'wp-slimstat'); ?></span><?php endif; ?></label>
                        <input id="slimstat-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" type="text" maxlength="191" <?php echo $required ? 'required' : ''; ?> placeholder="<?php echo esc_attr($placeholder); ?>" aria-describedby="slimstat-<?php echo esc_attr($key); ?>-help" spellcheck="false" autocapitalize="none">
                        <p id="slimstat-<?php echo esc_attr($key); ?>-help"><?php echo esc_html($help); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($group === 'extra') : ?></details><?php endif; ?>
        <?php endforeach; ?>
        <p><?php esc_html_e('Keep names consistent: Email and email appear separately in reports. Avoid personal information in campaign links.', 'wp-slimstat'); ?></p>
        <div class="slimstat-utm-builder__result">
            <label for="slimstat-utm-result"><?php esc_html_e('Campaign URL', 'wp-slimstat'); ?></label>
            <textarea id="slimstat-utm-result" readonly rows="3" dir="ltr" spellcheck="false" placeholder="<?php esc_attr_e('Complete the required fields to generate your link.', 'wp-slimstat'); ?>" aria-describedby="slimstat-utm-result-help"></textarea>
            <p id="slimstat-utm-result-help"><?php esc_html_e('Your URL updates as you type. Copy it into your campaign; recorded visits appear in UTM Campaigns.', 'wp-slimstat'); ?></p>
            <div class="slimstat-utm-builder__actions">
                <button type="submit" class="button"><?php esc_html_e('Copy campaign URL', 'wp-slimstat'); ?></button>
                <button type="reset" class="button"><?php esc_html_e('Reset', 'wp-slimstat'); ?></button>
                <span role="status" aria-live="polite" data-utm-status></span>
            </div>
        </div>
        <noscript><p><?php esc_html_e('Enable JavaScript to generate campaign links.', 'wp-slimstat'); ?></p></noscript>
    </form>
</details>
