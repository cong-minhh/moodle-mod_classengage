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
 * SSE-only mode: Provides real-time student status monitoring, session control,
 * and aggregate statistics display exclusively via Server-Sent Events.
 * api.php is used only for write operations (pause/resume).
 *
 * Requirements: 1.3, 1.4, 1.5, 5.1, 5.4, 5.5
 *
 * @module     mod_classengage/controlpanel
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/notification', 'mod_classengage/connection_manager'],
    function ($, Notification, ConnectionManager) {

        // SSE-only mode: No polling timers needed
        var pollingInterval = 1000; // Used only for SSE connection options
        var sessionId = null;
        var chart = null;
        var lastQuestionNumber = -1; // Track last known question to prevent stale updates

        // Constants
        var ANSWER_OPTIONS = ['A', 'B', 'C', 'D'];

        // Session state
        var isPaused = false;

        // Connected students cache
        var connectedStudents = {};

        // Student search filter
        var searchTerm = '';

        // Client-side timer state (local countdown)
        var timerState = {
            timeRemaining: 0,       // Current time remaining
            timelimit: 0,           // Total time limit
            clientStartTime: 0,     // When local countdown started
            isRunning: false,       // Whether countdown is active
            countdownTimer: null,    // setInterval reference
        };

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

                // Initialize SSE connection (SSE-only, no polling fallback for stats)
                this.initSSEConnection();

                // Initialize chart
                this.initChart();

                // Setup pause/resume controls
                this.setupSessionControls();

                // Add cleanup on page unload
                this.setupUnloadHandler();
            },

            /**
             * Initialize SSE connection for real-time updates (SSE-ONLY MODE)
             *
             * @private
             */
            initSSEConnection: function () {
                var self = this;

                // Setup student search functionality
                this.setupStudentSearch();

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

                // SSE-ONLY: Register handlers for stats and students updates
                ConnectionManager.on('stats_update', function (data) {
                    self.handleStatsUpdate(data);
                });

                ConnectionManager.on('students_update', function (data) {
                    self.handleStudentsUpdate(data);
                });

                // Try to connect via SSE
                ConnectionManager.init(sessionId, {
                    pollInterval: pollingInterval,
                }).then(function () {
                    // Log actual transport being used
                    var status = ConnectionManager.getInstance().getStatus();
                    // eslint-disable-next-line no-console
                    console.log('Connection established for control panel:', {
                        transport: status.transport,
                        status: status.status,
                        connectionId: status.connectionId,
                    });

                    if (status.transport === 'sse') {
                        // eslint-disable-next-line no-console
                        console.log('✓ SSE-ONLY mode active - No polling required!');
                    } else {
                        // eslint-disable-next-line no-console
                        console.warn('⚠ SSE failed - Updates may not work correctly');
                    }
                    return null;
                }).catch(function (error) {
                    // eslint-disable-next-line no-console
                    console.error('SSE connection failed:', error);
                });
            },

            /**
             * Handle stats update from SSE (replaces AJAX polling)
             *
             * @param {Object} data Stats data from SSE
             * @private
             */
            handleStatsUpdate: function (data) {
                // Debug: Log received SSE stats data
                // eslint-disable-next-line no-console
                console.log('SSE stats_update received:', {
                    currentquestion: data.currentquestion,
                    responses: data.responses,
                    distribution: data.distribution,
                    hasChart: !!chart,
                });

                // Sync local timer with server time (if changed significantly)
                if (data.timelimit > 0 && data.timeremaining !== undefined) {
                    this.syncLocalTimer(data.timelimit, data.timeremaining);
                }

                // Update display with ALL stats data from SSE
                this.updateDisplay({
                    currentquestion: data.currentquestion,
                    totalquestions: data.totalquestions,
                    responses: data.responses,
                    participants: data.participants,
                    participationrate: data.participationrate,
                    status: data.status,
                    timelimit: data.timelimit,
                    timeremaining: data.timeremaining,
                    distribution: data.distribution,
                    connected: data.connected,
                    answered: data.answered,
                    pending: data.pending,
                });
            },

            /**
             * Handle students update from SSE (replaces AJAX polling)
             *
             * @param {Object} data Students data from SSE
             * @private
             */
            handleStudentsUpdate: function (data) {
                // Update student list
                if (data.students) {
                    this.updateStudentList(data.students);
                }
                // Update aggregate stats
                if (data.stats) {
                    this.updateAggregateStats(data.stats);
                }
            },

            // NOTE: stopPolling removed - SSE-only mode

            /**
             * Setup page unload handler to disconnect SSE
             *
             * @private
             */
            setupUnloadHandler: function () {
                $(window).on('beforeunload', function () {
                    // SSE-only: Just disconnect the connection manager
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
             * Setup student search functionality with debouncing
             *
             * @private
             */
            setupStudentSearch: function () {
                var self = this;
                var searchTimer = null;

                $('#student-search').on('input', function () {
                    var value = $(this).val().toLowerCase().trim();

                    // Debounce: wait 150ms before filtering
                    if (searchTimer) {
                        clearTimeout(searchTimer);
                    }
                    searchTimer = setTimeout(function () {
                        searchTerm = value;
                        self.filterStudentList();
                    }, 150);
                });
            },

            /**
             * Filter and re-render student list based on search term
             *
             * @private
             */
            filterStudentList: function () {
                var container = $('#student-list');
                if (!container.length) {
                    return;
                }

                // Get all students from cache
                var students = Object.values(connectedStudents);

                // Filter by search term
                if (searchTerm) {
                    students = students.filter(function (student) {
                        var name = (student.fullname || '').toLowerCase();
                        return name.indexOf(searchTerm) !== -1;
                    });
                }

                // Re-render filtered list
                this.renderStudentListHtml(students);
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
                    url: M.cfg.wwwroot + '/mod/classengage/api.php',
                    method: 'POST',
                    data: {
                        action: 'pause',
                        sessionid: sessionId,
                        sesskey: M.cfg.sesskey,
                    },
                    dataType: 'json',
                    success: function (response) {
                        if (response.success) {
                            self.handleSessionPaused(response);
                            Notification.addNotification({
                                message: M.util.get_string('sessionpaused', 'mod_classengage'),
                                type: 'info',
                            });
                        } else {
                            Notification.addNotification({
                                message: response.error || 'Failed to pause session',
                                type: 'error',
                            });
                        }
                    },
                    error: function () {
                        Notification.addNotification({
                            message: 'Network error while pausing session',
                            type: 'error',
                        });
                    },
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
                    url: M.cfg.wwwroot + '/mod/classengage/api.php',
                    method: 'POST',
                    data: {
                        action: 'resume',
                        sessionid: sessionId,
                        sesskey: M.cfg.sesskey,
                    },
                    dataType: 'json',
                    success: function (response) {
                        if (response.success) {
                            self.handleSessionResumed(response);
                            Notification.addNotification({
                                message: M.util.get_string('sessionresumed', 'mod_classengage'),
                                type: 'info',
                            });
                        } else {
                            Notification.addNotification({
                                message: response.error || 'Failed to resume session',
                                type: 'error',
                            });
                        }
                    },
                    error: function () {
                        Notification.addNotification({
                            message: 'Network error while resuming session',
                            type: 'error',
                        });
                    },
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

                // Stop local timer and store remaining time
                if (timerState.isRunning) {
                    var clientElapsed = (Date.now() - timerState.clientStartTime) / 1000;
                    timerState.timeRemaining = Math.max(0, timerState.timeRemaining - clientElapsed);
                }
                this.stopLocalCountdown();

                // Update UI
                $('#btn-pause-session').hide();
                $('#btn-resume-session').show();
                $('#session-status').text('Paused').addClass('text-warning');
                $('#session-status-badge').removeClass('badge-success').addClass('badge-warning').text('Paused');

                // Update timer display with frozen time
                if (data.timerremaining !== undefined) {
                    timerState.timeRemaining = data.timerremaining;
                    this.renderTimerDisplay(data.timerremaining);
                }
                $('#time-display').addClass('text-warning');
            },

            /**
             * Handle session resumed event
             *
             * @param {Object} data Resume event data
             * @private
             */
            handleSessionResumed: function (data) {
                isPaused = false;

                // Update UI
                $('#btn-resume-session').hide();
                $('#btn-pause-session').show();
                $('#session-status').text('Active').removeClass('text-warning');
                $('#session-status-badge').removeClass('badge-warning').addClass('badge-success').text('Active');
                $('#time-display').removeClass('text-warning');

                // Resume timer with remaining time
                var remaining = (data && data.timerremaining !== undefined) ? data.timerremaining : timerState.timeRemaining;
                if (remaining > 0) {
                    this.startLocalCountdown(remaining, timerState.timelimit);
                }
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

                // Start fresh timer for new question
                if (data.timelimit && data.timelimit > 0) {
                    this.startLocalCountdown(data.timelimit, data.timelimit);
                }

                // NOTE: Stats will be pushed via SSE stats_update event - no manual refresh needed
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

            // NOTE: Student status polling removed - SSE provides students_update events now

            /**
             * Update the connected students list display
             *
             * Requirement: 5.1 - Display list of connected students with status
             * @param {Array} students Array of student objects
             * @private
             */
            updateStudentList: function (students) {
                var container = $('#student-list');
                if (!container.length) {
                    return;
                }

                // Update cache
                students.forEach(function (student) {
                    connectedStudents[student.userid] = student;
                });

                // Filter students based on search term
                var filteredStudents = students;
                if (searchTerm) {
                    filteredStudents = students.filter(function (student) {
                        var name = (student.fullname || '').toLowerCase();
                        return name.indexOf(searchTerm) !== -1;
                    });
                }

                // Render filtered list
                this.renderStudentListHtml(filteredStudents);
            },

            /**
             * Render student list HTML
             *
             * @param {Array} students Array of student objects to render
             * @private
             */
            renderStudentListHtml: function (students) {
                var self = this;
                var container = $('#student-list');
                if (!container.length) {
                    return;
                }

                // Build simple student list HTML
                var html = '';
                if (students.length === 0) {
                    html = '<div class="text-muted p-3 text-center">' +
                        (searchTerm ? 'No students match your search' : 'No students enrolled') + '</div>';
                } else {
                    html = '<ul class="list-group list-group-flush">';
                    students.forEach(function (student) {
                        // Icon: check for answered, circle for pending
                        var icon = student.hasanswered ? 'fa-check-circle text-success' : 'fa-circle text-muted';
                        var name = self.escapeHtml(student.fullname || 'User ' + student.userid);

                        // Visual distinction for non-connected students
                        var isConnected = student.status !== 'not_connected';
                        var nameClass = isConnected ? '' : 'text-muted';
                        var itemClass = isConnected ? '' : 'bg-light';

                        html += '<li class="list-group-item d-flex justify-content-between align-items-center py-2 ' +
                            itemClass + '" data-userid="' + student.userid + '">';
                        html += '<span class="' + nameClass + '">' + name + '</span>';
                        html += '<i class="fa ' + icon + '"></i>';
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
             * @param {Object} stats Statistics object
             * @private
             */
            updateAggregateStats: function (stats) {
                // Stats are now shown in the Participants card, not in the Students panel
                // This function is kept for backwards compatibility but does nothing
                void stats;
            },

            /**
             * Reset student answered status for new question
             *
             * @private
             */
            resetStudentAnsweredStatus: function () {
                // Reset cache
                Object.keys(connectedStudents).forEach(function (userid) {
                    connectedStudents[userid].hasanswered = false;
                });
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

            // NOTE: Stats polling removed - SSE provides stats_update events now
            // NOTE: handleError removed - not needed in SSE-only mode

            /**
             * Update the display with new session data
             *
             * @param {Object} data Session statistics data
             * @private
             */
            updateDisplay: function (data) {
                // NOTE: participant-count (denominator) is set on page load from enrolled students
                // and should NOT be updated dynamically - it represents total enrolled students

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

                // Update response count (current/total format in participants card)
                if (data.responses !== undefined) {
                    $('#response-count-current').text(data.responses);
                    $('#response-count').text(data.responses + ' / ' + data.participants);
                }

                // Update connection statistics (Requirement 5.5)
                if (data.connected !== undefined) {
                    this.updateAggregateStats({
                        connected: data.connected,
                        answered: data.answered,
                        pending: data.pending,
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
             * Update the time display (uses local timer state)
             *
             * @param {Object} data Session data
             * @private
             */
            updateTimeDisplay: function (data) {
                // Local timer handles display updates via startLocalCountdown
                // This method is called from updateDisplay but timer runs independently
                if (data.timelimit > 0 && !timerState.isRunning && !isPaused) {
                    var remaining = data.timeremaining !== undefined ? data.timeremaining : 0;
                    if (remaining > 0) {
                        this.startLocalCountdown(remaining, data.timelimit);
                    }
                }
            },

            /**
             * Sync local timer with server time
             *
             * @param {number} timelimit Total time limit
             * @param {number} serverRemaining Server's time remaining
             * @private
             */
            syncLocalTimer: function (timelimit, serverRemaining) {
                if (!timerState.isRunning) {
                    // Start timer if not running
                    this.startLocalCountdown(serverRemaining, timelimit);
                    return;
                }

                // Calculate client's remaining time
                var clientElapsed = (Date.now() - timerState.clientStartTime) / 1000;
                var clientRemaining = Math.max(0, timerState.timeRemaining - clientElapsed);

                // Only sync if drift > 2 seconds
                var drift = Math.abs(serverRemaining - clientRemaining);
                if (drift > 2) {
                    // eslint-disable-next-line no-console
                    console.log('Timer drift correction:', drift.toFixed(1), 'seconds');
                    timerState.timeRemaining = serverRemaining;
                    timerState.clientStartTime = Date.now();
                }
            },

            /**
             * Start local countdown timer
             *
             * @param {number} seconds Initial seconds remaining
             * @param {number} timelimit Total time limit
             * @private
             */
            startLocalCountdown: function (seconds, timelimit) {
                var self = this;

                // Stop any existing countdown
                this.stopLocalCountdown();

                // Initialize timer state
                timerState.timeRemaining = seconds;
                timerState.timelimit = timelimit;
                timerState.clientStartTime = Date.now();
                timerState.isRunning = true;

                // Update display immediately
                this.renderTimerDisplay(seconds);

                // Start client-side countdown (1 second interval for instructor panel)
                timerState.countdownTimer = setInterval(function () {
                    if (!timerState.isRunning || isPaused) {
                        return;
                    }

                    // Calculate elapsed time on client
                    var clientElapsed = (Date.now() - timerState.clientStartTime) / 1000;
                    var remaining = Math.max(0, timerState.timeRemaining - clientElapsed);

                    self.renderTimerDisplay(remaining);

                    // Stop when timer reaches 0
                    if (remaining <= 0) {
                        self.stopLocalCountdown();
                    }
                }, 1000); // 1 second updates for instructor panel
            },

            /**
             * Stop local countdown timer
             * @private
             */
            stopLocalCountdown: function () {
                if (timerState.countdownTimer) {
                    clearInterval(timerState.countdownTimer);
                    timerState.countdownTimer = null;
                }
                timerState.isRunning = false;
            },

            /**
             * Render timer display
             *
             * @param {number} remaining Seconds remaining
             * @private
             */
            renderTimerDisplay: function (remaining) {
                var timeText = this.formatTime(remaining);
                var timeDisplay = $('#time-display');

                timeDisplay.text(timeText);

                if (remaining <= 0) {
                    timeDisplay.removeClass('text-warning').addClass('text-danger font-weight-bold');
                } else if (remaining < 10) {
                    timeDisplay.removeClass('text-warning').addClass('text-danger font-weight-bold');
                } else if (remaining < 30) {
                    timeDisplay.removeClass('text-danger font-weight-bold').addClass('text-warning');
                } else {
                    timeDisplay.removeClass('text-danger text-warning font-weight-bold');
                }
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
                                'rgba(54, 162, 235, 0.8)',
                            ],
                            borderColor: [
                                'rgba(54, 162, 235, 1)',
                                'rgba(54, 162, 235, 1)',
                                'rgba(54, 162, 235, 1)',
                                'rgba(54, 162, 235, 1)',
                            ],
                            borderWidth: 1,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                },
                            },
                        },
                        plugins: {
                            legend: {
                                display: false,
                            },
                            title: {
                                display: true,
                                text: 'Response Distribution',
                            },
                        },
                    },
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
