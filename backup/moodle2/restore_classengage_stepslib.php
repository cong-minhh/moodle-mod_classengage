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
 * Define all the restore steps that will be used by the restore_classengage_activity_task
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Structure step to restore one classengage activity
 */
class restore_classengage_activity_structure_step extends restore_activity_structure_step {

    /**
     * Defines structure of path elements to be processed during the restore
     *
     * @return array of restore_path_element
     */
    protected function define_structure() {

        $paths = array();
        $paths[] = new restore_path_element('classengage', '/activity/classengage');
        $paths[] = new restore_path_element('classengage_slide', '/activity/classengage/slides/slide');
        $paths[] = new restore_path_element('classengage_question', '/activity/classengage/questions/question');
        $paths[] = new restore_path_element('classengage_session', '/activity/classengage/sessions/session');
        $paths[] = new restore_path_element('classengage_session_question', 
            '/activity/classengage/sessions/session/session_questions/session_question');
        $paths[] = new restore_path_element('classengage_response', 
            '/activity/classengage/sessions/session/responses/response');

        // Return the paths wrapped into standard activity structure
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Process the classengage restore.
     */
    protected function process_classengage($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // Insert the classengage record
        $newitemid = $DB->insert_record('classengage', $data);
        // Immediately after inserting "activity" record, call this
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Process a slide restore
     */
    protected function process_classengage_slide($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->classengageid = $this->get_new_parentid('classengage');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('classengage_slides', $data);
        $this->set_mapping('classengage_slide', $oldid, $newitemid, true);
    }

    /**
     * Process a question restore
     */
    protected function process_classengage_question($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->classengageid = $this->get_new_parentid('classengage');
        $data->slideid = $this->get_mappingid('classengage_slide', $data->slideid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('classengage_questions', $data);
        $this->set_mapping('classengage_question', $oldid, $newitemid);
    }

    /**
     * Process a session restore
     */
    protected function process_classengage_session($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->classengageid = $this->get_new_parentid('classengage');
        $data->createdby = $this->get_mappingid('user', $data->createdby);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timestarted = $this->apply_date_offset($data->timestarted);
        $data->timecompleted = $this->apply_date_offset($data->timecompleted);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('classengage_sessions', $data);
        $this->set_mapping('classengage_session', $oldid, $newitemid);
    }

    /**
     * Process a session question restore
     */
    protected function process_classengage_session_question($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->sessionid = $this->get_new_parentid('classengage_session');
        $data->questionid = $this->get_mappingid('classengage_question', $data->questionid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        $newitemid = $DB->insert_record('classengage_session_questions', $data);
    }

    /**
     * Process a response restore
     */
    protected function process_classengage_response($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->sessionid = $this->get_new_parentid('classengage_session');
        $data->questionid = $this->get_mappingid('classengage_question', $data->questionid);
        $data->classengageid = $this->get_new_parentid('classengage');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        $newitemid = $DB->insert_record('classengage_responses', $data);
    }

    /**
     * Post-execution actions
     */
    protected function after_execute() {
        // Add classengage related files, no need to match by itemname (just internally handled context)
        $this->add_related_files('mod_classengage', 'intro', null);
        $this->add_related_files('mod_classengage', 'slides', 'classengage_slide');
    }
}

