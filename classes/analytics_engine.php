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
     * Note: The currentquestion field in classengage_sessions is 0-indexed (0 = first question),
     * while questionorder in classengage_session_questions is 1-indexed (1 = first question).
     * This method handles the conversion by adding 1 to currentquestion when querying.
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
        
        if ($session->status !== 'active') {
            return array(
                'A' => 0,
                'B' => 0,
                'C' => 0,
                'D' => 0,
                'total' => 0,
                'question' => null
            );
        }
        
        // Get current question (currentquestion is 0-indexed, questionorder is 1-indexed)
        $sql = "SELECT q.*
                  FROM {classengage_questions} q
                  JOIN {classengage_session_questions} sq ON sq.questionid = q.id
                 WHERE sq.sessionid = :sessionid
                   AND sq.questionorder = :questionorder";
        
        $question = $DB->get_record_sql($sql, array(
            'sessionid' => $sessionid,
            'questionorder' => $session->currentquestion + 1
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
                $performance->fullname = $result->firstname . ' ' . $result->lastname;
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
        
        // Invalidate all cache keys related to this session.
        $this->cache->delete("current_question_{$sessionid}");
        $this->cache->delete("session_summary_{$sessionid}");
        $this->cache->delete("question_breakdown_{$sessionid}");
        $this->cache->delete("all_students_perf_{$sessionid}");
        $this->cache->delete("engagement_timeline_{$sessionid}");
        
        // Invalidate engagement_calculator caches.
        $this->cache->delete("engagement_level_{$sessionid}");
        $this->cache->delete("activity_counts_{$sessionid}");
        $this->cache->delete("responsiveness_{$sessionid}");
        
        // Invalidate comprehension_analyzer caches.
        $this->cache->delete("comprehension_summary_{$sessionid}");
        $this->cache->delete("concept_difficulty_{$sessionid}");
        $this->cache->delete("response_trends_{$sessionid}");
        
        // Invalidate teaching_recommender caches.
        $this->cache->delete("recommendations_{$sessionid}");
        
        // Invalidate participation distribution cache (requires courseid).
        // We need to get the courseid from the session.
        global $DB;
        $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), 'classengageid');
        if ($session) {
            $classengage = $DB->get_record('classengage', array('id' => $session->classengageid), 'course');
            if ($classengage) {
                $this->cache->delete("participation_distribution_{$sessionid}_{$classengage->course}");
            }
        }
        
        // Note: Individual student performance caches will expire naturally
        // or could be invalidated if we track all userids.
    }
    
    /**
     * Get at-risk students based on low correctness or slow response time
     *
     * @param int $sessionid Session ID
     * @param float $threshold Correctness percentage threshold (default 50.0)
     * @return array Array of at-risk student objects
     */
    public function get_at_risk_students($sessionid, $threshold = 50.0) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "at_risk_{$sessionid}_{$threshold}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // First, calculate mean and standard deviation of response times
        $sql = "SELECT AVG(avg_time) as mean, STDDEV(avg_time) as stddev
                  FROM (
                      SELECT userid, AVG(responsetime) as avg_time
                        FROM {classengage_responses}
                       WHERE sessionid = :sessionid
                    GROUP BY userid
                  ) user_times";
        
        $stats = $DB->get_record_sql($sql, array('sessionid' => $sessionid));
        $mean = $stats && $stats->mean !== null ? (float)$stats->mean : 0;
        $stddev = $stats && $stats->stddev !== null ? (float)$stats->stddev : 0;
        $timethreshold = $mean + (2 * $stddev);
        
        // Get students who meet at-risk criteria
        $sql = "SELECT 
                    r.userid,
                    u.firstname,
                    u.lastname,
                    COUNT(*) as total_responses,
                    SUM(r.iscorrect) as correct_responses,
                    (SUM(r.iscorrect) * 100.0 / COUNT(*)) as percentage,
                    AVG(r.responsetime) as avg_response_time
                  FROM {classengage_responses} r
                  JOIN {user} u ON u.id = r.userid
                 WHERE r.sessionid = :sessionid
              GROUP BY r.userid, u.firstname, u.lastname
                HAVING (SUM(r.iscorrect) * 100.0 / COUNT(*)) < :threshold
                    OR AVG(r.responsetime) > :timethreshold
              ORDER BY percentage ASC, avg_response_time DESC";
        
        $results = $DB->get_records_sql($sql, array(
            'sessionid' => $sessionid,
            'threshold' => $threshold,
            'timethreshold' => $timethreshold
        ));
        
        $atriskstudents = array();
        foreach ($results as $result) {
            $student = new \stdClass();
            $student->userid = $result->userid;
            $student->firstname = $result->firstname;
            $student->lastname = $result->lastname;
            $student->fullname = $result->firstname . ' ' . $result->lastname;
            $student->total_responses = (int)$result->total_responses;
            $student->correct_responses = (int)$result->correct_responses;
            $student->percentage = round((float)$result->percentage, 2);
            $student->avg_response_time = round((float)$result->avg_response_time, 2);
            $student->reason = array();
            
            if ($student->percentage < $threshold) {
                $student->reason[] = 'low_correctness';
            }
            if ($student->avg_response_time > $timethreshold) {
                $student->reason[] = 'slow_response';
            }
            
            //Should be the fix
            $student->isatrisk = true;

            $atriskstudents[] = $student;
        }
        
        // Cache for 2 seconds
        if ($this->cache) {
            $this->cache->set($cachekey, $atriskstudents);
        }
        
        return $atriskstudents;
    }
    
    /**
     * Get top performing students
     *
     * @param int $sessionid Session ID
     * @param int $limit Number of top performers to return (default 10)
     * @return array Array of top performer objects
     */
    public function get_top_performers($sessionid, $limit = 10) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "top_performers_{$sessionid}_{$limit}";
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
                    COUNT(*) as total_responses,
                    SUM(r.iscorrect) as correct_responses,
                    (SUM(r.iscorrect) * 100.0 / COUNT(*)) as percentage,
                    AVG(r.responsetime) as avg_response_time
                  FROM {classengage_responses} r
                  JOIN {user} u ON u.id = r.userid
                 WHERE r.sessionid = :sessionid
              GROUP BY r.userid, u.firstname, u.lastname
              ORDER BY percentage DESC, avg_response_time ASC";
        
        $results = $DB->get_records_sql($sql, array('sessionid' => $sessionid), 0, $limit);
        
        $topperformers = array();
        $rank = 1;
        foreach ($results as $result) {
            $student = new \stdClass();
            $student->userid = $result->userid;
            $student->firstname = $result->firstname;
            $student->lastname = $result->lastname;
            $student->fullname = $result->firstname . ' ' . $result->lastname;
            $student->total_responses = (int)$result->total_responses;
            $student->correct_responses = (int)$result->correct_responses;
            $student->percentage = round((float)$result->percentage, 2);
            $student->avg_response_time = round((float)$result->avg_response_time, 2);
            $student->rank = $rank;
            
            $topperformers[] = $student;
            $rank++;
        }
        
        // Cache for 2 seconds
        if ($this->cache) {
            $this->cache->set($cachekey, $topperformers);
        }
        
        return $topperformers;
    }
    
    /**
     * Get students who are enrolled but have not participated
     *
     * @param int $sessionid Session ID
     * @param int $courseid Course ID
     * @return array Array of missing participant objects
     */
    public function get_missing_participants($sessionid, $courseid) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "missing_participants_{$sessionid}_{$courseid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // Get all enrolled students in the course
        $context = \context_course::instance($courseid);
        $enrolledusers = get_enrolled_users($context, 'mod/classengage:submit');
        
        if (empty($enrolledusers)) {
            return array();
        }
        
        $enrolleduserids = array_keys($enrolledusers);
        
        // Get users who have submitted responses
        list($insql, $params) = $DB->get_in_or_equal($enrolleduserids, SQL_PARAMS_NAMED);
        $params['sessionid'] = $sessionid;
        
        $sql = "SELECT DISTINCT userid
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid
                   AND userid $insql";
        
        $respondedusers = $DB->get_records_sql($sql, $params);
        $respondeduserids = array_keys($respondedusers);
        
        // Find users who haven't responded
        $missinguserids = array_diff($enrolleduserids, $respondeduserids);
        
        $missingparticipants = array();
        foreach ($missinguserids as $userid) {
            $user = $enrolledusers[$userid];
            $participant = new \stdClass();
            $participant->userid = $user->id;
            $participant->firstname = $user->firstname;
            $participant->lastname = $user->lastname;
            $participant->fullname = $user->firstname . ' ' . $user->lastname;
            $participant->email = $user->email;
            
            $missingparticipants[] = $participant;
        }
        
        // Cache for 2 seconds
        if ($this->cache) {
            $this->cache->set($cachekey, $missingparticipants);
        }
        
        return $missingparticipants;
    }
    
    /**
     * Get performance badges (most improved, fastest responder, most consistent)
     *
     * @param int $sessionid Session ID
     * @return object Object containing badge recipients
     */
    public function get_performance_badges($sessionid) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "performance_badges_{$sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $badges = new \stdClass();
        $badges->mostimproved = null;
        $badges->fastestresponder = null;
        $badges->mostconsistent = null;
        
        // Get current session's classengage ID
        $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), 'classengageid', MUST_EXIST);
        
        // Most Improved: Compare to previous session
        $sql = "SELECT id, timecreated
                  FROM {classengage_sessions}
                 WHERE classengageid = :classengageid
                   AND id < :sessionid
                   AND status = 'completed'
              ORDER BY timecreated DESC";
        
        $previoussessions = $DB->get_records_sql($sql, array(
            'classengageid' => $session->classengageid,
            'sessionid' => $sessionid
        ), 0, 1);
        
        if (!empty($previoussessions)) {
            $previoussession = reset($previoussessions);
            
            // Get improvement for each user
            $sql = "SELECT 
                        curr.userid,
                        u.firstname,
                        u.lastname,
                        (SUM(curr.iscorrect) * 100.0 / COUNT(curr.id)) as current_percentage,
                        (SUM(prev.iscorrect) * 100.0 / COUNT(prev.id)) as previous_percentage,
                        ((SUM(curr.iscorrect) * 100.0 / COUNT(curr.id)) - 
                         (SUM(prev.iscorrect) * 100.0 / COUNT(prev.id))) as improvement
                      FROM {classengage_responses} curr
                      JOIN {classengage_responses} prev ON prev.userid = curr.userid
                      JOIN {user} u ON u.id = curr.userid
                     WHERE curr.sessionid = :currentsessionid
                       AND prev.sessionid = :previoussessionid
                  GROUP BY curr.userid, u.firstname, u.lastname
                  ORDER BY improvement DESC";
            
            $improvements = $DB->get_records_sql($sql, array(
                'currentsessionid' => $sessionid,
                'previoussessionid' => $previoussession->id
            ), 0, 1);
            
            if (!empty($improvements)) {
                $mostimproved = reset($improvements);
                $badges->mostimproved = new \stdClass();
                $badges->mostimproved->userid = $mostimproved->userid;
                $badges->mostimproved->fullname = $mostimproved->firstname . ' ' . $mostimproved->lastname;
                $badges->mostimproved->improvement = round((float)$mostimproved->improvement, 2);
                $badges->mostimproved->current_percentage = round((float)$mostimproved->current_percentage, 2);
                $badges->mostimproved->previous_percentage = round((float)$mostimproved->previous_percentage, 2);
            }
        }
        
        // Fastest Responder: Minimum average response time
        $sql = "SELECT 
                    r.userid,
                    u.firstname,
                    u.lastname,
                    AVG(r.responsetime) as avg_time
                  FROM {classengage_responses} r
                  JOIN {user} u ON u.id = r.userid
                 WHERE r.sessionid = :sessionid
              GROUP BY r.userid, u.firstname, u.lastname
              ORDER BY avg_time ASC";
        
        $fastestusers = $DB->get_records_sql($sql, array('sessionid' => $sessionid), 0, 1);
        
        if (!empty($fastestusers)) {
            $fastest = reset($fastestusers);
            $badges->fastestresponder = new \stdClass();
            $badges->fastestresponder->userid = $fastest->userid;
            $badges->fastestresponder->fullname = $fastest->firstname . ' ' . $fastest->lastname;
            $badges->fastestresponder->avg_time = round((float)$fastest->avg_time, 2);
        }
        
        // Most Consistent: Minimum standard deviation in response times
        $sql = "SELECT 
                    r.userid,
                    u.firstname,
                    u.lastname,
                    STDDEV(r.responsetime) as stddev_time
                  FROM {classengage_responses} r
                  JOIN {user} u ON u.id = r.userid
                 WHERE r.sessionid = :sessionid
              GROUP BY r.userid, u.firstname, u.lastname
                HAVING COUNT(*) > 1
              ORDER BY stddev_time ASC";
        
        $consistentusers = $DB->get_records_sql($sql, array('sessionid' => $sessionid), 0, 1);
        
        if (!empty($consistentusers)) {
            $consistent = reset($consistentusers);
            $badges->mostconsistent = new \stdClass();
            $badges->mostconsistent->userid = $consistent->userid;
            $badges->mostconsistent->fullname = $consistent->firstname . ' ' . $consistent->lastname;
            $badges->mostconsistent->stddev = round((float)$consistent->stddev_time, 2);
        }
        
        // Cache for 2 seconds
        if ($this->cache) {
            $this->cache->set($cachekey, $badges);
        }
        
        return $badges;
    }
    
    /**
     * Get anomalies in response data
     *
     * @param int $sessionid Session ID
     * @return array Array of anomaly objects
     */
    public function get_anomalies($sessionid) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "anomalies_{$sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $anomalies = array();
        
        // Suspicious Speed: Average response time < 1 second
        $sql = "SELECT 
                    r.userid,
                    u.firstname,
                    u.lastname,
                    AVG(r.responsetime) as avg_time,
                    COUNT(*) as response_count
                  FROM {classengage_responses} r
                  JOIN {user} u ON u.id = r.userid
                 WHERE r.sessionid = :sessionid
              GROUP BY r.userid, u.firstname, u.lastname
                HAVING AVG(r.responsetime) < 1.0";
        
        $suspicioususers = $DB->get_records_sql($sql, array('sessionid' => $sessionid));
        
        foreach ($suspicioususers as $user) {
            $anomaly = new \stdClass();
            $anomaly->type = 'suspicious_speed';
            $anomaly->userid = $user->userid;
            $anomaly->fullname = $user->firstname . ' ' . $user->lastname;
            $anomaly->details = get_string('anomaly_suspicious_speed', 'mod_classengage', array(
                'avgtime' => round((float)$user->avg_time, 2),
                'count' => (int)$user->response_count
            ));
            $anomaly->severity = 'high';
            $anomaly->avg_time = round((float)$user->avg_time, 2);
            
            $anomalies[] = $anomaly;
        }
        
        // Perfect Score with Fast Time: 100% correct with avg time < 3 seconds
        $sql = "SELECT 
                    r.userid,
                    u.firstname,
                    u.lastname,
                    COUNT(*) as total,
                    SUM(r.iscorrect) as correct,
                    AVG(r.responsetime) as avg_time
                  FROM {classengage_responses} r
                  JOIN {user} u ON u.id = r.userid
                 WHERE r.sessionid = :sessionid
              GROUP BY r.userid, u.firstname, u.lastname
                HAVING SUM(r.iscorrect) = COUNT(*)
                   AND AVG(r.responsetime) < 3.0";
        
        $perfectfastusers = $DB->get_records_sql($sql, array('sessionid' => $sessionid));
        
        foreach ($perfectfastusers as $user) {
            $anomaly = new \stdClass();
            $anomaly->type = 'perfect_fast';
            $anomaly->userid = $user->userid;
            $anomaly->fullname = $user->firstname . ' ' . $user->lastname;
            $anomaly->details = get_string('anomaly_perfect_fast', 'mod_classengage', array(
                'avgtime' => round((float)$user->avg_time, 2),
                'count' => (int)$user->total
            ));
            $anomaly->severity = 'medium';
            $anomaly->avg_time = round((float)$user->avg_time, 2);
            $anomaly->percentage = 100.0;
            
            $anomalies[] = $anomaly;
        }
        
        // Cache for 2 seconds
        if ($this->cache) {
            $this->cache->set($cachekey, $anomalies);
        }
        
        return $anomalies;
    }
    
    /**
     * Get question insights (highest/lowest performing questions)
     *
     * @param int $sessionid Session ID
     * @return object Object containing question insights
     */
    public function get_question_insights($sessionid) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "question_insights_{$sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $insights = new \stdClass();
        $insights->highest_performing = null;
        $insights->lowest_performing = null;
        
        // Get question performance statistics
        $sql = "SELECT 
                    q.id,
                    q.questiontext,
                    q.correctanswer,
                    sq.questionorder,
                    COUNT(*) as total_responses,
                    SUM(r.iscorrect) as correct_responses,
                    (SUM(r.iscorrect) * 100.0 / COUNT(*)) as success_rate,
                    AVG(r.responsetime) as avg_response_time
                  FROM {classengage_questions} q
                  JOIN {classengage_session_questions} sq ON sq.questionid = q.id
                  JOIN {classengage_responses} r ON r.questionid = q.id AND r.sessionid = sq.sessionid
                 WHERE sq.sessionid = :sessionid
              GROUP BY q.id, q.questiontext, q.correctanswer, sq.questionorder
              ORDER BY success_rate DESC";
        
        $questions = $DB->get_records_sql($sql, array('sessionid' => $sessionid));
        
        if (!empty($questions)) {
            // Highest performing is first (highest success rate)
            $highest = reset($questions);
            $insights->highest_performing = new \stdClass();
            $insights->highest_performing->questionid = $highest->id;
            $insights->highest_performing->questiontext = $highest->questiontext;
            $insights->highest_performing->correctanswer = $highest->correctanswer;
            $insights->highest_performing->questionorder = $highest->questionorder;
            $insights->highest_performing->total_responses = (int)$highest->total_responses;
            $insights->highest_performing->correct_responses = (int)$highest->correct_responses;
            $insights->highest_performing->success_rate = round((float)$highest->success_rate, 2);
            $insights->highest_performing->avg_response_time = round((float)$highest->avg_response_time, 2);
            
            // Lowest performing is last (lowest success rate)
            $lowest = end($questions);
            $insights->lowest_performing = new \stdClass();
            $insights->lowest_performing->questionid = $lowest->id;
            $insights->lowest_performing->questiontext = $lowest->questiontext;
            $insights->lowest_performing->correctanswer = $lowest->correctanswer;
            $insights->lowest_performing->questionorder = $lowest->questionorder;
            $insights->lowest_performing->total_responses = (int)$lowest->total_responses;
            $insights->lowest_performing->correct_responses = (int)$lowest->correct_responses;
            $insights->lowest_performing->success_rate = round((float)$lowest->success_rate, 2);
            $insights->lowest_performing->avg_response_time = round((float)$lowest->avg_response_time, 2);
        }
        
        // Cache for 2 seconds
        if ($this->cache) {
            $this->cache->set($cachekey, $insights);
        }
        
        return $insights;
    }
    
    /**
     * Get engagement timeline showing response submission patterns over time
     * Refactored to return max 20 intervals with peak/dip flags
     *
     * @param int $sessionid Session ID
     * @return array Array of timeline data points with interval, timestamp, label, count, is_peak, is_dip
     */
    public function get_engagement_timeline($sessionid) {
        global $DB;
        
        // Maximum 20 intervals as per requirements
        $maxintervals = 20;
        
        // Try to get from cache first
        $cachekey = "engagement_timeline_{$sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // Get session start and end times
        $sql = "SELECT MIN(timecreated) as start_time, MAX(timecreated) as end_time
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid";
        
        $timerange = $DB->get_record_sql($sql, array('sessionid' => $sessionid));
        
        if (!$timerange || $timerange->start_time === null) {
            return array();
        }
        
        $starttime = (int)$timerange->start_time;
        $endtime = (int)$timerange->end_time;
        $duration = $endtime - $starttime;
        
        // If duration is 0, all responses were at the same time
        if ($duration == 0) {
            $totalcount = $DB->count_records('classengage_responses', array('sessionid' => $sessionid));
            $timeline = array();
            $timeline[] = array(
                'interval' => 1,
                'timestamp' => $starttime,
                'label' => userdate($starttime, get_string('strftimetime', 'langconfig')),
                'count' => $totalcount,
                'is_peak' => true,
                'is_dip' => false
            );
            
            // Cache for 5 minutes
            if ($this->cache) {
                $this->cache->set($cachekey, $timeline);
            }
            
            return $timeline;
        }
        
        // Calculate interval size to ensure equal duration
        $intervalsize = ceil($duration / $maxintervals);
        $timeline = array();
        
        // Create intervals and count responses in each
        for ($i = 0; $i < $maxintervals; $i++) {
            $intervalstart = $starttime + ($i * $intervalsize);
            $intervalend = $starttime + (($i + 1) * $intervalsize);
            
            // Stop if we've passed the end time
            if ($intervalstart > $endtime) {
                break;
            }
            
            $sql = "SELECT COUNT(*) as count
                      FROM {classengage_responses}
                     WHERE sessionid = :sessionid
                       AND timecreated >= :start
                       AND timecreated < :end";
            
            $count = $DB->get_record_sql($sql, array(
                'sessionid' => $sessionid,
                'start' => $intervalstart,
                'end' => $intervalend
            ));
            
            $timeline[] = array(
                'interval' => $i + 1,
                'timestamp' => $intervalstart,
                'label' => userdate($intervalstart, get_string('strftimetime', 'langconfig')),
                'count' => $count ? (int)$count->count : 0,
                'is_peak' => false,
                'is_dip' => false
            );
        }
        
        // Identify peaks and dips
        if (!empty($timeline)) {
            $counts = array_column($timeline, 'count');
            $maxcount = max($counts);
            $dipthreshold = $maxcount * 0.5; // Dips are < 50% of max
            
            for ($i = 0; $i < count($timeline); $i++) {
                // Mark peak (highest count)
                if ($timeline[$i]['count'] == $maxcount && $maxcount > 0) {
                    $timeline[$i]['is_peak'] = true;
                }
                
                // Mark dip (< 50% of max)
                if ($timeline[$i]['count'] < $dipthreshold && $maxcount > 0) {
                    $timeline[$i]['is_dip'] = true;
                }
            }
        }
        
        // Cache for 5 minutes
        if ($this->cache) {
            $this->cache->set($cachekey, $timeline);
        }
        
        return $timeline;
    }
    
    /**
     * Get participation distribution categorizing students by response count
     * Returns anonymous distribution without student names
     *
     * @param int $sessionid Session ID
     * @param int $courseid Course ID
     * @return object Object with high, moderate, low, none counts and message
     */
    public function get_participation_distribution($sessionid, $courseid) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "participation_distribution_{$sessionid}_{$courseid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // Get all enrolled students in the course
        $context = \context_course::instance($courseid);
        $enrolledusers = get_enrolled_users($context, 'mod/classengage:submit');
        $totalenrolled = count($enrolledusers);
        
        if ($totalenrolled == 0) {
            $distribution = new \stdClass();
            $distribution->high = 0;
            $distribution->moderate = 0;
            $distribution->low = 0;
            $distribution->none = 0;
            $distribution->total_enrolled = 0;
            $distribution->message = '';
            return $distribution;
        }
        
        // Get response counts per student
        $sql = "SELECT userid, COUNT(*) as response_count
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid
              GROUP BY userid";
        
        $responsecounts = $DB->get_records_sql($sql, array('sessionid' => $sessionid));
        
        // Initialize distribution
        $distribution = new \stdClass();
        $distribution->high = 0;      // 5+ responses
        $distribution->moderate = 0;  // 2-4 responses
        $distribution->low = 0;       // 1 response
        $distribution->none = 0;      // 0 responses
        
        // Categorize students who responded
        $respondeduserids = array();
        foreach ($responsecounts as $record) {
            $respondeduserids[] = $record->userid;
            $count = (int)$record->response_count;
            
            if ($count >= 5) {
                $distribution->high++;
            } else if ($count >= 2 && $count <= 4) {
                $distribution->moderate++;
            } else if ($count == 1) {
                $distribution->low++;
            }
        }
        
        // Count students who didn't respond
        $enrolleduserids = array_keys($enrolledusers);
        $nonrespondeduserids = array_diff($enrolleduserids, $respondeduserids);
        $distribution->none = count($nonrespondeduserids);
        
        // Add total enrolled count
        $distribution->total_enrolled = $totalenrolled;
        
        // Generate message based on participation
        $participatedcount = $distribution->high + $distribution->moderate + $distribution->low;
        $participationrate = $totalenrolled > 0 ? ($participatedcount / $totalenrolled) * 100 : 0;
        
        if ($participationrate > 75) {
            $distribution->message = get_string('broadparticipation', 'mod_classengage');
        } else if ($distribution->none > 0) {
            $distribution->message = get_string('quietperiodsuggestion', 'mod_classengage');
        } else {
            $distribution->message = '';
        }
        
        // Cache for 5 minutes
        if ($this->cache) {
            $this->cache->set($cachekey, $distribution);
        }
        
        return $distribution;
    }
    
    /**
     * Get score distribution showing frequency of students in percentage buckets
     *
     * @param int $sessionid Session ID
     * @param int $buckets Number of percentage buckets (default 10 for 10% increments)
     * @return array Associative array of bucket labels to student counts
     */
    public function get_score_distribution($sessionid, $buckets = 10) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "score_distribution_{$sessionid}_{$buckets}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // Get all student percentages
        $sql = "SELECT 
                    userid,
                    (SUM(iscorrect) * 100.0 / COUNT(*)) as percentage
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid
              GROUP BY userid";
        
        $results = $DB->get_records_sql($sql, array('sessionid' => $sessionid));
        
        // Initialize buckets
        $bucketsize = 100 / $buckets;
        $distribution = array();
        
        for ($i = 0; $i < $buckets; $i++) {
            $lower = $i * $bucketsize;
            $upper = ($i + 1) * $bucketsize;
            $label = $lower . '-' . $upper;
            $distribution[$label] = 0;
        }
        
        // Count students in each bucket
        foreach ($results as $result) {
            $percentage = (float)$result->percentage;
            
            // Determine which bucket this percentage falls into
            $bucketindex = min(floor($percentage / $bucketsize), $buckets - 1);
            $lower = $bucketindex * $bucketsize;
            $upper = ($bucketindex + 1) * $bucketsize;
            $label = $lower . '-' . $upper;
            
            if (isset($distribution[$label])) {
                $distribution[$label]++;
            }
        }
        
        // Cache for 2 seconds
        if ($this->cache) {
            $this->cache->set($cachekey, $distribution);
        }
        
        return $distribution;
    }
    
    /**
     * Get participation rate as percentage of enrolled students who responded
     *
     * @param int $sessionid Session ID
     * @param int $courseid Course ID
     * @return float Participation rate percentage
     */
    public function get_participation_rate($sessionid, $courseid) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "participation_rate_{$sessionid}_{$courseid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // Get all enrolled students in the course
        $context = \context_course::instance($courseid);
        $enrolledusers = get_enrolled_users($context, 'mod/classengage:submit');
        $totalenrolled = count($enrolledusers);
        
        if ($totalenrolled == 0) {
            return 0.0;
        }
        
        // Get count of unique students who responded
        $sql = "SELECT COUNT(DISTINCT userid) as count
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid";
        
        $result = $DB->get_record_sql($sql, array('sessionid' => $sessionid));
        $respondingcount = $result ? (int)$result->count : 0;
        
        $participationrate = round(($respondingcount / $totalenrolled) * 100, 2);
        
        // Cache for 2 seconds
        if ($this->cache) {
            $this->cache->set($cachekey, $participationrate);
        }
        
        return $participationrate;
    }
    
    /**
     * Get accuracy trend comparing current session to previous session
     *
     * @param int $sessionid Session ID
     * @param int $classengageid ClassEngage activity ID
     * @return float|null Accuracy trend (positive = improvement, negative = decline), null if no previous session
     */
    public function get_accuracy_trend($sessionid, $classengageid) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "accuracy_trend_{$sessionid}_{$classengageid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // Get current session average correctness
        $sql = "SELECT AVG(percentage) as avg_percentage
                  FROM (
                      SELECT (SUM(iscorrect) * 100.0 / COUNT(*)) as percentage
                        FROM {classengage_responses}
                       WHERE sessionid = :sessionid
                    GROUP BY userid
                  ) user_percentages";
        
        $currentavg = $DB->get_record_sql($sql, array('sessionid' => $sessionid));
        $currentpercentage = $currentavg && $currentavg->avg_percentage !== null ? (float)$currentavg->avg_percentage : 0;
        
        // Get previous session
        $sql = "SELECT id
                  FROM {classengage_sessions}
                 WHERE classengageid = :classengageid
                   AND id < :sessionid
                   AND status = 'completed'
              ORDER BY timecreated DESC";
        
        $previoussessions = $DB->get_records_sql($sql, array(
            'classengageid' => $classengageid,
            'sessionid' => $sessionid
        ), 0, 1);
        
        if (empty($previoussessions)) {
            // No previous session to compare
            return null;
        }
        
        $previoussession = reset($previoussessions);
        
        // Get previous session average correctness
        $sql = "SELECT AVG(percentage) as avg_percentage
                  FROM (
                      SELECT (SUM(iscorrect) * 100.0 / COUNT(*)) as percentage
                        FROM {classengage_responses}
                       WHERE sessionid = :sessionid
                    GROUP BY userid
                  ) user_percentages";
        
        $previousavg = $DB->get_record_sql($sql, array('sessionid' => $previoussession->id));
        $previouspercentage = $previousavg && $previousavg->avg_percentage !== null ? (float)$previousavg->avg_percentage : 0;
        
        $trend = round($currentpercentage - $previouspercentage, 2);
        
        // Cache for 2 seconds
        if ($this->cache) {
            $this->cache->set($cachekey, $trend);
        }
        
        return $trend;
    }
    
    /**
     * Get response speed statistics (mean, median, standard deviation)
     *
     * @param int $sessionid Session ID
     * @return object Object containing mean, median, and stddev
     */
    public function get_response_speed_stats($sessionid) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "response_speed_stats_{$sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $stats = new \stdClass();
        
        // Get mean and standard deviation
        $sql = "SELECT 
                    AVG(responsetime) as mean,
                    STDDEV(responsetime) as stddev
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid";
        
        $result = $DB->get_record_sql($sql, array('sessionid' => $sessionid));
        
        $stats->mean = $result && $result->mean !== null ? round((float)$result->mean, 2) : 0;
        $stats->stddev = $result && $result->stddev !== null ? round((float)$result->stddev, 2) : 0;
        
        // Get median (requires fetching all response times and calculating)
        $sql = "SELECT responsetime
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid
              ORDER BY responsetime ASC";
        
        $times = $DB->get_fieldset_sql($sql, array('sessionid' => $sessionid));
        
        if (!empty($times)) {
            $times = array_map('floatval', $times);
            $count = count($times);
            
            if ($count % 2 == 0) {
                // Even number of elements - average the two middle values
                $median = ($times[$count / 2 - 1] + $times[$count / 2]) / 2;
            } else {
                // Odd number of elements - take the middle value
                $median = $times[floor($count / 2)];
            }
            
            $stats->median = round($median, 2);
        } else {
            $stats->median = 0;
        }
        
        // Cache for 2 seconds
        if ($this->cache) {
            $this->cache->set($cachekey, $stats);
        }
        
        return $stats;
    }
    
    /**
     * Get highest consecutive correct answer streak by any student
     *
     * @param int $sessionid Session ID
     * @return object Object containing userid, fullname, and streak count
     */
    public function get_highest_streak($sessionid) {
        global $DB;
        
        // Try to get from cache first
        $cachekey = "highest_streak_{$sessionid}";
        if ($this->cache) {
            $cached = $this->cache->get($cachekey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // Get all responses ordered by user and time
        $sql = "SELECT r.userid, r.iscorrect, u.firstname, u.lastname
                  FROM {classengage_responses} r
                  JOIN {user} u ON u.id = r.userid
                 WHERE r.sessionid = :sessionid
              ORDER BY r.userid, r.timecreated ASC";
        
        $responses = $DB->get_records_sql($sql, array('sessionid' => $sessionid));
        
        $maxstreak = 0;
        $maxstreakuser = null;
        $currentuserid = null;
        $currentstreak = 0;
        
        foreach ($responses as $response) {
            if ($response->userid != $currentuserid) {
                // New user, reset streak
                $currentuserid = $response->userid;
                $currentstreak = 0;
            }
            
            if ($response->iscorrect) {
                $currentstreak++;
                if ($currentstreak > $maxstreak) {
                    $maxstreak = $currentstreak;
                    $maxstreakuser = $response;
                }
            } else {
                $currentstreak = 0;
            }
        }
        
        $result = new \stdClass();
        
        if ($maxstreakuser) {
            $result->userid = $maxstreakuser->userid;
            $result->fullname = $maxstreakuser->firstname . ' ' . $maxstreakuser->lastname;
            $result->streak = $maxstreak;
        } else {
            $result->userid = null;
            $result->fullname = null;
            $result->streak = 0;
        }
        
        // Cache for 2 seconds
        if ($this->cache) {
            $this->cache->set($cachekey, $result);
        }
        
        return $result;
    }
}
