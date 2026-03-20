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
 * Student Quiz Results Page
 *
 * This page allows students to review their quiz results after completing
 * a ClassEngage session. Features include:
 * - Detailed question-by-question breakdown
 * - Correct answer reveal
 * - Performance insights
 * - Class comparison (anonymized)
 * - Trustworthiness analysis display
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_classengage\student_results_renderer;
use mod_classengage\constants;

$id = required_param('id', PARAM_INT);
$sessionid = required_param('sessionid', PARAM_INT);

$cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);
$session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);

if ($session->classengageid != $classengage->id) {
    throw new moodle_exception('invalidsession', 'mod_classengage');
}

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/classengage:viewownresults', $context);

$PAGE->set_url('/mod/classengage/myresults.php', array('id' => $cm->id, 'sessionid' => $sessionid));
$PAGE->set_title(get_string('myresults', 'mod_classengage') . ' - ' . format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

$PAGE->requires->js_call_amd('mod_classengage/student_results', 'init');

echo $OUTPUT->header();

// Breadcrumb - build manually for Moodle 4 compatibility
echo html_writer::start_div('breadcrumb-nav mb-3');
echo html_writer::link(
    new moodle_url('/course/view.php', array('id' => $course->id)),
    '<i class="fa fa-home"></i> ' . format_string($course->fullname),
    array('class' => 'breadcrumb-link')
);
echo ' <i class="fa fa-chevron-right text-muted"></i> ';
echo html_writer::link(
    new moodle_url('/mod/classengage/view.php', array('id' => $cm->id)),
    format_string($classengage->name),
    array('class' => 'breadcrumb-link')
);
echo ' <i class="fa fa-chevron-right text-muted"></i> ';
echo '<span class="text-muted">My Results</span>';
echo html_writer::end_div();

// Page title
echo $OUTPUT->heading(format_string($classengage->name));

echo html_writer::start_div('mod-classengage student-results-page');

// Sticky navigation bar
echo html_writer::start_div('card shadow-sm mb-4 sticky-top', array('style' => 'z-index: 100;'));
echo html_writer::start_div('card-body py-3');
echo html_writer::start_div('d-flex justify-content-between align-items-center flex-wrap gap-2');

// Left side - Back button
$backurl = new moodle_url('/mod/classengage/view.php', array('id' => $cm->id));
echo html_writer::link(
    $backurl,
    '<i class="fa fa-arrow-left"></i> Back to Activity',
    array('class' => 'btn btn-outline-secondary')
);

// Center - Quick navigation
$history = [];
try {
    $renderer = new student_results_renderer($classengage->id, $sessionid, $USER->id);
    $history = $renderer->get_session_history();
} catch (Exception $e) {
    // Ignore
}

if (count($history) > 1) {
    echo html_writer::start_div('btn-group mx-2');
    
    // Previous session
    $prev_session = null;
    $next_session = null;
    $found_current = false;
    foreach ($history as $h) {
        if ($h->id == $sessionid) {
            $found_current = true;
            continue;
        }
        if (!$found_current && !$prev_session) {
            $prev_session = $h;
        }
        if ($found_current && !$next_session) {
            $next_session = $h;
            break;
        }
    }
    
    if ($prev_session) {
        $prev_url = new moodle_url('/mod/classengage/myresults.php', array('id' => $cm->id, 'sessionid' => $prev_session->id));
        echo html_writer::link(
            $prev_url,
            '<i class="fa fa-chevron-left"></i> Previous',
            array('class' => 'btn btn-outline-secondary', 'title' => format_string($prev_session->name))
        );
    }
    
    // Session selector dropdown
    $session_options = [];
    foreach ($history as $h) {
        $session_options[$h->id] = format_string($h->name) . ' - ' . userdate($h->timecompleted, get_string('strftimedate', 'langconfig'));
    }
    echo html_writer::select($session_options, 'session_selector', $sessionid, null, 
        array('class' => 'custom-select custom-select-sm mx-2', 'style' => 'width: auto;', 
              'onchange' => 'window.location.href=this.value ? "/mod/classengage/myresults.php?id=' . $cm->id . '&sessionid=" + this.value : ""'));
    
    if ($next_session) {
        $next_url = new moodle_url('/mod/classengage/myresults.php', array('id' => $cm->id, 'sessionid' => $next_session->id));
        echo html_writer::link(
            $next_url,
            'Next <i class="fa fa-chevron-right"></i>',
            array('class' => 'btn btn-outline-secondary', 'title' => format_string($next_session->name))
        );
    }
    
    echo html_writer::end_div();
}

// Right side - Action buttons
echo html_writer::start_div('btn-group');
echo html_writer::link(
    'javascript:window.print()',
    '<i class="fa fa-print"></i> Print',
    array('class' => 'btn btn-outline-primary')
);

// Show "Retake" only if there's an active session to retake
$activesession = $DB->get_record('classengage_sessions', array('classengageid' => $classengage->id, 'status' => 'active'));
if ($activesession) {
    $quizurl = new moodle_url('/mod/classengage/quiz.php', array('id' => $cm->id, 'sessionid' => $activesession->id));
    echo html_writer::link(
        $quizurl,
        '<i class="fa fa-refresh"></i> Retake',
        array('class' => 'btn btn-outline-info')
    );
}

$viewcourse = new moodle_url('/course/view.php', array('id' => $course->id));
echo html_writer::link(
    $viewcourse,
    '<i class="fa fa-home"></i> Course',
    array('class' => 'btn btn-outline-secondary')
);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// Session info bar
echo html_writer::start_div('alert alert-info mb-4 py-2');
echo html_writer::start_div('d-flex justify-content-between align-items-center flex-wrap');
echo html_writer::tag('strong', '<i class="fa fa-calendar mr-2"></i>Session: ' . format_string($session->name));
echo html_writer::tag('small', '<i class="fa fa-clock-o mr-1"></i>' . userdate($session->timecompleted, get_string('strftimedatetime', 'langconfig')));
echo html_writer::end_div();
echo html_writer::end_div();

try {
    $renderer = new student_results_renderer($classengage->id, $sessionid, $USER->id);

    echo $renderer->render_summary_card();

    echo $renderer->render_trustworthiness_summary();

    echo $renderer->render_insights();

    echo $renderer->render_question_breakdown();

    $history = $renderer->get_session_history();
    if (count($history) > 1) {
        echo html_writer::start_div('card shadow-sm mt-4');
        echo html_writer::start_div('card-header bg-white');
        echo html_writer::tag('h4', '<i class="fa fa-history mr-2 text-info"></i>Your Quiz History', array('class' => 'mb-0'));
        echo html_writer::end_div();
        echo html_writer::start_div('card-body p-0');

        $table = new html_table();
        $table->head = array(
            get_string('session', 'mod_classengage'),
            get_string('date'),
            get_string('score', 'mod_classengage'),
            get_string('correctanswers', 'mod_classengage'),
            get_string('percentage', 'mod_classengage')
        );
        $table->attributes['class'] = 'table table-hover mb-0';

        foreach ($history as $hist_session) {
            if ($hist_session->id == $sessionid) {
                continue;
            }

            $total = $hist_session->total_answered ?? 0;
            $correct = $hist_session->total_correct ?? 0;
            $percentage = $total > 0 ? round(($correct / $total) * 100, 1) : 0;

            $view_url = new moodle_url('/mod/classengage/myresults.php', array(
                'id' => $cm->id,
                'sessionid' => $hist_session->id
            ));

            $row = new html_table_row();
            $row->cells = array(
                html_writer::link($view_url, format_string($hist_session->name)),
                userdate($hist_session->timecompleted, get_string('strftimedatetime', 'langconfig')),
                round(($percentage / 100) * $classengage->grade, 1) . '/' . $classengage->grade,
                $correct . '/' . $total,
                $percentage . '%'
            );
            $table->data[] = $row;
        }

        echo html_writer::table($table);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }

} catch (Exception $e) {
    echo $OUTPUT->notification(
        get_string('error:cannotloadresults', 'mod_classengage') . ': ' . $e->getMessage(),
        \core\output\notification::NOTIFY_ERROR
    );
}

echo html_writer::end_div();

// Keyboard navigation hint
echo html_writer::start_div('keyboard-hint', array('title' => 'Keyboard shortcuts'));
echo '<kbd>J</kbd> / <kbd>↓</kbd> Next &nbsp; <kbd>K</kbd> / <kbd>↑</kbd> Prev &nbsp; <kbd>Esc</kbd> Close';
echo html_writer::end_div();

echo $OUTPUT->footer();
