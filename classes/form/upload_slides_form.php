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
 * Form for uploading slides
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Upload slides form class
 */
class upload_slides_form extends \moodleform {

    /**
     * Define the form
     */
    public function definition() {
        $mform = $this->_form;
        $customdata = $this->_customdata;

        // Title field
        $mform->addElement('text', 'title', get_string('slidetitle', 'mod_classengage'), array('size' => '60'));
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');

        // File picker
        $maxbytes = get_config('mod_classengage', 'maxfilesize');
        if (!$maxbytes) {
            $maxbytes = 50 * 1024 * 1024; // 50MB default
        } else {
            $maxbytes = $maxbytes * 1024 * 1024;
        }

        $mform->addElement('filemanager', 'slidefile', get_string('slidefile', 'mod_classengage'), null,
            array(
                'subdirs' => 0,
                'maxbytes' => $maxbytes,
                'maxfiles' => 1,
                'accepted_types' => array('.pdf', '.ppt', '.pptx')
            ));
        $mform->addRule('slidefile', null, 'required', null, 'client');

        // Hidden fields
        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);
        $mform->setDefault('cmid', $customdata['cmid']);

        $mform->addElement('hidden', 'contextid');
        $mform->setType('contextid', PARAM_INT);
        $mform->setDefault('contextid', $customdata['contextid']);

        // Buttons
        $this->add_action_buttons(true, get_string('uploadslide', 'mod_classengage'));
    }

    /**
     * Validation
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        
        return $errors;
    }
}

