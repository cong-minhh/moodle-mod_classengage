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
 * Instructor control panel for managing live quiz sessions
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');
require_once(__DIR__.'/classes/session_manager.php');

$id = required_param('id', PARAM_INT); // Course module ID
$sessionid = required_param('sessionid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);
$session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/classengage:startquiz', $context);

$PAGE->set_url('/mod/classengage/controlpanel.php', array('id' => $cm->id, 'sessionid' => $sessionid));
$PAGE->set_title(format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$sessionmanager = new \mod_classengage\session_manager($classengage->id, $context);

// Handle actions
if ($action === 'next' && confirm_sesskey()) {
    $sessionmanager->next_question($sessionid);
    redirect($PAGE->url);
}

if ($action === 'stop' && confirm_sesskey()) {
    $sessionmanager->stop_session($sessionid);
    redirect(new moodle_url('/mod/classengage/sessions.php', array('id' => $cm->id)));
}

// Auto-refresh page
$PAGE->requires->js_init_code("
    setInterval(function() {
        location.reload();
    }, 5000);
");

echo $OUTPUT->header();

echo $OUTPUT->heading(format_string($session->name));

echo html_writer::tag('h4', get_string('controlpanel', 'mod_classengage'));

// Session status
echo html_writer::start_div('row mb-4');

echo html_writer::start_div('col-md-4');
echo html_writer::start_div('card');
echo html_writer::div(
    html_writer::tag('h5', get_string('currentquestion', 'mod_classengage', 
        array('current' => $session->currentquestion + 1, 'total' => $session->numquestions)), 
        array('class' => 'card-title')) .
    html_writer::tag('p', 'Status: ' . ucfirst($session->status), array('class' => 'h3')),
    'card-body'
);
echo html_writer::end_div();
echo html_writer::end_div();

// Participant count
$sql = "SELECT COUNT(DISTINCT userid) FROM {classengage_responses} WHERE sessionid = ?";
$participantcount = $DB->count_records_sql($sql, array($sessionid));

echo html_writer::start_div('col-md-4');
echo html_writer::start_div('card');
echo html_writer::div(
    html_writer::tag('h5', get_string('participants', 'mod_classengage'), array('class' => 'card-title')) .
    html_writer::tag('p', $participantcount, array('class' => 'display-4')),
    'card-body text-center'
);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();

// Current question display
if ($session->status === 'active') {
    $currentq = $sessionmanager->get_current_question($sessionid);
    
    if ($currentq) {
        echo html_writer::tag('h5', get_string('currentquestiontext', 'mod_classengage'));
        echo html_writer::div(
            html_writer::tag('p', format_text($currentq->questiontext), array('class' => 'lead')),
            'card card-body mb-3'
        );
        
        // Response statistics
        $sql = "SELECT answer, COUNT(*) as count
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid AND questionid = :questionid
              GROUP BY answer";
        
        $responses = $DB->get_records_sql($sql, array(
            'sessionid' => $sessionid,
            'questionid' => $currentq->id
        ));
        
        echo html_writer::tag('h5', get_string('liveresponses', 'mod_classengage'));
        
        $table = new html_table();
        $table->head = array('Answer', 'Count', 'Percentage');
        $table->attributes['class'] = 'generaltable';
        
        $total = array_sum(array_column((array)$responses, 'count'));
        
        foreach (array('A', 'B', 'C', 'D') as $option) {
            $count = 0;
            foreach ($responses as $r) {
                if (strtoupper($r->answer) === $option) {
                    $count = $r->count;
                    break;
                }
            }
            
            $percentage = $total > 0 ? round(($count / $total) * 100, 1) : 0;
            $iscorrect = (strtoupper($currentq->correctanswer) === $option);
            
            $row = array(
                ($iscorrect ? '✓ ' : '') . $option,
                $count,
                $percentage . '%'
            );
            
            if ($iscorrect) {
                $table->rowclasses[] = 'table-success';
            } else {
                $table->rowclasses[] = '';
            }
            
            $table->data[] = $row;
        }
        
        echo html_writer::table($table);
    }
    
    // Control buttons
    echo html_writer::start_div('mt-4');
    
    if ($session->currentquestion + 1 < $session->numquestions) {
        $nexturl = new moodle_url('/mod/classengage/controlpanel.php', 
            array('id' => $cm->id, 'sessionid' => $sessionid, 'action' => 'next', 'sesskey' => sesskey()));
        echo html_writer::link($nexturl, get_string('nextquestion', 'mod_classengage'), 
            array('class' => 'btn btn-primary btn-lg mr-2'));
    }
    
    $stopurl = new moodle_url('/mod/classengage/controlpanel.php',
        array('id' => $cm->id, 'sessionid' => $sessionid, 'action' => 'stop', 'sesskey' => sesskey()));
    echo html_writer::link($stopurl, get_string('stopsession', 'mod_classengage'),
        array('class' => 'btn btn-danger btn-lg'));
    
    echo html_writer::end_div();
    
} else {
    echo html_writer->div(get_string('sessionnotactive', 'mod_classengage'), 'alert alert-warning');
}

echo $OUTPUT->footer();

