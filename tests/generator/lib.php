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
class mod_classengage_generator extends testing_module_generator
{

    /**
     * Create new classengage module instance
     * @param array|stdClass $record
     * @param array $options
     * @return stdClass activity record with extra cmid field
     */
    public function create_instance($record = null, array $options = null)
    {
        $record = (object) (array) $record;

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

        return parent::create_instance($record, (array) $options);
    }

    /**
     * Create a question for a classengage instance
     *
     * @param int $classengageid
     * @param array $record
     * @return stdClass
     */
    public function create_question($classengageid, $record = array())
    {
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

        $record = (object) array_merge($defaults, $record);
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
    public function create_session($classengageid, $createdby, $record = array())
    {
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

        $record = (object) array_merge($defaults, $record);
        $record->id = $DB->insert_record('classengage_sessions', $record);

        return $record;
    }

    /**
     * Create a connection record for a user in a session
     *
     * @param int $sessionid
     * @param int $userid
     * @param array $record
     * @return stdClass
     */
    public function create_connection($sessionid, $userid, $record = array())
    {
        global $DB;

        $defaults = array(
            'sessionid' => $sessionid,
            'userid' => $userid,
            'connectionid' => uniqid('test_' . $userid . '_', true),
            'transport' => 'polling',
            'status' => 'connected',
            'current_question_answered' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        );

        $record = (object) array_merge($defaults, $record);
        $record->id = $DB->insert_record('classengage_connections', $record);

        return $record;
    }

    /**
     * Create a response record for a user
     *
     * @param int $sessionid
     * @param int $questionid
     * @param int $classengageid
     * @param int $userid
     * @param array $record
     * @return stdClass
     */
    public function create_response($sessionid, $questionid, $classengageid, $userid, $record = array())
    {
        global $DB;

        // Get the question to check correct answer.
        $question = $DB->get_record('classengage_questions', ['id' => $questionid]);
        $answer = $record['answer'] ?? 'A';
        $iscorrect = ($question && strtoupper($answer) === strtoupper($question->correctanswer)) ? 1 : 0;

        $defaults = array(
            'sessionid' => $sessionid,
            'questionid' => $questionid,
            'classengageid' => $classengageid,
            'userid' => $userid,
            'answer' => $answer,
            'iscorrect' => $iscorrect,
            'score' => $iscorrect ? 1 : 0,
            'responsetime' => 5,
            'timecreated' => time(),
        );

        $record = (object) array_merge($defaults, $record);
        $record->id = $DB->insert_record('classengage_responses', $record);

        return $record;
    }

    /**
     * Link questions to a session
     *
     * @param int $sessionid
     * @param array $questionids Array of question IDs
     * @return void
     */
    public function link_questions_to_session($sessionid, $questionids)
    {
        global $DB;

        $order = 1;
        foreach ($questionids as $questionid) {
            $sq = new stdClass();
            $sq->sessionid = $sessionid;
            $sq->questionid = $questionid;
            $sq->questionorder = $order++;
            $sq->timecreated = time();
            $DB->insert_record('classengage_session_questions', $sq);
        }
    }

    /**
     * Start a session (set to active status)
     *
     * @param int $sessionid
     * @return stdClass Updated session record
     */
    public function start_session($sessionid)
    {
        global $DB;

        $session = $DB->get_record('classengage_sessions', ['id' => $sessionid], '*', MUST_EXIST);
        $session->status = 'active';
        $session->currentquestion = 0;
        $session->questionstarttime = time();
        $session->timestarted = time();
        $session->timemodified = time();

        $DB->update_record('classengage_sessions', $session);

        return $session;
    }
}

