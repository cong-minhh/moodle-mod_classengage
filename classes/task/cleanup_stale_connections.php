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
 * Scheduled task to clean up stale connections
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\task;

use mod_classengage\constants;

defined('MOODLE_INTERNAL') || die();

/**
 * Cleanup stale connections task
 *
 * Removes connections that haven't been active within the timeout period.
 */
class cleanup_stale_connections extends \core\task\scheduled_task
{

    /**
     * Get task name
     *
     * @return string
     */
    public function get_name(): string
    {
        return get_string('task:cleanupstaleconnections', 'mod_classengage');
    }

    /**
     * Execute the task
     *
     * @return void
     */
    public function execute(): void
    {
        global $DB;

        $timeout = constants::CONNECTION_STALE_TIMEOUT;
        $cutoff = time() - $timeout;

        // Mark stale connections as disconnected.
        $sql = "UPDATE {classengage_connections}
                   SET status = 'disconnected',
                       timemodified = :now
                 WHERE status = 'connected'
                   AND timemodified < :cutoff";

        $params = [
            'now' => time(),
            'cutoff' => $cutoff,
        ];

        $updated = $DB->execute($sql, $params);

        // Delete very old connections (older than 24 hours).
        $olddatecutoff = time() - (24 * 60 * 60);
        $deleted = $DB->delete_records_select(
            'classengage_connections',
            'status = :status AND timemodified < :cutoff',
            ['status' => 'disconnected', 'cutoff' => $olddatecutoff]
        );

        mtrace("ClassEngage: Cleaned up stale connections (timeout: {$timeout}s)");
    }
}
