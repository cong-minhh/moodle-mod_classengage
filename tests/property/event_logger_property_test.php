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
 * Property-based tests
 *
 * @group mod_classengage
 * @group mod_classengage_property for mod_classengage event logger
 *
 * These tests verify correctness properties that should hold across all valid inputs.
 * Each test runs multiple iterations with randomly generated inputs.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Property-based tests
 *
 * @group mod_classengage
 * @group mod_classengage_property for event logger
 *
 * Uses PHPUnit data providers to simulate property-based testing with random inputs.
 * Each property test runs a minimum of 100 iterations as specified in the design document.
 */
class event_logger_property_test extends \advanced_testcase {

    /** @var int Number of iterations for property tests */
    const PROPERTY_TEST_ITERATIONS = 100;

    /** @var \stdClass Course for testing */
    protected $course;

    /** @var \stdClass User for testing */
    protected $user;

    /** @var \stdClass ClassEngage instance */
    protected $classengage;

    /** @var \mod_classengage_generator Test data generator */
    protected $generator;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $this->course = $this->getDataGenerator()->create_course();
        $this->user = $this->getDataGenerator()->create_user();
        $this->classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $this->course->id]);
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
    }

    /**
     * Generate random latency value in milliseconds
     *
     * @return int Latency in milliseconds (0-2000ms range)
     */
    protected function generate_random_latency(): int {
        return rand(0, 2000);
    }

    /**
     * Generate random success status
     *
     * @return bool
     */
    protected function generate_random_success(): bool {
        return rand(0, 1) === 1;
    }

    /**
     * Create an active session for testing
     *
     * @return \stdClass Session object
     */
    protected function create_active_session(): \stdClass {
        return $this->generator->create_session($this->classengage->id, $this->user->id, [
            'status' => 'active',
            'questionstarttime' => time(),
            'timelimit' => 30,
        ]);
    }

    /**
     * **Feature: realtime-quiz-engine, Property 16: Response Logging**
     *
     * For any response received by the server, a log entry SHALL be created
     * containing timestamp, calculated latency, and success/failure status.
     *
     * **Validates: Requirements 7.2**
     *
     * @covers \mod_classengage\event_logger::log_response_submission
     */
    public function test_property_response_logging(): void {
        global $DB;

        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create fresh test fixtures for each iteration.
            $course = $this->getDataGenerator()->create_course();
            $user = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            $session = $generator->create_session($classengage->id, $user->id, [
                'status' => 'active',
                'questionstarttime' => time(),
                'timelimit' => 30,
            ]);

            // Generate random response data.
            $latencyms = $this->generate_random_latency();
            $success = $this->generate_random_success();
            $questionid = rand(1, 1000);

            $responsedata = [
                'latency_ms' => $latencyms,
                'success' => $success,
                'questionid' => $questionid,
                'answer' => ['A', 'B', 'C', 'D'][array_rand(['A', 'B', 'C', 'D'])],
            ];

            // Log the response.
            $logger = new event_logger();
            $beforetime = time();
            $logid = $logger->log_response_submission($session->id, $user->id, $responsedata);
            $aftertime = time();

            // Verify log entry was created.
            $this->assertGreaterThan(
                0,
                $logid,
                "Iteration $i: Log entry should be created with valid ID"
            );

            // Retrieve the log entry.
            $logentry = $DB->get_record('classengage_session_log', ['id' => $logid]);

            $this->assertNotFalse(
                $logentry,
                "Iteration $i: Log entry should exist in database"
            );

            // Verify timestamp is present and within expected range.
            $this->assertGreaterThanOrEqual(
                $beforetime,
                $logentry->timecreated,
                "Iteration $i: Log timestamp should be >= start time"
            );
            $this->assertLessThanOrEqual(
                $aftertime,
                $logentry->timecreated,
                "Iteration $i: Log timestamp should be <= end time"
            );

            // Verify latency is recorded.
            $this->assertEquals(
                $latencyms,
                $logentry->latency_ms,
                "Iteration $i: Log entry should contain correct latency value"
            );

            // Verify event type is correct.
            $this->assertEquals(
                event_logger::EVENT_RESPONSE_SUBMIT,
                $logentry->event_type,
                "Iteration $i: Log entry should have correct event type"
            );

            // Verify session ID is correct.
            $this->assertEquals(
                $session->id,
                $logentry->sessionid,
                "Iteration $i: Log entry should have correct session ID"
            );

            // Verify user ID is correct.
            $this->assertEquals(
                $user->id,
                $logentry->userid,
                "Iteration $i: Log entry should have correct user ID"
            );

            // Verify event data contains success status.
            $eventdata = json_decode($logentry->event_data, true);
            $this->assertIsArray(
                $eventdata,
                "Iteration $i: Event data should be valid JSON"
            );
            $this->assertArrayHasKey(
                'success',
                $eventdata,
                "Iteration $i: Event data should contain success status"
            );
            $this->assertEquals(
                $success,
                $eventdata['success'],
                "Iteration $i: Event data should contain correct success status"
            );
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 16: Response Logging (Performance Warning)**
     *
     * For any response with latency exceeding the threshold, a performance warning
     * log entry SHALL also be created.
     *
     * **Validates: Requirements 7.2, 7.4**
     *
     * @covers \mod_classengage\event_logger::log_response_submission
     * @covers \mod_classengage\event_logger::log_performance_warning
     */
    public function test_property_high_latency_triggers_warning(): void {
        global $DB;

        $warningthreshold = event_logger::LATENCY_WARNING_THRESHOLD_MS;

        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create fresh test fixtures.
            $course = $this->getDataGenerator()->create_course();
            $user = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            $session = $generator->create_session($classengage->id, $user->id, [
                'status' => 'active',
                'questionstarttime' => time(),
                'timelimit' => 30,
            ]);

            // Generate latency that exceeds threshold.
            $highlatency = $warningthreshold + rand(1, 1000);

            $responsedata = [
                'latency_ms' => $highlatency,
                'success' => true,
                'questionid' => rand(1, 1000),
            ];

            // Log the response.
            $logger = new event_logger();
            $logger->log_response_submission($session->id, $user->id, $responsedata);

            // Verify performance warning was created.
            $warnings = $DB->get_records('classengage_session_log', [
                'sessionid' => $session->id,
                'event_type' => event_logger::EVENT_PERFORMANCE_WARNING,
            ]);

            $this->assertNotEmpty(
                $warnings,
                "Iteration $i: Performance warning should be created for high latency ({$highlatency}ms > {$warningthreshold}ms)"
            );

            // Verify warning contains latency information.
            $warning = reset($warnings);
            $warningdata = json_decode($warning->event_data, true);

            $this->assertArrayHasKey(
                'type',
                $warningdata,
                "Iteration $i: Warning should contain type"
            );
            $this->assertEquals(
                'high_latency',
                $warningdata['type'],
                "Iteration $i: Warning type should be 'high_latency'"
            );
            $this->assertArrayHasKey(
                'latency_ms',
                $warningdata,
                "Iteration $i: Warning should contain latency_ms"
            );
            $this->assertEquals(
                $highlatency,
                $warningdata['latency_ms'],
                "Iteration $i: Warning should contain correct latency value"
            );
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 16: Response Logging (No Warning for Normal Latency)**
     *
     * For any response with latency below the threshold, no performance warning
     * log entry SHALL be created.
     *
     * **Validates: Requirements 7.2**
     *
     * @covers \mod_classengage\event_logger::log_response_submission
     */
    public function test_property_normal_latency_no_warning(): void {
        global $DB;

        $warningthreshold = event_logger::LATENCY_WARNING_THRESHOLD_MS;

        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create fresh test fixtures.
            $course = $this->getDataGenerator()->create_course();
            $user = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            $session = $generator->create_session($classengage->id, $user->id, [
                'status' => 'active',
                'questionstarttime' => time(),
                'timelimit' => 30,
            ]);

            // Generate latency below threshold.
            $normallatency = rand(0, $warningthreshold - 1);

            $responsedata = [
                'latency_ms' => $normallatency,
                'success' => true,
                'questionid' => rand(1, 1000),
            ];

            // Log the response.
            $logger = new event_logger();
            $logger->log_response_submission($session->id, $user->id, $responsedata);

            // Verify no performance warning was created.
            $warnings = $DB->get_records('classengage_session_log', [
                'sessionid' => $session->id,
                'event_type' => event_logger::EVENT_PERFORMANCE_WARNING,
            ]);

            $this->assertEmpty(
                $warnings,
                "Iteration $i: No performance warning should be created for normal latency ({$normallatency}ms < {$warningthreshold}ms)"
            );
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 17: Statistics API**
     *
     * For any statistics query, the Response_Capture_Engine SHALL return current
     * session statistics including average latency, error rate, and throughput.
     *
     * **Validates: Requirements 7.5**
     *
     * @covers \mod_classengage\event_logger::get_session_statistics
     */
    public function test_property_statistics_api(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create fresh test fixtures.
            $course = $this->getDataGenerator()->create_course();
            $user = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            $session = $generator->create_session($classengage->id, $user->id, [
                'status' => 'active',
                'questionstarttime' => time(),
                'timelimit' => 30,
            ]);

            $logger = new event_logger();

            // Generate random number of responses.
            $numresponses = rand(1, 20);
            $totallatency = 0;
            $numerrors = 0;

            for ($r = 0; $r < $numresponses; $r++) {
                $latency = rand(50, 500);
                $totallatency += $latency;

                $logger->log_response_submission($session->id, $user->id, [
                    'latency_ms' => $latency,
                    'success' => true,
                    'questionid' => $r + 1,
                ]);
            }

            // Generate random number of errors.
            $numerrors = rand(0, 5);
            for ($e = 0; $e < $numerrors; $e++) {
                $logger->log_connection_error($session->id, $user->id, 'Test error ' . $e, []);
            }

            // Get statistics.
            $stats = $logger->get_session_statistics($session->id);

            // Verify statistics object structure.
            $this->assertInstanceOf(
                session_statistics::class,
                $stats,
                "Iteration $i: Statistics should be a session_statistics object"
            );

            // Verify session ID.
            $this->assertEquals(
                $session->id,
                $stats->sessionid,
                "Iteration $i: Statistics should have correct session ID"
            );

            // Verify average latency is calculated.
            $expectedavglatency = $numresponses > 0 ? $totallatency / $numresponses : 0;
            $this->assertEqualsWithDelta(
                $expectedavglatency,
                $stats->averagelatency,
                1.0, // Allow 1ms tolerance for floating point.
                "Iteration $i: Average latency should be approximately correct"
            );

            // Verify total responses count.
            $this->assertEquals(
                $numresponses,
                $stats->totalresponses,
                "Iteration $i: Total responses should match"
            );

            // Verify total errors count.
            $this->assertEquals(
                $numerrors,
                $stats->totalerrors,
                "Iteration $i: Total errors should match"
            );

            // Verify error rate calculation.
            $totalevents = $numresponses + $numerrors;
            $expectederrorrate = $totalevents > 0 ? ($numerrors / $totalevents) * 100 : 0;
            $this->assertEqualsWithDelta(
                $expectederrorrate,
                $stats->errorrate,
                0.1, // Allow 0.1% tolerance.
                "Iteration $i: Error rate should be approximately correct"
            );

            // Verify throughput is non-negative.
            $this->assertGreaterThanOrEqual(
                0,
                $stats->throughput,
                "Iteration $i: Throughput should be non-negative"
            );

            // Verify timestamp is present.
            $this->assertGreaterThan(
                0,
                $stats->timestamp,
                "Iteration $i: Statistics should have a timestamp"
            );
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 17: Statistics API (Empty Session)**
     *
     * For any session with no logged events, statistics SHALL return zero values.
     *
     * **Validates: Requirements 7.5**
     *
     * @covers \mod_classengage\event_logger::get_session_statistics
     */
    public function test_property_statistics_empty_session(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create fresh test fixtures.
            $course = $this->getDataGenerator()->create_course();
            $user = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            $session = $generator->create_session($classengage->id, $user->id, [
                'status' => 'active',
                'questionstarttime' => time(),
                'timelimit' => 30,
            ]);

            $logger = new event_logger();

            // Get statistics for empty session.
            $stats = $logger->get_session_statistics($session->id);

            // Verify all values are zero or appropriate defaults.
            $this->assertEquals(
                0.0,
                $stats->averagelatency,
                "Iteration $i: Average latency should be 0 for empty session"
            );
            $this->assertEquals(
                0.0,
                $stats->errorrate,
                "Iteration $i: Error rate should be 0 for empty session"
            );
            $this->assertEquals(
                0,
                $stats->throughput,
                "Iteration $i: Throughput should be 0 for empty session"
            );
            $this->assertEquals(
                0,
                $stats->totalresponses,
                "Iteration $i: Total responses should be 0 for empty session"
            );
            $this->assertEquals(
                0,
                $stats->totalerrors,
                "Iteration $i: Total errors should be 0 for empty session"
            );
        }
    }
}
