<?php
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
 * Student quiz participation page with real-time updates
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_classengage\session_manager;
use mod_classengage\constants;

// ============================================================================
// PARAMETER VALIDATION AND SETUP
// ============================================================================

$id = required_param('id', PARAM_INT); // Course module ID.
$sessionid = required_param('sessionid', PARAM_INT);

// Validate database records.
$cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);
$session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);

// Verify session belongs to this activity.
if ($session->classengageid != $classengage->id) {
    throw new moodle_exception('invalidsession', 'mod_classengage');
}

// Security checks.
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/classengage:takequiz', $context);

// Page setup.
$PAGE->set_url('/mod/classengage/quiz.php', array('id' => $cm->id, 'sessionid' => $sessionid));
$PAGE->set_title(format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

// ============================================================================
// INITIALIZE COMPONENTS
// ============================================================================

$sessionmanager = new session_manager($classengage->id, $context);

// Get current question if session is active.
$currentquestion = null;
if ($session->status === constants::SESSION_STATUS_ACTIVE) {
    try {
        $currentquestion = $sessionmanager->get_current_question($sessionid);
    } catch (Exception $e) {
        // No question available yet.
        $currentquestion = null;
    }
}

// ============================================================================
// PAGE RESOURCES AND INITIALIZATION
// ============================================================================

// Get polling interval from settings.
$pollinginterval = get_config('mod_classengage', 'pollinginterval');
if (empty($pollinginterval)) {
    $pollinginterval = constants::DEFAULT_POLLING_INTERVAL;
}

// Calculate timer data if question is active.
$timelimit = 0;
$timeremaining = 0;
$questionid = 0;
$hasanswered = false;
if ($currentquestion && $session->status === constants::SESSION_STATUS_ACTIVE) {
    $timelimit = $currentquestion->timelimit ?? 0;
    if ($timelimit > 0 && !empty($session->questionstarttime)) {
        $elapsed = time() - $session->questionstarttime;
        $timeremaining = max(0, $timelimit - $elapsed);
    }
    $questionid = $currentquestion->id;
    // Check if user already answered this question.
    $hasanswered = $DB->record_exists('classengage_responses', array(
        'sessionid' => $sessionid,
        'questionid' => $questionid,
        'userid' => $USER->id
    ));
}

// Initialize real-time updates with AMD module.
$PAGE->requires->js_call_amd('mod_classengage/quiz', 'init', array(
    'cmid' => $cm->id,
    'sessionid' => $sessionid,
    'pollinginterval' => $pollinginterval,
    'timelimit' => $timelimit,
    'timeremaining' => $timeremaining,
    'questionid' => $questionid,
    'hasanswered' => $hasanswered
));

// Add custom CSS for better styling.
$PAGE->requires->css('/mod/classengage/styles.css');

// ============================================================================
// OUTPUT STARTS HERE
// ============================================================================

echo $OUTPUT->header();

// Main wrapper with proper class for styling
echo html_writer::start_div('mod-classengage');

// Page heading.
echo $OUTPUT->heading(format_string($classengage->name));

// Session info card
echo html_writer::start_div('card mb-4 shadow-sm');
echo html_writer::start_div('card-body py-3');
echo html_writer::start_div('d-flex justify-content-between align-items-center flex-wrap');

// Session name
echo html_writer::tag('h4', format_string($session->name), array('class' => 'mb-0 font-weight-bold'));

// Status badge
$statusbadgeclass = 'badge-secondary';
$statustext = get_string('sessionnotstarted', 'mod_classengage');
if ($session->status === constants::SESSION_STATUS_ACTIVE) {
    $statusbadgeclass = 'badge-success';
    $statustext = get_string('active', 'mod_classengage');
} else if ($session->status === constants::SESSION_STATUS_PAUSED) {
    $statusbadgeclass = 'badge-warning';
    $statustext = get_string('sessionpaused', 'mod_classengage');
} else if ($session->status === constants::SESSION_STATUS_COMPLETED) {
    $statusbadgeclass = 'badge-info';
    $statustext = get_string('completed', 'mod_classengage');
}
echo html_writer::span($statustext, 'badge badge-pill ' . $statusbadgeclass . ' px-3 py-2', array('id' => 'session-status-badge'));

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// ============================================================================
// MAIN QUIZ CONTAINER
// ============================================================================

echo html_writer::start_div('quiz-container', array('id' => 'quiz-container'));

if ($session->status === constants::SESSION_STATUS_ACTIVE || $session->status === constants::SESSION_STATUS_PAUSED) {
    // Quiz is active or paused - show real-time interface.

    // Status area (hidden by default, JS shows when needed)
    echo html_writer::div('', 'alert alert-info d-none', array('id' => 'quiz-status'));

    // Timer card
    echo html_writer::start_div('card mb-4 border-0 shadow-sm');
    echo html_writer::start_div('card-body text-center py-4');
    echo html_writer::tag(
        'div',
        get_string('timeleft', 'mod_classengage'),
        array('class' => 'text-uppercase text-muted small mb-2 font-weight-bold')
    );
    echo html_writer::tag('div', '--:--', array(
        'id' => 'timer-display',
        'class' => 'display-4 font-weight-bold'
    ));
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Question container card
    echo html_writer::start_div('card shadow-sm');
    echo html_writer::start_div('card-body p-4', array('id' => 'question-container'));

    if ($currentquestion) {
        // Display pre-loaded question
        echo html_writer::start_div('question-text mb-4');

        // Display referenced image if present (with lazy loading for performance)
        if (!empty($currentquestion->question_image)) {
            // Construct full URL from stored path
            $imagesrc = $currentquestion->question_image;
            if (strpos($imagesrc, '/') === 0) {
                $nlppublicurl = rtrim(get_config('mod_classengage', 'nlppublicurl'), '/');
                $imagesrc = $nlppublicurl . $imagesrc;
            }
            echo html_writer::start_div('question-image text-center mb-3');
            echo html_writer::empty_tag('img', array(
                'src' => $imagesrc,
                'alt' => get_string('referenceimage', 'mod_classengage'),
                'class' => 'img-fluid rounded shadow-sm',
                'style' => 'max-height: 300px; cursor: zoom-in;',
                'loading' => 'lazy',
                'onclick' => 'window.open(this.src, "_blank")'
            ));
            echo html_writer::end_div();
        }

        echo html_writer::tag('h4', format_string($currentquestion->questiontext), array('class' => 'font-weight-bold'));
        echo html_writer::end_div();

        // Check if user has already answered
        $useranswer = $DB->get_record('classengage_responses', array(
            'sessionid' => $sessionid,
            'questionid' => $currentquestion->id,
            'userid' => $USER->id
        ));

        if ($useranswer) {
            echo html_writer::div(
                html_writer::tag('strong', get_string('alreadyanswered', 'mod_classengage')),
                'alert alert-info text-center'
            );
        } else {
            // Display options
            $options = json_decode($currentquestion->options, true);
            if ($options) {
                echo html_writer::start_tag('form', array('id' => 'answer-form'));
                echo html_writer::start_div('question-options');

                foreach (['A', 'B', 'C', 'D'] as $key) {
                    if (!isset($options[$key])) {
                        continue;
                    }
                    echo html_writer::start_div('quiz-option', array('data-option' => $key));
                    echo html_writer::start_tag('label', array('class' => 'quiz-option-label'));
                    echo html_writer::empty_tag('input', array(
                        'type' => 'radio',
                        'name' => 'answer',
                        'value' => $key,
                        'required' => 'required'
                    ));
                    echo html_writer::span($key, 'option-key');
                    echo html_writer::span(format_string($options[$key]), 'option-text');
                    echo html_writer::end_tag('label');
                    echo html_writer::end_div();
                }

                echo html_writer::end_div();
                echo html_writer::tag('button', get_string('submitanswer', 'mod_classengage'), array(
                    'type' => 'button',
                    'class' => 'btn btn-primary btn-lg submit-answer-btn mt-4'
                ));
                echo html_writer::end_tag('form');
            }
        }
    } else {
        // No question yet - show waiting state
        echo html_writer::start_div('text-center py-5');
        echo html_writer::start_div('waiting-animation mb-4');
        echo html_writer::tag('div', '', array('class' => 'spinner-border text-primary', 'role' => 'status'));
        echo html_writer::end_div();
        echo html_writer::tag(
            'h5',
            get_string('waitingforquestion', 'mod_classengage'),
            array('class' => 'text-muted', 'id' => 'waiting-message')
        );
        echo html_writer::tag(
            'p',
            get_string('waitingtostartdesc', 'mod_classengage'),
            array('class' => 'text-muted small')
        );
        echo html_writer::end_div();
    }

    echo html_writer::end_div(); // card-body
    echo html_writer::end_div(); // card

} else if ($session->status === constants::SESSION_STATUS_COMPLETED) {
    // Quiz completed - show results.
    try {
        $sql = "SELECT COUNT(*) as total, COALESCE(SUM(iscorrect), 0) as correct
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid AND userid = :userid";

        $result = $DB->get_record_sql($sql, array('sessionid' => $sessionid, 'userid' => $USER->id));

        echo html_writer::start_div('card shadow-sm');
        echo html_writer::start_div('card-body text-center py-5');

        if ($result && $result->total > 0) {
            $percentage = ($result->correct / $result->total) * 100;
            $grade = ($percentage / 100) * $classengage->grade;

            // Success icon
            $iconclass = $percentage >= 70 ? 'text-success' : ($percentage >= 50 ? 'text-warning' : 'text-danger');
            echo html_writer::tag('div', '✓', array(
                'class' => 'display-1 mb-3 ' . $iconclass,
                'style' => 'font-size: 5rem;'
            ));

            echo html_writer::tag(
                'h2',
                get_string('quizcompleted', 'mod_classengage'),
                array('class' => 'mb-4 font-weight-bold')
            );

            // Stats row
            echo html_writer::start_div('row mt-4 mb-4');

            // Score
            echo html_writer::start_div('col-md-4 mb-3 mb-md-0');
            echo html_writer::start_div('p-3 rounded', array('style' => 'background-color: #f8f9fa;'));
            echo html_writer::tag('div', round($grade, 1), array('class' => 'display-4 font-weight-bold text-primary'));
            echo html_writer::tag('div', get_string('score', 'mod_classengage'), array('class' => 'text-muted text-uppercase small'));
            echo html_writer::end_div();
            echo html_writer::end_div();

            // Correct answers
            echo html_writer::start_div('col-md-4 mb-3 mb-md-0');
            echo html_writer::start_div('p-3 rounded', array('style' => 'background-color: #f8f9fa;'));
            echo html_writer::tag('div', (int) $result->correct . '/' . (int) $result->total, array('class' => 'display-4 font-weight-bold'));
            echo html_writer::tag('div', get_string('correctanswers', 'mod_classengage'), array('class' => 'text-muted text-uppercase small'));
            echo html_writer::end_div();
            echo html_writer::end_div();

            // Percentage
            echo html_writer::start_div('col-md-4');
            echo html_writer::start_div('p-3 rounded', array('style' => 'background-color: #f8f9fa;'));
            echo html_writer::tag('div', round($percentage, 0) . '%', array('class' => 'display-4 font-weight-bold'));
            echo html_writer::tag('div', get_string('percentage', 'mod_classengage'), array('class' => 'text-muted text-uppercase small'));
            echo html_writer::end_div();
            echo html_writer::end_div();

            echo html_writer::end_div(); // row

        } else {
            echo html_writer::tag('div', '📝', array('style' => 'font-size: 4rem;'));
            echo html_writer::tag('h3', get_string('quizcompleted', 'mod_classengage'), array('class' => 'mt-3 mb-3'));
            echo html_writer::div(get_string('noresponses', 'mod_classengage'), 'text-muted');
        }

        // Back button
        $viewurl = new moodle_url('/mod/classengage/view.php', array('id' => $cm->id));
        echo html_writer::div(
            html_writer::link(
                $viewurl,
                get_string('backtoactivity', 'mod_classengage'),
                array('class' => 'btn btn-outline-primary btn-lg mt-4')
            ),
            'mt-3'
        );

        echo html_writer::end_div(); // card-body
        echo html_writer::end_div(); // card

    } catch (Exception $e) {
        echo $OUTPUT->notification(
            get_string('error:cannotloadresults', 'mod_classengage'),
            \core\output\notification::NOTIFY_ERROR
        );
    }

} else {
    // Session not started (status is 'ready').
    echo html_writer::start_div('card shadow-sm');
    echo html_writer::start_div('card-body text-center py-5');

    echo html_writer::tag('div', '⏳', array('style' => 'font-size: 4rem;'));
    echo html_writer::tag(
        'h3',
        get_string('sessionnotstarted', 'mod_classengage'),
        array('class' => 'mt-3 mb-3 font-weight-bold')
    );
    echo html_writer::tag(
        'p',
        get_string('waitingtostartdesc', 'mod_classengage'),
        array('class' => 'text-muted mb-4')
    );

    // Refresh button
    echo html_writer::link(
        $PAGE->url,
        get_string('refresh', 'mod_classengage'),
        array('class' => 'btn btn-primary')
    );

    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::end_div(); // quiz-container
echo html_writer::end_div(); // mod-classengage

echo $OUTPUT->footer();
