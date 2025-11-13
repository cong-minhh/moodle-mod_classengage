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
 * External function to submit a single clicker response
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\external;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_module;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Submit clicker response external function
 */
class submit_clicker_response extends external_api {

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'sessionid' => new external_value(PARAM_INT, 'Session ID'),
                'userid' => new external_value(PARAM_INT, 'User ID (student who pressed the button)'),
                'clickerid' => new external_value(PARAM_TEXT, 'Clicker device ID (optional)', VALUE_OPTIONAL, ''),
                'answer' => new external_value(PARAM_TEXT, 'Answer selected (A/B/C/D)'),
                'timestamp' => new external_value(PARAM_INT, 'Timestamp when button was pressed', VALUE_OPTIONAL, 0),
            )
        );
    }

    /**
     * Submit a clicker response
     *
     * @param int $sessionid Session ID
     * @param int $userid User ID
     * @param string $clickerid Clicker device ID
     * @param string $answer Answer (A/B/C/D)
     * @param int $timestamp Timestamp
     * @return array Result with success status and message
     */
    public static function execute($sessionid, $userid, $clickerid, $answer, $timestamp) {
        global $DB, $USER;

        // Validate parameters
        $params = self::validate_parameters(self::execute_parameters(), array(
            'sessionid' => $sessionid,
            'userid' => $userid,
            'clickerid' => $clickerid,
            'answer' => $answer,
            'timestamp' => $timestamp,
        ));

        // Get session
        $session = $DB->get_record('classengage_sessions', array('id' => $params['sessionid']), '*', MUST_EXIST);
        $classengage = $DB->get_record('classengage', array('id' => $session->classengageid), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('classengage', $classengage->id, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        // Validate context
        self::validate_context($context);

        // Check capability
        require_capability('mod/classengage:takequiz', $context);

        // Verify session is active
        if ($session->status !== 'active') {
            return array(
                'success' => false,
                'message' => 'Session is not active',
                'iscorrect' => false,
                'correctanswer' => '',
            );
        }

        // Get current question
        $sql = "SELECT q.*
                  FROM {classengage_questions} q
                  JOIN {classengage_session_questions} sq ON sq.questionid = q.id
                 WHERE sq.sessionid = :sessionid
                   AND sq.questionorder = :questionorder";

        $currentquestion = $DB->get_record_sql($sql, array(
            'sessionid' => $params['sessionid'],
            'questionorder' => $session->currentquestion + 1
        ));

        if (!$currentquestion) {
            return array(
                'success' => false,
                'message' => 'No active question',
                'iscorrect' => false,
                'correctanswer' => '',
            );
        }

        // Check if already answered
        if ($DB->record_exists('classengage_responses', array(
            'sessionid' => $params['sessionid'],
            'questionid' => $currentquestion->id,
            'userid' => $params['userid']
        ))) {
            return array(
                'success' => false,
                'message' => 'Already answered this question',
                'iscorrect' => false,
                'correctanswer' => '',
            );
        }

        // Validate answer
        $answer = strtoupper(trim($params['answer']));
        if (!in_array($answer, array('A', 'B', 'C', 'D'))) {
            return array(
                'success' => false,
                'message' => 'Invalid answer format. Must be A, B, C, or D',
                'iscorrect' => false,
                'correctanswer' => '',
            );
        }

        // Check if answer is correct
        $iscorrect = ($answer === strtoupper($currentquestion->correctanswer));

        // Calculate response time
        $responsetime = 0;
        if ($params['timestamp'] > 0 && $session->questionstarttime > 0) {
            $responsetime = $params['timestamp'] - $session->questionstarttime;
        } else {
            $responsetime = time() - $session->questionstarttime;
        }

        // Ensure response time is positive and reasonable
        $responsetime = max(0, min($responsetime, $session->timelimit));

        // Save response
        $response = new \stdClass();
        $response->sessionid = $params['sessionid'];
        $response->questionid = $currentquestion->id;
        $response->classengageid = $classengage->id;
        $response->userid = $params['userid'];
        $response->answer = $answer;
        $response->iscorrect = $iscorrect;
        $response->score = $iscorrect ? 1 : 0;
        $response->responsetime = $responsetime;
        $response->timecreated = $params['timestamp'] > 0 ? $params['timestamp'] : time();

        $responseid = $DB->insert_record('classengage_responses', $response);

        // Invalidate analytics cache
        $analytics = new \mod_classengage\analytics_engine($classengage->id, $context);
        $analytics->invalidate_cache($params['sessionid']);

        // Log clicker ID if provided
        if (!empty($params['clickerid'])) {
            self::log_clicker_usage($params['userid'], $params['clickerid'], $context->id);
        }

        // Trigger event
        $event = \mod_classengage\event\question_answered::create(array(
            'objectid' => $currentquestion->id,
            'context' => $context,
            'relateduserid' => $params['userid'],
            'other' => array(
                'sessionid' => $params['sessionid'],
                'iscorrect' => $iscorrect,
                'clickerid' => $params['clickerid'],
            )
        ));
        $event->trigger();

        // Update gradebook
        self::update_user_grade($classengage->id, $params['userid']);

        return array(
            'success' => true,
            'message' => 'Response recorded successfully',
            'iscorrect' => $iscorrect,
            'correctanswer' => $currentquestion->correctanswer,
            'responseid' => $responseid,
        );
    }

    /**
     * Returns description of method result value
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure(
            array(
                'success' => new external_value(PARAM_BOOL, 'Whether the submission was successful'),
                'message' => new external_value(PARAM_TEXT, 'Response message'),
                'iscorrect' => new external_value(PARAM_BOOL, 'Whether the answer was correct'),
                'correctanswer' => new external_value(PARAM_TEXT, 'The correct answer'),
                'responseid' => new external_value(PARAM_INT, 'ID of the created response record', VALUE_OPTIONAL),
            )
        );
    }

    /**
     * Log clicker device usage
     *
     * @param int $userid User ID
     * @param string $clickerid Clicker device ID
     * @param int $contextid Context ID
     */
    private static function log_clicker_usage($userid, $clickerid, $contextid) {
        global $DB;

        $record = $DB->get_record('classengage_clicker_devices', array(
            'userid' => $userid,
            'clickerid' => $clickerid
        ));

        if ($record) {
            // Update last used time
            $record->lastused = time();
            $DB->update_record('classengage_clicker_devices', $record);
        } else {
            // Create new device registration
            $newrecord = new \stdClass();
            $newrecord->userid = $userid;
            $newrecord->clickerid = $clickerid;
            $newrecord->contextid = $contextid;
            $newrecord->timecreated = time();
            $newrecord->lastused = time();
            $DB->insert_record('classengage_clicker_devices', $newrecord);
        }
    }

    /**
     * Update user's overall grade
     *
     * @param int $classengageid ClassEngage activity ID
     * @param int $userid User ID
     */
    private static function update_user_grade($classengageid, $userid) {
        global $DB;

        // Calculate user's overall score
        $sql = "SELECT COUNT(id) as total, SUM(iscorrect) as correct
                  FROM {classengage_responses}
                 WHERE classengageid = :classengageid
                   AND userid = :userid";

        $stats = $DB->get_record_sql($sql, array(
            'classengageid' => $classengageid,
            'userid' => $userid
        ));

        if ($stats && $stats->total > 0) {
            $grade = ($stats->correct / $stats->total) * 100;

            // Update gradebook
            $classengage = $DB->get_record('classengage', array('id' => $classengageid));
            $grades = new \stdClass();
            $grades->userid = $userid;
            $grades->rawgrade = $grade;

            require_once(__DIR__ . '/../../lib.php');
            classengage_grade_item_update($classengage, $grades);
        }
    }
}

