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
 * Unit tests for mod_classengage health checker
 *
 * Tests the enterprise health monitoring functionality including:
 * - Database connectivity checks
 * - Cache system verification
 * - SSE capability detection
 * - Table existence checks
 * - System metrics collection
 * - Health report generation
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_classengage\health_checker
 * @covers     \mod_classengage\health_check_result
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Health checker unit tests
 *
 * @group mod_classengage
 * @group mod_classengage_unit
 */
class health_checker_test extends \advanced_testcase
{

    /**
     * Test health check result object construction
     */
    public function test_health_check_result_construction(): void
    {
        $result = new health_check_result(
            'database',
            true,
            'Database is healthy',
            15.5,
            ['queries' => 10]
        );

        $this->assertEquals('database', $result->component);
        $this->assertTrue($result->healthy);
        $this->assertEquals('Database is healthy', $result->message);
        $this->assertEquals(15.5, $result->response_time_ms);
        $this->assertEquals(['queries' => 10], $result->details);
    }

    /**
     * Test health check result to_array method
     */
    public function test_health_check_result_to_array(): void
    {
        $result = new health_check_result(
            'cache',
            false,
            'Cache failure',
            100.0,
            ['error' => 'Connection failed']
        );

        $array = $result->to_array();

        $this->assertArrayHasKey('component', $array);
        $this->assertArrayHasKey('healthy', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('response_time_ms', $array);
        $this->assertArrayHasKey('details', $array);

        $this->assertEquals('cache', $array['component']);
        $this->assertFalse($array['healthy']);
    }

    /**
     * Test database health check passes with valid connection
     */
    public function test_check_database_healthy(): void
    {
        $this->resetAfterTest(true);

        $checker = new health_checker();
        $result = $checker->check_database();

        $this->assertEquals('database', $result->component);
        $this->assertTrue($result->healthy);
        $this->assertStringContainsString('healthy', strtolower($result->message));
        $this->assertGreaterThan(0, $result->response_time_ms);
        $this->assertArrayHasKey('test_query_ms', $result->details);
    }

    /**
     * Test cache health check with all cache definitions
     */
    public function test_check_cache_healthy(): void
    {
        $this->resetAfterTest(true);

        $checker = new health_checker();
        $result = $checker->check_cache();

        $this->assertEquals('cache', $result->component);
        $this->assertIsBool($result->healthy);
        $this->assertGreaterThan(0, $result->response_time_ms);
        $this->assertArrayHasKey('caches', $result->details);

        // Verify individual cache definitions are tested.
        $caches = $result->details['caches'];
        $this->assertArrayHasKey('response_stats', $caches);
        $this->assertArrayHasKey('session_state', $caches);
        $this->assertArrayHasKey('connection_status', $caches);
        $this->assertArrayHasKey('question_broadcast', $caches);
    }

    /**
     * Test SSE capability check
     */
    public function test_check_sse_capability(): void
    {
        $this->resetAfterTest(true);

        $checker = new health_checker();
        $result = $checker->check_sse_capability();

        $this->assertEquals('sse_capability', $result->component);
        $this->assertIsBool($result->healthy);
        $this->assertArrayHasKey('checks', $result->details);
        $this->assertArrayHasKey('issues', $result->details);

        // Check for expected capability checks.
        $checks = $result->details['checks'];
        $this->assertArrayHasKey('set_time_limit_available', $checks);
        $this->assertArrayHasKey('flush_available', $checks);
    }

    /**
     * Test table existence check
     */
    public function test_check_tables(): void
    {
        $this->resetAfterTest(true);

        $checker = new health_checker();
        $result = $checker->check_tables();

        $this->assertEquals('tables', $result->component);
        $this->assertTrue($result->healthy);
        $this->assertArrayHasKey('tables', $result->details);

        // Verify all required tables are checked.
        $tables = $result->details['tables'];
        $requiredtables = [
            'classengage',
            'classengage_slides',
            'classengage_questions',
            'classengage_sessions',
            'classengage_responses',
            'classengage_connections',
        ];

        foreach ($requiredtables as $table) {
            $this->assertArrayHasKey($table, $tables);
            $this->assertTrue($tables[$table], "Table $table should exist");
        }
    }

    /**
     * Test disk space check
     */
    public function test_check_disk_space(): void
    {
        $this->resetAfterTest(true);

        $checker = new health_checker();
        $result = $checker->check_disk_space();

        $this->assertEquals('disk_space', $result->component);
        $this->assertIsBool($result->healthy);
        $this->assertArrayHasKey('free_gb', $result->details);
        $this->assertArrayHasKey('total_gb', $result->details);
        $this->assertArrayHasKey('used_percent', $result->details);

        // Verify reasonable values.
        $this->assertGreaterThan(0, $result->details['total_gb']);
        $this->assertGreaterThanOrEqual(0, $result->details['free_gb']);
        $this->assertLessThanOrEqual(100, $result->details['used_percent']);
    }

    /**
     * Test memory check
     */
    public function test_check_memory(): void
    {
        $this->resetAfterTest(true);

        $checker = new health_checker();
        $result = $checker->check_memory();

        $this->assertEquals('memory', $result->component);
        $this->assertIsBool($result->healthy);
        $this->assertArrayHasKey('current_mb', $result->details);
        $this->assertArrayHasKey('peak_mb', $result->details);
        $this->assertArrayHasKey('limit_mb', $result->details);
        $this->assertArrayHasKey('used_percent', $result->details);

        // Verify reasonable values.
        $this->assertGreaterThan(0, $result->details['current_mb']);
        $this->assertGreaterThanOrEqual(
            $result->details['current_mb'],
            $result->details['peak_mb']
        );
    }

    /**
     * Test run_all_checks returns all expected checks
     */
    public function test_run_all_checks(): void
    {
        $this->resetAfterTest(true);

        $checker = new health_checker();
        $results = $checker->run_all_checks();

        $this->assertIsArray($results);
        $this->assertCount(6, $results);

        // Verify all results are health_check_result objects.
        foreach ($results as $result) {
            $this->assertInstanceOf(health_check_result::class, $result);
        }

        // Extract component names.
        $components = array_map(fn($r) => $r->component, $results);

        $this->assertContains('database', $components);
        $this->assertContains('cache', $components);
        $this->assertContains('sse_capability', $components);
        $this->assertContains('tables', $components);
        $this->assertContains('disk_space', $components);
        $this->assertContains('memory', $components);
    }

    /**
     * Test get_metrics returns expected structure
     */
    public function test_get_metrics(): void
    {
        $this->resetAfterTest(true);

        $checker = new health_checker();
        $metrics = $checker->get_metrics();

        $this->assertArrayHasKey('timestamp', $metrics);
        $this->assertArrayHasKey('php_version', $metrics);
        $this->assertArrayHasKey('moodle_version', $metrics);
        $this->assertArrayHasKey('global', $metrics);

        // Verify global metrics structure.
        $global = $metrics['global'];
        $this->assertArrayHasKey('total_activities', $global);
        $this->assertArrayHasKey('total_sessions', $global);
        $this->assertArrayHasKey('total_responses', $global);
        $this->assertArrayHasKey('active_connections', $global);
        $this->assertArrayHasKey('pending_queue', $global);
    }

    /**
     * Test get_metrics with activity ID includes activity metrics
     */
    public function test_get_metrics_with_activity(): void
    {
        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->getDataGenerator()->create_course();
        $classengage = $this->getDataGenerator()->create_module('classengage', [
            'course' => $course->id,
        ]);

        $checker = new health_checker($classengage->id);
        $metrics = $checker->get_metrics();

        $this->assertArrayHasKey('activity', $metrics);
        $this->assertEquals($classengage->id, $metrics['activity']['id']);
        $this->assertArrayHasKey('questions', $metrics['activity']);
        $this->assertArrayHasKey('sessions', $metrics['activity']);
        $this->assertArrayHasKey('responses', $metrics['activity']);
    }

    /**
     * Test export_report generates valid JSON
     */
    public function test_export_report(): void
    {
        $this->resetAfterTest(true);

        $checker = new health_checker();
        $report = $checker->export_report();

        $this->assertIsString($report);

        // Verify it's valid JSON.
        $decoded = json_decode($report, true);
        $this->assertNotNull($decoded);

        $this->assertArrayHasKey('generated_at', $decoded);
        $this->assertArrayHasKey('overall_healthy', $decoded);
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertArrayHasKey('metrics', $decoded);

        // Verify checks are included.
        $this->assertIsArray($decoded['checks']);
        $this->assertCount(6, $decoded['checks']);
    }

    /**
     * Test export_report overall_healthy reflects component statuses
     */
    public function test_export_report_overall_healthy(): void
    {
        $this->resetAfterTest(true);

        $checker = new health_checker();
        $report = json_decode($checker->export_report(), true);

        // If all checks pass, overall_healthy should be true.
        $allhealthy = true;
        foreach ($report['checks'] as $check) {
            if (!$check['healthy']) {
                $allhealthy = false;
                break;
            }
        }

        $this->assertEquals($allhealthy, $report['overall_healthy']);
    }

    /**
     * Test health checker with populated test data
     */
    public function test_metrics_with_test_data(): void
    {
        global $DB;
        $this->resetAfterTest(true);

        // Create test data.
        $course = $this->getDataGenerator()->create_course();
        $classengage = $this->getDataGenerator()->create_module('classengage', [
            'course' => $course->id,
        ]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

        // Create multiple questions.
        $question1 = $generator->create_question($classengage->id);
        $question2 = $generator->create_question($classengage->id);

        // Create and start a session.
        $teacher = $this->getDataGenerator()->create_user();
        $session = $generator->create_session($classengage->id, $teacher->id);
        $generator->link_questions_to_session($session->id, [$question1->id, $question2->id]);

        // Create student responses.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();

        $generator->create_response($session->id, $question1->id, $classengage->id, $student1->id);
        $generator->create_response($session->id, $question1->id, $classengage->id, $student2->id);

        // Get metrics.
        $checker = new health_checker($classengage->id);
        $metrics = $checker->get_metrics();

        $this->assertEquals(2, $metrics['activity']['questions']);
        $this->assertEquals(1, $metrics['activity']['sessions']);
        $this->assertEquals(2, $metrics['activity']['responses']);
    }
}
