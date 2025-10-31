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
 * Question management page
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');
require_once(__DIR__.'/classes/form/edit_question_form.php');

$id = required_param('id', PARAM_INT); // Course module ID
$action = optional_param('action', '', PARAM_ALPHA);
$questionid = optional_param('questionid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/classengage:managequestions', $context);

$PAGE->set_url('/mod/classengage/questions.php', array('id' => $cm->id));
$PAGE->set_title(format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Handle actions
if ($action === 'delete' && $questionid && confirm_sesskey()) {
    $DB->delete_records('classengage_questions', array('id' => $questionid, 'classengageid' => $classengage->id));
    redirect($PAGE->url, get_string('questiondeleted', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'approve' && $questionid && confirm_sesskey()) {
    $DB->set_field('classengage_questions', 'status', 'approved', array('id' => $questionid));
    redirect($PAGE->url, get_string('questionapproved', 'mod_classengage'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Handle edit form
$editurl = new moodle_url('/mod/classengage/editquestion.php', array('id' => $cm->id));

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

print_tabs(array($tabs), 'questions');

// Add question button
$addurl = new moodle_url('/mod/classengage/editquestion.php', array('id' => $cm->id));
echo html_writer::link($addurl, get_string('addquestion', 'mod_classengage'), 
    array('class' => 'btn btn-primary mb-3'));

echo html_writer::tag('h3', get_string('generatedquestions', 'mod_classengage'));

// List questions
$questions = $DB->get_records('classengage_questions', array('classengageid' => $classengage->id), 'timecreated DESC');

if ($questions) {
    $table = new html_table();
    $table->head = array(
        get_string('questiontext', 'mod_classengage'),
        get_string('difficulty', 'mod_classengage'),
        get_string('status', 'mod_classengage'),
        get_string('actions', 'mod_classengage')
    );
    $table->attributes['class'] = 'generaltable';
    
    foreach ($questions as $question) {
        $editurl = new moodle_url('/mod/classengage/editquestion.php', 
            array('id' => $cm->id, 'questionid' => $question->id));
        $deleteurl = new moodle_url('/mod/classengage/questions.php', 
            array('id' => $cm->id, 'action' => 'delete', 'questionid' => $question->id, 'sesskey' => sesskey()));
        $approveurl = new moodle_url('/mod/classengage/questions.php',
            array('id' => $cm->id, 'action' => 'approve', 'questionid' => $question->id, 'sesskey' => sesskey()));
        
        $editlink = html_writer::link($editurl, get_string('edit', 'mod_classengage'), 
            array('class' => 'btn btn-sm btn-secondary'));
        $deletelink = html_writer::link($deleteurl, get_string('delete', 'mod_classengage'), 
            array('class' => 'btn btn-sm btn-danger'));
        
        $actions = $editlink . ' ';
        
        if ($question->status !== 'approved') {
            $approvelink = html_writer::link($approveurl, get_string('approve', 'mod_classengage'),
                array('class' => 'btn btn-sm btn-success'));
            $actions .= $approvelink . ' ';
        }
        
        $actions .= $deletelink;
        
        // Truncate question text if too long
        $questiontext = format_string($question->questiontext);
        if (strlen($questiontext) > 100) {
            $questiontext = substr($questiontext, 0, 100) . '...';
        }
        
        $statusbadge = $question->status === 'approved' ? 
            '<span class="badge badge-success">'.get_string('approved', 'mod_classengage').'</span>' :
            '<span class="badge badge-warning">'.get_string('pending', 'mod_classengage').'</span>';
        
        $table->data[] = array(
            $questiontext,
            ucfirst($question->difficulty),
            $statusbadge,
            $actions
        );
    }
    
    echo html_writer::table($table);
} else {
    echo html_writer::div(get_string('noquestions', 'mod_classengage'), 'alert alert-info');
}

echo $OUTPUT->footer();

