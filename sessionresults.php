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
 * Session results page - Comprehensive results view for completed quiz sessions
 *
 * Features:
 * - Summary statistics dashboard
 * - Question-by-question breakdown
 * - Student leaderboard
 * - Response distribution visualization
 * - Export functionality
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_classengage\analytics_engine;
use mod_classengage\engagement_calculator;

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
require_capability('mod/classengage:viewanalytics', $context);

$PAGE->set_url('/mod/classengage/sessionresults.php', array('id' => $cm->id, 'sessionid' => $sessionid));
$PAGE->set_title(get_string('sessionresults', 'mod_classengage') . ' - ' . format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

$PAGE->requires->js_call_amd('mod_classengage/analytics_charts', 'init');

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($classengage->name));

classengage_render_tabs($cm->id, 'sessions');

echo html_writer::start_div('mod-classengage session-results');

$backurl = new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id));
echo html_writer::link($backurl, '<i class="fa fa-arrow-left"></i> ' . get_string('backtosessions', 'mod_classengage'), 
    array('class' => 'btn btn-outline-secondary mb-3'));

echo html_writer::start_div('session-header card shadow-sm mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', format_string($session->name), array('class' => 'card-title mb-2'));

$sessionduration = '';
if ($session->timestarted && $session->timecompleted) {
    $duration = $session->timecompleted - $session->timestarted;
    $minutes = floor($duration / 60);
    $seconds = $duration % 60;
    $sessionduration = $minutes . 'm ' . $seconds . 's';
}

$statusbadge = $session->status === 'completed' ? 'badge-success' : 'badge-warning';
echo html_writer::span(ucfirst($session->status), "badge {$statusbadge} mr-2");
echo html_writer::span($sessionduration, 'text-muted font-size-sm');

echo html_writer::end_div();
echo html_writer::end_div();

$sql = "SELECT COUNT(DISTINCT userid) as total, 
        COALESCE(SUM(iscorrect), 0) as correct,
        AVG(score) as avgscore,
        AVG(responsetime) as avgresponsetime
    FROM {classengage_responses}
    WHERE sessionid = ?";
$stats = $DB->get_record_sql($sql, array($sessionid));

$totalparticipants = $stats->total ?? 0;
$totalcorrect = $stats->correct ?? 0;
$avgscore = $stats->avgscore ?? 0;
$avgresponsetime = $stats->avgresponsetime ?? 0;

$questionsql = "SELECT COUNT(*) as total FROM {classengage_session_questions} WHERE sessionid = ?";
$totalquestions = $DB->get_record_sql($questionsql, array($sessionid))->total ?? 0;

echo html_writer::start_div('row mb-4');

echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card border-0 shadow-sm h-100 stat-card');
echo html_writer::start_div('card-body text-center');
echo html_writer::tag('div', '<i class="fa fa-users fa-2x text-primary mb-2"></i>', array('class' => 'stat-icon'));
echo html_writer::tag('h3', $totalparticipants, array('class' => 'stat-value mb-1'));
echo html_writer::tag('p', get_string('participants', 'mod_classengage'), array('class' => 'text-muted mb-0'));
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card border-0 shadow-sm h-100 stat-card');
echo html_writer::start_div('card-body text-center');
$accuracy = $totalparticipants > 0 ? round(($totalcorrect / ($totalparticipants * $totalquestions)) * 100) : 0;
echo html_writer::tag('div', '<i class="fa fa-check-circle fa-2x text-success mb-2"></i>', array('class' => 'stat-icon'));
echo html_writer::tag('h3', $accuracy . '%', array('class' => 'stat-value mb-1'));
echo html_writer::tag('p', get_string('accuracy', 'mod_classengage'), array('class' => 'text-muted mb-0'));
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card border-0 shadow-sm h-100 stat-card');
echo html_writer::start_div('card-body text-center');
echo html_writer::tag('div', '<i class="fa fa-clock fa-2x text-info mb-2"></i>', array('class' => 'stat-icon'));
echo html_writer::tag('h3', round($avgresponsetime) . 's', array('class' => 'stat-value mb-1'));
echo html_writer::tag('p', get_string('avgresponsetime', 'mod_classengage'), array('class' => 'text-muted mb-0'));
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card border-0 shadow-sm h-100 stat-card');
echo html_writer::start_div('card-body text-center');
echo html_writer::tag('div', '<i class="fa fa-list-ol fa-2x text-warning mb-2"></i>', array('class' => 'stat-icon'));
echo html_writer::tag('h3', $totalquestions, array('class' => 'stat-value mb-1'));
echo html_writer::tag('p', get_string('totalquestions', 'mod_classengage'), array('class' => 'text-muted mb-0'));
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('row mb-4');
echo html_writer::start_div('col-md-6');
echo html_writer::start_div('card border-0 shadow-sm h-100');
echo html_writer::start_div('card-header bg-white font-weight-bold');
echo get_string('leaderboard', 'mod_classengage');
echo html_writer::end_div();
echo html_writer::start_div('card-body p-0');

$leadershipsql = "SELECT u.id, u.firstname, u.lastname, u.email,
    COUNT(r.id) as responses,
    SUM(r.iscorrect) as correct,
    AVG(r.score) as avgscore,
    AVG(r.responsetime) as avgtime
    FROM {user} u
    JOIN {classengage_responses} r ON r.userid = u.id
    WHERE r.sessionid = ?
    GROUP BY u.id, u.firstname, u.lastname, u.email
    ORDER BY avgscore DESC, avgtime ASC
    LIMIT 10";
$leaders = $DB->get_records_sql($leadershipsql, array($sessionid));

if (!empty($leaders)) {
    $table = new html_table();
    $table->head = array('#', get_string('student', 'mod_classengage'), get_string('score', 'mod_classengage'), get_string('accuracy', 'mod_classengage'), get_string('avgresponsetime', 'mod_classengage'));
    $table->head[] = get_string('time', 'mod_classengage');
    $table->head = array_values($table->head);
    $table->attributes['class'] = 'table table-hover mb-0';
    
    $rank = 1;
    foreach ($leaders as $leader) {
        $userurl = new moodle_url('/user/view.php', array('id' => $leader->id, 'course' => $course->id));
        $usernamelink = html_writer::link($userurl, fullname($leader));
        $useraccuracy = $leader->responses > 0 ? round(($leader->correct / $leader->responses) * 100) : 0;
        
        $row = new html_table_row();
        $row->attributes['class'] = $rank <= 3 ? 'table-' . ($rank == 1 ? 'success' : ($rank == 2 ? 'info' : 'warning')) : '';
        $row->cells = array(
            $rank,
            $usernamelink,
            round($leader->avgscore * 100) . '%',
            $useraccuracy . '%',
            round($leader->avgtime) . 's'
        );
        $table->data[] = $row;
        $rank++;
    }
    echo html_writer::table($table);
} else {
    echo html_writer::div(get_string('noresponses', 'mod_classengage'), 'p-3 text-muted');
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('col-md-6');
echo html_writer::start_div('card border-0 shadow-sm h-100');
echo html_writer::start_div('card-header bg-white font-weight-bold');
echo get_string('questionbreakdown', 'mod_classengage');
echo html_writer::end_div();
echo html_writer::start_div('card-body p-0');

$questionssql = "SELECT q.id, q.questiontext, q.optiona, q.optionb, q.optionc, q.optiond, q.correctanswer,
    sq.questionorder,
    (SELECT COUNT(*) FROM {classengage_responses} r WHERE r.questionid = q.id AND r.sessionid = sq.sessionid) as responsecount,
    (SELECT SUM(r.iscorrect) FROM {classengage_responses} r WHERE r.questionid = q.id AND r.sessionid = sq.sessionid) as correctcount
    FROM {classengage_questions} q
    JOIN {classengage_session_questions} sq ON sq.questionid = q.id
    WHERE sq.sessionid = ?
    ORDER BY sq.questionorder";
$questions = $DB->get_records_sql($questionssql, array($sessionid));

if (!empty($questions)) {
    $table = new html_table();
    $table->head = array('#', get_string('question', 'mod_classengage'), get_string('responses', 'mod_classengage'), get_string('accuracy', 'mod_classengage'), get_string('difficulty', 'mod_classengage'));
    $table->attributes['class'] = 'table table-hover mb-0';
    
    foreach ($questions as $q) {
        $qaccuracy = $q->responsecount > 0 ? round(($q->correctcount / $q->responsecount) * 100) : 0;
        
        $difficultycss = 'badge-success';
        if ($qaccuracy < 40) {
            $difficultycss = 'badge-danger';
        } else if ($qaccuracy < 70) {
            $difficultycss = 'badge-warning';
        }
        
        $row = new html_table_row();
        $row->cells = array(
            $q->questionorder,
            format_text($q->questiontext, FORMAT_PLAIN),
            $q->responsecount,
            $qaccuracy . '%',
            html_writer::span(ucfirst($qaccuracy < 40 ? 'hard' : ($qaccuracy < 70 ? 'medium' : 'easy')), "badge {$difficultycss}")
        );
        $table->data[] = $row;
    }
    echo html_writer::table($table);
} else {
    echo html_writer::div(get_string('noquestions', 'mod_classengage'), 'p-3 text-muted');
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();

echo html_writer::start_div('row mb-4');
echo html_writer::start_div('col-md-12');
echo html_writer::start_div('card border-0 shadow-sm');
echo html_writer::start_div('card-header bg-white font-weight-bold');
echo get_string('responsetime', 'mod_classengage');
echo html_writer::end_div();
echo html_writer::start_div('card-body');

$timedatasql = "SELECT 
    CASE 
        WHEN responsetime < 5 THEN '< 5s'
        WHEN responsetime < 10 THEN '5-10s'
        WHEN responsetime < 15 THEN '10-15s'
        WHEN responsetime < 20 THEN '15-20s'
        WHEN responsetime < 30 THEN '20-30s'
        ELSE '> 30s'
    END as timerange,
    COUNT(*) as count
    FROM {classengage_responses}
    WHERE sessionid = ?
    GROUP BY timerange
    ORDER BY timerange";
$timedata = $DB->get_records_sql($timedatasql, array($sessionid));

$chartdata = array(
    'labels' => array(),
    'data' => array()
);
foreach ($timedata as $td) {
    $chartdata['labels'][] = $td->timerange;
    $chartdata['data'][] = $td->count;
}

echo html_writer::div('', 'chart-container', array(
    'data-charttype' => 'bar',
    'data-chartdata' => json_encode($chartdata),
    'data-chartlabel' => get_string('responsetime', 'mod_classengage'),
    'style' => 'max-height: 300px;'
));

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('row mb-4');
echo html_writer::start_div('col-md-6');
echo html_writer::start_div('card border-0 shadow-sm');
echo html_writer::start_div('card-header bg-white font-weight-bold');
echo get_string('answerdistribution', 'mod_classengage');
echo html_writer::end_div();
echo html_writer::start_div('card-body');

$answerdist = array('A' => 0, 'B' => 0, 'C' => 0, 'D' => 0);
foreach ($questions as $q) {
    $answercountsql = "SELECT LOWER(answer) as answer, COUNT(*) as count 
        FROM {classengage_responses} 
        WHERE sessionid = ? AND questionid = ?
        GROUP BY answer";
    $answercounts = $DB->get_records_sql($answercountsql, array($sessionid, $q->id));
    foreach ($answercounts as $ac) {
        $ans = strtoupper($ac->answer);
        if (isset($answerdist[$ans])) {
            $answerdist[$ans] += $ac->count;
        }
    }
}

$piechart = array(
    'labels' => array('A', 'B', 'C', 'D'),
    'data' => array($answerdist['A'], $answerdist['B'], $answerdist['C'], $answerdist['D'])
);

echo html_writer::div('', 'chart-container', array(
    'data-charttype' => 'doughnut',
    'data-chartdata' => json_encode($piechart),
    'data-chartlabel' => get_string('answerdistribution', 'mod_classengage'),
    'style' => 'max-height: 250px;'
));

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('col-md-6');
echo html_writer::start_div('card border-0 shadow-sm');
echo html_writer::start_div('card-header bg-white font-weight-bold');
echo get_string('exportoptions', 'mod_classengage');
echo html_writer::end_div();
echo html_writer::start_div('card-body');

$exporturl = new moodle_url('/mod/classengage/export.php', array('id' => $cm->id, 'sessionid' => $sessionid));

echo html_writer::start_div('list-group');
echo html_writer::link(
    new moodle_url('/mod/classengage/export.php', array('id' => $cm->id, 'sessionid' => $sessionid, 'format' => 'csv')),
    '<i class="fa fa-file-csv"></i> ' . get_string('exportcsv', 'mod_classengage'),
    array('class' => 'list-group-item list-group-item-action')
);
echo html_writer::link(
    new moodle_url('/mod/classengage/export.php', array('id' => $cm->id, 'sessionid' => $sessionid, 'format' => 'xlsx')),
    '<i class="fa fa-file-excel"></i> ' . get_string('exportxlsx', 'mod_classengage'),
    array('class' => 'list-group-item list-group-item-action')
);
echo html_writer::link(
    new moodle_url('/mod/classengage/export.php', array('id' => $cm->id, 'sessionid' => $sessionid, 'format' => 'pdf')),
    '<i class="fa fa-file-pdf"></i> ' . get_string('exportpdf', 'mod_classengage'),
    array('class' => 'list-group-item list-group-item-action')
);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();

echo $OUTPUT->footer();
