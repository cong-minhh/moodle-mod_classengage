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
 * mod_classengage data generator
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * ClassEngage module data generator class
 */
class mod_classengage_generator extends testing_module_generator {

    /**
     * Create new classengage module instance
     * @param array|stdClass $record
     * @param array $options
     * @return stdClass activity record with extra cmid field
     */
    public function create_instance($record = null, array $options = null) {
        $record = (object)(array)$record;

        $defaultsettings = array(
            'name' => 'Test ClassEngage',
            'intro' => 'Test introduction',
            'introformat' => FORMAT_HTML,
            'grade' => 100,
        );

        foreach ($defaultsettings as $name => $value) {
            if (!isset($record->$name)) {
                $record->$name = $value;
            }
        }

        return parent::create_instance($record, (array)$options);
    }

    /**
     * Create a question for a classengage instance
     *
     * @param int $classengageid
     * @param array $record
     * @return stdClass
     */
    public function create_question($classengageid, $record = array()) {
        global $DB;

        $defaults = array(
            'classengageid' => $classengageid,
            'questiontext' => 'Sample question?',
            'questiontype' => 'multichoice',
            'optiona' => 'Option A',
            'optionb' => 'Option B',
            'optionc' => 'Option C',
            'optiond' => 'Option D',
            'correctanswer' => 'A',
            'difficulty' => 'medium',
            'status' => 'approved',
            'source' => 'manual',
            'timecreated' => time(),
            'timemodified' => time(),
        );

        $record = (object)array_merge($defaults, $record);
        $record->id = $DB->insert_record('classengage_questions', $record);

        return $record;
    }

    /**
     * Create a session for a classengage instance
     *
     * @param int $classengageid
     * @param int $createdby
     * @param array $record
     * @return stdClass
     */
    public function create_session($classengageid, $createdby, $record = array()) {
        global $DB;

        $defaults = array(
            'classengageid' => $classengageid,
            'name' => 'Test Session',
            'numquestions' => 5,
            'timelimit' => 30,
            'shufflequestions' => 1,
            'shuffleanswers' => 1,
            'status' => 'ready',
            'currentquestion' => 0,
            'createdby' => $createdby,
            'timecreated' => time(),
            'timemodified' => time(),
        );

        $record = (object)array_merge($defaults, $record);
        $record->id = $DB->insert_record('classengage_sessions', $record);

        return $record;
    }
}

