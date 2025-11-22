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
 * JavaScript for real-time quiz participation
 *
 * @module     mod_classengage/quiz
 * @package
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    var currentQuestion = null;
    var pollingTimer = null;
    var countdownTimer = null;

    return {
        init: function(cmid, sessionid, pollinginterval) {
            var self = this;

            // Start polling for updates
            this.startPolling(sessionid, pollinginterval);

            // Handle answer submission
            $(document).on('click', '.submit-answer-btn', function() {
                self.submitAnswer(sessionid);
            });
        },

        startPolling: function(sessionid, interval) {
            var self = this;

            // Poll for current question
            pollingTimer = setInterval(function() {
                self.getCurrentQuestion(sessionid);
            }, interval);

            // Get initial question
            this.getCurrentQuestion(sessionid);
        },

        getCurrentQuestion: function(sessionid) {
            var self = this;

            Ajax.call([{
                methodname: 'mod_classengage_get_current_question',
                args: {sessionid: sessionid},
                done: function(response) {
                    self.updateQuestionDisplay(response);
                },
                fail: function() {
                    // Fallback to direct AJAX call
                    $.ajax({
                        url: M.cfg.wwwroot + '/mod/classengage/ajax.php',
                        method: 'POST',
                        data: {
                            action: 'getcurrent',
                            sessionid: sessionid,
                            sesskey: M.cfg.sesskey
                        },
                        dataType: 'json',
                        success: function(response) {
                            self.updateQuestionDisplay(response);
                        }
                    });
                }
            }]);
        },

        updateQuestionDisplay: function(response) {
            var container = $('#question-container');
            var statusDiv = $('#quiz-status');

            if (!response.success || response.status !== 'active') {
                container.html('');
                if (response.status === 'completed') {
                    statusDiv.removeClass('alert-info').addClass('alert-success');
                    statusDiv.html(M.util.get_string('quizcompleted', 'mod_classengage'));
                    this.stopPolling();
                } else {
                    statusDiv.html(M.util.get_string('waitingnextquestion', 'mod_classengage'));
                }
                return;
            }

            var question = response.question;

            if (!question) {
                container.html('<p>' + M.util.get_string('waitingnextquestion', 'mod_classengage') + '</p>');
                return;
            }

            // Check if this is a new question
            if (currentQuestion === null || currentQuestion.id !== question.id) {
                currentQuestion = question;
                this.displayQuestion(question);
            }

            // Update timer
            this.updateTimer(question.timeremaining);

            // Update question number
            statusDiv.html(M.util.get_string('currentquestion', 'mod_classengage', {
                current: question.number,
                total: question.total
            }));
        },

        displayQuestion: function(question) {
            var html = '<div class="question-text mb-4">';
            html += '<h4>' + question.text + '</h4>';
            html += '</div>';

            if (question.answered) {
                html += '<div class="alert alert-info">' + M.util.get_string('alreadyanswered', 'mod_classengage') + '</div>';
            } else {
                html += '<form id="answer-form">';
                html += '<div class="question-options">';

                for (var i = 0; i < question.options.length; i++) {
                    var option = question.options[i];
                    html += '<div class="quiz-option">';
                    html += '<label>';
                    html += '<input type="radio" name="answer" value="' + option.key + '" required> ';
                    html += '<strong>' + option.key + '.</strong> ' + option.text;
                    html += '</label>';
                    html += '</div>';
                }

                html += '</div>';
                html += '<button type="button" class="btn btn-primary btn-lg submit-answer-btn mt-3">';
                html += M.util.get_string('submitanswer', 'mod_classengage');
                html += '</button>';
                html += '</form>';
            }

            $('#question-container').html(html);
        },

        updateTimer: function(seconds) {
            var display = $('#timer-display');

            if (seconds <= 0) {
                display.text('0:00');
                display.removeClass('warning').addClass('danger');
                return;
            }

            var minutes = Math.floor(seconds / 60);
            var secs = seconds % 60;
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

        submitAnswer: function(sessionid) {
            var selectedAnswer = $('input[name="answer"]:checked').val();

            if (!selectedAnswer) {
                Notification.alert(
                    M.util.get_string('error'),
                    M.util.get_string('selectanswer', 'mod_classengage')
                );
                return;
            }

            if (!currentQuestion) {
                return;
            }

            $.ajax({
                url: M.cfg.wwwroot + '/mod/classengage/ajax.php',
                method: 'POST',
                data: {
                    action: 'submitanswer',
                    sessionid: sessionid,
                    questionid: currentQuestion.id,
                    answer: selectedAnswer,
                    sesskey: M.cfg.sesskey
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var message = response.iscorrect ?
                            M.util.get_string('correct', 'mod_classengage') :
                            M.util.get_string('incorrect', 'mod_classengage');

                        $('#question-container').html(
                            '<div class="alert alert-' + (response.iscorrect ? 'success' : 'warning') + '">' +
                            '<h4>' + message + '</h4>' +
                            '<p>' + M.util.get_string('correctanswer', 'mod_classengage') + ': ' + response.correctanswer + '</p>' +
                            '<p>' + M.util.get_string('waitingnextquestion', 'mod_classengage') + '</p>' +
                            '</div>'
                        );
                    } else {
                        Notification.exception({message: response.error || 'Error submitting answer'});
                    }
                },
                error: function() {
                    Notification.exception({message: 'Error submitting answer'});
                }
            });
        },

        stopPolling: function() {
            if (pollingTimer) {
                clearInterval(pollingTimer);
                pollingTimer = null;
            }
            if (countdownTimer) {
                clearInterval(countdownTimer);
                countdownTimer = null;
            }
        }
    };
});

