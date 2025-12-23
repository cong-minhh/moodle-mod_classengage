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
 * Archive old sessions scheduled task
 *
 * Cleans up completed sessions older than the configured retention period
 * to reduce database size and improve query performance.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Archive old sessions task
 */
class archive_old_sessions extends \core\task\scheduled_task
{

    /**
     * Get task name
     *
     * @return string
     */
    public function get_name()
    {
        return get_string('task:archiveoldsessions', 'mod_classengage');
    }

    /**
     * Execute the task
     *
     * Archives completed sessions older than the log retention period.
     * This removes session logs and analytics cache entries while preserving
     * the core session and response data for historical reporting.
     */
    public function execute()
    {
        global $DB;

        $retentiondays = get_config('mod_classengage', 'log_retention_days');
        if (empty($retentiondays)) {
            $retentiondays = 90; // Default 90 days.
        }

        $cutofftime = time() - ($retentiondays * 24 * 60 * 60);

        mtrace("Archiving sessions completed before " . userdate($cutofftime));

        // Get old completed sessions.
        $oldsessions = $DB->get_records_select(
            'classengage_sessions',
            'status = :status AND timecompleted < :cutoff',
            ['status' => 'completed', 'cutoff' => $cutofftime],
            '',
            'id'
        );

        if (empty($oldsessions)) {
            mtrace("No old sessions to archive.");
            return;
        }

        $sessionids = array_keys($oldsessions);
        $count = count($sessionids);

        // Delete session logs for old sessions.
        list($insql, $params) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('classengage_session_log', "sessionid $insql", $params);
        mtrace("Deleted session logs for $count sessions.");

        // Delete analytics cache for old sessions.
        if ($DB->get_manager()->table_exists('classengage_analytics_cache')) {
            $DB->delete_records_select('classengage_analytics_cache', "sessionid $insql", $params);
            mtrace("Deleted analytics cache for $count sessions.");
        }

        // Delete response queue entries for old sessions.
        $DB->delete_records_select('classengage_response_queue', "sessionid $insql", $params);
        mtrace("Deleted response queue entries for $count sessions.");

        // Delete connection records for old sessions.
        $DB->delete_records_select('classengage_connections', "sessionid $insql", $params);
        mtrace("Deleted connection records for $count sessions.");

        mtrace("Archive completed: processed $count sessions.");
    }
}
