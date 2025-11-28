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
 * Student quiz participation page
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

$id = required_param('id', PARAM_INT); // Course module ID
$sessionid = required_param('sessionid', PARAM_INT);

$cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);
$session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/classengage:takequiz', $context);

$PAGE->set_url('/mod/classengage/quiz.php', array('id' => $cm->id, 'sessionid' => $sessionid));
$PAGE->set_title(format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Add JavaScript for real-time updates
$pollinginterval = get_config('mod_classengage', 'pollinginterval');
if (!$pollinginterval) {
    $pollinginterval = 1000;
}

$PAGE->requires->js_call_amd('mod_classengage/quiz', 'init', array(
    'cmid' => $cm->id,
    'sessionid' => $sessionid,
    'pollinginterval' => $pollinginterval
));

echo $OUTPUT->header();

echo $OUTPUT->heading(format_string($classengage->name));

echo html_writer::tag('h3', format_string($session->name));

// Main quiz container
echo html_writer::start_div('classengage-quiz-container', array('id' => 'quiz-container'));

if ($session->status === 'active') {
    // Quiz is active - show current question
    echo html_writer::div('', 'alert alert-info', array('id' => 'quiz-status'));
    echo html_writer::div('', 'quiz-question-container', array('id' => 'question-container'));
    
    // Timer display
    echo html_writer::start_div('quiz-timer-container text-center mb-3');
    echo html_writer::tag('div', get_string('timeleft', 'mod_classengage'), array('class' => 'timer-label'));
    echo html_writer::tag('div', '', array('id' => 'timer-display', 'class' => 'timer-display h1'));
    echo html_writer::end_div();
    
} else if ($session->status === 'completed') {
    // Quiz completed - show results
    $sql = "SELECT COUNT(*) as total, SUM(iscorrect) as correct
              FROM {classengage_responses}
             WHERE sessionid = :sessionid AND userid = :userid";
    
    $result = $DB->get_record_sql($sql, array('sessionid' => $sessionid, 'userid' => $USER->id));
    
    if ($result && $result->total > 0) {
        $percentage = ($result->correct / $result->total) * 100;
        $grade = ($percentage / 100) * $classengage->grade;
        
        echo html_writer::start_div('alert alert-success text-center');
        echo html_writer::tag('h3', get_string('quizcompleted', 'mod_classengage', round($grade, 2)));
        echo html_writer::tag('p', get_string('correctanswers', 'mod_classengage', $result->correct) . ' / ' . $result->total);
        echo html_writer::tag('p', get_string('percentage', 'mod_classengage', round($percentage, 1)) . '%');
        echo html_writer::end_div();
    } else {
        echo html_writer::div(get_string('noresponses', 'mod_classengage'), 'alert alert-warning');
    }
    
} else {
    echo html_writer::div(get_string('sessionnotstarted', 'mod_classengage'), 'alert alert-warning');
}

echo html_writer::end_div();

// Add CSS
echo html_writer::tag('style', '
.quiz-question-container {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin: 20px 0;
}
.quiz-option {
    margin: 10px 0;
}
.quiz-option label {
    display: block;
    padding: 15px;
    background: white;
    border: 2px solid #dee2e6;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.3s;
}
.quiz-option input[type="radio"] {
    margin-right: 10px;
}
.quiz-option label:hover {
    border-color: #0066cc;
    background: #e7f3ff;
}
.timer-display {
    font-size: 3em;
    font-weight: bold;
    color: #0066cc;
}
.timer-display.warning {
    color: #ff9800;
}
.timer-display.danger {
    color: #f44336;
}
.submit-answer-btn {
    margin-top: 20px;
}
');

echo $OUTPUT->footer();

