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
 * Analytics engine for response aggregation and statistics
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Analytics engine class
 */
class analytics_engine {
    
    /** @var int Activity instance ID */
    protected $classengageid;
    
    /** @var \context_module Context */
    protected $context;
    
    /** @var \cache_application Cache instance */
    protected $cache;
    
    /**
     * Constructor
     *
     * @param int $classengageid Activity instance ID
     * @param \context_module $context Module context
     */
    public function __construct($classengageid, $context) {
        $this->classengageid = $classengageid;
        $this->context = $context;
        
        // Initialize cache for response statistics
        // Note: Cache definition should be added to db/caches.php
        try {
            $this->cache = \cache::make('mod_classengage', 'response_stats');
        } catch (\Exception $e) {
            // If cache not configured, set to null and skip caching
            $this->cache = null;
        }
    }
    
    /**
     * Get real-time response distribution for current question
     *
     * @param int $sessionid Session ID
     * @return array Response distribution ['A' => 10, 'B' => 15, 'C' => 5, 'D' => 2]
     */
    public function get_current_question_stats($sessionid) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "current_question_{$sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // Get session and current question
        $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
        
        if ($session->status !== 'active' || $session->currentquestion < 1) {
            return array(
                'A' => 0,
                'B' => 0,
                'C' => 0,
                'D' => 0,
                'total' => 0,
                'question' => null
            );
        }
        
        // Get current question
        $sql = "SELECT q.*
                  FROM {classengage_questions} q
                  JOIN {classengage_session_questions} sq ON sq.questionid = q.id
                 WHERE sq.sessionid = :sessionid
                   AND sq.questionorder = :questionorder";
        
        $question = $DB->get_record_sql($sql, array(
            'sessionid' => $sessionid,
            'questionorder' => $session->currentquestion
        ));
        
        if (!$question) {
            return array(
                'A' => 0,
                'B' => 0,
                'C' => 0,
                'D' => 0,
                'total' => 0,
                'question' => null
            );
        }
        
        // Aggregate responses for current question
        $sql = "SELECT answer, COUNT(*) as count
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid
                   AND questionid = :questionid
              GROUP BY answer";
        
        $responses = $DB->get_records_sql($sql, array(
            'sessionid' => $sessionid,
            'questionid' => $question->id
        ));
        
        // Initialize distribution
        $distribution = array(
            'A' => 0,
            'B' => 0,
            'C' => 0,
            'D' => 0
        );
        
        $total = 0;
        foreach ($responses as $response) {
            $answer = strtoupper($response->answer);
            if (isset($distribution[$answer])) {
                $distribution[$answer] = (int)$response->count;
                $total += (int)$response->count;
            }
        }
        
        $result = array_merge($distribution, array(
            'total' => $total,
            'question' => $question,
            'correctanswer' => $question->correctanswer,
            'questiontext' => $question->questiontext
        ));
        
        // Cache for 2 seconds
        if ($this->cache) {
            $this->cache->set($cachekey, $result);
        }
        
        return $result;
    }
    
    /**
     * Get session summary statistics
     *
     * @param int $sessionid Session ID
     * @return object Summary statistics {total_participants, avg_score, completion_rate, total_questions, avg_response_time}
     */
    public function get_session_summary($sessionid) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "session_summary_{$sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
        
        // Get total unique participants
        $sql = "SELECT COUNT(DISTINCT userid) as count
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid";
        
        $participants = $DB->get_record_sql($sql, array('sessionid' => $sessionid));
        $totalparticipants = $participants ? (int)$participants->count : 0;
        
        // Get average score per user
        $sql = "SELECT AVG(user_score) as avg_score
                  FROM (
                      SELECT userid, 
                             (SUM(iscorrect) * 100.0 / COUNT(*)) as user_score
                        FROM {classengage_responses}
                       WHERE sessionid = :sessionid
                    GROUP BY userid
                  ) user_scores";
        
        $avgscore = $DB->get_record_sql($sql, array('sessionid' => $sessionid));
        $averagescore = $avgscore && $avgscore->avg_score !== null ? round((float)$avgscore->avg_score, 2) : 0;
        
        // Get completion rate (users who answered all questions)
        $sql = "SELECT COUNT(DISTINCT userid) as count
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid
              GROUP BY userid
                HAVING COUNT(*) = :numquestions";
        
        $completed = $DB->get_records_sql($sql, array(
            'sessionid' => $sessionid,
            'numquestions' => $session->numquestions
        ));
        $completedcount = count($completed);
        $completionrate = $totalparticipants > 0 ? round(($completedcount / $totalparticipants) * 100, 2) : 0;
        
        // Get average response time
        $sql = "SELECT AVG(responsetime) as avg_time
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid";
        
        $avgtime = $DB->get_record_sql($sql, array('sessionid' => $sessionid));
        $averageresponsetime = $avgtime && $avgtime->avg_time !== null ? round((float)$avgtime->avg_time, 2) : 0;
        
        // Get total responses
        $totalresponses = $DB->count_records('classengage_responses', array('sessionid' => $sessionid));
        
        $summary = new \stdClass();
        $summary->total_participants = $totalparticipants;
        $summary->avg_score = $averagescore;
        $summary->completion_rate = $completionrate;
        $summary->total_questions = $session->numquestions;
        $summary->avg_response_time = $averageresponsetime;
        $summary->total_responses = $totalresponses;
        $summary->session_status = $session->status;
        $summary->current_question = $session->currentquestion;
        
        // Cache for 2 seconds
        if ($this->cache) {
            $this->cache->set($cachekey, $summary);
        }
        
        return $summary;
    }
    
    /**
     * Get question-by-question breakdown
     *
     * @param int $sessionid Session ID
     * @return array Array of question statistics
     */
    public function get_question_breakdown($sessionid) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "question_breakdown_{$sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
        
        // Get all questions for this session
        $sql = "SELECT q.*, sq.questionorder
                  FROM {classengage_questions} q
                  JOIN {classengage_session_questions} sq ON sq.questionid = q.id
                 WHERE sq.sessionid = :sessionid
              ORDER BY sq.questionorder";
        
        $questions = $DB->get_records_sql($sql, array('sessionid' => $sessionid));
        
        $breakdown = array();
        
        foreach ($questions as $question) {
            // Get response statistics for this question
            $sql = "SELECT 
                        COUNT(*) as total_responses,
                        SUM(iscorrect) as correct_responses,
                        AVG(responsetime) as avg_response_time,
                        MIN(responsetime) as min_response_time,
                        MAX(responsetime) as max_response_time
                      FROM {classengage_responses}
                     WHERE sessionid = :sessionid
                       AND questionid = :questionid";
            
            $stats = $DB->get_record_sql($sql, array(
                'sessionid' => $sessionid,
                'questionid' => $question->id
            ));
            
            // Get answer distribution
            $sql = "SELECT answer, COUNT(*) as count
                      FROM {classengage_responses}
                     WHERE sessionid = :sessionid
                       AND questionid = :questionid
                  GROUP BY answer";
            
            $answerdist = $DB->get_records_sql($sql, array(
                'sessionid' => $sessionid,
                'questionid' => $question->id
            ));
            
            $distribution = array('A' => 0, 'B' => 0, 'C' => 0, 'D' => 0);
            foreach ($answerdist as $ans) {
                $answer = strtoupper($ans->answer);
                if (isset($distribution[$answer])) {
                    $distribution[$answer] = (int)$ans->count;
                }
            }
            
            $totalresponses = $stats ? (int)$stats->total_responses : 0;
            $correctresponses = $stats ? (int)$stats->correct_responses : 0;
            $successrate = $totalresponses > 0 ? round(($correctresponses / $totalresponses) * 100, 2) : 0;
            
            $questionstat = new \stdClass();
            $questionstat->question_id = $question->id;
            $questionstat->question_order = $question->questionorder;
            $questionstat->question_text = $question->questiontext;
            $questionstat->correct_answer = $question->correctanswer;
            $questionstat->difficulty = $question->difficulty;
            $questionstat->total_responses = $totalresponses;
            $questionstat->correct_responses = $correctresponses;
            $questionstat->success_rate = $successrate;
            $questionstat->avg_response_time = $stats && $stats->avg_response_time !== null ? round((float)$stats->avg_response_time, 2) : 0;
            $questionstat->min_response_time = $stats && $stats->min_response_time !== null ? (int)$stats->min_response_time : 0;
            $questionstat->max_response_time = $stats && $stats->max_response_time !== null ? (int)$stats->max_response_time : 0;
            $questionstat->answer_distribution = $distribution;
            
            $breakdown[] = $questionstat;
        }
        
        // Cache for 2 seconds
        if ($this->cache) {
            $this->cache->set($cachekey, $breakdown);
        }
        
        return $breakdown;
    }
    
    /**
     * Get student performance data
     *
     * @param int $sessionid Session ID
     * @param int $userid User ID (optional, if null returns all students)
     * @return object|array Student performance {correct, total, percentage, rank, avg_response_time}
     */
    public function get_student_performance($sessionid, $userid = null) {
        global $DB;
        
        if ($userid !== null) {
            // Get individual student performance
            $cachekey = "student_perf_{$sessionid}_{$userid}";
            if ($this->cache) {
                $cached = $this->cache->get($cachekey);
                if ($cached !== false) {
                    return $cached;
                }
            }
            
            // Get student's responses
            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(iscorrect) as correct,
                        AVG(responsetime) as avg_response_time
                      FROM {classengage_responses}
                     WHERE sessionid = :sessionid
                       AND userid = :userid";
            
            $stats = $DB->get_record_sql($sql, array(
                'sessionid' => $sessionid,
                'userid' => $userid
            ));
            
            if (!$stats || $stats->total == 0) {
                $performance = new \stdClass();
                $performance->userid = $userid;
                $performance->correct = 0;
                $performance->total = 0;
                $performance->percentage = 0;
                $performance->rank = null;
                $performance->avg_response_time = 0;
                return $performance;
            }
            
            $correct = (int)$stats->correct;
            $total = (int)$stats->total;
            $percentage = $total > 0 ? round(($correct / $total) * 100, 2) : 0;
            
            // Calculate rank
            $sql = "SELECT COUNT(DISTINCT userid) + 1 as rank
                      FROM {classengage_responses}
                     WHERE sessionid = :sessionid
                  GROUP BY userid
                    HAVING (SUM(iscorrect) * 100.0 / COUNT(*)) > :percentage";
            
            $rankresult = $DB->get_record_sql($sql, array(
                'sessionid' => $sessionid,
                'percentage' => $percentage
            ));
            
            $rank = $rankresult ? (int)$rankresult->rank : 1;
            
            $performance = new \stdClass();
            $performance->userid = $userid;
            $performance->correct = $correct;
            $performance->total = $total;
            $performance->percentage = $percentage;
            $performance->rank = $rank;
            $performance->avg_response_time = $stats->avg_response_time !== null ? round((float)$stats->avg_response_time, 2) : 0;
            
            // Cache for 2 seconds
            if ($this->cache) {
                $this->cache->set($cachekey, $performance);
            }
            
            return $performance;
        } else {
            // Get all students' performance
            $cachekey = "all_students_perf_{$sessionid}";
            if ($this->cache) {
                $cached = $this->cache->get($cachekey);
                if ($cached !== false) {
                    return $cached;
                }
            }
            
            $sql = "SELECT 
                        r.userid,
                        u.firstname,
                        u.lastname,
                        COUNT(*) as total,
                        SUM(r.iscorrect) as correct,
                        (SUM(r.iscorrect) * 100.0 / COUNT(*)) as percentage,
                        AVG(r.responsetime) as avg_response_time
                      FROM {classengage_responses} r
                      JOIN {user} u ON u.id = r.userid
                     WHERE r.sessionid = :sessionid
                  GROUP BY r.userid, u.firstname, u.lastname
                  ORDER BY percentage DESC, avg_response_time ASC";
            
            $results = $DB->get_records_sql($sql, array('sessionid' => $sessionid));
            
            $performances = array();
            $rank = 1;
            
            foreach ($results as $result) {
                $performance = new \stdClass();
                $performance->userid = $result->userid;
                $performance->firstname = $result->firstname;
                $performance->lastname = $result->lastname;
                $performance->fullname = fullname($result);
                $performance->correct = (int)$result->correct;
                $performance->total = (int)$result->total;
                $performance->percentage = round((float)$result->percentage, 2);
                $performance->rank = $rank;
                $performance->avg_response_time = round((float)$result->avg_response_time, 2);
                
                $performances[] = $performance;
                $rank++;
            }
            
            // Cache for 2 seconds
            if ($this->cache) {
                $this->cache->set($cachekey, $performances);
            }
            
            return $performances;
        }
    }
    
    /**
     * Invalidate cache for a session
     * Should be called when new responses are submitted
     *
     * @param int $sessionid Session ID
     */
    public function invalidate_cache($sessionid) {
        if (!$this->cache) {
            return;
        }
        
        // Invalidate all cache keys related to this session
        $this->cache->delete("current_question_{$sessionid}");
        $this->cache->delete("session_summary_{$sessionid}");
        $this->cache->delete("question_breakdown_{$sessionid}");
        $this->cache->delete("all_students_perf_{$sessionid}");
        
        // Note: Individual student performance caches will expire naturally
        // or could be invalidated if we track all userids
    }
}
