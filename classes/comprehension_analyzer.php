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
 * Comprehension analyzer for ClassEngage analytics
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Comprehension analyzer class
 *
 * Analyzes class-level understanding and identifies difficult concepts.
 * Results are cached for 5 minutes to improve performance.
 */
class comprehension_analyzer {
    
    /** @var float Strong comprehension threshold percentage */
    const STRONG_COMPREHENSION_THRESHOLD = 70.0;
    
    /** @var float Partial comprehension threshold percentage */
    const PARTIAL_COMPREHENSION_THRESHOLD = 40.0;
    
    /** @var float Confused topics threshold percentage */
    const CONFUSED_TOPICS_THRESHOLD = 40.0;
    
    /** @var float Difficult concept threshold percentage */
    const DIFFICULT_CONCEPT_THRESHOLD = 50.0;
    
    /** @var float Well-understood concept threshold percentage */
    const WELL_UNDERSTOOD_THRESHOLD = 80.0;
    
    /** @var float Common wrong answer threshold percentage */
    const COMMON_WRONG_ANSWER_THRESHOLD = 30.0;
    
    /** @var int Cache duration in seconds (5 minutes) */
    const CACHE_DURATION = 300;
    
    /** @var int Session ID */
    protected $sessionid;
    
    /** @var \cache_application Cache instance */
    protected $cache;
    
    /**
     * Constructor
     *
     * @param int $sessionid Session ID
     */
    public function __construct($sessionid) {
        $this->sessionid = $sessionid;
        
        // Initialize cache for performance optimization.
        try {
            $this->cache = \cache::make('mod_classengage', 'response_stats');
        } catch (\Exception $e) {
            // If cache not configured, set to null and skip caching.
            $this->cache = null;
        }
    }
    
    /**
     * Get comprehension summary
     *
     * Analyzes average correctness rate across all questions.
     *
     * @return object Comprehension data {avg_correctness, level, message, confused_topics}
     */
    public function get_comprehension_summary() {
        // Try to get from cache first.
        $cachekey = "comprehension_summary_{$this->sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        global $DB;
        
        // Calculate average correctness rate.
        $sql = "SELECT AVG(iscorrect) * 100 as avg_correctness
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid";
        
        $result = $DB->get_record_sql($sql, array('sessionid' => $this->sessionid));
        $avgcorrectness = $result && $result->avg_correctness !== null ? (float)$result->avg_correctness : 0;
        
        // Determine comprehension level and message.
        $leveldata = $this->determine_comprehension_level($avgcorrectness);
        
        // Identify confused topics (questions with < 40% correctness).
        $confusedtopics = $this->get_confused_topics();
        
        $comprehension = new \stdClass();
        $comprehension->avg_correctness = round($avgcorrectness, 2);
        $comprehension->level = $leveldata['level'];
        $comprehension->message = $leveldata['message'];
        $comprehension->confused_topics = $confusedtopics;
        
        // Cache for 5 minutes.
        if ($this->cache) {
            $this->cache->set($cachekey, $comprehension);
        }
        
        return $comprehension;
    }
    
    /**
     * Get concept difficulty
     *
     * Identifies questions with low correctness rates as difficult.
     *
     * @return array Array of concept difficulty objects
     */
    public function get_concept_difficulty() {
        // Try to get from cache first.
        $cachekey = "concept_difficulty_{$this->sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        global $DB;
        
        // Get correctness rate for each question.
        $sql = "SELECT 
                    q.id,
                    sq.questionorder,
                    q.questiontext,
                    COUNT(r.id) as total_responses,
                    SUM(r.iscorrect) as correct_responses,
                    (SUM(r.iscorrect) * 100.0 / COUNT(r.id)) as correctness_rate
                  FROM {classengage_questions} q
                  JOIN {classengage_session_questions} sq ON sq.questionid = q.id
             LEFT JOIN {classengage_responses} r ON r.questionid = q.id AND r.sessionid = :sessionid
                 WHERE sq.sessionid = :sessionid2
              GROUP BY q.id, sq.questionorder, q.questiontext
              ORDER BY correctness_rate ASC, sq.questionorder ASC";
        
        $results = $DB->get_records_sql($sql, array(
            'sessionid' => $this->sessionid,
            'sessionid2' => $this->sessionid
        ));
        
        $concepts = array();
        foreach ($results as $result) {
            $correctnessrate = $result->total_responses > 0 ? (float)$result->correctness_rate : 0;
            
            $concept = new \stdClass();
            $concept->question_order = (int)$result->questionorder;
            $concept->question_text = $result->questiontext;
            $concept->correctness_rate = round($correctnessrate, 2);
            $concept->difficulty_level = $this->determine_difficulty_level($correctnessrate);
            $concept->total_responses = (int)$result->total_responses;
            
            $concepts[] = $concept;
        }
        
        // Cache for 5 minutes.
        if ($this->cache) {
            $this->cache->set($cachekey, $concepts);
        }
        
        return $concepts;
    }
    
    /**
     * Get response trends
     *
     * Identifies common wrong answers (selected by >30% of class).
     *
     * @return array Array of response trend objects
     */
    public function get_response_trends() {
        // Try to get from cache first.
        $cachekey = "response_trends_{$this->sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        global $DB;
        
        // Get questions with their correct answers.
        $sql = "SELECT q.id, sq.questionorder, q.questiontext, q.correctanswer
                  FROM {classengage_questions} q
                  JOIN {classengage_session_questions} sq ON sq.questionid = q.id
                 WHERE sq.sessionid = :sessionid
              ORDER BY sq.questionorder";
        
        $questions = $DB->get_records_sql($sql, array('sessionid' => $this->sessionid));
        
        $trends = array();
        
        foreach ($questions as $question) {
            // Get answer distribution for this question.
            $sql = "SELECT 
                        answer,
                        COUNT(*) as count,
                        (COUNT(*) * 100.0 / (SELECT COUNT(*) FROM {classengage_responses} 
                                              WHERE sessionid = :sessionid2 AND questionid = :questionid2)) as percentage
                      FROM {classengage_responses}
                     WHERE sessionid = :sessionid
                       AND questionid = :questionid
                       AND answer != :correctanswer
                  GROUP BY answer
                    HAVING percentage > :threshold
                  ORDER BY percentage DESC";
            
            $wronganswers = $DB->get_records_sql($sql, array(
                'sessionid' => $this->sessionid,
                'sessionid2' => $this->sessionid,
                'questionid' => $question->id,
                'questionid2' => $question->id,
                'correctanswer' => strtoupper($question->correctanswer),
                'threshold' => self::COMMON_WRONG_ANSWER_THRESHOLD
            ));
            
            foreach ($wronganswers as $wronganswer) {
                $trend = new \stdClass();
                $trend->question_order = (int)$question->questionorder;
                $trend->question_text = $question->questiontext;
                $trend->common_wrong_answer = strtoupper($wronganswer->answer);
                $trend->percentage = round((float)$wronganswer->percentage, 2);
                $trend->misconception_description = $this->generate_misconception_description(
                    $question->questiontext,
                    $wronganswer->answer
                );
                
                $trends[] = $trend;
            }
        }
        
        // Cache for 5 minutes.
        if ($this->cache) {
            $this->cache->set($cachekey, $trends);
        }
        
        return $trends;
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
        $this->cache->delete("comprehension_summary_{$this->sessionid}");
        $this->cache->delete("concept_difficulty_{$this->sessionid}");
        $this->cache->delete("response_trends_{$this->sessionid}");
    }
    
    /**
     * Determine comprehension level from average correctness
     *
     * @param float $avgcorrectness Average correctness percentage
     * @return array Array with 'level' and 'message' keys
     */
    private function determine_comprehension_level($avgcorrectness) {
        if ($avgcorrectness > self::STRONG_COMPREHENSION_THRESHOLD) {
            return [
                'level' => 'strong',
                'message' => get_string('comprehensionstrong', 'mod_classengage')
            ];
        } else if ($avgcorrectness >= self::PARTIAL_COMPREHENSION_THRESHOLD) {
            return [
                'level' => 'partial',
                'message' => get_string('comprehensionpartial', 'mod_classengage')
            ];
        } else {
            return [
                'level' => 'weak',
                'message' => get_string('comprehensionweak', 'mod_classengage')
            ];
        }
    }
    
    /**
     * Get confused topics (questions with < 40% correctness)
     *
     * @return array Array of question texts
     */
    private function get_confused_topics() {
        global $DB;
        
        $sql = "SELECT 
                    q.questiontext,
                    (SUM(r.iscorrect) * 100.0 / COUNT(r.id)) as correctness_rate
                  FROM {classengage_questions} q
                  JOIN {classengage_session_questions} sq ON sq.questionid = q.id
                  JOIN {classengage_responses} r ON r.questionid = q.id AND r.sessionid = :sessionid
                 WHERE sq.sessionid = :sessionid2
              GROUP BY q.id, q.questiontext
                HAVING correctness_rate < :threshold
              ORDER BY correctness_rate ASC";
        
        $results = $DB->get_records_sql($sql, array(
            'sessionid' => $this->sessionid,
            'sessionid2' => $this->sessionid,
            'threshold' => self::CONFUSED_TOPICS_THRESHOLD
        ));
        
        $topics = array();
        foreach ($results as $result) {
            $topics[] = $result->questiontext;
        }
        
        return $topics;
    }
    
    /**
     * Determine difficulty level from correctness rate
     *
     * @param float $correctnessrate Correctness rate percentage
     * @return string Difficulty level ('easy', 'moderate', 'difficult')
     */
    private function determine_difficulty_level($correctnessrate) {
        if ($correctnessrate >= self::WELL_UNDERSTOOD_THRESHOLD) {
            return 'easy';
        } else if ($correctnessrate >= self::DIFFICULT_CONCEPT_THRESHOLD) {
            return 'moderate';
        } else {
            return 'difficult';
        }
    }
    
    /**
     * Generate misconception description
     *
     * @param string $questiontext Question text
     * @param string $wronganswer Wrong answer selected
     * @return string Misconception description
     */
    private function generate_misconception_description($questiontext, $wronganswer) {
        // For now, return a generic description.
        // This can be enhanced with NLP or predefined patterns in the future.
        return get_string('misconception', 'mod_classengage');
    }
}
