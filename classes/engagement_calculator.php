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
 * Engagement calculator for ClassEngage analytics
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Engagement calculator class
 *
 * Calculates overall engagement level based on participation metrics.
 * Results are cached for 5 minutes to improve performance.
 */
class engagement_calculator {
    
    /** @var float High engagement threshold percentage */
    const HIGH_ENGAGEMENT_THRESHOLD = 75.0;
    
    /** @var float Moderate engagement threshold percentage */
    const MODERATE_ENGAGEMENT_THRESHOLD = 40.0;
    
    /** @var float Variance threshold multiplier for consistency detection */
    const VARIANCE_THRESHOLD_MULTIPLIER = 0.5;
    
    /** @var int Cache duration in seconds (5 minutes) */
    const CACHE_DURATION = 300;
    
    /** @var int Session ID */
    protected $sessionid;
    
    /** @var int Course ID */
    protected $courseid;
    
    /** @var \cache_application Cache instance */
    protected $cache;
    
    /**
     * Constructor
     *
     * @param int $sessionid Session ID
     * @param int $courseid Course ID
     */
    public function __construct($sessionid, $courseid) {
        $this->sessionid = $sessionid;
        $this->courseid = $courseid;
        
        // Initialize cache for performance optimization.
        try {
            $this->cache = \cache::make('mod_classengage', 'response_stats');
        } catch (\Exception $e) {
            // If cache not configured, set to null and skip caching.
            $this->cache = null;
        }
    }
    
    /**
     * Calculate engagement level
     *
     * Formula: (Unique responding students / Enrolled students) * 100
     *
     * @return object Engagement level data {percentage, level, message, unique_participants, total_enrolled, cached_at}
     */
    public function calculate_engagement_level() {
        // Try to get from cache first.
        $cachekey = "engagement_level_{$this->sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        global $DB;
        
        // Get unique responding students.
        $sql = "SELECT COUNT(DISTINCT userid) as count
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid";
        
        $result = $DB->get_record_sql($sql, array('sessionid' => $this->sessionid));
        $uniqueparticipants = $result ? (int)$result->count : 0;
        
        // Get total enrolled students (optimized count).
        $totalenrolled = $this->get_enrolled_student_count();
        
        // Calculate percentage.
        $percentage = $totalenrolled > 0 ? ($uniqueparticipants / $totalenrolled) * 100 : 0;
        
        // Determine level and message.
        $leveldata = $this->determine_engagement_level($percentage);
        
        $engagement = new \stdClass();
        $engagement->percentage = round($percentage, 2);
        $engagement->level = $leveldata['level'];
        $engagement->message = $leveldata['message'];
        $engagement->unique_participants = $uniqueparticipants;
        $engagement->total_enrolled = $totalenrolled;
        $engagement->cached_at = time();
        
        // Cache for 5 minutes.
        if ($this->cache) {
            $this->cache->set($cachekey, $engagement);
        }
        
        return $engagement;
    }
    
    /**
     * Get activity counts
     *
     * @return object Activity counts {questions_answered, poll_submissions, reactions}
     */
    public function get_activity_counts() {
        // Try to get from cache first.
        $cachekey = "activity_counts_{$this->sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        global $DB;
        
        // Get total responses (questions answered).
        $questionsanswered = $DB->count_records('classengage_responses', array('sessionid' => $this->sessionid));
        
        // For now, polls and reactions are not implemented, so return 0.
        // These can be implemented when poll and reaction features are added.
        $pollsubmissions = 0;
        $reactions = 0;
        
        $counts = new \stdClass();
        $counts->questions_answered = $questionsanswered;
        $counts->poll_submissions = $pollsubmissions;
        $counts->reactions = $reactions;
        
        // Cache for 5 minutes.
        if ($this->cache) {
            $this->cache->set($cachekey, $counts);
        }
        
        return $counts;
    }
    
    /**
     * Get responsiveness indicator
     *
     * Compares average response time to session median.
     * Uses database aggregation for better performance with large datasets.
     *
     * @return object Responsiveness data {avg_time, median_time, pace, message, variance}
     */
    public function get_responsiveness_indicator() {
        // Try to get from cache first.
        $cachekey = "responsiveness_{$this->sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        global $DB;
        
        // Calculate statistics at database level for better performance.
        $sql = "SELECT 
                    AVG(responsetime) as avg_time,
                    COUNT(*) as count
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid
                   AND responsetime IS NOT NULL";
        
        $stats = $DB->get_record_sql($sql, array('sessionid' => $this->sessionid));
        
        if (!$stats || $stats->count == 0) {
            return $this->get_empty_responsiveness();
        }
        
        $avgtime = (float)$stats->avg_time;
        
        // Calculate median (requires fetching ordered data).
        $mediantime = $this->calculate_median_response_time();
        
        // Calculate standard deviation in PHP for database compatibility.
        $variance = $this->calculate_response_time_stddev($avgtime);
        
        // Determine pace based on average vs median.
        $pacedata = $this->determine_pace($avgtime, $mediantime, $variance);
        
        $responsiveness = new \stdClass();
        $responsiveness->avg_time = round($avgtime, 2);
        $responsiveness->median_time = round($mediantime, 2);
        $responsiveness->pace = $pacedata['pace'];
        $responsiveness->message = $pacedata['message'];
        $responsiveness->variance = round($variance, 2);
        
        // Cache for 5 minutes.
        if ($this->cache) {
            $this->cache->set($cachekey, $responsiveness);
        }
        
        return $responsiveness;
    }
    
    /**
     * Invalidate cache for this session
     *
     * Should be called when new responses are submitted.
     */
    public function invalidate_cache() {
        if (!$this->cache) {
            return;
        }
        
        // Invalidate all cache keys related to this session.
        $this->cache->delete("engagement_level_{$this->sessionid}");
        $this->cache->delete("activity_counts_{$this->sessionid}");
        $this->cache->delete("responsiveness_{$this->sessionid}");
    }
    
    /**
     * Get count of enrolled students
     *
     * Uses count_enrolled_users for better performance than get_enrolled_users.
     *
     * @return int Number of enrolled students
     */
    private function get_enrolled_student_count() {
        $context = \context_course::instance($this->courseid);
        
        // Use count_enrolled_users for better performance.
        $count = count_enrolled_users($context, 'mod/classengage:takequiz');
        
        return $count;
    }
    
    /**
     * Determine engagement level from percentage
     *
     * @param float $percentage Engagement percentage
     * @return array Array with 'level' and 'message' keys
     */
    private function determine_engagement_level($percentage) {
        if ($percentage > self::HIGH_ENGAGEMENT_THRESHOLD) {
            return [
                'level' => 'high',
                'message' => get_string('engagementhigh', 'mod_classengage', round($percentage, 1))
            ];
        } else if ($percentage >= self::MODERATE_ENGAGEMENT_THRESHOLD) {
            return [
                'level' => 'moderate',
                'message' => get_string('engagementmoderate', 'mod_classengage', round($percentage, 1))
            ];
        } else {
            return [
                'level' => 'low',
                'message' => get_string('engagementlow', 'mod_classengage', round($percentage, 1))
            ];
        }
    }
    
    /**
     * Calculate median response time
     *
     * @return float Median time
     */
    private function calculate_median_response_time() {
        global $DB;
        
        $sql = "SELECT responsetime
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid
                   AND responsetime IS NOT NULL
              ORDER BY responsetime";
        
        $times = $DB->get_fieldset_sql($sql, array('sessionid' => $this->sessionid));
        
        if (empty($times)) {
            return 0;
        }
        
        $count = count($times);
        $middle = floor($count / 2);
        
        if ($count % 2 == 0) {
            return ($times[$middle - 1] + $times[$middle]) / 2;
        } else {
            return $times[$middle];
        }
    }
    
    /**
     * Calculate standard deviation of response times
     *
     * @param float $avgtime Average response time
     * @return float Standard deviation
     */
    private function calculate_response_time_stddev($avgtime) {
        global $DB;
        
        $sql = "SELECT responsetime
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid
                   AND responsetime IS NOT NULL";
        
        $times = $DB->get_fieldset_sql($sql, array('sessionid' => $this->sessionid));
        
        if (empty($times)) {
            return 0;
        }
        
        $variance = 0;
        foreach ($times as $time) {
            $variance += pow((float)$time - $avgtime, 2);
        }
        
        return sqrt($variance / count($times));
    }
    
    /**
     * Determine pace and message from response time statistics
     *
     * @param float $avgtime Average response time
     * @param float $mediantime Median response time
     * @param float $variance Standard deviation
     * @return array Array with 'pace' and 'message' keys
     */
    private function determine_pace($avgtime, $mediantime, $variance) {
        // Determine pace based on average vs median.
        if ($avgtime < $mediantime) {
            $pace = 'quick';
            $message = get_string('responsivenessquick', 'mod_classengage');
        } else if ($avgtime > $mediantime) {
            $pace = 'slow';
            $message = get_string('responsivenessslow', 'mod_classengage');
        } else {
            $pace = 'normal';
            $message = get_string('responsivenessnormal', 'mod_classengage');
        }
        
        // Add variance indicator to message.
        if ($variance < ($avgtime * self::VARIANCE_THRESHOLD_MULTIPLIER)) {
            $message .= ' ' . get_string('consistentengagement', 'mod_classengage');
        } else {
            $message .= ' ' . get_string('fluctuatingengagement', 'mod_classengage');
        }
        
        return [
            'pace' => $pace,
            'message' => $message
        ];
    }
    
    /**
     * Get empty responsiveness object
     *
     * @return object Empty responsiveness data
     */
    private function get_empty_responsiveness() {
        $responsiveness = new \stdClass();
        $responsiveness->avg_time = 0;
        $responsiveness->median_time = 0;
        $responsiveness->pace = 'normal';
        $responsiveness->message = get_string('responsivenessnormal', 'mod_classengage');
        $responsiveness->variance = 0;
        return $responsiveness;
    }
}
