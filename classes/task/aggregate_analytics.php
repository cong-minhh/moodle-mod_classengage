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
 * Scheduled task to aggregate analytics data
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Aggregate analytics task
 *
 * Pre-computes analytics summaries for completed sessions.
 */
class aggregate_analytics extends \core\task\scheduled_task {

    /**
     * Get task name
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:aggregateanalytics', 'mod_classengage');
    }

    /**
     * Execute the task
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        // Get recently completed sessions that might need analytics aggregation.
        $onehourago = time() - 3600;

        $sessions = $DB->get_records_select(
            'classengage_sessions',
            'status = :status AND timecompleted > :cutoff',
            ['status' => 'completed', 'cutoff' => $onehourago],
            'timecompleted DESC',
            'id, classengageid, name',
            0,
            50
        );

        if (empty($sessions)) {
            return;
        }

        $cache = \cache::make('mod_classengage', 'analytics_summary');
        $count = 0;

        foreach ($sessions as $session) {
            $cachekey = "session_summary_{$session->id}";

            // Skip if already cached.
            if ($cache->get($cachekey) !== false) {
                continue;
            }

            // Compute and cache basic analytics.
            $summary = $this->compute_session_summary($session->id);
            $cache->set($cachekey, $summary);
            $count++;
        }

        if ($count > 0) {
            mtrace("ClassEngage: Aggregated analytics for {$count} sessions");
        }
    }

    /**
     * Compute session summary statistics
     *
     * @param int $sessionid Session ID
     * @return object Summary object
     */
    private function compute_session_summary(int $sessionid): object {
        global $DB;

        $sql = "SELECT
                    COUNT(DISTINCT r.userid) as total_participants,
                    COUNT(r.id) as total_responses,
                    AVG(CASE WHEN r.iscorrect = 1 THEN 100 ELSE 0 END) as avg_score,
                    AVG(r.responsetime) as avg_response_time
                FROM {classengage_responses} r
                WHERE r.sessionid = :sessionid";

        $stats = $DB->get_record_sql($sql, ['sessionid' => $sessionid]);

        return (object) [
            'total_participants' => (int) ($stats->total_participants ?? 0),
            'total_responses' => (int) ($stats->total_responses ?? 0),
            'avg_score' => round($stats->avg_score ?? 0, 1),
            'avg_response_time' => round($stats->avg_response_time ?? 0, 1),
            'computed_at' => time(),
        ];
    }
}
