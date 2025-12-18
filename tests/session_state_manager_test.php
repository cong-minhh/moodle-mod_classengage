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
 * Unit tests for mod_classengage session state manager
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for session state manager
 *
 * Requirements: 1.1, 1.4, 1.5, 5.1
 */
class session_state_manager_test extends \advanced_testcase {

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
            'status' => 'ready',
            'timelimit' => 30,
            'numquestions' => $numquestions,
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
     * Test session start transitions to active status
     *
     * Requirement 1.1: WHEN an instructor starts a quiz session THEN the Quiz_Session
     * SHALL transition to "active" status
     *
     * @covers \mod_classengage\session_state_manager::start_session
     */
    public function test_start_session_transitions_to_active(): void {
        global $DB;

        $session = $this->create_session_with_questions(['status' => 'ready']);
        $manager = new session_state_manager();

        $state = $manager->start_session($session->id);

        $this->assertEquals('active', $state->status);
        $this->assertEquals(0, $state->currentquestion);
        $this->assertNotNull($state->timerremaining);
        $this->assertNotNull($state->timestamp);

        // Verify database was updated.
        $dbsession = $DB->get_record('classengage_sessions', ['id' => $session->id]);
        $this->assertEquals('active', $dbsession->status);
        $this->assertNotNull($dbsession->timestarted);
    }

    /**
     * Test session start logs the event
     *
     * @covers \mod_classengage\session_state_manager::start_session
     */
    public function test_start_session_logs_event(): void {
        global $DB;

        $session = $this->create_session_with_questions(['status' => 'ready']);
        $manager = new session_state_manager();

        $manager->start_session($session->id);

        $log = $DB->get_record('classengage_session_log', [
            'sessionid' => $session->id,
            'event_type' => 'session_start',
        ]);

        $this->assertNotFalse($log);
        $this->assertNotNull($log->timecreated);

        $eventdata = json_decode($log->event_data, true);
        $this->assertArrayHasKey('latency_ms', $eventdata);
    }

    /**
     * Test session start stops other active sessions for the same activity
     *
     * @covers \mod_classengage\session_state_manager::start_session
     */
    public function test_start_session_stops_other_active_sessions(): void {
        global $DB;

        // Create first session and start it.
        $session1 = $this->create_session_with_questions(['status' => 'ready']);
        $manager = new session_state_manager();
        $manager->start_session($session1->id);

        // Create second session.
        $session2 = $this->create_session_with_questions(['status' => 'ready']);

        // Start second session.
        $manager->start_session($session2->id);

        // First session should now be completed.
        $dbsession1 = $DB->get_record('classengage_sessions', ['id' => $session1->id]);
        $this->assertEquals('completed', $dbsession1->status);

        // Second session should be active.
        $dbsession2 = $DB->get_record('classengage_sessions', ['id' => $session2->id]);
        $this->assertEquals('active', $dbsession2->status);
    }


    /**
     * Test pause session freezes timer correctly
     *
     * Requirement 1.4: WHEN an instructor pauses a quiz session THEN the Quiz_Session
     * SHALL freeze the timer and prevent new response submissions until resumed
     *
     * @covers \mod_classengage\session_state_manager::pause_session
     */
    public function test_pause_session_freezes_timer(): void {
        global $DB;

        $timelimit = 60;
        $session = $this->create_session_with_questions([
            'status' => 'active',
            'timelimit' => $timelimit,
            'questionstarttime' => time() - 10, // Started 10 seconds ago.
            'timestarted' => time() - 10,
        ]);

        $manager = new session_state_manager();
        $state = $manager->pause_session($session->id);

        $this->assertEquals('paused', $state->status);
        $this->assertNotNull($state->timerremaining);
        // Timer should be approximately 50 seconds (60 - 10).
        $this->assertEqualsWithDelta(50, $state->timerremaining, 2);

        // Verify database was updated.
        $dbsession = $DB->get_record('classengage_sessions', ['id' => $session->id]);
        $this->assertEquals('paused', $dbsession->status);
        $this->assertNotNull($dbsession->paused_at);
        $this->assertNotNull($dbsession->timer_remaining);
    }

    /**
     * Test pause session logs the event
     *
     * @covers \mod_classengage\session_state_manager::pause_session
     */
    public function test_pause_session_logs_event(): void {
        global $DB;

        $session = $this->create_session_with_questions([
            'status' => 'active',
            'timelimit' => 60,
            'questionstarttime' => time(),
            'timestarted' => time(),
        ]);

        $manager = new session_state_manager();
        $manager->pause_session($session->id);

        $log = $DB->get_record('classengage_session_log', [
            'sessionid' => $session->id,
            'event_type' => 'session_pause',
        ]);

        $this->assertNotFalse($log);

        $eventdata = json_decode($log->event_data, true);
        $this->assertArrayHasKey('timer_remaining', $eventdata);
        $this->assertArrayHasKey('current_question', $eventdata);
    }

    /**
     * Test pause session throws exception for non-active session
     *
     * @covers \mod_classengage\session_state_manager::pause_session
     */
    public function test_pause_session_throws_for_non_active(): void {
        $session = $this->create_session_with_questions(['status' => 'ready']);
        $manager = new session_state_manager();

        $this->expectException(\moodle_exception::class);
        $manager->pause_session($session->id);
    }

    /**
     * Test resume session restores timer correctly
     *
     * Requirement 1.5: WHEN an instructor resumes a paused session THEN the Quiz_Session
     * SHALL restore the timer and re-enable response submissions
     *
     * @covers \mod_classengage\session_state_manager::resume_session
     */
    public function test_resume_session_restores_timer(): void {
        global $DB;

        $timelimit = 60;
        $timerremaining = 45;

        $session = $this->create_session_with_questions([
            'status' => 'paused',
            'timelimit' => $timelimit,
            'timer_remaining' => $timerremaining,
            'paused_at' => time() - 5, // Paused 5 seconds ago.
            'questionstarttime' => time() - 20,
            'timestarted' => time() - 20,
        ]);

        $manager = new session_state_manager();
        $state = $manager->resume_session($session->id);

        $this->assertEquals('active', $state->status);
        $this->assertNotNull($state->timerremaining);
        // Timer should be restored to approximately 45 seconds.
        $this->assertEqualsWithDelta($timerremaining, $state->timerremaining, 2);

        // Verify database was updated.
        $dbsession = $DB->get_record('classengage_sessions', ['id' => $session->id]);
        $this->assertEquals('active', $dbsession->status);
        $this->assertNull($dbsession->paused_at);
        $this->assertGreaterThan(0, $dbsession->pause_duration);
    }

    /**
     * Test resume session logs the event
     *
     * @covers \mod_classengage\session_state_manager::resume_session
     */
    public function test_resume_session_logs_event(): void {
        global $DB;

        $session = $this->create_session_with_questions([
            'status' => 'paused',
            'timelimit' => 60,
            'timer_remaining' => 45,
            'paused_at' => time() - 5,
            'questionstarttime' => time() - 20,
            'timestarted' => time() - 20,
        ]);

        $manager = new session_state_manager();
        $manager->resume_session($session->id);

        $log = $DB->get_record('classengage_session_log', [
            'sessionid' => $session->id,
            'event_type' => 'session_resume',
        ]);

        $this->assertNotFalse($log);

        $eventdata = json_decode($log->event_data, true);
        $this->assertArrayHasKey('pause_duration', $eventdata);
        $this->assertArrayHasKey('timer_remaining', $eventdata);
    }

    /**
     * Test resume session throws exception for non-paused session
     *
     * @covers \mod_classengage\session_state_manager::resume_session
     */
    public function test_resume_session_throws_for_non_paused(): void {
        $session = $this->create_session_with_questions(['status' => 'active', 'questionstarttime' => time()]);
        $manager = new session_state_manager();

        $this->expectException(\moodle_exception::class);
        $manager->resume_session($session->id);
    }


    /**
     * Test connection registration creates new connection record
     *
     * Requirement 5.1: WHILE a quiz session is active THEN the Quiz_Session SHALL
     * display a list of connected students with their connection status
     *
     * @covers \mod_classengage\session_state_manager::register_connection
     */
    public function test_register_connection_creates_record(): void {
        global $DB;

        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
        ]);

        $student = $this->getDataGenerator()->create_user();
        $connectionid = 'conn_' . uniqid();

        $manager = new session_state_manager();
        $manager->register_connection($session->id, $student->id, $connectionid, 'polling');

        $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);

        $this->assertNotFalse($connection);
        $this->assertEquals($session->id, $connection->sessionid);
        $this->assertEquals($student->id, $connection->userid);
        $this->assertEquals('connected', $connection->status);
        $this->assertEquals('polling', $connection->transport);
        $this->assertNotNull($connection->last_heartbeat);
    }

    /**
     * Test connection registration updates existing connection
     *
     * @covers \mod_classengage\session_state_manager::register_connection
     */
    public function test_register_connection_updates_existing(): void {
        global $DB;

        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
        ]);

        $student = $this->getDataGenerator()->create_user();
        $connectionid = 'conn_' . uniqid();

        $manager = new session_state_manager();

        // Register initial connection.
        $manager->register_connection($session->id, $student->id, $connectionid, 'polling');

        // Update with different transport.
        $manager->register_connection($session->id, $student->id, $connectionid, 'sse');

        $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);

        $this->assertEquals('sse', $connection->transport);
        $this->assertEquals('connected', $connection->status);
    }

    /**
     * Test connection registration disconnects old connection for same user
     *
     * @covers \mod_classengage\session_state_manager::register_connection
     */
    public function test_register_connection_disconnects_old_connection(): void {
        global $DB;

        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
        ]);

        $student = $this->getDataGenerator()->create_user();
        $connectionid1 = 'conn_' . uniqid() . '_1';
        $connectionid2 = 'conn_' . uniqid() . '_2';

        $manager = new session_state_manager();

        // Register first connection.
        $manager->register_connection($session->id, $student->id, $connectionid1, 'polling');

        // Register second connection for same user.
        $manager->register_connection($session->id, $student->id, $connectionid2, 'polling');

        // First connection should be disconnected.
        $conn1 = $DB->get_record('classengage_connections', ['connectionid' => $connectionid1]);
        $this->assertEquals('disconnected', $conn1->status);

        // Second connection should be connected.
        $conn2 = $DB->get_record('classengage_connections', ['connectionid' => $connectionid2]);
        $this->assertEquals('connected', $conn2->status);
    }

    /**
     * Test connection registration logs the event
     *
     * @covers \mod_classengage\session_state_manager::register_connection
     */
    public function test_register_connection_logs_event(): void {
        global $DB;

        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
        ]);

        $student = $this->getDataGenerator()->create_user();
        $connectionid = 'conn_' . uniqid();

        $manager = new session_state_manager();
        $manager->register_connection($session->id, $student->id, $connectionid, 'polling');

        $log = $DB->get_record('classengage_session_log', [
            'sessionid' => $session->id,
            'userid' => $student->id,
            'event_type' => 'connection_register',
        ]);

        $this->assertNotFalse($log);

        $eventdata = json_decode($log->event_data, true);
        $this->assertEquals($connectionid, $eventdata['connectionid']);
        $this->assertEquals('polling', $eventdata['transport']);
    }

    /**
     * Test handle disconnect updates connection status
     *
     * @covers \mod_classengage\session_state_manager::handle_disconnect
     */
    public function test_handle_disconnect_updates_status(): void {
        global $DB;

        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
        ]);

        $student = $this->getDataGenerator()->create_user();
        $connectionid = 'conn_' . uniqid();

        $manager = new session_state_manager();
        $manager->register_connection($session->id, $student->id, $connectionid, 'polling');

        // Disconnect.
        $manager->handle_disconnect($connectionid);

        $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);
        $this->assertEquals('disconnected', $connection->status);
    }

    /**
     * Test handle disconnect logs the event
     *
     * @covers \mod_classengage\session_state_manager::handle_disconnect
     */
    public function test_handle_disconnect_logs_event(): void {
        global $DB;

        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
        ]);

        $student = $this->getDataGenerator()->create_user();
        $connectionid = 'conn_' . uniqid();

        $manager = new session_state_manager();
        $manager->register_connection($session->id, $student->id, $connectionid, 'polling');
        $manager->handle_disconnect($connectionid);

        $log = $DB->get_record('classengage_session_log', [
            'sessionid' => $session->id,
            'userid' => $student->id,
            'event_type' => 'connection_disconnect',
        ]);

        $this->assertNotFalse($log);

        $eventdata = json_decode($log->event_data, true);
        $this->assertEquals($connectionid, $eventdata['connectionid']);
    }

    /**
     * Test handle disconnect with non-existent connection does nothing
     *
     * @covers \mod_classengage\session_state_manager::handle_disconnect
     */
    public function test_handle_disconnect_nonexistent_connection(): void {
        $manager = new session_state_manager();

        // Should not throw exception.
        $manager->handle_disconnect('nonexistent_connection_id');

        $this->assertTrue(true); // If we get here, no exception was thrown.
    }


    /**
     * Test get connected students returns correct list
     *
     * Requirement 5.1: WHILE a quiz session is active THEN the Quiz_Session SHALL
     * display a list of connected students with their connection status
     *
     * @covers \mod_classengage\session_state_manager::get_connected_students
     */
    public function test_get_connected_students_returns_list(): void {
        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
        ]);

        $manager = new session_state_manager();

        // Create and connect multiple students.
        $students = [];
        for ($i = 0; $i < 3; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $students[] = $student;
            $connectionid = 'conn_' . uniqid() . '_' . $i;
            $manager->register_connection($session->id, $student->id, $connectionid, 'polling');
        }

        $connectedstudents = $manager->get_connected_students($session->id);

        $this->assertCount(3, $connectedstudents);

        foreach ($connectedstudents as $connstudent) {
            $this->assertInstanceOf(connected_student::class, $connstudent);
            $this->assertEquals('connected', $connstudent->status);
            $this->assertFalse($connstudent->hasanswered);
            $this->assertEquals('polling', $connstudent->transport);
        }
    }

    /**
     * Test get connected students includes disconnected students
     *
     * @covers \mod_classengage\session_state_manager::get_connected_students
     */
    public function test_get_connected_students_includes_disconnected(): void {
        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
        ]);

        $manager = new session_state_manager();

        // Connect two students.
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();

        $connid1 = 'conn_' . uniqid() . '_1';
        $connid2 = 'conn_' . uniqid() . '_2';

        $manager->register_connection($session->id, $student1->id, $connid1, 'polling');
        $manager->register_connection($session->id, $student2->id, $connid2, 'polling');

        // Disconnect one student.
        $manager->handle_disconnect($connid1);

        $connectedstudents = $manager->get_connected_students($session->id);

        $this->assertCount(2, $connectedstudents);

        $statuses = array_map(fn($s) => $s->status, $connectedstudents);
        $this->assertContains('connected', $statuses);
        $this->assertContains('disconnected', $statuses);
    }

    /**
     * Test get session statistics returns correct counts
     *
     * @covers \mod_classengage\session_state_manager::get_session_statistics
     */
    public function test_get_session_statistics(): void {
        global $DB;

        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
        ]);

        $manager = new session_state_manager();

        // Connect 3 students.
        for ($i = 0; $i < 3; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $connectionid = 'conn_' . uniqid() . '_' . $i;
            $manager->register_connection($session->id, $student->id, $connectionid, 'polling');

            // Mark first student as having answered.
            if ($i === 0) {
                $manager->mark_question_answered($session->id, $student->id);
            }
        }

        $stats = $manager->get_session_statistics($session->id);

        $this->assertEquals(3, $stats['connected']);
        $this->assertEquals(1, $stats['answered']);
        $this->assertEquals(2, $stats['pending']);
    }

    /**
     * Test next question advances to next question
     *
     * @covers \mod_classengage\session_state_manager::next_question
     */
    public function test_next_question_advances(): void {
        global $DB;

        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
            'currentquestion' => 0,
        ]);

        $manager = new session_state_manager();
        $broadcast = $manager->next_question($session->id);

        $this->assertInstanceOf(question_broadcast::class, $broadcast);
        $this->assertEquals(1, $broadcast->questionnumber);
        $this->assertEquals($session->id, $broadcast->sessionid);

        // Verify database was updated.
        $dbsession = $DB->get_record('classengage_sessions', ['id' => $session->id]);
        $this->assertEquals(1, $dbsession->currentquestion);
    }

    /**
     * Test next question resets answered status for all connections
     *
     * @covers \mod_classengage\session_state_manager::next_question
     */
    public function test_next_question_resets_answered_status(): void {
        global $DB;

        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
            'currentquestion' => 0,
        ]);

        $manager = new session_state_manager();

        // Connect a student and mark as answered.
        $student = $this->getDataGenerator()->create_user();
        $connectionid = 'conn_' . uniqid();
        $manager->register_connection($session->id, $student->id, $connectionid, 'polling');
        $manager->mark_question_answered($session->id, $student->id);

        // Verify answered status is set.
        $conn = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);
        $this->assertEquals(1, $conn->current_question_answered);

        // Advance to next question.
        $manager->next_question($session->id);

        // Answered status should be reset.
        $conn = $DB->get_record('classengage_connections', ['connectionid' => $connectionid]);
        $this->assertEquals(0, $conn->current_question_answered);
    }

    /**
     * Test next question completes session when all questions done
     *
     * @covers \mod_classengage\session_state_manager::next_question
     */
    public function test_next_question_completes_session(): void {
        global $DB;

        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
            'currentquestion' => 4, // Last question (0-indexed, 5 questions total).
            'numquestions' => 5,
        ], 5);

        $manager = new session_state_manager();
        $broadcast = $manager->next_question($session->id);

        // Session should be completed.
        $dbsession = $DB->get_record('classengage_sessions', ['id' => $session->id]);
        $this->assertEquals('completed', $dbsession->status);
        $this->assertNotNull($dbsession->timecompleted);
    }

    /**
     * Test get client state returns correct state for reconnecting client
     *
     * @covers \mod_classengage\session_state_manager::get_client_state
     */
    public function test_get_client_state_returns_correct_state(): void {
        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
            'timelimit' => 60,
            'currentquestion' => 0,
        ]);

        $student = $this->getDataGenerator()->create_user();
        $manager = new session_state_manager();

        $state = $manager->get_client_state($session->id, $student->id);

        $this->assertInstanceOf(client_session_state::class, $state);
        $this->assertEquals('active', $state->status);
        $this->assertEquals(0, $state->currentquestion);
        $this->assertNotNull($state->question);
        $this->assertNotNull($state->timerremaining);
        $this->assertFalse($state->hasanswered);
        $this->assertNull($state->useranswer);
    }

    /**
     * Test get session state returns cached state
     *
     * @covers \mod_classengage\session_state_manager::get_session_state
     */
    public function test_get_session_state_returns_state(): void {
        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
            'timelimit' => 60,
            'currentquestion' => 0,
        ]);

        $manager = new session_state_manager();
        $state = $manager->get_session_state($session->id);

        $this->assertInstanceOf(session_state::class, $state);
        $this->assertEquals($session->id, $state->sessionid);
        $this->assertEquals('active', $state->status);
        $this->assertEquals(0, $state->currentquestion);
    }

    /**
     * Test get session state returns null for non-existent session
     *
     * @covers \mod_classengage\session_state_manager::get_session_state
     */
    public function test_get_session_state_returns_null_for_nonexistent(): void {
        $manager = new session_state_manager();
        $state = $manager->get_session_state(99999);

        $this->assertNull($state);
    }

    /**
     * Test invalidate cache clears session cache
     *
     * @covers \mod_classengage\session_state_manager::invalidate_cache
     */
    public function test_invalidate_cache(): void {
        $session = $this->create_session_with_questions([
            'status' => 'active',
            'questionstarttime' => time(),
            'timestarted' => time(),
        ]);

        $manager = new session_state_manager();

        // Get state to populate cache.
        $manager->get_session_state($session->id);

        // Invalidate cache.
        $manager->invalidate_cache($session->id);

        // This should not throw an exception.
        $this->assertTrue(true);
    }
}
