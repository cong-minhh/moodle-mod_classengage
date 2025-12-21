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
 * Scheduled task to clean up old session logs
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Cleanup session logs task
 *
 * Removes old session log entries to prevent database bloat.
 */
class cleanup_session_logs extends \core\task\scheduled_task {

    /** @var int Default retention period in days */
    const DEFAULT_RETENTION_DAYS = 90;

    /**
     * Get task name
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:cleanupsessionlogs', 'mod_classengage');
    }

    /**
     * Execute the task
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        // Get retention period from config or use default.
        $retentiondays = get_config('mod_classengage', 'log_retention_days');
        if (empty($retentiondays)) {
            $retentiondays = self::DEFAULT_RETENTION_DAYS;
        }

        $cutoff = time() - ($retentiondays * 24 * 60 * 60);

        // Delete old log entries.
        $deleted = $DB->delete_records_select(
            'classengage_session_log',
            'timecreated < :cutoff',
            ['cutoff' => $cutoff]
        );

        if ($deleted > 0) {
            mtrace("ClassEngage: Deleted {$deleted} old session log entries (retention: {$retentiondays} days)");
        }

        // Also clean up orphaned queue entries.
        $oldqueuecutoff = time() - (7 * 24 * 60 * 60); // 7 days.
        $queuedeleted = $DB->delete_records_select(
            'classengage_response_queue',
            'processed = 1 AND server_timestamp < :cutoff',
            ['cutoff' => $oldqueuecutoff]
        );

        if ($queuedeleted > 0) {
            mtrace("ClassEngage: Deleted {$queuedeleted} processed queue entries");
        }
    }
}
