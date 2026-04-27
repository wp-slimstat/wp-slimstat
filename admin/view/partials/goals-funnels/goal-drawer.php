<?php
/**
 * Goal drawer — side-sheet form for creating or editing a goal.
 *
 * Rendered once per slimview6 page; JS populates fields + toggles .is-open.
 * The paused toggle uses the hidden-companion checkbox idiom (reference:
 * wp-slimstat-pro/src/Addon/Addons/EmailReportsAddon.php:240) so that unchecking
 * actually POSTs active=0 — sanitize_goal() defaults to true on missing field.
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
<aside class="slimstat-gf-drawer" id="slimstat-gf-goal-drawer" role="dialog" aria-modal="true" aria-labelledby="slimstat-gf-goal-drawer-title" aria-hidden="true">
    <div class="slimstat-gf-drawer__backdrop" data-action="close-goal-drawer"></div>
    <form class="slimstat-gf-drawer__panel" data-form="goal" tabindex="-1" autocomplete="off" onsubmit="return false;">
        <header class="slimstat-gf-drawer__head">
            <h2 class="slimstat-gf-drawer__title" id="slimstat-gf-goal-drawer-title">
                <span data-role="title-create"><?php esc_html_e('Add goal', 'wp-slimstat'); ?></span>
                <span data-role="title-edit" hidden><?php esc_html_e('Edit goal', 'wp-slimstat'); ?></span>
            </h2>
            <button type="button" class="slimstat-gf-drawer__close" data-action="close-goal-drawer" aria-label="<?php esc_attr_e('Close drawer', 'wp-slimstat'); ?>">×</button>
        </header>

        <input type="hidden" name="goal_id" value="" data-role="goal-id">

        <label class="slimstat-gf-field">
            <span class="slimstat-gf-field__label"><?php esc_html_e('Name', 'wp-slimstat'); ?></span>
            <input type="text" name="goal_name" data-role="goal-name" class="regular-text" required>
        </label>

        <label class="slimstat-gf-field">
            <span class="slimstat-gf-field__label"><?php esc_html_e('Dimension', 'wp-slimstat'); ?></span>
            <select name="goal_dimension" data-role="goal-dimension">
                <?php foreach ($dimensions as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="slimstat-gf-field">
            <span class="slimstat-gf-field__label"><?php esc_html_e('Operator', 'wp-slimstat'); ?></span>
            <select name="goal_operator" data-role="goal-operator">
                <?php foreach ($operators as $op) :
                    $op_label = $operator_labels[$op] ?? $op;
                    ?>
                    <option value="<?php echo esc_attr($op); ?>"><?php echo esc_html($op_label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="slimstat-gf-field" data-role="value-field">
            <span class="slimstat-gf-field__label"><?php esc_html_e('Value', 'wp-slimstat'); ?></span>
            <input type="text" name="goal_value" data-role="goal-value" class="regular-text" placeholder="<?php esc_attr_e('e.g. /pricing', 'wp-slimstat'); ?>">
        </label>

        <div class="slimstat-gf-field slimstat-gf-field--toggle">
            <label class="slimstat-gf-field__label slimstat-gf-toggle-label" for="slimstat-gf-goal-paused">
                <?php esc_html_e('Paused', 'wp-slimstat'); ?>
            </label>
            <span class="slimstat-gf-toggle">
                <input type="checkbox"
                       id="slimstat-gf-goal-paused"
                       name="goal_paused"
                       value="1"
                       data-role="goal-paused"
                       aria-describedby="slimstat-gf-goal-paused-hint">
                <span id="slimstat-gf-goal-paused-hint" class="slimstat-gf-field__hint">
                    <?php esc_html_e('Paused goals don\'t count against the limit.', 'wp-slimstat'); ?>
                </span>
            </span>
        </div>

        <p class="slimstat-gf-drawer__error" data-role="drawer-error" hidden role="alert"></p>

        <footer class="slimstat-gf-drawer__foot">
            <button type="button" class="button" data-action="close-goal-drawer"><?php esc_html_e('Cancel', 'wp-slimstat'); ?></button>
            <button type="button" class="button button-primary" data-action="save-goal">
                <span data-role="save-create"><?php esc_html_e('Add', 'wp-slimstat'); ?></span>
                <span data-role="save-edit" hidden><?php esc_html_e('Save changes', 'wp-slimstat'); ?></span>
            </button>
        </footer>
    </form>
</aside>
