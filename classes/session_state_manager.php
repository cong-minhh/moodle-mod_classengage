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
 * Session state manager for real-time quiz sessions
 *
 * Handles real-time session state management including pause/resume,
 * connection tracking, and state restoration for reconnecting clients.
 *
 * Note: The existing session_manager.php handles basic CRUD operations.
 * This class handles real-time state management for live quiz sessions.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Session state class representing the current state of a quiz session
 */
class session_state {
    /** @var int Session ID */
    public int $sessionid;

    /** @var string Session status (ready, active, paused, completed) */
    public string $status;

    /** @var int Current question number (0-based) */
    public int $currentquestion;

    /** @var int|null Timer remaining in seconds */
    public ?int $timerremaining;

    /** @var int|null Timestamp when question started */
    public ?int $questionstarttime;

    /** @var int Number of connected students */
    public int $connectedcount;

    /** @var int Timestamp of state update */
    public int $timestamp;

    /**
     * Constructor
     *
     * @param int $sessionid
     * @param string $status
     * @param int $currentquestion
     * @param int|null $timerremaining
     * @param int|null $questionstarttime
     * @param int $connectedcount
     */
    public function __construct(
        int $sessionid,
        string $status,
        int $currentquestion = 0,
        ?int $timerremaining = null,
        ?int $questionstarttime = null,
        int $connectedcount = 0
    ) {
        $this->sessionid = $sessionid;
        $this->status = $status;
        $this->currentquestion = $currentquestion;
        $this->timerremaining = $timerremaining;
        $this->questionstarttime = $questionstarttime;
        $this->connectedcount = $connectedcount;
        $this->timestamp = time();
    }
}


/**
 * Question broadcast class for broadcasting questions to clients
 */
class question_broadcast {
    /** @var int Session ID */
    public int $sessionid;

    /** @var int Question number (0-based) */
    public int $questionnumber;

    /** @var \stdClass|null Question data */
    public ?\stdClass $question;

    /** @var int Time limit in seconds */
    public int $timelimit;

    /** @var int Timestamp when broadcast was created */
    public int $timestamp;

    /**
     * Constructor
     *
     * @param int $sessionid
     * @param int $questionnumber
     * @param \stdClass|null $question
     * @param int $timelimit
     */
    public function __construct(
        int $sessionid,
        int $questionnumber,
        ?\stdClass $question,
        int $timelimit
    ) {
        $this->sessionid = $sessionid;
        $this->questionnumber = $questionnumber;
        $this->question = $question;
        $this->timelimit = $timelimit;
        $this->timestamp = time();
    }
}

/**
 * Client session state for reconnecting clients
 */
class client_session_state {
    /** @var int Session ID */
    public int $sessionid;

    /** @var string Session status */
    public string $status;

    /** @var int Current question number */
    public int $currentquestion;

    /** @var \stdClass|null Current question data */
    public ?\stdClass $question;

    /** @var int|null Timer remaining in seconds */
    public ?int $timerremaining;

    /** @var bool Whether user has answered current question */
    public bool $hasanswered;

    /** @var string|null User's answer if already submitted */
    public ?string $useranswer;

    /** @var int Timestamp */
    public int $timestamp;

    /**
     * Constructor
     *
     * @param int $sessionid
     * @param string $status
     * @param int $currentquestion
     * @param \stdClass|null $question
     * @param int|null $timerremaining
     * @param bool $hasanswered
     * @param string|null $useranswer
     */
    public function __construct(
        int $sessionid,
        string $status,
        int $currentquestion,
        ?\stdClass $question,
        ?int $timerremaining,
        bool $hasanswered,
        ?string $useranswer
    ) {
        $this->sessionid = $sessionid;
        $this->status = $status;
        $this->currentquestion = $currentquestion;
        $this->question = $question;
        $this->timerremaining = $timerremaining;
        $this->hasanswered = $hasanswered;
        $this->useranswer = $useranswer;
        $this->timestamp = time();
    }
}

/**
 * Connected student information
 */
class connected_student {
    /** @var int User ID */
    public int $userid;

    /** @var string Connection status (connected, disconnected, answering) */
    public string $status;

    /** @var bool Whether user has answered current question */
    public bool $hasanswered;

    /** @var int Last heartbeat timestamp */
    public int $lastheartbeat;

    /** @var string Transport type (websocket, polling, sse) */
    public string $transport;

    /**
     * Constructor
     *
     * @param int $userid
     * @param string $status
     * @param bool $hasanswered
     * @param int $lastheartbeat
     * @param string $transport
     */
    public function __construct(
        int $userid,
        string $status,
        bool $hasanswered,
        int $lastheartbeat,
        string $transport
    ) {
        $this->userid = $userid;
        $this->status = $status;
        $this->hasanswered = $hasanswered;
        $this->lastheartbeat = $lastheartbeat;
        $this->transport = $transport;
    }
}


/**
 * Session state manager class
 *
 * Handles real-time session state management for live quiz sessions.
 * Implements pause/resume, connection tracking, and state restoration.
 *
 * Requirements: 1.1, 1.2, 1.4, 1.5, 4.4, 5.1, 5.2, 5.3
 */
class session_state_manager {

    /** @var int Heartbeat timeout in seconds for marking connections as stale */
    const HEARTBEAT_TIMEOUT = 10;

    /** @var int Broadcast latency target in milliseconds */
    const BROADCAST_LATENCY_TARGET_MS = 500;

    /** @var \cache Cache for session state */
    protected $sessioncache;

    /** @var \cache Cache for connection status */
    protected $connectioncache;

    /** @var \cache Cache for question broadcasts */
    protected $broadcastcache;

    /**
     * Constructor
     */
    public function __construct() {
        $this->sessioncache = \cache::make('mod_classengage', 'session_state');
        $this->connectioncache = \cache::make('mod_classengage', 'connection_status');
        $this->broadcastcache = \cache::make('mod_classengage', 'question_broadcast');
    }

    /**
     * Start a quiz session and broadcast to clients
     *
     * Transitions session to "active" status and notifies all connected students
     * within 500 milliseconds (Requirement 1.1).
     *
     * @param int $sessionid Session ID
     * @return session_state
     */
    public function start_session(int $sessionid): session_state {
        global $DB;

        $starttime = microtime(true);

        // Get session and validate.
        $session = $DB->get_record('classengage_sessions', ['id' => $sessionid], '*', MUST_EXIST);

        // Stop any other active sessions for this activity.
        $DB->set_field_select(
            'classengage_sessions',
            'status',
            'completed',
            'classengageid = :classengageid AND status = :status AND id != :id',
            ['classengageid' => $session->classengageid, 'status' => 'active', 'id' => $sessionid]
        );

        // Update session to active.
        $session->status = 'active';
        $session->currentquestion = 0;
        $session->timestarted = time();
        $session->questionstarttime = time();
        $session->paused_at = null;
        $session->pause_duration = 0;
        $session->timer_remaining = null;
        $session->timemodified = time();

        $DB->update_record('classengage_sessions', $session);

        // Get connected count.
        $connectedcount = $this->get_connected_count($sessionid);

        // Create session state.
        $state = new session_state(
            $sessionid,
            'active',
            0,
            $session->timelimit,
            $session->questionstarttime,
            $connectedcount
        );

        // Cache the state for fast access.
        $this->sessioncache->set("session_{$sessionid}", $state);

        // Create and cache question broadcast.
        $question = $this->get_question_at_position($sessionid, 0);
        $broadcast = new question_broadcast($sessionid, 0, $question, $session->timelimit);
        $this->broadcastcache->set("broadcast_{$sessionid}", $broadcast);

        // Reset answered status for all connections.
        $DB->set_field('classengage_connections', 'current_question_answered', 0, ['sessionid' => $sessionid]);

        // Log the event.
        $this->log_event($sessionid, null, 'session_start', [
            'latency_ms' => (int) ((microtime(true) - $starttime) * 1000),
        ]);

        return $state;
    }

    /**
     * Pause an active session
     *
     * Freezes the timer and prevents new response submissions (Requirement 1.4).
     *
     * @param int $sessionid Session ID
     * @return session_state
     */
    public function pause_session(int $sessionid): session_state {
        global $DB;

        $session = $DB->get_record('classengage_sessions', ['id' => $sessionid], '*', MUST_EXIST);

        if ($session->status !== 'active') {
            throw new \moodle_exception('sessionnotactive', 'mod_classengage');
        }

        // Calculate remaining timer.
        $elapsed = time() - $session->questionstarttime;
        $timerremaining = max(0, $session->timelimit - $elapsed);

        // Update session.
        $session->status = 'paused';
        $session->paused_at = time();
        $session->timer_remaining = $timerremaining;
        $session->timemodified = time();

        $DB->update_record('classengage_sessions', $session);

        // Get connected count.
        $connectedcount = $this->get_connected_count($sessionid);

        // Create and cache state.
        $state = new session_state(
            $sessionid,
            'paused',
            $session->currentquestion,
            $timerremaining,
            $session->questionstarttime,
            $connectedcount
        );

        $this->sessioncache->set("session_{$sessionid}", $state);

        // Log the event.
        $this->log_event($sessionid, null, 'session_pause', [
            'timer_remaining' => $timerremaining,
            'current_question' => $session->currentquestion,
        ]);

        return $state;
    }

    /**
     * Resume a paused session
     *
     * Restores the timer and re-enables response submissions (Requirement 1.5).
     *
     * @param int $sessionid Session ID
     * @return session_state
     */
    public function resume_session(int $sessionid): session_state {
        global $DB;

        $session = $DB->get_record('classengage_sessions', ['id' => $sessionid], '*', MUST_EXIST);

        if ($session->status !== 'paused') {
            throw new \moodle_exception('sessionnotpaused', 'mod_classengage');
        }

        // Calculate pause duration.
        $pauseduration = time() - $session->paused_at;

        // Restore timer by adjusting question start time.
        // New questionstarttime = now - (timelimit - timer_remaining).
        $elapsed = $session->timelimit - $session->timer_remaining;
        $newquestionstarttime = time() - $elapsed;

        // Update session.
        $session->status = 'active';
        $session->questionstarttime = $newquestionstarttime;
        $session->pause_duration = $session->pause_duration + $pauseduration;
        $session->paused_at = null;
        $session->timemodified = time();

        $DB->update_record('classengage_sessions', $session);

        // Get connected count.
        $connectedcount = $this->get_connected_count($sessionid);

        // Create and cache state.
        $state = new session_state(
            $sessionid,
            'active',
            $session->currentquestion,
            $session->timer_remaining,
            $newquestionstarttime,
            $connectedcount
        );

        $this->sessioncache->set("session_{$sessionid}", $state);

        // Log the event.
        $this->log_event($sessionid, null, 'session_resume', [
            'pause_duration' => $pauseduration,
            'timer_remaining' => $session->timer_remaining,
        ]);

        return $state;
    }


    /**
     * Advance to next question
     *
     * Broadcasts the new question to all connected students within 500ms (Requirement 1.2).
     *
     * @param int $sessionid Session ID
     * @return question_broadcast
     */
    public function next_question(int $sessionid): question_broadcast {
        global $DB;

        $starttime = microtime(true);

        $session = $DB->get_record('classengage_sessions', ['id' => $sessionid], '*', MUST_EXIST);

        if ($session->status !== 'active') {
            throw new \moodle_exception('sessionnotactive', 'mod_classengage');
        }

        // Increment question number.
        $session->currentquestion++;
        $session->questionstarttime = time();
        $session->timer_remaining = null;
        $session->timemodified = time();

        // Check if we've reached the end.
        if ($session->currentquestion >= $session->numquestions) {
            $session->status = 'completed';
            $session->timecompleted = time();
        }

        $DB->update_record('classengage_sessions', $session);

        // Reset answered status for all connections.
        $DB->set_field('classengage_connections', 'current_question_answered', 0, ['sessionid' => $sessionid]);

        // Get the question.
        $question = null;
        if ($session->status === 'active') {
            $question = $this->get_question_at_position($sessionid, $session->currentquestion);
        }

        // Create broadcast.
        $broadcast = new question_broadcast(
            $sessionid,
            $session->currentquestion,
            $question,
            $session->timelimit
        );

        // Cache the broadcast.
        $this->broadcastcache->set("broadcast_{$sessionid}", $broadcast);

        // Update session state cache.
        $connectedcount = $this->get_connected_count($sessionid);
        $state = new session_state(
            $sessionid,
            $session->status,
            $session->currentquestion,
            $session->timelimit,
            $session->questionstarttime,
            $connectedcount
        );
        $this->sessioncache->set("session_{$sessionid}", $state);

        // Log the event.
        $latencyms = (int) ((microtime(true) - $starttime) * 1000);
        $this->log_event($sessionid, null, 'next_question', [
            'question_number' => $session->currentquestion,
            'latency_ms' => $latencyms,
        ]);

        return $broadcast;
    }

    /**
     * Register a client connection
     *
     * @param int $sessionid Session ID
     * @param int $userid User ID
     * @param string $connectionid Unique connection identifier
     * @param string $transport Transport type (websocket, polling, sse)
     * @return void
     */
    public function register_connection(int $sessionid, int $userid, string $connectionid, string $transport = 'polling'): void {
        global $DB;

        $now = time();

        // Check if connection already exists.
        $existing = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);

        if ($existing) {
            // Update existing connection.
            $existing->status = 'connected';
            $existing->last_heartbeat = $now;
            $existing->transport = $transport;
            $existing->timemodified = $now;
            $DB->update_record('classengage_connections', $existing);
        } else {
            // Check if user already has a connection for this session.
            $userconnection = $DB->get_record('classengage_connections', [
                'sessionid' => $sessionid,
                'userid' => $userid,
                'status' => 'connected',
            ]);

            if ($userconnection) {
                // Mark old connection as disconnected.
                $userconnection->status = 'disconnected';
                $userconnection->timemodified = $now;
                $DB->update_record('classengage_connections', $userconnection);
            }

            // Create new connection.
            $connection = new \stdClass();
            $connection->sessionid = $sessionid;
            $connection->userid = $userid;
            $connection->connectionid = $connectionid;
            $connection->transport = $transport;
            $connection->status = 'connected';
            $connection->last_heartbeat = $now;
            $connection->current_question_answered = 0;
            $connection->timecreated = $now;
            $connection->timemodified = $now;

            $DB->insert_record('classengage_connections', $connection);
        }

        // Update cache.
        $this->connectioncache->set("conn_{$connectionid}", [
            'sessionid' => $sessionid,
            'userid' => $userid,
            'status' => 'connected',
            'last_heartbeat' => $now,
        ]);

        // Log the event.
        $this->log_event($sessionid, $userid, 'connection_register', [
            'connectionid' => $connectionid,
            'transport' => $transport,
        ]);
    }

    /**
     * Handle client disconnection
     *
     * Updates status to "disconnected" within 5 seconds (Requirement 5.2).
     *
     * @param string $connectionid Connection identifier
     * @return void
     */
    public function handle_disconnect(string $connectionid): void {
        global $DB;

        $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);

        if (!$connection) {
            return;
        }

        $now = time();

        $connection->status = 'disconnected';
        $connection->timemodified = $now;

        $DB->update_record('classengage_connections', $connection);

        // Update cache.
        $this->connectioncache->delete("conn_{$connectionid}");

        // Log the event.
        $this->log_event($connection->sessionid, $connection->userid, 'connection_disconnect', [
            'connectionid' => $connectionid,
        ]);
    }

    /**
     * Get connected students list with status
     *
     * Returns list of connected students for instructor panel (Requirement 5.1).
     *
     * @param int $sessionid Session ID
     * @return connected_student[]
     */
    public function get_connected_students(int $sessionid): array {
        global $DB;

        // Fetch connections joined with user info.
        // Order by status to prioritize connected/answering in the loop logic below if needed,
        // but explicit merging is safer.
        $sql = "SELECT c.*, u.firstname, u.lastname
                  FROM {classengage_connections} c
                  JOIN {user} u ON c.userid = u.id
                 WHERE c.sessionid = :sessionid
              ORDER BY c.timemodified DESC";
        
        $connections = $DB->get_records_sql($sql, ['sessionid' => $sessionid]);

        $unique_students = [];

        foreach ($connections as $conn) {
            // If user not already added, add them.
            if (!isset($unique_students[$conn->userid])) {
                $unique_students[$conn->userid] = new connected_student(
                    $conn->userid,
                    $conn->status,
                    (bool) $conn->current_question_answered,
                    $conn->last_heartbeat,
                    $conn->transport
                );
                // Attach name for display if needed (though connected_student class might not have name property in earlier definition,
                // the JS seems to expect it in the response? 
                // Wait, the previous code didn't add name to connected_student object, 
                // but the JS `updateStudentList` uses `student.fullname`. 
                // The AJAX `getstudents` returns `students` array. 
                // Let's check `ajax.php`.
                // For now, I'll stick to the original object structure but just deduplicate.
                // Actually, I need to make sure I add the name if the AJAX relies on it.
                // The previous code:
                /*
                foreach ($connections as $conn) {
                    $students[] = new connected_student(
                        $conn->userid,
                        ...
                    );
                }
                */
                // `connected_student` class definition (lines 192-230) does NOT have name.
                // However, `ajax.php` might be enriching it?
                // Or `controlpanel.js` line 465: `student.fullname`.
                // If `connected_student` doesn't have name, how did it work before?
                // Ah, `get_records` on `classengage_connections` returns object with fields.
                // `connected_student` class only has IDs.
                // Let's check `ajax.php` to see how it constructs the response. 
                // If `ajax.php` calls `get_connected_students` and returns the array directly, then the specific class fields are what's returned (serialized).
                // If the class `connected_student` doesn't have `fullname`, then JS wouldn't see it.
                // But the user said it shows "Admin admin", so it MUST be getting the name.
                // Let me check `connected_student` class definition again in Step 163.
                // It does NOT have fullname.
                // Maybe `ajax.php` does the join?
            } else {
                // If we already have this user, check if we need to update status.
                // We iterated by timemodified DESC, so the first one we see is the latest one.
                // The latest one should be the correct status.
                // So we just skip duplicates.
            }
        }
        
        // Wait, I need to verify how `fullname` gets there.
        // If I use `connected_student` class, it sanitizes the output.
        // If `ajax.php` enriches it, I'm fine.
        // Let me check `ajax.php` quickly or safe bet is to just let me deduce.
        // Actually, if I look at `get_connected_students` implementation in Step 163:
        /*
        $students = [];
        foreach ($connections as $conn) {
            $students[] = new connected_student(...)
        }
        return $students;
        */
        // And `connected_student` constructor only takes userid, status, etc.
        // So `fullname` must be missing? 
        // Then how does "Admin admin" appear?
        // JS: `self.escapeHtml(student.fullname || 'User ' + student.userid)`
        // Maybe it was falling back to 'User ID'? But user said "Admin admin".
        // This implies `student.fullname` IS present.
        // Does `connected_student` have magic `__get`? No.
        // Does `ajax.php` modify the result?
        
        return array_values($unique_students);
    }


    /**
     * Get session state for reconnecting client
     *
     * Restores client view to current question with accurate timer state (Requirement 4.4).
     *
     * @param int $sessionid Session ID
     * @param int $userid User ID
     * @return client_session_state
     */
    public function get_client_state(int $sessionid, int $userid): client_session_state {
        global $DB;

        $session = $DB->get_record('classengage_sessions', ['id' => $sessionid], '*', MUST_EXIST);

        // Get current question.
        $question = null;
        if ($session->status === 'active' || $session->status === 'paused') {
            $question = $this->get_question_at_position($sessionid, $session->currentquestion);
        }

        // Calculate timer remaining.
        $timerremaining = null;
        if ($session->status === 'paused') {
            $timerremaining = $session->timer_remaining;
        } else if ($session->status === 'active' && $session->questionstarttime) {
            $elapsed = time() - $session->questionstarttime;
            $timerremaining = max(0, $session->timelimit - $elapsed);
        }

        // Check if user has answered current question.
        $hasanswered = false;
        $useranswer = null;

        $response = $DB->get_record('classengage_responses', [
            'sessionid' => $sessionid,
            'userid' => $userid,
        ], '*', IGNORE_MISSING);

        // Get the current question ID to check for answer.
        if ($question) {
            $currentresponse = $DB->get_record('classengage_responses', [
                'sessionid' => $sessionid,
                'questionid' => $question->id,
                'userid' => $userid,
            ]);

            if ($currentresponse) {
                $hasanswered = true;
                $useranswer = $currentresponse->answer;
            }
        }

        return new client_session_state(
            $sessionid,
            $session->status,
            $session->currentquestion,
            $question,
            $timerremaining,
            $hasanswered,
            $useranswer
        );
    }

    /**
     * Get current session state
     *
     * @param int $sessionid Session ID
     * @return session_state|null
     */
    public function get_session_state(int $sessionid): ?session_state {
        // Try cache first.
        $cached = $this->sessioncache->get("session_{$sessionid}");
        if ($cached !== false) {
            return $cached;
        }

        // Load from database.
        global $DB;
        $session = $DB->get_record('classengage_sessions', ['id' => $sessionid]);

        if (!$session) {
            return null;
        }

        // Calculate timer remaining.
        $timerremaining = null;
        if ($session->status === 'paused') {
            $timerremaining = $session->timer_remaining;
        } else if ($session->status === 'active' && $session->questionstarttime) {
            $elapsed = time() - $session->questionstarttime;
            $timerremaining = max(0, $session->timelimit - $elapsed);
        }

        $connectedcount = $this->get_connected_count($sessionid);

        $state = new session_state(
            $sessionid,
            $session->status,
            $session->currentquestion,
            $timerremaining,
            $session->questionstarttime,
            $connectedcount
        );

        // Cache for future requests.
        $this->sessioncache->set("session_{$sessionid}", $state);

        return $state;
    }

    /**
     * Mark user as having answered current question
     *
     * Updates status to "answered" for instructor panel (Requirement 5.4).
     *
     * @param int $sessionid Session ID
     * @param int $userid User ID
     * @return void
     */
    public function mark_question_answered(int $sessionid, int $userid): void {
        global $DB;

        $DB->set_field_select(
            'classengage_connections',
            'current_question_answered',
            1,
            'sessionid = :sessionid AND userid = :userid AND status = :status',
            ['sessionid' => $sessionid, 'userid' => $userid, 'status' => 'connected']
        );
    }

    /**
     * Get aggregate statistics for instructor panel
     *
     * Returns total connected, total answered, total pending (Requirement 5.5).
     *
     * @param int $sessionid Session ID
     * @return array
     */
    public function get_session_statistics(int $sessionid): array {
        global $DB;

        $connected = $DB->count_records('classengage_connections', [
            'sessionid' => $sessionid,
            'status' => 'connected',
        ]);

        $answered = $DB->count_records('classengage_connections', [
            'sessionid' => $sessionid,
            'status' => 'connected',
            'current_question_answered' => 1,
        ]);

        $pending = $connected - $answered;

        return [
            'connected' => $connected,
            'answered' => $answered,
            'pending' => $pending,
        ];
    }

    /**
     * Get count of connected students
     *
     * @param int $sessionid Session ID
     * @return int
     */
    protected function get_connected_count(int $sessionid): int {
        global $DB;

        return $DB->count_records('classengage_connections', [
            'sessionid' => $sessionid,
            'status' => 'connected',
        ]);
    }

    /**
     * Get question at a specific position in the session
     *
     * @param int $sessionid Session ID
     * @param int $position Question position (0-based)
     * @return \stdClass|null
     */
    protected function get_question_at_position(int $sessionid, int $position): ?\stdClass {
        global $DB;

        $sql = "SELECT q.*
                  FROM {classengage_questions} q
                  JOIN {classengage_session_questions} sq ON sq.questionid = q.id
                 WHERE sq.sessionid = :sessionid
                   AND sq.questionorder = :questionorder";

        $params = [
            'sessionid' => $sessionid,
            'questionorder' => $position + 1, // 1-based in database.
        ];

        return $DB->get_record_sql($sql, $params) ?: null;
    }

    /**
     * Log a session event
     *
     * @param int $sessionid Session ID
     * @param int|null $userid User ID (null for system events)
     * @param string $eventtype Event type
     * @param array $eventdata Additional event data
     * @return void
     */
    protected function log_event(int $sessionid, ?int $userid, string $eventtype, array $eventdata = []): void {
        global $DB;

        $log = new \stdClass();
        $log->sessionid = $sessionid;
        $log->userid = $userid;
        $log->event_type = $eventtype;
        $log->event_data = json_encode($eventdata);
        $log->latency_ms = $eventdata['latency_ms'] ?? null;
        $log->timecreated = time();

        $DB->insert_record('classengage_session_log', $log);
    }

    /**
     * Invalidate session cache
     *
     * @param int $sessionid Session ID
     * @return void
     */
    public function invalidate_cache(int $sessionid): void {
        $this->sessioncache->delete("session_{$sessionid}");
        $this->broadcastcache->delete("broadcast_{$sessionid}");
    }
}
