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
echo $OUTPUT->heading(format_string($classengage->name));

echo html_writer::start_div('mod-classengage student-results-page');

echo html_writer::start_div('card shadow-sm mb-4');
echo html_writer::start_div('card-body d-flex justify-content-between align-items-center flex-wrap');
$backurl = new moodle_url('/mod/classengage/view.php', array('id' => $cm->id));
echo html_writer::link(
    $backurl,
    '<i class="fa fa-arrow-left"></i> ' . get_string('backtoactivity', 'mod_classengage'),
    array('class' => 'btn btn-outline-secondary')
);

echo html_writer::start_div('btn-group');
echo html_writer::link(
    'javascript:window.print()',
    '<i class="fa fa-print"></i> Print Results',
    array('class' => 'btn btn-outline-primary')
);

if (!empty($session->classengageid)) {
    $quizurl = new moodle_url('/mod/classengage/quiz.php', array('id' => $cm->id, 'sessionid' => $sessionid));
    echo html_writer::link(
        $quizurl,
        '<i class="fa fa-refresh"></i> Retake Quiz',
        array('class' => 'btn btn-outline-info')
    );
}
echo html_writer::end_div();
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

echo $OUTPUT->footer();
