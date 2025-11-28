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
 * Form for editing questions
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Edit question form class
 */
class edit_question_form extends \moodleform {

    /**
     * Define the form
     */
    public function definition() {
        $mform = $this->_form;
        $customdata = $this->_customdata;
        $question = $customdata['question'];

        // Question text
        $mform->addElement('textarea', 'questiontext', get_string('questiontext', 'mod_classengage'), 
            array('rows' => 4, 'cols' => 60));
        $mform->setType('questiontext', PARAM_TEXT);
        $mform->addRule('questiontext', null, 'required', null, 'client');

        // Question type
        $types = array(
            'multichoice' => get_string('multichoice', 'mod_classengage'),
        );
        $mform->addElement('select', 'questiontype', get_string('questiontype', 'mod_classengage'), $types);
        $mform->setDefault('questiontype', 'multichoice');

        // Options
        $mform->addElement('textarea', 'optiona', get_string('optiona', 'mod_classengage'), 
            array('rows' => 2, 'cols' => 60));
        $mform->setType('optiona', PARAM_TEXT);
        $mform->addRule('optiona', null, 'required', null, 'client');

        $mform->addElement('textarea', 'optionb', get_string('optionb', 'mod_classengage'), 
            array('rows' => 2, 'cols' => 60));
        $mform->setType('optionb', PARAM_TEXT);
        $mform->addRule('optionb', null, 'required', null, 'client');

        $mform->addElement('textarea', 'optionc', get_string('optionc', 'mod_classengage'), 
            array('rows' => 2, 'cols' => 60));
        $mform->setType('optionc', PARAM_TEXT);

        $mform->addElement('textarea', 'optiond', get_string('optiond', 'mod_classengage'), 
            array('rows' => 2, 'cols' => 60));
        $mform->setType('optiond', PARAM_TEXT);

        // Correct answer
        $answers = array('A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D');
        $mform->addElement('select', 'correctanswer', get_string('correctanswer', 'mod_classengage'), $answers);
        $mform->addRule('correctanswer', null, 'required', null, 'client');

        // Difficulty
        $difficulties = array(
            'easy' => get_string('easy', 'mod_classengage'),
            'medium' => get_string('medium', 'mod_classengage'),
            'hard' => get_string('hard', 'mod_classengage'),
        );
        $mform->addElement('select', 'difficulty', get_string('difficulty', 'mod_classengage'), $difficulties);
        $mform->setDefault('difficulty', 'medium');

        // Buttons
        $this->add_action_buttons(true, get_string('savequestion', 'mod_classengage'));

        // Set defaults if editing
        if ($question) {
            $this->set_data($question);
        }
    }
}

