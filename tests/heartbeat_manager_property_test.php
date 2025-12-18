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
 * Property-based tests for mod_classengage heartbeat manager
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
 * Property-based tests for heartbeat manager
 *
 * Uses PHPUnit data providers to simulate property-based testing with random inputs.
 * Each property test runs a minimum of 100 iterations as specified in the design document.
 */
class heartbeat_manager_property_test extends \advanced_testcase {

    /** @var int Number of iterations for property tests */
    const PROPERTY_TEST_ITERATIONS = 100;

    /**
     * **Feature: realtime-quiz-engine, Property 11: Connection Status Update Timing**
     *
     * For any student connection state change (connect/disconnect), the instructor's
     * control panel SHALL reflect the updated status within 5 seconds for disconnects
     * and 2 seconds for reconnects.
     *
     * **Validates: Requirements 5.2, 5.3**
     *
     * @covers \mod_classengage\heartbeat_manager::process_heartbeat
     * @covers \mod_classengage\heartbeat_manager::check_stale_connections
     */
    public function test_property_connection_status_update_timing(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create fresh test fixtures for each iteration.
            $course = $this->getDataGenerator()->create_course();
            $instructor = $this->getDataGenerator()->create_user();
            $student = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            // Generate random time limit between 10 and 120 seconds.
            $timelimit = rand(10, 120);

            // Create an active session.
            $session = $generator->create_session($classengage->id, $instructor->id, [
                'status' => 'active',
                'timelimit' => $timelimit,
                'numquestions' => 5,
                'questionstarttime' => time(),
                'timestarted' => time(),
            ]);

            // Create at least one question.
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

            $sessionstatemanager = new session_state_manager();
            $heartbeatmanager = new heartbeat_manager();

            // Generate random connection ID.
            $connectionid = 'conn_' . uniqid() . '_' . $i;

            // Register initial connection.
            $sessionstatemanager->register_connection($session->id, $student->id, $connectionid, 'polling');

            // Verify initial connection status is 'connected'.
            $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);
            $this->assertEquals(
                'connected',
                $connection->status,
                "Iteration $i: Initial connection should be 'connected'"
            );

            // Test 1: Heartbeat processing updates last_heartbeat immediately.
            $beforeheartbeat = time();
            $response = $heartbeatmanager->process_heartbeat($session->id, $student->id, $connectionid);
            $afterheartbeat = time();

            $this->assertTrue(
                $response->success,
                "Iteration $i: Heartbeat should be processed successfully"
            );

            $this->assertEquals(
                'connected',
                $response->status,
                "Iteration $i: Status should be 'connected' after heartbeat"
            );

            // Verify last_heartbeat was updated within the timing window.
            $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);
            $this->assertGreaterThanOrEqual(
                $beforeheartbeat,
                $connection->last_heartbeat,
                "Iteration $i: last_heartbeat should be updated to current time"
            );
            $this->assertLessThanOrEqual(
                $afterheartbeat,
                $connection->last_heartbeat,
                "Iteration $i: last_heartbeat should not be in the future"
            );

            // Test 2: Stale connection detection marks as disconnected.
            // Simulate a stale connection by setting last_heartbeat to past.
            $staleTime = time() - (heartbeat_manager::HEARTBEAT_TIMEOUT + 1);
            $DB->set_field('classengage_connections', 'last_heartbeat', $staleTime, ['connectionid' => $connectionid]);

            // Check for stale connections.
            $disconnectedUsers = $heartbeatmanager->check_stale_connections($session->id);

            // Verify the student was marked as disconnected.
            $this->assertContains(
                $student->id,
                $disconnectedUsers,
                "Iteration $i: Student should be in disconnected users list"
            );

            // Verify database status was updated.
            $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);
            $this->assertEquals(
                'disconnected',
                $connection->status,
                "Iteration $i: Connection status should be 'disconnected' after timeout"
            );

            // Test 3: Reconnection updates status back to connected.
            // Process a new heartbeat to simulate reconnection.
            $response = $heartbeatmanager->process_heartbeat($session->id, $student->id, $connectionid);

            $this->assertTrue(
                $response->success,
                "Iteration $i: Reconnection heartbeat should succeed"
            );

            $this->assertEquals(
                'connected',
                $response->status,
                "Iteration $i: Status should be 'connected' after reconnection"
            );

            // Verify database status was updated.
            $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);
            $this->assertEquals(
                'connected',
                $connection->status,
                "Iteration $i: Database status should be 'connected' after reconnection"
            );

            // Verify reconnection event was logged.
            $log = $DB->get_record_select(
                'classengage_session_log',
                'sessionid = :sessionid AND userid = :userid AND event_type = :eventtype',
                [
                    'sessionid' => $session->id,
                    'userid' => $student->id,
                    'eventtype' => 'heartbeat_reconnect',
                ]
            );

            $this->assertNotFalse(
                $log,
                "Iteration $i: Reconnection event should be logged"
            );
        }
    }
}
