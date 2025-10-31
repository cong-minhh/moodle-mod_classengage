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
 * Edit question page
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');
require_once(__DIR__.'/classes/form/edit_question_form.php');

$id = required_param('id', PARAM_INT); // Course module ID
$questionid = optional_param('questionid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/classengage:managequestions', $context);

$returnurl = new moodle_url('/mod/classengage/questions.php', array('id' => $cm->id));
$PAGE->set_url('/mod/classengage/editquestion.php', array('id' => $cm->id, 'questionid' => $questionid));
$PAGE->set_title(format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$question = null;
if ($questionid) {
    $question = $DB->get_record('classengage_questions', 
        array('id' => $questionid, 'classengageid' => $classengage->id), '*', MUST_EXIST);
}

$mform = new \mod_classengage\form\edit_question_form($PAGE->url, 
    array('cmid' => $cm->id, 'classengageid' => $classengage->id, 'question' => $question));

if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $mform->get_data()) {
    if ($questionid) {
        // Update existing question
        $data->id = $questionid;
        $data->timemodified = time();
        $DB->update_record('classengage_questions', $data);
    } else {
        // Create new question
        $data->classengageid = $classengage->id;
        $data->source = 'manual';
        $data->status = 'approved';
        $data->timecreated = time();
        $data->timemodified = time();
        $DB->insert_record('classengage_questions', $data);
    }
    
    redirect($returnurl, get_string('questionupdated', 'mod_classengage'), 
        null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();

echo $OUTPUT->heading($questionid ? get_string('editquestion', 'mod_classengage') : get_string('addquestion', 'mod_classengage'));

$mform->display();

echo $OUTPUT->footer();

