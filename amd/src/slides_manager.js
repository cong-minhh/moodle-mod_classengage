/**
 * Slides manager with NLP generation - enterprise edition
 *
 * ARCHITECTURE:
 * - Requests enqueue work (AJAX to slides_api.php?action=generatenlp)
 * - Workers do work (adhoc task via cron)
 * - UI observes state (polling slides_api.php?action=nlpstatus)
 *
 * Key principles:
 * - Poll job status at a fixed interval (no fake progress)
 * - Stop polling immediately when status is completed or failed
 * - Disable generate button while job is pending or running
 * - Resume polling for active jobs on page load
 *
 * @module     mod_classengage/slides_manager
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/notification', 'core/str'], function($, Notification, Str) {

    // Polling interval in milliseconds.
    var POLL_INTERVAL = 1500;

    // Status messages for progress display.
    var STATUS_MESSAGES = {
        'idle': 'Ready to generate',
        'pending': 'Queued for processing...',
        'running': 'Generating questions...',
        'completed': 'Questions generated!',
        'failed': 'Generation failed'
    };

    // Progress messages based on percentage.
    var PROGRESS_MESSAGES = {
        10: 'Initializing NLP engine...',
        20: 'Analyzing slide content...',
        40: 'Extracting key concepts...',
        60: 'Processing with NLP engine...',
        90: 'Finalizing questions...',
        100: 'Complete!'
    };

    /**
     * Get progress message based on percentage.
     * @param {number} progress - Progress percentage.
     * @returns {string} Progress message.
     */
    function getProgressMessage(progress) {
        var message = 'Processing...';
        var thresholds = [10, 20, 40, 60, 90, 100];
        for (var i = thresholds.length - 1; i >= 0; i--) {
            if (progress >= thresholds[i]) {
                message = PROGRESS_MESSAGES[thresholds[i]];
                break;
            }
        }
        return message;
    }

    return {
        /** Active polling intervals by slide ID */
        activePollers: {},

        /** Configuration object */
        config: {},

        /**
         * Initialize the slides manager.
         * @param {Object} config - Configuration object with cmid.
         */
        init: function(config) {
            this.config = config || {};
            this.setupGenerateHandlers();
            this.setupResetHandlers();
            this.resumeActiveJobs();
        },

        /**
         * Resume polling for any slides with active jobs on page load.
         * This ensures progress continues to display after page refresh.
         */
        resumeActiveJobs: function() {
            var self = this;
            $('.classengage-slide-card').each(function() {
                var card = $(this);
                var status = card.data('nlp-status');
                var slideid = card.data('slideid');

                if (status === 'pending' || status === 'running') {
                    var progress = parseInt(card.data('nlp-progress') || 0, 10);
                    self.showProgressOverlay(card, status, progress);
                    self.startPolling(slideid, card);
                }
            });
        },

        /**
         * Set up click handlers for generate buttons.
         */
        setupGenerateHandlers: function() {
            var self = this;

            $(document).on('click', '.btn-generate-nlp', function(e) {
                e.preventDefault();
                var btn = $(this);
                var slideId = parseInt(btn.data('slideid'), 10);
                var card = btn.closest('.classengage-slide-card');

                // Disable button while job is pending/running (single-flight enforcement).
                if (btn.prop('disabled') || btn.hasClass('disabled')) {
                    return;
                }

                self.enqueueJob(slideId, card, btn);
            });
        },

        /**
         * Set up click handlers for reset/dismiss buttons.
         */
        setupResetHandlers: function() {
            var self = this;

            $(document).on('click', '.nlp-dismiss-btn', function(e) {
                e.preventDefault();
                var overlay = $(this).closest('.nlp-progress-overlay');
                var card = overlay.closest('.classengage-slide-card');
                var slideid = parseInt(card.data('slideid'), 10);

                // Call reset endpoint to allow retry.
                self.resetJob(slideid, card, overlay);
            });
        },

        /**
         * Send NLP generation request (synchronous - waits for full response).
         * @param {number} slideId - Slide ID.
         * @param {jQuery} card - Card element.
         * @param {jQuery} btn - Button element.
         */
        enqueueJob: function(slideId, card, btn) {
            var self = this;

            // Disable button immediately.
            btn.prop('disabled', true).addClass('disabled');

            // Show progress overlay with running state.
            this.showProgressOverlay(card, 'running', 30);

            // Make synchronous request to NLP service.
            // Longer timeout since we're waiting for the full NLP response.
            $.ajax({
                url: M.cfg.wwwroot + '/mod/classengage/slides_api.php',
                method: 'POST',
                data: {
                    action: 'generatenlp',
                    slideid: slideId,
                    sesskey: M.cfg.sesskey
                },
                dataType: 'json',
                timeout: 120000 // 2 minute timeout for NLP processing.
            }).done(function(response) {
                if (response.success && response.status === 'completed') {
                    // Success - show result and reload.
                    self.showSuccess(card, response.count);
                } else {
                    // Failed.
                    self.showError(card, response.error || 'Generation failed');
                    btn.prop('disabled', false).removeClass('disabled');
                }
            }).fail(function(xhr, status, error) {
                var errorMsg = 'Network error: ' + (error || status);
                if (status === 'timeout') {
                    errorMsg = 'Request timed out. The NLP service may be slow or unavailable.';
                }
                self.showError(card, errorMsg);
                btn.prop('disabled', false).removeClass('disabled');
            });
        },

        /**
         * Start polling job status at fixed interval.
         * @param {number} slideId - Slide ID.
         * @param {jQuery} card - Card element.
         */
        startPolling: function(slideId, card) {
            var self = this;

            // Clear any existing poller for this slide.
            if (this.activePollers[slideId]) {
                clearInterval(this.activePollers[slideId]);
            }

            this.activePollers[slideId] = setInterval(function() {
                self.pollStatus(slideId, card);
            }, POLL_INTERVAL);

            // Also poll immediately.
            this.pollStatus(slideId, card);
        },

        /**
         * Poll status endpoint for authoritative progress from database.
         * @param {number} slideId - Slide ID.
         * @param {jQuery} card - Card element.
         */
        pollStatus: function(slideId, card) {
            var self = this;

            $.ajax({
                url: M.cfg.wwwroot + '/mod/classengage/slides_api.php',
                method: 'POST',
                data: {
                    action: 'nlpstatus',
                    slideid: slideId,
                    sesskey: M.cfg.sesskey
                },
                dataType: 'json',
                timeout: 5000
            }).done(function(response) {
                if (!response.success) {
                    // Don't stop polling on transient errors.
                    return;
                }

                self.updateProgress(card, response.status, response.progress);

                // Terminal states - stop polling immediately.
                if (response.status === 'completed') {
                    self.stopPolling(slideId);
                    self.showSuccess(card, response.count);
                } else if (response.status === 'failed') {
                    self.stopPolling(slideId);
                    self.showError(card, response.error);
                }
            }).fail(function() {
                // Ignore transient network failures, keep polling.
            });
        },

        /**
         * Stop polling for a slide to avoid unnecessary server load.
         * @param {number} slideId - Slide ID.
         */
        stopPolling: function(slideId) {
            if (this.activePollers[slideId]) {
                clearInterval(this.activePollers[slideId]);
                delete this.activePollers[slideId];
            }
        },

        /**
         * Show progress overlay within the card.
         * @param {jQuery} card - Card element.
         * @param {string} status - Current status.
         * @param {number} progress - Progress percentage.
         */
        showProgressOverlay: function(card, status, progress) {
            // Remove any existing overlay.
            card.find('.nlp-progress-overlay').remove();

            var message = STATUS_MESSAGES[status] || 'Processing...';
            if (status === 'running' && progress > 0) {
                message = getProgressMessage(progress);
            }

            var overlay = $(
                '<div class="nlp-progress-overlay">' +
                    '<div class="nlp-progress-container">' +
                        '<div class="nlp-progress-icon"><i class="fa fa-magic fa-pulse"></i></div>' +
                        '<div class="nlp-progress-title">Generating Questions</div>' +
                        '<div class="nlp-progress-bar-wrapper">' +
                            '<div class="nlp-progress-bar" style="width: ' + progress + '%">' +
                                '<div class="nlp-progress-glow"></div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="nlp-progress-text">' + message + '</div>' +
                    '</div>' +
                '</div>'
            );

            card.find('.card-body').append(overlay);
        },

        /**
         * Update progress bar and message.
         * @param {jQuery} card - Card element.
         * @param {string} status - Current status.
         * @param {number} progress - Progress percentage.
         */
        updateProgress: function(card, status, progress) {
            var overlay = card.find('.nlp-progress-overlay');
            if (!overlay.length) {
                this.showProgressOverlay(card, status, progress);
                return;
            }

            overlay.find('.nlp-progress-bar').css('width', progress + '%');

            var message = STATUS_MESSAGES[status] || 'Processing...';
            if (status === 'running' && progress > 0) {
                message = getProgressMessage(progress);
            }
            overlay.find('.nlp-progress-text').text(message);
        },

        /**
         * Show success state.
         * @param {jQuery} card - Card element.
         * @param {number} count - Number of questions generated.
         */
        showSuccess: function(card, count) {
            var overlay = card.find('.nlp-progress-overlay');
            if (!overlay.length) {
                return;
            }

            overlay.find('.nlp-progress-bar').css('width', '100%');
            overlay.find('.nlp-progress-icon').html('<i class="fa fa-check-circle"></i>');
            overlay.find('.nlp-progress-title').text('Success!');
            overlay.find('.nlp-progress-text').text(count + ' questions generated');
            overlay.addClass('nlp-success');
            overlay.find('.nlp-progress-glow').remove();

            // Reload page after brief delay to show updated state.
            setTimeout(function() {
                location.reload();
            }, 1500);
        },

        /**
         * Show error state with dismiss button.
         * @param {jQuery} card - Card element.
         * @param {string} error - Error message.
         */
        showError: function(card, error) {
            var overlay = card.find('.nlp-progress-overlay');

            if (!overlay.length) {
                this.showProgressOverlay(card, 'failed', 0);
                overlay = card.find('.nlp-progress-overlay');
            }

            overlay.find('.nlp-progress-icon').html('<i class="fa fa-times-circle"></i>');
            overlay.find('.nlp-progress-title').text('Error');
            overlay.find('.nlp-progress-text').text(error || 'Generation failed');
            overlay.addClass('nlp-error');
            overlay.find('.nlp-progress-bar-wrapper').hide();
            overlay.find('.nlp-progress-glow').remove();

            // Add dismiss button if not already present.
            if (!overlay.find('.nlp-dismiss-btn').length) {
                overlay.find('.nlp-progress-container').append(
                    '<button class="btn btn-outline-danger btn-sm mt-3 nlp-dismiss-btn">Dismiss</button>'
                );
            }
        },

        /**
         * Reset a failed job to allow retry.
         * @param {number} slideid - Slide ID.
         * @param {jQuery} card - Card element.
         * @param {jQuery} overlay - Overlay element.
         */
        resetJob: function(slideid, card, overlay) {
            var self = this;

            $.ajax({
                url: M.cfg.wwwroot + '/mod/classengage/slides_api.php',
                method: 'POST',
                data: {
                    action: 'resetjob',
                    slideid: slideid,
                    sesskey: M.cfg.sesskey
                },
                dataType: 'json'
            }).done(function(response) {
                if (response.success) {
                    overlay.fadeOut(300, function() {
                        overlay.remove();
                        card.find('.btn-generate-nlp').prop('disabled', false).removeClass('disabled');
                    });
                } else {
                    Notification.addNotification({
                        message: response.error || 'Failed to reset job',
                        type: 'error'
                    });
                }
            }).fail(function() {
                // Just remove the overlay on network error.
                overlay.fadeOut(300, function() {
                    overlay.remove();
                    card.find('.btn-generate-nlp').prop('disabled', false).removeClass('disabled');
                });
            });
        }
    };
});
