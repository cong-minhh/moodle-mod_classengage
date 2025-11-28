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
 * The main classengage configuration form
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');

/**
 * Module instance settings form
 */
class mod_classengage_mod_form extends moodleform_mod {

    /**
     * Defines forms elements
     */
    public function definition() {
        global $CFG;

        $mform = $this->_form;

        // General settings
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Name field
        $mform->addElement('text', 'name', get_string('name'), array('size' => '64'));
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Introduction field
        $this->standard_intro_elements();

        // Grading settings
        $mform->addElement('header', 'gradesettings', get_string('gradesettings', 'mod_classengage'));

        // Grade
        $mform->addElement('text', 'grade', get_string('grade', 'mod_classengage'), array('size' => '10'));
        $mform->setType('grade', PARAM_INT);
        $mform->setDefault('grade', 100);
        $mform->addHelpButton('grade', 'grade', 'mod_classengage');

        // Standard elements
        $this->standard_coursemodule_elements();

        // Buttons
        $this->add_action_buttons();
    }

    /**
     * Add any custom completion rules to the form.
     *
     * @return array Array of string IDs of added items, empty array if none
     */
    public function add_completion_rules() {
        return array();
    }

    /**
     * Called during validation to see whether some module-specific completion rules are selected.
     *
     * @param array $data Input data not yet validated.
     * @return bool True if one or more rules is enabled, false if none are.
     */
    public function completion_rule_enabled($data) {
        return false;
    }
}

