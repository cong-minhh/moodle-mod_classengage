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

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

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

echo $OUTPUT->header();

echo $OUTPUT->heading(format_string($classengage->name));

// Introduction
if ($classengage->intro) {
    echo $OUTPUT->box(format_module_intro('classengage', $classengage, $cm->id), 'generalbox', 'intro');
}

// Check if user is teacher or student
$isteacher = has_capability('mod/classengage:managequestions', $context);

if ($isteacher) {
    // Teacher view - tabs for different functions
    echo html_writer::start_div('classengage-teacher-view');
    
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
    
    print_tabs(array($tabs), 'slides');
    
    echo html_writer::div(get_string('teacherwelcome', 'mod_classengage'), 'alert alert-info');
    
    echo html_writer::end_div();
} else {
    // Student view
    echo html_writer::start_div('classengage-student-view');
    
    // Check for active session
    $activesession = $DB->get_record('classengage_sessions', array(
        'classengageid' => $classengage->id,
        'status' => 'active'
    ));
    
    if ($activesession) {
        $quizurl = new moodle_url('/mod/classengage/quiz.php', array('id' => $cm->id, 'sessionid' => $activesession->id));
        echo html_writer::div(
            html_writer::link($quizurl, get_string('joinquiz', 'mod_classengage'), 
                            array('class' => 'btn btn-primary btn-lg')),
            'text-center mb-3'
        );
    } else {
        echo html_writer::div(get_string('nosession', 'mod_classengage'), 'alert alert-info');
    }
    
    // Show past results
    echo html_writer::tag('h3', get_string('yourresults', 'mod_classengage'));
    
    $sql = "SELECT s.name, r.score, r.timecreated
              FROM {classengage_sessions} s
              JOIN {classengage_responses} r ON r.sessionid = s.id
             WHERE s.classengageid = :classengageid
               AND r.userid = :userid
          ORDER BY r.timecreated DESC";
    
    $results = $DB->get_records_sql($sql, array('classengageid' => $classengage->id, 'userid' => $USER->id));
    
    if ($results) {
        $table = new html_table();
        $table->head = array(get_string('sessionname', 'mod_classengage'), 
                           get_string('score', 'mod_classengage'), 
                           get_string('date'));
        
        foreach ($results as $result) {
            $table->data[] = array(
                format_string($result->name),
                $result->score,
                userdate($result->timecreated)
            );
        }
        
        echo html_writer::table($table);
    } else {
        echo html_writer::div(get_string('noresults', 'mod_classengage'), 'alert alert-secondary');
    }
    
    echo html_writer::end_div();
}

echo $OUTPUT->footer();

