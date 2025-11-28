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
 * Analytics query builder class for dynamic SQL construction
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Analytics query builder class
 *
 * Constructs dynamic SQL queries with filters, sorting, and pagination.
 */
class analytics_query_builder {

    /** @var int Session ID */
    protected $sessionid;

    /** @var analytics_filter Filter object */
    protected $filter;

    /**
     * Constructor
     *
     * @param int $sessionid Session ID
     * @param analytics_filter $filter Filter object
     */
    public function __construct($sessionid, analytics_filter $filter) {
        $this->sessionid = $sessionid;
        $this->filter = $filter;
    }

    /**
     * Build student performance query with filters, sorting, and pagination
     *
     * @return array [sql, params, countsql, perpage, offset] SQL query, parameters, count query, and pagination info
     */
    public function build_student_performance_query() {
        global $DB;

        $params = ['sessionid' => $this->sessionid];
        $whereclauses = ['r.sessionid = :sessionid'];
        $havingclauses = [];

        // Name search filter.
        $namesearch = $this->filter->get_name_search();
        if ($namesearch !== null) {
            $whereclauses[] = $DB->sql_like(
                $DB->sql_concat('u.firstname', "' '", 'u.lastname'),
                ':namesearch',
                false
            );
            $params['namesearch'] = '%' . $DB->sql_like_escape($namesearch) . '%';
        }

        // Question filter - only show students who answered this specific question.
        $questionfilter = $this->filter->get_question_filter();
        if ($questionfilter !== null) {
            $whereclauses[] = 'r.questionid = :questionid';
            $params['questionid'] = $questionfilter;
        }

        // Build WHERE clause.
        $whereclause = implode(' AND ', $whereclauses);

        // Score range filters (applied in HAVING clause after aggregation).
        $minscore = $this->filter->get_min_score();
        if ($minscore !== null) {
            $havingclauses[] = 'percentage >= :minscore';
            $params['minscore'] = $minscore;
        }

        $maxscore = $this->filter->get_max_score();
        if ($maxscore !== null) {
            $havingclauses[] = 'percentage <= :maxscore';
            $params['maxscore'] = $maxscore;
        }

        // Response time filters (applied in HAVING clause after aggregation).
        $mintime = $this->filter->get_min_response_time();
        if ($mintime !== null) {
            $havingclauses[] = 'avg_response_time >= :mintime';
            $params['mintime'] = $mintime;
        }

        $maxtime = $this->filter->get_max_response_time();
        if ($maxtime !== null) {
            $havingclauses[] = 'avg_response_time <= :maxtime';
            $params['maxtime'] = $maxtime;
        }

        // Build HAVING clause.
        $havingclause = '';
        if (!empty($havingclauses)) {
            $havingclause = 'HAVING ' . implode(' AND ', $havingclauses);
        }

        // Build base aggregation query.
        $basesql = "SELECT 
                        r.userid,
                        u.firstname,
                        u.lastname,
                        COUNT(*) as totalresponses,
                        SUM(r.iscorrect) as correctresponses,
                        (SUM(r.iscorrect) * 100.0 / COUNT(*)) as percentage,
                        AVG(r.responsetime) as avg_response_time
                      FROM {classengage_responses} r
                      JOIN {user} u ON u.id = r.userid
                     WHERE {$whereclause}
                  GROUP BY r.userid, u.firstname, u.lastname
                  {$havingclause}";

        // Handle top performers filter - limit to top 10 by percentage.
        $topperformersonly = $this->filter->get_top_performers_only();
        
        // Build count query (counts total matching records before pagination).
        if ($topperformersonly) {
            // For top performers, count is always min(10, total_students).
            $countsql = "SELECT COUNT(*) FROM (
                            SELECT * FROM ({$basesql}) base 
                            ORDER BY percentage DESC 
                            LIMIT 10
                         ) countquery";
        } else {
            $countsql = "SELECT COUNT(*) FROM ({$basesql}) countquery";
        }

        // Build sort clause with column validation.
        $sortcolumn = $this->filter->get_sort_column();
        $sortdirection = $this->filter->get_sort_direction();

        // Map filter column names to actual query column names.
        $sortmap = [
            'fullname' => 'lastname, firstname',  // Sort by last name first, then first name.
            'totalresponses' => 'totalresponses',
            'correctresponses' => 'correctresponses',
            'percentage' => 'percentage',
            'avgresponsetime' => 'avg_response_time'
        ];

        $sortcolumnname = isset($sortmap[$sortcolumn]) ? $sortmap[$sortcolumn] : 'percentage';

        // Apply top performers filter and sorting.
        if ($topperformersonly) {
            // Top performers: always sort by percentage DESC, limit to 10.
            $sql = "SELECT * FROM ({$basesql}) base 
                    ORDER BY percentage DESC 
                    LIMIT 10";
            // Then apply user's requested sort on those top 10.
            $sql = "SELECT * FROM ({$sql}) topten 
                    ORDER BY {$sortcolumnname} {$sortdirection}";
        } else {
            // Normal case: apply user's sort directly.
            $sql = $basesql . " ORDER BY {$sortcolumnname} {$sortdirection}";
        }

        // Add pagination with LIMIT and OFFSET.
        $page = $this->filter->get_page();
        $perpage = $this->filter->get_per_page();
        $offset = ($page - 1) * $perpage;

        return [$sql, $params, $countsql, $perpage, $offset];
    }

    /**
     * Build question breakdown query
     *
     * @return array [sql, params]
     */
    public function build_question_breakdown_query() {
        $params = ['sessionid' => $this->sessionid];

        $sql = "SELECT 
                    q.id as questionid,
                    sq.questionorder,
                    q.questiontext,
                    q.correctanswer,
                    COUNT(r.id) as totalresponses,
                    SUM(r.iscorrect) as correctresponses,
                    (SUM(r.iscorrect) * 100.0 / COUNT(r.id)) as successrate,
                    (100.0 - (SUM(r.iscorrect) * 100.0 / COUNT(r.id))) as difficulty,
                    AVG(r.responsetime) as avgresponsetime
                  FROM {classengage_questions} q
                  JOIN {classengage_session_questions} sq ON sq.questionid = q.id
             LEFT JOIN {classengage_responses} r ON r.questionid = q.id AND r.sessionid = :sessionid
                 WHERE sq.sessionid = :sessionid2
              GROUP BY q.id, sq.questionorder, q.questiontext, q.correctanswer
              ORDER BY sq.questionorder";

        $params['sessionid2'] = $this->sessionid;

        return [$sql, $params];
    }

    /**
     * Build insights query for at-risk students and top performers
     *
     * @return array [sql, params]
     */
    public function build_insights_query() {
        $params = ['sessionid' => $this->sessionid];

        $sql = "SELECT 
                    r.userid,
                    u.firstname,
                    u.lastname,
                    COUNT(*) as totalresponses,
                    SUM(r.iscorrect) as correctresponses,
                    (SUM(r.iscorrect) * 100.0 / COUNT(*)) as percentage,
                    AVG(r.responsetime) as avg_response_time
                  FROM {classengage_responses} r
                  JOIN {user} u ON u.id = r.userid
                 WHERE r.sessionid = :sessionid
              GROUP BY r.userid, u.firstname, u.lastname";

        return [$sql, $params];
    }

    /**
     * Build engagement timeline query
     *
     * @param int $intervals Number of time intervals
     * @return array [sql, params]
     */
    public function build_engagement_timeline_query($intervals = 10) {
        $params = [
            'sessionid' => $this->sessionid,
            'intervals' => $intervals
        ];

        $sql = "SELECT 
                    timecreated,
                    COUNT(*) as count
                  FROM {classengage_responses}
                 WHERE sessionid = :sessionid
              GROUP BY timecreated
              ORDER BY timecreated";

        return [$sql, $params];
    }

    /**
     * Build score distribution query
     *
     * @return array [sql, params]
     */
    public function build_score_distribution_query() {
        $params = ['sessionid' => $this->sessionid];

        $sql = "SELECT 
                    r.userid,
                    (SUM(r.iscorrect) * 100.0 / COUNT(*)) as percentage
                  FROM {classengage_responses} r
                 WHERE r.sessionid = :sessionid
              GROUP BY r.userid";

        return [$sql, $params];
    }
}
