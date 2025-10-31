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
 * Quiz session management page
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');
require_once(__DIR__.'/classes/form/create_session_form.php');
require_once(__DIR__.'/classes/session_manager.php');

$id = required_param('id', PARAM_INT); // Course module ID
$action = optional_param('action', '', PARAM_ALPHA);
$sessionid = optional_param('sessionid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/classengage:startquiz', $context);

$PAGE->set_url('/mod/classengage/sessions.php', array('id' => $cm->id));
$PAGE->set_title(format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$sessionmanager = new \mod_classengage\session_manager($classengage->id, $context);

// Handle actions
if ($action === 'start' && $sessionid && confirm_sesskey()) {
    $sessionmanager->start_session($sessionid);
    
    $event = \mod_classengage\event\session_started::create(array(
        'objectid' => $sessionid,
        'context' => $context,
        'other' => array('classengageid' => $classengage->id)
    ));
    $event->trigger();
    
    redirect($PAGE->url, get_string('sessionstarted', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'stop' && $sessionid && confirm_sesskey()) {
    $sessionmanager->stop_session($sessionid);
    
    $event = \mod_classengage\event\session_stopped::create(array(
        'objectid' => $sessionid,
        'context' => $context,
        'other' => array('classengageid' => $classengage->id)
    ));
    $event->trigger();
    
    redirect($PAGE->url, get_string('sessionstopped', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'nextquestion' && $sessionid && confirm_sesskey()) {
    $sessionmanager->next_question($sessionid);
    redirect(new moodle_url('/mod/classengage/controlpanel.php', array('id' => $cm->id, 'sessionid' => $sessionid)));
}

// Create session form
$mform = new \mod_classengage\form\create_session_form($PAGE->url, 
    array('cmid' => $cm->id, 'classengageid' => $classengage->id));

if ($mform->is_cancelled()) {
    redirect($PAGE->url);
} else if ($data = $mform->get_data()) {
    $sessionid = $sessionmanager->create_session($data, $USER->id);
    
    if ($sessionid) {
        redirect($PAGE->url, get_string('sessioncreated', 'mod_classengage'), 
            null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

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

print_tabs(array($tabs), 'sessions');

echo html_writer::tag('h3', get_string('createnewsession', 'mod_classengage'));
$mform->display();

echo html_writer::tag('h3', get_string('activesessions', 'mod_classengage'), array('class' => 'mt-4'));

// List active sessions
$sessions = $DB->get_records('classengage_sessions', 
    array('classengageid' => $classengage->id, 'status' => 'active'), 'timecreated DESC');

if ($sessions) {
    $table = new html_table();
    $table->head = array(
        get_string('sessionname', 'mod_classengage'),
        get_string('numberofquestions', 'mod_classengage'),
        get_string('participants', 'mod_classengage'),
        get_string('actions', 'mod_classengage')
    );
    $table->attributes['class'] = 'generaltable';
    
    foreach ($sessions as $session) {
        $controlurl = new moodle_url('/mod/classengage/controlpanel.php', 
            array('id' => $cm->id, 'sessionid' => $session->id));
        $stopurl = new moodle_url('/mod/classengage/sessions.php', 
            array('id' => $cm->id, 'action' => 'stop', 'sessionid' => $session->id, 'sesskey' => sesskey()));
        
        $controllink = html_writer::link($controlurl, get_string('controlpanel', 'mod_classengage'), 
            array('class' => 'btn btn-sm btn-primary'));
        $stoplink = html_writer::link($stopurl, get_string('stopsession', 'mod_classengage'), 
            array('class' => 'btn btn-sm btn-danger'));
        
        // Count participants
        $sql = "SELECT COUNT(DISTINCT userid) FROM {classengage_responses} WHERE sessionid = ?";
        $participantcount = $DB->count_records_sql($sql, array($session->id));
        
        $table->data[] = array(
            format_string($session->name),
            $session->numquestions,
            $participantcount,
            $controllink . ' ' . $stoplink
        );
    }
    
    echo html_writer::table($table);
} else {
    echo html_writer::div(get_string('noactivesessions', 'mod_classengage'), 'alert alert-info');
}

echo html_writer::tag('h3', get_string('readysessions', 'mod_classengage'), array('class' => 'mt-4'));

// List ready sessions
$sessions = $DB->get_records('classengage_sessions', 
    array('classengageid' => $classengage->id, 'status' => 'ready'), 'timecreated DESC');

if ($sessions) {
    $table = new html_table();
    $table->head = array(
        get_string('sessionname', 'mod_classengage'),
        get_string('numberofquestions', 'mod_classengage'),
        get_string('actions', 'mod_classengage')
    );
    $table->attributes['class'] = 'generaltable';
    
    foreach ($sessions as $session) {
        $starturl = new moodle_url('/mod/classengage/sessions.php', 
            array('id' => $cm->id, 'action' => 'start', 'sessionid' => $session->id, 'sesskey' => sesskey()));
        
        $startlink = html_writer::link($starturl, get_string('startsession', 'mod_classengage'), 
            array('class' => 'btn btn-sm btn-success'));
        
        $table->data[] = array(
            format_string($session->name),
            $session->numquestions,
            $startlink
        );
    }
    
    echo html_writer::table($table);
} else {
    echo html_writer::div(get_string('noreadysessions', 'mod_classengage'), 'alert alert-secondary');
}

echo html_writer::tag('h3', get_string('completedsessions', 'mod_classengage'), array('class' => 'mt-4'));

// List completed sessions
$sessions = $DB->get_records('classengage_sessions', 
    array('classengageid' => $classengage->id, 'status' => 'completed'), 'timecreated DESC', '*', 0, 10);

if ($sessions) {
    $table = new html_table();
    $table->head = array(
        get_string('sessionname', 'mod_classengage'),
        get_string('participants', 'mod_classengage'),
        get_string('completeddate', 'mod_classengage'),
        get_string('actions', 'mod_classengage')
    );
    $table->attributes['class'] = 'generaltable';
    
    foreach ($sessions as $session) {
        $viewurl = new moodle_url('/mod/classengage/sessionresults.php', 
            array('id' => $cm->id, 'sessionid' => $session->id));
        
        $viewlink = html_writer::link($viewurl, get_string('viewresults', 'mod_classengage'), 
            array('class' => 'btn btn-sm btn-info'));
        
        $sql = "SELECT COUNT(DISTINCT userid) FROM {classengage_responses} WHERE sessionid = ?";
        $participantcount = $DB->count_records_sql($sql, array($session->id));
        
        $table->data[] = array(
            format_string($session->name),
            $participantcount,
            userdate($session->timecompleted),
            $viewlink
        );
    }
    
    echo html_writer::table($table);
} else {
    echo html_writer::div(get_string('nocompletedsessions', 'mod_classengage'), 'alert alert-secondary');
}

echo $OUTPUT->footer();

