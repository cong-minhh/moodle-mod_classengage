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
 * Prints a particular instance of classengage
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// Course_module ID
$id = optional_param('id', 0, PARAM_INT);

// Activity instance ID
$c = optional_param('c', 0, PARAM_INT);

if ($id) {
    $cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);
} else if ($c) {
    $classengage = $DB->get_record('classengage', array('id' => $c), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $classengage->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('classengage', $classengage->id, $course->id, false, MUST_EXIST);
} else {
    print_error('missingidandcmid', 'mod_classengage');
}

require_login($course, true, $cm);

$context = context_module::instance($cm->id);

// Trigger course_module_viewed event
$event = \mod_classengage\event\course_module_viewed::create(array(
    'objectid' => $classengage->id,
    'context' => $context
));
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('classengage', $classengage);
$event->add_record_snapshot('course_modules', $cm);
$event->trigger();

// Print the page header
$PAGE->set_url('/mod/classengage/view.php', array('id' => $cm->id));
$PAGE->set_title(format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Check if user is teacher or student - do this before any output
$isteacher = has_capability('mod/classengage:managequestions', $context);

if ($isteacher) {
    // Teacher view - redirect to slides.php which is the default tab
    // This ensures they see the slides content when clicking the module
    redirect(new moodle_url('/mod/classengage/slides.php', array('id' => $cm->id)));
}

echo $OUTPUT->header();

echo $OUTPUT->heading(format_string($classengage->name));

// Introduction
if ($classengage->intro) {
    echo $OUTPUT->box(format_module_intro('classengage', $classengage, $cm->id), 'generalbox', 'intro');
}

// Student view only reaches here (teachers are redirected above)
echo html_writer::start_div('classengage-student-view');

// Hero section for quick actions
echo html_writer::start_div('card mb-4', array('style' => 'background: linear-gradient(135deg, #4a90a4 0%, #5bc0de 100%); color: white; border: none;'));
echo html_writer::start_div('card-body py-4');
echo html_writer::start_div('d-flex justify-content-between align-items-center flex-wrap gap-3');

// Check for active session
$activesession = $DB->get_record('classengage_sessions', array(
    'classengageid' => $classengage->id,
    'status' => 'active'
));

// Get latest completed session (use SQL to avoid duplicates)
$latest_completed = $DB->get_record_sql(
    "SELECT id FROM {classengage_sessions} 
     WHERE classengageid = :classengageid AND status = 'completed' 
     ORDER BY timecompleted DESC LIMIT 1",
    array('classengageid' => $classengage->id)
);

if ($activesession) {
    $quizurl = new moodle_url('/mod/classengage/quiz.php', array('id' => $cm->id, 'sessionid' => $activesession->id));
    echo html_writer::div(
        html_writer::link(
            $quizurl,
            '<i class="fa fa-play-circle"></i> ' . get_string('joinquiz', 'mod_classengage'),
            array('class' => 'btn btn-light btn-lg')
        ),
        'text-center flex-grow-1'
    );
    
    // Also show results from latest completed session
    if ($latest_completed) {
        $resultsurl = new moodle_url('/mod/classengage/myresults.php', array('id' => $cm->id, 'sessionid' => $latest_completed->id));
        echo html_writer::div(
            html_writer::link(
                $resultsurl,
                '<i class="fa fa-chart-bar"></i> View Past Results',
                array('class' => 'btn btn-outline-light btn-lg')
            ),
            'text-center flex-grow-1'
        );
    }
} else {
    // No active session - show message and link to results
    echo html_writer::div(
        '<i class="fa fa-info-circle fa-2x mb-2"></i><br><strong>No Active Quiz</strong><br><small>Wait for your instructor to start a session</small>',
        'text-center flex-grow-1'
    );
    
    // Link to latest results if available
    if ($latest_completed) {
        $resultsurl = new moodle_url('/mod/classengage/myresults.php', array('id' => $cm->id, 'sessionid' => $latest_completed->id));
        echo html_writer::div(
            html_writer::link(
                $resultsurl,
                '<i class="fa fa-chart-bar"></i> View Your Results',
                array('class' => 'btn btn-light btn-lg')
            ),
            'text-center flex-grow-1'
        );
    }
}
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// Get user's sessions with their best scores - using subquery to avoid duplicates
$sql = "SELECT s.id, s.name, 
            (SELECT AVG(r.score) FROM {classengage_responses} r WHERE r.sessionid = s.id AND r.userid = :userid1) as avgscore,
            (SELECT MAX(r.timecreated) FROM {classengage_responses} r WHERE r.sessionid = s.id AND r.userid = :userid2) as lastattempt
          FROM {classengage_sessions} s
         WHERE s.classengageid = :classengageid
           AND EXISTS (SELECT 1 FROM {classengage_responses} r WHERE r.sessionid = s.id AND r.userid = :userid3)
      ORDER BY lastattempt DESC";

$results = $DB->get_records_sql($sql, array(
    'classengageid' => $classengage->id, 
    'userid1' => $USER->id,
    'userid2' => $USER->id,
    'userid3' => $USER->id
));

// Show past results table only if there are multiple sessions
if ($results && count($results) > 1) {
    echo html_writer::tag('h3', '<i class="fa fa-history mr-2"></i>' . get_string('yourresults', 'mod_classengage'));
    
    // Add table styling
    $table = new html_table();
    $table->head = array(
        get_string('sessionname', 'mod_classengage'),
        get_string('score', 'mod_classengage'),
        get_string('date'),
        ''
    );
    $table->attributes['class'] = 'table table-hover';

    foreach ($results as $result) {
        $score = is_numeric($result->avgscore) ? round($result->avgscore, 1) . '%' : $result->avgscore;
        
        // Score badge color
        $score_class = 'badge-secondary';
        if ($result->avgscore >= 80) {
            $score_class = 'badge-success';
        } else if ($result->avgscore >= 50) {
            $score_class = 'badge-warning';
        } else if ($result->avgscore >= 0) {
            $score_class = 'badge-danger';
        }
        
        $viewurl = new moodle_url('/mod/classengage/myresults.php', array('id' => $cm->id, 'sessionid' => $result->id));
        $viewlink = html_writer::link($viewurl, '<i class="fa fa-eye"></i> View Details', array('class' => 'btn btn-sm btn-primary'));
        
        $sessionlink = html_writer::link($viewurl, format_string($result->name), array('class' => 'text-primary font-weight-bold'));
        
        $table->data[] = array(
            $sessionlink,
            '<span class="badge ' . $score_class . '">' . $score . '</span>',
            '<small class="text-muted">' . userdate($result->lastattempt, get_string('strftimedate', 'langconfig')) . '</small>',
            $viewlink
        );
    }

    echo html_writer::table($table);
    
    // Quick link to latest result
    $latest = reset($results);
    if ($latest) {
        $viewurl = new moodle_url('/mod/classengage/myresults.php', array('id' => $cm->id, 'sessionid' => $latest->id));
        echo html_writer::div(
            html_writer::link($viewurl, '<i class="fa fa-arrow-right"></i> View Latest Results in Detail', 
                array('class' => 'btn btn-outline-primary')),
            'text-center mt-3'
        );
    }
}

echo html_writer::end_div();

echo $OUTPUT->footer();
