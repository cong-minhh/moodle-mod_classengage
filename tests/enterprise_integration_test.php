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
 * Integration tests for mod_classengage enterprise features
 *
 * Tests the integration between enterprise components:
 * - Rate limiting with AJAX requests
 * - Health checker with live system
 * - Scheduled tasks with database state
 * - Cache definitions and behavior
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Enterprise integration tests
 */
class enterprise_integration_test extends \advanced_testcase
{

    /** @var \testing_data_generator */
    protected $generator;

    /** @var \mod_classengage_generator */
    protected $plugingenerator;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = $this->getDataGenerator();
        $this->plugingenerator = $this->generator->get_plugin_generator('mod_classengage');
    }

    // =========================================================================
    // Cache Integration Tests
    // =========================================================================

    /**
     * Test all cache definitions can be instantiated
     */
    public function test_cache_definitions_instantiation(): void
    {
        $this->resetAfterTest(true);

        $cachedefs = [
            'response_stats',
            'session_state',
            'connection_status',
            'question_broadcast',
            'analytics_summary',
            'question_data',
            'rate_limiting',
        ];

        foreach ($cachedefs as $def) {
            $cache = \cache::make('mod_classengage', $def);
            $this->assertNotNull($cache, "Cache '$def' should be instantiable");

            // Test basic operations.
            $testkey = 'integration_test_' . time();
            $testvalue = ['test' => true, 'def' => $def];

            $result = $cache->set($testkey, $testvalue);
            $this->assertTrue($result, "Cache '$def' set should succeed");

            $retrieved = $cache->get($testkey);
            $this->assertNotFalse($retrieved, "Cache '$def' get should succeed");
            $this->assertEquals($testvalue, $retrieved);

            $deleted = $cache->delete($testkey);
            $this->assertTrue($deleted, "Cache '$def' delete should succeed");
        }
    }

    /**
     * Test cache TTLs are correctly configured
     */
    public function test_cache_ttl_configuration(): void
    {
        $this->resetAfterTest(true);

        // Get cache definitions.
        $definitions = \cache_config::instance()->get_definitions();

        $expectedttls = [
            'mod_classengage/response_stats' => constants::CACHE_TTL_RESPONSE_STATS,
            'mod_classengage/session_state' => constants::CACHE_TTL_SESSION_STATE,
            'mod_classengage/connection_status' => constants::CACHE_TTL_CONNECTION_STATUS,
        ];

        foreach ($expectedttls as $defid => $expectedttl) {
            if (isset($definitions[$defid])) {
                $this->assertEquals(
                    $expectedttl,
                    $definitions[$defid]['ttl'],
                    "Cache '$defid' TTL should match constants"
                );
            }
        }
    }

    // =========================================================================
    // Rate Limiting Integration Tests
    // =========================================================================

    /**
     * Test rate limiter uses cache correctly
     */
    public function test_rate_limiter_cache_integration(): void
    {
        $this->resetAfterTest(true);

        $limiter = new rate_limiter(5, 60);

        // Consume some tokens.
        for ($i = 0; $i < 3; $i++) {
            $limiter->check(1, 'test');
        }

        // Create a new limiter instance (simulating different request).
        $limiter2 = new rate_limiter(5, 60);
        $result = $limiter2->peek(1, 'test');

        // Should see the consumed tokens.
        $this->assertEquals(2, $result->remaining);
    }

    /**
     * Test rate limiter integrates with multiple cache backends
     */
    public function test_rate_limiter_distributed_behavior(): void
    {
        $this->resetAfterTest(true);

        // Multiple limiters simulating multiple app servers.
        $limiter1 = new rate_limiter(10, 60);
        $limiter2 = new rate_limiter(10, 60);
        $limiter3 = new rate_limiter(10, 60);

        $userid = 999;

        // Distribute requests across "servers".
        $limiter1->check($userid, 'submit');
        $limiter2->check($userid, 'submit');
        $limiter3->check($userid, 'submit');
        $limiter1->check($userid, 'submit');
        $limiter2->check($userid, 'submit');

        // All should see the same state.
        $result1 = $limiter1->peek($userid, 'submit');
        $result2 = $limiter2->peek($userid, 'submit');
        $result3 = $limiter3->peek($userid, 'submit');

        $this->assertEquals($result1->remaining, $result2->remaining);
        $this->assertEquals($result2->remaining, $result3->remaining);
        $this->assertEquals(5, $result1->remaining);
    }

    // =========================================================================
    // Health Checker Integration Tests
    // =========================================================================

    /**
     * Test health checker with populated database
     */
    public function test_health_checker_with_data(): void
    {
        global $DB;
        $this->resetAfterTest(true);

        // Create substantial test data.
        $course = $this->generator->create_course();
        $classengage = $this->generator->create_module('classengage', [
            'course' => $course->id,
        ]);

        $teacher = $this->generator->create_user();
        $students = [];
        for ($i = 0; $i < 10; $i++) {
            $students[] = $this->generator->create_user();
        }

        // Create questions.
        $questions = [];
        for ($i = 0; $i < 5; $i++) {
            $questions[] = $this->plugingenerator->create_question($classengage->id);
        }

        // Create sessions with responses.
        $session = $this->plugingenerator->create_session($classengage->id, $teacher->id);
        $this->plugingenerator->link_questions_to_session(
            $session->id,
            array_map(fn($q) => $q->id, $questions)
        );

        foreach ($students as $student) {
            foreach ($questions as $question) {
                $this->plugingenerator->create_response(
                    $session->id,
                    $question->id,
                    $classengage->id,
                    $student->id
                );
            }
        }

        // Run health check.
        $checker = new health_checker($classengage->id);
        $metrics = $checker->get_metrics();

        $this->assertEquals(5, $metrics['activity']['questions']);
        $this->assertEquals(1, $metrics['activity']['sessions']);
        $this->assertEquals(50, $metrics['activity']['responses']); // 10 students x 5 questions.
    }

    /**
     * Test health report JSON is valid for monitoring systems
     */
    public function test_health_report_monitoring_format(): void
    {
        $this->resetAfterTest(true);

        $checker = new health_checker();
        $report = $checker->export_report();

        // Should be valid JSON.
        $decoded = json_decode($report, true);
        $this->assertNotNull($decoded);
        $this->assertIsArray($decoded);

        // Should have required fields for monitoring.
        $this->assertArrayHasKey('generated_at', $decoded);
        $this->assertArrayHasKey('overall_healthy', $decoded);
        $this->assertArrayHasKey('checks', $decoded);

        // Each check should have standard fields.
        foreach ($decoded['checks'] as $check) {
            $this->assertArrayHasKey('component', $check);
            $this->assertArrayHasKey('healthy', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertArrayHasKey('response_time_ms', $check);
        }
    }

    // =========================================================================
    // Scheduled Tasks Integration Tests
    // =========================================================================

    /**
     * Test stale connection cleanup with real database state
     */
    public function test_connection_cleanup_integration(): void
    {
        global $DB;
        $this->resetAfterTest(true);

        // Create test environment.
        $course = $this->generator->create_course();
        $classengage = $this->generator->create_module('classengage', [
            'course' => $course->id,
        ]);
        $teacher = $this->generator->create_user();
        $session = $this->plugingenerator->create_session($classengage->id, $teacher->id);

        // Create a mix of stale and active connections.
        $staletime = time() - constants::CONNECTION_STALE_TIMEOUT - 60;
        $activetime = time() - 5;

        for ($i = 0; $i < 5; $i++) {
            $student = $this->generator->create_user();
            $this->plugingenerator->create_connection($session->id, $student->id, [
                'timemodified' => $staletime,
                'status' => 'connected',
            ]);
        }

        for ($i = 0; $i < 3; $i++) {
            $student = $this->generator->create_user();
            $this->plugingenerator->create_connection($session->id, $student->id, [
                'timemodified' => $activetime,
                'status' => 'connected',
            ]);
        }

        // Verify initial state.
        $this->assertEquals(8, $DB->count_records('classengage_connections', [
            'sessionid' => $session->id,
            'status' => 'connected',
        ]));

        // Run cleanup task.
        $task = new task\cleanup_stale_connections();
        $task->execute();

        // Verify only active connections remain.
        $this->assertEquals(3, $DB->count_records('classengage_connections', [
            'sessionid' => $session->id,
            'status' => 'connected',
        ]));

        $this->assertEquals(5, $DB->count_records('classengage_connections', [
            'sessionid' => $session->id,
            'status' => 'disconnected',
        ]));
    }

    // =========================================================================
    // Full Workflow Integration Tests
    // =========================================================================

    /**
     * Test complete enterprise workflow
     *
     * Simulates a complete session lifecycle with enterprise features:
     * 1. Create session with questions
     * 2. Connect users (within rate limits)
     * 3. Submit responses
     * 4. Run analytics aggregation
     * 5. Check health status
     * 6. Clean up stale connections
     */
    public function test_complete_enterprise_workflow(): void
    {
        global $DB;
        $this->resetAfterTest(true);

        // 1. Setup.
        $course = $this->generator->create_course();
        $classengage = $this->generator->create_module('classengage', [
            'course' => $course->id,
        ]);
        $teacher = $this->generator->create_user();

        // Create questions.
        $questions = [];
        for ($i = 0; $i < 3; $i++) {
            $questions[] = $this->plugingenerator->create_question($classengage->id);
        }

        // Create and start session.
        $session = $this->plugingenerator->create_session($classengage->id, $teacher->id, [
            'status' => 'active',
        ]);
        $this->plugingenerator->link_questions_to_session(
            $session->id,
            array_map(fn($q) => $q->id, $questions)
        );

        // 2. Connect students with rate limiting.
        $limiter = new rate_limiter(100, 60);
        $students = [];
        for ($i = 0; $i < 20; $i++) {
            $student = $this->generator->create_user();
            $students[] = $student;

            // Rate limit check.
            $result = $limiter->check($student->id, 'connect');
            $this->assertTrue($result->allowed);

            // Create connection.
            $this->plugingenerator->create_connection($session->id, $student->id);
        }

        // 3. Submit responses.
        foreach ($students as $student) {
            foreach ($questions as $question) {
                $this->plugingenerator->create_response(
                    $session->id,
                    $question->id,
                    $classengage->id,
                    $student->id
                );
            }
        }

        // 4. Mark session as completed and run analytics.
        $DB->set_field('classengage_sessions', 'status', 'completed', ['id' => $session->id]);
        $DB->set_field('classengage_sessions', 'timecompleted', time(), ['id' => $session->id]);

        $task = new task\aggregate_analytics();
        $task->execute();

        // Check cache.
        $cache = \cache::make('mod_classengage', 'analytics_summary');
        $summary = $cache->get("session_summary_{$session->id}");
        $this->assertNotFalse($summary);
        $this->assertEquals(20, $summary->total_participants);

        // 5. Health check.
        $checker = new health_checker($classengage->id);
        $results = $checker->run_all_checks();
        $allhealthy = true;
        foreach ($results as $result) {
            if (!$result->healthy) {
                $allhealthy = false;
            }
        }
        $this->assertTrue($allhealthy, 'All health checks should pass');

        // 6. Simulate stale connections and cleanup.
        $staletime = time() - constants::CONNECTION_STALE_TIMEOUT - 60;
        $DB->set_field('classengage_connections', 'timemodified', $staletime);

        $cleanuptask = new task\cleanup_stale_connections();
        $cleanuptask->execute();

        $this->assertEquals(0, $DB->count_records('classengage_connections', [
            'sessionid' => $session->id,
            'status' => 'connected',
        ]));
    }

    // =========================================================================
    // Error Handling Integration Tests
    // =========================================================================

    /**
     * Test error codes are used consistently
     */
    public function test_error_code_consistency(): void
    {
        // All error codes should be unique and meaningful.
        $errorcodes = [
            'SESSION_NOT_FOUND' => constants::ERROR_SESSION_NOT_FOUND,
            'SESSION_NOT_ACTIVE' => constants::ERROR_SESSION_NOT_ACTIVE,
            'ALREADY_ANSWERED' => constants::ERROR_ALREADY_ANSWERED,
            'RATE_LIMIT_EXCEEDED' => constants::ERROR_RATE_LIMIT_EXCEEDED,
            'INVALID_ANSWER' => constants::ERROR_INVALID_ANSWER,
            'CONNECTION_TIMEOUT' => constants::ERROR_CONNECTION_TIMEOUT,
            'DATABASE' => constants::ERROR_DATABASE,
            'PERMISSION_DENIED' => constants::ERROR_PERMISSION_DENIED,
        ];

        $values = array_values($errorcodes);
        $this->assertEquals(count($values), count(array_unique($values)));
    }
}
