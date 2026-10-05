<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables passed by get_template().
/**
 * Free's sample email (QA C1), shown on the Email Report page and sent by "Send me a sample".
 * Inline styles: the same markup is the email body.
 *
 * @var array $sections wp_slimstat_admin::email_sample_sections()
 */
if (!defined('ABSPATH')) {
    exit;
}
$cell = 'padding:8px 0;border-bottom:1px solid #dcdcde;text-align:start;';
?>
<div dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>" style="max-width:600px;padding:24px;background:#fcfcfd;border:1px solid #dcdcde;color:#1d2327;font:14px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    <p style="margin:0;color:#50575e;"><?php esc_html_e('Last 7 days', 'wp-slimstat'); ?></p>
    <h2 style="margin:0 0 16px;font-size:20px;color:#1d2327;"><?php echo esc_html(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)); ?></h2>
    <?php foreach ($sections as $section) : ?>
        <table style="width:100%;margin:0 0 24px;border-collapse:collapse;">
            <thead>
                <tr>
                    <th scope="col" style="<?php echo esc_attr($cell); ?>font-size:15px;"><?php echo esc_html($section['title']); ?></th>
                    <th scope="col" style="<?php echo esc_attr($cell); ?>text-align:end;font-weight:400;color:#50575e;"><?php esc_html_e('Pageviews', 'wp-slimstat'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($section['rows'] as $row) : ?>
                    <tr>
                        <td style="<?php echo esc_attr($cell); ?>word-break:break-all;"><?php echo esc_html($row[$section['column']]); ?></td>
                        <td style="<?php echo esc_attr($cell); ?>text-align:end;"><?php echo esc_html(number_format_i18n((int) $row['counthits'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$section['rows']) : ?>
                    <tr><td colspan="2" style="<?php echo esc_attr($cell); ?>color:#50575e;"><?php esc_html_e('No pageviews in this date range.', 'wp-slimstat'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
    <p style="margin:0;color:#50575e;font-size:12px;"><?php esc_html_e('Sent by SlimStat Analytics.', 'wp-slimstat'); ?></p>
</div>
