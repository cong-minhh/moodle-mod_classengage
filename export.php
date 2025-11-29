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
 * Export analytics data
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');
require_once(__DIR__.'/classes/form/export_form.php');
require_once(__DIR__.'/classes/export_manager.php');

$id = required_param('id', PARAM_INT); // Course module ID.
$sessionid = required_param('sessionid', PARAM_INT);

// Validate database records.
$cm = get_coursemodule_from_id('classengage', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $cm->instance), '*', MUST_EXIST);
$session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);

// Verify session belongs to this activity.
if ($session->classengageid != $classengage->id) {
    throw new moodle_exception('invalidsession', 'mod_classengage');
}

// Security checks.
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/classengage:viewanalytics', $context);

$PAGE->set_url('/mod/classengage/export.php', array('id' => $cm->id, 'sessionid' => $sessionid));
$PAGE->set_title(format_string($classengage->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Instantiate form.
$mform = new \mod_classengage\form\export_form(null, array('id' => $id, 'sessionid' => $sessionid));

// Set default data.
$toform = new stdClass();
$toform->id = $id;
$toform->sessionid = $sessionid;
$mform->set_data($toform);

// Handle form submission.
if ($mform->is_cancelled()) {
    redirect(new moodle_url('/mod/classengage/analytics.php', array('id' => $id, 'sessionid' => $sessionid)));
} else if ($data = $mform->get_data()) {
    
    $exportmanager = new \mod_classengage\export_manager($sessionid, $course->id);
    $filename = clean_filename($classengage->name . '_' . $session->name . '_' . $data->reporttype . '_' . date('Y-m-d'));
    
    switch ($data->reporttype) {
        case 'summary':
            $exportdata = $exportmanager->get_session_summary_data();
            $columns = [
                'sessionname' => get_string('sessionname', 'mod_classengage'),
                'completeddate' => get_string('completeddate', 'mod_classengage'),
                'engagementpercentage' => get_string('engagementpercentage', 'mod_classengage'),
                'engagementlevel' => get_string('engagementlevel', 'mod_classengage'),
                'comprehensionsummary' => get_string('comprehensionsummary', 'mod_classengage'),
                'questionsanswered' => get_string('questionsanswered', 'mod_classengage'),
                'pollsubmissions' => get_string('pollsubmissions', 'mod_classengage'),
                'reactions' => get_string('reactions', 'mod_classengage'),
                'responsiveness' => get_string('responsiveness', 'mod_classengage'),
                'avgresponsetime' => get_string('avgresponsetime', 'mod_classengage'),
                'difficultconcepts' => get_string('difficultconcepts', 'mod_classengage'),
                'recommendations' => get_string('teachingrecommendations', 'mod_classengage')
            ];
            break;
            
        case 'participation':
            $exportdata = $exportmanager->get_student_participation_data();
            $columns = [
                'fullname' => get_string('studentname', 'mod_classengage'),
                'email' => get_string('email'),
                'responsecount' => get_string('totalresponses', 'mod_classengage'),
                'correctcount' => get_string('correctresponses', 'mod_classengage'),
                'score' => get_string('score', 'mod_classengage'),
                'avgtime' => get_string('avgresponsetime', 'mod_classengage')
            ];
            break;
            
        case 'questions':
            $exportdata = $exportmanager->get_question_analysis_data();
            $columns = [
                'question' => get_string('question', 'mod_classengage'),
                'type' => get_string('questiontype', 'mod_classengage'),
                'correctanswer' => get_string('correctanswer', 'mod_classengage'),
                'totalresponses' => get_string('totalresponses', 'mod_classengage'),
                'correctresponses' => get_string('correctresponses', 'mod_classengage'),
                'correctnessrate' => get_string('correctnessrate', 'mod_classengage'),
                'avgtime' => get_string('avgresponsetime', 'mod_classengage')
            ];
            break;
            
        case 'raw':
            $exportdata = $exportmanager->get_raw_response_data();
            $columns = [
                'username' => get_string('username'),
                'question' => get_string('question', 'mod_classengage'),
                'answer' => get_string('answer', 'mod_classengage'),
                'iscorrect' => get_string('correct', 'mod_classengage'),
                'responsetime' => get_string('responsetime', 'mod_classengage'),
                'timestamp' => get_string('time')
            ];
            break;
            
        default:
            throw new moodle_exception('invalidreporttype', 'mod_classengage');
    }
    
    \core\dataformat::download_data(
        $filename,
        $data->format,
        $columns,
        $exportdata,
        function($row) {
            return (array)$row;
        }
    );
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('exportanalytics', 'mod_classengage'));

$mform->display();

echo $OUTPUT->footer();
