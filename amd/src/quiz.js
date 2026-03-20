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
  "jquery",
  "core/ajax",
  "core/notification",
  "core/str",
  "mod_classengage/connection_manager",
  "mod_classengage/client_cache",
], function ($, Ajax, Notification, Str, ConnectionManager, ClientCache) {
  /**
   * Quiz state constants
   * @type {Object}
   */
  var STATE = {
    WAITING: "waiting",
    ACTIVE: "active",
    PAUSED: "paused",
    COMPLETED: "completed",
  };

  /**
   * Quiz module instance
   * @type {Object}
   */
  var Quiz = {
    cmid: null,
    sessionId: null,
    currentQuestion: null,
    currentQuestionId: null,
    pollingTimer: null,
    countdownTimer: null,
    isOnline: true,
    pendingSubmission: null,
    strings: {},
    answeredQuestions: {}, // Track which questions user has answered
    selectedAnswers: {}, // Track selected answers (before submission)

    // Client-side timer state (enterprise timer separation)
    timerState: {
      serverTimeRemaining: 0, // Last known server time remaining
      serverTimestamp: 0, // Server timestamp when we received the sync
      clientStartTime: 0, // Client timestamp when countdown started
      isRunning: false, // Whether countdown is active
      isPaused: false, // Whether timer is paused
      lastSyncTime: 0, // Last time we synced with server
    },

    /**
     * Initialize the quiz module
     *
     * @param {Object} options Initialization options
     * @param {number} options.cmid Course module ID
     * @param {number} options.sessionid Session ID
     * @param {number} options.pollinginterval Polling interval in milliseconds
     * @param {number} options.timelimit Time limit for current question
     * @param {number} options.timeremaining Time remaining for current question
     * @param {number} options.questionid Current question ID
     * @return {Promise} Resolves when initialized
     */
    init: function (options) {
      var self = this;

      // Handle both object and legacy positional arguments
      var cmid,
        sessionid,
        pollinginterval,
        timelimit,
        timeremaining,
        questionid,
        hasanswered;
      if (typeof options === "object" && options !== null) {
        cmid = options.cmid;
        sessionid = options.sessionid;
        pollinginterval = options.pollinginterval;
        timelimit = options.timelimit || 0;
        timeremaining = options.timeremaining || 0;
        questionid = options.questionid || 0;
        hasanswered = options.hasanswered || false;
      } else {
        // Legacy positional arguments
        cmid = arguments[0];
        sessionid = arguments[1];
        pollinginterval = arguments[2];
        timelimit = 0;
        timeremaining = 0;
        questionid = 0;
        hasanswered = false;
      }

      this.cmid = cmid;
      this.sessionId = sessionid;
      this.currentQuestionId = questionid;

      // Mark current question as answered if PHP says so
      if (questionid > 0 && hasanswered) {
        this.answeredQuestions[questionid] = true;
      }

      // Load language strings
      return this.loadStrings()
        .then(function () {
          // Initialize client cache for offline support
          return ClientCache.init({
            maxRetries: 3,
            retryDelay: 2000,
          });
        })
        .then(function () {
          // Set up connection manager for client cache
          ClientCache.setConnectionManager(ConnectionManager.getInstance());

          // Set up event handlers FIRST (before connecting)
          self.setupEventHandlers();
          self.setupConnectionHandlers();
          self.setupOfflineIndicator();

          // Handle answer submission
          $(document).on("click", ".submit-answer-btn", function () {
            self.submitAnswer();
          });

          // Track when user selects an answer (before submitting)
          $(document).on("change", 'input[name="answer"]', function () {
            var selectedValue = $(this).val();
            var questionId = self.currentQuestionId || (self.currentQuestion && self.currentQuestion.id);
            if (questionId && selectedValue) {
              self.selectedAnswers[questionId] = selectedValue;
              // eslint-disable-next-line no-console
              console.log("Answer selected:", selectedValue, "for question:", questionId);
            }
          });

          // Handle touch events for mobile
          $(document).on("touchend", ".quiz-option", function (e) {
            e.preventDefault();
            $(this).find('input[type="radio"]').prop("checked", true);
            $(this).addClass("selected").siblings().removeClass("selected");
            // Also track selection for mobile
            var selectedValue = $(this).find('input[type="radio"]').val();
            var questionId = self.currentQuestionId || (self.currentQuestion && self.currentQuestion.id);
            if (questionId && selectedValue) {
              self.selectedAnswers[questionId] = selectedValue;
            }
          });

          // Initialize connection manager (after handlers are set up)
          return ConnectionManager.init(sessionid, {
            pollInterval: pollinginterval || 2000,
          });
        })
        .then(function () {
          // eslint-disable-next-line no-console
          console.log("SSE connection established successfully");

          // Start timer immediately if we have timer data from PHP
          if (timeremaining > 0 && timelimit > 0) {
            self.startLocalCountdown(timeremaining);
          }

          // Update quiz status when connected
          $("#quiz-status").removeClass("d-none").addClass("alert-success").text("Connected");
          setTimeout(function () {
            $("#quiz-status").fadeOut();
          }, 3000);

          return null;
        })
        .catch(function (error) {
          // eslint-disable-next-line no-console
          console.error("Quiz initialization error:", error);
          $("#quiz-status").removeClass("d-none").addClass("alert-danger").text("Connection failed - please refresh");
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
        { key: "answersubmitted", component: "mod_classengage" },
        { key: "correct", component: "mod_classengage" },
        { key: "incorrect", component: "mod_classengage" },
        { key: "correctanswer", component: "mod_classengage" },
        { key: "waitingnextquestion", component: "mod_classengage" },
        { key: "quizcompleted", component: "mod_classengage" },
        { key: "alreadyanswered", component: "mod_classengage" },
        { key: "selectanswer", component: "mod_classengage" },
        { key: "error", component: "core" },
        { key: "offline", component: "mod_classengage" },
        { key: "reconnecting", component: "mod_classengage" },
        { key: "connectionrestored", component: "mod_classengage" },
        { key: "submittingoffline", component: "mod_classengage" },
        { key: "pendingsubmissions", component: "mod_classengage" },
        { key: "timeexpired", component: "mod_classengage" },
        { key: "timesup", component: "mod_classengage" },
      ];

      return Str.get_strings(stringKeys)
        .then(function (strings) {
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
            offline: strings[9] || "Offline - responses will be saved locally",
            reconnecting: strings[10] || "Reconnecting...",
            connectionrestored: strings[11] || "Connection restored",
            submittingoffline: strings[12] || "Saving response offline...",
            pendingsubmissions: strings[13] || "Pending submissions",
            timeexpired: strings[14] || "Time has expired for this question",
            timesup: strings[15] || "Time's Up!",
          };
          return null;
        })
        .catch(function () {
          // Use fallback strings if loading fails
          self.strings = {
            answersubmitted: "Answer submitted!",
            correct: "Correct!",
            incorrect: "Incorrect",
            correctanswer: "Correct Answer",
            waitingnextquestion: "Waiting for next question...",
            quizcompleted: "Quiz completed!",
            alreadyanswered: "You have already answered this question",
            selectanswer: "Please select an answer",
            error: "Error",
            offline: "Offline - responses will be saved locally",
            reconnecting: "Reconnecting...",
            connectionrestored: "Connection restored",
            submittingoffline: "Saving response offline...",
            pendingsubmissions: "Pending submissions",
            timeexpired: "Time has expired for this question",
            timesup: "Time's Up!",
          };
        });
    },

    /**
     * Set up connection manager event handlers
     */
    setupConnectionHandlers: function () {
      var self = this;

      // Handle connection status changes
      ConnectionManager.on("statuschange", function (data) {
        self.handleConnectionStatusChange(data);
      });

      // Handle session state updates from server
      ConnectionManager.on("state_update", function (data) {
        self.handleStateUpdate(data);
      });

      // Handle question broadcasts
      ConnectionManager.on("question_broadcast", function (data) {
        self.handleQuestionBroadcast(data);
      });

      // Handle session events
      ConnectionManager.on("session_started", function (data) {
        self.handleSessionStarted(data);
      });

      ConnectionManager.on("session_paused", function (data) {
        self.handleSessionPaused(data);
      });

      ConnectionManager.on("session_resumed", function (data) {
        self.handleSessionResumed(data);
      });

      ConnectionManager.on("session_completed", function (data) {
        self.handleSessionCompleted(data);
      });

      // Handle timer sync from server (only for drift correction)
      ConnectionManager.on("timer_sync", function (data) {
        self.syncServerTime(data);
      });

      // Handle reconnection
      ConnectionManager.on("reconnected", function () {
        self.handleReconnected();
      });

      // Handle disconnection
      ConnectionManager.on("disconnected", function () {
        self.handleDisconnected();
      });
    },

    /**
     * Set up client cache event handlers
     */
    setupEventHandlers: function () {
      var self = this;

      // Handle cached response submission
      ClientCache.on("submitted", function (data) {
        self.handleCachedResponseSubmitted(data);
      });

      // Handle retry events
      ClientCache.on("retrying", function (data) {
        self.showNotification(
          "info",
          self.strings.pendingsubmissions + ": " + data.count,
        );
      });

      // Handle retry completion
      ClientCache.on("retryComplete", function (data) {
        var successCount = data.results.filter(function (r) {
          return r.success;
        }).length;
        if (successCount > 0) {
          self.showNotification(
            "success",
            successCount + " cached response(s) submitted",
          );
        }
      });
    },

    /**
     * Set up offline indicator UI element
     */
    setupOfflineIndicator: function () {
      // Create offline indicator if it doesn't exist (hidden by default via CSS)
      if ($("#offline-indicator").length === 0) {
        var indicator = $(
          '<div id="offline-indicator" class="offline-indicator">' +
            '<span class="offline-icon">&#9888;</span>' +
            '<span class="offline-text"></span>' +
            '<span class="pending-count"></span>' +
            "</div>",
        );
        $("#quiz-status").after(indicator);
      }

      // Listen for online/offline events
      var self = this;
      window.addEventListener("online", function () {
        self.handleOnlineStatusChange(true);
      });
      window.addEventListener("offline", function () {
        self.handleOnlineStatusChange(false);
      });

      // Check initial status
      this.isOnline = navigator.onLine;
      // Don't show offline indicator immediately - wait for connection attempt
      // This prevents false "offline" messages during page load
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
      var indicator = $("#offline-indicator");
      var textSpan = indicator.find(".offline-text");
      var pendingSpan = indicator.find(".pending-count");

      if (!this.isOnline) {
        textSpan.text(this.strings.offline);
        indicator.removeClass("reconnecting").addClass("offline").show();
      } else {
        indicator.hide();
      }

      // Update pending count
      var stats = ClientCache.getStats();
      if (stats.pending > 0) {
        pendingSpan.text(" (" + stats.pending + " pending)").show();
        indicator.show();
      } else {
        pendingSpan.hide();
      }
    },

    /**
     * Show reconnecting indicator
     */
    showReconnectingIndicator: function () {
      var indicator = $("#offline-indicator");
      indicator.find(".offline-text").text(this.strings.reconnecting);
      indicator.removeClass("offline").addClass("reconnecting").show();
    },

    /**
     * Update transport indicator (SSE vs polling)
     *
     * @param {string} transport Transport type
     */
    updateTransportIndicator: function (transport) {
      var transportIndicator = $("#transport-indicator");
      if (transportIndicator.length === 0) {
        transportIndicator = $(
          '<span id="transport-indicator" class="transport-indicator"></span>',
        );
        $("#quiz-status").append(transportIndicator);
      }

      if (transport === ConnectionManager.TRANSPORT.SSE) {
        transportIndicator.text("Real-time").addClass("realtime");
      } else if (transport === ConnectionManager.TRANSPORT.POLLING) {
        transportIndicator.text("Polling").removeClass("realtime");
      }
    },

    /**
     * Handle state update from server
     *
     * @param {Object} data State update data
     */
    handleStateUpdate: function (data) {
      // Sync timer if we have timer info
      if (data.timeremaining !== undefined && data.timeremaining !== null) {
        this.syncServerTime({
          timerremaining: data.timeremaining,
          timestamp: data.timestamp || Date.now() / 1000,
        });
      }

      if (data.question) {
        this.updateQuestionDisplay({
          success: true,
          status: data.status,
          question: data.question,
          timeremaining: data.timeremaining,
          questionstarttime: data.questionstarttime,
          timelimit: data.timelimit,
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
      // eslint-disable-next-line no-console
      console.log("Received question_broadcast event:", data);

      var question = data.question;
      var questionId = data.questionid || (question && question.id);

      // Stop any existing countdown when new question arrives
      if (this.countdownTimer) {
        clearInterval(this.countdownTimer);
        this.countdownTimer = null;
      }

      // Check if this is a NEW question (reset answered state for new questions)
      if (questionId && this.currentQuestionId !== questionId) {
        this.currentQuestionId = questionId;
        this.answeredQuestions[questionId] = data.hasanswered || false;
      }

      // Check if user has already answered this question
      if (data.hasanswered) {
        if (question) {
          question.answered = true;
        }
      }

      this.currentQuestion = question;
      this.displayQuestion(question);

      // Start local countdown timer with remaining time from server
      var timeremaining = data.timeremaining || 0;
      var questionstarttime = data.questionstarttime || 0;
      var timelimit = data.timelimit || 0;

      // If server sent start time but no remaining time, calculate locally
      if (questionstarttime > 0 && timelimit > 0 && timeremaining === 0) {
        var serverNow = data.timestamp || Date.now() / 1000;
        timeremaining = Math.max(0, timelimit - (serverNow - questionstarttime));
      }

      // eslint-disable-next-line no-console
      console.log("Processing question:", {
        questionId: questionId,
        timeremaining: timeremaining,
        timelimit: timelimit,
        hasanswered: data.hasanswered,
        questionText: question ? question.text.substring(0, 50) : 'none'
      });

      if (timeremaining > 0 && !data.hasanswered) {
        this.startLocalCountdown(timeremaining);
        $(".submit-answer-btn").prop("disabled", false);
      } else if (timeremaining <= 0 && !data.hasanswered) {
        // Time already expired for this question
        this.showTimeExpiredIndicator();
        $(".submit-answer-btn").prop("disabled", true);
      }
    },

    /**
     * Handle session started event
     *
     * @param {Object} data Session data
     */
    handleSessionStarted: function (data) {
      $("#quiz-status").removeClass("alert-warning").addClass("alert-info");
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
      var container = $("#question-container");
      container.find(".submit-answer-btn").prop("disabled", true);
      this.showNotification("warning", "Quiz paused by instructor");

      // Show paused overlay
      if ($(".paused-overlay").length === 0) {
        container.append(
          '<div class="paused-overlay"><span>Quiz Paused</span></div>',
        );
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
      var container = $("#question-container");
      container.find(".submit-answer-btn").prop("disabled", false);
      container.find(".paused-overlay").remove();
      this.showNotification("info", "Quiz resumed");

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
      var container = $("#question-container");
      var statusDiv = $("#quiz-status");

      statusDiv.removeClass("alert-info").addClass("alert-success");

      var scoreText =
        data.score !== undefined ? " Your score: " + data.score : "";
      statusDiv.html(this.strings.quizcompleted + scoreText);

      container.html(
        '<div class="alert alert-success">' +
          "<h4>" +
          this.strings.quizcompleted +
          "</h4>" +
          (data.score !== undefined
            ? "<p>Your score: " + data.score + "</p>"
            : "") +
          "</div>",
      );

      this.stopPolling();
      ConnectionManager.disconnect();
    },

    /**
     * Handle reconnection
     */
    handleReconnected: function () {
      this.isOnline = true;
      this.updateOfflineIndicator();
      this.showNotification("success", this.strings.connectionrestored);

      // Request current state
      ConnectionManager.send("getstatus", {
        sessionid: this.sessionId,
      })
        .then(
          function (response) {
            if (response.success && response.session) {
              this.handleStateUpdate(response.session);
            }
            return null;
          }.bind(this),
        )
        .catch(function () {
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
      this.showNotification("success", "Cached response submitted: " + data.id);
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

      // Get question ID - from currentQuestion object or from stored currentQuestionId
      var questionId = 0;
      if (this.currentQuestion && this.currentQuestion.id) {
        questionId = this.currentQuestion.id;
      } else if (this.currentQuestionId) {
        questionId = this.currentQuestionId;
      }

      if (!questionId) {
        // eslint-disable-next-line no-console
        console.error("No question ID available for submission");
        self.showNotification("error", "Error: No active question to submit to");
        return;
      }

      // Store selected answer immediately (so it's preserved if timer expires)
      this.selectedAnswers[questionId] = selectedAnswer;

      // Check if already answered this question
      if (this.answeredQuestions[questionId]) {
        self.showNotification("info", self.strings.alreadyanswered);
        return;
      }

      var clientTimestamp = Date.now();

      // Optimistic UI update (Requirement 8.5)
      this.showOptimisticSubmission();

      // Disable submit button to prevent double submission
      $(".submit-answer-btn").prop("disabled", true);

      // Check if we're online
      if (!this.isOnline || !ConnectionManager.getStatus().connected) {
        // Store in cache for later submission (Requirement 4.5)
        this.submitOffline(questionId, selectedAnswer, clientTimestamp);
        return;
      }

      // Submit via connection manager
      ConnectionManager.send("submitanswer", {
        sessionid: this.sessionId,
        questionid: questionId,
        answer: selectedAnswer,
        clienttimestamp: clientTimestamp,
      })
        .then(function (response) {
          self.handleSubmissionResponse(response);
          return null;
        })
        .catch(function (error) {
          // Network error - cache the response
          self.submitOffline(questionId, selectedAnswer, clientTimestamp);
          // eslint-disable-next-line no-console
          console.warn("Submission failed, cached offline:", error);
        });
    },

    /**
     * Show optimistic UI update before server confirmation (Requirement 8.5)
     */
    showOptimisticSubmission: function () {
      var container = $("#question-container");

      // Add submitting state
      container.addClass("submitting");

      // Show optimistic feedback
      var feedbackDiv = container.find(".optimistic-feedback");
      if (feedbackDiv.length === 0) {
        feedbackDiv = $(
          '<div class="optimistic-feedback">' +
            '<span class="spinner"></span> Submitting...' +
            "</div>",
        );
        container.find(".submit-answer-btn").after(feedbackDiv);
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
      this.showNotification("info", this.strings.submittingoffline);

      ClientCache.storeResponse({
        sessionId: this.sessionId,
        questionId: questionId,
        answer: answer,
        clientTimestamp: clientTimestamp,
      })
        .then(function () {
          self.showOfflineSubmissionConfirmation();
          self.updateOfflineIndicator();
          return null;
        })
        .catch(function (error) {
          // eslint-disable-next-line no-console
          console.error("Failed to cache response:", error);
          Notification.exception({
            message: "Failed to save response offline",
          });
          $(".submit-answer-btn").prop("disabled", false);
        });
    },

    /**
     * Show offline submission confirmation
     */
    showOfflineSubmissionConfirmation: function () {
      var container = $("#question-container");
      container.removeClass("submitting");
      container.find(".optimistic-feedback").remove();

      container.html(
        '<div class="alert alert-info">' +
          "<h4>" +
          this.strings.answersubmitted +
          "</h4>" +
          "<p>" +
          this.strings.offline +
          "</p>" +
          "<p>" +
          this.strings.waitingnextquestion +
          "</p>" +
          "</div>",
      );
    },

    /**
     * Handle submission response from server
     *
     * @param {Object} response Server response
     */
    handleSubmissionResponse: function (response) {
      var container = $("#question-container");
      container.removeClass("submitting");
      container.find(".optimistic-feedback").remove();

      if (response.success) {
        // Mark this question as answered so SSE won't overwrite
        var questionId = this.currentQuestionId || (this.currentQuestion && this.currentQuestion.id);
        if (questionId) {
          this.answeredQuestions[questionId] = true;
        }

        // Get user answer for display
        var userAnswer = this.selectedAnswers[questionId] || '';

        // Use currentQuestion data for rich feedback if available
        var question = this.currentQuestion || {};
        var correctAnswer = response.correctanswer || question.correctanswer || '';
        var isCorrect = (response.iscorrect || (userAnswer.toUpperCase() === correctAnswer.toUpperCase()));

        // Build rich feedback HTML
        var html = '<div class="answer-feedback">';
        
        // Result banner
        if (isCorrect) {
          html += '<div class="alert alert-success mb-3">';
          html += '<i class="fa fa-check-circle fa-lg mr-2"></i>';
          html += '<strong>Correct!</strong> Great job!';
          html += '</div>';
        } else {
          html += '<div class="alert alert-danger mb-3">';
          html += '<i class="fa fa-times-circle fa-lg mr-2"></i>';
          html += '<strong>Incorrect.</strong> The correct answer is <strong>' + correctAnswer + '</strong>';
          html += '</div>';
        }
        
        // Show your answer
        html += '<div class="your-answer mb-3 p-3 rounded" style="background: #f0f0f0;">';
        html += '<strong>Your answer:</strong> ' + (userAnswer || 'No answer submitted');
        html += '</div>';
        
        // Rationale
        if (question.rationale) {
          html += '<div class="rationale-section mb-3 p-3 rounded" style="background: #e8f4f8; border-left: 4px solid #17a2b8;">';
          html += '<h5><i class="fa fa-lightbulb-o text-info mr-2"></i>Explanation</h5>';
          html += '<p class="mb-0">' + question.rationale + '</p>';
          html += '</div>';
        }
        
        // Question metadata
        html += '<div class="question-meta mt-3 p-3 rounded" style="background: #f8f9fa;">';
        html += '<h5><i class="fa fa-info-circle mr-2"></i>Question Details</h5>';
        html += '<div class="row">';
        
        // Difficulty
        if (question.difficulty) {
          var diffColors = { 'easy': 'success', 'medium': 'warning', 'hard': 'danger' };
          var diffColor = diffColors[question.difficulty] || 'secondary';
          html += '<div class="col-md-4 mb-2">';
          html += '<strong>Difficulty:</strong> ';
          html += '<span class="badge badge-' + diffColor + '">' + question.difficulty.charAt(0).toUpperCase() + question.difficulty.slice(1) + '</span>';
          html += '</div>';
        }
        
        // Bloom's Level
        if (question.bloomlevel) {
          html += '<div class="col-md-4 mb-2">';
          html += '<strong>Bloom\'s Level:</strong> ';
          html += '<span class="badge badge-info">' + question.bloomlevel.charAt(0).toUpperCase() + question.bloomlevel.slice(1) + '</span>';
          html += '</div>';
        }
        
        // Trustworthiness
        if (question.trustworthiness_score !== undefined) {
          var twColors = { 'trustworthy': 'primary', 'uncertain': 'warning', 'unlikely': 'danger' };
          var twColor = twColors[question.trustworthiness_level] || 'secondary';
          html += '<div class="col-md-4 mb-2">';
          html += '<strong>Reliability:</strong> ';
          html += '<span class="badge badge-' + twColor + '">' + question.trustworthiness_score + '%</span>';
          html += '</div>';
        }
        
        html += '</div>'; // End row
        html += '</div>'; // End question-meta
        
        // Late submission notice
        if (response.islate) {
          html += '<div class="alert alert-warning mt-3"><em>Response recorded as late</em></div>';
        }
        
        // Waiting for next question
        html += '<div class="waiting-next mt-3 p-3 rounded text-center" style="background: #e9ecef;">';
        html += '<i class="fa fa-hourglass-half mr-2"></i>Waiting for next question...';
        html += '</div>';
        
        html += '</div>'; // End answer-feedback

        container.html(html);

        // Visual confirmation (Requirement 2.4)
        this.showVisualConfirmation(isCorrect);
      } else {
        // Handle error
        var errorMsg = response.error || "";
        if (
          errorMsg.toLowerCase().indexOf("already") !== -1 ||
          errorMsg.toLowerCase().indexOf("duplicate") !== -1
        ) {
          // Already answered - show friendly message
          container.html(
            '<div class="alert alert-info">' +
              this.strings.alreadyanswered +
              "</div>",
          );
        } else if (errorMsg.toLowerCase().indexOf("not active") !== -1) {
          // Session not active
          container.html(
            '<div class="alert alert-warning">Session is not active</div>',
          );
        } else if (
          response.timeexpired ||
          errorMsg.toLowerCase().indexOf("time expired") !== -1
        ) {
          // Time expired - show friendly message
          container.html(
            '<div class="alert alert-danger">' +
              "<h4>" +
              this.strings.timesup +
              "</h4>" +
              "<p>" +
              this.strings.timeexpired +
              "</p>" +
              "<p>" +
              this.strings.waitingnextquestion +
              "</p>" +
              "</div>",
          );
        } else {
          // Other errors - show as notification instead of exception popup
          this.showNotification("error", errorMsg || "Error submitting answer");
          $(".submit-answer-btn").prop("disabled", false);
        }
      }
    },

    /**
     * Show visual confirmation of answer submission (Requirement 2.4)
     *
     * @param {boolean} isCorrect Whether the answer was correct
     */
    showVisualConfirmation: function (isCorrect) {
      var confirmationClass = isCorrect
        ? "confirmation-correct"
        : "confirmation-incorrect";

      // Create confirmation overlay
      var overlay = $(
        '<div class="submission-confirmation ' +
          confirmationClass +
          '">' +
          '<span class="confirmation-icon">' +
          (isCorrect ? "✓" : "✗") +
          "</span>" +
          "</div>",
      );

      $("body").append(overlay);

      // Animate and remove
      setTimeout(function () {
        overlay.addClass("fade-out");
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
      var notificationArea = $("#quiz-notifications");
      if (notificationArea.length === 0) {
        notificationArea = $(
          '<div id="quiz-notifications" class="quiz-notifications"></div>',
        );
        $("#quiz-status").before(notificationArea);
      }

      var alertClass = "alert-" + (type === "error" ? "danger" : type);
      var notification = $(
        '<div class="alert ' +
          alertClass +
          ' notification-toast">' +
          message +
          "</div>",
      );

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
      var container = $("#question-container");
      var statusDiv = $("#quiz-status");

      if (!response.success || response.status !== "active") {
        container.html("");
        if (response.status === "completed") {
          statusDiv.removeClass("alert-info").addClass("alert-success");
          statusDiv.html(this.strings.quizcompleted);
          this.stopPolling();
        } else if (response.status === "paused") {
          statusDiv.html("Quiz is paused");
        } else {
          statusDiv.html(this.strings.waitingnextquestion);
        }
        return;
      }

      var question = response.question;

      if (!question) {
        container.html("<p>" + this.strings.waitingnextquestion + "</p>");
        return;
      }

      // Check if this is a new question
      if (
        this.currentQuestion === null ||
        this.currentQuestion.id !== question.id
      ) {
        this.currentQuestion = question;
        this.displayQuestion(question);
      }

      // Update timer with remaining time from server
      var timeremaining = response.timeremaining || (question && question.timeremaining) || 0;
      var questionstarttime = response.questionstarttime || (question && question.questionstarttime) || 0;
      var timelimit = response.timelimit || (question && question.timelimit) || 0;

      // Calculate remaining time if we have start time
      if (questionstarttime > 0 && timelimit > 0) {
        var serverNow = response.timestamp || Date.now() / 1000;
        timeremaining = Math.max(0, timelimit - (serverNow - questionstarttime));
      }

      this.updateTimer(timeremaining);

      // Update question number
      if (question.number !== undefined && question.total !== undefined) {
        statusDiv.html("Question " + question.number + " of " + question.total);
      }
    },

    /**
     * Display a question
     *
     * @param {Object} question Question data
     */
    displayQuestion: function (question) {
      var self = this;
      var questionId = question.id || this.currentQuestionId;
      var savedAnswer = this.selectedAnswers[questionId];

      var html = '<div class="question-card">';
      
      // Question text
      html += '<div class="question-text mb-4">';
      html += "<h4>" + question.text + "</h4>";
      html += "</div>";

      // Trustworthiness indicator (show before answering too)
      if (question.trustworthiness_score !== undefined) {
        html += this.renderTrustworthinessBadge(question);
      }

      if (question.answered || this.answeredQuestions[questionId]) {
        // Already answered - show results
        html += this.renderAnsweredState(question, questionId);
      } else {
        // Not answered yet - show answer options
        html += '<div id="answer-form-container">';
        html += '<div class="question-options">';

        for (var i = 0; i < question.options.length; i++) {
          var option = question.options[i];
          var isSelected = (savedAnswer === option.key) ? 'checked' : '';
          html += '<div class="quiz-option" data-option="' + option.key + '">';
          html += '<label class="quiz-option-label">';
          html += '<input type="radio" name="answer" value="' + option.key + '" ' + isSelected + ' required> ';
          html += '<span class="option-key">' + option.key + "</span>";
          html += '<span class="option-text">' + option.text + "</span>";
          html += "</label>";
          html += "</div>";
        }

        html += "</div>";
        
        // Only show submit button if time remaining
        if (this.timerState.isRunning && this.timerState.serverTimeRemaining > 0) {
          html += '<button type="button" class="btn btn-primary btn-lg submit-answer-btn mt-3">';
          html += "Submit Answer";
          html += "</button>";
        } else {
          html += '<div class="alert alert-warning mt-3">';
          html += '<i class="fa fa-clock-o"></i> Time is up! You can no longer submit an answer.';
          html += "</div>";
        }
        
        html += '</div>';
      }

      html += '</div>'; // End question-card
      $("#question-container").html(html);
    },

    /**
     * Render trustworthiness badge
     *
     * @param {Object} question Question data
     * @return {string} HTML for badge
     */
    renderTrustworthinessBadge: function (question) {
      var score = question.trustworthiness_score || 0;
      var level = question.trustworthiness_level || 'uncertain';
      var factors = question.trustworthiness_factors;
      
      var colors = {
        'trustworthy': '#2196F3',   // Blue
        'uncertain': '#FFC107',    // Yellow
        'unlikely': '#F44336'      // Red
      };
      
      var labels = {
        'trustworthy': 'Pretty reliable',
        'uncertain': 'Could be wrong',
        'unlikely': 'Likely wrong'
      };
      
      var color = colors[level] || colors.uncertain;
      var label = labels[level] || labels.uncertain;
      
      var html = '<div class="trustworthiness-badge mb-3" style="border-left: 4px solid ' + color + '; padding: 10px 15px; background: #f8f9fa; border-radius: 4px;">';
      html += '<div class="d-flex align-items-center">';
      html += '<span class="badge mr-2" style="background: ' + color + '; color: white; padding: 4px 10px;">' + score + '%</span>';
      html += '<span class="font-weight-bold">' + label + '</span>';
      html += '</div>';
      
      // Show factors if available
      if (factors) {
        try {
          var factorsObj = typeof factors === 'string' ? JSON.parse(factors) : factors;
          if (factorsObj.factors && Array.isArray(factorsObj.factors)) {
            html += '<small class="text-muted mt-1 d-block">';
            html += factorsObj.factors.slice(0, 3).join(' • ');
            html += '</small>';
          }
        } catch (e) {
          // Ignore parse errors
        }
      }
      
      html += '</div>';
      return html;
    },

    /**
     * Render answered state with feedback
     *
     * @param {Object} question Question data
     * @param {int} questionId Question ID
     * @return {string} HTML for answered state
     */
    renderAnsweredState: function (question, questionId) {
      var userAnswer = this.selectedAnswers[questionId];
      var correctAnswer = question.correctanswer;
      var isCorrect = (userAnswer && userAnswer.toUpperCase() === correctAnswer.toUpperCase());
      
      var html = '<div class="answer-feedback">';
      
      // Result banner
      if (isCorrect) {
        html += '<div class="alert alert-success mb-3">';
        html += '<i class="fa fa-check-circle fa-lg mr-2"></i>';
        html += '<strong>Correct!</strong> Great job!';
        html += '</div>';
      } else {
        html += '<div class="alert alert-danger mb-3">';
        html += '<i class="fa fa-times-circle fa-lg mr-2"></i>';
        html += '<strong>Incorrect.</strong> The correct answer is <strong>' + correctAnswer + '</strong>';
        html += '</div>';
      }
      
      // Show your answer
      html += '<div class="your-answer mb-3 p-3 rounded" style="background: #f0f0f0;">';
      html += '<strong>Your answer:</strong> ' + (userAnswer || 'No answer submitted');
      html += '</div>';
      
      // Rationale
      if (question.rationale) {
        html += '<div class="rationale-section mb-3 p-3 rounded" style="background: #e8f4f8; border-left: 4px solid #17a2b8;">';
        html += '<h5><i class="fa fa-lightbulb-o text-info mr-2"></i>Explanation</h5>';
        html += '<p class="mb-0">' + question.rationale + '</p>';
        html += '</div>';
      }
      
      // Question metadata
      html += '<div class="question-meta mt-3 p-3 rounded" style="background: #f8f9fa;">';
      html += '<h5><i class="fa fa-info-circle mr-2"></i>Question Details</h5>';
      html += '<div class="row">';
      
      // Difficulty
      if (question.difficulty) {
        var diffColors = { 'easy': 'success', 'medium': 'warning', 'hard': 'danger' };
        var diffColor = diffColors[question.difficulty] || 'secondary';
        html += '<div class="col-md-4 mb-2">';
        html += '<strong>Difficulty:</strong> ';
        html += '<span class="badge badge-' + diffColor + '">' + question.difficulty.charAt(0).toUpperCase() + question.difficulty.slice(1) + '</span>';
        html += '</div>';
      }
      
      // Bloom's Level
      if (question.bloomlevel) {
        html += '<div class="col-md-4 mb-2">';
        html += '<strong>Bloom\'s Level:</strong> ';
        html += '<span class="badge badge-info">' + question.bloomlevel.charAt(0).toUpperCase() + question.bloomlevel.slice(1) + '</span>';
        html += '</div>';
      }
      
      // Trustworthiness
      if (question.trustworthiness_score !== undefined) {
        var twColors = { 'trustworthy': 'primary', 'uncertain': 'warning', 'unlikely': 'danger' };
        var twColor = twColors[question.trustworthiness_level] || 'secondary';
        html += '<div class="col-md-4 mb-2">';
        html += '<strong>Reliability:</strong> ';
        html += '<span class="badge badge-' + twColor + '">' + question.trustworthiness_score + '%</span>';
        html += '</div>';
      }
      
      html += '</div>'; // End row
      html += '</div>'; // End question-meta
      
      html += '</div>'; // End answer-feedback
      return html;
    },

    /**
     * Update timer display (called by local countdown)
     *
     * @param {number} seconds Seconds remaining
     */
    updateTimerDisplay: function (seconds) {
      var display = $("#timer-display");

      if (!display || display.length === 0) {
        // eslint-disable-next-line no-console
        console.warn("Timer display element not found");
        return;
      }

      // Ensure seconds is a valid number
      if (seconds === undefined || seconds === null || isNaN(seconds)) {
        seconds = 0;
      }

      seconds = Math.max(0, Math.floor(seconds));

      if (seconds <= 0) {
        display.text("0:00");
        display.removeClass("warning").addClass("danger");
        return;
      }

      var minutes = Math.floor(seconds / 60);
      var secs = Math.floor(seconds % 60);
      var timeStr = minutes + ":" + (secs < 10 ? "0" : "") + secs;

      display.text(timeStr);

      if (seconds <= 10) {
        display.removeClass("warning").addClass("danger");
      } else if (seconds <= 30) {
        display.removeClass("danger").addClass("warning");
      } else {
        display.removeClass("warning danger");
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

      // Validate input
      if (seconds === undefined || seconds === null || isNaN(seconds)) {
        // eslint-disable-next-line no-console
        console.error("Invalid seconds value for countdown:", seconds);
        return;
      }

      seconds = Math.max(0, Math.floor(seconds));

      // Stop any existing countdown
      if (this.countdownTimer) {
        clearInterval(this.countdownTimer);
        this.countdownTimer = null;
      }

      // eslint-disable-next-line no-console
      console.log("Starting countdown:", seconds, "seconds");

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
        var clientElapsed =
          (Date.now() - self.timerState.clientStartTime) / 1000;
        var remaining = Math.max(
          0,
          self.timerState.serverTimeRemaining - clientElapsed,
        );

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

      // Check if user has selected an answer
      var questionId = this.currentQuestionId;
      var hasSelectedAnswer = this.selectedAnswers[questionId];
      var hasSubmittedAnswer = this.answeredQuestions[questionId];

      // Only disable submission if NO answer was selected
      if (!hasSelectedAnswer && !hasSubmittedAnswer) {
        $(".submit-answer-btn").prop("disabled", true);
        $(".submit-answer-btn").hide();
        this.showTimeExpiredIndicator();
      }
    },

    /**
     * Show time expired indicator overlay
     */
    showTimeExpiredIndicator: function () {
      var container = $("#question-container");

      // Only show if not already answered and no existing feedback
      if (
        container.find(".alert").length === 0 &&
        container.find(".time-expired-overlay").length === 0
      ) {
        var overlay = $(
          '<div class="time-expired-overlay">' +
            '<span class="time-expired-text">' +
            (this.strings.timesup || "Time's Up!") +
            "</span>" +
            "</div>",
        );
        container.css("position", "relative").append(overlay);
      }

      // Update timer display to show expired state
      $("#timer-display").text("0:00").addClass("expired");
    },

    /**
     * Pause local countdown timer
     */
    pauseLocalCountdown: function () {
      this.timerState.isPaused = true;
      // Store remaining time when paused
      var clientElapsed = (Date.now() - this.timerState.clientStartTime) / 1000;
      this.timerState.serverTimeRemaining = Math.max(
        0,
        this.timerState.serverTimeRemaining - clientElapsed,
      );
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

      // Handle undefined or invalid values
      if (serverRemaining === undefined || serverRemaining === null || serverRemaining < 0) {
        serverRemaining = 0;
      }

      // If timer is not running, just start it with server's remaining time
      if (!this.timerState.isRunning) {
        if (serverRemaining > 0) {
          this.startLocalCountdown(serverRemaining);
        }
        return;
      }

      // Calculate what client thinks the time should be
      var clientElapsed = (Date.now() - this.timerState.clientStartTime) / 1000;
      var clientRemaining = Math.max(
        0,
        this.timerState.serverTimeRemaining - clientElapsed,
      );

      // Calculate drift (difference between server and client)
      var drift = Math.abs(serverRemaining - clientRemaining);

      // Only correct if drift > 2 seconds (enterprise threshold)
      if (drift > 2) {
        // eslint-disable-next-line no-console
        console.log(
          "Timer sync: correcting drift of",
          drift.toFixed(1),
          "seconds",
        );
        this.timerState.serverTimeRemaining = serverRemaining;
        this.timerState.clientStartTime = Date.now();
      }

      this.timerState.serverTimestamp = serverTimestamp;
      this.timerState.lastSyncTime = Date.now();

      // Handle timer expiration during sync
      if (serverRemaining <= 0 && this.timerState.isRunning) {
        this.stopLocalCountdown();
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
        this.syncServerTime({
          timerremaining: seconds,
          timestamp: Date.now() / 1000,
        });
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
      this.showNotification(
        "error",
        "SSE connection required. Please ensure your browser supports Server-Sent Events.",
      );
    },

    /**
     * Get current question - now SSE-only
     * This method is kept for backward compatibility but SSE events
     * are the primary source for question data
     */
    getCurrentQuestion: function () {
      var self = this;

      // Try ConnectionManager send for state refresh
      ConnectionManager.send("getstatus", {
        sessionid: this.sessionId,
      })
        .then(function (response) {
          if (response && response.success && response.session) {
            self.handleStateUpdate(response.session);
          }
          return null;
        })
        .catch(function () {
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
