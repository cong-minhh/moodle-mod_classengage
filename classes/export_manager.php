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

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

use mod_classengage\engagement_calculator;
use mod_classengage\comprehension_analyzer;
use mod_classengage\teaching_recommender;

/**
 * Export manager class
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_manager {

    /** @var int Session ID */
    protected $sessionid;
    
    /** @var int Course ID */
    protected $courseid;

    /**
     * Constructor
     *
     * @param int $sessionid
     * @param int $courseid
     */
    public function __construct($sessionid, $courseid) {
        $this->sessionid = $sessionid;
        $this->courseid = $courseid;
    }

    /**
     * Get session summary data
     *
     * @return array
     */
    public function get_session_summary_data() {
        global $DB;
        
        $session = $DB->get_record('classengage_sessions', ['id' => $this->sessionid], '*', MUST_EXIST);
        
        // Instantiate analytics components
        $engagementcalculator = new engagement_calculator($this->sessionid, $this->courseid);
        $engagement = $engagementcalculator->calculate_engagement_level();
        $activitycounts = $engagementcalculator->get_activity_counts();
        $responsiveness = $engagementcalculator->get_responsiveness_indicator();
        
        $comprehensionanalyzer = new comprehension_analyzer($this->sessionid);
        $comprehension = $comprehensionanalyzer->get_comprehension_summary();
        $conceptdifficulty = $comprehensionanalyzer->get_concept_difficulty();
        
        $teachingrecommender = new teaching_recommender($this->sessionid, $engagement, $comprehension);
        $recommendations = $teachingrecommender->generate_recommendations();
        
        // Format lists
        $confusedtopics = '';
        if (!empty($comprehension->confused_topics)) {
            $confusedtopics = implode('; ', array_map('strip_tags', $comprehension->confused_topics));
        }
        
        $difficultconcepts = '';
        if (!empty($conceptdifficulty)) {
            $difficultlist = [];
            foreach ($conceptdifficulty as $concept) {
                if ($concept->difficulty_level === 'difficult') {
                    $difficultlist[] = strip_tags($concept->question_text) . ' (' . round($concept->correctness_rate, 1) . '%)';
                }
            }
            $difficultconcepts = implode('; ', $difficultlist);
        }
        
        $recommendationstext = '';
        if (!empty($recommendations)) {
            $recommendationstext = implode('; ', array_map(function($rec) {
                return strip_tags($rec->message);
            }, $recommendations));
        }
        
        $row = new \stdClass();
        $row->sessionname = $session->name;
        $row->completeddate = $session->timecompleted ? userdate($session->timecompleted) : '-';
        $row->engagementpercentage = round($engagement->percentage, 1) . '%';
        $row->engagementlevel = $engagement->level;
        $row->comprehensionsummary = $comprehension->message . ($confusedtopics ? ' (' . $confusedtopics . ')' : '');
        $row->questionsanswered = $activitycounts->questions_answered;
        $row->pollsubmissions = $activitycounts->poll_submissions;
        $row->reactions = $activitycounts->reactions;
        $row->responsiveness = $responsiveness->pace;
        $row->avgresponsetime = round($responsiveness->avg_time, 1) . 's';
        $row->difficultconcepts = $difficultconcepts ?: get_string('none');
        $row->recommendations = $recommendationstext ?: get_string('none');
        
        return [$row];
    }
    
    /**
     * Get student participation data
     *
     * @return array
     */
    public function get_student_participation_data() {
        global $DB;
        
        $sql = "SELECT u.id, u.firstname, u.lastname, u.email,
                       COUNT(r.id) as responsecount,
                       SUM(r.iscorrect) as correctcount,
                       AVG(r.responsetime) as avgtime
                FROM {user} u
                JOIN {classengage_responses} r ON r.userid = u.id
                WHERE r.sessionid = :sessionid
                GROUP BY u.id, u.firstname, u.lastname, u.email
                ORDER BY u.lastname, u.firstname";
                
        $records = $DB->get_records_sql($sql, ['sessionid' => $this->sessionid]);
        
        $data = [];
        foreach ($records as $record) {
            $row = new \stdClass();
            $row->fullname = fullname($record);
            $row->email = $record->email;
            $row->responsecount = $record->responsecount;
            $row->correctcount = $record->correctcount;
            $row->score = $record->responsecount > 0 ? round(($record->correctcount / $record->responsecount) * 100, 1) . '%' : '0%';
            $row->avgtime = round((float)$record->avgtime, 1) . 's';
            $data[] = $row;
        }
        
        return $data;
    }
    
    /**
     * Get question analysis data
     *
     * @return array
     */
    public function get_question_analysis_data() {
        global $DB;
        
        $sql = "SELECT q.id, q.questiontext, q.questiontype, q.correctanswer,
                       COUNT(r.id) as totalresponses,
                       SUM(r.iscorrect) as correctresponses,
                       AVG(r.responsetime) as avgtime
                FROM {classengage_questions} q
                JOIN {classengage_session_questions} sq ON sq.questionid = q.id
                LEFT JOIN {classengage_responses} r ON r.questionid = q.id AND r.sessionid = sq.sessionid
                WHERE sq.sessionid = :sessionid
                GROUP BY q.id, q.questiontext, q.questiontype, q.correctanswer
                ORDER BY sq.questionorder";
                
        $records = $DB->get_records_sql($sql, ['sessionid' => $this->sessionid]);
        
        $data = [];
        foreach ($records as $record) {
            $row = new \stdClass();
            $row->question = strip_tags($record->questiontext);
            $row->type = $record->questiontype;
            $row->correctanswer = $record->correctanswer;
            $row->totalresponses = $record->totalresponses;
            $row->correctresponses = $record->correctresponses;
            $row->correctnessrate = $record->totalresponses > 0 ? round(($record->correctresponses / $record->totalresponses) * 100, 1) . '%' : '0%';
            $row->avgtime = round((float)$record->avgtime, 1) . 's';
            $data[] = $row;
        }
        
        return $data;
    }
    
    /**
     * Get raw response data
     *
     * @return array
     */
    public function get_raw_response_data() {
        global $DB;
        
        $sql = "SELECT r.id, u.username, q.questiontext, r.answer, r.iscorrect, r.responsetime, r.timecreated
                FROM {classengage_responses} r
                JOIN {user} u ON u.id = r.userid
                JOIN {classengage_questions} q ON q.id = r.questionid
                WHERE r.sessionid = :sessionid
                ORDER BY r.timecreated";
                
        $records = $DB->get_records_sql($sql, ['sessionid' => $this->sessionid]);
        
        $data = [];
        foreach ($records as $record) {
            $row = new \stdClass();
            $row->username = $record->username;
            $row->question = strip_tags($record->questiontext);
            $row->answer = $record->answer;
            $row->iscorrect = $record->iscorrect ? get_string('yes') : get_string('no');
            $row->responsetime = $record->responsetime . 's';
            $row->timestamp = userdate($record->timecreated);
            $data[] = $row;
        }
        
        return $data;
    }
}
