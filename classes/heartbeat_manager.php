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
 * Heartbeat manager for real-time quiz sessions
 *
 * Handles heartbeat processing, stale connection detection, and connection statistics.
 * Updates connection status within 5 seconds for disconnects and 2 seconds for reconnects.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Heartbeat response class
 */
class heartbeat_response {
    /** @var bool Whether the heartbeat was processed successfully */
    public bool $success;

    /** @var string|null Error message if processing failed */
    public ?string $error;

    /** @var int Server timestamp */
    public int $servertimestamp;

    /** @var string Connection status after heartbeat */
    public string $status;

    /**
     * Constructor
     *
     * @param bool $success
     * @param string|null $error
     * @param int $servertimestamp
     * @param string $status
     */
    public function __construct(
        bool $success,
        ?string $error = null,
        int $servertimestamp = 0,
        string $status = 'connected'
    ) {
        $this->success = $success;
        $this->error = $error;
        $this->servertimestamp = $servertimestamp ?: time();
        $this->status = $status;
    }
}


/**
 * Connection statistics class
 */
class connection_stats {
    /** @var int Total number of connections */
    public int $totalconnections;

    /** @var int Number of active (connected) connections */
    public int $activeconnections;

    /** @var int Number of disconnected connections */
    public int $disconnectedconnections;

    /** @var int Number of stale connections (heartbeat timeout exceeded) */
    public int $staleconnections;

    /** @var float Average latency in milliseconds */
    public float $averagelatency;

    /** @var int Timestamp of statistics calculation */
    public int $timestamp;

    /**
     * Constructor
     *
     * @param int $totalconnections
     * @param int $activeconnections
     * @param int $disconnectedconnections
     * @param int $staleconnections
     * @param float $averagelatency
     */
    public function __construct(
        int $totalconnections = 0,
        int $activeconnections = 0,
        int $disconnectedconnections = 0,
        int $staleconnections = 0,
        float $averagelatency = 0.0
    ) {
        $this->totalconnections = $totalconnections;
        $this->activeconnections = $activeconnections;
        $this->disconnectedconnections = $disconnectedconnections;
        $this->staleconnections = $staleconnections;
        $this->averagelatency = $averagelatency;
        $this->timestamp = time();
    }
}

/**
 * Heartbeat manager class
 *
 * Handles heartbeat processing, stale connection detection, and connection monitoring.
 * Implements Requirements 5.2, 5.3, 7.5 for connection status tracking and statistics.
 */
class heartbeat_manager {

    /** @var int Heartbeat timeout in seconds for marking connections as stale */
    const HEARTBEAT_TIMEOUT = 10;

    /** @var int Reconnection detection window in seconds */
    const RECONNECT_WINDOW = 2;

    /** @var \cache|null Cache for connection status */
    protected $connectioncache;

    /**
     * Constructor
     */
    public function __construct() {
        try {
            $this->connectioncache = \cache::make('mod_classengage', 'connection_status');
        } catch (\Exception $e) {
            $this->connectioncache = null;
        }
    }

    /**
     * Process heartbeat from client
     *
     * Updates the last_heartbeat timestamp and connection status.
     * Requirement 5.3: Status updates within 2 seconds for reconnects.
     *
     * @param int $sessionid Session ID
     * @param int $userid User ID
     * @param string $connectionid Unique connection identifier
     * @return heartbeat_response
     */
    public function process_heartbeat(int $sessionid, int $userid, string $connectionid): heartbeat_response {
        global $DB;

        $now = time();

        // Find the connection record.
        $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);

        if (!$connection) {
            return new heartbeat_response(false, 'Connection not found', $now, 'disconnected');
        }

        // Verify session and user match.
        if ($connection->sessionid != $sessionid || $connection->userid != $userid) {
            return new heartbeat_response(false, 'Connection mismatch', $now, 'disconnected');
        }

        // Determine if this is a reconnection (was disconnected or stale).
        $wasdisconnected = ($connection->status === 'disconnected');
        $wasstale = ($now - $connection->last_heartbeat) > self::HEARTBEAT_TIMEOUT;

        // Update the connection record.
        $connection->last_heartbeat = $now;
        $connection->status = 'connected';
        $connection->timemodified = $now;

        $DB->update_record('classengage_connections', $connection);

        // Update cache.
        if ($this->connectioncache) {
            $this->connectioncache->set("conn_{$connectionid}", [
                'sessionid' => $sessionid,
                'userid' => $userid,
                'status' => 'connected',
                'last_heartbeat' => $now,
            ]);
        }

        // Log reconnection event if applicable.
        if ($wasdisconnected || $wasstale) {
            $this->log_event($sessionid, $userid, 'heartbeat_reconnect', [
                'connectionid' => $connectionid,
                'was_disconnected' => $wasdisconnected,
                'was_stale' => $wasstale,
            ]);
        }

        return new heartbeat_response(true, null, $now, 'connected');
    }

    /**
     * Check for stale connections and mark as disconnected
     *
     * Identifies connections that haven't sent a heartbeat within the timeout period
     * and marks them as disconnected. Requirement 5.2: Status updates within 5 seconds.
     *
     * @param int $sessionid Session ID
     * @return array List of disconnected user IDs
     */
    public function check_stale_connections(int $sessionid): array {
        global $DB;

        $now = time();
        $cutofftime = $now - self::HEARTBEAT_TIMEOUT;

        // Find stale connections that are still marked as connected.
        $sql = "SELECT id, userid, connectionid, last_heartbeat
                  FROM {classengage_connections}
                 WHERE sessionid = :sessionid
                   AND status = :status
                   AND last_heartbeat < :cutoff";

        $params = [
            'sessionid' => $sessionid,
            'status' => 'connected',
            'cutoff' => $cutofftime,
        ];

        $staleconnections = $DB->get_records_sql($sql, $params);

        $disconnecteduserids = [];

        foreach ($staleconnections as $connection) {
            // Update status to disconnected.
            $DB->update_record('classengage_connections', (object)[
                'id' => $connection->id,
                'status' => 'disconnected',
                'timemodified' => $now,
            ]);

            // Remove from cache.
            if ($this->connectioncache) {
                $this->connectioncache->delete("conn_{$connection->connectionid}");
            }

            $disconnecteduserids[] = $connection->userid;

            // Log the disconnection event.
            $this->log_event($sessionid, $connection->userid, 'heartbeat_timeout', [
                'connectionid' => $connection->connectionid,
                'last_heartbeat' => $connection->last_heartbeat ?? 0,
                'timeout_seconds' => self::HEARTBEAT_TIMEOUT,
            ]);
        }

        return $disconnecteduserids;
    }

    /**
     * Get connection statistics for monitoring
     *
     * Returns aggregate statistics about connections for a session.
     * Requirement 7.5: Provide session statistics including throughput.
     *
     * @param int $sessionid Session ID
     * @return connection_stats
     */
    public function get_connection_stats(int $sessionid): connection_stats {
        global $DB;

        $now = time();
        $cutofftime = $now - self::HEARTBEAT_TIMEOUT;

        // Get total connections.
        $totalconnections = $DB->count_records('classengage_connections', ['sessionid' => $sessionid]);

        // Get active connections (connected and not stale).
        $sql = "SELECT COUNT(*)
                  FROM {classengage_connections}
                 WHERE sessionid = :sessionid
                   AND status = :status
                   AND last_heartbeat >= :cutoff";

        $params = [
            'sessionid' => $sessionid,
            'status' => 'connected',
            'cutoff' => $cutofftime,
        ];

        $activeconnections = $DB->count_records_sql($sql, $params);

        // Get disconnected connections.
        $disconnectedconnections = $DB->count_records('classengage_connections', [
            'sessionid' => $sessionid,
            'status' => 'disconnected',
        ]);

        // Get stale connections (connected but heartbeat timed out).
        $sql = "SELECT COUNT(*)
                  FROM {classengage_connections}
                 WHERE sessionid = :sessionid
                   AND status = :status
                   AND last_heartbeat < :cutoff";

        $staleconnections = $DB->count_records_sql($sql, $params);

        // Calculate average latency from recent session logs.
        $averagelatency = $this->calculate_average_latency($sessionid);

        return new connection_stats(
            $totalconnections,
            $activeconnections,
            $disconnectedconnections,
            $staleconnections,
            $averagelatency
        );
    }

    /**
     * Calculate average latency from recent response logs
     *
     * @param int $sessionid Session ID
     * @return float Average latency in milliseconds
     */
    protected function calculate_average_latency(int $sessionid): float {
        global $DB;

        // Get average latency from recent logs (last 5 minutes).
        $cutofftime = time() - 300;

        $sql = "SELECT AVG(latency_ms) as avglatency
                  FROM {classengage_session_log}
                 WHERE sessionid = :sessionid
                   AND latency_ms IS NOT NULL
                   AND timecreated >= :cutoff";

        $params = [
            'sessionid' => $sessionid,
            'cutoff' => $cutofftime,
        ];

        $result = $DB->get_record_sql($sql, $params);

        return $result && $result->avglatency ? (float) $result->avglatency : 0.0;
    }

    /**
     * Log a heartbeat event
     *
     * @param int $sessionid Session ID
     * @param int $userid User ID
     * @param string $eventtype Event type
     * @param array $eventdata Additional event data
     * @return void
     */
    protected function log_event(int $sessionid, int $userid, string $eventtype, array $eventdata = []): void {
        global $DB;

        $log = new \stdClass();
        $log->sessionid = $sessionid;
        $log->userid = $userid;
        $log->event_type = $eventtype;
        $log->event_data = json_encode($eventdata);
        $log->latency_ms = null;
        $log->timecreated = time();

        $DB->insert_record('classengage_session_log', $log);
    }

    /**
     * Check if a specific connection is stale
     *
     * @param string $connectionid Connection identifier
     * @return bool True if connection is stale
     */
    public function is_connection_stale(string $connectionid): bool {
        global $DB;

        $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);

        if (!$connection) {
            return true;
        }

        $now = time();
        return ($now - $connection->last_heartbeat) > self::HEARTBEAT_TIMEOUT;
    }

    /**
     * Get the heartbeat timeout value
     *
     * @return int Timeout in seconds
     */
    public function get_heartbeat_timeout(): int {
        return self::HEARTBEAT_TIMEOUT;
    }
}
