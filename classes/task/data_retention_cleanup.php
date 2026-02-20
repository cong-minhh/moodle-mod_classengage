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
 * Data retention cleanup task for GDPR compliance.
 *
 * This scheduled task automatically cleans up old data based on retention settings:
 * - Quiz responses older than retention period
 * - Session logs older than retention period
 * - Stale connections
 * - Expired analytics cache entries
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Data retention cleanup task.
 */
class data_retention_cleanup extends \core\task\scheduled_task {

    /**
     * Get the name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:dataretentioncleanup', 'mod_classengage');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        // Check if auto cleanup is enabled
        $enabled = get_config('mod_classengage', 'enable_auto_cleanup');
        if (!$enabled) {
            mtrace('Data retention cleanup is disabled. Skipping.');
            return;
        }

        $retentiondays = (int)get_config('mod_classengage', 'response_retention_days');
        $sessionlogdays = (int)get_config('mod_classengage', 'session_log_retention_days');
        $connectionhours = (int)get_config('mod_classengage', 'connection_retention_hours');

        $cutofftime = time() - ($retentiondays * DAYSECS);
        $logcutofftime = time() - ($sessionlogdays * DAYSECS);
        $connectioncutoff = time() - ($connectionhours * HOURSECS);

        $total_deleted = 0;

        // 1. Delete old responses
        mtrace("Deleting responses older than {$retentiondays} days...");
        $deleted = $DB->delete_records_select(
            'classengage_responses',
            'timecreated < ?',
            [$cutofftime]
        );
        mtrace("Deleted {$deleted} old responses.");
        $total_deleted += $deleted;

        // 2. Delete old session logs
        mtrace("Deleting session logs older than {$sessionlogdays} days...");
        $deleted = $DB->delete_records_select(
            'classengage_session_log',
            'timecreated < ?',
            [$logcutofftime]
        );
        mtrace("Deleted {$deleted} old session logs.");
        $total_deleted += $deleted;

        // 3. Delete stale connections
        mtrace("Deleting stale connections older than {$connectionhours} hours...");
        $deleted = $DB->delete_records_select(
            'classengage_connections',
            'timemodified < ?',
            [$connectioncutoff]
        );
        mtrace("Deleted {$deleted} stale connections.");
        $total_deleted += $deleted;

        // 4. Delete expired analytics cache
        mtrace("Deleting expired analytics cache entries...");
        $now = time();
        $deleted = $DB->delete_records_select(
            'classengage_analytics_cache',
            'expires_at > 0 AND expires_at < ?',
            [$now]
        );
        mtrace("Deleted {$deleted} expired cache entries.");
        $total_deleted += $deleted;

        // 5. Delete processed queue entries older than 7 days
        mtrace("Deleting old processed queue entries...");
        $queuecutoff = time() - (7 * DAYSECS);
        $deleted = $DB->delete_records_select(
            'classengage_response_queue',
            'processed = 1 AND server_timestamp < ?',
            [$queuecutoff]
        );
        mtrace("Deleted {$deleted} old queue entries.");
        $total_deleted += $deleted;

        // 6. Clean up orphaned connections (sessions that don't exist)
        mtrace("Cleaning up orphaned connections...");
        $deleted = $DB->delete_records_select(
            'classengage_connections',
            "sessionid NOT IN (SELECT id FROM {classengage_sessions})"
        );
        mtrace("Deleted {$deleted} orphaned connections.");
        $total_deleted += $deleted;

        mtrace("Data retention cleanup complete. Total records deleted: {$total_deleted}");
    }
}
