/**
 * Goals & Funnels — admin interactions for slimview6 (5.5.0 redesign).
 *
 * Responsibilities:
 *   - Open/close goal drawer, funnel builder, destructive confirm sheet.
 *   - Save/delete goal + funnel via existing AJAX endpoints.
 *   - Funnel tab lazy-load via slimstat_load_funnel_data.
 *   - Paused-toggle round-trip using explicit active=0|1 (hidden-companion idiom
 *     on server via sanitize_goal; JS serializes explicitly to avoid the
 *     "unchecked = missing = defaults true" trap).
 *
 * Dependencies: jQuery, SlimStatAdminParams (localized via wp_localize_script).
 */
(function ($) {
    'use strict';

    if (typeof SlimStatAdminParams === 'undefined') {
        return;
    }

    var ajaxUrl = SlimStatAdminParams.ajax_url;
    var nonce   = SlimStatAdminParams.goals_nonce;

    // Single source of truth for operators that take no value — mirrors the
    // server's wp_slimstat_db::$valueless_operators (localized in
    // SlimStatAdminParams) so the value-required check and the disable logic
    // never drift from the server contract. (#2)
    var VALUELESS_OPERATORS = (SlimStatAdminParams.valueless_operators && SlimStatAdminParams.valueless_operators.length)
        ? SlimStatAdminParams.valueless_operators
        : ['is_empty', 'is_not_empty'];
    function isValuelessOperator(op) {
        return VALUELESS_OPERATORS.indexOf(op) !== -1;
    }

    // Focus the visible value control on a validation error. Once the autosuggest
    // widget mounts it hides the raw input (display:none) and shows its own
    // display, so focusing the input is a no-op — open the widget (which focuses
    // its search box) when present, else fall back to the bare input. (#2)
    function focusValueField($value) {
        var inst = $value[0] && $value[0]._slimstatSearchable;
        if (inst && typeof inst.open === 'function') {
            inst.open();
        } else {
            $value.trigger('focus');
        }
    }

    // Shared wp.i18n accessor (admin/assets/js/i18n.js, the 'slimstat-i18n'
    // script dependency). Every __()/_n() call below passes the 'wp-slimstat'
    // text domain: `wp i18n make-pot` only extracts JS strings from calls with a
    // literal domain argument, so a single-arg wrapper would hide them from the
    // .pot and they'd never get translated. Keep the domain on every call.
    var _i18n   = window.wpSlimstatI18n;
    var __      = _i18n.__;
    var _n      = _i18n._n;
    var sprintf = _i18n.sprintf;

    // Defaults derived from a WordPress-ecosystem audit of dominant plugins,
    // their canonical permalinks, and default-redirect behaviour
    // (jaan-to/outputs/research/22-funnel_templates_report.md). Two structural
    // findings drove the set:
    // 1. Form plugins (CF7, WPForms Lite, Fluent Forms, Gravity Forms,
    //    Forminator, Elementor Forms) do NOT redirect to a thank-you page
    //    by default. Most service sites stop on the contact page itself, so
    //    `landing_to_contact` ends there intentionally. The longer
    //    `landing_to_thanks` template is for sites that HAVE configured a
    //    redirect (donation, membership, custom form thank-you).
    // 2. WooCommerce powers ~20–21% of all WP sites with the most stable
    //    default URL set in the ecosystem. It earns the top two slots:
    //    `woocommerce_purchase` (full funnel) and `checkout_completion`
    //    (cart → confirm only — answers the most-asked SMB store question).
    var FUNNEL_TEMPLATES = {
        woocommerce_purchase: {
            name: __('WooCommerce purchase', 'wp-slimstat'),
            steps: [
                { name: __('Product', 'wp-slimstat'),        dimension: 'resource', operator: 'contains', value: '/product/' },
                { name: __('Cart', 'wp-slimstat'),           dimension: 'resource', operator: 'contains', value: '/cart' },
                { name: __('Checkout', 'wp-slimstat'),       dimension: 'resource', operator: 'contains', value: '/checkout' },
                // WooCommerce's thank-you page is the `order-received` endpoint
                // nested under checkout: /checkout/order-received/{id}/?key=...
                // We match the endpoint segment alone (`/order-received`) rather
                // than `/checkout/order-received`: WooCommerce does not translate
                // the endpoint slug (no _x() in core), so this still matches when
                // the checkout PAGE slug is localized or renamed (e.g. /kasse/,
                // /panier/), which the /checkout-prefixed value would miss.
                // Verified against WooCommerce core + docs — see research #21. (#5)
                { name: __('Order received', 'wp-slimstat'), dimension: 'resource', operator: 'contains', value: '/order-received' }
            ]
        },
        checkout_completion: {
            name: __('Checkout completion', 'wp-slimstat'),
            steps: [
                { name: __('Cart', 'wp-slimstat'),           dimension: 'resource', operator: 'contains', value: '/cart' },
                { name: __('Checkout', 'wp-slimstat'),       dimension: 'resource', operator: 'contains', value: '/checkout' },
                // Match the `order-received` endpoint segment alone so it survives
                // a localized/renamed checkout page slug — see research #21. (#5)
                { name: __('Order received', 'wp-slimstat'), dimension: 'resource', operator: 'contains', value: '/order-received' }
            ]
        },
        landing_to_contact: {
            // Ends at the contact page on purpose — most WP form plugins
            // do not redirect to a thank-you page by default.
            // Step 1 uses `equals /` (homepage only) — `contains /` would
            // match every URL and inflate the step-1 visitor count.
            name: __('Landing to contact', 'wp-slimstat'),
            steps: [
                { name: __('Homepage', 'wp-slimstat'),       dimension: 'resource', operator: 'equals',   value: '/' },
                { name: __('Contact page', 'wp-slimstat'),   dimension: 'resource', operator: 'contains', value: '/contact' }
            ]
        },
        pricing_to_checkout: {
            name: __('Homepage to pricing to checkout', 'wp-slimstat'),
            steps: [
                { name: __('Homepage', 'wp-slimstat'),       dimension: 'resource', operator: 'equals',   value: '/' },
                { name: __('Pricing', 'wp-slimstat'),        dimension: 'resource', operator: 'contains', value: '/pricing' },
                { name: __('Checkout', 'wp-slimstat'),       dimension: 'resource', operator: 'contains', value: '/checkout' }
            ]
        },
        landing_to_thanks: {
            // For sites that HAVE configured a thank-you redirect (custom
            // form plugin behaviour, GiveWP /donation-confirmation/, or a
            // MemberPress /thank-you/). Users will edit all three steps.
            name: __('Landing to thank-you (advanced)', 'wp-slimstat'),
            steps: [
                { name: __('Homepage', 'wp-slimstat'),       dimension: 'resource', operator: 'equals',   value: '/' },
                { name: __('Form page', 'wp-slimstat'),      dimension: 'resource', operator: 'contains', value: '/contact' },
                { name: __('Thank-you page', 'wp-slimstat'), dimension: 'resource', operator: 'contains', value: '/thank-you' }
            ]
        },
        blank: {
            name: '',
            steps: [
                { name: '', dimension: 'resource', operator: 'contains', value: '' },
                { name: '', dimension: 'resource', operator: 'contains', value: '' }
            ]
        }
    };

    var $body = $('body');

    // ============================================================
    //  Helpers
    // ============================================================

    function escHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // WP-locale separators (localized from $wp_locale) so JS-rendered numbers on
    // lazily-loaded funnel tabs match the server's number_format_i18n() output
    // rather than the browser locale's toLocaleString(). Falls back to en_US-ish.
    var _numFmt = (SlimStatAdminParams.number_format && typeof SlimStatAdminParams.number_format === 'object')
        ? SlimStatAdminParams.number_format
        : { decimal_point: '.', thousands_sep: ',' };

    // Integer counts: group thousands with the locale separator. Mirrors
    // number_format_i18n($n) (0 decimals).
    function formatNumber(n) {
        var int = Math.round(Number(n) || 0);
        var sign = int < 0 ? '-' : '';
        return sign + String(Math.abs(int)).replace(/\B(?=(\d{3})+(?!\d))/g, _numFmt.thousands_sep);
    }

    // Percentages/decimals: render with the locale decimal separator, trimming a
    // trailing ".0". Mirrors number_format_i18n($pct, 1) without forcing a decimal.
    function formatPercent(n) {
        var rounded = Math.round((Number(n) || 0) * 10) / 10;
        var str = (rounded === Math.round(rounded)) ? String(rounded) : rounded.toFixed(1);
        return str.replace('.', _numFmt.decimal_point);
    }

    function post(data, onSuccess, onError) {
        return $.post(ajaxUrl, data, function (response) {
            if (response && response.success) {
                if (onSuccess) onSuccess(response.data || {});
            } else {
                var msg = (response && response.data && response.data.message) || __('Request failed.', 'wp-slimstat');
                if (onError) onError(msg); else window.alert(msg);
            }
        }).fail(function () {
            if (onError) onError(__('Network error.', 'wp-slimstat')); else window.alert(__('Network error.', 'wp-slimstat'));
        });
    }

    // ============================================================
    //  Confirm sheet
    // ============================================================

    var $confirmSheet = $('#slimstat-gf-confirm-sheet');
    var confirmHandler = null;

    function openConfirmSheet(opts) {
        if (!$confirmSheet.length) return;
        opts = opts || {};
        $confirmSheet.find('[data-role="confirm-title"]').text(opts.title || __('Delete this?', 'wp-slimstat'));
        $confirmSheet.find('[data-role="confirm-body"]').text(opts.body || '');
        $confirmSheet.find('[data-role="confirm-warning"]').text(
            opts.warning || __('Historical data stays — only the definition is removed. You can always rebuild it.', 'wp-slimstat')
        );
        $confirmSheet.find('[data-role="confirm-cancel"]').text(opts.cancelLabel || __('Cancel', 'wp-slimstat'));
        $confirmSheet.find('[data-role="confirm-destructive"]').text(opts.destructiveLabel || __('Delete', 'wp-slimstat'));
        $confirmSheet.addClass('is-open').attr('aria-hidden', 'false');
        onDialogOpen();
        confirmHandler = opts.onConfirm || null;
        setTimeout(function () {
            $confirmSheet.find('[data-role="confirm-destructive"]').trigger('focus');
        }, 0);
    }

    function closeConfirmSheet() {
        $confirmSheet.removeClass('is-open').attr('aria-hidden', 'true');
        confirmHandler = null;
        onDialogClose();
    }

    $body.on('click', '[data-action="close-confirm-sheet"]', closeConfirmSheet);

    $body.on('click', '[data-action="confirm-destructive"]', function () {
        if (typeof confirmHandler === 'function') {
            confirmHandler();
        }
    });

    // ============================================================
    //  Goal drawer
    // ============================================================

    var $goalDrawer = $('#slimstat-gf-goal-drawer');

    function openGoalDrawer(mode, goal) {
        if (!$goalDrawer.length) return;
        goal = goal || { id: '', name: '', dimension: 'resource', operator: 'contains', value: '', active: true };
        var isEdit = (mode === 'edit');

        $goalDrawer.find('[data-role="title-create"]').prop('hidden', isEdit);
        $goalDrawer.find('[data-role="title-edit"]').prop('hidden', !isEdit);
        $goalDrawer.find('[data-role="save-create"]').prop('hidden', isEdit);
        $goalDrawer.find('[data-role="save-edit"]').prop('hidden', !isEdit);

        $goalDrawer.find('[data-role="goal-id"]').val(goal.id || '');
        $goalDrawer.find('[data-role="goal-name"]').val(goal.name || '');
        $goalDrawer.find('[data-role="goal-dimension"]').val(goal.dimension || 'resource');
        $goalDrawer.find('[data-role="goal-operator"]').val(goal.operator || 'contains');
        $goalDrawer.find('[data-role="goal-value"]').val(goal.value || '');
        $goalDrawer.find('[data-role="goal-paused"]').prop('checked', !goal.active);
        $goalDrawer.find('[data-role="drawer-error"]').attr('hidden', true).text('');

        $goalDrawer.addClass('is-open').attr('aria-hidden', 'false');
        onDialogOpen();
        initAutoSuggest(
            $goalDrawer.find('[data-role="goal-value"]')[0],
            $goalDrawer.find('[data-role="goal-dimension"]').val(),
            $goalDrawer.find('[data-role="goal-operator"]').val()
        );
        setTimeout(function () {
            $goalDrawer.find('[data-role="goal-name"]').trigger('focus');
        }, 0);
    }

    function closeGoalDrawer() {
        destroyAutoSuggest($goalDrawer.find('[data-role="goal-value"]')[0]);
        $goalDrawer.removeClass('is-open').attr('aria-hidden', 'true');
        onDialogClose();
    }

    $body.on('click', '[data-action="open-goal-drawer"]', function () {
        var mode = $(this).data('mode') || 'create';
        var goalAttr = $(this).attr('data-goal');
        var goal = null;
        if (goalAttr) {
            try { goal = JSON.parse(goalAttr); } catch (_e) { goal = null; }
        }
        openGoalDrawer(mode, goal);
    });

    $body.on('click', '[data-action="close-goal-drawer"]', closeGoalDrawer);

    $body.on('click', '[data-action="save-goal"]', function () {
        var $err = $goalDrawer.find('[data-role="drawer-error"]');
        var name = $goalDrawer.find('[data-role="goal-name"]').val();
        if (!name || !String(name).trim()) {
            $err.text(__('Goal name is required.', 'wp-slimstat')).attr('hidden', false);
            return;
        }

        // A value-bearing operator needs a value — otherwise the server rejects
        // the save with a generic banner, or (historically) an empty value was
        // read as "match anything". Mirror the server contract client-side. (#2)
        var $value   = $goalDrawer.find('[data-role="goal-value"]');
        var operator = $goalDrawer.find('[data-role="goal-operator"]').val();
        var value    = $value.val();
        if (!isValuelessOperator(operator) && (!value || !String(value).trim())) {
            $err.text(__('Value is required for this operator.', 'wp-slimstat')).attr('hidden', false);
            focusValueField($value);
            return;
        }

        var paused = $goalDrawer.find('[data-role="goal-paused"]').is(':checked');
        var data = {
            action:    'slimstat_save_goal',
            security:  nonce,
            id:        $goalDrawer.find('[data-role="goal-id"]').val(),
            name:      name,
            dimension: $goalDrawer.find('[data-role="goal-dimension"]').val(),
            operator:  operator,
            value:     value,
            active:    paused ? 0 : 1
        };

        post(data, function () {
            closeGoalDrawer();
            window.location.reload();
        }, function (msg) {
            $err.text(msg).attr('hidden', false);
        });
    });

    // ============================================================
    //  Goal delete — confirm sheet
    // ============================================================

    $body.on('click', '[data-action="delete-goal"]', function () {
        var $btn = $(this);
        var goalId = $btn.data('goal-id');
        var goalName = $btn.data('goal-name') || '';
        openConfirmSheet({
            title:            __('Delete goal?', 'wp-slimstat'),
            body:             goalName
                /* translators: %s is the goal name */
                ? sprintf(__('Delete "%s"?', 'wp-slimstat'), goalName)
                : __('Delete this goal?', 'wp-slimstat'),
            warning:          __('Historical data stays — only the goal definition is removed. You can always rebuild it.', 'wp-slimstat'),
            cancelLabel:      __('Keep goal', 'wp-slimstat'),
            destructiveLabel: __('Delete goal', 'wp-slimstat'),
            onConfirm: function () {
                post({
                    action:   'slimstat_delete_goal',
                    security: nonce,
                    goal_id:  goalId
                }, function () {
                    closeConfirmSheet();
                    window.location.reload();
                }, function (msg) {
                    window.alert(msg);
                });
            }
        });
    });

    // ============================================================
    //  Funnel builder
    // ============================================================

    var $builder = $('#slimstat-gf-funnel-builder');
    var $stepsContainer = $builder.find('[data-role="steps-container"]');
    var $stepTemplate = $builder.find('[data-role="step-template"]');
    var $builderLive = $builder.find('[data-role="builder-live"]');

    // Scoped polite live region for step add/remove/reorder. Announcing concrete,
    // one-line messages here keeps screen readers from re-reading the whole steps
    // container on every renumber (the container is no longer aria-live). FN-12.
    function announceBuilder(message) {
        $builderLive.text(message);
    }

    function renderStepRow(index, step) {
        step = step || { name: '', dimension: 'resource', operator: 'contains', value: '' };
        var tpl = $stepTemplate[0] ? $stepTemplate[0].content.cloneNode(true) : null;
        if (!tpl) return null;
        var $row = $(tpl).find('[data-step-row]').attr('data-step-row', index);
        /* translators: %d is the step number (1–5) */
        $row.find('[data-role="step-num"]').text(sprintf(__('Step %d', 'wp-slimstat'), index + 1));
        $row.find('[data-role="step-name"]').val(step.name || '');
        $row.find('[data-role="step-dimension"]').val(step.dimension || 'resource');
        $row.find('[data-role="step-operator"]').val(step.operator || 'contains');
        $row.find('[data-role="step-value"]').val(step.value || '');
        $row.find('[data-role="test-result"]').text('');
        return $row;
    }

    function renumberSteps() {
        $stepsContainer.find('.slimstat-gf-step-row').each(function (idx) {
            $(this).attr('data-step-row', idx);
            $(this).find('[data-role="step-num"]').text(sprintf(__('Step %d', 'wp-slimstat'), idx + 1));
        });
        var count = $stepsContainer.find('.slimstat-gf-step-row').length;
        $builder.find('.slimstat-gf-builder__add-step').prop('disabled', count >= 5);
        $stepsContainer.find('.slimstat-gf-step-row__remove').prop('disabled', count <= 2);
    }

    function openFunnelBuilder(mode, funnel, templateKey) {
        if (!$builder.length) return;
        funnel = funnel || null;
        var isEdit = (mode === 'edit');

        $builder.find('[data-role="title-create"]').prop('hidden', isEdit);
        $builder.find('[data-role="title-edit"]').prop('hidden', !isEdit);
        $builder.find('[data-role="save-create"]').prop('hidden', isEdit);
        $builder.find('[data-role="save-edit"]').prop('hidden', !isEdit);
        $builder.find('[data-role="builder-error"]').attr('hidden', true).text('');

        destroyStepRowsAutoSuggest();
        $stepsContainer.empty();

        var steps;
        var funnelName;
        var funnelId = '';
        if (mode === 'edit' && funnel) {
            funnelName = funnel.name || '';
            funnelId   = funnel.id || '';
            steps      = (funnel.steps || []).map(function (s) {
                return { name: s.name, dimension: s.dimension, operator: s.operator, value: s.value };
            });
        } else {
            var tpl = FUNNEL_TEMPLATES[templateKey] || FUNNEL_TEMPLATES.blank;
            funnelName = tpl.name || '';
            steps      = tpl.steps.slice(0, 5);
        }
        if (!steps || steps.length < 2) {
            steps = [
                { name: '', dimension: 'resource', operator: 'contains', value: '' },
                { name: '', dimension: 'resource', operator: 'contains', value: '' }
            ];
        }

        $builder.find('[data-role="funnel-name"]').val(funnelName);
        $builder.find('[data-role="funnel-id"]').val(funnelId);

        steps.forEach(function (s, i) {
            var $row = renderStepRow(i, s);
            if ($row) $stepsContainer.append($row);
        });
        renumberSteps();
        initStepRowsAutoSuggest();

        $builder.addClass('is-open').attr('aria-hidden', 'false');
        onDialogOpen();
        setTimeout(function () {
            $builder.find('[data-role="funnel-name"]').trigger('focus');
        }, 0);
    }

    function closeFunnelBuilder() {
        destroyStepRowsAutoSuggest();
        $builder.removeClass('is-open').attr('aria-hidden', 'true');
        onDialogClose();
    }

    function initStepRowsAutoSuggest() {
        $stepsContainer.find('.slimstat-gf-step-row').each(function () {
            var $row = $(this);
            initAutoSuggest(
                $row.find('[data-role="step-value"]')[0],
                $row.find('[data-role="step-dimension"]').val(),
                $row.find('[data-role="step-operator"]').val()
            );
        });
    }

    function destroyStepRowsAutoSuggest() {
        $stepsContainer.find('.slimstat-gf-step-row').each(function () {
            destroyAutoSuggest($(this).find('[data-role="step-value"]')[0]);
        });
    }

    $body.on('click', '[data-action="open-funnel-builder"]', function () {
        var mode = $(this).data('mode') || 'create';
        var templateKey = $(this).data('template') || 'blank';
        var funnelAttr = $(this).attr('data-funnel');
        var funnel = null;
        if (funnelAttr) {
            try { funnel = JSON.parse(funnelAttr); } catch (_e) { funnel = null; }
        }
        openFunnelBuilder(mode, funnel, templateKey);
    });

    $body.on('click', '[data-action="close-funnel-builder"]', closeFunnelBuilder);

    // "See templates" — reveal/hide the prefab template gallery once funnels
    // already exist (the cards used to disappear after the first funnel). The
    // toggle button lives in the postbox header beside "+ Add funnel", so it
    // resolves its panel by aria-controls id rather than a DOM-ancestor lookup
    // (the panel sits in the card body, a separate subtree). The existing
    // open-funnel-builder handler wires the revealed cards. (#7)
    $body.on('click', '[data-action="toggle-funnel-templates"]', function () {
        var $btn    = $(this);
        var panelId = $btn.attr('aria-controls');
        var $panel  = $(document.getElementById(panelId));
        if (!$panel.length) return;
        var willShow = $panel.attr('hidden') != null;
        if (willShow) {
            $panel.removeAttr('hidden');
            $btn.attr('aria-expanded', 'true').text(__('Hide templates', 'wp-slimstat'));
            var $firstCard = $panel.find('.slimstat-gf-template-card').first();
            if ($firstCard.length) $firstCard.trigger('focus');
        } else {
            $panel.attr('hidden', 'hidden');
            $btn.attr('aria-expanded', 'false').text(__('See templates', 'wp-slimstat'));
        }
    });

    $body.on('click', '[data-action="add-funnel-step"]', function () {
        var count = $stepsContainer.find('.slimstat-gf-step-row').length;
        if (count >= 5) return;
        var $row = renderStepRow(count, null);
        if ($row) $stepsContainer.append($row);
        renumberSteps();
        /* translators: %d is the new step number */
        announceBuilder(sprintf(__('Step %d added', 'wp-slimstat'), $stepsContainer.find('.slimstat-gf-step-row').length));
        initAutoSuggest(
            $row.find('[data-role="step-value"]')[0],
            $row.find('[data-role="step-dimension"]').val(),
            $row.find('[data-role="step-operator"]').val()
        );
    });

    $body.on('click', '[data-action="remove-funnel-step"]', function () {
        var count = $stepsContainer.find('.slimstat-gf-step-row').length;
        if (count <= 2) return;
        var $row = $(this).closest('.slimstat-gf-step-row');
        destroyAutoSuggest($row.find('[data-role="step-value"]')[0]);
        $row.remove();
        renumberSteps();
        /* translators: %d is the number of steps remaining */
        announceBuilder(sprintf(__('Step removed, %d steps remaining', 'wp-slimstat'), $stepsContainer.find('.slimstat-gf-step-row').length));
    });

    $body.on('click', '[data-action="save-funnel"]', function () {
        var $err = $builder.find('[data-role="builder-error"]');
        var funnelName = $builder.find('[data-role="funnel-name"]').val();
        if (!funnelName || !String(funnelName).trim()) {
            $err.text(__('Funnel name is required.', 'wp-slimstat')).attr('hidden', false);
            return;
        }

        var steps = [];
        var invalidStep = null; // first step missing a required value
        $stepsContainer.find('.slimstat-gf-step-row').each(function (idx) {
            var $row     = $(this);
            var $value   = $row.find('[data-role="step-value"]');
            var operator = $row.find('[data-role="step-operator"]').val();
            var value    = $value.val();
            if (invalidStep === null && !isValuelessOperator(operator) && (!value || !String(value).trim())) {
                invalidStep = { index: idx, $value: $value };
            }
            steps.push({
                name:      $row.find('[data-role="step-name"]').val(),
                dimension: $row.find('[data-role="step-dimension"]').val(),
                operator:  operator,
                value:     value,
                active:    1
            });
        });

        // A value-bearing step operator needs a value — flag the first offending
        // step by number so the user knows which row to fix. (#2)
        if (invalidStep !== null) {
            $err.text(sprintf(__('Step %d needs a value for its operator.', 'wp-slimstat'), invalidStep.index + 1)).attr('hidden', false);
            focusValueField(invalidStep.$value);
            return;
        }

        if (steps.length < 2 || steps.length > 5) {
            $err.text(__('Funnels need between 2 and 5 steps.', 'wp-slimstat')).attr('hidden', false);
            return;
        }

        var data = {
            action:      'slimstat_save_funnel',
            security:    nonce,
            funnel_id:   $builder.find('[data-role="funnel-id"]').val(),
            funnel_name: funnelName,
            steps:       steps
        };

        post(data, function () {
            closeFunnelBuilder();
            window.location.reload();
        }, function (msg) {
            $err.text(msg).attr('hidden', false);
        });
    });

    // ============================================================
    //  Funnel delete — confirm sheet
    // ============================================================

    $body.on('click', '[data-action="delete-funnel"]', function () {
        var $btn = $(this);
        var funnelId = $btn.data('funnel-id');
        var funnelName = $btn.data('funnel-name') || '';
        openConfirmSheet({
            title:            __('Delete funnel?', 'wp-slimstat'),
            body:             funnelName
                /* translators: %s is the funnel name */
                ? sprintf(__('Delete "%s"?', 'wp-slimstat'), funnelName)
                : __('Delete this funnel?', 'wp-slimstat'),
            warning:          __('Historical data stays — only the funnel definition is removed. You can always rebuild it from the same goals.', 'wp-slimstat'),
            cancelLabel:      __('Keep funnel', 'wp-slimstat'),
            destructiveLabel: __('Delete funnel', 'wp-slimstat'),
            onConfirm: function () {
                post({
                    action:    'slimstat_delete_funnel',
                    security:  nonce,
                    funnel_id: funnelId
                }, function () {
                    closeConfirmSheet();
                    window.location.reload();
                }, function (msg) {
                    window.alert(msg);
                });
            }
        });
    });

    // ============================================================
    //  Funnel tab lazy-load
    // ============================================================

    function renderFunnelBody(steps, summary) {
        var html = '';

        if (!steps || !steps.length) {
            return '<p class="slimstat-gf-empty__body">' + escHtml(__('No data yet for this funnel.', 'wp-slimstat')) + '</p>';
        }

        var stepOne = steps[0] && steps[0].visitors ? Number(steps[0].visitors) : 0;
        html += '<ol class="slimstat-gf-steps" role="list">';
        for (var i = 0; i < steps.length; i++) {
            var step = steps[i];
            var visitors = Number(step.visitors) || 0;
            var pct = Number(step.pct) || 0;
            var dropoff = Number(step.dropoff) || 0;
            var unreachable = !!step.unreachable;
            var width = stepOne > 0 ? Math.max(2, Math.round((visitors / stepOne) * 100)) : 0;
            var stepNum = i + 1;
            var pctLabel = formatPercent(pct);
            var stepCls = unreachable ? 'slimstat-gf-step slimstat-gf-step--unreachable' : 'slimstat-gf-step';

            html += '<li class="' + stepCls + '" data-step="' + stepNum + '">';
            html += '<div class="slimstat-gf-step__head">';
            html += '<span class="slimstat-gf-step__name">' + escHtml(step.name || '') + '</span>';
            // Keep title identical to funnel-bars.php (SSR) — anti-drift.
            html += '<span class="slimstat-gf-step__count" title="' + escHtml(__('Unique visitors who reached this step', 'wp-slimstat')) + '">';
            html += formatNumber(visitors);
            html += ' <span class="slimstat-gf-step__pct">(' + escHtml(pctLabel) + '%)</span>';
            html += '</span></div>';
            html += '<div class="slimstat-gf-step__track" role="presentation">';
            html += '<div class="slimstat-gf-step__fill"' + (visitors === 0 ? ' data-zero' : '') + ' style="width:' + width + '%;"';
            html += ' role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + Math.round(pct) + '"';
            html += ' aria-valuetext="' + escHtml(pctLabel + '%') + '"';
            /* translators: 1: step name, 2: visitor count */
            var ariaLabel = sprintf(__('%1$s: %2$s visitors', 'wp-slimstat'), step.name || '', formatNumber(visitors));
            html += ' aria-label="' + escHtml(ariaLabel) + '"></div>';
            html += '</div>';
            if (unreachable) {
                // Keep this string identical to funnel-bars.php (SSR) — anti-drift. (#9)
                html += '<div class="slimstat-gf-step__unreachable">' +
                    escHtml(__('No visitors reached this step in the selected date range', 'wp-slimstat')) + '</div>';
            } else if (i > 0 && dropoff > 0 && steps[i - 1] && steps[i - 1].visitors) {
                var prev = Number(steps[i - 1].visitors);
                var dropoffPct = prev > 0 ? formatPercent((dropoff / prev) * 100) : formatPercent(0);
                /* translators: 1: visitors dropped, 2: drop-off percentage */
                var dropLine = sprintf(__('↓ %1$s dropped (%2$s%%)', 'wp-slimstat'), formatNumber(dropoff), dropoffPct);
                html += '<div class="slimstat-gf-step__dropoff">' + escHtml(dropLine) + '</div>';
            }
            html += '</li>';
        }
        html += '</ol>';
        return html;
    }

    function renderFunnelSummary(summary) {
        if (!summary || summary.total_cr === null || summary.total_cr === undefined) {
            return '<span class="slimstat-gf-summary slimstat-gf-summary--empty">' +
                escHtml(__('No visitors matched in this date range', 'wp-slimstat')) + '</span>';
        }
        var cr = Number(summary.total_cr);
        var crLabel = formatPercent(cr);
        var stepCount = Number(summary.step_count) || 0;
        var unreachable = Number(summary.unreachable_count) || 0;
        var isHealthy100 = (cr === 100 && unreachable === 0 && stepCount > 1);

        var mainHtml;
        if (isHealthy100) {
            /* translators: %d is the step count */
            mainHtml = '<span class="slimstat-gf-summary slimstat-gf-summary--success">' +
                '<span class="slimstat-gf-summary__glyph" aria-hidden="true">✓</span> ' +
                escHtml(sprintf(__('Healthy pass-through · %d-step funnel', 'wp-slimstat'), stepCount)) + '</span>';
        } else if (unreachable > 0) {
            /* A step has no data yet — the overall rate isn't a real measurement.
               Mirror funnel-summary.php: say "pending", not a misleading 0.0%. */
            /* translators: %d is the step count */
            mainHtml = '<span class="slimstat-gf-summary">' +
                escHtml(sprintf(__('%d-step funnel · Conversion rate pending', 'wp-slimstat'), stepCount)) + '</span>';
        } else {
            /* translators: 1: number of steps, 2: conversion rate */
            mainHtml = '<span class="slimstat-gf-summary">' +
                escHtml(sprintf(__('%1$d-step funnel · %2$s%% conversion rate', 'wp-slimstat'), stepCount, crLabel)) + '</span>';
        }

        if (unreachable > 0) {
            /* translators: %d is the number of steps with no visitors in range */
            var label = sprintf(_n('%d step had no visitors in range', '%d steps had no visitors in range', unreachable, 'wp-slimstat'), unreachable);
            mainHtml += '<span class="slimstat-gf-summary slimstat-gf-summary--warn">' + escHtml(label) + '</span>';
        }
        return mainHtml;
    }

    // Per-funnel in-flight tracker. Clicking the same tab twice while the first
    // fetch is pending aborts the prior request so responses can't race and paint
    // stale markup over a newer view.
    var funnelInflight = {};

    // Remembers the funnel tab the user is viewing so a postbox "refresh" (which
    // re-renders the box with funnel[0] active and the rest as skeletons) can restore
    // it instead of bouncing the user to the first funnel. (#2)
    var lastActiveFunnelIndex = null;

    // The exact [start,end] window the server-rendered funnel resolved, exposed as
    // data attributes on the funnels card. Posting these back pins the AJAX funnel +
    // Test to the IDENTICAL window, so identical funnels share one cached result
    // instead of re-resolving the preset in a different timezone and disagreeing. (#1)
    function pinnedRange() {
        var el = document.querySelector('.slimstat-gf-funnels[data-gf-range-start]');
        if (!el) return {};
        var start = parseInt(el.getAttribute('data-gf-range-start'), 10) || 0;
        var end   = parseInt(el.getAttribute('data-gf-range-end'), 10) || 0;
        return (start > 0 && end > 0) ? { gf_utime_start: start, gf_utime_end: end } : {};
    }

    $body.on('click', '.slimstat-gf-tab', function () {
        var $tab = $(this);
        var funnelId = $tab.data('funnel-id');
        var funnelIndex = String($tab.data('funnel-index'));
        lastActiveFunnelIndex = funnelIndex;

        $tab.siblings('.slimstat-gf-tab').removeClass('is-active').attr('aria-selected', 'false');
        $tab.addClass('is-active').attr('aria-selected', 'true');

        var $card = $tab.closest('.slimstat-gf-funnels');
        $card.find('.slimstat-gf-funnel-panel').attr('hidden', true).removeClass('is-active');
        var $panel = $card.find('.slimstat-gf-funnel-panel[data-funnel-index="' + funnelIndex + '"]');
        $panel.removeAttr('hidden').addClass('is-active');

        if ($panel.attr('data-loaded') === 'true') {
            return;
        }

        if (funnelInflight[funnelId] && typeof funnelInflight[funnelId].abort === 'function') {
            funnelInflight[funnelId].abort();
        }
        $panel.attr('data-loaded', 'pending');

        // Send the on-screen date range so the funnel is computed for the window
        // the user is viewing (the backend otherwise can't know it). (#8)
        var loadRange = (typeof window.SlimStatGetTimeRangeForAjax === 'function')
            ? window.SlimStatGetTimeRangeForAjax() : {};

        // Send the active global report filters too, so a lazily-loaded funnel tab
        // honors the same filters as the server-rendered first funnel and as Goals.
        // init() ingests these from $_REQUEST['fs'] on the AJAX side. (#22)
        var loadFilters = (typeof window.SlimStatGetFiltersForAjax === 'function')
            ? window.SlimStatGetFiltersForAjax() : {};

        funnelInflight[funnelId] = post($.extend({
            action:          'slimstat_load_funnel_data',
            security:        nonce,
            funnel_id:       funnelId,
            time_range_type: loadRange.type || '',
            time_range_from: loadRange.from || '',
            time_range_to:   loadRange.to   || ''
        }, pinnedRange(), loadFilters), function (data) {
            if (!$panel.hasClass('is-active')) return;
            $panel.attr('data-loaded', 'true');
            $panel.find('.slimstat-gf-funnel-panel__meta').html(renderFunnelSummary(data.summary));
            $panel.find('.slimstat-gf-funnel-body').html(renderFunnelBody(data.steps, data.summary));
        }, function (msg) {
            if (!$panel.hasClass('is-active')) return;
            $panel.attr('data-loaded', 'false');
            $panel.find('.slimstat-gf-funnel-body').html('<p class="slimstat-gf-empty__body">' + escHtml(msg) + '</p>');
        }).always(function () {
            delete funnelInflight[funnelId];
        });
    });

    // ── Compact Funnels widget (dashboard / shortcode) tab switching ──
    // The compact widget (show_funnels_compact) renders every funnel as a
    // visible .slimstat-funnel-chart panel inside .slimstat-funnel-widget, plus
    // a .slimstat-funnel-wtab tab strip when there is more than one. All data is
    // server-rendered, so switching is pure show/hide — no AJAX. The class is
    // distinct from the main page's .slimstat-gf-tab so the two handlers never
    // collide on a screen (e.g. the dashboard) that loads this script.
    $('.slimstat-funnel-widget').each(function () {
        var $widget = $(this);
        // Only collapse to a single visible panel when there's a tab strip;
        // otherwise (no-JS / single funnel) leave the stacked panels as-is.
        if (!$widget.find('.slimstat-funnel-wtab').length) return;
        var $panels = $widget.find('.slimstat-funnel-chart');
        $panels.attr('hidden', true);
        $panels.filter('[data-funnel-index="0"]').removeAttr('hidden');
    });
    $body.on('click', '.slimstat-funnel-wtab', function () {
        var $tab = $(this);
        var idx  = String($tab.data('funnel-index'));
        var $widget = $tab.closest('.slimstat-funnel-widget');
        $widget.find('.slimstat-funnel-wtab').removeClass('is-active').attr('aria-selected', 'false');
        $tab.addClass('is-active').attr('aria-selected', 'true');
        $widget.find('.slimstat-funnel-chart').attr('hidden', true);
        $widget.find('.slimstat-funnel-chart[data-funnel-index="' + idx + '"]').removeAttr('hidden');
    });

    // The legacy postbox "refresh" control swaps the Funnels box .inside via an
    // in-place AJAX re-render. The card comes back with funnel[0] active and every
    // other funnel as an unloaded skeleton, and the page-load tab auto-load does not
    // re-run — so a user reading funnel 2 who clicks refresh is bounced to funnel 1
    // with their funnel blank ("refresh didn't update my funnel", #2). Watch the box
    // and, once it re-renders, re-select the remembered tab — which reuses the lazy
    // load above to repaint it. Guarded so it only acts when the active tab was reset
    // away from the user's selection (never on funnel[0], the default). Goals (no
    // tabs) already refreshes every row, so only the Funnels box needs this.
    $(function () {
        var box = document.getElementById('slim_p9_02');
        if (!box || typeof MutationObserver === 'undefined') {
            return;
        }
        var restoring = false;
        new MutationObserver(function () {
            if (restoring || lastActiveFunnelIndex === null || lastActiveFunnelIndex === '0') {
                return;
            }
            var $tab = $(box).find('.slimstat-gf-tab[data-funnel-index="' + lastActiveFunnelIndex + '"]');
            if (!$tab.length || $tab.hasClass('is-active')) {
                return;
            }
            restoring = true;
            $tab.trigger('click');
            window.setTimeout(function () { restoring = false; }, 0);
        }).observe(box, { childList: true, subtree: true });
    });

    // ============================================================
    //  Auto-suggest on value fields (reuses existing SlimStatSearchableSelect)
    // ============================================================
    //
    // Populates the value field with historical options for the selected
    // dimension via the existing `slimstat_get_filter_options` AJAX endpoint.
    // Transplanted from commit fec65cc3 — adapted to the 5.5.0 [data-role] hooks.

    var _suggestInflight = {};   // keyed by input DOM node (via data-gf-id)
    var _suggestCache    = {};   // per-dimension cache
    var _suggestIdSeq    = 0;

    function inputId(inputEl) {
        if (!inputEl) return null;
        if (!inputEl.__gfId) {
            inputEl.__gfId = 'gf-in-' + (++_suggestIdSeq);
        }
        return inputEl.__gfId;
    }

    function destroyAutoSuggest(inputEl) {
        if (!inputEl) return;
        var id = inputId(inputEl);
        if (_suggestInflight[id] && typeof _suggestInflight[id].abort === 'function') {
            _suggestInflight[id].abort();
            delete _suggestInflight[id];
        }
        if (inputEl._slimstatSearchable && typeof inputEl._slimstatSearchable.destroy === 'function') {
            inputEl._slimstatSearchable.destroy();
            inputEl._slimstatSearchable = null;
        }
    }

    function syncValueDisabledByOperator($value, operator) {
        var isEmptyOp = isValuelessOperator(operator);
        if (isEmptyOp) {
            $value.prop('disabled', true).attr('title', __('Not applicable for this operator', 'wp-slimstat')).val('');
        } else {
            $value.prop('disabled', false).removeAttr('title');
        }
        // Toggle the Value field's required marker (where present — the goal drawer)
        // so the asterisk matches when a value is actually required. No-op for funnel
        // step rows, which have no per-row label marker. (#2)
        $value.closest('.slimstat-gf-field').find('[data-role="value-required"]').css('display', isEmptyOp ? 'none' : '');
        return !isEmptyOp;
    }

    function initAutoSuggest(inputEl, dimension, operator) {
        if (!inputEl || typeof window.SlimStatSearchableSelect === 'undefined') return;

        // destroyAutoSuggest() -> widget.destroy() blanks inputEl.value. Capture
        // it first so an edited/template-prefilled value survives the rebuild and
        // the new widget can seed its display from it (otherwise the Value field
        // goes blank the moment the suggest widget mounts). (#4)
        var preserved = inputEl.value;

        destroyAutoSuggest(inputEl);

        var $input = $(inputEl);
        // For valueless operators this disables + clears the field on purpose; do
        // not restore the preserved value in that case.
        if (!syncValueDisabledByOperator($input, operator)) return;
        if (!dimension) return;

        // Restore the value before the widget mounts so seedFromInputValue() shows it.
        if (preserved !== '' && preserved != null) {
            inputEl.value = preserved;
        }

        var ajaxDimension = (dimension === 'event_notes') ? 'notes' : dimension;

        if (_suggestCache[ajaxDimension]) {
            buildSuggestWidget(inputEl, _suggestCache[ajaxDimension], ajaxDimension);
            return;
        }

        var id = inputId(inputEl);
        var timeRange = (typeof window.SlimStatGetTimeRangeForAjax === 'function')
            ? window.SlimStatGetTimeRangeForAjax() : {};

        _suggestInflight[id] = $.post(ajaxUrl, {
            action:          'slimstat_get_filter_options',
            dimension:       ajaxDimension,
            security:        $('#meta-box-order-nonce').val(),
            time_range_type: timeRange.type || '',
            time_range_from: timeRange.from || '',
            time_range_to:   timeRange.to   || ''
        }).done(function (response) {
            if (response && response.success && response.data) {
                _suggestCache[ajaxDimension] = response.data;
                buildSuggestWidget(inputEl, response.data, ajaxDimension);
            }
        }).always(function () {
            delete _suggestInflight[id];
        });
    }

    function buildSuggestWidget(inputEl, options, ajaxDimension) {
        if (!inputEl || typeof window.SlimStatSearchableSelect === 'undefined') return;
        var timeRange = (typeof window.SlimStatGetTimeRangeForAjax === 'function')
            ? window.SlimStatGetTimeRangeForAjax() : {};
        var instance = new window.SlimStatSearchableSelect(inputEl, {
            placeholder:       __('Type or pick a value', 'wp-slimstat'),
            searchPlaceholder: __('Search or type…', 'wp-slimstat'),
            // No "Apply" button here (unlike the filter bar) — a typed value is
            // saved as-is, so invite the user to type any value. (#1.1/#1.2)
            noMatchesText:     __('No matches — type any value to use it.', 'wp-slimstat'),
            noResultsText:     __('No matches', 'wp-slimstat'),
            loadingText:       __('Loading…', 'wp-slimstat'),
            // Wire server-side search so typed custom values are looked up per
            // dimension within the selected date range. Custom values still save
            // because syncTypedValue() commits typed text to the hidden input. (#1)
            serverSearchAction:    'slimstat_get_filter_options',
            serverSearchDimension: ajaxDimension,
            serverSearchNonce:     $('#meta-box-order-nonce').val(),
            serverSearchTimeRange: timeRange
        });
        instance.setOptions(options || []);
        inputEl._slimstatSearchable = instance;
    }

    // Goal drawer: dimension change → re-init suggest.
    $body.on('change', '#slimstat-gf-goal-drawer [data-role="goal-dimension"]', function () {
        initAutoSuggest(
            $goalDrawer.find('[data-role="goal-value"]')[0],
            $(this).val(),
            $goalDrawer.find('[data-role="goal-operator"]').val()
        );
    });

    // Goal drawer: operator change → maybe disable value.
    $body.on('change', '#slimstat-gf-goal-drawer [data-role="goal-operator"]', function () {
        syncValueDisabledByOperator(
            $goalDrawer.find('[data-role="goal-value"]'),
            $(this).val()
        );
    });

    // Funnel builder: per-row dimension change → re-init that row's suggest.
    $body.on('change', '.slimstat-gf-step-row [data-role="step-dimension"]', function () {
        var $row = $(this).closest('.slimstat-gf-step-row');
        initAutoSuggest(
            $row.find('[data-role="step-value"]')[0],
            $(this).val(),
            $row.find('[data-role="step-operator"]').val()
        );
    });

    // Funnel builder: per-row operator change.
    $body.on('change', '.slimstat-gf-step-row [data-role="step-operator"]', function () {
        var $row = $(this).closest('.slimstat-gf-step-row');
        syncValueDisabledByOperator(
            $row.find('[data-role="step-value"]'),
            $(this).val()
        );
    });

    // ============================================================
    //  Per-step "Test" preview
    // ============================================================

    var _testInflight = {};

    $body.on('click', '[data-action="test-step"]', function () {
        var $btn = $(this);
        var $row = $btn.closest('.slimstat-gf-step-row');
        var $result = $row.find('[data-role="test-result"]');
        var rowId = inputId($row[0]);

        var step = {
            name:      $row.find('[data-role="step-name"]').val() || __('Step', 'wp-slimstat'),
            dimension: $row.find('[data-role="step-dimension"]').val(),
            operator:  $row.find('[data-role="step-operator"]').val(),
            value:     $row.find('[data-role="step-value"]').val(),
            active:    1
        };

        if (_testInflight[rowId] && typeof _testInflight[rowId].abort === 'function') {
            _testInflight[rowId].abort();
        }
        $result.addClass('is-loading').text(__('Testing…', 'wp-slimstat'));

        // Test against the report's selected date range, not a server default. (#6)
        var testRange = (typeof window.SlimStatGetTimeRangeForAjax === 'function')
            ? window.SlimStatGetTimeRangeForAjax() : {};

        _testInflight[rowId] = $.post(ajaxUrl, $.extend({
            action:          'slimstat_test_funnel_step',
            security:        nonce,
            time_range_type: testRange.type || '',
            time_range_from: testRange.from || '',
            time_range_to:   testRange.to   || ''
        }, pinnedRange(), step)).done(function (response) {
            if (response && response.success && response.data) {
                // Show UNIQUE VISITORS — the same unit the funnel step counts — so the
                // Test previews "how many visitors this step will show", not raw
                // pageviews (which read far higher and confused QA). (#1, #3)
                var count = Number(response.data.visitors) || 0;
                /* translators: %s is a localized unique-visitor count */
                $result.removeClass('is-loading').text(
                    sprintf(_n('%s unique visitor', '%s unique visitors', count, 'wp-slimstat'), formatNumber(count))
                );
            } else {
                $result.removeClass('is-loading').text('—');
            }
        }).fail(function (_jqXHR, textStatus) {
            if (textStatus === 'abort') return;
            $result.removeClass('is-loading').text('—');
        }).always(function () {
            delete _testInflight[rowId];
        });
    });

    // ============================================================
    //  Drag-reorder steps (HTML5 DnD, no external lib)
    // ============================================================

    var _dragFrom = null;

    $body.on('dragstart', '.slimstat-gf-step-row', function (e) {
        _dragFrom = this;
        $(this).addClass('is-dragging');
        var dt = e.originalEvent && e.originalEvent.dataTransfer;
        if (dt) {
            dt.effectAllowed = 'move';
            try { dt.setData('text/plain', $(this).attr('data-step-row') || ''); } catch (_e) {}
        }
    });

    $body.on('dragend', '.slimstat-gf-step-row', function () {
        $(this).removeClass('is-dragging');
        $stepsContainer.find('.is-drag-over').removeClass('is-drag-over');
        _dragFrom = null;
    });

    $body.on('dragover', '.slimstat-gf-step-row', function (e) {
        if (!_dragFrom || _dragFrom === this) return;
        e.preventDefault();
        var dt = e.originalEvent && e.originalEvent.dataTransfer;
        if (dt) dt.dropEffect = 'move';
        $stepsContainer.find('.is-drag-over').removeClass('is-drag-over');
        $(this).addClass('is-drag-over');
    });

    $body.on('drop', '.slimstat-gf-step-row', function (e) {
        if (!_dragFrom || _dragFrom === this) return;
        e.preventDefault();
        var target = this;
        var fromIdx = $(_dragFrom).index();
        var toIdx   = $(target).index();
        if (fromIdx < toIdx) {
            $(target).after(_dragFrom);
        } else {
            $(target).before(_dragFrom);
        }
        $stepsContainer.find('.is-drag-over').removeClass('is-drag-over');
        $(_dragFrom).removeClass('is-dragging');
        _dragFrom = null;
        renumberSteps();
    });

    // Keyboard alternative to drag-reorder (HTML5 DnD is pointer-only). The drag
    // handle is focusable; ArrowUp/ArrowDown move the step one position and keep
    // focus on the handle, reusing the same DOM-move + renumberSteps() path.
    // WCAG 2.1.1 (Keyboard).
    $body.on('keydown', '[data-action="drag-step"]', function (e) {
        if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
        e.preventDefault();
        var $row  = $(this).closest('.slimstat-gf-step-row');
        var $rows = $stepsContainer.find('.slimstat-gf-step-row');
        var idx   = $rows.index($row);
        var target = idx + (e.key === 'ArrowUp' ? -1 : 1);
        if (target < 0 || target >= $rows.length) return;

        if (e.key === 'ArrowUp') {
            $row.insertBefore($rows.eq(target));
        } else {
            $row.insertAfter($rows.eq(target));
        }
        renumberSteps();

        // Announce the new position via the scoped live region, keep the position
        // on the handle label for re-focus context, and retain focus.
        var $handle = $row.find('[data-action="drag-step"]');
        /* translators: 1: new step position, 2: total steps */
        var posLabel = sprintf(__('Reorder step, position %1$d of %2$d', 'wp-slimstat'), target + 1, $rows.length);
        $handle.attr('aria-label', posLabel);
        announceBuilder(posLabel);
        $handle.trigger('focus');
    });

    // ============================================================
    //  Modal focus management — trap + restore
    // ============================================================
    //
    // The dialogs carry role="dialog" aria-modal="true" (in the partials); combined
    // with the Tab trap below and focus-restore on close, that is a screen-reader
    // -correct modal (WCAG 2.4.3). We deliberately do NOT inert a page container:
    // the dialogs are printed via admin_footer, which fires INSIDE #wpwrap, so
    // inerting #wpwrap (or #wpcontent) would disable the dialog itself.

    var _dialogOpener = null;

    function focusableIn($dialog) {
        return $dialog
            .find('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')
            .filter(':visible');
    }

    function topOpenDialog() {
        if ($confirmSheet.hasClass('is-open')) return $confirmSheet;
        if ($goalDrawer.hasClass('is-open'))   return $goalDrawer;
        if ($builder.hasClass('is-open'))      return $builder;
        return null;
    }

    function onDialogOpen() {
        // Remember the trigger so focus can return to it on close.
        _dialogOpener = document.activeElement;
    }

    function onDialogClose() {
        // Restore focus only once every dialog is closed (defensive — they don't
        // normally stack).
        if (topOpenDialog()) return;
        if (_dialogOpener && typeof _dialogOpener.focus === 'function') {
            _dialogOpener.focus();
        }
        _dialogOpener = null;
    }

    // ============================================================
    //  Keyboard — Esc closes, Tab is trapped inside the open dialog
    // ============================================================

    $(document).on('keydown', function (e) {
        var $dialog = topOpenDialog();
        if (!$dialog) return;

        if (e.key === 'Escape') {
            if ($confirmSheet.hasClass('is-open')) closeConfirmSheet();
            else if ($goalDrawer.hasClass('is-open')) closeGoalDrawer();
            else if ($builder.hasClass('is-open')) closeFunnelBuilder();
            return;
        }

        if (e.key === 'Tab') {
            var $f = focusableIn($dialog);
            if (!$f.length) { e.preventDefault(); return; }
            var first  = $f[0];
            var last   = $f[$f.length - 1];
            var active = document.activeElement;
            var inside = $dialog[0].contains(active);
            if (e.shiftKey) {
                if (!inside || active === first) { e.preventDefault(); last.focus(); }
            } else {
                if (!inside || active === last) { e.preventDefault(); first.focus(); }
            }
        }
    });

})(jQuery);
