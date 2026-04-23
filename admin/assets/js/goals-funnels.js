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

    // wp.i18n is a declared script dependency; fallback object keeps the module
    // working if it ever loads out-of-order (e.g., Customize preview).
    var _i18n = (window.wp && window.wp.i18n) ? window.wp.i18n : {
        __: function (s) { return s; },
        _n: function (s, p, n) { return n === 1 ? s : p; },
        sprintf: function () {
            var args = arguments, i = 0;
            return String(args[0]).replace(/%(?:(\d+)\$)?[ds]/g, function (_m, pos) {
                return args[pos ? parseInt(pos, 10) : ++i];
            });
        }
    };
    function __(str)                { return _i18n.__(str, 'wp-slimstat'); }
    function _n(single, plural, n)  { return _i18n._n(single, plural, n, 'wp-slimstat'); }
    var sprintf = _i18n.sprintf;

    // Defaults derived from a WordPress-ecosystem audit of dominant plugins
    // and their canonical permalinks (see jaan-to/outputs/research/21-data-
    // funnel-templates-wordpress-archetypes-final.md). WooCommerce gets two
    // slots because it powers ~8% of all sites with unusually stable default
    // page URLs. The lead-form template is a starter for brochureware /
    // agency / consultant sites where the success URL varies — users will
    // likely tweak the value field.
    var FUNNEL_TEMPLATES = {
        store_checkout: {
            name: __('Store checkout'),
            steps: [
                { name: __('Shop'),           dimension: 'resource', operator: 'contains', value: '/shop' },
                { name: __('Cart'),           dimension: 'resource', operator: 'contains', value: '/cart' },
                { name: __('Checkout'),       dimension: 'resource', operator: 'contains', value: '/checkout' },
                { name: __('Order received'), dimension: 'resource', operator: 'contains', value: '/order-received/' }
            ]
        },
        store_browse_to_purchase: {
            name: __('Store browse to purchase'),
            steps: [
                { name: __('Shop'),           dimension: 'resource', operator: 'contains', value: '/shop' },
                { name: __('Product'),        dimension: 'resource', operator: 'contains', value: '/product/' },
                { name: __('Cart'),           dimension: 'resource', operator: 'contains', value: '/cart' },
                { name: __('Checkout'),       dimension: 'resource', operator: 'contains', value: '/checkout' },
                { name: __('Order received'), dimension: 'resource', operator: 'contains', value: '/order-received/' }
            ]
        },
        lead_form: {
            name: __('Lead form submission'),
            steps: [
                { name: __('Service page'),   dimension: 'resource', operator: 'contains', value: '/services' },
                { name: __('Contact page'),   dimension: 'resource', operator: 'contains', value: '/contact' },
                { name: __('Thank-you page'), dimension: 'resource', operator: 'contains', value: '/thank-you' }
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

    // Mirrors number_format_i18n for the common case; server-rendered numbers
    // remain authoritative, this only runs for lazy-loaded funnel tabs.
    function formatNumber(n) {
        return (Number(n) || 0).toLocaleString();
    }

    function post(data, onSuccess, onError) {
        return $.post(ajaxUrl, data, function (response) {
            if (response && response.success) {
                if (onSuccess) onSuccess(response.data || {});
            } else {
                var msg = (response && response.data && response.data.message) || __('Request failed.');
                if (onError) onError(msg); else window.alert(msg);
            }
        }).fail(function () {
            if (onError) onError(__('Network error.')); else window.alert(__('Network error.'));
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
        $confirmSheet.find('[data-role="confirm-title"]').text(opts.title || __('Delete this?'));
        $confirmSheet.find('[data-role="confirm-body"]').text(opts.body || '');
        $confirmSheet.find('[data-role="confirm-warning"]').text(
            opts.warning || __('Historical data stays — only the definition is removed. You can always rebuild it.')
        );
        $confirmSheet.find('[data-role="confirm-cancel"]').text(opts.cancelLabel || __('Cancel'));
        $confirmSheet.find('[data-role="confirm-destructive"]').text(opts.destructiveLabel || __('Delete'));
        $confirmSheet.addClass('is-open').attr('aria-hidden', 'false');
        confirmHandler = opts.onConfirm || null;
        setTimeout(function () {
            $confirmSheet.find('[data-role="confirm-destructive"]').trigger('focus');
        }, 0);
    }

    function closeConfirmSheet() {
        $confirmSheet.removeClass('is-open').attr('aria-hidden', 'true');
        confirmHandler = null;
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
            $err.text(__('Goal name is required.')).attr('hidden', false);
            return;
        }

        var paused = $goalDrawer.find('[data-role="goal-paused"]').is(':checked');
        var data = {
            action:    'slimstat_save_goal',
            security:  nonce,
            id:        $goalDrawer.find('[data-role="goal-id"]').val(),
            name:      name,
            dimension: $goalDrawer.find('[data-role="goal-dimension"]').val(),
            operator:  $goalDrawer.find('[data-role="goal-operator"]').val(),
            value:     $goalDrawer.find('[data-role="goal-value"]').val(),
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
            title:            __('Delete goal?'),
            body:             goalName
                /* translators: %s is the goal name */
                ? sprintf(__('Delete "%s"?'), goalName)
                : __('Delete this goal?'),
            warning:          __('Historical data stays — only the goal definition is removed. You can always rebuild it.'),
            cancelLabel:      __('Keep goal'),
            destructiveLabel: __('Delete goal'),
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

    function renderStepRow(index, step) {
        step = step || { name: '', dimension: 'resource', operator: 'contains', value: '' };
        var tpl = $stepTemplate[0] ? $stepTemplate[0].content.cloneNode(true) : null;
        if (!tpl) return null;
        var $row = $(tpl).find('[data-step-row]').attr('data-step-row', index);
        /* translators: %d is the step number (1–5) */
        $row.find('[data-role="step-num"]').text(sprintf(__('Step %d'), index + 1));
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
            $(this).find('[data-role="step-num"]').text(sprintf(__('Step %d'), idx + 1));
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
        setTimeout(function () {
            $builder.find('[data-role="funnel-name"]').trigger('focus');
        }, 0);
    }

    function closeFunnelBuilder() {
        destroyStepRowsAutoSuggest();
        $builder.removeClass('is-open').attr('aria-hidden', 'true');
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

    $body.on('click', '[data-action="add-funnel-step"]', function () {
        var count = $stepsContainer.find('.slimstat-gf-step-row').length;
        if (count >= 5) return;
        var $row = renderStepRow(count, null);
        if ($row) $stepsContainer.append($row);
        renumberSteps();
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
    });

    $body.on('click', '[data-action="save-funnel"]', function () {
        var $err = $builder.find('[data-role="builder-error"]');
        var funnelName = $builder.find('[data-role="funnel-name"]').val();
        if (!funnelName || !String(funnelName).trim()) {
            $err.text(__('Funnel name is required.')).attr('hidden', false);
            return;
        }

        var steps = [];
        $stepsContainer.find('.slimstat-gf-step-row').each(function () {
            var $row = $(this);
            steps.push({
                name:      $row.find('[data-role="step-name"]').val(),
                dimension: $row.find('[data-role="step-dimension"]').val(),
                operator:  $row.find('[data-role="step-operator"]').val(),
                value:     $row.find('[data-role="step-value"]').val(),
                active:    1
            });
        });

        if (steps.length < 2 || steps.length > 5) {
            $err.text(__('Funnels need between 2 and 5 steps.')).attr('hidden', false);
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
            title:            __('Delete funnel?'),
            body:             funnelName
                /* translators: %s is the funnel name */
                ? sprintf(__('Delete "%s"?'), funnelName)
                : __('Delete this funnel?'),
            warning:          __('Historical data stays — only the funnel definition is removed. You can always rebuild it from the same goals.'),
            cancelLabel:      __('Keep funnel'),
            destructiveLabel: __('Delete funnel'),
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
            return '<p class="slimstat-gf-empty__body">' + escHtml(__('No data yet for this funnel.')) + '</p>';
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
            var pctLabel = (Math.round(pct * 10) / 10);
            var stepCls = unreachable ? 'slimstat-gf-step slimstat-gf-step--unreachable' : 'slimstat-gf-step';

            html += '<li class="' + stepCls + '" data-step="' + stepNum + '">';
            html += '<div class="slimstat-gf-step__head">';
            html += '<span class="slimstat-gf-step__name">' + escHtml(step.name || '') + '</span>';
            html += '<span class="slimstat-gf-step__count">';
            html += formatNumber(visitors);
            html += ' <span class="slimstat-gf-step__pct">(' + escHtml(String(pctLabel)) + '%)</span>';
            html += '</span></div>';
            html += '<div class="slimstat-gf-step__track" role="presentation">';
            html += '<div class="slimstat-gf-step__fill" data-step="' + stepNum + '" style="width:' + width + '%;"';
            html += ' role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + Math.round(pct) + '"';
            /* translators: 1: step name, 2: visitor count */
            var ariaLabel = sprintf(__('%1$s: %2$s visitors'), step.name || '', formatNumber(visitors));
            html += ' aria-label="' + escHtml(ariaLabel) + '"></div>';
            html += '</div>';
            if (unreachable) {
                html += '<div class="slimstat-gf-step__unreachable"><span aria-hidden="true">⚠</span> ' +
                    escHtml(__('Step unreachable · event not seen in range')) + '</div>';
            } else if (i > 0 && dropoff > 0 && steps[i - 1] && steps[i - 1].visitors) {
                var prev = Number(steps[i - 1].visitors);
                var dropoffPct = prev > 0 ? Math.round((dropoff / prev) * 1000) / 10 : 0;
                /* translators: 1: visitors dropped, 2: drop-off percentage */
                var dropLine = sprintf(__('↓ %1$s dropped (%2$s%%)'), formatNumber(dropoff), dropoffPct);
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
                escHtml(__('No visitors matched in this date range')) + '</span>';
        }
        var cr = Number(summary.total_cr);
        var crLabel = (cr === Math.round(cr)) ? String(cr) : cr.toFixed(1);
        var stepCount = Number(summary.step_count) || 0;
        var unreachable = Number(summary.unreachable_count) || 0;
        var isHealthy100 = (cr === 100 && unreachable === 0 && stepCount > 1);

        var mainHtml;
        if (isHealthy100) {
            /* translators: %d is the step count */
            mainHtml = '<span class="slimstat-gf-summary slimstat-gf-summary--success">' +
                '<span class="slimstat-gf-summary__glyph" aria-hidden="true">✓</span> ' +
                escHtml(sprintf(__('Healthy pass-through · %d-step funnel'), stepCount)) + '</span>';
        } else {
            /* translators: 1: number of steps, 2: conversion rate */
            mainHtml = '<span class="slimstat-gf-summary">' +
                escHtml(sprintf(__('%1$d-step funnel · %2$s%% conversion rate'), stepCount, crLabel)) + '</span>';
        }

        if (unreachable > 0) {
            /* translators: %d is the number of unreachable steps */
            var label = sprintf(_n('%d step unreachable', '%d steps unreachable', unreachable), unreachable);
            mainHtml += '<span class="slimstat-gf-summary slimstat-gf-summary--warn">' + escHtml(label) + '</span>';
        }
        return mainHtml;
    }

    // Per-funnel in-flight tracker. Clicking the same tab twice while the first
    // fetch is pending aborts the prior request so responses can't race and paint
    // stale markup over a newer view.
    var funnelInflight = {};

    $body.on('click', '.slimstat-gf-tab', function () {
        var $tab = $(this);
        var funnelId = $tab.data('funnel-id');
        var funnelIndex = String($tab.data('funnel-index'));

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

        funnelInflight[funnelId] = post({
            action:    'slimstat_load_funnel_data',
            security:  nonce,
            funnel_id: funnelId
        }, function (data) {
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
        var isEmptyOp = (operator === 'is_empty' || operator === 'is_not_empty');
        if (isEmptyOp) {
            $value.prop('disabled', true).attr('title', __('Not applicable for this operator')).val('');
        } else {
            $value.prop('disabled', false).removeAttr('title');
        }
        return !isEmptyOp;
    }

    function initAutoSuggest(inputEl, dimension, operator) {
        if (!inputEl || typeof window.SlimStatSearchableSelect === 'undefined') return;

        destroyAutoSuggest(inputEl);

        var $input = $(inputEl);
        if (!syncValueDisabledByOperator($input, operator)) return;
        if (!dimension) return;

        var ajaxDimension = (dimension === 'event_notes') ? 'notes' : dimension;

        if (_suggestCache[ajaxDimension]) {
            buildSuggestWidget(inputEl, _suggestCache[ajaxDimension]);
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
                buildSuggestWidget(inputEl, response.data);
            }
        }).always(function () {
            delete _suggestInflight[id];
        });
    }

    function buildSuggestWidget(inputEl, options) {
        if (!inputEl || typeof window.SlimStatSearchableSelect === 'undefined') return;
        var instance = new window.SlimStatSearchableSelect(inputEl, {
            placeholder:       __('Select or type a value…'),
            searchPlaceholder: __('Search or type…'),
            noResultsText:     __('No matches'),
            loadingText:       __('Loading…')
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
            name:      $row.find('[data-role="step-name"]').val() || __('Step'),
            dimension: $row.find('[data-role="step-dimension"]').val(),
            operator:  $row.find('[data-role="step-operator"]').val(),
            value:     $row.find('[data-role="step-value"]').val(),
            active:    1
        };

        if (_testInflight[rowId] && typeof _testInflight[rowId].abort === 'function') {
            _testInflight[rowId].abort();
        }
        $result.addClass('is-loading').text(__('Testing…'));

        _testInflight[rowId] = $.post(ajaxUrl, $.extend({
            action:   'slimstat_test_funnel_step',
            security: nonce
        }, step)).done(function (response) {
            if (response && response.success && response.data) {
                var count = Number(response.data.visitors) || 0;
                /* translators: %s is a localized visitor count */
                $result.removeClass('is-loading').text(
                    sprintf(_n('%s match', '%s matches', count), formatNumber(count))
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

    // ============================================================
    //  Keyboard — Esc closes, Enter in sheet confirms
    // ============================================================

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            if ($confirmSheet.hasClass('is-open')) closeConfirmSheet();
            else if ($goalDrawer.hasClass('is-open')) closeGoalDrawer();
            else if ($builder.hasClass('is-open')) closeFunnelBuilder();
        }
    });

})(jQuery);
