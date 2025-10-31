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
 * Analytics dashboard page
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

$id = required_param('id', PARAM_INT); // Course module ID
$sessionid = optional_param('sessionid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/classengage:viewanalytics', $context);

$PAGE->set_url('/mod/classengage/analytics.php', array('id' => $cm->id));
$PAGE->set_title(format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Include Chart.js
$PAGE->requires->js(new moodle_url('https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js'), true);

echo $OUTPUT->header();

echo $OUTPUT->heading(format_string($classengage->name));

// Tab navigation
$tabs = array();
$tabs[] = new tabobject('slides', new moodle_url('/mod/classengage/slides.php', array('id' => $cm->id)), 
                       get_string('uploadslides', 'mod_classengage'));
$tabs[] = new tabobject('questions', new moodle_url('/mod/classengage/questions.php', array('id' => $cm->id)), 
                       get_string('managequestions', 'mod_classengage'));
$tabs[] = new tabobject('sessions', new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id)), 
                       get_string('managesessions', 'mod_classengage'));
$tabs[] = new tabobject('analytics', new moodle_url('/mod/classengage/analytics.php', array('id' => $cm->id)), 
                       get_string('analytics', 'mod_classengage'));

print_tabs(array($tabs), 'analytics');

echo html_writer::tag('h3', get_string('analyticspage', 'mod_classengage'));

// Session selector
$sessions = $DB->get_records_menu('classengage_sessions', 
    array('classengageid' => $classengage->id, 'status' => 'completed'), 
    'timecreated DESC', 'id,name');

if (empty($sessions)) {
    echo html_writer::div(get_string('nocompletedsessions', 'mod_classengage'), 'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

if (!$sessionid || !isset($sessions[$sessionid])) {
    $sessionid = key($sessions);
}

// Session selector form
echo html_writer::start_div('mb-3');
echo html_writer::label(get_string('selectsession', 'mod_classengage'), 'sessionselect');
echo ' ';
$url = new moodle_url('/mod/classengage/analytics.php', array('id' => $cm->id));
echo html_writer::select($sessions, 'sessionid', $sessionid, null, array(
    'id' => 'sessionselect',
    'onchange' => "window.location='{$url->out(false)}&sessionid=' + this.value;"
));
echo html_writer::end_div();

$session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);

// Overall statistics
echo html_writer::tag('h4', get_string('overallperformance', 'mod_classengage'));

$sql = "SELECT COUNT(DISTINCT userid) as participants,
               COUNT(*) as totalresponses,
               SUM(iscorrect) as correctresponses,
               AVG(responsetime) as avgresponsetime
          FROM {classengage_responses}
         WHERE sessionid = :sessionid";

$stats = $DB->get_record_sql($sql, array('sessionid' => $sessionid));

echo html_writer::start_div('row mb-4');

// Participants card
echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card');
echo html_writer::div(
    html_writer::tag('h5', get_string('participants', 'mod_classengage'), array('class' => 'card-title')) .
    html_writer::tag('p', $stats->participants, array('class' => 'display-4')),
    'card-body text-center'
);
echo html_writer::end_div();
echo html_writer::end_div();

// Average score card
$avgscore = $stats->totalresponses > 0 ? round(($stats->correctresponses / $stats->totalresponses) * 100, 1) : 0;
echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card');
echo html_writer::div(
    html_writer::tag('h5', get_string('averagescore', 'mod_classengage'), array('class' => 'card-title')) .
    html_writer::tag('p', $avgscore . '%', array('class' => 'display-4')),
    'card-body text-center'
);
echo html_writer::end_div();
echo html_writer::end_div();

// Total responses card
echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card');
echo html_writer::div(
    html_writer::tag('h5', get_string('totalresponses', 'mod_classengage'), array('class' => 'card-title')) .
    html_writer::tag('p', $stats->totalresponses, array('class' => 'display-4')),
    'card-body text-center'
);
echo html_writer::end_div();
echo html_writer::end_div();

// Average response time card
$avgtime = $stats->avgresponsetime ? round($stats->avgresponsetime, 1) : 0;
echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card');
echo html_writer::div(
    html_writer::tag('h5', get_string('responsetime', 'mod_classengage'), array('class' => 'card-title')) .
    html_writer::tag('p', $avgtime . 's', array('class' => 'display-4')),
    'card-body text-center'
);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // End row

// Question breakdown
echo html_writer::tag('h4', get_string('questionbreakdown', 'mod_classengage'), array('class' => 'mt-4'));

$sql = "SELECT q.id, q.questiontext, sq.questionorder,
               COUNT(r.id) as responses,
               SUM(r.iscorrect) as correct
          FROM {classengage_questions} q
          JOIN {classengage_session_questions} sq ON sq.questionid = q.id
          LEFT JOIN {classengage_responses} r ON r.questionid = q.id AND r.sessionid = sq.sessionid
         WHERE sq.sessionid = :sessionid
      GROUP BY q.id, q.questiontext, sq.questionorder
      ORDER BY sq.questionorder";

$questions = $DB->get_records_sql($sql, array('sessionid' => $sessionid));

if ($questions) {
    // Prepare data for chart
    $questionlabels = array();
    $correctdata = array();
    $incorrectdata = array();
    
    foreach ($questions as $q) {
        $questionlabels[] = 'Q' . $q->questionorder;
        $correctdata[] = $q->correct;
        $incorrectdata[] = $q->responses - $q->correct;
    }
    
    echo html_writer::tag('canvas', '', array('id' => 'questionChart', 'style' => 'max-height: 400px;'));
    
    echo html_writer::script("
        var ctx = document.getElementById('questionChart').getContext('2d');
        var chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: " . json_encode($questionlabels) . ",
                datasets: [{
                    label: 'Correct',
                    data: " . json_encode($correctdata) . ",
                    backgroundColor: 'rgba(75, 192, 192, 0.8)'
                }, {
                    label: 'Incorrect',
                    data: " . json_encode($incorrectdata) . ",
                    backgroundColor: 'rgba(255, 99, 132, 0.8)'
                }]
            },
            options: {
                responsive: true,
                scales: {
                    x: {
                        stacked: true
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true
                    }
                }
            }
        });
    ");
}

// Student performance table
echo html_writer::tag('h4', get_string('studentperformance', 'mod_classengage'), array('class' => 'mt-4'));

$sql = "SELECT u.id, u.firstname, u.lastname,
               COUNT(r.id) as totalresponses,
               SUM(r.iscorrect) as correctresponses,
               AVG(r.responsetime) as avgresponsetime
          FROM {user} u
          JOIN {classengage_responses} r ON r.userid = u.id
         WHERE r.sessionid = :sessionid
      GROUP BY u.id, u.firstname, u.lastname
      ORDER BY correctresponses DESC, avgresponsetime ASC";

$students = $DB->get_records_sql($sql, array('sessionid' => $sessionid));

if ($students) {
    $table = new html_table();
    $table->head = array(
        get_string('studentname', 'mod_classengage'),
        get_string('totalresponses', 'mod_classengage'),
        get_string('correctresponses', 'mod_classengage'),
        get_string('score', 'mod_classengage'),
        get_string('responsetime', 'mod_classengage')
    );
    $table->attributes['class'] = 'generaltable';
    
    foreach ($students as $student) {
        $percentage = $student->totalresponses > 0 ? 
            round(($student->correctresponses / $student->totalresponses) * 100, 1) : 0;
        
        $table->data[] = array(
            fullname($student),
            $student->totalresponses,
            $student->correctresponses,
            $percentage . '%',
            round($student->avgresponsetime, 1) . 's'
        );
    }
    
    echo html_writer::table($table);
}

// Export button
echo html_writer::start_div('mt-3');
$exporturl = new moodle_url('/mod/classengage/export.php', array('id' => $cm->id, 'sessionid' => $sessionid));
echo html_writer::link($exporturl, get_string('exportcsv', 'mod_classengage'), 
    array('class' => 'btn btn-secondary'));
echo html_writer::end_div();

echo $OUTPUT->footer();

