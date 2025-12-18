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
 * Provides real-time student status monitoring, session control (pause/resume),
 * and aggregate statistics display via SSE with polling fallback.
 *
 * Requirements: 1.3, 1.4, 1.5, 5.1, 5.4, 5.5
 *
 * @module     mod_classengage/controlpanel
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/notification', 'mod_classengage/connection_manager'],
    function ($, Notification, ConnectionManager) {

        var pollingTimer = null;
        var pollingInterval = 1000; // 1 second
        var sessionId = null;
        var chart = null;
        var consecutiveFailures = 0;
        var warningDisplayed = false;
        var lastQuestionNumber = -1; // Track last known question to prevent stale updates

        // Constants
        var MAX_CONSECUTIVE_FAILURES = 5;

        var ANSWER_OPTIONS = ['A', 'B', 'C', 'D'];
        var STUDENT_STATUS_UPDATE_INTERVAL = 2000; // 2 seconds (Requirement 1.3)

        // Session state
        var isPaused = false;

        // SSE connection state
        var studentStatusTimer = null;

        // Connected students cache
        var connectedStudents = {};

        return {
            /**
             * Initialize the control panel with real-time polling and SSE
             *
             * @param {number} sid Session ID
             * @param {number} interval Polling interval in milliseconds
             */
            init: function (sid, interval) {
                // eslint-disable-next-line no-console
                console.log('Control panel init called with sessionId:', sid, 'interval:', interval);

                sessionId = sid;
                pollingInterval = interval || 1000;

                // Initialize connection manager for SSE
                this.initSSEConnection();

                // Start polling for updates (fallback and stats)
                this.startPolling();

                // Start student status polling
                this.startStudentStatusPolling();

                // Initialize chart
                this.initChart();

                // Setup pause/resume controls
                this.setupSessionControls();

                // Add cleanup on page unload
                this.setupUnloadHandler();
            },

            /**
             * Initialize SSE connection for real-time updates
             *
             * @private
             */
            initSSEConnection: function () {
                var self = this;

                // Register event handlers before connecting
                ConnectionManager.on('session_paused', function (data) {
                    self.handleSessionPaused(data);
                });

                ConnectionManager.on('session_resumed', function (data) {
                    self.handleSessionResumed(data);
                });

                ConnectionManager.on('question_broadcast', function (data) {
                    self.handleQuestionBroadcast(data);
                });

                ConnectionManager.on('state_update', function (data) {
                    self.handleStateUpdate(data);
                });

                ConnectionManager.on('statuschange', function (data) {
                    self.handleConnectionStatusChange(data);
                });

                // Try to connect via SSE
                ConnectionManager.init(sessionId, {
                    pollInterval: pollingInterval
                }).then(function () {
                    // eslint-disable-next-line no-console
                    console.log('SSE connection established for control panel');
                    return null;
                }).catch(function () {
                    // eslint-disable-next-line no-console
                    console.log('SSE not available, using polling fallback');
                    // Ensure polling is active if SSE fails
                    if (!pollingTimer) {
                        self.startPolling();
                    }
                });
            },

            /**
             * Stop polling for session statistics
             *
             * @private
             */
            stopPolling: function () {
                if (pollingTimer) {
                    clearInterval(pollingTimer);
                    pollingTimer = null;
                }
            },

            /**
             * Setup page unload handler to clean up polling timer
             *
             * @private
             */
            setupUnloadHandler: function () {
                var self = this;
                $(window).on('beforeunload', function () {
                    self.stopPolling();
                    self.stopStudentStatusPolling();
                    ConnectionManager.disconnect();
                });
            },

            /**
             * Setup pause/resume session controls
             *
             * Requirements: 1.4, 1.5
             * @private
             */
            setupSessionControls: function () {
                var self = this;

                // Pause button handler
                $(document).on('click', '#btn-pause-session', function (e) {
                    e.preventDefault();
                    self.pauseSession();
                });

                // Resume button handler
                $(document).on('click', '#btn-resume-session', function (e) {
                    e.preventDefault();
                    self.resumeSession();
                });
            },


            /**
             * Pause the current session
             *
             * Requirement: 1.4 - Freeze timer and prevent new submissions
             * @private
             */
            pauseSession: function () {
                var self = this;

                $.ajax({
                    url: M.cfg.wwwroot + '/mod/classengage/ajax.php',
                    method: 'POST',
                    data: {
                        action: 'pause',
                        sessionid: sessionId,
                        sesskey: M.cfg.sesskey
                    },
                    dataType: 'json',
                    success: function (response) {
                        if (response.success) {
                            self.handleSessionPaused(response);
                            Notification.addNotification({
                                message: M.util.get_string('sessionpaused', 'mod_classengage'),
                                type: 'info'
                            });
                        } else {
                            Notification.addNotification({
                                message: response.error || 'Failed to pause session',
                                type: 'error'
                            });
                        }
                    },
                    error: function () {
                        Notification.addNotification({
                            message: 'Network error while pausing session',
                            type: 'error'
                        });
                    }
                });
            },

            /**
             * Resume the paused session
             *
             * Requirement: 1.5 - Restore timer and re-enable submissions
             * @private
             */
            resumeSession: function () {
                var self = this;

                $.ajax({
                    url: M.cfg.wwwroot + '/mod/classengage/ajax.php',
                    method: 'POST',
                    data: {
                        action: 'resume',
                        sessionid: sessionId,
                        sesskey: M.cfg.sesskey
                    },
                    dataType: 'json',
                    success: function (response) {
                        if (response.success) {
                            self.handleSessionResumed(response);
                            Notification.addNotification({
                                message: M.util.get_string('sessionresumed', 'mod_classengage'),
                                type: 'info'
                            });
                        } else {
                            Notification.addNotification({
                                message: response.error || 'Failed to resume session',
                                type: 'error'
                            });
                        }
                    },
                    error: function () {
                        Notification.addNotification({
                            message: 'Network error while resuming session',
                            type: 'error'
                        });
                    }
                });
            },

            /**
             * Handle session paused event
             *
             * @param {Object} data Pause event data
             * @private
             */
            handleSessionPaused: function (data) {
                isPaused = true;

                // Update UI
                $('#btn-pause-session').hide();
                $('#btn-resume-session').show();
                $('#session-status').text('Paused').addClass('text-warning');
                $('#session-status-badge').removeClass('badge-success').addClass('badge-warning').text('Paused');

                // Update timer display
                if (data.timerremaining !== undefined) {
                    $('#time-display').text(this.formatTime(data.timerremaining));
                }
            },

            /**
             * Handle session resumed event
             *
             * @private
             */
            handleSessionResumed: function () {
                isPaused = false;

                // Update UI
                $('#btn-resume-session').hide();
                $('#btn-pause-session').show();
                $('#session-status').text('Active').removeClass('text-warning');
                $('#session-status-badge').removeClass('badge-warning').addClass('badge-success').text('Active');
            },

            /**
             * Handle question broadcast event
             *
             * @param {Object} data Question broadcast data
             * @private
             */
            handleQuestionBroadcast: function (data) {
                // Reset student answered status for new question
                this.resetStudentAnsweredStatus();

                // Update question progress
                if (data.questionnumber !== undefined) {
                    var newQuestion = parseInt(data.questionnumber);
                    lastQuestionNumber = newQuestion; // Update version tracker to prevent stale overwrites

                    var total = $('#question-progress').data('total') || data.questionnumber + 1;
                    $('#question-progress').text((newQuestion + 1) + ' / ' + total);
                }

                // Refresh stats
                this.updateStats();
            },

            /**
             * Handle state update from polling
             *
             * @param {Object} data State update data
             * @private
             */
            handleStateUpdate: function (data) {
                if (data.status) {
                    isPaused = (data.status === 'paused');

                    if (isPaused) {
                        $('#btn-pause-session').hide();
                        $('#btn-resume-session').show();
                    } else {
                        $('#btn-resume-session').hide();
                        $('#btn-pause-session').show();
                    }
                }
            },

            /**
             * Handle connection status change
             *
             * @param {Object} data Status change data
             * @private
             */
            handleConnectionStatusChange: function (data) {
                var statusIndicator = $('#connection-status-indicator');
                if (data.status === 'connected') {
                    statusIndicator.removeClass('text-danger text-warning').addClass('text-success');
                    statusIndicator.attr('title', 'Connected via ' + (data.transport || 'polling'));
                } else if (data.status === 'reconnecting') {
                    statusIndicator.removeClass('text-success text-danger').addClass('text-warning');
                    statusIndicator.attr('title', 'Reconnecting...');
                } else {
                    statusIndicator.removeClass('text-success text-warning').addClass('text-danger');
                    statusIndicator.attr('title', 'Disconnected');
                }
            },

            /**
             * Start polling for student status updates
             *
             * Requirement: 1.3 - Display connected students count updated every 2 seconds
             * @private
             */
            startStudentStatusPolling: function () {
                var self = this;

                // Poll for student status
                studentStatusTimer = setInterval(function () {
                    self.updateStudentStatus();
                }, STUDENT_STATUS_UPDATE_INTERVAL);

                // Get initial status
                this.updateStudentStatus();
            },

            /**
             * Stop student status polling
             *
             * @private
             */
            stopStudentStatusPolling: function () {
                if (studentStatusTimer) {
                    clearInterval(studentStatusTimer);
                    studentStatusTimer = null;
                }
            },

            /**
             * Fetch and update student connection status
             *
             * Requirements: 5.1, 5.4, 5.5
             * @private
             */
            updateStudentStatus: function () {
                var self = this;

                $.ajax({
                    url: M.cfg.wwwroot + '/mod/classengage/ajax.php',
                    method: 'POST',
                    data: {
                        action: 'getstudents',
                        sessionid: sessionId,
                        sesskey: M.cfg.sesskey
                    },
                    dataType: 'json',
                    success: function (response) {
                        if (response.success) {
                            self.updateStudentList(response.students || []);
                            self.updateAggregateStats(response.stats || {});
                        }
                    },
                    error: function () {
                        // Silent fail - stats polling will continue
                    }
                });
            },

            /**
             * Update the connected students list display
             *
             * Requirement: 5.1 - Display list of connected students with status
             * @param {Array} students Array of student objects
             * @private
             */
            updateStudentList: function (students) {
                var self = this;
                var container = $('#connected-students-list');
                if (!container.length) {
                    return;
                }

                // Update cache
                students.forEach(function (student) {
                    connectedStudents[student.userid] = student;
                });

                // Build student list HTML
                var html = '';
                if (students.length === 0) {
                    html = '<div class="text-muted p-2">No students connected</div>';
                } else {
                    html = '<ul class="list-group list-group-flush student-status-list">';
                    students.forEach(function (student) {
                        var statusClass = self.getStatusClass(student.status);
                        var statusIcon = self.getStatusIcon(student.status, student.hasanswered);
                        var answeredBadge = student.hasanswered ?
                            '<span class="badge badge-success ml-2">Answered</span>' : '';

                        html += '<li class="list-group-item d-flex ' +
                            'justify-content-between align-items-center py-2">';
                        html += '<span class="student-name">' +
                            self.escapeHtml(student.fullname || 'User ' + student.userid) + '</span>';
                        html += '<span class="student-status">';
                        html += '<i class="fa ' + statusIcon + ' ' + statusClass +
                            '" title="' + student.status + '"></i>';
                        html += answeredBadge;
                        html += '</span>';
                        html += '</li>';
                    });
                    html += '</ul>';
                }

                container.html(html);
            },

            /**
             * Get CSS class for student status
             *
             * @param {string} status Student connection status
             * @return {string} CSS class
             * @private
             */
            getStatusClass: function (status) {
                switch (status) {
                    case 'connected':
                        return 'text-success';
                    case 'disconnected':
                        return 'text-danger';
                    case 'answering':
                        return 'text-info';
                    default:
                        return 'text-muted';
                }
            },

            /**
             * Get Font Awesome icon for student status
             *
             * @param {string} status Student connection status
             * @param {boolean} hasAnswered Whether student has answered
             * @return {string} FA icon class
             * @private
             */
            getStatusIcon: function (status, hasAnswered) {
                if (hasAnswered) {
                    return 'fa-check-circle';
                }
                switch (status) {
                    case 'connected':
                        return 'fa-circle';
                    case 'disconnected':
                        return 'fa-times-circle';
                    case 'answering':
                        return 'fa-spinner fa-spin';
                    default:
                        return 'fa-question-circle';
                }
            },

            /**
             * Update aggregate statistics display
             *
             * Requirement: 5.5 - Display aggregate statistics
             * @param {Object} stats Statistics object
             * @private
             */
            updateAggregateStats: function (stats) {
                // Update connected count
                if (stats.connected !== undefined) {
                    $('#stat-connected').text(stats.connected);
                    $('#connected-count').text(stats.connected);
                }

                // Update answered count
                if (stats.answered !== undefined) {
                    $('#stat-answered').text(stats.answered);
                }

                // Update pending count
                if (stats.pending !== undefined) {
                    $('#stat-pending').text(stats.pending);
                }

                // Update progress bar if available
                if (stats.connected > 0 && stats.answered !== undefined) {
                    var percentage = Math.round((stats.answered / stats.connected) * 100);
                    $('#answered-progress').css('width', percentage + '%');
                    $('#answered-progress').attr('aria-valuenow', percentage);
                }
            },

            /**
             * Reset student answered status for new question
             *
             * @private
             */
            resetStudentAnsweredStatus: function () {
                // Clear answered badges in UI
                $('.student-status-list .badge-success').remove();

                // Reset cache
                Object.keys(connectedStudents).forEach(function (userid) {
                    connectedStudents[userid].hasanswered = false;
                });

                // Reset aggregate stats
                $('#stat-answered').text('0');
                $('#stat-pending').text($('#stat-connected').text());
                $('#answered-progress').css('width', '0%');
            },

            /**
             * Escape HTML to prevent XSS
             *
             * @param {string} text Text to escape
             * @return {string} Escaped text
             * @private
             */
            escapeHtml: function (text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            },

            /**
             * Start polling for session statistics updates
             */
            startPolling: function () {
                var self = this;

                // Poll for current question stats
                pollingTimer = setInterval(function () {
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
            updateStats: function () {
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
                    success: function (response) {
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
                    error: function () {
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
            handleError: function () {
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
             * @private
             */
            updateDisplay: function (data) {
                // Update participant count
                if (data.participants !== undefined) {
                    $('#participant-count').text(data.participants);
                }

                // Update question progress
                if (data.currentquestion !== undefined && data.totalquestions !== undefined) {
                    var newQuestion = parseInt(data.currentquestion);

                    // Prevent flickering: only update if question number is same or newer
                    if (newQuestion >= lastQuestionNumber) {
                        lastQuestionNumber = newQuestion;
                        $('#question-progress').text((newQuestion + 1) + ' / ' + data.totalquestions);
                        $('#question-progress').data('total', data.totalquestions);
                    }
                }

                // Update response count and participation rate
                if (data.responses !== undefined) {
                    $('#response-count').text(data.responses + ' / ' + data.participants);

                    if (data.participationrate !== undefined) {
                        $('#response-rate').text(data.participationrate + '%');
                        $('#response-progress').css('width', data.participationrate + '%');
                        $('#response-progress').attr('aria-valuenow', data.participationrate);
                    }
                }

                // Update connection statistics (Requirement 5.5)
                if (data.connected !== undefined) {
                    this.updateAggregateStats({
                        connected: data.connected,
                        answered: data.answered,
                        pending: data.pending
                    });
                }

                // Update answer distribution
                if (data.distribution) {
                    this.updateDistribution(data.distribution);

                    if (chart) {
                        this.updateChart(data.distribution);
                    }
                }

                // Update session status
                if (data.status) {
                    isPaused = (data.status === 'paused');

                    var statusText = data.status.charAt(0).toUpperCase() + data.status.slice(1);
                    $('#session-status').text(statusText);

                    // Update pause/resume buttons
                    if (isPaused) {
                        $('#btn-pause-session').hide();
                        $('#btn-resume-session').show();
                        $('#session-status-badge').removeClass('badge-success').addClass('badge-warning');
                    } else if (data.status === 'active') {
                        $('#btn-resume-session').hide();
                        $('#btn-pause-session').show();
                        $('#session-status-badge').removeClass('badge-warning').addClass('badge-success');
                    }

                    // Stop polling if session is completed
                    if (data.status === 'completed') {
                        this.stopPolling();
                        this.stopStudentStatusPolling();
                    }
                }

                // Update time display
                this.updateTimeDisplay(data);
            },

            /**
             * Update the time display
             *
             * @param {Object} data Session data
             * @private
             */
            updateTimeDisplay: function (data) {
                var timeText = '--:--';

                if (isPaused && data.timerremaining !== undefined) {
                    // Show frozen time when paused
                    timeText = this.formatTime(data.timerremaining);
                    $('#time-display').addClass('text-warning');
                } else if (data.timelimit > 0) {
                    // Show remaining time
                    var remaining = data.timeremaining !== undefined ? data.timeremaining : 0;
                    timeText = this.formatTime(remaining);

                    // Add warning class if low time
                    var timeDisplay = $('#time-display');
                    if (remaining < 10) {
                        timeDisplay.addClass('text-danger').addClass('font-weight-bold');
                    } else {
                        timeDisplay.removeClass('text-danger').removeClass('font-weight-bold').removeClass('text-warning');
                    }
                } else {
                    // Show elapsed time
                    var elapsed = data.elapsed !== undefined ? data.elapsed : 0;
                    timeText = this.formatTime(elapsed);
                }

                $('#time-display').text(timeText);
            },

            /**
             * Format seconds into MM:SS
             *
             * @param {number} seconds Seconds to format
             * @return {string} Formatted time string
             * @private
             */
            formatTime: function (seconds) {
                var m = Math.floor(seconds / 60);
                var s = Math.floor(seconds % 60);
                return (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
            },

            /**
             * Update the answer distribution table
             *
             * @param {Object} distribution Distribution data with A, B, C, D counts
             * @private
             */
            updateDistribution: function (distribution) {
                var total = distribution.total || 0;
                var correctAnswer = distribution.correctanswer || '';

                ANSWER_OPTIONS.forEach(function (option) {
                    var count = distribution[option] || 0;
                    var percentage = total > 0 ? Math.round((count / total) * 100) : 0;
                    var isCorrect = option === correctAnswer.toUpperCase();

                    // Update count
                    var countElem = $('#count-' + option);
                    if (countElem.length) {
                        countElem.text(count);
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
            initChart: function () {
                var ctx = document.getElementById('responseChart');
                if (!ctx) {
                    return;
                }

                // Check if Chart.js loaded successfully
                if (typeof window.Chart === 'undefined') {
                    // eslint-disable-next-line no-console
                    console.error('Chart.js failed to load. Displaying table view only.');
                    return;
                }

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
             * @private
             */
            updateChart: function (distribution) {
                if (!chart) {
                    return;
                }

                var data = ANSWER_OPTIONS.map(function (option) {
                    return distribution[option] || 0;
                });

                var correctAnswer = distribution.correctanswer || '';
                var colors = ANSWER_OPTIONS.map(function (option) {
                    return option === correctAnswer.toUpperCase() ?
                        'rgba(75, 192, 192, 0.8)' : 'rgba(54, 162, 235, 0.8)';
                });

                var borderColors = ANSWER_OPTIONS.map(function (option) {
                    return option === correctAnswer.toUpperCase() ?
                        'rgba(75, 192, 192, 1)' : 'rgba(54, 162, 235, 1)';
                });

                chart.data.datasets[0].data = data;
                chart.data.datasets[0].backgroundColor = colors;
                chart.data.datasets[0].borderColor = borderColors;
                chart.update('none');
            },

        };
    });
