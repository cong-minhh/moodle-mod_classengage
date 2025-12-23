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
 * Student quiz interface with real-time updates
 *
 * SSE-only mode: Receives question broadcasts and session state updates
 * exclusively via Server-Sent Events. api.php is used only for
 * answer submissions and session operations.
 *
 * Requirements: 2.4, 4.5, 8.5
 *
 * @module     mod_classengage/quiz
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'jquery',
    'core/ajax',
    'core/notification',
    'core/str',
    'mod_classengage/connection_manager',
    'mod_classengage/client_cache',
], function ($, Ajax, Notification, Str, ConnectionManager, ClientCache) {

    /**
     * Quiz state constants
     * @type {Object}
     */
    var STATE = {
        WAITING: 'waiting',
        ACTIVE: 'active',
        PAUSED: 'paused',
        COMPLETED: 'completed',
    };

    /**
     * Quiz module instance
     * @type {Object}
     */
    var Quiz = {
        cmid: null,
        sessionId: null,
        currentQuestion: null,
        pollingTimer: null,
        countdownTimer: null,
        isOnline: true,
        pendingSubmission: null,
        strings: {},

        // Client-side timer state (enterprise timer separation)
        timerState: {
            serverTimeRemaining: 0,    // Last known server time remaining
            serverTimestamp: 0,        // Server timestamp when we received the sync
            clientStartTime: 0,        // Client timestamp when countdown started
            isRunning: false,          // Whether countdown is active
            isPaused: false,           // Whether timer is paused
            lastSyncTime: 0,            // Last time we synced with server
        },

        /**
         * Initialize the quiz module
         *
         * @param {number} cmid Course module ID
         * @param {number} sessionid Session ID
         * @param {number} pollinginterval Polling interval in milliseconds
         * @return {Promise} Resolves when initialized
         */
        init: function (cmid, sessionid, pollinginterval) {
            var self = this;

            this.cmid = cmid;
            this.sessionId = sessionid;

            // Load language strings
            return this.loadStrings().then(function () {
                // Initialize client cache for offline support
                return ClientCache.init({
                    maxRetries: 3,
                    retryDelay: 2000,
                });
            }).then(function () {
                // Set up connection manager for client cache
                ClientCache.setConnectionManager(ConnectionManager.getInstance());

                // Initialize connection manager
                return ConnectionManager.init(sessionid, {
                    pollInterval: pollinginterval || 2000,
                });
            }).then(function () {
                // Set up event handlers
                self.setupEventHandlers();
                self.setupConnectionHandlers();
                self.setupOfflineIndicator();

                // Handle answer submission
                $(document).on('click', '.submit-answer-btn', function () {
                    self.submitAnswer();
                });

                // Handle touch events for mobile
                $(document).on('touchend', '.quiz-option', function (e) {
                    e.preventDefault();
                    $(this).find('input[type="radio"]').prop('checked', true);
                    $(this).addClass('selected').siblings().removeClass('selected');
                });

                return null;
            }).catch(function (error) {
                // eslint-disable-next-line no-console
                console.error('Quiz initialization error:', error);
                // Fall back to legacy polling if connection manager fails
                self.startLegacyPolling(pollinginterval);
            });
        },

        /**
         * Load required language strings
         *
         * @return {Promise} Resolves when strings are loaded
         */
        loadStrings: function () {
            var self = this;
            var stringKeys = [
                { key: 'answersubmitted', component: 'mod_classengage' },
                { key: 'correct', component: 'mod_classengage' },
                { key: 'incorrect', component: 'mod_classengage' },
                { key: 'correctanswer', component: 'mod_classengage' },
                { key: 'waitingnextquestion', component: 'mod_classengage' },
                { key: 'quizcompleted', component: 'mod_classengage' },
                { key: 'alreadyanswered', component: 'mod_classengage' },
                { key: 'selectanswer', component: 'mod_classengage' },
                { key: 'error', component: 'core' },
                { key: 'offline', component: 'mod_classengage' },
                { key: 'reconnecting', component: 'mod_classengage' },
                { key: 'connectionrestored', component: 'mod_classengage' },
                { key: 'submittingoffline', component: 'mod_classengage' },
                { key: 'pendingsubmissions', component: 'mod_classengage' },
            ];

            return Str.get_strings(stringKeys).then(function (strings) {
                self.strings = {
                    answersubmitted: strings[0],
                    correct: strings[1],
                    incorrect: strings[2],
                    correctanswer: strings[3],
                    waitingnextquestion: strings[4],
                    quizcompleted: strings[5],
                    alreadyanswered: strings[6],
                    selectanswer: strings[7],
                    error: strings[8],
                    offline: strings[9] || 'Offline - responses will be saved locally',
                    reconnecting: strings[10] || 'Reconnecting...',
                    connectionrestored: strings[11] || 'Connection restored',
                    submittingoffline: strings[12] || 'Saving response offline...',
                    pendingsubmissions: strings[13] || 'Pending submissions',
                };
                return null;
            }).catch(function () {
                // Use fallback strings if loading fails
                self.strings = {
                    answersubmitted: 'Answer submitted!',
                    correct: 'Correct!',
                    incorrect: 'Incorrect',
                    correctanswer: 'Correct Answer',
                    waitingnextquestion: 'Waiting for next question...',
                    quizcompleted: 'Quiz completed!',
                    alreadyanswered: 'You have already answered this question',
                    selectanswer: 'Please select an answer',
                    error: 'Error',
                    offline: 'Offline - responses will be saved locally',
                    reconnecting: 'Reconnecting...',
                    connectionrestored: 'Connection restored',
                    submittingoffline: 'Saving response offline...',
                    pendingsubmissions: 'Pending submissions',
                };
            });
        },

        /**
         * Set up connection manager event handlers
         */
        setupConnectionHandlers: function () {
            var self = this;

            // Handle connection status changes
            ConnectionManager.on('statuschange', function (data) {
                self.handleConnectionStatusChange(data);
            });

            // Handle session state updates from server
            ConnectionManager.on('state_update', function (data) {
                self.handleStateUpdate(data);
            });

            // Handle question broadcasts
            ConnectionManager.on('question_broadcast', function (data) {
                self.handleQuestionBroadcast(data);
            });

            // Handle session events
            ConnectionManager.on('session_started', function (data) {
                self.handleSessionStarted(data);
            });

            ConnectionManager.on('session_paused', function (data) {
                self.handleSessionPaused(data);
            });

            ConnectionManager.on('session_resumed', function (data) {
                self.handleSessionResumed(data);
            });

            ConnectionManager.on('session_completed', function (data) {
                self.handleSessionCompleted(data);
            });

            // Handle timer sync from server (only for drift correction)
            ConnectionManager.on('timer_sync', function (data) {
                self.syncServerTime(data);
            });

            // Handle reconnection
            ConnectionManager.on('reconnected', function () {
                self.handleReconnected();
            });

            // Handle disconnection
            ConnectionManager.on('disconnected', function () {
                self.handleDisconnected();
            });
        },

        /**
         * Set up client cache event handlers
         */
        setupEventHandlers: function () {
            var self = this;

            // Handle cached response submission
            ClientCache.on('submitted', function (data) {
                self.handleCachedResponseSubmitted(data);
            });

            // Handle retry events
            ClientCache.on('retrying', function (data) {
                self.showNotification('info', self.strings.pendingsubmissions + ': ' + data.count);
            });

            // Handle retry completion
            ClientCache.on('retryComplete', function (data) {
                var successCount = data.results.filter(function (r) {
                    return r.success;
                }).length;
                if (successCount > 0) {
                    self.showNotification('success', successCount + ' cached response(s) submitted');
                }
            });
        },

        /**
         * Set up offline indicator UI element
         */
        setupOfflineIndicator: function () {
            // Create offline indicator if it doesn't exist
            if ($('#offline-indicator').length === 0) {
                var indicator = $('<div id="offline-indicator" class="offline-indicator" style="display: none;">' +
                    '<span class="offline-icon">&#9888;</span>' +
                    '<span class="offline-text"></span>' +
                    '<span class="pending-count"></span>' +
                    '</div>');
                $('#quiz-status').after(indicator);
            }

            // Listen for online/offline events
            var self = this;
            window.addEventListener('online', function () {
                self.handleOnlineStatusChange(true);
            });
            window.addEventListener('offline', function () {
                self.handleOnlineStatusChange(false);
            });

            // Check initial status
            this.isOnline = navigator.onLine;
            this.updateOfflineIndicator();
        },

        /**
         * Handle online/offline status change
         *
         * @param {boolean} online Whether we're online
         */
        handleOnlineStatusChange: function (online) {
            this.isOnline = online;
            this.updateOfflineIndicator();

            if (online) {
                // Try to reconnect
                ConnectionManager.reconnect().catch(function () {
                    // Reconnection will be retried automatically
                });
            }
        },

        /**
         * Handle connection status change from connection manager
         *
         * @param {Object} data Status change data
         */
        handleConnectionStatusChange: function (data) {
            var status = data.status;
            var transport = data.transport;

            if (status === ConnectionManager.STATUS.CONNECTED) {
                this.isOnline = true;
                this.updateOfflineIndicator();
                // Update transport indicator if needed
                this.updateTransportIndicator(transport);
            } else if (status === ConnectionManager.STATUS.RECONNECTING) {
                this.showReconnectingIndicator();
            } else if (status === ConnectionManager.STATUS.DISCONNECTED) {
                this.isOnline = false;
                this.updateOfflineIndicator();
            }
        },

        /**
         * Update offline indicator display
         */
        updateOfflineIndicator: function () {
            var indicator = $('#offline-indicator');
            var textSpan = indicator.find('.offline-text');
            var pendingSpan = indicator.find('.pending-count');

            if (!this.isOnline) {
                textSpan.text(this.strings.offline);
                indicator.removeClass('reconnecting').addClass('offline').show();
            } else {
                indicator.hide();
            }

            // Update pending count
            var stats = ClientCache.getStats();
            if (stats.pending > 0) {
                pendingSpan.text(' (' + stats.pending + ' pending)').show();
                indicator.show();
            } else {
                pendingSpan.hide();
            }
        },

        /**
         * Show reconnecting indicator
         */
        showReconnectingIndicator: function () {
            var indicator = $('#offline-indicator');
            indicator.find('.offline-text').text(this.strings.reconnecting);
            indicator.removeClass('offline').addClass('reconnecting').show();
        },

        /**
         * Update transport indicator (SSE vs polling)
         *
         * @param {string} transport Transport type
         */
        updateTransportIndicator: function (transport) {
            var transportIndicator = $('#transport-indicator');
            if (transportIndicator.length === 0) {
                transportIndicator = $('<span id="transport-indicator" class="transport-indicator"></span>');
                $('#quiz-status').append(transportIndicator);
            }

            if (transport === ConnectionManager.TRANSPORT.SSE) {
                transportIndicator.text('Real-time').addClass('realtime');
            } else if (transport === ConnectionManager.TRANSPORT.POLLING) {
                transportIndicator.text('Polling').removeClass('realtime');
            }
        },

        /**
         * Handle state update from server
         *
         * @param {Object} data State update data
         */
        handleStateUpdate: function (data) {
            if (data.question) {
                this.updateQuestionDisplay({
                    success: true,
                    status: data.status,
                    question: data.question,
                });
            }

            if (data.status === STATE.COMPLETED) {
                this.handleSessionCompleted(data);
            } else if (data.status === STATE.PAUSED) {
                this.handleSessionPaused(data);
            }
        },

        /**
         * Handle question broadcast from server
         *
         * @param {Object} data Question data
         */
        handleQuestionBroadcast: function (data) {
            this.currentQuestion = data.question;
            this.displayQuestion(data.question);

            // Start local countdown timer with timelimit from server
            // Timer is fully client-side - server validates on submission
            var timelimit = data.timelimit || (data.question && data.question.timelimit) || 0;
            if (timelimit > 0) {
                this.startLocalCountdown(timelimit);
            }
        },

        /**
         * Handle session started event
         *
         * @param {Object} data Session data
         */
        handleSessionStarted: function (data) {
            $('#quiz-status').removeClass('alert-warning').addClass('alert-info');
            if (data.question) {
                this.currentQuestion = data.question;
                this.displayQuestion(data.question);
            }
        },

        /**
         * Handle session paused event
         *
         * @param {Object} data Session data
         */
        handleSessionPaused: function (data) {
            var container = $('#question-container');
            container.find('.submit-answer-btn').prop('disabled', true);
            this.showNotification('warning', 'Quiz paused by instructor');

            // Show paused overlay
            if ($('.paused-overlay').length === 0) {
                container.append('<div class="paused-overlay"><span>Quiz Paused</span></div>');
            }

            // Pause local countdown
            this.pauseLocalCountdown();

            // Store remaining time if provided
            if (data.timerRemaining !== undefined) {
                this.pausedTimerRemaining = data.timerRemaining;
            }
        },

        /**
         * Handle session resumed event
         *
         * @param {Object} data Session data
         */
        handleSessionResumed: function (data) {
            var container = $('#question-container');
            container.find('.submit-answer-btn').prop('disabled', false);
            container.find('.paused-overlay').remove();
            this.showNotification('info', 'Quiz resumed');

            // Resume local countdown
            if (data.timerRemaining !== undefined) {
                this.resumeLocalCountdown(data.timerRemaining);
            } else {
                this.resumeLocalCountdown();
            }
        },

        /**
         * Handle session completed event
         *
         * @param {Object} data Session data
         */
        handleSessionCompleted: function (data) {
            var container = $('#question-container');
            var statusDiv = $('#quiz-status');

            statusDiv.removeClass('alert-info').addClass('alert-success');

            var scoreText = data.score !== undefined ? ' Your score: ' + data.score : '';
            statusDiv.html(this.strings.quizcompleted + scoreText);

            container.html('<div class="alert alert-success">' +
                '<h4>' + this.strings.quizcompleted + '</h4>' +
                (data.score !== undefined ? '<p>Your score: ' + data.score + '</p>' : '') +
                '</div>');

            this.stopPolling();
            ConnectionManager.disconnect();
        },

        /**
         * Handle reconnection
         */
        handleReconnected: function () {
            this.isOnline = true;
            this.updateOfflineIndicator();
            this.showNotification('success', this.strings.connectionrestored);

            // Request current state
            ConnectionManager.send('getstatus', {
                sessionid: this.sessionId,
            }).then(function (response) {
                if (response.success && response.session) {
                    this.handleStateUpdate(response.session);
                }
                return null;
            }.bind(this)).catch(function () {
                // Ignore errors, state will sync on next update
            });
        },

        /**
         * Handle disconnection
         */
        handleDisconnected: function () {
            this.isOnline = false;
            this.updateOfflineIndicator();
        },

        /**
         * Handle cached response submitted
         *
         * @param {Object} data Submission data
         */
        handleCachedResponseSubmitted: function (data) {
            this.showNotification('success', 'Cached response submitted: ' + data.id);
            this.updateOfflineIndicator();
        },

        /**
         * Submit answer with optimistic UI update
         */
        submitAnswer: function () {
            var self = this;
            var selectedAnswer = $('input[name="answer"]:checked').val();

            if (!selectedAnswer) {
                Notification.alert(this.strings.error, this.strings.selectanswer);
                return;
            }

            if (!this.currentQuestion) {
                return;
            }

            var questionId = this.currentQuestion.id;
            var clientTimestamp = Date.now();

            // Optimistic UI update (Requirement 8.5)
            this.showOptimisticSubmission();

            // Disable submit button to prevent double submission
            $('.submit-answer-btn').prop('disabled', true);

            // Check if we're online
            if (!this.isOnline || !ConnectionManager.getStatus().connected) {
                // Store in cache for later submission (Requirement 4.5)
                this.submitOffline(questionId, selectedAnswer, clientTimestamp);
                return;
            }

            // Submit via connection manager
            ConnectionManager.send('submitanswer', {
                sessionid: this.sessionId,
                questionid: questionId,
                answer: selectedAnswer,
                clienttimestamp: clientTimestamp,
            }).then(function (response) {
                self.handleSubmissionResponse(response);
                return null;
            }).catch(function (error) {
                // Network error - cache the response
                self.submitOffline(questionId, selectedAnswer, clientTimestamp);
                // eslint-disable-next-line no-console
                console.warn('Submission failed, cached offline:', error);
            });
        },

        /**
         * Show optimistic UI update before server confirmation (Requirement 8.5)
         */
        showOptimisticSubmission: function () {
            var container = $('#question-container');

            // Add submitting state
            container.addClass('submitting');

            // Show optimistic feedback
            var feedbackDiv = container.find('.optimistic-feedback');
            if (feedbackDiv.length === 0) {
                feedbackDiv = $('<div class="optimistic-feedback">' +
                    '<span class="spinner"></span> Submitting...' +
                    '</div>');
                container.find('.submit-answer-btn').after(feedbackDiv);
            }
            feedbackDiv.show();
        },

        /**
         * Submit response offline
         *
         * @param {number} questionId Question ID
         * @param {string} answer Selected answer
         * @param {number} clientTimestamp Client timestamp
         */
        submitOffline: function (questionId, answer, clientTimestamp) {
            var self = this;

            // Show offline submission feedback
            this.showNotification('info', this.strings.submittingoffline);

            ClientCache.storeResponse({
                sessionId: this.sessionId,
                questionId: questionId,
                answer: answer,
                clientTimestamp: clientTimestamp,
            }).then(function () {
                self.showOfflineSubmissionConfirmation();
                self.updateOfflineIndicator();
                return null;
            }).catch(function (error) {
                // eslint-disable-next-line no-console
                console.error('Failed to cache response:', error);
                Notification.exception({ message: 'Failed to save response offline' });
                $('.submit-answer-btn').prop('disabled', false);
            });
        },

        /**
         * Show offline submission confirmation
         */
        showOfflineSubmissionConfirmation: function () {
            var container = $('#question-container');
            container.removeClass('submitting');
            container.find('.optimistic-feedback').remove();

            container.html(
                '<div class="alert alert-info">' +
                '<h4>' + this.strings.answersubmitted + '</h4>' +
                '<p>' + this.strings.offline + '</p>' +
                '<p>' + this.strings.waitingnextquestion + '</p>' +
                '</div>',
            );
        },

        /**
         * Handle submission response from server
         *
         * @param {Object} response Server response
         */
        handleSubmissionResponse: function (response) {
            var container = $('#question-container');
            container.removeClass('submitting');
            container.find('.optimistic-feedback').remove();

            if (response.success) {
                var message = response.iscorrect ? this.strings.correct : this.strings.incorrect;
                var alertClass = response.iscorrect ? 'success' : 'warning';

                var html = '<div class="alert alert-' + alertClass + '">' +
                    '<h4>' + message + '</h4>';

                if (response.correctanswer) {
                    html += '<p>' + this.strings.correctanswer + ': ' + response.correctanswer + '</p>';
                }

                if (response.islate) {
                    html += '<p class="text-muted"><em>Response recorded as late</em></p>';
                }

                html += '<p>' + this.strings.waitingnextquestion + '</p>' +
                    '</div>';

                container.html(html);

                // Visual confirmation (Requirement 2.4)
                this.showVisualConfirmation(response.iscorrect);
            } else {
                // Handle error
                if (response.error && response.error.indexOf('Duplicate') !== -1) {
                    container.html('<div class="alert alert-info">' + this.strings.alreadyanswered + '</div>');
                } else {
                    Notification.exception({ message: response.error || 'Error submitting answer' });
                    $('.submit-answer-btn').prop('disabled', false);
                }
            }
        },

        /**
         * Show visual confirmation of answer submission (Requirement 2.4)
         *
         * @param {boolean} isCorrect Whether the answer was correct
         */
        showVisualConfirmation: function (isCorrect) {
            var confirmationClass = isCorrect ? 'confirmation-correct' : 'confirmation-incorrect';

            // Create confirmation overlay
            var overlay = $('<div class="submission-confirmation ' + confirmationClass + '">' +
                '<span class="confirmation-icon">' + (isCorrect ? '✓' : '✗') + '</span>' +
                '</div>');

            $('body').append(overlay);

            // Animate and remove
            setTimeout(function () {
                overlay.addClass('fade-out');
                setTimeout(function () {
                    overlay.remove();
                }, 300);
            }, 500);
        },

        /**
         * Show notification message
         *
         * @param {string} type Notification type (success, info, warning, error)
         * @param {string} message Message to display
         */
        showNotification: function (type, message) {
            var notificationArea = $('#quiz-notifications');
            if (notificationArea.length === 0) {
                notificationArea = $('<div id="quiz-notifications" class="quiz-notifications"></div>');
                $('#quiz-status').before(notificationArea);
            }

            var alertClass = 'alert-' + (type === 'error' ? 'danger' : type);
            var notification = $('<div class="alert ' + alertClass + ' notification-toast">' + message + '</div>');

            notificationArea.append(notification);

            // Auto-dismiss after 3 seconds
            setTimeout(function () {
                notification.fadeOut(function () {
                    $(this).remove();
                });
            }, 3000);
        },

        /**
         * Update question display
         *
         * @param {Object} response Server response with question data
         */
        updateQuestionDisplay: function (response) {
            var container = $('#question-container');
            var statusDiv = $('#quiz-status');

            if (!response.success || response.status !== 'active') {
                container.html('');
                if (response.status === 'completed') {
                    statusDiv.removeClass('alert-info').addClass('alert-success');
                    statusDiv.html(this.strings.quizcompleted);
                    this.stopPolling();
                } else if (response.status === 'paused') {
                    statusDiv.html('Quiz is paused');
                } else {
                    statusDiv.html(this.strings.waitingnextquestion);
                }
                return;
            }

            var question = response.question;

            if (!question) {
                container.html('<p>' + this.strings.waitingnextquestion + '</p>');
                return;
            }

            // Check if this is a new question
            if (this.currentQuestion === null || this.currentQuestion.id !== question.id) {
                this.currentQuestion = question;
                this.displayQuestion(question);
            }

            // Update timer
            this.updateTimer(question.timeremaining);

            // Update question number
            statusDiv.html('Question ' + question.number + ' of ' + question.total);
        },

        /**
         * Display a question
         *
         * @param {Object} question Question data
         */
        displayQuestion: function (question) {
            var html = '<div class="question-text mb-4">';
            html += '<h4>' + question.text + '</h4>';
            html += '</div>';

            if (question.answered) {
                html += '<div class="alert alert-info">' + this.strings.alreadyanswered + '</div>';
            } else {
                html += '<form id="answer-form">';
                html += '<div class="question-options">';

                for (var i = 0; i < question.options.length; i++) {
                    var option = question.options[i];
                    html += '<div class="quiz-option" data-option="' + option.key + '">';
                    html += '<label class="quiz-option-label">';
                    html += '<input type="radio" name="answer" value="' + option.key + '" required> ';
                    html += '<span class="option-key">' + option.key + '</span>';
                    html += '<span class="option-text">' + option.text + '</span>';
                    html += '</label>';
                    html += '</div>';
                }

                html += '</div>';
                html += '<button type="button" class="btn btn-primary btn-lg submit-answer-btn mt-3">';
                html += this.strings.answersubmitted ? 'Submit Answer' : 'Submit Answer';
                html += '</button>';
                html += '</form>';
            }

            $('#question-container').html(html);
        },

        /**
         * Update timer display (called by local countdown)
         *
         * @param {number} seconds Seconds remaining
         */
        updateTimerDisplay: function (seconds) {
            var display = $('#timer-display');

            if (seconds <= 0) {
                display.text('0:00');
                display.removeClass('warning').addClass('danger');
                return;
            }

            var minutes = Math.floor(seconds / 60);
            var secs = Math.floor(seconds % 60);
            var timeStr = minutes + ':' + (secs < 10 ? '0' : '') + secs;

            display.text(timeStr);

            if (seconds <= 10) {
                display.removeClass('warning').addClass('danger');
            } else if (seconds <= 30) {
                display.removeClass('danger').addClass('warning');
            } else {
                display.removeClass('warning danger');
            }
        },

        /**
         * Start local countdown timer (enterprise timer separation)
         * Client runs its own timer to reduce server SSE traffic.
         *
         * @param {number} seconds Initial seconds remaining
         */
        startLocalCountdown: function (seconds) {
            var self = this;

            // Stop any existing countdown
            if (this.countdownTimer) {
                clearInterval(this.countdownTimer);
                this.countdownTimer = null;
            }

            // Initialize timer state
            this.timerState.serverTimeRemaining = seconds;
            this.timerState.clientStartTime = Date.now();
            this.timerState.isRunning = true;
            this.timerState.isPaused = false;

            // Update display immediately
            this.updateTimerDisplay(seconds);

            // Start client-side countdown (runs every 100ms for smooth updates)
            this.countdownTimer = setInterval(function () {
                if (!self.timerState.isRunning || self.timerState.isPaused) {
                    return;
                }

                // Calculate elapsed time on client
                var clientElapsed = (Date.now() - self.timerState.clientStartTime) / 1000;
                var remaining = Math.max(0, self.timerState.serverTimeRemaining - clientElapsed);

                self.updateTimerDisplay(remaining);

                // Stop when timer reaches 0
                if (remaining <= 0) {
                    self.stopLocalCountdown();
                }
            }, 100); // 100ms for smooth visual updates
        },

        /**
         * Stop local countdown timer
         */
        stopLocalCountdown: function () {
            if (this.countdownTimer) {
                clearInterval(this.countdownTimer);
                this.countdownTimer = null;
            }
            this.timerState.isRunning = false;
        },

        /**
         * Pause local countdown timer
         */
        pauseLocalCountdown: function () {
            this.timerState.isPaused = true;
            // Store remaining time when paused
            var clientElapsed = (Date.now() - this.timerState.clientStartTime) / 1000;
            this.timerState.serverTimeRemaining = Math.max(0, this.timerState.serverTimeRemaining - clientElapsed);
            this.timerState.clientStartTime = Date.now();
        },

        /**
         * Resume local countdown timer
         *
         * @param {number} seconds Seconds remaining from server (optional)
         */
        resumeLocalCountdown: function (seconds) {
            if (seconds !== undefined) {
                this.timerState.serverTimeRemaining = seconds;
            }
            this.timerState.clientStartTime = Date.now();
            this.timerState.isPaused = false;
        },

        /**
         * Sync server time and correct client drift (enterprise timer separation)
         * Called on timer_sync events from server (sent every ~30s or on key events)
         *
         * @param {Object} data Timer sync data from server
         */
        syncServerTime: function (data) {
            var serverRemaining = data.timerremaining;
            var serverTimestamp = data.timestamp;

            // Calculate what client thinks the time should be
            var clientElapsed = (Date.now() - this.timerState.clientStartTime) / 1000;
            var clientRemaining = Math.max(0, this.timerState.serverTimeRemaining - clientElapsed);

            // Calculate drift (difference between server and client)
            var drift = Math.abs(serverRemaining - clientRemaining);

            // Only correct if drift > 2 seconds (enterprise threshold)
            if (drift > 2 || !this.timerState.isRunning) {
                // eslint-disable-next-line no-console
                console.log('Timer sync: correcting drift of', drift.toFixed(1), 'seconds');
                this.timerState.serverTimeRemaining = serverRemaining;
                this.timerState.clientStartTime = Date.now();
                this.timerState.serverTimestamp = serverTimestamp;
            }

            this.timerState.lastSyncTime = Date.now();

            // Start countdown if not running
            if (!this.timerState.isRunning && serverRemaining > 0) {
                this.startLocalCountdown(serverRemaining);
            }
        },

        /**
         * Update timer (legacy method - starts local countdown)
         *
         * @param {number} seconds Seconds remaining
         */
        updateTimer: function (seconds) {
            if (seconds === undefined || seconds === null) {
                return;
            }
            // Start or sync local countdown
            if (!this.timerState.isRunning) {
                this.startLocalCountdown(seconds);
            } else {
                // Sync with new value
                this.syncServerTime({ timerremaining: seconds, timestamp: Date.now() / 1000 });
            }
        },

        /**
         * Get current question via SSE connection
         * Legacy polling removed - all data now comes via SSE events
         * This method is kept for initial state fetch on connect failures
         */
        startLegacyPolling: function () {
            // SSE-only mode: No polling fallback
            // Show connection error notification
            this.showNotification('error',
                'SSE connection required. Please ensure your browser supports Server-Sent Events.');
        },

        /**
         * Get current question - now SSE-only
         * This method is kept for backward compatibility but SSE events
         * are the primary source for question data
         */
        getCurrentQuestion: function () {
            var self = this;

            // Try ConnectionManager send for state refresh
            ConnectionManager.send('getstatus', {
                sessionid: this.sessionId,
            }).then(function (response) {
                if (response && response.success && response.session) {
                    self.handleStateUpdate(response.session);
                }
                return null;
            }).catch(function () {
                // SSE events will provide the data, ignore errors
            });
        },

        /**
         * Stop polling
         */
        stopPolling: function () {
            if (this.pollingTimer) {
                clearInterval(this.pollingTimer);
                this.pollingTimer = null;
            }
            if (this.countdownTimer) {
                clearInterval(this.countdownTimer);
                this.countdownTimer = null;
            }
        },
    };

    return {
        /**
         * Initialize the quiz module
         *
         * @param {number} cmid Course module ID
         * @param {number} sessionid Session ID
         * @param {number} pollinginterval Polling interval
         * @return {Promise} Resolves when initialized
         */
        init: function (cmid, sessionid, pollinginterval) {
            return Quiz.init(cmid, sessionid, pollinginterval);
        },
    };
});
