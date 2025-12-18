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
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');
require_once(__DIR__.'/classes/form/create_session_form.php');
require_once(__DIR__.'/classes/session_manager.php');

$id = required_param('id', PARAM_INT); // Course module ID
$action = optional_param('action', '', PARAM_ALPHA);
$sessionid = optional_param('sessionid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

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

if ($action === 'delete' && $sessionid && confirm_sesskey()) {
    if (!$confirm) {
        $continueurl = new moodle_url($PAGE->url, array('action' => 'delete', 'sessionid' => $sessionid, 'confirm' => 1, 'sesskey' => sesskey()));
        $cancelurl = $PAGE->url;
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('deleteconfirm', 'mod_classengage'), $continueurl, $cancelurl);
        echo $OUTPUT->footer();
        exit;
    }
    
    $sessionmanager->delete_session($sessionid);
    redirect($PAGE->url, get_string('sessiondeleted', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'nextquestion' && $sessionid && confirm_sesskey()) {
    $sessionmanager->next_question($sessionid);
    redirect(new moodle_url('/mod/classengage/controlpanel.php', array('id' => $cm->id, 'sessionid' => $sessionid)));
}

// Handle Bulk Actions
if ($data = data_submitted() && confirm_sesskey()) {
    $bulkaction = optional_param('bulkaction', '', PARAM_ALPHA);
    $selectedsessions = optional_param_array('sessionids', array(), PARAM_INT);
    
    if (!empty($selectedsessions) && !empty($bulkaction)) {
        if ($bulkaction === 'delete') {
            $sessionmanager->delete_sessions($selectedsessions);
            redirect($PAGE->url, get_string('sessionsdeleted', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
        } else if ($bulkaction === 'stop') {
            foreach ($selectedsessions as $sid) {
                $sessionmanager->stop_session($sid);
            }
            redirect($PAGE->url, get_string('sessionsstopped', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
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

// Add JavaScript for "Select All" functionality
$PAGE->requires->js_init_code("
    document.addEventListener('DOMContentLoaded', function() {
        var selectAllToggles = document.querySelectorAll('.select-all-toggle');
        selectAllToggles.forEach(function(toggle) {
            toggle.addEventListener('change', function() {
                var targetClass = this.getAttribute('data-target');
                var checkboxes = document.querySelectorAll('.' + targetClass);
                checkboxes.forEach(function(checkbox) {
                    checkbox.checked = toggle.checked;
                });
            });
        });
    });
");

echo html_writer::tag('h3', get_string('createnewsession', 'mod_classengage'), array('class' => 'mt-4 mb-3'));
$mform->display();

// Helper function to render session table
function render_session_table($sessions, $cm, $type) {
    global $OUTPUT;
    
    if (!$sessions) {
        return html_writer::div(get_string('no' . $type . 'sessions', 'mod_classengage'), 'alert alert-info mt-3');
    }
    
    $formurl = new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id));
    $o = html_writer::start_tag('form', array('action' => $formurl, 'method' => 'post'));
    $o .= html_writer::input_hidden_params($formurl);
    $o .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
    
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table table-hover';
    
    $head = array(
        html_writer::checkbox('selectall', 1, false, '', array('class' => 'select-all-toggle', 'data-target' => 'session-checkbox-' . $type)),
        get_string('sessionname', 'mod_classengage'),
        get_string('numberofquestions', 'mod_classengage'),
    );
    
    if ($type === 'active' || $type === 'completed') {
        $head[] = get_string('participants', 'mod_classengage');
    }
    
    if ($type === 'completed') {
        $head[] = get_string('completeddate', 'mod_classengage');
    }
    
    $head[] = get_string('actions', 'mod_classengage');
    
    $table->head = $head;
    
    foreach ($sessions as $session) {
        $checkbox = html_writer::checkbox('sessionids[]', $session->id, false, '', array('class' => 'session-checkbox-' . $type));
        
        $row = array($checkbox, format_string($session->name), $session->numquestions);
        
        if ($type === 'active' || $type === 'completed') {
            global $DB;
            $sql = "SELECT COUNT(DISTINCT userid) FROM {classengage_responses} WHERE sessionid = ?";
            $participantcount = $DB->count_records_sql($sql, array($session->id));
            $row[] = $participantcount;
        }
        
        if ($type === 'completed') {
            $row[] = userdate($session->timecompleted);
        }
        
        // Actions
        $actions = array();
        
        if ($type === 'active') {
            $controlurl = new moodle_url('/mod/classengage/controlpanel.php', array('id' => $cm->id, 'sessionid' => $session->id));
            $actions[] = html_writer::link($controlurl, get_string('controlpanel', 'mod_classengage'), array('class' => 'btn btn-sm btn-primary mr-1'));
            
            $stopurl = new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id, 'action' => 'stop', 'sessionid' => $session->id, 'sesskey' => sesskey()));
            $actions[] = html_writer::link($stopurl, get_string('stopsession', 'mod_classengage'), array('class' => 'btn btn-sm btn-warning mr-1'));
        } else if ($type === 'ready') {
            $starturl = new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id, 'action' => 'start', 'sessionid' => $session->id, 'sesskey' => sesskey()));
            $actions[] = html_writer::link($starturl, get_string('startsession', 'mod_classengage'), array('class' => 'btn btn-sm btn-success mr-1'));
        } else if ($type === 'completed') {
            $viewurl = new moodle_url('/mod/classengage/sessionresults.php', array('id' => $cm->id, 'sessionid' => $session->id));
            $actions[] = html_writer::link($viewurl, get_string('viewresults', 'mod_classengage'), array('class' => 'btn btn-sm btn-info mr-1'));
        }
        
        $deleteurl = new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id, 'action' => 'delete', 'sessionid' => $session->id, 'sesskey' => sesskey()));
        $actions[] = html_writer::link($deleteurl, $OUTPUT->pix_icon('t/delete', get_string('delete')), array('class' => 'btn btn-sm btn-link text-danger', 'title' => get_string('delete')));
        
        $row[] = implode(' ', $actions);
        
        $table->data[] = $row;
    }
    
    $o .= html_writer::table($table);
    
    // Bulk actions
    $o .= html_writer::start_div('d-flex align-items-center mt-2 mb-4');
    $o .= html_writer::tag('span', get_string('withselected', 'mod_classengage') . ': ', array('class' => 'mr-2'));
    
    $bulkoptions = array('delete' => get_string('delete'));
    if ($type === 'active') {
        $bulkoptions['stop'] = get_string('stop', 'mod_classengage');
    }
    
    $o .= html_writer::select($bulkoptions, 'bulkaction', '', array('' => get_string('choose', 'moodle')), array('class' => 'custom-select w-auto mr-2'));
    $o .= html_writer::empty_tag('input', array('type' => 'submit', 'value' => get_string('go'), 'class' => 'btn btn-secondary'));
    $o .= html_writer::end_div();
    
    $o .= html_writer::end_tag('form');
    
    return $o;
}

echo html_writer::tag('h3', get_string('activesessions', 'mod_classengage'), array('class' => 'mt-5'));
// Include both active and paused sessions in the active sessions list.
$sql = "SELECT * FROM {classengage_sessions}
         WHERE classengageid = :classengageid
           AND (status = 'active' OR status = 'paused')
      ORDER BY timecreated DESC";
$sessions = $DB->get_records_sql($sql, array('classengageid' => $classengage->id));
echo render_session_table($sessions, $cm, 'active');

echo html_writer::tag('h3', get_string('readysessions', 'mod_classengage'), array('class' => 'mt-4'));
$sessions = $DB->get_records('classengage_sessions', array('classengageid' => $classengage->id, 'status' => 'ready'), 'timecreated DESC');
echo render_session_table($sessions, $cm, 'ready');

echo html_writer::tag('h3', get_string('completedsessions', 'mod_classengage'), array('class' => 'mt-4'));
$sessions = $DB->get_records('classengage_sessions', array('classengageid' => $classengage->id, 'status' => 'completed'), 'timecreated DESC', '*', 0, 20);
echo render_session_table($sessions, $cm, 'completed');

echo $OUTPUT->footer();

