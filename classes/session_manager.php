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
 * Session manager class for handling quiz sessions
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Session manager class
 */
class session_manager {
    
    /** @var int Activity instance ID */
    protected $classengageid;
    
    /** @var \context_module Context */
    protected $context;
    
    /**
     * Constructor
     *
     * @param int $classengageid
     * @param \context_module $context
     */
    public function __construct($classengageid, $context) {
        $this->classengageid = $classengageid;
        $this->context = $context;
    }
    
    /**
     * Create a new quiz session
     *
     * @param object $data Form data
     * @param int $userid Creator user ID
     * @return int Session ID
     */
    public function create_session($data, $userid) {
        global $DB;
        
        $session = new \stdClass();
        $session->classengageid = $this->classengageid;
        $session->name = $data->name;
        $session->numquestions = $data->numquestions;
        $session->timelimit = $data->timelimit;
        $session->shufflequestions = $data->shufflequestions;
        $session->shuffleanswers = $data->shuffleanswers;
        $session->status = 'ready';
        $session->currentquestion = 0;
        $session->createdby = $userid;
        $session->timecreated = time();
        $session->timemodified = time();
        
        $sessionid = $DB->insert_record('classengage_sessions', $session);
        
        // Select questions for this session
        $this->select_questions($sessionid, $data->numquestions, $data->shufflequestions);
        
        return $sessionid;
    }
    
    /**
     * Select questions for a session
     *
     * @param int $sessionid
     * @param int $numquestions
     * @param bool $shuffle
     */
    protected function select_questions($sessionid, $numquestions, $shuffle) {
        global $DB;
        
        // Get approved questions
        $questions = $DB->get_records('classengage_questions', 
            array('classengageid' => $this->classengageid, 'status' => 'approved'));
        
        $questions = array_values($questions);
        
        if ($shuffle) {
            shuffle($questions);
        }
        
        // Take only requested number
        $questions = array_slice($questions, 0, $numquestions);
        
        // Insert into session_questions table
        $order = 1;
        foreach ($questions as $question) {
            $sq = new \stdClass();
            $sq->sessionid = $sessionid;
            $sq->questionid = $question->id;
            $sq->questionorder = $order;
            $sq->timecreated = time();
            
            $DB->insert_record('classengage_session_questions', $sq);
            $order++;
        }
    }
    
    /**
     * Start a quiz session
     *
     * @param int $sessionid
     */
    public function start_session($sessionid) {
        global $DB;
        
        $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
        
        // Stop any other active sessions for this activity
        $DB->set_field('classengage_sessions', 'status', 'completed', 
            array('classengageid' => $this->classengageid, 'status' => 'active'));
        
        $session->status = 'active';
        $session->currentquestion = 0;
        $session->timestarted = time();
        $session->questionstarttime = time();
        $session->timemodified = time();
        
        $DB->update_record('classengage_sessions', $session);
    }
    
    /**
     * Stop a quiz session
     *
     * @param int $sessionid
     */
    public function stop_session($sessionid) {
        global $DB;
        
        $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
        
        $session->status = 'completed';
        $session->timecompleted = time();
        $session->timemodified = time();
        
        $DB->update_record('classengage_sessions', $session);
        
        // Update gradebook
        $this->update_gradebook($sessionid);
    }
    
    /**
     * Move to next question
     *
     * @param int $sessionid
     */
    public function next_question($sessionid) {
        global $DB;
        
        $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
        
        $session->currentquestion++;
        $session->questionstarttime = time();
        $session->timemodified = time();
        
        // Check if we've reached the end
        if ($session->currentquestion >= $session->numquestions) {
            $session->status = 'completed';
            $session->timecompleted = time();
        }
        
        $DB->update_record('classengage_sessions', $session);
        
        // Update gradebook if session completed
        if ($session->status === 'completed') {
            $this->update_gradebook($sessionid);
        }
    }
    
    /**
     * Get current question for a session
     *
     * @param int $sessionid
     * @return object|null Question object
     */
    public function get_current_question($sessionid) {
        global $DB;
        
        $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
        
        if ($session->status !== 'active') {
            return null;
        }
        
        $sql = "SELECT q.*
                  FROM {classengage_questions} q
                  JOIN {classengage_session_questions} sq ON sq.questionid = q.id
                 WHERE sq.sessionid = :sessionid
                   AND sq.questionorder = :questionorder";
        
        $params = array(
            'sessionid' => $sessionid,
            'questionorder' => $session->currentquestion + 1 // 1-based
        );
        
        return $DB->get_record_sql($sql, $params);
    }
    
    /**
     * Update gradebook for session participants
     *
     * @param int $sessionid
     */
    protected function update_gradebook($sessionid) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/classengage/lib.php');
        
        $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
        $classengage = $DB->get_record('classengage', array('id' => $session->classengageid), '*', MUST_EXIST);
        
        // Calculate scores for each participant
        $sql = "SELECT r.userid, COUNT(*) as total, SUM(r.iscorrect) as correct
                  FROM {classengage_responses} r
                 WHERE r.sessionid = :sessionid
              GROUP BY r.userid";
        
        $results = $DB->get_records_sql($sql, array('sessionid' => $sessionid));
        
        foreach ($results as $result) {
            // Calculate grade as percentage
            $percentage = $result->total > 0 ? ($result->correct / $result->total) : 0;
            $grade = $percentage * $classengage->grade;
            
            $gradedata = new \stdClass();
            $gradedata->userid = $result->userid;
            $gradedata->rawgrade = $grade;
            
            classengage_grade_item_update($classengage, $gradedata);
        }
    }
    /**
     * Delete a session and all related data
     *
     * @param int $sessionid
     * @return bool True on success
     */
    public function delete_session($sessionid) {
        global $DB;

        // Delete responses
        $DB->delete_records('classengage_responses', array('sessionid' => $sessionid));

        // Delete session questions
        $DB->delete_records('classengage_session_questions', array('sessionid' => $sessionid));

        // Delete session
        return $DB->delete_records('classengage_sessions', array('id' => $sessionid));
    }

    /**
     * Delete multiple sessions
     *
     * @param array $sessionids
     * @return bool True on success
     */
    public function delete_sessions($sessionids) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        try {
            foreach ($sessionids as $sessionid) {
                $this->delete_session($sessionid);
            }
            $transaction->allow_commit();
            return true;
        } catch (\Exception $e) {
            $transaction->rollback($e);
            return false;
        }
    }
}

