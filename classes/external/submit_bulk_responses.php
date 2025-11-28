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
 * External function to submit bulk clicker responses
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\external;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Submit bulk clicker responses external function
 */
class submit_bulk_responses extends external_api {

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'sessionid' => new external_value(PARAM_INT, 'Session ID'),
                'responses' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'userid' => new external_value(PARAM_INT, 'User ID', VALUE_OPTIONAL, 0),
                            'clickerid' => new external_value(PARAM_TEXT, 'Clicker device ID'),
                            'answer' => new external_value(PARAM_TEXT, 'Answer (A/B/C/D)'),
                            'timestamp' => new external_value(PARAM_INT, 'When button was pressed', VALUE_OPTIONAL, 0),
                        )
                    ),
                    'Array of student responses from clicker hub'
                ),
            )
        );
    }

    /**
     * Submit bulk clicker responses
     *
     * @param int $sessionid Session ID
     * @param array $responses Array of responses
     * @return array Results
     */
    public static function execute($sessionid, $responses) {
        global $DB;

        // Validate parameters
        $params = self::validate_parameters(self::execute_parameters(), array(
            'sessionid' => $sessionid,
            'responses' => $responses,
        ));

        // Get session
        $session = $DB->get_record('classengage_sessions', array('id' => $params['sessionid']), '*', MUST_EXIST);
        $classengage = $DB->get_record('classengage', array('id' => $session->classengageid), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('classengage', $classengage->id, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        // Validate context
        self::validate_context($context);

        // Check capability - requires special bulk submission capability
        require_capability('mod/classengage:submitclicker', $context);

        // Verify session is active
        if ($session->status !== 'active') {
            return array(
                'success' => false,
                'message' => 'Session is not active',
                'processed' => 0,
                'failed' => count($params['responses']),
                'results' => array(),
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
                'processed' => 0,
                'failed' => count($params['responses']),
                'results' => array(),
            );
        }

        // Process each response
        $results = array();
        $processed = 0;
        $failed = 0;
        
        // Initialize analytics engine for cache invalidation
        $analytics = new \mod_classengage\analytics_engine($classengage->id, $context);

        foreach ($params['responses'] as $resp) {
            try {
                // Resolve user ID from clicker ID if needed
                $userid = $resp['userid'];
                if (empty($userid) && !empty($resp['clickerid'])) {
                    $userid = self::get_userid_from_clicker($resp['clickerid']);
                    if (!$userid) {
                        $results[] = array(
                            'clickerid' => $resp['clickerid'],
                            'success' => false,
                            'message' => 'Clicker device not registered',
                        );
                        $failed++;
                        continue;
                    }
                }

                // Check if already answered
                if ($DB->record_exists('classengage_responses', array(
                    'sessionid' => $params['sessionid'],
                    'questionid' => $currentquestion->id,
                    'userid' => $userid
                ))) {
                    $results[] = array(
                        'clickerid' => $resp['clickerid'],
                        'success' => false,
                        'message' => 'User already answered',
                    );
                    $failed++;
                    continue;
                }

                // Validate answer
                $answer = strtoupper(trim($resp['answer']));
                if (!in_array($answer, array('A', 'B', 'C', 'D'))) {
                    $results[] = array(
                        'clickerid' => $resp['clickerid'],
                        'success' => false,
                        'message' => 'Invalid answer format',
                    );
                    $failed++;
                    continue;
                }

                // Check if answer is correct
                $iscorrect = ($answer === strtoupper($currentquestion->correctanswer));

                // Calculate response time
                $responsetime = 0;
                if (!empty($resp['timestamp']) && $session->questionstarttime > 0) {
                    $responsetime = $resp['timestamp'] - $session->questionstarttime;
                } else {
                    $responsetime = time() - $session->questionstarttime;
                }
                $responsetime = max(0, min($responsetime, $session->timelimit));

                // Save response
                $response = new \stdClass();
                $response->sessionid = $params['sessionid'];
                $response->questionid = $currentquestion->id;
                $response->classengageid = $classengage->id;
                $response->userid = $userid;
                $response->answer = $answer;
                $response->iscorrect = $iscorrect;
                $response->score = $iscorrect ? 1 : 0;
                $response->responsetime = $responsetime;
                $response->timecreated = !empty($resp['timestamp']) ? $resp['timestamp'] : time();

                $responseid = $DB->insert_record('classengage_responses', $response);

                // Log clicker usage
                if (!empty($resp['clickerid'])) {
                    self::log_clicker_usage($userid, $resp['clickerid'], $context->id);
                }

                // Trigger event
                $event = \mod_classengage\event\question_answered::create(array(
                    'objectid' => $currentquestion->id,
                    'context' => $context,
                    'relateduserid' => $userid,
                    'other' => array(
                        'sessionid' => $params['sessionid'],
                        'iscorrect' => $iscorrect,
                        'clickerid' => $resp['clickerid'],
                    )
                ));
                $event->trigger();

                $results[] = array(
                    'clickerid' => $resp['clickerid'],
                    'success' => true,
                    'message' => 'Response recorded',
                    'responseid' => $responseid,
                );

                $processed++;

            } catch (\Exception $e) {
                $results[] = array(
                    'clickerid' => $resp['clickerid'] ?? 'unknown',
                    'success' => false,
                    'message' => $e->getMessage(),
                );
                $failed++;
            }
        }
        
        // Invalidate analytics cache after bulk processing
        if ($processed > 0) {
            $analytics->invalidate_cache($params['sessionid']);
        }

        return array(
            'success' => true,
            'message' => "Processed $processed responses, $failed failed",
            'processed' => $processed,
            'failed' => $failed,
            'results' => $results,
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
                'success' => new external_value(PARAM_BOOL, 'Overall success'),
                'message' => new external_value(PARAM_TEXT, 'Summary message'),
                'processed' => new external_value(PARAM_INT, 'Number of successful responses'),
                'failed' => new external_value(PARAM_INT, 'Number of failed responses'),
                'results' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'clickerid' => new external_value(PARAM_TEXT, 'Clicker device ID'),
                            'success' => new external_value(PARAM_BOOL, 'Whether this response succeeded'),
                            'message' => new external_value(PARAM_TEXT, 'Result message'),
                            'responseid' => new external_value(PARAM_INT, 'Response record ID', VALUE_OPTIONAL),
                        )
                    )
                ),
            )
        );
    }

    /**
     * Get user ID from clicker device ID
     *
     * @param string $clickerid Clicker device ID
     * @return int|null User ID or null
     */
    private static function get_userid_from_clicker($clickerid) {
        global $DB;

        $record = $DB->get_record('classengage_clicker_devices', 
            array('clickerid' => $clickerid), 
            'userid', 
            IGNORE_MISSING
        );

        return $record ? $record->userid : null;
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
            $record->lastused = time();
            $DB->update_record('classengage_clicker_devices', $record);
        } else {
            $newrecord = new \stdClass();
            $newrecord->userid = $userid;
            $newrecord->clickerid = $clickerid;
            $newrecord->contextid = $contextid;
            $newrecord->timecreated = time();
            $newrecord->lastused = time();
            $DB->insert_record('classengage_clicker_devices', $newrecord);
        }
    }
}

