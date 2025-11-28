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
 * Unit tests for analytics_query_builder
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for analytics_query_builder class
 */
class analytics_query_builder_test extends \advanced_testcase {

    /**
     * Test basic query building without filters
     */
    public function test_build_basic_query() {
        $this->resetAfterTest(true);

        $filter = new analytics_filter([]);
        $builder = new analytics_query_builder(1, $filter);

        list($sql, $params, $countsql, $perpage, $offset) = $builder->build_student_performance_query();

        // Verify SQL contains required elements.
        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('FROM {classengage_responses}', $sql);
        $this->assertStringContainsString('JOIN {user}', $sql);
        $this->assertStringContainsString('GROUP BY', $sql);
        $this->assertStringContainsString('ORDER BY', $sql);

        // Verify params contain sessionid.
        $this->assertArrayHasKey('sessionid', $params);
        $this->assertEquals(1, $params['sessionid']);

        // Verify pagination defaults.
        $this->assertEquals(25, $perpage);
        $this->assertEquals(0, $offset);
    }

    /**
     * Test query with name search filter
     */
    public function test_build_query_with_name_search() {
        $this->resetAfterTest(true);

        $filter = new analytics_filter(['namesearch' => 'John']);
        $builder = new analytics_query_builder(1, $filter);

        list($sql, $params, $countsql, $perpage, $offset) = $builder->build_student_performance_query();

        // Verify name search parameter is included.
        $this->assertArrayHasKey('namesearch', $params);
        $this->assertStringContainsString('%John%', $params['namesearch']);
    }

    /**
     * Test query with score range filters
     */
    public function test_build_query_with_score_filters() {
        $this->resetAfterTest(true);

        $filter = new analytics_filter(['minscore' => 50, 'maxscore' => 90]);
        $builder = new analytics_query_builder(1, $filter);

        list($sql, $params, $countsql, $perpage, $offset) = $builder->build_student_performance_query();

        // Verify score parameters are included.
        $this->assertArrayHasKey('minscore', $params);
        $this->assertArrayHasKey('maxscore', $params);
        $this->assertEquals(50, $params['minscore']);
        $this->assertEquals(90, $params['maxscore']);

        // Verify HAVING clause is present.
        $this->assertStringContainsString('HAVING', $sql);
    }

    /**
     * Test query with response time filters
     */
    public function test_build_query_with_time_filters() {
        $this->resetAfterTest(true);

        $filter = new analytics_filter(['mintime' => 5, 'maxtime' => 30]);
        $builder = new analytics_query_builder(1, $filter);

        list($sql, $params, $countsql, $perpage, $offset) = $builder->build_student_performance_query();

        // Verify time parameters are included.
        $this->assertArrayHasKey('mintime', $params);
        $this->assertArrayHasKey('maxtime', $params);
        $this->assertEquals(5, $params['mintime']);
        $this->assertEquals(30, $params['maxtime']);
    }

    /**
     * Test query with top performers filter
     */
    public function test_build_query_with_top_performers() {
        $this->resetAfterTest(true);

        $filter = new analytics_filter(['toponly' => 1]);
        $builder = new analytics_query_builder(1, $filter);

        list($sql, $params, $countsql, $perpage, $offset) = $builder->build_student_performance_query();

        // Verify LIMIT 10 is present for top performers.
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    /**
     * Test query with question filter
     */
    public function test_build_query_with_question_filter() {
        $this->resetAfterTest(true);

        $filter = new analytics_filter(['questionid' => 5]);
        $builder = new analytics_query_builder(1, $filter);

        list($sql, $params, $countsql, $perpage, $offset) = $builder->build_student_performance_query();

        // Verify question parameter is included.
        $this->assertArrayHasKey('questionid', $params);
        $this->assertEquals(5, $params['questionid']);
    }

    /**
     * Test query with custom sorting
     */
    public function test_build_query_with_sorting() {
        $this->resetAfterTest(true);

        $filter = new analytics_filter(['sort' => 'totalresponses', 'dir' => 'ASC']);
        $builder = new analytics_query_builder(1, $filter);

        list($sql, $params, $countsql, $perpage, $offset) = $builder->build_student_performance_query();

        // Verify ORDER BY clause contains the sort column.
        $this->assertStringContainsString('ORDER BY', $sql);
        $this->assertStringContainsString('totalresponses', $sql);
        $this->assertStringContainsString('ASC', $sql);
    }

    /**
     * Test query with pagination
     */
    public function test_build_query_with_pagination() {
        $this->resetAfterTest(true);

        $filter = new analytics_filter(['page' => 3, 'perpage' => 50]);
        $builder = new analytics_query_builder(1, $filter);

        list($sql, $params, $countsql, $perpage, $offset) = $builder->build_student_performance_query();

        // Verify pagination values.
        $this->assertEquals(50, $perpage);
        $this->assertEquals(100, $offset); // Page 3 with 50 per page = offset 100.
    }

    /**
     * Test query with multiple filters combined
     */
    public function test_build_query_with_multiple_filters() {
        $this->resetAfterTest(true);

        $filter = new analytics_filter([
            'namesearch' => 'Smith',
            'minscore' => 60,
            'maxscore' => 95,
            'mintime' => 10,
            'maxtime' => 25,
            'sort' => 'percentage',
            'dir' => 'DESC',
            'page' => 2,
            'perpage' => 10
        ]);
        $builder = new analytics_query_builder(1, $filter);

        list($sql, $params, $countsql, $perpage, $offset) = $builder->build_student_performance_query();

        // Verify all parameters are included.
        $this->assertArrayHasKey('namesearch', $params);
        $this->assertArrayHasKey('minscore', $params);
        $this->assertArrayHasKey('maxscore', $params);
        $this->assertArrayHasKey('mintime', $params);
        $this->assertArrayHasKey('maxtime', $params);

        // Verify pagination.
        $this->assertEquals(10, $perpage);
        $this->assertEquals(10, $offset); // Page 2 with 10 per page = offset 10.
    }

    /**
     * Test count query structure
     */
    public function test_count_query_structure() {
        $this->resetAfterTest(true);

        $filter = new analytics_filter(['minscore' => 70]);
        $builder = new analytics_query_builder(1, $filter);

        list($sql, $params, $countsql, $perpage, $offset) = $builder->build_student_performance_query();

        // Verify count query is a SELECT COUNT(*).
        $this->assertStringContainsString('SELECT COUNT(*)', $countsql);
        $this->assertStringContainsString('FROM', $countsql);
    }
}
