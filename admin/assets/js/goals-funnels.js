/**
 * Goals & Funnels AJAX handlers for WP Slimstat admin.
 */
(function ($) {
    'use strict';

    if (typeof SlimStatAdminParams === 'undefined') return;

    var ajaxUrl = SlimStatAdminParams.ajax_url;
    var nonce   = SlimStatAdminParams.goals_nonce;

    // ---- Goals ---- //

    $(document).on('click', '.slimstat-save-goal', function () {
        var $form = $(this).closest('.slimstat-goal-form');
        var data  = {
            action:    'slimstat_save_goal',
            security:  nonce,
            name:      $form.find('[name="goal_name"]').val(),
            dimension: $form.find('[name="goal_dimension"]').val(),
            operator:  $form.find('[name="goal_operator"]').val(),
            value:     $form.find('[name="goal_value"]').val(),
            active:    1
        };

        if (!data.name) {
            alert('Goal name is required.');
            return;
        }

        $.post(ajaxUrl, data, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data && response.data.message ? response.data.message : 'Error saving goal.');
            }
        });
    });

    $(document).on('click', '.slimstat-goal-delete', function (e) {
        e.preventDefault();
        if (!confirm('Delete this goal?')) return;

        var goalId = $(this).data('goal-id');
        $.post(ajaxUrl, {
            action:   'slimstat_delete_goal',
            security: nonce,
            goal_id:  goalId
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data && response.data.message ? response.data.message : 'Error deleting goal.');
            }
        });
    });

    // ---- Funnels ---- //

    $(document).on('click', '.slimstat-save-funnel', function () {
        var $form  = $(this).closest('.slimstat-funnel-form');
        var steps  = [];

        $form.find('.slimstat-funnel-step-form').each(function () {
            var $step = $(this);
            steps.push({
                name:      $step.find('input[name*="[name]"]').val(),
                dimension: $step.find('select[name*="[dimension]"]').val(),
                operator:  $step.find('select[name*="[operator]"]').val(),
                value:     $step.find('input[name*="[value]"]').val(),
                active:    1
            });
        });

        var data = {
            action:      'slimstat_save_funnel',
            security:    nonce,
            funnel_name: $form.find('[name="funnel_name"]').val(),
            steps:       steps
        };

        if (!data.funnel_name) {
            alert('Funnel name is required.');
            return;
        }

        if (steps.length < 2) {
            alert('At least 2 steps are required.');
            return;
        }

        $.post(ajaxUrl, data, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data && response.data.message ? response.data.message : 'Error saving funnel.');
            }
        });
    });

    $(document).on('click', '.slimstat-funnel-delete', function (e) {
        e.preventDefault();
        if (!confirm('Delete this funnel?')) return;

        var funnelId = $(this).data('funnel-id');
        $.post(ajaxUrl, {
            action:    'slimstat_delete_funnel',
            security:  nonce,
            funnel_id: funnelId
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data && response.data.message ? response.data.message : 'Error deleting funnel.');
            }
        });
    });

    // Add step button
    $(document).on('click', '.slimstat-add-step', function () {
        var $steps = $(this).siblings('.slimstat-funnel-steps');
        var count  = $steps.find('.slimstat-funnel-step-form').length;

        if (count >= 5) {
            alert('Maximum 5 steps allowed.');
            return;
        }

        var $last = $steps.find('.slimstat-funnel-step-form:last');
        var $clone = $last.clone();
        var newIndex = count;

        $clone.attr('data-step', newIndex + 1);
        $clone.find('strong').text('Step ' + (newIndex + 1));
        $clone.find('input').val('');
        $clone.find('select').prop('selectedIndex', 0);

        // Update name attributes
        $clone.find('[name]').each(function () {
            this.name = this.name.replace(/\[\d+\]/, '[' + newIndex + ']');
        });

        // Add remove button if not already there
        if (!$clone.find('.slimstat-remove-step').length) {
            $clone.append(' <button type="button" class="button slimstat-remove-step">×</button>');
        }

        $steps.append($clone);
    });

    // Remove step button
    $(document).on('click', '.slimstat-remove-step', function () {
        var $steps = $(this).closest('.slimstat-funnel-steps');
        if ($steps.find('.slimstat-funnel-step-form').length <= 2) {
            alert('Minimum 2 steps required.');
            return;
        }
        $(this).closest('.slimstat-funnel-step-form').remove();

        // Re-number steps
        $steps.find('.slimstat-funnel-step-form').each(function (i) {
            $(this).attr('data-step', i + 1);
            $(this).find('strong').text('Step ' + (i + 1));
            $(this).find('[name]').each(function () {
                this.name = this.name.replace(/\[\d+\]/, '[' + i + ']');
            });
        });
    });

    // Funnel tab switching
    $(document).on('click', '.slimstat-funnel-tab', function () {
        var index = $(this).data('funnel-index');
        $('.slimstat-funnel-tab').removeClass('active');
        $(this).addClass('active');
        $('.slimstat-funnel-chart').hide();
        $('.slimstat-funnel-chart[data-funnel-index="' + index + '"]').show();
    });

    // ---- Auto-suggest for value fields ---- //

    var _currentXHR = null;
    var _optionsCache = {};

    /**
     * Initialize a SlimStatSearchableSelect on a value input element.
     * Reuses the existing slimstat_get_filter_options AJAX endpoint.
     */
    function initAutoSuggest(inputEl, dimension) {
        if (!inputEl || typeof window.SlimStatSearchableSelect === 'undefined') return;

        // Abort any in-flight request
        if (_currentXHR) { _currentXHR.abort(); _currentXHR = null; }

        // Destroy previous instance via its destroy() method
        if (inputEl._slimstatSearchable && typeof inputEl._slimstatSearchable.destroy === 'function') {
            inputEl._slimstatSearchable.destroy();
            inputEl._slimstatSearchable = null;
        }

        if (!dimension) {
            return;
        }

        // Map event_notes to notes for the AJAX endpoint
        var ajaxDimension = (dimension === 'event_notes') ? 'notes' : dimension;

        // Use cached options if available
        if (_optionsCache[ajaxDimension]) {
            buildWidget(inputEl, _optionsCache[ajaxDimension]);
            return;
        }

        // Get time range from admin.js helper
        var timeRange = typeof window.SlimStatGetTimeRangeForAjax === 'function'
            ? window.SlimStatGetTimeRangeForAjax() : {};

        // Fetch options from existing AJAX endpoint
        _currentXHR = $.post(ajaxUrl, {
            action: 'slimstat_get_filter_options',
            dimension: ajaxDimension,
            security: $('#meta-box-order-nonce').val(),
            time_range_type: timeRange.type || '',
            time_range_from: timeRange.from || '',
            time_range_to: timeRange.to || ''
        }, function (response) {
            _currentXHR = null;
            if (response.success && response.data) {
                _optionsCache[ajaxDimension] = response.data;
                buildWidget(inputEl, response.data);
            }
        });
    }

    function buildWidget(inputEl, options) {
        var instance = new window.SlimStatSearchableSelect(inputEl, {
            placeholder: 'Select or type a value...',
            searchPlaceholder: 'Search...',
            noResultsText: 'No matching values'
        });
        instance.setOptions(options);
        inputEl._slimstatSearchable = instance;
    }

    // When goal dimension changes, init auto-suggest on value field
    $(document).on('change', '[name="goal_dimension"]', function () {
        var dimension = $(this).val();
        var $valueInput = $(this).closest('.slimstat-goal-form').find('[name="goal_value"]');
        if ($valueInput.length) {
            initAutoSuggest($valueInput[0], dimension);
        }
    });

    // When funnel step dimension changes, init auto-suggest on step value field
    $(document).on('change', 'select[name*="[dimension]"]', function () {
        var dimension = $(this).val();
        var $valueInput = $(this).closest('.slimstat-funnel-step-form').find('input[name*="[value]"]');
        if ($valueInput.length) {
            initAutoSuggest($valueInput[0], dimension);
        }
    });

    // Disable value field for operators that don't need one
    $(document).on('change', '[name="goal_operator"], select[name*="[operator]"]', function () {
        var op = $(this).val();
        var $form = $(this).closest('.slimstat-goal-form, .slimstat-funnel-step-form');
        var $valueInput = $form.find('[name="goal_value"], input[name*="[value]"]');

        if (op === 'is_empty' || op === 'is_not_empty') {
            $valueInput.prop('disabled', true).val('');
        } else {
            $valueInput.prop('disabled', false);
        }
    });

})(jQuery);
