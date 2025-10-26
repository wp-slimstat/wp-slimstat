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
        SERVER_FORMAT: 'YYYY-MM-DD',
        STORAGE_KEY: 'slimstat_session_date_range'
    };

    // Global variables
    var wpTimezone = (SlimStatDatePicker.options && SlimStatDatePicker.options.wp_timezone) ? SlimStatDatePicker.options.wp_timezone : null;
    var startOfWeek = parseInt((SlimStatDatePicker.options && SlimStatDatePicker.options.start_of_week) ? SlimStatDatePicker.options.start_of_week : 1) || 1;
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
     * Check if current page load is a refresh or new tab (not a navigation)
     * Returns true for refresh/new tab, false for normal navigation
     */
    function isPageRefreshOrNewTab() {
        // Check if performance API is available
        if (window.performance && window.performance.navigation) {
            // TYPE_RELOAD = 1 means page was refreshed
            if (window.performance.navigation.type === 1) {
                return true;
            }
        }
        
        // Check if we have a referrer from the same site
        var currentHost = window.location.hostname;
        var referrer = document.referrer;
        
        // No referrer or different host means new tab or external link
        if (!referrer || referrer.indexOf(currentHost) === -1) {
            // But we need to check if this is initial page load vs navigation
            // If sessionStorage exists but we have no referrer, it might be a refresh
            try {
                var stored = sessionStorage.getItem(CONFIG.STORAGE_KEY);
                // If we have stored data but no referrer, likely a refresh or new tab
                if (stored && !referrer) {
                    return true;
                }
            } catch (e) {
                // sessionStorage not available
            }
        }
        
        return false;
    }

    /**
     * Save date range to sessionStorage
     */
    function saveDateRangeToSession(startDate, endDate, presetType) {
        try {
            var data = {
                startDate: moment(startDate).format(CONFIG.SERVER_FORMAT),
                endDate: moment(endDate).format(CONFIG.SERVER_FORMAT),
                preset: presetType || 'custom',
                timestamp: new Date().getTime()
            };
            sessionStorage.setItem(CONFIG.STORAGE_KEY, JSON.stringify(data));
        } catch (e) {
            // sessionStorage not available or quota exceeded
            console.warn('SlimStat DatePicker: Could not save to sessionStorage', e);
        }
    }

    /**
     * Get date range from sessionStorage
     * Returns null if not found or if page was refreshed/new tab
     */
    function getDateRangeFromSession() {
        // If this is a page refresh or new tab, clear the session storage and return null
        if (isPageRefreshOrNewTab()) {
            clearDateRangeFromSession();
            return null;
        }

        try {
            var stored = sessionStorage.getItem(CONFIG.STORAGE_KEY);
            if (stored) {
                var data = JSON.parse(stored);
                // Validate the data structure
                if (data && data.startDate && data.endDate) {
                    return data;
                }
            }
        } catch (e) {
            console.warn('SlimStat DatePicker: Could not read from sessionStorage', e);
        }
        return null;
    }

    /**
     * Clear date range from sessionStorage
     */
    function clearDateRangeFromSession() {
        try {
            sessionStorage.removeItem(CONFIG.STORAGE_KEY);
        } catch (e) {
            // sessionStorage not available
        }
    }

    /**
     * Normalize date to site timezone
     */
    function normalizeDate(date, timezone) {
        if (!date) return null;
        
        var normalizedDate;
        var offset;
        if (timezone && (timezone.startsWith('UTC') || timezone.startsWith('+') || timezone.startsWith('-'))) {
            offset = timezone.startsWith('UTC') ? timezone.replace('UTC', '') : timezone;
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
        var offset;
        if (validTimezone) {
            if (validTimezone.startsWith('UTC') || validTimezone.startsWith('+') || validTimezone.startsWith('-')) {
                offset = validTimezone.startsWith('UTC') ? validTimezone.replace('UTC', '') : validTimezone;
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
        
        // For single-day selections, only show one date
        if (start === end) {
            return label + ' ' + start;
        }
        
        // For date ranges, always show both dates
        return label + ' ' + start + ' – ' + end;
    }

    /**
     * Get current date range from sessionStorage, URL, or default
     */
    function getCurrentDateRange() {
        var urlParams = new URLSearchParams(window.location.search);
        var type = urlParams.get('type');
        var fromParam = urlParams.get('from');
        var toParam = urlParams.get('to');
        var presetLabel, presetRanges, range, localTime, fromDate, toDate, sessionData, startDate, endDate;
        var result;
        
        // Priority 1: Check URL parameters (user explicitly set via URL)
        if (type || fromParam || toParam) {
            // If type parameter exists, get the preset range
            if (type && type !== 'custom') {
                presetLabel = getPresetLabel(type);
                if (presetLabel) {
                    presetRanges = getPresetRanges();
                    range = presetRanges[presetLabel];
                    if (range && Array.isArray(range) && range.length === 2) {
                        // Ensure we're creating independent moment object clones
                        result = {
                            startDate: moment(range[0]).clone(),
                            endDate: moment(range[1]).clone(),
                            preset: type
                        };
                        return result;
                    }
                }
            }
            
            // Fallback to from/to parameters with site timezone normalization
            if (fromParam || toParam) {
                localTime = getLocalTime();
                fromDate = fromParam
                    ? normalizeDate(moment(fromParam, CONFIG.SERVER_FORMAT), validTimezone)
                    : normalizeDate(localTime.clone().subtract(27, 'days'), validTimezone);
                toDate = toParam
                    ? normalizeDate(moment(toParam, CONFIG.SERVER_FORMAT), validTimezone)
                    : normalizeDate(localTime.clone(), validTimezone);
                return {
                    startDate: fromDate,
                    endDate: toDate,
                    preset: 'custom'
                };
            }
        }
        
        // Priority 2: Check sessionStorage (persisted during navigation)
        sessionData = getDateRangeFromSession();
        if (sessionData) {
            // If we have session data and no URL params, use session data
            localTime = getLocalTime();
            startDate = normalizeDate(moment(sessionData.startDate, CONFIG.SERVER_FORMAT), validTimezone);
            endDate = normalizeDate(moment(sessionData.endDate, CONFIG.SERVER_FORMAT), validTimezone);
            
            return {
                startDate: startDate,
                endDate: endDate,
                preset: sessionData.preset || 'custom'
            };
        }
        
        // Priority 3: Default to Last 28 Days preset
        presetLabel = getPresetLabel('last_28_days');
        presetRanges = getPresetRanges();
        range = presetRanges[presetLabel];
        if (range && Array.isArray(range) && range.length === 2) {
            return {
                startDate: range[0],
                endDate: range[1],
                preset: 'last_28_days'
            };
        }
        
        // Final fallback
        localTime = getLocalTime();
        return {
            startDate: normalizeDate(localTime.clone().subtract(27, 'days'), validTimezone),
            endDate: normalizeDate(localTime.clone(), validTimezone),
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
        presetType = presetType || null;
        var url = new URL(window.location);
        var paramsToDelete = [];
        var i, entry, key, value;
        
        // Clear existing date-related parameters
        url.searchParams.delete('from');
        url.searchParams.delete('to');
        url.searchParams.delete('type');
        
        // Remove existing SlimStat date filters
        var entries = [];
        var iterator = url.searchParams.entries();
        var result = iterator.next();
        while (!result.done) {
            entries.push(result.value);
            result = iterator.next();
        }
        
        for (i = 0; i < entries.length; i++) {
            entry = entries[i];
            key = entry[0];
            value = entry[1];
            if (key.indexOf('fs[') === 0 && (key.indexOf('strtotime') !== -1 || key.indexOf('interval') !== -1)) {
                paramsToDelete.push(key);
            }
        }
        
        for (i = 0; i < paramsToDelete.length; i++) {
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
        presetType = presetType || null;
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
        var $picker;
        
        if (!$dateFilters.length) {
            console.warn('SlimStat DatePicker: #slimstat-date-filters not found');
            return null;
        }

        // The date range picker should already be in the HTML from PHP
        $picker = $dateFilters.find('.slimstat-date-range-picker');
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
        var $button, $input, ranges, currentRange, datePickerOptions, presetLabel, initialLabel, displayLabel;
        
        if (!$picker) return;

        $button = $picker.find(CONFIG.SELECTORS.dateButton);
        $input = $picker.find(CONFIG.SELECTORS.dateInput);

        // Validate timezone
        if (wpTimezone && (wpTimezone.startsWith('+') || wpTimezone.startsWith('-'))) {
            validTimezone = 'UTC' + wpTimezone;
        } else if (!moment.tz || !moment.tz.zone(validTimezone)) {
            validTimezone = 'UTC';
        }

        ranges = getPresetRanges();
        currentRange = getCurrentDateRange();

        // Click handler for button
        $button.on('click', function(e) {
            e.preventDefault();
            $input.trigger('click');
        });

        // Initialize daterangepicker
        datePickerOptions = {
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
            presetLabel = getPresetLabel(currentRange.preset);
            if (presetLabel) {
                datePickerOptions.chosenLabel = presetLabel;
            }
        }

        $input.daterangepicker(datePickerOptions);

        // Set initial button label based on current range
        initialLabel = (currentRange.preset && currentRange.preset !== 'custom') 
            ? getPresetLabel(currentRange.preset) 
            : SlimStatDatePicker.strings.custom_range;
        displayLabel = formatDateRange(currentRange.startDate, currentRange.endDate, initialLabel);
        $button.find('.date-label').text(displayLabel);

        // Add custom CSS class to the daterangepicker and handle calendar visibility
        $input.on('show.daterangepicker', function(ev, picker) {
            var buttonOffset, buttonHeight, buttonWidth, isCustomRange, $ranges, $clearWrap, $clearBtn;
            
            picker.container.addClass(CONFIG.CLASSES.daterangepicker);
            $button.addClass(CONFIG.CLASSES.active);
            $button.attr('aria-expanded', 'true');

            // Position the dropdown below the button
            buttonOffset = $button.offset();
            buttonHeight = $button.outerHeight();
            buttonWidth = $button.outerWidth();
            
            picker.container.css({
                'top': buttonOffset.top + buttonHeight + 4,
                'z-index': 9999
            });

            // Check if current selection is a custom range
            isCustomRange = currentRange.preset === 'custom';
            
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
            $ranges = picker.container.find('.ranges');
            if ($ranges.length && picker.container.find(CONFIG.SELECTORS.clearCacheBtn).length === 0) {
                $clearWrap = $('<div class="slimstat-clear-cache-wrap" style="padding:8px 12px 12px;">');
                $clearBtn = $('<button type="button" class="button button-secondary" id="slimstat-clear-cache"></button>')
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
            var displayLabel, presetType, targetUrl;

            // Update button label
            displayLabel = formatDateRange(startDate, endDate, chosenLabel);
            $button.find('.date-label').text(displayLabel);

            // Determine preset type from chosen label
            presetType = detectPresetType(chosenLabel);

            // Save to sessionStorage for persistence during navigation
            saveDateRangeToSession(startDate, endDate, presetType);

            // Generate the proper SlimStat URL with date filters
            targetUrl = generateSlimStatUrl(startDate, endDate, presetType);
            
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
                var nextIndex, prevIndex;
                
                switch(e.key) {
                    case 'ArrowDown':
                        e.preventDefault();
                        nextIndex = (currentIndex + 1) % $items.length;
                        $items.eq(nextIndex).focus();
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        prevIndex = currentIndex === 0 ? $items.length - 1 : currentIndex - 1;
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
            var $form, startDate, endDate, intervalDays;
            
            // Update hidden form fields if they exist
            $form = $(CONFIG.SELECTORS.dateForm);
            
            // Remove existing date filter inputs
            $form.find('input[name*="fs["]').filter(function() {
                return this.name.indexOf('day') !== -1 || this.name.indexOf('month') !== -1 || this.name.indexOf('year') !== -1 || this.name.indexOf('interval') !== -1;
            }).remove();

            // Add new date range as interval filter (SlimStat style)
            startDate = moment(data.startDate);
            endDate = moment(data.endDate);
            intervalDays = endDate.diff(startDate, 'days') + 1;

            // Add hidden inputs for the new date range
            $form.append('<input type="hidden" name="fs[strtotime]" value="equals ' + data.endDate + '" />');
            $form.append('<input type="hidden" name="fs[interval]" value="equals -' + intervalDays + '" />');
        });
    }

    /**
     * Intercept clicks on Slimstat menu links to apply stored date range
     */
    function interceptSlimstatMenuClicks() {
        // Listen for clicks on Slimstat admin menu items
        $(document).on('click', 'a[href*="page=slimview"], a[href*="page=slim"]', function(e) {
            var $link = $(this);
            var href = $link.attr('href');
            
            // Skip if this is not a Slimstat page or if it's a settings page
            if (!href || href.indexOf('slim') === -1 || href.indexOf('setting') !== -1) {
                return true; // Allow normal navigation
            }
            
            // Check if we have a stored date range in sessionStorage
            var sessionData = getDateRangeFromSession();
            if (!sessionData) {
                return true; // No stored date, allow normal navigation
            }
            
            // Parse the current link URL
            var linkUrl;
            try {
                // Handle relative URLs
                if (href.indexOf('http') === 0) {
                    linkUrl = new URL(href);
                } else {
                    linkUrl = new URL(href, window.location.origin);
                }
            } catch (ex) {
                return true; // Invalid URL, allow normal navigation
            }
            
            // Check if the link already has date parameters
            var hasDateParams = linkUrl.searchParams.has('type') || 
                               linkUrl.searchParams.has('from') || 
                               linkUrl.searchParams.has('to');
            
            // If link already has date params, don't override
            if (hasDateParams) {
                return true; // Allow normal navigation
            }
            
            // Apply the stored date range to the link
            if (sessionData.preset && sessionData.preset !== 'custom') {
                linkUrl.searchParams.set('type', sessionData.preset);
            } else {
                linkUrl.searchParams.set('from', sessionData.startDate);
                linkUrl.searchParams.set('to', sessionData.endDate);
                linkUrl.searchParams.set('type', 'custom');
            }
            
            // Prevent default navigation and redirect with date parameters
            e.preventDefault();
            window.location.href = linkUrl.toString();
            return false;
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
        interceptSlimstatMenuClicks();
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
