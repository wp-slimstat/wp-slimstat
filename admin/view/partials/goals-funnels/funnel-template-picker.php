<?php
/**
 * Funnel template picker — the grid of prefab template cards.
 *
 * Shared by funnels-card.php's empty state and the "See templates" reveal that
 * appears once funnels already exist (#7), so the prefab templates stay
 * reachable instead of vanishing after the first funnel is created.
 *
 * Each entry's `key` must match a FUNNEL_TEMPLATES entry in
 * admin/assets/js/goals-funnels.js; the existing open-funnel-builder click
 * handler routes the chosen template into the builder.
 *
 * @var array $template_cards  list of ['key','title','body', optional 'modifier']
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="slimstat-gf-template-picker"
     id="slimstat-gf-template-picker"
     role="group"
     aria-label="<?php esc_attr_e('Funnel templates', 'wp-slimstat'); ?>">
    <?php foreach ($template_cards as $card) : ?>
        <button type="button"
                class="slimstat-gf-template-card<?php echo empty($card['modifier']) ? '' : ' ' . esc_attr($card['modifier']); ?>"
                data-action="open-funnel-builder"
                data-mode="create"
                data-template="<?php echo esc_attr($card['key']); ?>">
            <span class="slimstat-gf-template-card__title"><?php echo esc_html($card['title']); ?></span>
            <?php if ('' !== (string) ($card['body'] ?? '')) : ?>
                <span class="slimstat-gf-template-card__body"><?php echo esc_html($card['body']); ?></span>
            <?php endif; ?>
        </button>
    <?php endforeach; ?>
</div>
