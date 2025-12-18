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
 * Property-based tests for mod_classengage connection manager
 *
 * These tests verify correctness properties for the connection manager's
 * server-side support for SSE/polling transport and transport equivalence.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Property-based tests for connection manager transport behavior
 *
 * Uses PHPUnit data providers to simulate property-based testing with random inputs.
 * Each property test runs a minimum of 100 iterations as specified in the design document.
 */
class connection_manager_property_test extends \advanced_testcase {

    /** @var int Number of iterations for property tests */
    const PROPERTY_TEST_ITERATIONS = 100;

    /** @var int Maximum SSE retry attempts before fallback */
    const SSE_RETRY_ATTEMPTS = 3;

    /** @var int Maximum polling interval in milliseconds */
    const MAX_POLLING_INTERVAL = 2000;

    /**
     * **Feature: realtime-quiz-engine, Property 13: WebSocket Fallback to Polling**
     *
     * For any failed SSE connection after 3 attempts, the Connection Manager
     * SHALL automatically activate HTTP polling as a fallback transport.
     *
     * This test verifies the server-side support for both SSE and polling transports,
     * ensuring that connections can be registered with either transport type and
     * that the server handles both identically.
     *
     * **Validates: Requirements 6.1**
     *
     * @covers \mod_classengage\session_state_manager::register_connection
     */
    public function test_property_sse_fallback_to_polling(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create test fixtures.
            $course = $this->getDataGenerator()->create_course();
            $instructor = $this->getDataGenerator()->create_user();
            $student = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            // Create an active session.
            $session = $generator->create_session($classengage->id, $instructor->id, [
                'status' => 'active',
                'timelimit' => rand(30, 120),
                'numquestions' => 5,
                'questionstarttime' => time(),
                'timestarted' => time(),
            ]);

            // Create a question.
            $question = $generator->create_question($classengage->id, [
                'questiontype' => 'multichoice',
                'correctanswer' => 'A',
            ]);

            global $DB;
            $sq = new \stdClass();
            $sq->sessionid = $session->id;
            $sq->questionid = $question->id;
            $sq->questionorder = 1;
            $sq->timecreated = time();
            $DB->insert_record('classengage_session_questions', $sq);

            $statemanager = new session_state_manager();

            // Simulate SSE connection attempts (up to 3 failures).
            $sseconnectionid = 'sse_' . uniqid() . '_' . $i;
            $sseattemptsbeforefallback = rand(1, self::SSE_RETRY_ATTEMPTS);

            // Simulate failed SSE attempts by registering and then disconnecting.
            for ($attempt = 1; $attempt <= $sseattemptsbeforefallback; $attempt++) {
                $attemptconnectionid = $sseconnectionid . '_attempt' . $attempt;

                // Register SSE connection.
                $statemanager->register_connection($session->id, $student->id, $attemptconnectionid, 'sse');

                // Verify connection was registered.
                $connection = $DB->get_record('classengage_connections', ['connectionid' => $attemptconnectionid]);
                $this->assertNotFalse(
                    $connection,
                    "Iteration $i, Attempt $attempt: SSE connection should be registered"
                );
                $this->assertEquals(
                    'sse',
                    $connection->transport,
                    "Iteration $i, Attempt $attempt: Transport should be 'sse'"
                );

                // Simulate SSE failure by disconnecting.
                $statemanager->handle_disconnect($attemptconnectionid);

                // Verify disconnection.
                $connection = $DB->get_record('classengage_connections', ['connectionid' => $attemptconnectionid]);
                $this->assertEquals(
                    'disconnected',
                    $connection->status,
                    "Iteration $i, Attempt $attempt: Connection should be disconnected after failure"
                );
            }

            // After max SSE attempts, fall back to polling.
            $pollingconnectionid = 'polling_' . uniqid() . '_' . $i;
            $statemanager->register_connection($session->id, $student->id, $pollingconnectionid, 'polling');

            // Verify polling connection was registered successfully.
            $connection = $DB->get_record('classengage_connections', ['connectionid' => $pollingconnectionid]);
            $this->assertNotFalse(
                $connection,
                "Iteration $i: Polling fallback connection should be registered"
            );
            $this->assertEquals(
                'polling',
                $connection->transport,
                "Iteration $i: Transport should be 'polling' after fallback"
            );
            $this->assertEquals(
                'connected',
                $connection->status,
                "Iteration $i: Polling connection should be 'connected'"
            );

            // Verify the student can still get session state via polling.
            $clientstate = $statemanager->get_client_state($session->id, $student->id);
            $this->assertNotNull(
                $clientstate,
                "Iteration $i: Client state should be retrievable via polling"
            );
            $this->assertEquals(
                'active',
                $clientstate->status,
                "Iteration $i: Session status should be 'active'"
            );
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 14: Polling Interval Constraint**
     *
     * For any client using polling fallback, the polling interval SHALL not exceed 2 seconds.
     *
     * This test verifies that the server can handle polling requests at the maximum
     * allowed interval (2 seconds) and that responses are returned within acceptable
     * time limits to support the polling constraint.
     *
     * **Validates: Requirements 6.2**
     *
     * @covers \mod_classengage\session_state_manager::get_session_state
     * @covers \mod_classengage\heartbeat_manager::process_heartbeat
     */
    public function test_property_polling_interval_constraint(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create test fixtures.
            $course = $this->getDataGenerator()->create_course();
            $instructor = $this->getDataGenerator()->create_user();
            $student = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            // Generate random session parameters.
            $timelimit = rand(30, 300);
            $numquestions = rand(1, 20);

            // Create an active session.
            $session = $generator->create_session($classengage->id, $instructor->id, [
                'status' => 'active',
                'timelimit' => $timelimit,
                'numquestions' => $numquestions,
                'questionstarttime' => time(),
                'timestarted' => time(),
            ]);

            // Create questions.
            for ($q = 1; $q <= min($numquestions, 5); $q++) {
                $question = $generator->create_question($classengage->id, [
                    'questiontype' => 'multichoice',
                    'correctanswer' => chr(64 + rand(1, 4)), // A, B, C, or D.
                ]);

                global $DB;
                $sq = new \stdClass();
                $sq->sessionid = $session->id;
                $sq->questionid = $question->id;
                $sq->questionorder = $q;
                $sq->timecreated = time();
                $DB->insert_record('classengage_session_questions', $sq);
            }

            $statemanager = new session_state_manager();
            $heartbeatmanager = new heartbeat_manager();

            // Register a polling connection.
            $connectionid = 'polling_' . uniqid() . '_' . $i;
            $statemanager->register_connection($session->id, $student->id, $connectionid, 'polling');

            // Simulate multiple polling requests at the maximum interval.
            $pollcount = rand(3, 10);
            $maxresponsetimems = 0;

            for ($poll = 0; $poll < $pollcount; $poll++) {
                // Measure response time for session state retrieval.
                $starttime = microtime(true);
                $state = $statemanager->get_session_state($session->id);
                $endtime = microtime(true);

                $responsetimems = ($endtime - $starttime) * 1000;
                $maxresponsetimems = max($maxresponsetimems, $responsetimems);

                // Verify state was retrieved successfully.
                $this->assertNotNull(
                    $state,
                    "Iteration $i, Poll $poll: Session state should be retrieved"
                );

                // Verify response time is reasonable (should be much less than 2 seconds).
                // Allow up to 1 second for database operations under load.
                $this->assertLessThan(
                    1000,
                    $responsetimems,
                    "Iteration $i, Poll $poll: Response time should be under 1 second to support 2s polling"
                );

                // Process heartbeat to keep connection alive.
                $heartbeatresponse = $heartbeatmanager->process_heartbeat($session->id, $student->id, $connectionid);
                $this->assertTrue(
                    $heartbeatresponse->success,
                    "Iteration $i, Poll $poll: Heartbeat should succeed"
                );
            }

            // Verify the polling interval constraint can be maintained.
            // If max response time is under 1 second, 2 second polling is achievable.
            $this->assertLessThan(
                self::MAX_POLLING_INTERVAL,
                $maxresponsetimems,
                "Iteration $i: Max response time ($maxresponsetimems ms) should allow 2s polling interval"
            );

            // Verify connection is still active after all polls.
            $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);
            $this->assertEquals(
                'connected',
                $connection->status,
                "Iteration $i: Connection should remain 'connected' after polling"
            );
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 15: Transport Equivalence**
     *
     * For any operation (submit response, receive question, get status), the result
     * SHALL be identical regardless of whether SSE or polling transport is used,
     * and transport switches SHALL preserve session state.
     *
     * This test verifies that the server provides identical functionality and data
     * regardless of the transport type used by the client.
     *
     * **Validates: Requirements 6.4, 6.5**
     *
     * @covers \mod_classengage\session_state_manager::get_client_state
     * @covers \mod_classengage\session_state_manager::register_connection
     * @covers \mod_classengage\response_capture_engine::submit_response
     */
    public function test_property_transport_equivalence(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create test fixtures.
            $course = $this->getDataGenerator()->create_course();
            $instructor = $this->getDataGenerator()->create_user();
            $studentsse = $this->getDataGenerator()->create_user();
            $studentpolling = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            // Generate random session parameters.
            $timelimit = rand(30, 120);

            // Create an active session.
            $session = $generator->create_session($classengage->id, $instructor->id, [
                'status' => 'active',
                'timelimit' => $timelimit,
                'numquestions' => 5,
                'questionstarttime' => time(),
                'timestarted' => time(),
            ]);

            // Create a question.
            $question = $generator->create_question($classengage->id, [
                'questiontype' => 'multichoice',
                'correctanswer' => 'A',
            ]);

            global $DB;
            $sq = new \stdClass();
            $sq->sessionid = $session->id;
            $sq->questionid = $question->id;
            $sq->questionorder = 1;
            $sq->timecreated = time();
            $DB->insert_record('classengage_session_questions', $sq);

            $statemanager = new session_state_manager();
            $responseengine = new response_capture_engine();

            // Register SSE connection for student 1.
            $sseconnectionid = 'sse_' . uniqid() . '_' . $i;
            $statemanager->register_connection($session->id, $studentsse->id, $sseconnectionid, 'sse');

            // Register polling connection for student 2.
            $pollingconnectionid = 'polling_' . uniqid() . '_' . $i;
            $statemanager->register_connection($session->id, $studentpolling->id, $pollingconnectionid, 'polling');

            // Test 1: Both transports should return identical session state.
            $sseclientstate = $statemanager->get_client_state($session->id, $studentsse->id);
            $pollingclientstate = $statemanager->get_client_state($session->id, $studentpolling->id);

            // Verify session status is identical.
            $this->assertEquals(
                $sseclientstate->status,
                $pollingclientstate->status,
                "Iteration $i: Session status should be identical for both transports"
            );

            // Verify current question is identical.
            $this->assertEquals(
                $sseclientstate->currentquestion,
                $pollingclientstate->currentquestion,
                "Iteration $i: Current question should be identical for both transports"
            );

            // Verify timer remaining is identical (within 1 second tolerance).
            $this->assertEqualsWithDelta(
                $sseclientstate->timerremaining,
                $pollingclientstate->timerremaining,
                1,
                "Iteration $i: Timer remaining should be identical for both transports"
            );

            // Verify question data is identical.
            if ($sseclientstate->question && $pollingclientstate->question) {
                $this->assertEquals(
                    $sseclientstate->question->id,
                    $pollingclientstate->question->id,
                    "Iteration $i: Question ID should be identical for both transports"
                );
                $this->assertEquals(
                    $sseclientstate->question->questiontext,
                    $pollingclientstate->question->questiontext,
                    "Iteration $i: Question text should be identical for both transports"
                );
            }

            // Test 2: Response submission should work identically for both transports.
            $answer = chr(64 + rand(1, 4)); // A, B, C, or D.

            $sseresponse = $responseengine->submit_response(
                $session->id,
                $question->id,
                $answer,
                $studentsse->id
            );

            $pollingresponse = $responseengine->submit_response(
                $session->id,
                $question->id,
                $answer,
                $studentpolling->id
            );

            // Verify both submissions succeeded.
            $this->assertTrue(
                $sseresponse->success,
                "Iteration $i: SSE transport response submission should succeed"
            );
            $this->assertTrue(
                $pollingresponse->success,
                "Iteration $i: Polling transport response submission should succeed"
            );

            // Verify correctness evaluation is identical.
            $this->assertEquals(
                $sseresponse->iscorrect,
                $pollingresponse->iscorrect,
                "Iteration $i: Correctness evaluation should be identical for both transports"
            );

            // Test 3: Transport switch should preserve session state.
            // Disconnect SSE and reconnect with polling.
            $statemanager->handle_disconnect($sseconnectionid);

            $newpollingconnectionid = 'polling_switched_' . uniqid() . '_' . $i;
            $statemanager->register_connection($session->id, $studentsse->id, $newpollingconnectionid, 'polling');

            // Get state after transport switch.
            $switchedstate = $statemanager->get_client_state($session->id, $studentsse->id);

            // Verify state is preserved after transport switch.
            $this->assertEquals(
                $sseclientstate->status,
                $switchedstate->status,
                "Iteration $i: Session status should be preserved after transport switch"
            );

            // Verify the student's answer is still recorded.
            $this->assertTrue(
                $switchedstate->hasanswered,
                "Iteration $i: Answer should be preserved after transport switch"
            );
            $this->assertEquals(
                $answer,
                $switchedstate->useranswer,
                "Iteration $i: User answer should be preserved after transport switch"
            );

            // Verify connection is active with new transport.
            $connection = $DB->get_record('classengage_connections', ['connectionid' => $newpollingconnectionid]);
            $this->assertEquals(
                'connected',
                $connection->status,
                "Iteration $i: New polling connection should be 'connected'"
            );
            $this->assertEquals(
                'polling',
                $connection->transport,
                "Iteration $i: Transport should be 'polling' after switch"
            );
        }
    }
}
