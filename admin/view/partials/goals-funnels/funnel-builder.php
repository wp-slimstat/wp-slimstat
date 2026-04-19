<?php
/**
 * Funnel builder — overlay for creating or editing a 2–5 step funnel.
 *
 * Rendered once per slimview6 page; JS populates fields + toggles .is-open,
 * and clones the step-template for each step row.
 *
 * Caller-scope variables:
 *   array $dimensions       — key => label
 *   array $operators        — list of keys
 *   array $operator_labels  — key => label
 *
 * @var array $dimensions
 * @var array $operators
 * @var array $operator_labels
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="slimstat-gf-builder" id="slimstat-gf-funnel-builder" role="dialog" aria-modal="true" aria-labelledby="slimstat-gf-builder-title" aria-hidden="true">
    <div class="slimstat-gf-builder__backdrop" data-action="close-funnel-builder"></div>
    <form class="slimstat-gf-builder__panel" data-form="funnel" tabindex="-1" autocomplete="off" onsubmit="return false;">
        <header class="slimstat-gf-builder__head">
            <h2 class="slimstat-gf-builder__title" id="slimstat-gf-builder-title">
                <span data-role="title-create"><?php esc_html_e('Add funnel', 'wp-slimstat'); ?></span>
                <span data-role="title-edit" hidden><?php esc_html_e('Edit funnel', 'wp-slimstat'); ?></span>
            </h2>
            <button type="button" class="slimstat-gf-builder__close" data-action="close-funnel-builder" aria-label="<?php esc_attr_e('Close builder', 'wp-slimstat'); ?>">×</button>
        </header>

        <input type="hidden" name="funnel_id" value="" data-role="funnel-id">

        <label class="slimstat-gf-field">
            <span class="slimstat-gf-field__label"><?php esc_html_e('Funnel name', 'wp-slimstat'); ?></span>
            <input type="text" name="funnel_name" data-role="funnel-name" class="regular-text" required placeholder="<?php esc_attr_e('e.g. Checkout', 'wp-slimstat'); ?>">
        </label>

        <div class="slimstat-gf-builder__steps" data-role="steps-container" aria-live="polite"></div>

        <p class="slimstat-gf-builder__error" data-role="builder-error" hidden role="alert"></p>

        <footer class="slimstat-gf-builder__foot">
            <button type="button" class="button slimstat-gf-builder__add-step" data-action="add-funnel-step">
                <?php esc_html_e('+ Add step', 'wp-slimstat'); ?>
            </button>
            <span class="slimstat-gf-field__hint"><?php esc_html_e('Funnels need between 2 and 5 steps.', 'wp-slimstat'); ?></span>
            <span class="slimstat-gf-builder__spacer"></span>
            <button type="button" class="button" data-action="close-funnel-builder"><?php esc_html_e('Cancel', 'wp-slimstat'); ?></button>
            <button type="button" class="button button-primary" data-action="save-funnel"><?php esc_html_e('Save funnel', 'wp-slimstat'); ?></button>
        </footer>

        <template data-role="step-template">
            <div class="slimstat-gf-step-row" data-step-row="">
                <span class="slimstat-gf-step-row__num" data-role="step-num"></span>
                <input type="text"
                       data-role="step-name"
                       class="regular-text"
                       placeholder="<?php esc_attr_e('Step name', 'wp-slimstat'); ?>">
                <select data-role="step-dimension">
                    <?php foreach ($dimensions as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <select data-role="step-operator">
                    <?php foreach ($operators as $op) :
                        $op_label = $operator_labels[$op] ?? $op;
                        ?>
                        <option value="<?php echo esc_attr($op); ?>"><?php echo esc_html($op_label); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text"
                       data-role="step-value"
                       class="regular-text"
                       placeholder="<?php esc_attr_e('Value', 'wp-slimstat'); ?>">
                <button type="button"
                        class="button-link slimstat-gf-step-row__remove"
                        data-action="remove-funnel-step"
                        aria-label="<?php esc_attr_e('Remove step', 'wp-slimstat'); ?>">×</button>
            </div>
        </template>
    </form>
</div>
