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
 * Server-Sent Events (SSE) handler for real-time session updates
 *
 * Provides server-to-client push for session state changes and question broadcasts.
 * Implements Requirements 1.1, 1.2, 6.4 for real-time communication.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Disable output buffering for SSE.
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);

// Disable time limit for long-running SSE connections.
@set_time_limit(0);

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

use mod_classengage\session_state_manager;
use mod_classengage\heartbeat_manager;

$sessionid = required_param('sessionid', PARAM_INT);
$connectionid = optional_param('connectionid', '', PARAM_ALPHANUMEXT);
$lastEventId = optional_param('lastEventId', 0, PARAM_INT);

// Validate session and permissions.
$session = $DB->get_record('classengage_sessions', ['id' => $sessionid], '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', ['id' => $session->classengageid], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('classengage', $classengage->id, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($cm->course, false, $cm, false, true);
require_capability('mod/classengage:takequiz', $context);

// Generate connection ID if not provided.
if (empty($connectionid)) {
    $connectionid = uniqid('sse_' . $USER->id . '_', true);
}

// Set SSE headers.
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no'); // Disable nginx buffering.
header('Connection: keep-alive');

// Disable output buffering at PHP level.
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}

// Flush any existing output buffers.
while (ob_get_level() > 0) {
    ob_end_flush();
}

// Initialize managers.
$statemanager = new session_state_manager();
$heartbeatmanager = new heartbeat_manager();

// Release session lock to allow other requests (like AJAX polling or control actions) to proceed.
// This is critical for SSE as it keeps the connection open.
\core\session\manager::write_close();

// Register connection.
$statemanager->register_connection($sessionid, $USER->id, $connectionid, 'sse');

// Track last sent state for change detection.
$laststate = null;
$lastquestion = -1;
$eventid = $lastEventId;
$maxruntime = 300; // 5 minutes max runtime.
$starttime = time();
$heartbeatinterval = 15; // Send heartbeat every 15 seconds.
$lastheartbeat = time();

/**
 * Send an SSE event to the client.
 *
 * @param string $event Event type
 * @param mixed $data Event data
 * @param int $id Event ID
 */
function send_sse_event(string $event, $data, int $id): void {
    echo "id: {$id}\n";
    echo "event: {$event}\n";
    echo "data: " . json_encode($data) . "\n\n";
    
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

/**
 * Send a keep-alive comment to prevent connection timeout.
 */
function send_keepalive(): void {
    echo ": keepalive\n\n";
    
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

/**
 * Get question options as array for classengage.
 *
 * @param stdClass $question Question object
 * @return array Options array
 */
function classengage_get_question_options($question): array {
    $options = [];
    
    if (!empty($question->optiona)) {
        $options[] = ['key' => 'A', 'text' => $question->optiona];
    }
    if (!empty($question->optionb)) {
        $options[] = ['key' => 'B', 'text' => $question->optionb];
    }
    if (!empty($question->optionc)) {
        $options[] = ['key' => 'C', 'text' => $question->optionc];
    }
    if (!empty($question->optiond)) {
        $options[] = ['key' => 'D', 'text' => $question->optiond];
    }
    
    return $options;
}

// Send initial connection confirmation.
$eventid++;
send_sse_event('connected', [
    'connectionid' => $connectionid,
    'sessionid' => $sessionid,
    'userid' => $USER->id,
    'timestamp' => time(),
], $eventid);

// Main SSE loop.
while (true) {
    // Check if connection is still alive.
    if (connection_aborted()) {
        $statemanager->handle_disconnect($connectionid);
        break;
    }
    
    // Check max runtime.
    if ((time() - $starttime) > $maxruntime) {
        $eventid++;
        send_sse_event('reconnect', [
            'reason' => 'max_runtime',
            'message' => 'Connection timeout, please reconnect',
        ], $eventid);
        break;
    }
    
    try {
        // Get current session state.
        $currentstate = $statemanager->get_session_state($sessionid);
        
        if ($currentstate === null) {
            // Session no longer exists.
            $eventid++;
            send_sse_event('session_ended', [
                'reason' => 'session_not_found',
                'timestamp' => time(),
            ], $eventid);
            break;
        }
        
        // Check for state changes.
        $statechanged = false;
        
        if ($laststate === null) {
            $statechanged = true;
        } else if ($laststate->status !== $currentstate->status) {
            $statechanged = true;
        } else if ($laststate->currentquestion !== $currentstate->currentquestion) {
            $statechanged = true;
        }
        
        // Send state update if changed.
        if ($statechanged) {
            $eventid++;
            
            // Determine event type based on status change.
            if ($laststate !== null && $laststate->status !== $currentstate->status) {
                switch ($currentstate->status) {
                    case 'active':
                        if ($laststate->status === 'paused') {
                            send_sse_event('session_resumed', [
                                'sessionid' => $sessionid,
                                'status' => $currentstate->status,
                                'currentquestion' => $currentstate->currentquestion,
                                'timerremaining' => $currentstate->timerremaining,
                                'timestamp' => time(),
                            ], $eventid);
                        } else {
                            send_sse_event('session_started', [
                                'sessionid' => $sessionid,
                                'status' => $currentstate->status,
                                'currentquestion' => $currentstate->currentquestion,
                                'timerremaining' => $currentstate->timerremaining,
                                'timestamp' => time(),
                            ], $eventid);
                        }
                        break;
                        
                    case 'paused':
                        send_sse_event('session_paused', [
                            'sessionid' => $sessionid,
                            'status' => $currentstate->status,
                            'timerremaining' => $currentstate->timerremaining,
                            'timestamp' => time(),
                        ], $eventid);
                        break;
                        
                    case 'completed':
                        send_sse_event('session_completed', [
                            'sessionid' => $sessionid,
                            'status' => $currentstate->status,
                            'timestamp' => time(),
                        ], $eventid);
                        // End the SSE connection when session completes.
                        $statemanager->handle_disconnect($connectionid);
                        break 2;
                }
            }
            
            // Check for question change.
            if ($lastquestion !== $currentstate->currentquestion && $currentstate->status === 'active') {
                // Get client state with question details.
                $clientstate = $statemanager->get_client_state($sessionid, $USER->id);
                
                $eventid++;
                send_sse_event('question_broadcast', [
                    'sessionid' => $sessionid,
                    'questionnumber' => $currentstate->currentquestion,
                    'question' => $clientstate->question ? [
                        'id' => $clientstate->question->id,
                        'text' => $clientstate->question->questiontext,
                        'options' => classengage_get_question_options($clientstate->question),
                    ] : null,
                    'timelimit' => $currentstate->timerremaining,
                    'hasanswered' => $clientstate->hasanswered,
                    'timestamp' => time(),
                ], $eventid);
                
                $lastquestion = $currentstate->currentquestion;
            }
            
            $laststate = $currentstate;
        }
        
        // Send periodic state update (for timer sync).
        if ($currentstate->status === 'active' && $currentstate->timerremaining !== null) {
            // Calculate current timer remaining.
            $elapsed = time() - $currentstate->questionstarttime;
            $timerremaining = max(0, $currentstate->timerremaining - $elapsed);
            
            $eventid++;
            send_sse_event('timer_sync', [
                'sessionid' => $sessionid,
                'timerremaining' => $timerremaining,
                'timestamp' => time(),
            ], $eventid);
        }
        
        // Process heartbeat.
        if ((time() - $lastheartbeat) >= $heartbeatinterval) {
            $heartbeatmanager->process_heartbeat($sessionid, $USER->id, $connectionid);
            $lastheartbeat = time();
            send_keepalive();
        }
        
    } catch (Exception $e) {
        $eventid++;
        send_sse_event('error', [
            'message' => 'Server error occurred',
            'timestamp' => time(),
        ], $eventid);
    }
    
    // Sleep for 1 second before next iteration.
    sleep(1);
}

// Cleanup on exit.
$statemanager->handle_disconnect($connectionid);
