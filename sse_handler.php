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

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_classengage\session_state_manager;

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

// Check if user is instructor (for stats/students events).
$isinstructor = has_capability('mod/classengage:viewanalytics', $context);

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
$keepaliveinterval = 15; // Send keepalive every 15 seconds.
$lastkeepalive = time();

// Enterprise optimization: Staggered intervals to reduce server load.
$statsinterval = 2;      // Stats update every 2 seconds.
$studentsinterval = 3;   // Students list update every 3 seconds.
$laststatsupdate = 0;
$laststudentsupdate = 0;

// Change detection hashes to avoid sending duplicate data.
$laststathash = '';
$laststudenthash = '';

/**
 * Send an SSE event to the client.
 *
 * @param string $event Event type
 * @param mixed $data Event data
 * @param int $id Event ID
 */
function send_sse_event(string $event, $data, int $id): void
{
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
function send_keepalive(): void
{
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
function classengage_get_question_options($question): array
{
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

// INSTRUCTOR: Send initial stats and students immediately on connection.
if ($isinstructor) {
    // Initial stats - calculate all required fields
    $analytics = new \mod_classengage\analytics_engine($classengage->id, $context);
    $currentstats = $analytics->get_current_question_stats($sessionid);
    $sessiondata = $DB->get_record('classengage_sessions', ['id' => $sessionid], 'status, currentquestion, numquestions, timelimit, questionstarttime');

    // Count distinct participants (users who submitted at least one response)
    $sql = "SELECT COUNT(DISTINCT userid) FROM {classengage_responses} WHERE sessionid = ?";
    $participants = $DB->count_records_sql($sql, [$sessionid]);

    // Calculate participation rate
    $totalenrolled = count_enrolled_users($context, 'mod/classengage:takequiz');
    if ($totalenrolled == 0) {
        $totalenrolled = count_enrolled_users($context);
    }
    $responses = $currentstats['total'];
    $participationrate = $totalenrolled > 0 ? round(($responses / $totalenrolled) * 100, 1) : 0.0;

    // Get connection statistics
    $connectionstats = $statemanager->get_session_statistics($sessionid);

    $statsdata = [
        'sessionid' => $sessionid,
        'status' => $sessiondata->status,
        'currentquestion' => (int) $sessiondata->currentquestion,
        'totalquestions' => (int) $sessiondata->numquestions,
        'timelimit' => (int) $sessiondata->timelimit,
        'timeremaining' => max(0, $sessiondata->timelimit - (time() - $sessiondata->questionstarttime)),
        'distribution' => [
            'A' => $currentstats['A'],
            'B' => $currentstats['B'],
            'C' => $currentstats['C'],
            'D' => $currentstats['D'],
            'total' => $currentstats['total'],
            'correctanswer' => $currentstats['correctanswer'] ?? '',
        ],
        'responses' => $responses,
        'participants' => $participants,
        'participationrate' => $participationrate,
        'connected' => $connectionstats['connected'] ?? 0,
        'answered' => $connectionstats['answered'] ?? 0,
        'pending' => $connectionstats['pending'] ?? 0,
        'timestamp' => time(),
    ];
    $eventid++;
    send_sse_event('stats_update', $statsdata, $eventid);
    $laststathash = md5(json_encode($statsdata));

    // Initial students list - fetch ALL enrolled students (not just connected).
    $connectedstudents = $statemanager->get_connected_students($sessionid);

    // Build lookup of connected students by userid.
    $connectedlookup = [];
    foreach ($connectedstudents as $student) {
        $connectedlookup[$student->userid] = $student;
    }

    // Get all enrolled students with takequiz capability.
    $enrolledstudents = get_enrolled_users($context, 'mod/classengage:takequiz', 0, 'u.*', 'u.lastname, u.firstname');

    // Get current question ID to check who has answered.
    $currentquestionid = null;
    $sqlq = "SELECT q.id FROM {classengage_questions} q
             JOIN {classengage_session_questions} sq ON sq.questionid = q.id
             WHERE sq.sessionid = :sessionid AND sq.questionorder = :questionorder";
    $currentq = $DB->get_record_sql($sqlq, ['sessionid' => $sessionid, 'questionorder' => $session->currentquestion + 1]);
    if ($currentq) {
        $currentquestionid = $currentq->id;
    }

    // Check which students have answered the current question.
    $answeredusers = [];
    if ($currentquestionid) {
        $answeredusers = $DB->get_fieldset_select(
            'classengage_responses',
            'userid',
            'sessionid = ? AND questionid = ?',
            [$sessionid, $currentquestionid]
        );
        $answeredusers = array_flip($answeredusers);
    }

    // Build students list from all enrolled.
    $students = [];
    foreach ($enrolledstudents as $user) {
        $isconnected = isset($connectedlookup[$user->id]);
        $hasanswered = isset($answeredusers[$user->id]);

        // Use connection status if connected, otherwise 'not_connected'.
        $status = 'not_connected';
        if ($isconnected) {
            $status = $connectedlookup[$user->id]->status;
            $hasanswered = $connectedlookup[$user->id]->hasanswered || $hasanswered;
        }

        $students[] = [
            'userid' => $user->id,
            'fullname' => fullname($user),
            'status' => $status,
            'hasanswered' => $hasanswered,
        ];
    }

    // Sort: connected first, then non-connected; within each group, alphabetically.
    usort($students, function ($a, $b) {
        $aconnected = ($a['status'] !== 'not_connected') ? 0 : 1;
        $bconnected = ($b['status'] !== 'not_connected') ? 0 : 1;
        if ($aconnected !== $bconnected) {
            return $aconnected - $bconnected;
        }
        return strcasecmp($a['fullname'], $b['fullname']);
    });

    $studentsdata = [
        'sessionid' => $sessionid,
        'students' => $students,
        'stats' => [
            'connected' => $connectionstats['connected'] ?? count($connectedstudents),
            'answered' => $connectionstats['answered'] ?? 0,
            'pending' => $connectionstats['pending'] ?? 0,
            'total' => count($enrolledstudents),
        ],
        'timestamp' => time(),
    ];
    $eventid++;
    send_sse_event('students_update', $studentsdata, $eventid);
    $laststudenthash = md5(json_encode($studentsdata));
}

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

        // NOTE: timer_sync removed - Timer is now fully client-side.
        // Client receives timelimit in question_broadcast and runs local countdown.
        // Server validates timing on answer submission via API only.

        // Send keepalive to prevent connection timeout.
        if ((time() - $lastkeepalive) >= $keepaliveinterval) {
            $lastkeepalive = time();
            send_keepalive();
        }

        // =========================================================================
        // INSTRUCTOR-ONLY SSE EVENTS (Enterprise optimization)
        // Push stats and students data to eliminate control panel polling.
        // =========================================================================
        if ($isinstructor) {
            $now = time();


            // Sent every 2 seconds, but only if meaningful data changed.
            if (($now - $laststatsupdate) >= $statsinterval) {
                $laststatsupdate = $now;

                // Get current question stats - BYPASS analytics engine cache for real-time accuracy.
                // Query database directly to ensure fresh data on every check.
                $sessiondata = $DB->get_record('classengage_sessions', ['id' => $sessionid], 'status, currentquestion, numquestions, timelimit, questionstarttime');

                // Get current question ID
                $sqlq = "SELECT q.id, q.correctanswer
                          FROM {classengage_questions} q
                          JOIN {classengage_session_questions} sq ON sq.questionid = q.id
                         WHERE sq.sessionid = :sessionid
                           AND sq.questionorder = :questionorder";
                $currentq = $DB->get_record_sql($sqlq, [
                    'sessionid' => $sessionid,
                    'questionorder' => $sessiondata->currentquestion + 1
                ]);

                // Get response distribution for current question
                $distribution = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'total' => 0];
                $correctanswer = '';
                if ($currentq) {
                    $correctanswer = $currentq->correctanswer;
                    $sqlresp = "SELECT answer, COUNT(*) as cnt
                                  FROM {classengage_responses}
                                 WHERE sessionid = :sessionid
                                   AND questionid = :questionid
                              GROUP BY answer";
                    $responses = $DB->get_records_sql($sqlresp, [
                        'sessionid' => $sessionid,
                        'questionid' => $currentq->id
                    ]);
                    foreach ($responses as $r) {
                        $ans = strtoupper($r->answer);
                        if (isset($distribution[$ans])) {
                            $distribution[$ans] = (int) $r->cnt;
                            $distribution['total'] += (int) $r->cnt;
                        }
                    }
                }

                // Count distinct participants
                $sql = "SELECT COUNT(DISTINCT userid) FROM {classengage_responses} WHERE sessionid = ?";
                $participants = $DB->count_records_sql($sql, [$sessionid]);

                // Calculate participation rate
                $totalenrolled = count_enrolled_users($context, 'mod/classengage:takequiz');
                if ($totalenrolled == 0) {
                    $totalenrolled = count_enrolled_users($context);
                }
                $responses = $distribution['total'];
                $participationrate = $totalenrolled > 0 ? round(($responses / $totalenrolled) * 100, 1) : 0.0;

                // Get connection statistics
                $connectionstats = $statemanager->get_session_statistics($sessionid);

                // Calculate timeremaining (volatile - excluded from hash).
                $timeremaining = max(0, $sessiondata->timelimit - ($now - $sessiondata->questionstarttime));

                // Build hash payload - EXCLUDE volatile fields (timestamp, timeremaining).
                // These change every second and would defeat change detection.
                $hashpayload = [
                    'status' => $sessiondata->status,
                    'currentquestion' => (int) $sessiondata->currentquestion,
                    'totalquestions' => (int) $sessiondata->numquestions,
                    'distribution' => [
                        'A' => $distribution['A'],
                        'B' => $distribution['B'],
                        'C' => $distribution['C'],
                        'D' => $distribution['D'],
                        'total' => $distribution['total'],
                    ],
                    'responses' => $responses,
                    'participants' => $participants,
                    'connected' => $connectionstats['connected'] ?? 0,
                    'answered' => $connectionstats['answered'] ?? 0,
                    'pending' => $connectionstats['pending'] ?? 0,
                ];

                // Change detection: only send if meaningful data changed.
                $stathash = md5(json_encode($hashpayload));
                if ($stathash !== $laststathash) {
                    $laststathash = $stathash;

                    // Build full stats payload with volatile fields for client.
                    $statsdata = [
                        'sessionid' => $sessionid,
                        'status' => $sessiondata->status,
                        'currentquestion' => (int) $sessiondata->currentquestion,
                        'totalquestions' => (int) $sessiondata->numquestions,
                        'timelimit' => (int) $sessiondata->timelimit,
                        'timeremaining' => $timeremaining,
                        'distribution' => [
                            'A' => $distribution['A'],
                            'B' => $distribution['B'],
                            'C' => $distribution['C'],
                            'D' => $distribution['D'],
                            'total' => $distribution['total'],
                            'correctanswer' => $correctanswer,
                        ],
                        'responses' => $responses,
                        'participants' => $participants,
                        'participationrate' => $participationrate,
                        'connected' => $connectionstats['connected'] ?? 0,
                        'answered' => $connectionstats['answered'] ?? 0,
                        'pending' => $connectionstats['pending'] ?? 0,
                        'timestamp' => $now,
                    ];

                    $eventid++;
                    send_sse_event('stats_update', $statsdata, $eventid);
                }
            }

            // Students update event (replaces AJAX polling for student list).
            // Sent every 3 seconds, but only if meaningful data changed.
            if (($now - $laststudentsupdate) >= $studentsinterval) {
                $laststudentsupdate = $now;

                // Get connected students and build lookup.
                $connectedstudents = $statemanager->get_connected_students($sessionid);
                $sessionstats = $statemanager->get_session_statistics($sessionid);

                $connectedlookup = [];
                foreach ($connectedstudents as $student) {
                    $connectedlookup[$student->userid] = $student;
                }

                // Get all enrolled students with takequiz capability.
                $enrolledstudents = get_enrolled_users($context, 'mod/classengage:takequiz', 0, 'u.*', 'u.lastname, u.firstname');

                // Get current question ID to check who has answered.
                $sessiondata = $DB->get_record('classengage_sessions', ['id' => $sessionid], 'currentquestion');
                $currentquestionid = null;
                $sqlq = "SELECT q.id FROM {classengage_questions} q
                         JOIN {classengage_session_questions} sq ON sq.questionid = q.id
                         WHERE sq.sessionid = :sessionid AND sq.questionorder = :questionorder";
                $currentq = $DB->get_record_sql($sqlq, ['sessionid' => $sessionid, 'questionorder' => $sessiondata->currentquestion + 1]);
                if ($currentq) {
                    $currentquestionid = $currentq->id;
                }

                // Check which students have answered the current question.
                $answeredusers = [];
                if ($currentquestionid) {
                    $answeredusers = $DB->get_fieldset_select(
                        'classengage_responses',
                        'userid',
                        'sessionid = ? AND questionid = ?',
                        [$sessionid, $currentquestionid]
                    );
                    $answeredusers = array_flip($answeredusers);
                }

                // Build students list from all enrolled.
                $students = [];
                foreach ($enrolledstudents as $user) {
                    $isconnected = isset($connectedlookup[$user->id]);
                    $hasanswered = isset($answeredusers[$user->id]);

                    // Use connection status if connected, otherwise 'not_connected'.
                    $status = 'not_connected';
                    if ($isconnected) {
                        $status = $connectedlookup[$user->id]->status;
                        $hasanswered = $connectedlookup[$user->id]->hasanswered || $hasanswered;
                    }

                    $students[] = [
                        'userid' => $user->id,
                        'fullname' => fullname($user),
                        'status' => $status,
                        'hasanswered' => $hasanswered,
                    ];
                }

                // Sort: connected first, then non-connected; within each group, alphabetically.
                usort($students, function ($a, $b) {
                    $aconnected = ($a['status'] !== 'not_connected') ? 0 : 1;
                    $bconnected = ($b['status'] !== 'not_connected') ? 0 : 1;
                    if ($aconnected !== $bconnected) {
                        return $aconnected - $bconnected;
                    }
                    return strcasecmp($a['fullname'], $b['fullname']);
                });

                // Build hash payload - EXCLUDE timestamp (volatile field).
                $hashpayload = [
                    'students' => $students,
                    'stats' => [
                        'connected' => $sessionstats['connected'] ?? count($connectedstudents),
                        'answered' => $sessionstats['answered'] ?? 0,
                        'pending' => $sessionstats['pending'] ?? 0,
                        'total' => count($enrolledstudents),
                    ],
                ];

                // Change detection: only send if meaningful data changed.
                $studenthash = md5(json_encode($hashpayload));
                if ($studenthash !== $laststudenthash) {
                    $laststudenthash = $studenthash;

                    // Build full payload with timestamp for client.
                    $studentsdata = [
                        'sessionid' => $sessionid,
                        'students' => $students,
                        'stats' => [
                            'connected' => $sessionstats['connected'] ?? count($connectedstudents),
                            'answered' => $sessionstats['answered'] ?? 0,
                            'pending' => $sessionstats['pending'] ?? 0,
                            'total' => count($enrolledstudents),
                        ],
                        'timestamp' => $now,
                    ];

                    $eventid++;
                    send_sse_event('students_update', $studentsdata, $eventid);
                }
            }
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
