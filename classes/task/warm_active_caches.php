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
 * Warm active caches scheduled task
 *
 * Pre-computes analytics data for active sessions to improve
 * dashboard responsiveness during live quiz sessions.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Warm active caches task
 */
class warm_active_caches extends \core\task\scheduled_task
{

    /**
     * Get task name
     *
     * @return string
     */
    public function get_name()
    {
        return get_string('task:warmactivecaches', 'mod_classengage');
    }

    /**
     * Execute the task
     *
     * Pre-computes analytics for active sessions and stores them
     * in the analytics cache table for faster dashboard access.
     */
    public function execute()
    {
        global $DB;

        mtrace("Warming caches for active sessions...");

        // Get all active sessions.
        $activesessions = $DB->get_records('classengage_sessions', ['status' => 'active']);

        if (empty($activesessions)) {
            mtrace("No active sessions to warm caches for.");
            return;
        }

        $count = 0;
        $now = time();
        $cachettl = 300; // 5 minutes cache TTL.

        foreach ($activesessions as $session) {
            try {
                // Get the activity for this session.
                $classengage = $DB->get_record('classengage', ['id' => $session->classengageid]);
                if (!$classengage) {
                    continue;
                }

                // Pre-compute session summary.
                $summary = $this->compute_session_summary($session);
                $this->cache_metric($session->id, 'summary', 'session_overview', $summary, $now, $cachettl);

                // Pre-compute current question stats.
                $questionstats = $this->compute_question_stats($session);
                $this->cache_metric($session->id, 'performance', 'current_question', $questionstats, $now, $cachettl);

                $count++;
            } catch (\Exception $e) {
                mtrace("Error warming cache for session {$session->id}: " . $e->getMessage());
            }
        }

        mtrace("Warmed caches for $count active sessions.");
    }

    /**
     * Compute session summary statistics
     *
     * @param object $session Session record
     * @return array Summary data
     */
    private function compute_session_summary($session)
    {
        global $DB;

        $responses = $DB->count_records('classengage_responses', ['sessionid' => $session->id]);
        $participants = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT userid) FROM {classengage_responses} WHERE sessionid = ?",
            [$session->id]
        );

        $correctcount = $DB->count_records('classengage_responses', [
            'sessionid' => $session->id,
            'iscorrect' => 1
        ]);

        $accuracy = $responses > 0 ? round(($correctcount / $responses) * 100, 1) : 0;

        return [
            'total_responses' => $responses,
            'participants' => $participants,
            'correct_count' => $correctcount,
            'accuracy' => $accuracy,
            'current_question' => $session->currentquestion,
            'total_questions' => $session->numquestions,
        ];
    }

    /**
     * Compute current question statistics
     *
     * @param object $session Session record
     * @return array Question stats
     */
    private function compute_question_stats($session)
    {
        global $DB;

        // Get the current question ID.
        $questionmap = $DB->get_record('classengage_session_questions', [
            'sessionid' => $session->id,
            'questionorder' => $session->currentquestion + 1, // 0-indexed to 1-indexed
        ]);

        if (!$questionmap) {
            return ['distribution' => [], 'total' => 0];
        }

        $questionid = $questionmap->questionid;

        // Count responses by answer.
        $sql = "SELECT answer, COUNT(*) as count
                FROM {classengage_responses}
                WHERE sessionid = ? AND questionid = ?
                GROUP BY answer";
        $distribution = $DB->get_records_sql_menu($sql, [$session->id, $questionid]);

        return [
            'questionid' => $questionid,
            'distribution' => $distribution ?: [],
            'total' => array_sum($distribution ?: []),
        ];
    }

    /**
     * Cache a metric to the analytics cache table
     *
     * @param int $sessionid Session ID
     * @param string $metrictype Metric type
     * @param string $metrickey Metric key
     * @param mixed $value Metric value
     * @param int $now Current timestamp
     * @param int $ttl TTL in seconds
     */
    private function cache_metric($sessionid, $metrictype, $metrickey, $value, $now, $ttl)
    {
        global $DB;

        // Check if analytics cache table exists.
        if (!$DB->get_manager()->table_exists('classengage_analytics_cache')) {
            return;
        }

        $record = new \stdClass();
        $record->sessionid = $sessionid;
        $record->metric_type = $metrictype;
        $record->metric_key = $metrickey;
        $record->metric_value = json_encode($value);
        $record->computed_at = $now;
        $record->expires_at = $now + $ttl;

        // Upsert: try update first, then insert.
        $existing = $DB->get_record('classengage_analytics_cache', [
            'sessionid' => $sessionid,
            'metric_type' => $metrictype,
            'metric_key' => $metrickey,
        ]);

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('classengage_analytics_cache', $record);
        } else {
            $DB->insert_record('classengage_analytics_cache', $record);
        }
    }
}
