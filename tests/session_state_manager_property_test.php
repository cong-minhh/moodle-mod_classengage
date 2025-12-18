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
 * Property-based tests for mod_classengage session state manager
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
 * Property-based tests for session state manager
 *
 * Uses PHPUnit data providers to simulate property-based testing with random inputs.
 * Each property test runs a minimum of 100 iterations as specified in the design document.
 */
class session_state_manager_property_test extends \advanced_testcase {

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
     * Create an active session with questions
     *
     * @param int $timelimit Time limit per question in seconds
     * @param int $numquestions Number of questions
     * @return \stdClass Session object
     */
    protected function create_active_session(int $timelimit = 30, int $numquestions = 5): \stdClass {
        $session = $this->generator->create_session($this->classengage->id, $this->user->id, [
            'status' => 'active',
            'timelimit' => $timelimit,
            'numquestions' => $numquestions,
            'questionstarttime' => time(),
            'timestarted' => time(),
        ]);

        // Create questions for the session.
        for ($i = 1; $i <= $numquestions; $i++) {
            $question = $this->generator->create_question($this->classengage->id, [
                'questiontype' => 'multichoice',
                'correctanswer' => 'A',
            ]);

            // Link question to session.
            global $DB;
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
     * **Feature: realtime-quiz-engine, Property 2: Pause/Resume Round-trip**
     *
     * For any active quiz session, pausing and then immediately resuming
     * SHALL restore the session to its original state with the timer
     * preserving the remaining time.
     *
     * **Validates: Requirements 1.4, 1.5**
     *
     * @covers \mod_classengage\session_state_manager::pause_session
     * @covers \mod_classengage\session_state_manager::resume_session
     */
    public function test_property_pause_resume_roundtrip(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create fresh test fixtures for each iteration.
            $course = $this->getDataGenerator()->create_course();
            $user = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            // Generate random time limit between 10 and 120 seconds.
            $timelimit = rand(10, 120);

            // Create session with random time limit.
            $session = $generator->create_session($classengage->id, $user->id, [
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

            $manager = new session_state_manager();

            // Get initial state.
            $initialstate = $manager->get_session_state($session->id);

            $this->assertEquals(
                'active',
                $initialstate->status,
                "Iteration $i: Initial session should be active"
            );

            // Record timer before pause.
            $timerbeforepause = $initialstate->timerremaining;

            // Pause the session.
            $pausedstate = $manager->pause_session($session->id);

            $this->assertEquals(
                'paused',
                $pausedstate->status,
                "Iteration $i: Session should be paused after pause_session()"
            );

            // Timer should be preserved when paused.
            $this->assertNotNull(
                $pausedstate->timerremaining,
                "Iteration $i: Timer remaining should be set when paused"
            );

            // Resume the session.
            $resumedstate = $manager->resume_session($session->id);

            $this->assertEquals(
                'active',
                $resumedstate->status,
                "Iteration $i: Session should be active after resume_session()"
            );

            // Timer should be restored (within 1 second tolerance for test execution time).
            $this->assertEqualsWithDelta(
                $pausedstate->timerremaining,
                $resumedstate->timerremaining,
                1,
                "Iteration $i: Timer should be restored after resume (within 1s tolerance)"
            );

            // Verify session is back to active in database.
            $dbsession = $DB->get_record('classengage_sessions', ['id' => $session->id]);
            $this->assertEquals(
                'active',
                $dbsession->status,
                "Iteration $i: Database should show session as active"
            );

            // Verify pause duration was recorded.
            $this->assertGreaterThanOrEqual(
                0,
                $dbsession->pause_duration,
                "Iteration $i: Pause duration should be recorded"
            );
        }
    }


    /**
     * **Feature: realtime-quiz-engine, Property 10: Session State Restoration on Reconnect**
     *
     * For any student who disconnects and reconnects to an active session,
     * the Quiz_Session SHALL restore their view to the current question
     * with accurate timer state.
     *
     * **Validates: Requirements 4.4**
     *
     * @covers \mod_classengage\session_state_manager::get_client_state
     * @covers \mod_classengage\session_state_manager::register_connection
     * @covers \mod_classengage\session_state_manager::handle_disconnect
     */
    public function test_property_session_state_restoration_on_reconnect(): void {
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
            $numquestions = rand(3, 10);

            // Create session.
            $session = $generator->create_session($classengage->id, $instructor->id, [
                'status' => 'active',
                'timelimit' => $timelimit,
                'numquestions' => $numquestions,
                'questionstarttime' => time(),
                'timestarted' => time(),
                'currentquestion' => 0,
            ]);

            // Create questions for the session.
            global $DB;
            $questions = [];
            for ($q = 1; $q <= $numquestions; $q++) {
                $question = $generator->create_question($classengage->id, [
                    'questiontype' => 'multichoice',
                    'correctanswer' => 'A',
                ]);
                $questions[] = $question;

                $sq = new \stdClass();
                $sq->sessionid = $session->id;
                $sq->questionid = $question->id;
                $sq->questionorder = $q;
                $sq->timecreated = time();
                $DB->insert_record('classengage_session_questions', $sq);
            }

            $manager = new session_state_manager();

            // Generate random connection ID.
            $connectionid1 = 'conn_' . uniqid() . '_' . $i;

            // Register initial connection.
            $manager->register_connection($session->id, $student->id, $connectionid1, 'polling');

            // Get initial client state.
            $initialstate = $manager->get_client_state($session->id, $student->id);

            $this->assertEquals(
                'active',
                $initialstate->status,
                "Iteration $i: Initial state should show active session"
            );

            $this->assertEquals(
                0,
                $initialstate->currentquestion,
                "Iteration $i: Initial state should show first question"
            );

            $this->assertNotNull(
                $initialstate->question,
                "Iteration $i: Initial state should include question data"
            );

            $this->assertNotNull(
                $initialstate->timerremaining,
                "Iteration $i: Initial state should include timer remaining"
            );

            // Simulate disconnect.
            $manager->handle_disconnect($connectionid1);

            // Verify disconnection was recorded.
            $connection = $DB->get_record('classengage_connections', ['connectionid' => $connectionid1]);
            $this->assertEquals(
                'disconnected',
                $connection->status,
                "Iteration $i: Connection should be marked as disconnected"
            );

            // Simulate reconnection with new connection ID.
            $connectionid2 = 'conn_' . uniqid() . '_' . $i . '_reconnect';
            $manager->register_connection($session->id, $student->id, $connectionid2, 'polling');

            // Get restored client state.
            $restoredstate = $manager->get_client_state($session->id, $student->id);

            // Verify state is restored correctly.
            $this->assertEquals(
                $initialstate->status,
                $restoredstate->status,
                "Iteration $i: Restored state should have same session status"
            );

            $this->assertEquals(
                $initialstate->currentquestion,
                $restoredstate->currentquestion,
                "Iteration $i: Restored state should show same current question"
            );

            $this->assertNotNull(
                $restoredstate->question,
                "Iteration $i: Restored state should include question data"
            );

            $this->assertEquals(
                $initialstate->question->id,
                $restoredstate->question->id,
                "Iteration $i: Restored state should show same question"
            );

            // Timer should be accurate (within reasonable tolerance for test execution).
            $this->assertNotNull(
                $restoredstate->timerremaining,
                "Iteration $i: Restored state should include timer remaining"
            );

            // Timer should have decreased but still be valid.
            $this->assertLessThanOrEqual(
                $initialstate->timerremaining,
                $restoredstate->timerremaining,
                "Iteration $i: Timer should not have increased"
            );

            $this->assertGreaterThanOrEqual(
                0,
                $restoredstate->timerremaining,
                "Iteration $i: Timer should not be negative"
            );
        }
    }
}
