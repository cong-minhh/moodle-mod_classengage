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
 * AJAX handler for real-time quiz operations
 *
 * @package    mod_classengage
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');
require_once(__DIR__.'/classes/session_manager.php');

$action = required_param('action', PARAM_ALPHA);
$sessionid = required_param('sessionid', PARAM_INT);

$session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
$classengage = $DB->get_record('classengage', array('id' => $session->classengageid), '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('classengage', $classengage->id, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);

require_login(0, false, null, false, true);
require_sesskey();

$response = array('success' => false);

try {
    switch ($action) {
        case 'getcurrent':
            // Get current question for student
            require_capability('mod/classengage:takequiz', $context);
            $response = get_current_question($sessionid);
            break;
            
        case 'submitanswer':
            // Submit student answer
            require_capability('mod/classengage:takequiz', $context);
            $questionid = required_param('questionid', PARAM_INT);
            $answer = required_param('answer', PARAM_TEXT);
            $response = submit_answer($sessionid, $questionid, $answer, $classengage->id);
            break;
            
        case 'getstats':
            // Get session statistics for instructor
            require_capability('mod/classengage:viewanalytics', $context);
            $response = get_session_stats($sessionid);
            break;
            
        default:
            $response['error'] = 'Invalid action';
    }
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($response);

/**
 * Get current question for session
 *
 * @param int $sessionid
 * @return array
 */
function get_current_question($sessionid) {
    global $DB, $USER;
    
    $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
    
    if ($session->status !== 'active') {
        return array(
            'success' => true,
            'status' => $session->status,
            'question' => null
        );
    }
    
    // Get current question
    $sql = "SELECT q.*
              FROM {classengage_questions} q
              JOIN {classengage_session_questions} sq ON sq.questionid = q.id
             WHERE sq.sessionid = :sessionid
               AND sq.questionorder = :questionorder";
    
    $params = array(
        'sessionid' => $sessionid,
        'questionorder' => $session->currentquestion + 1
    );
    
    $question = $DB->get_record_sql($sql, $params);
    
    if (!$question) {
        return array(
            'success' => true,
            'status' => 'waiting',
            'question' => null
        );
    }
    
    // Check if user has already answered
    $answered = $DB->record_exists('classengage_responses', array(
        'sessionid' => $sessionid,
        'questionid' => $question->id,
        'userid' => $USER->id
    ));
    
    // Prepare options (shuffle if needed)
    $options = array();
    if ($question->optiona) {
        $options[] = array('key' => 'A', 'text' => $question->optiona);
    }
    if ($question->optionb) {
        $options[] = array('key' => 'B', 'text' => $question->optionb);
    }
    if ($question->optionc) {
        $options[] = array('key' => 'C', 'text' => $question->optionc);
    }
    if ($question->optiond) {
        $options[] = array('key' => 'D', 'text' => $question->optiond);
    }
    
    if ($session->shuffleanswers) {
        shuffle($options);
    }
    
    // Calculate time remaining
    $elapsed = time() - $session->questionstarttime;
    $remaining = max(0, $session->timelimit - $elapsed);
    
    return array(
        'success' => true,
        'status' => 'active',
        'question' => array(
            'id' => $question->id,
            'text' => $question->questiontext,
            'options' => $options,
            'number' => $session->currentquestion + 1,
            'total' => $session->numquestions,
            'timelimit' => $session->timelimit,
            'timeremaining' => $remaining,
            'answered' => $answered
        )
    );
}

/**
 * Submit student answer
 *
 * @param int $sessionid
 * @param int $questionid
 * @param string $answer
 * @param int $classengageid
 * @return array
 */
function submit_answer($sessionid, $questionid, $answer, $classengageid) {
    global $DB, $USER;
    
    $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
    
    if ($session->status !== 'active') {
        return array('success' => false, 'error' => 'Session not active');
    }
    
    // Check if already answered
    if ($DB->record_exists('classengage_responses', array(
        'sessionid' => $sessionid,
        'questionid' => $questionid,
        'userid' => $USER->id
    ))) {
        return array('success' => false, 'error' => 'Already answered');
    }
    
    // Get question
    $question = $DB->get_record('classengage_questions', array('id' => $questionid), '*', MUST_EXIST);
    
    // Check if answer is correct
    $iscorrect = (strtoupper($answer) === strtoupper($question->correctanswer));
    
    // Calculate response time
    $responsetime = time() - $session->questionstarttime;
    
    // Calculate score
    $score = $iscorrect ? 1 : 0;
    
    // Save response
    $response = new stdClass();
    $response->sessionid = $sessionid;
    $response->questionid = $questionid;
    $response->classengageid = $classengageid;
    $response->userid = $USER->id;
    $response->answer = $answer;
    $response->iscorrect = $iscorrect;
    $response->score = $score;
    $response->responsetime = $responsetime;
    $response->timecreated = time();
    
    $DB->insert_record('classengage_responses', $response);
    
    // Invalidate analytics cache
    $cm = get_coursemodule_from_instance('classengage', $classengageid);
    $context = context_module::instance($cm->id);
    $analytics = new \mod_classengage\analytics_engine($classengageid, $context);
    $analytics->invalidate_cache($sessionid);
    
    // Trigger event
    $event = \mod_classengage\event\question_answered::create(array(
        'objectid' => $questionid,
        'context' => $context,
        'other' => array(
            'sessionid' => $sessionid,
            'iscorrect' => $iscorrect
        )
    ));
    $event->trigger();
    
    return array(
        'success' => true,
        'iscorrect' => $iscorrect,
        'correctanswer' => $question->correctanswer
    );
}

/**
 * Get session statistics
 *
 * @param int $sessionid
 * @return array
 */
function get_session_stats($sessionid) {
    global $DB;
    
    $session = $DB->get_record('classengage_sessions', array('id' => $sessionid), '*', MUST_EXIST);
    
    // Count participants
    $sql = "SELECT COUNT(DISTINCT userid) FROM {classengage_responses} WHERE sessionid = ?";
    $participants = $DB->count_records_sql($sql, array($sessionid));
    
    // Get current question stats
    $currentquestion = $session->currentquestion + 1;
    
    if ($currentquestion > 0) {
        $sql = "SELECT q.id, q.questiontext,
                       COUNT(r.id) as responses,
                       SUM(r.iscorrect) as correct
                  FROM {classengage_questions} q
                  JOIN {classengage_session_questions} sq ON sq.questionid = q.id
                  LEFT JOIN {classengage_responses} r ON r.questionid = q.id AND r.sessionid = sq.sessionid
                 WHERE sq.sessionid = :sessionid
                   AND sq.questionorder = :questionorder
              GROUP BY q.id, q.questiontext";
        
        $stats = $DB->get_record_sql($sql, array(
            'sessionid' => $sessionid,
            'questionorder' => $currentquestion
        ));
    } else {
        $stats = null;
    }
    
    return array(
        'success' => true,
        'participants' => $participants,
        'currentquestion' => $currentquestion,
        'totalquestions' => $session->numquestions,
        'status' => $session->status,
        'stats' => $stats
    );
}

