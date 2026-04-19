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

    var FUNNEL_TEMPLATES = {
        ecommerce: {
            name: 'E-commerce checkout',
            steps: [
                { name: 'View product',  dimension: 'resource', operator: 'contains', value: '/product' },
                { name: 'Cart',          dimension: 'resource', operator: 'contains', value: '/cart' },
                { name: 'Checkout',      dimension: 'resource', operator: 'contains', value: '/checkout' },
                { name: 'Thank-you',     dimension: 'resource', operator: 'contains', value: '/thank-you' }
            ]
        },
        saas: {
            name: 'SaaS signup',
            steps: [
                { name: 'Pricing',       dimension: 'resource', operator: 'contains', value: '/pricing' },
                { name: 'Trial start',   dimension: 'resource', operator: 'contains', value: '/trial' },
                { name: 'Activation',    dimension: 'resource', operator: 'contains', value: '/onboarding' }
            ]
        },
        content: {
            name: 'Content engagement',
            steps: [
                { name: 'Landing',       dimension: 'resource', operator: 'contains', value: '/' },
                { name: 'Article',       dimension: 'resource', operator: 'contains', value: '/blog' },
                { name: 'Signup',        dimension: 'resource', operator: 'contains', value: '/signup' }
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
                var msg = (response && response.data && response.data.message) || 'Request failed.';
                if (onError) onError(msg); else window.alert(msg);
            }
        }).fail(function () {
            if (onError) onError('Network error.'); else window.alert('Network error.');
        });
    }

    // ============================================================
    //  Confirm sheet
    // ============================================================

    var $confirmSheet = $('#slimstat-gf-confirm-sheet');
    var confirmHandler = null;

    function openConfirmSheet(title, body, destructiveLabel, onConfirm) {
        if (!$confirmSheet.length) return;
        $confirmSheet.find('#slimstat-gf-confirm-title').text(title);
        $confirmSheet.find('[data-role="confirm-body"]').text(body);
        $confirmSheet.find('[data-action="confirm-destructive"]').text(destructiveLabel || 'Delete');
        $confirmSheet.addClass('is-open').attr('aria-hidden', 'false');
        confirmHandler = onConfirm;
        setTimeout(function () {
            $confirmSheet.find('[data-action="confirm-destructive"]').trigger('focus');
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

        $goalDrawer.find('[data-role="title-create"]').prop('hidden', mode === 'edit');
        $goalDrawer.find('[data-role="title-edit"]').prop('hidden', mode !== 'edit');

        $goalDrawer.find('[data-role="goal-id"]').val(goal.id || '');
        $goalDrawer.find('[data-role="goal-name"]').val(goal.name || '');
        $goalDrawer.find('[data-role="goal-dimension"]').val(goal.dimension || 'resource');
        $goalDrawer.find('[data-role="goal-operator"]').val(goal.operator || 'contains');
        $goalDrawer.find('[data-role="goal-value"]').val(goal.value || '');
        $goalDrawer.find('[data-role="goal-paused"]').prop('checked', !goal.active);
        $goalDrawer.find('[data-role="drawer-error"]').attr('hidden', true).text('');

        $goalDrawer.addClass('is-open').attr('aria-hidden', 'false');
        setTimeout(function () {
            $goalDrawer.find('[data-role="goal-name"]').trigger('focus');
        }, 0);
    }

    function closeGoalDrawer() {
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
            $err.text('Goal name is required.').attr('hidden', false);
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
        openConfirmSheet(
            'Delete goal?',
            'The goal "' + goalName + '" will be removed. Historical data is not affected.',
            'Delete goal',
            function () {
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
        );
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
        $row.find('[data-role="step-num"]').text('Step ' + (index + 1));
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
            $(this).find('[data-role="step-num"]').text('Step ' + (idx + 1));
        });
        var count = $stepsContainer.find('.slimstat-gf-step-row').length;
        $builder.find('.slimstat-gf-builder__add-step').prop('disabled', count >= 5);
        $stepsContainer.find('.slimstat-gf-step-row__remove').prop('disabled', count <= 2);
    }

    function openFunnelBuilder(mode, funnel, templateKey) {
        if (!$builder.length) return;
        funnel = funnel || null;

        $builder.find('[data-role="title-create"]').prop('hidden', mode === 'edit');
        $builder.find('[data-role="title-edit"]').prop('hidden', mode !== 'edit');
        $builder.find('[data-role="builder-error"]').attr('hidden', true).text('');

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

        $builder.addClass('is-open').attr('aria-hidden', 'false');
        setTimeout(function () {
            $builder.find('[data-role="funnel-name"]').trigger('focus');
        }, 0);
    }

    function closeFunnelBuilder() {
        $builder.removeClass('is-open').attr('aria-hidden', 'true');
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
    });

    $body.on('click', '[data-action="remove-funnel-step"]', function () {
        var count = $stepsContainer.find('.slimstat-gf-step-row').length;
        if (count <= 2) return;
        $(this).closest('.slimstat-gf-step-row').remove();
        renumberSteps();
    });

    $body.on('click', '[data-action="save-funnel"]', function () {
        var $err = $builder.find('[data-role="builder-error"]');
        var funnelName = $builder.find('[data-role="funnel-name"]').val();
        if (!funnelName || !String(funnelName).trim()) {
            $err.text('Funnel name is required.').attr('hidden', false);
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
            $err.text('Funnels need between 2 and 5 steps.').attr('hidden', false);
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
        openConfirmSheet(
            'Delete funnel?',
            'The funnel "' + funnelName + '" will be removed. Historical data is not affected.',
            'Delete funnel',
            function () {
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
        );
    });

    // ============================================================
    //  Funnel tab lazy-load
    // ============================================================

    function renderFunnelBody(steps, summary) {
        var html = '';

        if (!steps || !steps.length) {
            return '<p class="slimstat-gf-empty__body">No data yet for this funnel.</p>';
        }

        var stepOne = steps[0] && steps[0].visitors ? Number(steps[0].visitors) : 0;
        html += '<ol class="slimstat-gf-steps" role="list">';
        for (var i = 0; i < steps.length; i++) {
            var step = steps[i];
            var visitors = Number(step.visitors) || 0;
            var pct = Number(step.pct) || 0;
            var dropoff = Number(step.dropoff) || 0;
            var width = stepOne > 0 ? Math.max(2, Math.round((visitors / stepOne) * 100)) : 0;
            var stepNum = i + 1;
            var pctLabel = (Math.round(pct * 10) / 10);

            html += '<li class="slimstat-gf-step" data-step="' + stepNum + '">';
            html += '<div class="slimstat-gf-step__head">';
            html += '<span class="slimstat-gf-step__name">' + escHtml(step.name || '') + '</span>';
            html += '<span class="slimstat-gf-step__count">';
            html += formatNumber(visitors);
            html += ' <span class="slimstat-gf-step__pct">(' + escHtml(String(pctLabel)) + '%)</span>';
            html += '</span></div>';
            html += '<div class="slimstat-gf-step__track" role="presentation">';
            html += '<div class="slimstat-gf-step__fill" data-step="' + stepNum + '" style="width:' + width + '%;"';
            html += ' role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + Math.round(pct) + '"';
            html += ' aria-label="' + escHtml(step.name || '') + ': ' + formatNumber(visitors) + ' visitors"></div>';
            html += '</div>';
            if (i > 0 && dropoff > 0 && steps[i - 1] && steps[i - 1].visitors) {
                var prev = Number(steps[i - 1].visitors);
                var dropoffPct = prev > 0 ? Math.round((dropoff / prev) * 1000) / 10 : 0;
                html += '<div class="slimstat-gf-step__dropoff">↓ ' + formatNumber(dropoff);
                html += ' dropped (' + dropoffPct + '%)</div>';
            }
            html += '</li>';
        }
        html += '</ol>';
        return html;
    }

    function renderFunnelSummary(summary) {
        if (!summary || summary.total_cr === null || summary.total_cr === undefined) {
            return '<span class="slimstat-gf-summary slimstat-gf-summary--empty">No matching visitors in this date range.</span>';
        }
        var cr = Number(summary.total_cr);
        var crLabel = (cr === Math.round(cr)) ? String(cr) : cr.toFixed(1);
        return '<span class="slimstat-gf-summary">' + summary.step_count + '-step funnel · ' + crLabel + '% conversion rate</span>';
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
