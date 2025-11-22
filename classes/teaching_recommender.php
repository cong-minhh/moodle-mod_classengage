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
 * Teaching recommender for ClassEngage analytics
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Teaching recommender class
 *
 * Generates supportive, actionable teaching recommendations based on
 * engagement and comprehension data.
 * Results are cached for 5 minutes to improve performance.
 */
class teaching_recommender {
    
    /** @var int Maximum number of recommendations to generate */
    const MAX_RECOMMENDATIONS = 5;
    
    /** @var float Low engagement threshold for pacing recommendation */
    const LOW_ENGAGEMENT_THRESHOLD = 40.0;
    
    /** @var float High engagement threshold for interactive activities */
    const HIGH_ENGAGEMENT_THRESHOLD = 75.0;
    
    /** @var float Low comprehension threshold for additional examples */
    const LOW_COMPREHENSION_THRESHOLD = 50.0;
    
    /** @var float Engagement drop threshold percentage */
    const ENGAGEMENT_DROP_THRESHOLD = 30.0;
    
    /** @var int Cache duration in seconds (5 minutes) */
    const CACHE_DURATION = 300;
    
    /** @var int Session ID */
    protected $sessionid;
    
    /** @var object Engagement data */
    protected $engagement;
    
    /** @var object Comprehension data */
    protected $comprehension;
    
    /** @var \cache_application Cache instance */
    protected $cache;
    
    /**
     * Constructor
     *
     * @param int $sessionid Session ID
     * @param object $engagement Engagement data from engagement_calculator
     * @param object $comprehension Comprehension data from comprehension_analyzer
     */
    public function __construct($sessionid, $engagement, $comprehension) {
        $this->sessionid = $sessionid;
        $this->engagement = $engagement;
        $this->comprehension = $comprehension;
        
        // Initialize cache for performance optimization.
        try {
            $this->cache = \cache::make('mod_classengage', 'response_stats');
        } catch (\Exception $e) {
            // If cache not configured, set to null and skip caching.
            $this->cache = null;
        }
    }
    
    /**
     * Generate teaching recommendations
     *
     * Analyzes engagement and comprehension data to provide supportive,
     * actionable suggestions for improving teaching effectiveness.
     *
     * @return array Array of recommendation objects, max 5, prioritized by impact
     */
    public function generate_recommendations() {
        // Try to get from cache first.
        $cachekey = "recommendations_{$this->sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $recommendations = array();
        
        // Priority 1: Low engagement + low comprehension.
        if ($this->engagement->percentage < self::LOW_ENGAGEMENT_THRESHOLD &&
            $this->comprehension->avg_correctness < self::LOW_COMPREHENSION_THRESHOLD) {
            $recommendations[] = $this->create_recommendation(
                1,
                'pacing',
                get_string('recommendationpacing', 'mod_classengage'),
                get_string('engagementlow', 'mod_classengage', round($this->engagement->percentage, 1)) . ', ' .
                get_string('comprehensionweak', 'mod_classengage')
            );
        }
        
        // Priority 2: High engagement + low comprehension.
        if ($this->engagement->percentage >= self::HIGH_ENGAGEMENT_THRESHOLD &&
            $this->comprehension->avg_correctness < self::LOW_COMPREHENSION_THRESHOLD) {
            $recommendations[] = $this->create_recommendation(
                2,
                'comprehension',
                'Students are engaged but struggling - try alternative explanations',
                get_string('engagementhigh', 'mod_classengage', round($this->engagement->percentage, 1)) . ', ' .
                'but ' . get_string('comprehensionweak', 'mod_classengage')
            );
        }
        
        // Priority 3: High engagement with good comprehension - highlight interactive activities.
        if ($this->engagement->percentage >= self::HIGH_ENGAGEMENT_THRESHOLD &&
            $this->comprehension->avg_correctness >= self::LOW_COMPREHENSION_THRESHOLD) {
            $recommendations[] = $this->create_recommendation(
                3,
                'engagement',
                get_string('recommendationengagement', 'mod_classengage'),
                get_string('engagementhigh', 'mod_classengage', round($this->engagement->percentage, 1)) . ', ' .
                get_string('comprehensionstrong', 'mod_classengage')
            );
        }
        
        // Priority 4: Specific difficult concepts.
        if (!empty($this->comprehension->confused_topics)) {
            foreach ($this->comprehension->confused_topics as $topic) {
                if (count($recommendations) >= self::MAX_RECOMMENDATIONS) {
                    break;
                }
                
                $recommendations[] = $this->create_recommendation(
                    4,
                    'comprehension',
                    get_string('recommendationexamples', 'mod_classengage', $topic),
                    get_string('confusedtopics', 'mod_classengage', $topic)
                );
            }
        }
        
        // Priority 5: Engagement drops in timeline (early intervals).
        $timelinedrops = $this->detect_engagement_drops();
        if (!empty($timelinedrops)) {
            // Check if drop occurred in early intervals (first 25% of session).
            $earlydrops = array_filter($timelinedrops, function($drop) {
                // Extract minute number from time string.
                preg_match('/\d+/', $drop['time'], $matches);
                $minute = isset($matches[0]) ? (int)$matches[0] : 0;
                return $minute <= 5; // Consider first 5 minutes as "early".
            });
            
            if (!empty($earlydrops)) {
                $recommendations[] = $this->create_recommendation(
                    5,
                    'pacing',
                    get_string('recommendationpacing', 'mod_classengage'),
                    'Attention waned around ' . $earlydrops[0]['time']
                );
            }
        }
        
        // Priority 6: Quiet periods detected.
        if ($this->has_quiet_periods()) {
            $recommendations[] = $this->create_recommendation(
                6,
                'interaction',
                get_string('recommendationprompts', 'mod_classengage'),
                'Some quiet periods detected during session'
            );
        }
        
        // Sort by priority and limit to max recommendations.
        usort($recommendations, function($a, $b) {
            return $a->priority - $b->priority;
        });
        
        $result = array_slice($recommendations, 0, self::MAX_RECOMMENDATIONS);
        
        // Cache for 5 minutes.
        if ($this->cache) {
            $this->cache->set($cachekey, $result);
        }
        
        return $result;
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
        $this->cache->delete("recommendations_{$this->sessionid}");
    }
    
    /**
     * Create a recommendation object
     *
     * @param int $priority Priority level (1-5, 1 is highest)
     * @param string $category Category ('pacing', 'engagement', 'comprehension', 'interaction')
     * @param string $message Supportive recommendation text
     * @param string $evidence Data supporting the recommendation
     * @return object Recommendation object
     */
    private function create_recommendation($priority, $category, $message, $evidence) {
        $recommendation = new \stdClass();
        $recommendation->priority = $priority;
        $recommendation->category = $category;
        $recommendation->message = $message;
        $recommendation->evidence = $evidence;
        
        return $recommendation;
    }
    
    /**
     * Detect engagement drops in timeline
     *
     * Identifies time intervals where engagement dropped significantly.
     *
     * @return array Array of drop points with time and magnitude
     */
    private function detect_engagement_drops() {
        global $DB;
        
        // Get response counts over time intervals.
        $sql = "SELECT 
                    FLOOR((timecreated - (SELECT MIN(timecreated) FROM {classengage_responses} 
                                          WHERE sessionid = :sessionid2)) / 60) as interval_minute,
                    COUNT(*) as response_count
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid
              GROUP BY interval_minute
              ORDER BY interval_minute";
        
        $intervals = $DB->get_records_sql($sql, array(
            'sessionid' => $this->sessionid,
            'sessionid2' => $this->sessionid
        ));
        
        if (count($intervals) < 3) {
            return array(); // Not enough data to detect drops.
        }
        
        $drops = array();
        $intervalarray = array_values($intervals);
        
        // Look for drops > 30% from previous interval.
        for ($i = 1; $i < count($intervalarray); $i++) {
            $previous = (int)$intervalarray[$i - 1]->response_count;
            $current = (int)$intervalarray[$i]->response_count;
            
            if ($previous > 0) {
                $droppercentage = (($previous - $current) / $previous) * 100;
                
                if ($droppercentage > self::ENGAGEMENT_DROP_THRESHOLD) {
                    $drops[] = array(
                        'time' => 'minute ' . $intervalarray[$i]->interval_minute,
                        'drop_percentage' => round($droppercentage, 1)
                    );
                }
            }
        }
        
        return $drops;
    }
    
    /**
     * Check if session has quiet periods
     *
     * Detects time intervals with zero or very low responses.
     *
     * @return bool True if quiet periods detected
     */
    private function has_quiet_periods() {
        global $DB;
        
        // Check if there are gaps in response times > 2 minutes.
        $sql = "SELECT 
                    timecreated,
                    LAG(timecreated) OVER (ORDER BY timecreated) as prev_time
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid
              ORDER BY timecreated";
        
        try {
            $times = $DB->get_records_sql($sql, array('sessionid' => $this->sessionid));
            
            foreach ($times as $time) {
                if ($time->prev_time !== null) {
                    $gap = $time->timecreated - $time->prev_time;
                    if ($gap > 120) { // 2 minutes gap.
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            // LAG function may not be supported in all databases.
            // Fall back to simpler check.
            return $this->has_quiet_periods_fallback();
        }
        
        return false;
    }
    
    /**
     * Fallback method to detect quiet periods (database-agnostic)
     *
     * @return bool True if quiet periods detected
     */
    private function has_quiet_periods_fallback() {
        global $DB;
        
        $sql = "SELECT timecreated
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid
              ORDER BY timecreated";
        
        $times = $DB->get_fieldset_sql($sql, array('sessionid' => $this->sessionid));
        
        if (count($times) < 2) {
            return false;
        }
        
        for ($i = 1; $i < count($times); $i++) {
            $gap = $times[$i] - $times[$i - 1];
            if ($gap > 120) { // 2 minutes gap.
                return true;
            }
        }
        
        return false;
    }
}
