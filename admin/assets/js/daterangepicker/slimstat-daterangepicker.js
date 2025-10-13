/**
 * SlimStat Date Range Picker
 * Replaces the current SlimStat date selector with a unified dropdown date-range picker
 * Based on Statistics plugin style with SlimStat customizations
 */

jQuery(document).ready(function($) {
    'use strict';

    // Configuration
    var CONFIG = {
        SELECTORS: {
            dateFilters: '#slimstat-date-filters',
            dateButton: '.slimstat-date-range-btn',
            dateInput: '.slimstat-date-range-input',
            dateForm: '#slimstat-filters-form',
            clearCacheBtn: '#slimstat-clear-cache'
        },
        CLASSES: {
            daterangepicker: 'slimstat-daterangepicker',
            active: 'active'
        },
        DATE_FORMAT: 'DD/MM/YYYY',
        SERVER_FORMAT: 'YYYY-MM-DD'
    };

    // Global variables
    var wpTimezone = SlimStatDatePicker.options && SlimStatDatePicker.options.wp_timezone ? SlimStatDatePicker.options.wp_timezone : null;
    var startOfWeek = parseInt(SlimStatDatePicker.options && SlimStatDatePicker.options.start_of_week ? SlimStatDatePicker.options.start_of_week : 1);
    var validTimezone = wpTimezone;

    // Initialize moment locale with WordPress week start
    if (typeof moment !== 'undefined') {
        moment.updateLocale('en', {
            week: {
                dow: startOfWeek
            }
        });
    }

    /**
     * Normalize date to site timezone
     */
    function normalizeDate(date, timezone) {
        if (!date) return null;
        
        var normalizedDate;
        if (timezone && (timezone.indexOf('UTC') === 0 || timezone.indexOf('+') === 0 || timezone.indexOf('-') === 0)) {
            var offset = timezone.indexOf('UTC') === 0 ? timezone.replace('UTC', '') : timezone;
            normalizedDate = moment(date).utcOffset(offset);
        } else if (moment.tz && moment.tz.zone(timezone)) {
            normalizedDate = moment(date).tz(timezone);
        } else {
            normalizedDate = moment(date).utc();
        }
        
        return normalizedDate.clone().startOf('day');
    }

    /**
     * Get local time based on WordPress timezone
     */
    function getLocalTime() {
        if (validTimezone) {
            if (validTimezone.indexOf('UTC') === 0 || validTimezone.indexOf('+') === 0 || validTimezone.indexOf('-') === 0) {
                var offset = validTimezone.indexOf('UTC') === 0 ? validTimezone.replace('UTC', '') : validTimezone;
                return moment().utcOffset(offset);
            } else if (moment.tz && moment.tz.zone(validTimezone)) {
                return moment().tz(validTimezone);
            }
        }
        return moment();
    }

    /**
     * Get preset date ranges
     */
    function getPresetRanges() {
        var localTime = getLocalTime();
        var ranges = {};
        
        ranges[SlimStatDatePicker.strings.today] = [
            normalizeDate(localTime.clone(), validTimezone),
            normalizeDate(localTime.clone(), validTimezone)
        ];
        ranges[SlimStatDatePicker.strings.yesterday] = [
            normalizeDate(localTime.clone().subtract(1, 'days'), validTimezone),
            normalizeDate(localTime.clone().subtract(1, 'days'), validTimezone)
        ];
        ranges[SlimStatDatePicker.strings.this_week] = [
            normalizeDate(localTime.clone().startOf('week'), validTimezone),
            normalizeDate(localTime.clone().endOf('week'), validTimezone)
        ];
        ranges[SlimStatDatePicker.strings.last_week] = [
            normalizeDate(localTime.clone().subtract(1, 'week').startOf('week'), validTimezone),
            normalizeDate(localTime.clone().subtract(1, 'week').endOf('week'), validTimezone)
        ];
        ranges[SlimStatDatePicker.strings.this_month] = [
            normalizeDate(localTime.clone().startOf('month'), validTimezone),
            normalizeDate(localTime.clone().endOf('month'), validTimezone)
        ];
        ranges[SlimStatDatePicker.strings.last_month] = [
            normalizeDate(localTime.clone().subtract(1, 'month').startOf('month'), validTimezone),
            normalizeDate(localTime.clone().subtract(1, 'month').endOf('month'), validTimezone)
        ];
        ranges[SlimStatDatePicker.strings.last_7_days] = [
            normalizeDate(localTime.clone().subtract(6, 'days'), validTimezone),
            normalizeDate(localTime.clone(), validTimezone)
        ];
        ranges[SlimStatDatePicker.strings.last_28_days] = [
            normalizeDate(localTime.clone().subtract(27, 'days'), validTimezone),
            normalizeDate(localTime.clone(), validTimezone)
        ];
        ranges[SlimStatDatePicker.strings.last_30_days] = [
            normalizeDate(localTime.clone().subtract(29, 'days'), validTimezone),
            normalizeDate(localTime.clone(), validTimezone)
        ];
        ranges[SlimStatDatePicker.strings.last_90_days] = [
            normalizeDate(localTime.clone().subtract(89, 'days'), validTimezone),
            normalizeDate(localTime.clone(), validTimezone)
        ];
        ranges[SlimStatDatePicker.strings.last_6_months] = [
            normalizeDate(localTime.clone().subtract(6, 'months'), validTimezone),
            normalizeDate(localTime.clone(), validTimezone)
        ];
        ranges[SlimStatDatePicker.strings.this_year] = [
            normalizeDate(localTime.clone().startOf('year'), validTimezone),
            normalizeDate(localTime.clone().endOf('year'), validTimezone)
        ];
        
        return ranges;
    }

    /**
     * Format date range for display
     */
    function formatDateRange(startDate, endDate, label) {
        if (!startDate || !endDate) return label || '';
        
        var start = moment(startDate).format(CONFIG.DATE_FORMAT);
        var end = moment(endDate).format(CONFIG.DATE_FORMAT);
        
        if (start === end) {
            return label + ' ' + start;
        }
        
        return label + ' ' + start + ' – ' + end;
    }

    /**
     * Get current date range from URL or default
     */
    function getCurrentDateRange() {
        var urlParams = new URLSearchParams(window.location.search);
        var type = urlParams.get('type');
        
        // If type parameter exists, get the preset range
        if (type && type !== 'custom') {
            var presetLabel = getPresetLabel(type);
            if (presetLabel) {
                var presetRanges = getPresetRanges();
                var range = presetRanges[presetLabel];
                if (range && Array.isArray(range) && range.length === 2) {
                    return {
                        startDate: range[0],
                        endDate: range[1],
                        preset: type
                    };
                }
            }
        }
        
        // If neither preset type nor from/to are provided, default to Last 28 Days preset
        var fromParam = urlParams.get('from');
        var toParam = urlParams.get('to');
        if (!fromParam && !toParam) {
            var presetLabel2 = getPresetLabel('last_28_days');
            var presetRanges2 = getPresetRanges();
            var range2 = presetRanges2[presetLabel2];
            if (range2 && Array.isArray(range2) && range2.length === 2) {
                return {
                    startDate: range2[0],
                    endDate: range2[1],
                    preset: 'last_28_days'
                };
            }
        }

        // Fallback to from/to parameters with site timezone normalization
        var localTime = getLocalTime();
        var fromDate = fromParam
            ? normalizeDate(moment(fromParam, CONFIG.SERVER_FORMAT), validTimezone)
            : normalizeDate(localTime.clone().subtract(27, 'days'), validTimezone);
        var toDate = toParam
            ? normalizeDate(moment(toParam, CONFIG.SERVER_FORMAT), validTimezone)
            : normalizeDate(localTime.clone(), validTimezone);
        return {
            startDate: fromDate,
            endDate: toDate,
            preset: 'custom'
        };
    }

    /**
     * Detect preset type from chosen label
     */
    function detectPresetType(chosenLabel) {
        var labelMap = {};
        labelMap[SlimStatDatePicker.strings.today] = 'today';
        labelMap[SlimStatDatePicker.strings.yesterday] = 'yesterday';
        labelMap[SlimStatDatePicker.strings.this_week] = 'this_week';
        labelMap[SlimStatDatePicker.strings.last_week] = 'last_week';
        labelMap[SlimStatDatePicker.strings.this_month] = 'this_month';
        labelMap[SlimStatDatePicker.strings.last_month] = 'last_month';
        labelMap[SlimStatDatePicker.strings.last_7_days] = 'last_7_days';
        labelMap[SlimStatDatePicker.strings.last_28_days] = 'last_28_days';
        labelMap[SlimStatDatePicker.strings.last_30_days] = 'last_30_days';
        labelMap[SlimStatDatePicker.strings.last_90_days] = 'last_90_days';
        labelMap[SlimStatDatePicker.strings.last_6_months] = 'last_6_months';
        labelMap[SlimStatDatePicker.strings.this_year] = 'this_year';
        
        return labelMap[chosenLabel] || 'custom';
    }

    /**
     * Get preset label from preset type
     */
    function getPresetLabel(presetType) {
        var typeMap = {
            'today': SlimStatDatePicker.strings.today,
            'yesterday': SlimStatDatePicker.strings.yesterday,
            'this_week': SlimStatDatePicker.strings.this_week,
            'last_week': SlimStatDatePicker.strings.last_week,
            'this_month': SlimStatDatePicker.strings.this_month,
            'last_month': SlimStatDatePicker.strings.last_month,
            'last_7_days': SlimStatDatePicker.strings.last_7_days,
            'last_28_days': SlimStatDatePicker.strings.last_28_days,
            'last_30_days': SlimStatDatePicker.strings.last_30_days,
            'last_90_days': SlimStatDatePicker.strings.last_90_days,
            'last_6_months': SlimStatDatePicker.strings.last_6_months,
            'this_year': SlimStatDatePicker.strings.this_year
        };
        
        return typeMap[presetType] || null;
    }

    /**
     * Generate SlimStat compatible URL with date filters
     */
    function generateSlimStatUrl(startDate, endDate, presetType) {
        if (typeof presetType === 'undefined') presetType = null;
        
        var url = new URL(window.location);
        
        // Clear existing date-related parameters
        url.searchParams.delete('from');
        url.searchParams.delete('to');
        url.searchParams.delete('type');
        
        // Remove existing SlimStat date filters
        var paramsToDelete = [];
        var entries = url.searchParams.entries();
        var entry = entries.next();
        while (!entry.done) {
            var key = entry.value[0];
            var value = entry.value[1];
            if (key.indexOf('fs[') === 0 && (key.indexOf('strtotime') !== -1 || key.indexOf('interval') !== -1)) {
                paramsToDelete.push(key);
            }
            entry = entries.next();
        }
        for (var i = 0; i < paramsToDelete.length; i++) {
            url.searchParams.delete(paramsToDelete[i]);
        }
        
        // Add type parameter
        if (presetType && presetType !== 'custom') {
            url.searchParams.set('type', presetType);
        } else {
            // For custom ranges, add from/to parameters
            url.searchParams.set('from', moment(startDate).format(CONFIG.SERVER_FORMAT));
            url.searchParams.set('to', moment(endDate).format(CONFIG.SERVER_FORMAT));
            url.searchParams.set('type', 'custom');
        }
        
        return url.toString();
    }

    /**
     * Update URL parameters with new date range (for history management)
     */
    function updateURL(startDate, endDate, presetType) {
        if (typeof presetType === 'undefined') presetType = null;
        
        var url = new URL(window.location);
        
        // Clear existing date-related parameters
        url.searchParams.delete('from');
        url.searchParams.delete('to');
        url.searchParams.delete('type');
        
        // Add type parameter
        if (presetType && presetType !== 'custom') {
            url.searchParams.set('type', presetType);
        } else {
            // For custom ranges, add from/to parameters
            url.searchParams.set('from', moment(startDate).format(CONFIG.SERVER_FORMAT));
            url.searchParams.set('to', moment(endDate).format(CONFIG.SERVER_FORMAT));
            url.searchParams.set('type', 'custom');
        }
        
        // Update browser history without reload
        window.history.pushState({}, '', url.toString());
        
        // Emit custom event for other components
        $(document).trigger('slimstat:dateRangeChanged', {
            startDate: moment(startDate).format(CONFIG.SERVER_FORMAT),
            endDate: moment(endDate).format(CONFIG.SERVER_FORMAT),
            presetType: presetType
        });
    }

    /**
     * Find the existing date range picker UI
     */
    function findDateRangePicker() {
        var $dateFilters = $(CONFIG.SELECTORS.dateFilters);
        if (!$dateFilters.length) {
            console.warn('SlimStat DatePicker: #slimstat-date-filters not found');
            return null;
        }

        // The date range picker should already be in the HTML from PHP
        var $picker = $dateFilters.find('.slimstat-date-range-picker');
        if (!$picker.length) {
            console.warn('SlimStat DatePicker: .slimstat-date-range-picker not found in HTML');
            console.log('Available elements in date filters:', $dateFilters.html());
            return null;
        }

        console.log('SlimStat DatePicker: Found date range picker element');

        // Make sure the legacy dropdown is hidden
        $dateFilters.find('.legacy-dropdown').hide();

        return $picker;
    }

    /**
     * Initialize the daterangepicker
     */
    function initializeDateRangePicker() {
        var $picker = findDateRangePicker();
        if (!$picker) return;

        var $button = $picker.find(CONFIG.SELECTORS.dateButton);
        var $input = $picker.find(CONFIG.SELECTORS.dateInput);

        // Validate timezone
        if (wpTimezone && (wpTimezone.indexOf('+') === 0 || wpTimezone.indexOf('-') === 0)) {
            validTimezone = 'UTC' + wpTimezone;
        } else if (!moment.tz || !moment.tz.zone(validTimezone)) {
            validTimezone = 'UTC';
        }

        var ranges = getPresetRanges();
        var currentRange = getCurrentDateRange();

        // Click handler for button
        $button.on('click', function(e) {
            e.preventDefault();
            $input.trigger('click');
        });

        // Initialize daterangepicker
        var datePickerOptions = {
            autoApply: false, // We'll handle apply logic manually for better control
            ranges: ranges,
            locale: {
                customRangeLabel: SlimStatDatePicker.strings.custom_range,
                format: CONFIG.DATE_FORMAT,
                cancelLabel: SlimStatDatePicker.strings.cancel,
                applyLabel: SlimStatDatePicker.strings.apply
            },
            startDate: currentRange.startDate,
            endDate: currentRange.endDate,
            maxDate: moment(),
            opens: 'right',
            drops: 'down',
            showCustomRangeLabel: true,
            alwaysShowCalendars: false, // Only show calendars for custom range
            parentEl: 'body', // Attach to body for better positioning
            singleDatePicker: false,
            showDropdowns: false,
            autoUpdateInput: false
        };

        // Set the chosen label if we have a preset
        if (currentRange.preset && currentRange.preset !== 'custom') {
            var presetLabel = getPresetLabel(currentRange.preset);
            if (presetLabel) {
                datePickerOptions.chosenLabel = presetLabel;
            }
        }

        $input.daterangepicker(datePickerOptions);

        // Set initial button label based on current range
        var initialLabel = currentRange.preset && currentRange.preset !== 'custom' 
            ? getPresetLabel(currentRange.preset) 
            : SlimStatDatePicker.strings.custom_range;
        var displayLabel = formatDateRange(currentRange.startDate, currentRange.endDate, initialLabel);
        $button.find('.date-label').text(displayLabel);

        // Add custom CSS class to the daterangepicker and handle calendar visibility
        $input.on('show.daterangepicker', function(ev, picker) {
            picker.container.addClass(CONFIG.CLASSES.daterangepicker);
            $button.addClass(CONFIG.CLASSES.active);
            $button.attr('aria-expanded', 'true');

            // Position the dropdown below the button
            var buttonOffset = $button.offset();
            var buttonHeight = $button.outerHeight();
            var buttonWidth = $button.outerWidth();
            
            picker.container.css({
                'top': buttonOffset.top + buttonHeight + 4,
                'z-index': 9999
            });

            // Check if current selection is a custom range
            var isCustomRange = currentRange.preset === 'custom';
            
            if (isCustomRange) {
                // Show calendars and buttons for custom range
                picker.container.addClass('custom-range-active');
                picker.container.find('.drp-buttons').show();
            } else {
                // Initially hide calendars and Apply/Cancel buttons (preset ranges auto-apply)
                picker.container.removeClass('custom-range-active');
                picker.container.find('.drp-buttons').hide();
            }
            
            // Add click handlers to range options to show/hide calendars
            picker.container.find('.ranges li').on('click', function() {
                var rangeText = $(this).attr('data-range-key') || $(this).text().trim();
                var customRangeLabel = SlimStatDatePicker.strings.custom_range;
                
                if (rangeText === customRangeLabel) {
                    // Show calendars for custom range
                    picker.container.addClass('custom-range-active');
                    // Show Apply/Cancel buttons for custom range
                    picker.container.find('.drp-buttons').show();
                } else {
                    // Hide calendars for preset ranges
                    picker.container.removeClass('custom-range-active');
                    // Hide Apply/Cancel buttons for preset ranges
                    picker.container.find('.drp-buttons').hide();
                    
                    // Apply the preset range immediately
                    setTimeout(function() {
                        picker.clickApply();
                    }, 200);
                }
            });

            // Inject Clear Cache button under the preset ranges list (only once per open)
            var $ranges = picker.container.find('.ranges');
            if ($ranges.length && picker.container.find(CONFIG.SELECTORS.clearCacheBtn).length === 0) {
                var $clearWrap = $('<div class="slimstat-clear-cache-wrap" style="padding:8px 12px 12px;">');
                var $clearBtn = $('<button type="button" class="button button-secondary" id="slimstat-clear-cache"></button>')
                    .text(SlimStatDatePicker.strings.clear_cache);
                $clearWrap.append($clearBtn);
                // Place it after the ranges list
                $ranges.append($clearWrap);
            }

            // No footer buttons needed - preset ranges auto-apply, custom ranges use built-in Apply/Cancel
        });

        // Handle date range application (both preset and custom ranges)
        $input.on('apply.daterangepicker', function(ev, picker) {
            var startDate = picker.startDate;
            var endDate = picker.endDate;
            var chosenLabel = picker.chosenLabel || SlimStatDatePicker.strings.custom_range;
            var customRangeLabel = SlimStatDatePicker.strings.custom_range;

            // Update button label
            var displayLabel = formatDateRange(startDate, endDate, chosenLabel);
            $button.find('.date-label').text(displayLabel);

            // Determine preset type from chosen label
            var presetType = detectPresetType(chosenLabel);

            // Generate the proper SlimStat URL with date filters
            var targetUrl = generateSlimStatUrl(startDate, endDate, presetType);
            
            // Navigate to the new URL
            window.location.href = targetUrl;
        });

        $input.on('hide.daterangepicker', function() {
            $button.removeClass(CONFIG.CLASSES.active);
            $button.attr('aria-expanded', 'false');
        });

        // Keyboard navigation
        $button.on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                $input.trigger('click');
            }
        });

        // Accessibility improvements
        $input.on('show.daterangepicker', function(ev, picker) {
            // Focus management
            picker.container.find('.ranges li:first').attr('tabindex', '0').focus();
            
            // Arrow key navigation for ranges
            picker.container.find('.ranges li').on('keydown', function(e) {
                var $items = picker.container.find('.ranges li');
                var currentIndex = $items.index(this);
                
                switch(e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        var nextIndex = (currentIndex + 1) % $items.length;
                        $items.eq(nextIndex).focus();
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        var prevIndex = currentIndex === 0 ? $items.length - 1 : currentIndex - 1;
                        $items.eq(prevIndex).focus();
                        break;
                    case 'Enter':
                        e.preventDefault();
                        $(this).click();
                        break;
                    case 'Escape':
                        e.preventDefault();
                        $input.data('daterangepicker').hide();
                        $button.focus();
                        break;
                }
            });
        });
    }


    /**
     * Handle form submission to preserve other filters
     */
    function handleFormSubmission() {
        $(document).on('slimstat:dateRangeChanged', function(event, data) {
            // Update hidden form fields if they exist
            var $form = $(CONFIG.SELECTORS.dateForm);
            
            // Remove existing date filter inputs
            $form.find('input[name*="fs["]').filter(function() {
                return this.name.indexOf('day') !== -1 || this.name.indexOf('month') !== -1 || this.name.indexOf('year') !== -1 || this.name.indexOf('interval') !== -1;
            }).remove();

            // Add new date range as interval filter (SlimStat style)
            var startDate = moment(data.startDate);
            var endDate = moment(data.endDate);
            var intervalDays = endDate.diff(startDate, 'days') + 1;

            // Add hidden inputs for the new date range
            $form.append('<input type="hidden" name="fs[strtotime]" value="equals ' + data.endDate + '" />');
            $form.append('<input type="hidden" name="fs[interval]" value="equals -' + intervalDays + '" />');
        });
    }

    // Initialize everything when DOM is ready
    function init() {
        console.log('SlimStat DatePicker: Initializing...');
        
        if (typeof moment === 'undefined') {
            console.error('SlimStat DatePicker: Moment.js is required');
            return;
        }

        if (typeof $.fn.daterangepicker === 'undefined') {
            console.error('SlimStat DatePicker: daterangepicker is required');
            return;
        }

        if (typeof SlimStatDatePicker === 'undefined') {
            console.error('SlimStat DatePicker: SlimStatDatePicker object not found');
            return;
        }

        console.log('SlimStat DatePicker: Dependencies loaded successfully');
        initializeDateRangePicker();
        handleFormSubmission();
    }

    // Initialize
    init();

    // Public API
    window.SlimStatDateRangePicker = {
        init: init,
        clearCache: function() {
            // Use existing global click handler in admin.js
            var $btn = jQuery(CONFIG.SELECTORS.clearCacheBtn);
            if ($btn.length) {
                $btn.trigger('click');
            }
        },
        updateURL: updateURL,
        getCurrentDateRange: getCurrentDateRange
    };
});
