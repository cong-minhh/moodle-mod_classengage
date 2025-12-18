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
 * Event logger for real-time quiz sessions
 *
 * Provides comprehensive logging for session events, response submissions,
 * connection errors, and performance warnings.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Session statistics class for API responses
 */
class session_statistics {
    /** @var int Session ID */
    public int $sessionid;

    /** @var float Average latency in milliseconds */
    public float $averagelatency;

    /** @var float Error rate as percentage */
    public float $errorrate;

    /** @var int Throughput (responses per minute) */
    public int $throughput;

    /** @var int Total responses */
    public int $totalresponses;

    /** @var int Total errors */
    public int $totalerrors;

    /** @var int Time window in seconds */
    public int $timewindow;

    /** @var int Timestamp of calculation */
    public int $timestamp;

    /**
     * Constructor
     *
     * @param int $sessionid
     * @param float $averagelatency
     * @param float $errorrate
     * @param int $throughput
     * @param int $totalresponses
     * @param int $totalerrors
     * @param int $timewindow
     */
    public function __construct(
        int $sessionid,
        float $averagelatency = 0.0,
        float $errorrate = 0.0,
        int $throughput = 0,
        int $totalresponses = 0,
        int $totalerrors = 0,
        int $timewindow = 300
    ) {
        $this->sessionid = $sessionid;
        $this->averagelatency = $averagelatency;
        $this->errorrate = $errorrate;
        $this->throughput = $throughput;
        $this->totalresponses = $totalresponses;
        $this->totalerrors = $totalerrors;
        $this->timewindow = $timewindow;
        $this->timestamp = time();
    }
}

/**
 * Event logger class
 *
 * Implements comprehensive logging for quiz session events.
 * Requirements: 7.1, 7.2, 7.3, 7.4, 7.5
 */
class event_logger {

    /** @var string Event type for session start */
    const EVENT_SESSION_START = 'session_start';

    /** @var string Event type for session end */
    const EVENT_SESSION_END = 'session_end';

    /** @var string Event type for session pause */
    const EVENT_SESSION_PAUSE = 'session_pause';

    /** @var string Event type for session resume */
    const EVENT_SESSION_RESUME = 'session_resume';

    /** @var string Event type for response submission */
    const EVENT_RESPONSE_SUBMIT = 'response_submit';

    /** @var string Event type for response batch */
    const EVENT_RESPONSE_BATCH = 'response_batch';

    /** @var string Event type for connection error */
    const EVENT_CONNECTION_ERROR = 'connection_error';

    /** @var string Event type for performance warning */
    const EVENT_PERFORMANCE_WARNING = 'performance_warning';

    /** @var string Event type for reconnection */
    const EVENT_RECONNECT = 'reconnect';

    /** @var int Performance warning threshold for latency (ms) */
    const LATENCY_WARNING_THRESHOLD_MS = 1000;

    /** @var int Performance warning threshold for CPU (percentage) */
    const CPU_WARNING_THRESHOLD = 80;

    /** @var int Default time window for statistics (seconds) */
    const DEFAULT_STATS_WINDOW = 300;

    /**
     * Log a session start event
     *
     * Requirement 7.1: Log session start with timestamp, session ID, and instructor ID.
     *
     * @param int $sessionid Session ID
     * @param int $instructorid Instructor user ID
     * @param array $additionaldata Additional event data
     * @return int Log entry ID
     */
    public function log_session_start(int $sessionid, int $instructorid, array $additionaldata = []): int {
        return $this->log_event(
            $sessionid,
            $instructorid,
            self::EVENT_SESSION_START,
            array_merge([
                'action' => 'start',
                'instructor_id' => $instructorid,
            ], $additionaldata)
        );
    }

    /**
     * Log a session end event
     *
     * Requirement 7.1: Log session end with timestamp, session ID, and instructor ID.
     *
     * @param int $sessionid Session ID
     * @param int $instructorid Instructor user ID
     * @param array $additionaldata Additional event data
     * @return int Log entry ID
     */
    public function log_session_end(int $sessionid, int $instructorid, array $additionaldata = []): int {
        return $this->log_event(
            $sessionid,
            $instructorid,
            self::EVENT_SESSION_END,
            array_merge([
                'action' => 'end',
                'instructor_id' => $instructorid,
            ], $additionaldata)
        );
    }

    /**
     * Log a generic session event
     *
     * @param int $sessionid Session ID
     * @param string $eventtype Event type
     * @param array $eventdata Event data
     * @return int Log entry ID
     */
    public function log_session_event(int $sessionid, string $eventtype, array $eventdata = []): int {
        $userid = $eventdata['userid'] ?? null;
        unset($eventdata['userid']);

        return $this->log_event($sessionid, $userid, $eventtype, $eventdata);
    }

    /**
     * Log a response submission
     *
     * Requirement 7.2: Log submission with timestamp, latency, and success/failure status.
     *
     * @param int $sessionid Session ID
     * @param int $userid User ID
     * @param array $responsedata Response data including latency and success status
     * @return int Log entry ID
     */
    public function log_response_submission(int $sessionid, int $userid, array $responsedata): int {
        $latencyms = $responsedata['latency_ms'] ?? null;
        $success = $responsedata['success'] ?? true;

        $eventdata = array_merge([
            'action' => 'submit',
            'success' => $success,
        ], $responsedata);

        $logid = $this->log_event(
            $sessionid,
            $userid,
            self::EVENT_RESPONSE_SUBMIT,
            $eventdata,
            $latencyms
        );

        // Check for performance warning.
        if ($latencyms !== null && $latencyms > self::LATENCY_WARNING_THRESHOLD_MS) {
            $this->log_performance_warning($sessionid, [
                'type' => 'high_latency',
                'latency_ms' => $latencyms,
                'threshold_ms' => self::LATENCY_WARNING_THRESHOLD_MS,
                'response_log_id' => $logid,
            ]);
        }

        return $logid;
    }

    /**
     * Log a connection error
     *
     * Requirement 7.3: Log connection errors with context information for debugging.
     *
     * @param int $sessionid Session ID
     * @param int|null $userid User ID (may be null for anonymous errors)
     * @param string $errormessage Error message
     * @param array $context Additional context information
     * @return int Log entry ID
     */
    public function log_connection_error(int $sessionid, ?int $userid, string $errormessage, array $context = []): int {
        return $this->log_event(
            $sessionid,
            $userid,
            self::EVENT_CONNECTION_ERROR,
            array_merge([
                'error_message' => $errormessage,
                'error_time' => time(),
            ], $context)
        );
    }

    /**
     * Log a performance warning
     *
     * Requirement 7.4: Log warning events with resource utilization metrics.
     *
     * @param int $sessionid Session ID
     * @param array $metrics Performance metrics
     * @return int Log entry ID
     */
    public function log_performance_warning(int $sessionid, array $metrics): int {
        // Add system metrics if available.
        $systemmetrics = $this->get_system_metrics();

        return $this->log_event(
            $sessionid,
            null,
            self::EVENT_PERFORMANCE_WARNING,
            array_merge($metrics, $systemmetrics)
        );
    }

    /**
     * Get session statistics
     *
     * Requirement 7.5: Provide session statistics including average latency, error rate, and throughput.
     *
     * @param int $sessionid Session ID
     * @param int $timewindow Time window in seconds (default 5 minutes)
     * @return session_statistics
     */
    public function get_session_statistics(int $sessionid, int $timewindow = self::DEFAULT_STATS_WINDOW): session_statistics {
        global $DB;

        $cutofftime = time() - $timewindow;

        // Get response statistics.
        $sql = "SELECT
                    COUNT(*) as total_responses,
                    AVG(latency_ms) as avg_latency,
                    MIN(timecreated) as first_response,
                    MAX(timecreated) as last_response
                FROM {classengage_session_log}
                WHERE sessionid = :sessionid
                  AND event_type = :eventtype
                  AND timecreated >= :cutoff";

        $params = [
            'sessionid' => $sessionid,
            'eventtype' => self::EVENT_RESPONSE_SUBMIT,
            'cutoff' => $cutofftime,
        ];

        $responsestats = $DB->get_record_sql($sql, $params);

        // Get error count.
        $errorcount = $DB->count_records_select(
            'classengage_session_log',
            'sessionid = :sessionid AND event_type = :eventtype AND timecreated >= :cutoff',
            [
                'sessionid' => $sessionid,
                'eventtype' => self::EVENT_CONNECTION_ERROR,
                'cutoff' => $cutofftime,
            ]
        );

        // Calculate metrics.
        $totalresponses = (int) ($responsestats->total_responses ?? 0);
        $averagelatency = (float) ($responsestats->avg_latency ?? 0.0);

        // Calculate error rate.
        $totalevents = $totalresponses + $errorcount;
        $errorrate = $totalevents > 0 ? ($errorcount / $totalevents) * 100 : 0.0;

        // Calculate throughput (responses per minute).
        $throughput = 0;
        if ($totalresponses > 0 && $responsestats->first_response && $responsestats->last_response) {
            $duration = max(1, $responsestats->last_response - $responsestats->first_response);
            $throughput = (int) (($totalresponses / $duration) * 60);
        }

        return new session_statistics(
            $sessionid,
            $averagelatency,
            $errorrate,
            $throughput,
            $totalresponses,
            $errorcount,
            $timewindow
        );
    }

    /**
     * Get recent log entries for a session
     *
     * @param int $sessionid Session ID
     * @param int $limit Maximum number of entries to return
     * @param string|null $eventtype Filter by event type (optional)
     * @return array Array of log entries
     */
    public function get_recent_logs(int $sessionid, int $limit = 100, ?string $eventtype = null): array {
        global $DB;

        $conditions = ['sessionid = :sessionid'];
        $params = ['sessionid' => $sessionid];

        if ($eventtype !== null) {
            $conditions[] = 'event_type = :eventtype';
            $params['eventtype'] = $eventtype;
        }

        $sql = "SELECT *
                FROM {classengage_session_log}
                WHERE " . implode(' AND ', $conditions) . "
                ORDER BY timecreated DESC";

        return $DB->get_records_sql($sql, $params, 0, $limit);
    }

    /**
     * Get error logs for a session
     *
     * @param int $sessionid Session ID
     * @param int $limit Maximum number of entries to return
     * @return array Array of error log entries
     */
    public function get_error_logs(int $sessionid, int $limit = 50): array {
        return $this->get_recent_logs($sessionid, $limit, self::EVENT_CONNECTION_ERROR);
    }

    /**
     * Get performance warning logs for a session
     *
     * @param int $sessionid Session ID
     * @param int $limit Maximum number of entries to return
     * @return array Array of performance warning log entries
     */
    public function get_performance_warnings(int $sessionid, int $limit = 50): array {
        return $this->get_recent_logs($sessionid, $limit, self::EVENT_PERFORMANCE_WARNING);
    }

    /**
     * Log an event to the session log table
     *
     * @param int $sessionid Session ID
     * @param int|null $userid User ID
     * @param string $eventtype Event type
     * @param array $eventdata Event data
     * @param int|null $latencyms Latency in milliseconds (optional)
     * @return int Log entry ID
     */
    protected function log_event(
        int $sessionid,
        ?int $userid,
        string $eventtype,
        array $eventdata = [],
        ?int $latencyms = null
    ): int {
        global $DB;

        $log = new \stdClass();
        $log->sessionid = $sessionid;
        $log->userid = $userid;
        $log->event_type = $eventtype;
        $log->event_data = json_encode($eventdata);
        $log->latency_ms = $latencyms;
        $log->timecreated = time();

        return $DB->insert_record('classengage_session_log', $log);
    }

    /**
     * Get system metrics for performance logging
     *
     * @return array System metrics
     */
    protected function get_system_metrics(): array {
        $metrics = [];

        // Get memory usage.
        $metrics['memory_usage_bytes'] = memory_get_usage(true);
        $metrics['memory_peak_bytes'] = memory_get_peak_usage(true);

        // Get load average if available (Unix systems).
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load !== false) {
                $metrics['load_1min'] = $load[0];
                $metrics['load_5min'] = $load[1];
                $metrics['load_15min'] = $load[2];
            }
        }

        return $metrics;
    }

    /**
     * Clean up old log entries
     *
     * @param int $sessionid Session ID
     * @param int $maxage Maximum age in seconds (default 7 days)
     * @return int Number of deleted entries
     */
    public function cleanup_old_logs(int $sessionid, int $maxage = 604800): int {
        global $DB;

        $cutofftime = time() - $maxage;

        return $DB->delete_records_select(
            'classengage_session_log',
            'sessionid = :sessionid AND timecreated < :cutoff',
            ['sessionid' => $sessionid, 'cutoff' => $cutofftime]
        );
    }
}
