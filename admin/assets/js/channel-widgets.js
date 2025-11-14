/**
 * Traffic Channel Report Widget JavaScript
 *
 * Handles AJAX refresh functionality for channel widgets.
 *
 * @package SlimStat
 * @since 5.1.0
 */

(function($) {
    'use strict';

    // Channel Widgets namespace
    window.SlimStatChannelWidgets = {
        /**
         * Initialize channel widget handlers
         */
        init: function() {
            this.bindRefreshHandlers();
            this.initAutoRefresh();
        },

        /**
         * Bind click handlers for widget refresh buttons
         */
        bindRefreshHandlers: function() {
            var self = this;

            // Top Channel Widget refresh
            $(document).on('click', '.slim_channel_top .postbox-refresh', function(e) {
                e.preventDefault();
                var $widget = $(this).closest('.postbox');
                self.refreshWidget($widget, 'slim_channel_top');
            });

            // Channel Distribution Widget refresh
            $(document).on('click', '.slim_channel_distribution .postbox-refresh', function(e) {
                e.preventDefault();
                var $widget = $(this).closest('.postbox');
                self.refreshWidget($widget, 'slim_channel_distribution');
            });
        },

        /**
         * Refresh a specific channel widget via AJAX
         *
         * @param {jQuery} $widget - Widget container
         * @param {string} widgetId - Widget identifier
         */
        refreshWidget: function($widget, widgetId) {
            var $inside = $widget.find('.inside');
            var self = this;

            // Add loading state
            $inside.addClass('loading');

            // Get current date range from SlimStat filters (if available)
            var dateFrom = this.getFilterValue('date_from');
            var dateTo = this.getFilterValue('date_to');

            // Prepare AJAX data
            var ajaxData = {
                action: 'slimstat_refresh_widget',
                widget_id: widgetId,
                date_from: dateFrom,
                date_to: dateTo,
                security: typeof SlimStatAdminParams !== 'undefined' && SlimStatAdminParams.widget_nonce ? SlimStatAdminParams.widget_nonce : ''
            };

            // Make AJAX request
            $.ajax({
                method: 'POST',
                url: ajaxurl,
                data: ajaxData,
                dataType: 'json',
                timeout: 10000
            })
            .done(function(response) {
                if (response.success && response.data && response.data.html) {
                    // Update widget content
                    $inside.html(response.data.html);

                    // Trigger custom event for extensions
                    $widget.trigger('slimstat_widget_refreshed', [widgetId, response.data]);
                } else {
                    self.showError($inside, response.data && response.data.message ? response.data.message : 'Failed to refresh widget');
                }
            })
            .fail(function(xhr, status, error) {
                var errorMsg = 'AJAX error: ' + status;
                if (xhr.status === 0) {
                    errorMsg = 'Network error. Please check your connection.';
                } else if (xhr.status === 403) {
                    errorMsg = 'Permission denied. Please refresh the page.';
                } else if (xhr.status === 500) {
                    errorMsg = 'Server error. Please try again later.';
                }
                self.showError($inside, errorMsg);
            })
            .always(function() {
                // Remove loading state
                $inside.removeClass('loading');
            });
        },

        /**
         * Get filter value from SlimStat's filter system
         *
         * @param {string} filterName - Filter parameter name
         * @return {string} Filter value or empty string
         */
        getFilterValue: function(filterName) {
            // Try to get from SlimStat's filter inputs
            var $input = $('[name="slimstat_filter[' + filterName + ']"]');
            if ($input.length) {
                return $input.val();
            }

            // Try to get from URL parameters
            var urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(filterName) || '';
        },

        /**
         * Show error message in widget
         *
         * @param {jQuery} $container - Widget content container
         * @param {string} message - Error message
         */
        showError: function($container, message) {
            var errorHtml = '<div class="notice notice-error inline">' +
                            '<p>' + this.escapeHtml(message) + '</p>' +
                            '</div>';
            $container.html(errorHtml);
        },

        /**
         * Escape HTML to prevent XSS
         *
         * @param {string} text - Text to escape
         * @return {string} Escaped text
         */
        escapeHtml: function(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        },

        /**
         * Initialize auto-refresh if configured
         */
        initAutoRefresh: function() {
            var self = this;

            // Check if auto-refresh is enabled (via SlimStatAdminParams)
            if (typeof SlimStatAdminParams === 'undefined' || !SlimStatAdminParams.channel_auto_refresh) {
                return;
            }

            var refreshInterval = parseInt(SlimStatAdminParams.channel_refresh_interval, 10) || 300000; // Default: 5 minutes

            // Auto-refresh all channel widgets
            setInterval(function() {
                // Only refresh if widgets are visible
                if ($('.slim_channel_top').is(':visible')) {
                    $('.slim_channel_top .postbox').each(function() {
                        self.refreshWidget($(this), 'slim_channel_top');
                    });
                }

                if ($('.slim_channel_distribution').is(':visible')) {
                    $('.slim_channel_distribution .postbox').each(function() {
                        self.refreshWidget($(this), 'slim_channel_distribution');
                    });
                }
            }, refreshInterval);
        }
    };

    // Initialize on document ready
    $(function() {
        SlimStatChannelWidgets.init();
    });

})(jQuery);

// =============================================================================
// Pro Upgrade Modal Handler (T051, T052, T053)
// =============================================================================
(function($) {
    'use strict';

    window.SlimStatProModal = {
        /**
         * Initialize modal handlers
         */
        init: function() {
            this.bindModalTriggers();
            this.bindModalClose();
        },

        /**
         * Bind click handlers to upgrade trigger buttons
         */
        bindModalTriggers: function() {
            var self = this;

            $(document).on('click', '.slimstat-upgrade-trigger', function(e) {
                e.preventDefault();

                var modalId = $(this).data('modal-id');
                if (modalId) {
                    self.showModal(modalId);
                }
            });

            // Also support click on Pro feature placeholder
            $(document).on('click', '.slimstat-pro-feature-placeholder', function(e) {
                // Only if not clicking the button directly
                if (!$(e.target).closest('.slimstat-upgrade-trigger').length) {
                    var modalId = $(this).data('modal-id');
                    if (modalId) {
                        self.showModal(modalId);
                    }
                }
            });
        },

        /**
         * Bind modal close handlers
         */
        bindModalClose: function() {
            var self = this;

            // Close button
            $(document).on('click', '.slimstat-modal-close', function(e) {
                e.preventDefault();
                var $modal = $(this).closest('.slimstat-pro-modal');
                self.hideModal($modal);
            });

            // Overlay click
            $(document).on('click', '.slimstat-modal-overlay', function(e) {
                e.preventDefault();
                var $modal = $(this).closest('.slimstat-pro-modal');
                self.hideModal($modal);
            });

            // ESC key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' || e.keyCode === 27) {
                    $('.slimstat-pro-modal:visible').each(function() {
                        self.hideModal($(this));
                    });
                }
            });
        },

        /**
         * Show modal by ID
         * @param {string} modalId - The modal element ID
         */
        showModal: function(modalId) {
            var $modal = $('#' + modalId);

            if ($modal.length) {
                $modal.fadeIn(300);
                $('body').addClass('slimstat-modal-open');

                // Trap focus within modal
                this.trapFocus($modal);

                // Track modal view (optional analytics)
                if (typeof gtag !== 'undefined') {
                    gtag('event', 'view_upgrade_modal', {
                        'event_category': 'Pro Upgrade',
                        'event_label': modalId
                    });
                }
            }
        },

        /**
         * Hide modal
         * @param {jQuery} $modal - The modal jQuery object
         */
        hideModal: function($modal) {
            $modal.fadeOut(300);
            $('body').removeClass('slimstat-modal-open');

            // Return focus to trigger button
            var modalId = $modal.attr('id');
            $('.slimstat-upgrade-trigger[data-modal-id="' + modalId + '"]').focus();
        },

        /**
         * Trap keyboard focus within modal for accessibility
         * @param {jQuery} $modal - The modal jQuery object
         */
        trapFocus: function($modal) {
            var focusableElements = $modal.find('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])');
            var firstFocusable = focusableElements.first();
            var lastFocusable = focusableElements.last();

            // Focus first element
            firstFocusable.focus();

            // Trap TAB key
            $modal.off('keydown.focustrap').on('keydown.focustrap', function(e) {
                if (e.key === 'Tab' || e.keyCode === 9) {
                    if (e.shiftKey) {
                        // Shift + Tab
                        if ($(document.activeElement).is(firstFocusable)) {
                            e.preventDefault();
                            lastFocusable.focus();
                        }
                    } else {
                        // Tab
                        if ($(document.activeElement).is(lastFocusable)) {
                            e.preventDefault();
                            firstFocusable.focus();
                        }
                    }
                }
            });
        }
    };

    // Initialize on document ready
    $(function() {
        SlimStatProModal.init();
    });

})(jQuery);
