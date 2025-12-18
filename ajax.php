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
 * AJAX handler for real-time quiz operations
 *
 * Handles both legacy endpoints and new real-time endpoints for:
 * - Response submission (single and batch)
 * - Session control (pause/resume)
 * - Connection management (heartbeat, reconnect, status)
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');
require_once(__DIR__.'/classes/session_manager.php');
require_once(__DIR__.'/classes/analytics_engine.php');

use mod_classengage\response_capture_engine;
use mod_classengage\session_state_manager;
use mod_classengage\heartbeat_manager;
use mod_classengage\event_logger;

$action = required_param('action', PARAM_ALPHA);
$sessionid = required_param('sessionid', PARAM_INT);

$session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $session->classengageid), '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('classengage', $classengage->id, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_login(0, false, null, false, true);
require_sesskey();

$response = array('success' => false);

try {
    switch ($action) {
        case 'getcurrent':
            // Get current question for student.
            require_capability('mod/classengage:takequiz', $context);
            $response = get_current_question($sessionid);
            break;

        case 'submitanswer':
            // Submit student answer.
            require_capability('mod/classengage:takequiz', $context);
            $questionid = required_param('questionid', PARAM_INT);
            $answer = required_param('answer', PARAM_TEXT);
            $response = submit_answer($sessionid, $questionid, $answer, $classengage->id);
            break;

        case 'submitbatch':
            // Submit batch of responses (for high-load scenarios).
            // Requirements: 2.1, 3.5.
            require_capability('mod/classengage:takequiz', $context);
            $responses = required_param('responses', PARAM_RAW);
            $response = submit_batch_responses($sessionid, $responses, $classengage->id);
            break;

        case 'heartbeat':
            // Process heartbeat for connection keep-alive.
            // Requirements: 5.2, 5.3.
            require_capability('mod/classengage:takequiz', $context);
            $connectionid = required_param('connectionid', PARAM_ALPHANUMEXT);
            $response = process_heartbeat($sessionid, $connectionid);
            break;

        case 'getstatus':
            // Get connection status and session state.
            // Requirements: 5.4.
            require_capability('mod/classengage:takequiz', $context);
            $connectionid = optional_param('connectionid', '', PARAM_ALPHANUMEXT);
            $response = get_connection_status($sessionid, $connectionid);
            break;

        case 'pause':
            // Pause an active session.
            // Requirements: 1.4.
            require_capability('mod/classengage:startquiz', $context);
            $response = pause_session($sessionid);
            break;

        case 'resume':
            // Resume a paused session.
            // Requirements: 1.5.
            require_capability('mod/classengage:startquiz', $context);
            $response = resume_session($sessionid);
            break;

        case 'reconnect':
            // Restore session state for reconnecting client.
            // Requirements: 4.4.
            require_capability('mod/classengage:takequiz', $context);
            $connectionid = optional_param('connectionid', '', PARAM_ALPHANUMEXT);
            $response = handle_reconnect($sessionid, $connectionid);
            break;

        case 'getstats':
            // Get session statistics for instructor.
            require_capability('mod/classengage:viewanalytics', $context);
            $response = get_session_stats($sessionid);
            break;

        case 'getstudents':
            // Get connected students list for instructor panel.
            // Requirements: 5.1, 5.4, 5.5.
            require_capability('mod/classengage:viewanalytics', $context);
            $response = get_connected_students($sessionid);
            break;

        default:
            $response['error'] = 'Invalid action';
    }

} catch (Exception $e) {
    $response['error'] = $e->getMessage();

    // Log the error.
    if (class_exists('mod_classengage\event_logger')) {
        $logger = new event_logger();
        $logger->log_connection_error($sessionid, $USER->id, $e->getMessage(), [
            'action' => $action,
            'trace' => $e->getTraceAsString(),
        ]);
    }
}

header('Content-Type: application/json');
echo json_encode($response);

/**
 * Get current question for session
 *
 * @param int $sessionid
 * @return array
 */
function get_current_question($sessionid) {
    global $DB, $USER;
    
    $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
    
    // Return question for both active and paused sessions.
    if ($session->status !== 'active' && $session->status !== 'paused') {
        return array(
            'success' => true,
            'status' => $session->status,
            'question' => null
        );
    }
    
    // Get current question
    $sql = "SELECT q.*
              FROM {classengage_questions} q
              JOIN {classengage_session_questions} sq ON sq.questionid = q.id
             WHERE sq.sessionid = :sessionid
               AND sq.questionorder = :questionorder";
    
    $params = array(
        'sessionid' => $sessionid,
        'questionorder' => $session->currentquestion + 1
    );
    
    $question = $DB->get_record_sql($sql, $params);
    
    if (!$question) {
        return array(
            'success' => true,
            'status' => 'waiting',
            'question' => null
        );
    }
    
    // Check if user has already answered
    $answered = $DB->record_exists('classengage_responses', array(
        'sessionid' => $sessionid,
        'questionid' => $question->id,
        'userid' => $USER->id
    ));
    
    // Prepare options (shuffle if needed)
    $options = array();
    if ($question->optiona) {
        $options[] = array('key' => 'A', 'text' => $question->optiona);
    }
    if ($question->optionb) {
        $options[] = array('key' => 'B', 'text' => $question->optionb);
    }
    if ($question->optionc) {
        $options[] = array('key' => 'C', 'text' => $question->optionc);
    }
    if ($question->optiond) {
        $options[] = array('key' => 'D', 'text' => $question->optiond);
    }
    
    if ($session->shuffleanswers) {
        shuffle($options);
    }
    
    // Calculate time remaining - use stored value for paused sessions.
    if ($session->status === 'paused' && isset($session->timer_remaining)) {
        $remaining = $session->timer_remaining;
    } else {
        $elapsed = time() - $session->questionstarttime;
        $remaining = max(0, $session->timelimit - $elapsed);
    }
    
    return array(
        'success' => true,
        'status' => $session->status,
        'question' => array(
            'id' => $question->id,
            'text' => $question->questiontext,
            'options' => $options,
            'number' => $session->currentquestion + 1,
            'total' => $session->numquestions,
            'timelimit' => $session->timelimit,
            'timeremaining' => $remaining,
            'answered' => $answered
        )
    );
}

/**
 * Submit student answer
 *
 * @param int $sessionid
 * @param int $questionid
 * @param string $answer
 * @param int $classengageid
 * @return array
 */
function submit_answer($sessionid, $questionid, $answer, $classengageid) {
    global $DB, $USER;
    
    $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
    
    if ($session->status !== 'active') {
        return array('success' => false, 'error' => 'Session not active');
    }
    
    // Check if already answered
    if ($DB->record_exists('classengage_responses', array(
        'sessionid' => $sessionid,
        'questionid' => $questionid,
        'userid' => $USER->id
    ))) {
        return array('success' => false, 'error' => 'Already answered');
    }
    
    // Get question
    $question = $DB->get_record('classengage_questions', array('id' => $questionid), '*', MUST_EXIST);
    
    // Check if answer is correct
    $iscorrect = (strtoupper($answer) === strtoupper($question->correctanswer));
    
    // Calculate response time
    $responsetime = time() - $session->questionstarttime;
    
    // Calculate score
    $score = $iscorrect ? 1 : 0;
    
    // Save response
    $response = new stdClass();
    $response->sessionid = $sessionid;
    $response->questionid = $questionid;
    $response->classengageid = $classengageid;
    $response->userid = $USER->id;
    $response->answer = $answer;
    $response->iscorrect = $iscorrect;
    $response->score = $score;
    $response->responsetime = $responsetime;
    $response->timecreated = time();
    
    $DB->insert_record('classengage_responses', $response);
    
    // Invalidate analytics cache
    $cm = get_coursemodule_from_instance('classengage', $classengageid);
    $context = context_module::instance($cm->id);
    $analytics = new \mod_classengage\analytics_engine($classengageid, $context);
    $analytics->invalidate_cache($sessionid);
    
    // Trigger event
    $event = \mod_classengage\event\question_answered::create(array(
        'objectid' => $questionid,
        'context' => $context,
        'other' => array(
            'sessionid' => $sessionid,
            'iscorrect' => $iscorrect
        )
    ));
    $event->trigger();
    
    return array(
        'success' => true,
        'iscorrect' => $iscorrect,
        'correctanswer' => $question->correctanswer
    );
}

/**
 * Get session statistics with detailed response distribution
 *
 * @param int $sessionid
 * @return array
 */
function get_session_stats($sessionid) {
    global $DB;

    $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
    $classengage = $DB->get_record('classengage', array('id' => $session->classengageid), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('classengage', $classengage->id);
    $context = context_module::instance($cm->id);

    // Use analytics engine for cached stats.
    $analytics = new \mod_classengage\analytics_engine($classengage->id, $context);
    $currentstats = $analytics->get_current_question_stats($sessionid);

    // Count total participants (all users who have submitted at least one response).
    $sql = "SELECT COUNT(DISTINCT userid) FROM {classengage_responses} WHERE sessionid = ?";
    $participants = $DB->count_records_sql($sql, array($sessionid));

    // Count responses for current question.
    $responses = $currentstats['total'];

    // Calculate participation rate (participants / enrolled students).
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

    // Use count_enrolled_users for better performance and reliability.
    $totalenrolled = count_enrolled_users($context, 'mod/classengage:takequiz');

    // Fallback if capability check is too restrictive or returns 0 in test env.
    if ($totalenrolled == 0) {
        $totalenrolled = count_enrolled_users($context);
    }

    $participationrate = $totalenrolled > 0 ? round(($responses / $totalenrolled) * 100, 1) : 0.0;

    // Build distribution data.
    $distribution = array(
        'A' => $currentstats['A'],
        'B' => $currentstats['B'],
        'C' => $currentstats['C'],
        'D' => $currentstats['D'],
        'total' => $currentstats['total'],
        'correctanswer' => isset($currentstats['correctanswer']) ? $currentstats['correctanswer'] : ''
    );

    // Get connection statistics if available.
    $connectionstats = [];
    try {
        $statemanager = new session_state_manager();
        $connectionstats = $statemanager->get_session_statistics($sessionid);
    } catch (Exception $e) {
        // Connection stats not available.
    }

    return array(
        'success' => true,
        'data' => array(
            'participants' => $participants,
            'responses' => $responses,
            'participationrate' => $participationrate,
            'currentquestion' => $session->currentquestion,
            'totalquestions' => $session->numquestions,
            'status' => $session->status,
            'distribution' => $distribution,
            'timelimit' => (int)$session->timelimit,
            'timeremaining' => max(0, $session->timelimit - (time() - $session->questionstarttime)),
            'elapsed' => time() - $session->questionstarttime,
            'connected' => $connectionstats['connected'] ?? 0,
            'answered' => $connectionstats['answered'] ?? 0,
            'pending' => $connectionstats['pending'] ?? 0,
        )
    );
}

/**
 * Submit batch of responses for high-load scenarios
 *
 * Requirements: 2.1, 3.5
 *
 * @param int $sessionid Session ID
 * @param string $responsesJson JSON-encoded array of responses
 * @param int $classengageid Activity ID
 * @return array
 */
function submit_batch_responses($sessionid, $responsesJson, $classengageid) {
    global $USER;

    $responses = json_decode($responsesJson, true);

    if (!is_array($responses)) {
        return ['success' => false, 'error' => 'Invalid responses format'];
    }

    if (empty($responses)) {
        return ['success' => true, 'processedcount' => 0, 'failedcount' => 0, 'results' => []];
    }

    // Add user ID to each response.
    foreach ($responses as &$response) {
        $response['userid'] = $USER->id;
        $response['sessionid'] = $sessionid;
    }

    $engine = new response_capture_engine();
    $result = $engine->submit_batch($responses);

    // Log the batch submission.
    if (class_exists('mod_classengage\event_logger')) {
        $logger = new event_logger();
        $logger->log_response_submission($sessionid, $USER->id, [
            'batch' => true,
            'count' => count($responses),
            'processed' => $result->processedcount,
            'failed' => $result->failedcount,
        ]);
    }

    // Update answered status for successful submissions.
    if ($result->processedcount > 0) {
        $statemanager = new session_state_manager();
        $statemanager->mark_question_answered($sessionid, $USER->id);
    }

    return [
        'success' => $result->success,
        'processedcount' => $result->processedcount,
        'failedcount' => $result->failedcount,
        'results' => array_map(function($r) {
            return [
                'success' => $r->success,
                'error' => $r->error,
                'iscorrect' => $r->iscorrect,
                'islate' => $r->islate,
            ];
        }, $result->results),
        'error' => $result->error,
    ];
}

/**
 * Process heartbeat for connection keep-alive
 *
 * Requirements: 5.2, 5.3
 *
 * @param int $sessionid Session ID
 * @param string $connectionid Connection identifier
 * @return array
 */
function process_heartbeat($sessionid, $connectionid) {
    global $USER;

    $manager = new heartbeat_manager();
    $result = $manager->process_heartbeat($sessionid, $USER->id, $connectionid);

    // Also check for stale connections.
    $manager->check_stale_connections($sessionid);

    return [
        'success' => $result->success,
        'error' => $result->error,
        'servertimestamp' => $result->servertimestamp,
        'status' => $result->status,
    ];
}

/**
 * Get connection status and session state
 *
 * Requirements: 5.4
 *
 * @param int $sessionid Session ID
 * @param string $connectionid Connection identifier (optional)
 * @return array
 */
function get_connection_status($sessionid, $connectionid = '') {
    global $USER, $DB;

    $statemanager = new session_state_manager();
    $heartbeatmanager = new heartbeat_manager();

    // Get session state.
    $state = $statemanager->get_session_state($sessionid);

    if (!$state) {
        return ['success' => false, 'error' => 'Session not found'];
    }

    // Get connection statistics.
    $stats = $heartbeatmanager->get_connection_stats($sessionid);

    // Get user's connection status if connectionid provided.
    $userconnection = null;
    if (!empty($connectionid)) {
        $userconnection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);
    }

    return [
        'success' => true,
        'session' => [
            'status' => $state->status,
            'currentquestion' => $state->currentquestion,
            'timerremaining' => $state->timerremaining,
            'connectedcount' => $state->connectedcount,
        ],
        'connection' => $userconnection ? [
            'status' => $userconnection->status,
            'lastheartbeat' => $userconnection->last_heartbeat,
            'hasanswered' => (bool) $userconnection->current_question_answered,
        ] : null,
        'stats' => [
            'totalconnections' => $stats->totalconnections,
            'activeconnections' => $stats->activeconnections,
            'disconnectedconnections' => $stats->disconnectedconnections,
            'staleconnections' => $stats->staleconnections,
            'averagelatency' => $stats->averagelatency,
        ],
    ];
}

/**
 * Pause an active session
 *
 * Requirements: 1.4
 *
 * @param int $sessionid Session ID
 * @return array
 */
function pause_session($sessionid) {
    $statemanager = new session_state_manager();

    try {
        $state = $statemanager->pause_session($sessionid);

        return [
            'success' => true,
            'status' => $state->status,
            'timerremaining' => $state->timerremaining,
            'timestamp' => $state->timestamp,
        ];
    } catch (moodle_exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Resume a paused session
 *
 * Requirements: 1.5
 *
 * @param int $sessionid Session ID
 * @return array
 */
function resume_session($sessionid) {
    $statemanager = new session_state_manager();

    try {
        $state = $statemanager->resume_session($sessionid);

        return [
            'success' => true,
            'status' => $state->status,
            'timerremaining' => $state->timerremaining,
            'questionstarttime' => $state->questionstarttime,
            'timestamp' => $state->timestamp,
        ];
    } catch (moodle_exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Handle client reconnection and restore session state
 *
 * Requirements: 4.4
 *
 * @param int $sessionid Session ID
 * @param string $connectionid Connection identifier (optional, will generate new one if empty)
 * @return array
 */
function handle_reconnect($sessionid, $connectionid = '') {
    global $USER;

    $statemanager = new session_state_manager();

    // Generate new connection ID if not provided.
    if (empty($connectionid)) {
        $connectionid = uniqid('ajax_' . $USER->id . '_', true);
    }

    // Register the connection.
    $statemanager->register_connection($sessionid, $USER->id, $connectionid, 'polling');

    // Get client state.
    $clientstate = $statemanager->get_client_state($sessionid, $USER->id);

    // Prepare question data.
    $questiondata = null;
    if ($clientstate->question) {
        $questiondata = [
            'id' => $clientstate->question->id,
            'text' => $clientstate->question->questiontext,
            'options' => [],
        ];

        if (!empty($clientstate->question->optiona)) {
            $questiondata['options'][] = ['key' => 'A', 'text' => $clientstate->question->optiona];
        }
        if (!empty($clientstate->question->optionb)) {
            $questiondata['options'][] = ['key' => 'B', 'text' => $clientstate->question->optionb];
        }
        if (!empty($clientstate->question->optionc)) {
            $questiondata['options'][] = ['key' => 'C', 'text' => $clientstate->question->optionc];
        }
        if (!empty($clientstate->question->optiond)) {
            $questiondata['options'][] = ['key' => 'D', 'text' => $clientstate->question->optiond];
        }
    }

    // Log the reconnection.
    if (class_exists('mod_classengage\event_logger')) {
        $logger = new event_logger();
        $logger->log_session_event($sessionid, 'reconnect', [
            'userid' => $USER->id,
            'connectionid' => $connectionid,
        ]);
    }

    return [
        'success' => true,
        'connectionid' => $connectionid,
        'session' => [
            'status' => $clientstate->status,
            'currentquestion' => $clientstate->currentquestion,
            'timerremaining' => $clientstate->timerremaining,
        ],
        'question' => $questiondata,
        'hasanswered' => $clientstate->hasanswered,
        'useranswer' => $clientstate->useranswer,
        'timestamp' => $clientstate->timestamp,
    ];
}

/**
 * Get connected students list for instructor panel
 *
 * Requirements: 5.1, 5.4, 5.5
 *
 * @param int $sessionid Session ID
 * @return array
 */
function get_connected_students($sessionid) {
    global $DB;

    $statemanager = new session_state_manager();

    // Get connected students.
    $connectedstudents = $statemanager->get_connected_students($sessionid);

    // Get aggregate statistics.
    $stats = $statemanager->get_session_statistics($sessionid);

    // Enrich student data with user names.
    // Enrich student data with user names.
    $studentids = array_map(function($s) { return $s->userid; }, $connectedstudents);

    if (!empty($studentids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($studentids);
        $users = $DB->get_records_select('user', "id $insql", $inparams, '', 'id, firstname, lastname, email');
    } else {
        $users = [];
    }

    $students = [];
    foreach ($connectedstudents as $student) {
        $user = isset($users[$student->userid]) ? $users[$student->userid] : null;
        $students[] = [
            'userid' => $student->userid,
            'fullname' => $user ? fullname($user) : 'User ' . $student->userid,
            'status' => $student->status,
            'hasanswered' => $student->hasanswered,
            'lastheartbeat' => $student->lastheartbeat,
            'transport' => $student->transport,
        ];
    }

    return [
        'success' => true,
        'students' => $students,
        'stats' => $stats,
    ];
}
