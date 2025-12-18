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
 * Unit tests for mod_classengage heartbeat manager
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for heartbeat manager
 *
 * Requirements: 5.2, 5.3
 */
class heartbeat_manager_test extends \advanced_testcase {

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
     * Create a session with questions for testing
     *
     * @param array $sessionparams Session parameters
     * @param int $numquestions Number of questions to create
     * @return \stdClass Session object
     */
    protected function create_session_with_questions(array $sessionparams = [], int $numquestions = 5): \stdClass {
        global $DB;

        $defaults = [
            'status' => 'active',
            'timelimit' => 30,
            'numquestions' => $numquestions,
            'questionstarttime' => time(),
            'timestarted' => time(),
        ];

        $session = $this->generator->create_session(
            $this->classengage->id,
            $this->user->id,
            array_merge($defaults, $sessionparams)
        );

        // Create questions for the session.
        for ($i = 1; $i <= $numquestions; $i++) {
            $question = $this->generator->create_question($this->classengage->id, [
                'questiontype' => 'multichoice',
                'correctanswer' => 'A',
            ]);

            $sq = new \stdClass();
            $sq->sessionid = $session->id;
            $sq->questionid = $question->id;
            $sq->questionorder = $i;
            $sq->timecreated = time();
            $DB->insert_record('classengage_session_questions', $sq);
        }

        return $session;
    }

    /**
     * Test heartbeat processing updates last_heartbeat
     *
     * Requirement 5.2: WHEN a student disconnects THEN the Quiz_Session SHALL
     * update their status to "disconnected" within 5 seconds
     *
     * @covers \mod_classengage\heartbeat_manager::process_heartbeat
     */
    public function test_process_heartbeat_updates_last_heartbeat(): void {
        global $DB;

        $session = $this->create_session_with_questions();
        $student = $this->getDataGenerator()->create_user();
        $connectionid = 'conn_' . uniqid();

        // Register connection first.
        $sessionmanager = new session_state_manager();
        $sessionmanager->register_connection($session->id, $student->id, $connectionid, 'polling');

        // Get initial last_heartbeat.
        $initialconnection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);
        $initiallastheartbeat = $initialconnection->last_heartbeat;

        // Wait a moment to ensure time difference.
        sleep(1);

        // Process heartbeat.
        $heartbeatmanager = new heartbeat_manager();
        $response = $heartbeatmanager->process_heartbeat($session->id, $student->id, $connectionid);

        $this->assertTrue($response->success);
        $this->assertEquals('connected', $response->status);

        // Verify last_heartbeat was updated.
        $updatedconnection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);
        $this->assertGreaterThan($initiallastheartbeat, $updatedconnection->last_heartbeat);
    }

    /**
     * Test heartbeat processing returns error for non-existent connection
     *
     * @covers \mod_classengage\heartbeat_manager::process_heartbeat
     */
    public function test_process_heartbeat_returns_error_for_nonexistent_connection(): void {
        $session = $this->create_session_with_questions();
        $student = $this->getDataGenerator()->create_user();

        $heartbeatmanager = new heartbeat_manager();
        $response = $heartbeatmanager->process_heartbeat($session->id, $student->id, 'nonexistent_connection');

        $this->assertFalse($response->success);
        $this->assertEquals('Connection not found', $response->error);
        $this->assertEquals('disconnected', $response->status);
    }

    /**
     * Test heartbeat processing returns error for mismatched session
     *
     * @covers \mod_classengage\heartbeat_manager::process_heartbeat
     */
    public function test_process_heartbeat_returns_error_for_mismatched_session(): void {
        global $DB;

        $session1 = $this->create_session_with_questions();
        $session2 = $this->create_session_with_questions();
        $student = $this->getDataGenerator()->create_user();
        $connectionid = 'conn_' . uniqid();

        // Register connection to session1.
        $sessionmanager = new session_state_manager();
        $sessionmanager->register_connection($session1->id, $student->id, $connectionid, 'polling');

        // Try to process heartbeat with session2.
        $heartbeatmanager = new heartbeat_manager();
        $response = $heartbeatmanager->process_heartbeat($session2->id, $student->id, $connectionid);

        $this->assertFalse($response->success);
        $this->assertEquals('Connection mismatch', $response->error);
    }

    /**
     * Test stale connection detection after timeout
     *
     * Requirement 5.2: WHEN a student disconnects THEN the Quiz_Session SHALL
     * update their status to "disconnected" within 5 seconds
     *
     * @covers \mod_classengage\heartbeat_manager::check_stale_connections
     */
    public function test_check_stale_connections_detects_timeout(): void {
        global $DB;

        $session = $this->create_session_with_questions();
        $student = $this->getDataGenerator()->create_user();
        $connectionid = 'conn_' . uniqid();

        // Register connection.
        $sessionmanager = new session_state_manager();
        $sessionmanager->register_connection($session->id, $student->id, $connectionid, 'polling');

        // Set last_heartbeat to past (beyond timeout).
        $staleTime = time() - (heartbeat_manager::HEARTBEAT_TIMEOUT + 1);
        $DB->set_field('classengage_connections', 'last_heartbeat', $staleTime, ['connectionid' => $connectionid]);

        // Check for stale connections.
        $heartbeatmanager = new heartbeat_manager();
        $disconnectedUsers = $heartbeatmanager->check_stale_connections($session->id);

        $this->assertContains($student->id, $disconnectedUsers);

        // Verify status was updated.
        $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);
        $this->assertEquals('disconnected', $connection->status);
    }

    /**
     * Test stale connection detection does not affect active connections
     *
     * @covers \mod_classengage\heartbeat_manager::check_stale_connections
     */
    public function test_check_stale_connections_ignores_active_connections(): void {
        global $DB;

        $session = $this->create_session_with_questions();
        $student = $this->getDataGenerator()->create_user();
        $connectionid = 'conn_' . uniqid();

        // Register connection with recent heartbeat.
        $sessionmanager = new session_state_manager();
        $sessionmanager->register_connection($session->id, $student->id, $connectionid, 'polling');

        // Check for stale connections.
        $heartbeatmanager = new heartbeat_manager();
        $disconnectedUsers = $heartbeatmanager->check_stale_connections($session->id);

        $this->assertEmpty($disconnectedUsers);

        // Verify status was not changed.
        $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);
        $this->assertEquals('connected', $connection->status);
    }

    /**
     * Test stale connection detection logs timeout event
     *
     * @covers \mod_classengage\heartbeat_manager::check_stale_connections
     */
    public function test_check_stale_connections_logs_event(): void {
        global $DB;

        $session = $this->create_session_with_questions();
        $student = $this->getDataGenerator()->create_user();
        $connectionid = 'conn_' . uniqid();

        // Register connection.
        $sessionmanager = new session_state_manager();
        $sessionmanager->register_connection($session->id, $student->id, $connectionid, 'polling');

        // Set last_heartbeat to past.
        $staleTime = time() - (heartbeat_manager::HEARTBEAT_TIMEOUT + 1);
        $DB->set_field('classengage_connections', 'last_heartbeat', $staleTime, ['connectionid' => $connectionid]);

        // Check for stale connections.
        $heartbeatmanager = new heartbeat_manager();
        $heartbeatmanager->check_stale_connections($session->id);

        // Verify event was logged.
        $log = $DB->get_record('classengage_session_log', [
            'sessionid' => $session->id,
            'userid' => $student->id,
            'event_type' => 'heartbeat_timeout',
        ]);

        $this->assertNotFalse($log);

        $eventdata = json_decode($log->event_data, true);
        $this->assertEquals($connectionid, $eventdata['connectionid']);
        $this->assertEquals(heartbeat_manager::HEARTBEAT_TIMEOUT, $eventdata['timeout_seconds']);
    }

    /**
     * Test connection statistics calculation
     *
     * @covers \mod_classengage\heartbeat_manager::get_connection_stats
     */
    public function test_get_connection_stats_returns_correct_counts(): void {
        global $DB;

        $session = $this->create_session_with_questions();
        $sessionmanager = new session_state_manager();
        $heartbeatmanager = new heartbeat_manager();

        // Create 3 students with different connection states.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $student3 = $this->getDataGenerator()->create_user();

        $connid1 = 'conn_' . uniqid() . '_1';
        $connid2 = 'conn_' . uniqid() . '_2';
        $connid3 = 'conn_' . uniqid() . '_3';

        // Register all connections.
        $sessionmanager->register_connection($session->id, $student1->id, $connid1, 'polling');
        $sessionmanager->register_connection($session->id, $student2->id, $connid2, 'polling');
        $sessionmanager->register_connection($session->id, $student3->id, $connid3, 'polling');

        // Make student2 disconnected.
        $sessionmanager->handle_disconnect($connid2);

        // Make student3 stale (connected but heartbeat timed out).
        $staleTime = time() - (heartbeat_manager::HEARTBEAT_TIMEOUT + 1);
        $DB->set_field('classengage_connections', 'last_heartbeat', $staleTime, ['connectionid' => $connid3]);

        // Get statistics.
        $stats = $heartbeatmanager->get_connection_stats($session->id);

        $this->assertInstanceOf(connection_stats::class, $stats);
        $this->assertEquals(3, $stats->totalconnections);
        $this->assertEquals(1, $stats->activeconnections); // Only student1 is active.
        $this->assertEquals(1, $stats->disconnectedconnections); // Student2.
        $this->assertEquals(1, $stats->staleconnections); // Student3.
    }

    /**
     * Test connection statistics with no connections
     *
     * @covers \mod_classengage\heartbeat_manager::get_connection_stats
     */
    public function test_get_connection_stats_with_no_connections(): void {
        $session = $this->create_session_with_questions();
        $heartbeatmanager = new heartbeat_manager();

        $stats = $heartbeatmanager->get_connection_stats($session->id);

        $this->assertEquals(0, $stats->totalconnections);
        $this->assertEquals(0, $stats->activeconnections);
        $this->assertEquals(0, $stats->disconnectedconnections);
        $this->assertEquals(0, $stats->staleconnections);
        $this->assertEquals(0.0, $stats->averagelatency);
    }

    /**
     * Test is_connection_stale returns true for stale connection
     *
     * @covers \mod_classengage\heartbeat_manager::is_connection_stale
     */
    public function test_is_connection_stale_returns_true_for_stale(): void {
        global $DB;

        $session = $this->create_session_with_questions();
        $student = $this->getDataGenerator()->create_user();
        $connectionid = 'conn_' . uniqid();

        // Register connection.
        $sessionmanager = new session_state_manager();
        $sessionmanager->register_connection($session->id, $student->id, $connectionid, 'polling');

        // Set last_heartbeat to past.
        $staleTime = time() - (heartbeat_manager::HEARTBEAT_TIMEOUT + 1);
        $DB->set_field('classengage_connections', 'last_heartbeat', $staleTime, ['connectionid' => $connectionid]);

        $heartbeatmanager = new heartbeat_manager();
        $this->assertTrue($heartbeatmanager->is_connection_stale($connectionid));
    }

    /**
     * Test is_connection_stale returns false for active connection
     *
     * @covers \mod_classengage\heartbeat_manager::is_connection_stale
     */
    public function test_is_connection_stale_returns_false_for_active(): void {
        $session = $this->create_session_with_questions();
        $student = $this->getDataGenerator()->create_user();
        $connectionid = 'conn_' . uniqid();

        // Register connection with recent heartbeat.
        $sessionmanager = new session_state_manager();
        $sessionmanager->register_connection($session->id, $student->id, $connectionid, 'polling');

        $heartbeatmanager = new heartbeat_manager();
        $this->assertFalse($heartbeatmanager->is_connection_stale($connectionid));
    }

    /**
     * Test is_connection_stale returns true for non-existent connection
     *
     * @covers \mod_classengage\heartbeat_manager::is_connection_stale
     */
    public function test_is_connection_stale_returns_true_for_nonexistent(): void {
        $heartbeatmanager = new heartbeat_manager();
        $this->assertTrue($heartbeatmanager->is_connection_stale('nonexistent_connection'));
    }

    /**
     * Test get_heartbeat_timeout returns correct value
     *
     * @covers \mod_classengage\heartbeat_manager::get_heartbeat_timeout
     */
    public function test_get_heartbeat_timeout(): void {
        $heartbeatmanager = new heartbeat_manager();
        $this->assertEquals(10, $heartbeatmanager->get_heartbeat_timeout());
    }

    /**
     * Test heartbeat processing logs reconnection event
     *
     * Requirement 5.3: WHEN a student reconnects THEN the Quiz_Session SHALL
     * update their status to "connected" within 2 seconds
     *
     * @covers \mod_classengage\heartbeat_manager::process_heartbeat
     */
    public function test_process_heartbeat_logs_reconnection_event(): void {
        global $DB;

        $session = $this->create_session_with_questions();
        $student = $this->getDataGenerator()->create_user();
        $connectionid = 'conn_' . uniqid();

        // Register connection.
        $sessionmanager = new session_state_manager();
        $sessionmanager->register_connection($session->id, $student->id, $connectionid, 'polling');

        // Disconnect the connection.
        $sessionmanager->handle_disconnect($connectionid);

        // Verify disconnected.
        $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);
        $this->assertEquals('disconnected', $connection->status);

        // Process heartbeat to reconnect.
        $heartbeatmanager = new heartbeat_manager();
        $response = $heartbeatmanager->process_heartbeat($session->id, $student->id, $connectionid);

        $this->assertTrue($response->success);
        $this->assertEquals('connected', $response->status);

        // Verify reconnection event was logged.
        $log = $DB->get_record('classengage_session_log', [
            'sessionid' => $session->id,
            'userid' => $student->id,
            'event_type' => 'heartbeat_reconnect',
        ]);

        $this->assertNotFalse($log);

        $eventdata = json_decode($log->event_data, true);
        $this->assertEquals($connectionid, $eventdata['connectionid']);
        $this->assertTrue($eventdata['was_disconnected']);
    }
}
