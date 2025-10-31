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
 * Form for creating quiz sessions
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Create session form class
 */
class create_session_form extends \moodleform {

    /**
     * Define the form
     */
    public function definition() {
        $mform = $this->_form;
        $customdata = $this->_customdata;

        // Session name
        $mform->addElement('text', 'name', get_string('sessiontitle', 'mod_classengage'), array('size' => '60'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        // Number of questions
        $defaultnum = get_config('mod_classengage', 'defaultquestions');
        if (!$defaultnum) {
            $defaultnum = 10;
        }
        
        $mform->addElement('text', 'numquestions', get_string('numberofquestions', 'mod_classengage'), array('size' => '10'));
        $mform->setType('numquestions', PARAM_INT);
        $mform->setDefault('numquestions', $defaultnum);
        $mform->addRule('numquestions', null, 'required', null, 'client');
        $mform->addRule('numquestions', null, 'numeric', null, 'client');

        // Time limit per question
        $defaulttime = get_config('mod_classengage', 'defaulttimelimit');
        if (!$defaulttime) {
            $defaulttime = 30;
        }
        
        $mform->addElement('text', 'timelimit', get_string('timelimit', 'mod_classengage'), array('size' => '10'));
        $mform->setType('timelimit', PARAM_INT);
        $mform->setDefault('timelimit', $defaulttime);
        $mform->addRule('timelimit', null, 'required', null, 'client');

        // Shuffle options
        $mform->addElement('advcheckbox', 'shufflequestions', get_string('shufflequestions', 'mod_classengage'));
        $mform->setDefault('shufflequestions', 1);

        $mform->addElement('advcheckbox', 'shuffleanswers', get_string('shuffleanswers', 'mod_classengage'));
        $mform->setDefault('shuffleanswers', 1);

        // Buttons
        $this->add_action_buttons(true, get_string('createsession', 'mod_classengage'));
    }

    /**
     * Validation
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $DB;
        
        $errors = parent::validation($data, $files);
        
        // Check if there are enough approved questions
        $classengageid = $this->_customdata['classengageid'];
        $approvedcount = $DB->count_records('classengage_questions', 
            array('classengageid' => $classengageid, 'status' => 'approved'));
        
        if ($approvedcount < $data['numquestions']) {
            $errors['numquestions'] = get_string('notenoughquestions', 'mod_classengage', $approvedcount);
        }
        
        if ($data['numquestions'] < 1) {
            $errors['numquestions'] = get_string('minimumquestions', 'mod_classengage');
        }
        
        if ($data['timelimit'] < 5) {
            $errors['timelimit'] = get_string('minimumtimelimit', 'mod_classengage');
        }
        
        return $errors;
    }
}

