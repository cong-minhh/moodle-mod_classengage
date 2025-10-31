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
 * Define all the backup steps that will be used by the backup_classengage_activity_task
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Define the complete classengage structure for backup, with file and id annotations
 */
class backup_classengage_activity_structure_step extends backup_activity_structure_step {

    /**
     * Defines the backup structure of the module
     *
     * @return backup_nested_element
     */
    protected function define_structure() {

        // Define each element separated
        $classengage = new backup_nested_element('classengage', array('id'), array(
            'name', 'intro', 'introformat', 'grade', 'timecreated', 'timemodified'));

        $slides = new backup_nested_element('slides');
        $slide = new backup_nested_element('slide', array('id'), array(
            'title', 'filename', 'filepath', 'filesize', 'mimetype',
            'extractedtext', 'status', 'userid', 'timecreated', 'timemodified'));

        $questions = new backup_nested_element('questions');
        $question = new backup_nested_element('question', array('id'), array(
            'slideid', 'questiontext', 'questiontype', 'optiona', 'optionb',
            'optionc', 'optiond', 'correctanswer', 'difficulty', 'status',
            'source', 'timecreated', 'timemodified'));

        $sessions = new backup_nested_element('sessions');
        $session = new backup_nested_element('session', array('id'), array(
            'name', 'numquestions', 'timelimit', 'shufflequestions',
            'shuffleanswers', 'status', 'currentquestion', 'questionstarttime',
            'createdby', 'timecreated', 'timestarted', 'timecompleted', 'timemodified'));

        $sessionquestions = new backup_nested_element('session_questions');
        $sessionquestion = new backup_nested_element('session_question', array('id'), array(
            'questionid', 'questionorder', 'timecreated'));

        $responses = new backup_nested_element('responses');
        $response = new backup_nested_element('response', array('id'), array(
            'questionid', 'userid', 'answer', 'iscorrect', 'score',
            'responsetime', 'timecreated'));

        // Build the tree
        $classengage->add_child($slides);
        $slides->add_child($slide);

        $classengage->add_child($questions);
        $questions->add_child($question);

        $classengage->add_child($sessions);
        $sessions->add_child($session);

        $session->add_child($sessionquestions);
        $sessionquestions->add_child($sessionquestion);

        $session->add_child($responses);
        $responses->add_child($response);

        // Define sources
        $classengage->set_source_table('classengage', array('id' => backup::VAR_ACTIVITYID));
        $slide->set_source_table('classengage_slides', array('classengageid' => backup::VAR_PARENTID));
        $question->set_source_table('classengage_questions', array('classengageid' => backup::VAR_PARENTID));
        $session->set_source_table('classengage_sessions', array('classengageid' => backup::VAR_PARENTID));
        $sessionquestion->set_source_table('classengage_session_questions', array('sessionid' => backup::VAR_PARENTID));
        $response->set_source_table('classengage_responses', array('sessionid' => backup::VAR_PARENTID));

        // Define id annotations
        $slide->annotate_ids('user', 'userid');
        $session->annotate_ids('user', 'createdby');
        $response->annotate_ids('user', 'userid');

        // Define file annotations
        $classengage->annotate_files('mod_classengage', 'intro', null);
        $slide->annotate_files('mod_classengage', 'slides', 'id');

        // Return the root element (classengage), wrapped into standard activity structure
        return $this->prepare_activity_structure($classengage);
    }
}

