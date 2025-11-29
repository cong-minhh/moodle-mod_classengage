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

namespace mod_classengage\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/formslib.php');

/**
 * Export options form
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_form extends \moodleform {

    /**
     * Define the form
     */
    public function definition() {
        $mform = $this->_form;
        
        $mform->addElement('header', 'exportheader', get_string('exportoptions', 'mod_classengage'));
        
        // Report Type
        $reportoptions = [
            'summary' => get_string('report_summary', 'mod_classengage'),
            'participation' => get_string('report_participation', 'mod_classengage'),
            'questions' => get_string('report_questions', 'mod_classengage'),
            'raw' => get_string('report_raw', 'mod_classengage'),
        ];
        $mform->addElement('select', 'reporttype', get_string('reporttype', 'mod_classengage'), $reportoptions);
        $mform->setDefault('reporttype', 'summary');
        $mform->addHelpButton('reporttype', 'reporttype', 'mod_classengage');
        
        // Format
        $formats = \core_plugin_manager::instance()->get_plugins_of_type('dataformat');
        $formatoptions = [];
        foreach ($formats as $format) {
            if ($format->is_enabled()) {
                $formatoptions[$format->name] = get_string('dataformat', $format->component);
            }
        }
        $mform->addElement('select', 'format', get_string('format'), $formatoptions);
        $mform->setDefault('format', 'csv');
        
        // Hidden fields
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        
        $mform->addElement('hidden', 'sessionid');
        $mform->setType('sessionid', PARAM_INT);
        
        // Buttons
        $this->add_action_buttons(true, get_string('export', 'mod_classengage'));
    }
}
