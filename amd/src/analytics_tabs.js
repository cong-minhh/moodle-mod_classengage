// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * JavaScript for analytics tab switching and state management
 *
 * This module handles:
 * - Tab click handlers for Simple and Advanced analysis tabs
 * - Smooth transition animations between tabs
 * - URL hash updates for bookmarkable tabs
 * - Lazy loading for Advanced tab data via AJAX
 * - Tab preference storage in local storage
 * - Keyboard accessibility for tab controls
 *
 * @module     mod_classengage/analytics_tabs
 * @package
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification'],
    function($, Ajax, Notification) {

    // Constants
    const TAB_SIMPLE = 'simple';
    const TAB_ADVANCED = 'advanced';
    const STORAGE_KEY = 'mod_classengage_analytics_tab';
    const TRANSITION_DURATION = 300; // Milliseconds
    const FADE_OUT_OPACITY = 0;
    const FADE_IN_OPACITY = 1;

    // Module state
    let currentTab = TAB_SIMPLE;
    let advancedDataLoaded = false;
    let sessionId = null;
    let cmId = null;

    /**
     * Initialize the tab switching functionality
     *
     * @param {number} sid Session ID
     * @param {number} cid Course module ID
     */
    const init = function(sid, cid) {
        sessionId = sid;
        cmId = cid;

        // Restore tab preference from local storage or URL hash
        restoreTabState();

        // Set up tab click handlers
        setupTabHandlers();

        // Set up keyboard accessibility
        setupKeyboardNavigation();

        // Handle browser back/forward buttons
        setupHashChangeHandler();

        // Show initial tab
        showTab(currentTab, false);
    };

    /**
     * Restore tab state from local storage or URL hash
     *
     * @private
     */
    const restoreTabState = function() {
        // Check URL hash first (takes precedence)
        const hash = window.location.hash.substring(1);
        if (hash === TAB_SIMPLE || hash === TAB_ADVANCED) {
            currentTab = hash;
            return;
        }

        // Fall back to local storage
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (stored === TAB_SIMPLE || stored === TAB_ADVANCED) {
                currentTab = stored;
            }
        } catch (e) {
            // Local storage not available or disabled - use default
            // eslint-disable-next-line no-console
            console.warn('Local storage not available:', e);
        }
    };

    /**
     * Set up click handlers for tab navigation
     *
     * @private
     */
    const setupTabHandlers = function() {
        // Simple tab click handler
        $('#tab-simple').on('click', function(e) {
            e.preventDefault();
            switchTab(TAB_SIMPLE);
        });

        // Advanced tab click handler
        $('#tab-advanced').on('click', function(e) {
            e.preventDefault();
            switchTab(TAB_ADVANCED);
        });
    };

    /**
     * Set up keyboard navigation for tabs
     *
     * @private
     */
    const setupKeyboardNavigation = function() {
        const tabs = $('#tab-simple, #tab-advanced');

        tabs.on('keydown', function(e) {
            const $this = $(this);
            let $target = null;

            // Arrow key navigation
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                e.preventDefault();

                if (e.key === 'ArrowRight') {
                    $target = $this.next('[role="tab"]');
                    if ($target.length === 0) {
                        $target = tabs.first();
                    }
                } else {
                    $target = $this.prev('[role="tab"]');
                    if ($target.length === 0) {
                        $target = tabs.last();
                    }
                }

                $target.focus().trigger('click');
            }

            // Enter or Space to activate
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                $this.trigger('click');
            }
        });
    };

    /**
     * Set up handler for browser back/forward buttons
     *
     * @private
     */
    const setupHashChangeHandler = function() {
        $(window).on('hashchange', function() {
            const hash = window.location.hash.substring(1);
            if (hash === TAB_SIMPLE || hash === TAB_ADVANCED) {
                showTab(hash, false);
            }
        });
    };

    /**
     * Switch to a different tab
     *
     * @param {string} tab Tab identifier (simple or advanced)
     * @private
     */
    const switchTab = function(tab) {
        if (tab === currentTab) {
            return; // Already on this tab
        }

        currentTab = tab;

        // Update URL hash
        updateUrlHash(tab);

        // Store preference
        storeTabPreference(tab);

        // Show the tab with animation
        showTab(tab, true);

        // Lazy load advanced data if needed
        if (tab === TAB_ADVANCED && !advancedDataLoaded) {
            loadAdvancedData();
        }
    };

    /**
     * Update URL hash for bookmarkable tabs
     *
     * @param {string} tab Tab identifier
     * @private
     */
    const updateUrlHash = function(tab) {
        // Update hash without triggering scroll or hashchange event
        if (history.pushState) {
            history.pushState(null, null, '#' + tab);
        } else {
            window.location.hash = tab;
        }
    };

    /**
     * Store tab preference in local storage
     *
     * @param {string} tab Tab identifier
     * @private
     */
    const storeTabPreference = function(tab) {
        try {
            localStorage.setItem(STORAGE_KEY, tab);
        } catch (e) {
            // Local storage not available - silently fail
            // eslint-disable-next-line no-console
            console.warn('Could not store tab preference:', e);
        }
    };

    /**
     * Show the specified tab with optional animation
     *
     * @param {string} tab Tab identifier
     * @param {boolean} animate Whether to animate the transition
     * @private
     */
    const showTab = function(tab, animate) {
        const $simpleTab = $('#tab-simple');
        const $advancedTab = $('#tab-advanced');
        const $simplePanel = $('#panel-simple');
        const $advancedPanel = $('#panel-advanced');

        // Update ARIA attributes and active states
        if (tab === TAB_SIMPLE) {
            $simpleTab.attr('aria-selected', 'true').addClass('active');
            $advancedTab.attr('aria-selected', 'false').removeClass('active');
            $simplePanel.attr('aria-hidden', 'false');
            $advancedPanel.attr('aria-hidden', 'true');
        } else {
            $simpleTab.attr('aria-selected', 'false').removeClass('active');
            $advancedTab.attr('aria-selected', 'true').addClass('active');
            $simplePanel.attr('aria-hidden', 'true');
            $advancedPanel.attr('aria-hidden', 'false');
        }

        if (animate) {
            // Fade out current panel
            const $currentPanel = tab === TAB_SIMPLE ? $advancedPanel : $simplePanel;
            const $targetPanel = tab === TAB_SIMPLE ? $simplePanel : $advancedPanel;

            $currentPanel.fadeTo(TRANSITION_DURATION, FADE_OUT_OPACITY, function() {
                $currentPanel.hide();
                $targetPanel.hide().fadeTo(TRANSITION_DURATION, FADE_IN_OPACITY, function() {
                    $targetPanel.css('opacity', ''); // Remove inline opacity
                });
            });
        } else {
            // No animation - just show/hide
            if (tab === TAB_SIMPLE) {
                $simplePanel.show();
                $advancedPanel.hide();
            } else {
                $simplePanel.hide();
                $advancedPanel.show();
            }
        }
    };

    /**
     * Lazy load Advanced tab data via AJAX
     *
     * @private
     */
    const loadAdvancedData = function() {
        // Show loading indicator
        const $advancedPanel = $('#panel-advanced');
        const $loadingIndicator = $('<div class="text-center p-5">' +
            '<div class="spinner-border text-primary" role="status">' +
            '<span class="sr-only">Loading...</span>' +
            '</div>' +
            '</div>');

        $advancedPanel.prepend($loadingIndicator);

        // Make AJAX request to load advanced data
        const promises = Ajax.call([{
            methodname: 'mod_classengage_get_advanced_analytics',
            args: {
                sessionid: sessionId,
                cmid: cmId
            }
        }]);

        promises[0].done(function(response) {
            advancedDataLoaded = true;
            $loadingIndicator.remove();

            // Populate advanced tab with data
            populateAdvancedTab(response);

        }).fail(function(error) {
            $loadingIndicator.remove();

            // Show error message
            Notification.addNotification({
                message: error.message || 'Failed to load advanced analytics data',
                type: 'error'
            });

            // eslint-disable-next-line no-console
            console.error('Failed to load advanced analytics:', error);
        });
    };

    /**
     * Populate Advanced tab with loaded data
     *
     * @param {Object} data Advanced analytics data
     * @private
     */
    const populateAdvancedTab = function(data) {
        // This function would populate the advanced tab with the loaded data
        // For now, we'll assume the data is already rendered server-side
        // And this is just a placeholder for future lazy loading implementation

        // If data needs to be rendered client-side, it would be done here
        // For example:
        // - Render concept difficulty table
        // - Initialize engagement timeline chart
        // - Render response trends
        // - Display teaching recommendations
        // - Initialize participation distribution chart

        // eslint-disable-next-line no-console
        console.log('Advanced analytics data loaded:', data);
    };

    // Public API
    return {
        init: init
    };
});
