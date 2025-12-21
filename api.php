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
 * Minimal API endpoint for write operations (SSE-only architecture)
 *
 * This endpoint handles client-to-server write operations only:
 * - Response submission (single and batch)
 * - Session control (pause/resume)
 *
 * All read operations (stats, students, status, current question) are
 * provided via Server-Sent Events (sse_handler.php).
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/session_manager.php');
require_once(__DIR__ . '/classes/analytics_engine.php');

use mod_classengage\response_capture_engine;
use mod_classengage\session_state_manager;
use mod_classengage\event_logger;
use mod_classengage\rate_limiter;
use mod_classengage\constants;

$action = required_param('action', PARAM_ALPHA);
$sessionid = required_param('sessionid', PARAM_INT);

$session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $session->classengageid), '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('classengage', $classengage->id, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_login(0, false, null, false, true);
require_sesskey();

// Enterprise optimization: Apply rate limiting for write operations.
$writeactions = ['submitanswer', 'submitbatch', 'pause', 'resume'];
if (in_array($action, $writeactions)) {
    $ratelimiter = new rate_limiter();
    $ratelimitresult = $ratelimiter->check($USER->id, $action);

    if (!$ratelimitresult->allowed) {
        rate_limiter::apply_headers($ratelimitresult);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Rate limit exceeded. Please wait before trying again.',
            'errorcode' => constants::ERROR_RATE_LIMIT_EXCEEDED,
            'retry_after' => $ratelimitresult->reset_in,
        ]);
        exit;
    }

    // Apply rate limit headers for successful requests too.
    rate_limiter::apply_headers($ratelimitresult);
}

$response = array('success' => false);

try {
    switch ($action) {
        case 'submitanswer':
            // Submit student answer.
            require_capability('mod/classengage:takequiz', $context);
            $questionid = required_param('questionid', PARAM_INT);
            $answer = required_param('answer', PARAM_TEXT);
            $response = submit_answer($sessionid, $questionid, $answer, $classengage->id);
            break;

        case 'submitbatch':
            // Submit batch of responses (for high-load scenarios).
            require_capability('mod/classengage:takequiz', $context);
            $responses = required_param('responses', PARAM_RAW);
            $response = submit_batch_responses($sessionid, $responses, $classengage->id);
            break;



        case 'pause':
            // Pause an active session.
            require_capability('mod/classengage:startquiz', $context);
            $response = pause_session($sessionid);
            break;

        case 'resume':
            // Resume a paused session.
            require_capability('mod/classengage:startquiz', $context);
            $response = resume_session($sessionid);
            break;

        case 'getstatus':
            // Get current session status (for state refresh on reconnect).
            require_capability('mod/classengage:takequiz', $context);
            $response = get_session_status($sessionid, $classengage->id);
            break;

        case 'reconnect':
            // Restore session state for reconnecting client.
            require_capability('mod/classengage:takequiz', $context);
            $connectionid = optional_param('connectionid', '', PARAM_ALPHANUMEXT);
            $response = handle_reconnect($sessionid, $connectionid);
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
 * Submit student answer
 *
 * @param int $sessionid
 * @param int $questionid
 * @param string $answer
 * @param int $classengageid
 * @return array
 */
function submit_answer($sessionid, $questionid, $answer, $classengageid)
{
    global $DB, $USER;

    $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);

    if ($session->status !== 'active') {
        return array('success' => false, 'error' => 'Session not active');
    }

    // Check if already answered.
    if (
        $DB->record_exists('classengage_responses', array(
            'sessionid' => $sessionid,
            'questionid' => $questionid,
            'userid' => $USER->id
        ))
    ) {
        return array('success' => false, 'error' => 'Already answered');
    }

    // Get question.
    $question = $DB->get_record('classengage_questions', array('id' => $questionid), '*', MUST_EXIST);

    // Check if answer is correct.
    $iscorrect = (strtoupper($answer) === strtoupper($question->correctanswer));

    // Calculate response time.
    $responsetime = time() - $session->questionstarttime;

    // Calculate score.
    $score = $iscorrect ? 1 : 0;

    // Save response.
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

    // Invalidate analytics cache.
    $cm = get_coursemodule_from_instance('classengage', $classengageid);
    $context = context_module::instance($cm->id);
    $analytics = new \mod_classengage\analytics_engine($classengageid, $context);
    $analytics->invalidate_cache($sessionid);

    // Update answered status in connection tracking.
    $statemanager = new session_state_manager();
    $statemanager->mark_question_answered($sessionid, $USER->id);

    // Trigger event.
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
 * Submit batch of responses for high-load scenarios
 *
 * @param int $sessionid Session ID
 * @param string $responsesJson JSON-encoded array of responses
 * @param int $classengageid Activity ID
 * @return array
 */
function submit_batch_responses($sessionid, $responsesJson, $classengageid)
{
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
        'results' => array_map(function ($r) {
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
 * Pause an active session
 *
 * @param int $sessionid Session ID
 * @return array
 */
function pause_session($sessionid)
{
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
 * @param int $sessionid Session ID
 * @return array
 */
function resume_session($sessionid)
{
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
 * Get current session status for state refresh
 *
 * @param int $sessionid Session ID
 * @param int $classengageid Activity ID
 * @return array
 */
function get_session_status($sessionid, $classengageid)
{
    global $DB, $USER;

    $session = $DB->get_record('classengage_sessions', ['id' => $sessionid]);
    if (!$session) {
        return ['success' => false, 'error' => 'Session not found'];
    }

    // Get current question if session is active.
    $question = null;
    if ($session->status === 'active' && $session->currentquestionid) {
        $q = $DB->get_record('classengage_questions', ['id' => $session->currentquestionid]);
        if ($q) {
            // Check if user already answered.
            $answered = $DB->record_exists('classengage_responses', [
                'sessionid' => $sessionid,
                'questionid' => $q->id,
                'userid' => $USER->id
            ]);

            // Parse options.
            $options = json_decode($q->options, true) ?: [];
            $formattedOptions = [];
            foreach ($options as $key => $text) {
                $formattedOptions[] = ['key' => $key, 'text' => $text];
            }

            // Calculate time remaining.
            $elapsed = time() - $session->questionstarttime;
            $timeremaining = max(0, $q->timelimit - $elapsed);

            // Get question number.
            $questionNumber = $DB->count_records_select(
                'classengage_questions',
                'sessionid = :sid AND id <= :qid',
                ['sid' => $sessionid, 'qid' => $q->id]
            );
            $totalQuestions = $DB->count_records('classengage_questions', ['sessionid' => $sessionid]);

            $question = [
                'id' => $q->id,
                'text' => $q->questiontext,
                'options' => $formattedOptions,
                'timeremaining' => $timeremaining,
                'timelimit' => $q->timelimit,
                'number' => $questionNumber,
                'total' => $totalQuestions,
                'answered' => $answered,
            ];
        }
    }

    return [
        'success' => true,
        'session' => [
            'status' => $session->status,
            'question' => $question,
        ],
    ];
}

/**
 * Handle client reconnection and restore session state
 *
 * @param int $sessionid Session ID
 * @param string $connectionid Connection identifier (optional, will generate new one if empty)
 * @return array
 */
function handle_reconnect($sessionid, $connectionid = '')
{
    global $USER;

    $statemanager = new session_state_manager();

    // Generate new connection ID if not provided.
    if (empty($connectionid)) {
        $connectionid = uniqid('api_' . $USER->id . '_', true);
    }

    // Register the connection.
    $statemanager->register_connection($sessionid, $USER->id, $connectionid, 'api');

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
    if (class_exists('mod_classengage\\event_logger')) {
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
