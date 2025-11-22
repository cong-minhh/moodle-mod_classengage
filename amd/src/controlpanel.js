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
 * JavaScript for real-time instructor control panel
 *
 * @module     mod_classengage/controlpanel
 * @package
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/notification'],
    function($, Notification) {

    var pollingTimer = null;
    var pollingInterval = 1000; // 1 second
    var sessionId = null;
    var chart = null;
    var consecutiveFailures = 0;
    var warningDisplayed = false;

    // Constants
    var MAX_CONSECUTIVE_FAILURES = 5;
    var ANSWER_OPTIONS = ['A', 'B', 'C', 'D'];

    return {
        /**
         * Initialize the control panel with real-time polling
         *
         * @param {number} sid Session ID
         * @param {number} interval Polling interval in milliseconds
         */
        init: function(sid, interval) {
            // eslint-disable-next-line no-console
            console.log('Control panel init called with sessionId:', sid, 'interval:', interval);

            sessionId = sid;
            pollingInterval = interval || 1000;

            // Start polling for updates
            this.startPolling();

            // Initialize chart
            this.initChart();

            // Add cleanup on page unload
            this.setupUnloadHandler();
        },

        /**
         * Setup page unload handler to clean up polling timer
         *
         * @private
         */
        setupUnloadHandler: function() {
            var self = this;
            $(window).on('beforeunload', function() {
                self.stopPolling();
            });
        },

        /**
         * Start polling for session statistics updates
         */
        startPolling: function() {
            var self = this;

            // Poll for current question stats
            pollingTimer = setInterval(function() {
                self.updateStats();
            }, pollingInterval);

            // Get initial stats
            this.updateStats();
        },

        /**
         * Fetch and update session statistics via AJAX
         *
         * @private
         */
        updateStats: function() {
            var self = this;

            $.ajax({
                url: M.cfg.wwwroot + '/mod/classengage/ajax.php',
                method: 'POST',
                data: {
                    action: 'getstats',
                    sessionid: sessionId,
                    sesskey: M.cfg.sesskey
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Reset failure counter on success
                        consecutiveFailures = 0;
                        warningDisplayed = false;
                        self.updateDisplay(response.data);
                    } else {
                        // Server returned error
                        self.handleError();
                    }
                },
                error: function() {
                    // Network or server error
                    self.handleError();
                }
            });
        },

        /**
         * Handle AJAX errors with consecutive failure tracking
         *
         * @private
         */
        handleError: function() {
            consecutiveFailures++;

            // Display warning after threshold consecutive failures
            if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES && !warningDisplayed) {
                Notification.addNotification({
                    message: M.util.get_string('error:connectionissues', 'mod_classengage'),
                    type: 'warning'
                });
                warningDisplayed = true;
            }

            // Continue polling - don't stop on errors
        },

        /**
         * Update the display with new session data
         *
         * @param {Object} data Session statistics data
         * @param {number} data.participants Total participant count
         * @param {number} data.responses Total response count
         * @param {Object} data.distribution Answer distribution
         * @param {string} data.status Session status
         * @private
         */
        updateDisplay: function(data) {
            // Debug logging
            // eslint-disable-next-line no-console
            console.log('Updating display with data:', data);

            // Update participant count
            if (data.participants !== undefined) {
                $('#participant-count').text(data.participants);
            }

            // Update response count
            if (data.responses !== undefined) {
                var responseRate = data.participants > 0 ?
                    Math.round((data.responses / data.participants) * 100) : 0;
                $('#response-count').text(data.responses + ' / ' + data.participants);
                $('#response-rate').text(responseRate + '%');

                // Update progress bar
                $('#response-progress').css('width', responseRate + '%');
                $('#response-progress').attr('aria-valuenow', responseRate);
            }

            // Update answer distribution
            if (data.distribution) {
                // eslint-disable-next-line no-console
                console.log('Updating distribution:', data.distribution);
                this.updateDistribution(data.distribution);

                // Update chart if available
                if (chart) {
                    // eslint-disable-next-line no-console
                    console.log('Updating chart');
                    this.updateChart(data.distribution);
                } else {
                    // eslint-disable-next-line no-console
                    console.log('Chart not initialized');
                }
            }

            // Update session status
            if (data.status) {
                $('#session-status').text(data.status.charAt(0).toUpperCase() + data.status.slice(1));

                // Stop polling if session is completed
                if (data.status === 'completed') {
                    this.stopPolling();
                }
            }
        },

        /**
         * Update the answer distribution table
         *
         * @param {Object} distribution Distribution data with A, B, C, D counts
         * @param {string} distribution.correctanswer The correct answer option
         * @param {number} distribution.total Total response count
         * @private
         */
        updateDistribution: function(distribution) {
            var total = distribution.total || 0;
            var correctAnswer = distribution.correctanswer || '';

            // eslint-disable-next-line no-console
            console.log('Distribution update - total:', total, 'correctAnswer:', correctAnswer);

            ANSWER_OPTIONS.forEach(function(option) {
                var count = distribution[option] || 0;
                var percentage = total > 0 ? Math.round((count / total) * 100) : 0;
                var isCorrect = option === correctAnswer.toUpperCase();

                // eslint-disable-next-line no-console
                console.log('Option', option, '- count:', count, 'percentage:', percentage);

                // Update count
                var countElem = $('#count-' + option);
                if (countElem.length) {
                    countElem.text(count);
                } else {
                    // eslint-disable-next-line no-console
                    console.warn('Element #count-' + option + ' not found');
                }

                // Update percentage
                var percentElem = $('#percent-' + option);
                if (percentElem.length) {
                    percentElem.text(percentage + '%');
                }

                // Update progress bar
                var progressBar = $('#bar-' + option);
                if (progressBar.length) {
                    progressBar.css('width', percentage + '%');
                    progressBar.attr('aria-valuenow', percentage);
                }

                // Highlight correct answer
                var row = $('#row-' + option);
                if (row.length) {
                    if (isCorrect) {
                        row.addClass('table-success');
                        progressBar.removeClass('bg-info').addClass('bg-success');
                    } else {
                        row.removeClass('table-success');
                        progressBar.removeClass('bg-success').addClass('bg-info');
                    }
                }
            });
        },

        /**
         * Initialize Chart.js bar chart for response visualization
         *
         * @private
         */
        initChart: function() {
            // eslint-disable-next-line no-console
            console.log('initChart called');

            var ctx = document.getElementById('responseChart');
            if (!ctx) {
                // Chart element not found - gracefully degrade to table-only view
                // eslint-disable-next-line no-console
                console.log('responseChart element not found');
                return;
            }

            // eslint-disable-next-line no-console
            console.log('responseChart element found, checking for Chart.js');

            // Check if Chart.js loaded successfully (loaded via script tag in PHP)
            if (typeof window.Chart === 'undefined') {
                // Chart.js failed to load - log error and continue with table view
                // eslint-disable-next-line no-console
                console.error('Chart.js failed to load. Displaying table view only.');
                return;
            }

            // eslint-disable-next-line no-console
            console.log('Chart.js found, creating chart');

            chart = new window.Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ANSWER_OPTIONS,
                    datasets: [{
                        label: 'Responses',
                        data: [0, 0, 0, 0],
                        backgroundColor: [
                            'rgba(54, 162, 235, 0.8)',
                            'rgba(54, 162, 235, 0.8)',
                            'rgba(54, 162, 235, 0.8)',
                            'rgba(54, 162, 235, 0.8)'
                        ],
                        borderColor: [
                            'rgba(54, 162, 235, 1)',
                            'rgba(54, 162, 235, 1)',
                            'rgba(54, 162, 235, 1)',
                            'rgba(54, 162, 235, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        title: {
                            display: true,
                            text: 'Response Distribution'
                        }
                    }
                }
            });
        },

        /**
         * Update chart with new distribution data
         *
         * @param {Object} distribution Distribution data with A, B, C, D counts
         * @param {string} distribution.correctanswer The correct answer option
         * @private
         */
        updateChart: function(distribution) {
            if (!chart) {
                return;
            }

            var data = ANSWER_OPTIONS.map(function(option) {
                return distribution[option] || 0;
            });

            var correctAnswer = distribution.correctanswer || '';
            var colors = ANSWER_OPTIONS.map(function(option) {
                return option === correctAnswer.toUpperCase() ?
                    'rgba(75, 192, 192, 0.8)' : 'rgba(54, 162, 235, 0.8)';
            });

            var borderColors = ANSWER_OPTIONS.map(function(option) {
                return option === correctAnswer.toUpperCase() ?
                    'rgba(75, 192, 192, 1)' : 'rgba(54, 162, 235, 1)';
            });

            chart.data.datasets[0].data = data;
            chart.data.datasets[0].backgroundColor = colors;
            chart.data.datasets[0].borderColor = borderColors;
            chart.update('none'); // Update without animation for smoother real-time updates
        },

        /**
         * Stop the polling timer
         */
        stopPolling: function() {
            if (pollingTimer) {
                clearInterval(pollingTimer);
                pollingTimer = null;
            }
        }
    };
});
