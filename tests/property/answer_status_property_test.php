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
 * @group mod_classengage_property for answer status update in instructor control panel
 *
 * These tests verify that student answer status is correctly updated
 * in the instructor control panel after successful response submission.
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
 * @group mod_classengage_property for answer status update
 *
 * Uses PHPUnit data providers to simulate property-based testing with random inputs.
 * Each property test runs a minimum of 100 iterations as specified in the design document.
 */
class answer_status_property_test extends \advanced_testcase {

    /** @var int Number of iterations for property tests */
    const PROPERTY_TEST_ITERATIONS = 100;

    /** @var array Valid answer options */
    const VALID_ANSWERS = ['A', 'B', 'C', 'D'];

    /**
     * Set up test fixtures
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Generate a random answer from valid options
     *
     * @return string Random answer (A, B, C, or D)
     */
    protected function generate_random_answer(): string {
        return self::VALID_ANSWERS[array_rand(self::VALID_ANSWERS)];
    }

    /**
     * Create an active session with questions and a connected student
     *
     * @param \stdClass $classengage ClassEngage instance
     * @param \stdClass $instructor Instructor user
     * @param \stdClass $student Student user
     * @param \mod_classengage_generator $generator Test data generator
     * @return array [session, question, connectionid]
     */
    protected function create_session_with_connected_student(
        \stdClass $classengage,
        \stdClass $instructor,
        \stdClass $student,
        $generator
    ): array {
        global $DB;

        // Generate random time limit between 10 and 120 seconds.
        $timelimit = rand(10, 120);

        // Create session.
        $session = $generator->create_session($classengage->id, $instructor->id, [
            'status' => 'active',
            'timelimit' => $timelimit,
            'numquestions' => 5,
            'questionstarttime' => time(),
            'timestarted' => time(),
            'currentquestion' => 0,
        ]);

        // Create a question for the session.
        $question = $generator->create_question($classengage->id, [
            'questiontype' => 'multichoice',
            'correctanswer' => $this->generate_random_answer(),
        ]);

        // Link question to session.
        $sq = new \stdClass();
        $sq->sessionid = $session->id;
        $sq->questionid = $question->id;
        $sq->questionorder = 1;
        $sq->timecreated = time();
        $DB->insert_record('classengage_session_questions', $sq);

        // Register student connection.
        $connectionid = 'conn_' . uniqid() . '_' . $student->id;
        $statemanager = new session_state_manager();
        $statemanager->register_connection($session->id, $student->id, $connectionid, 'polling');

        return [$session, $question, $connectionid];
    }

    /**
     * **Feature: realtime-quiz-engine, Property 12: Answer Status Update**
     *
     * For any successful response submission, the student's status in the
     * instructor control panel SHALL update to "answered" for the current question.
     *
     * **Validates: Requirements 5.4**
     *
     * @covers \mod_classengage\session_state_manager::mark_question_answered
     * @covers \mod_classengage\session_state_manager::get_connected_students
     * @covers \mod_classengage\response_capture_engine::submit_response
     */
    public function test_property_answer_status_update(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create fresh test fixtures for each iteration.
            $course = $this->getDataGenerator()->create_course();
            $instructor = $this->getDataGenerator()->create_user();
            $student = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', [
                'course' => $course->id,
            ]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            // Create session with connected student.
            list($session, $question, $connectionid) = $this->create_session_with_connected_student(
                $classengage,
                $instructor,
                $student,
                $generator
            );

            $statemanager = new session_state_manager();
            $engine = new response_capture_engine();

            // Verify student is connected but has not answered.
            $studentsbefore = $statemanager->get_connected_students($session->id);
            $studentbefore = null;
            foreach ($studentsbefore as $s) {
                if ($s->userid == $student->id) {
                    $studentbefore = $s;
                    break;
                }
            }

            $this->assertNotNull(
                $studentbefore,
                "Iteration $i: Student should be in connected students list"
            );

            $this->assertFalse(
                $studentbefore->hasanswered,
                "Iteration $i: Student should not have answered before submission"
            );

            // Submit a response.
            $answer = $this->generate_random_answer();
            $result = $engine->submit_response(
                $session->id,
                $question->id,
                $answer,
                $student->id
            );

            $this->assertTrue(
                $result->success,
                "Iteration $i: Response submission should succeed"
            );

            // Mark question as answered (this is called by ajax.php after successful submission).
            $statemanager->mark_question_answered($session->id, $student->id);

            // Verify student status is now "answered".
            $studentsafter = $statemanager->get_connected_students($session->id);
            $studentafter = null;
            foreach ($studentsafter as $s) {
                if ($s->userid == $student->id) {
                    $studentafter = $s;
                    break;
                }
            }

            $this->assertNotNull(
                $studentafter,
                "Iteration $i: Student should still be in connected students list after answering"
            );

            $this->assertTrue(
                $studentafter->hasanswered,
                "Iteration $i: Student status should show 'answered' after successful submission"
            );

            // Verify aggregate statistics reflect the answer.
            $stats = $statemanager->get_session_statistics($session->id);

            $this->assertGreaterThanOrEqual(
                1,
                $stats['answered'],
                "Iteration $i: Aggregate stats should show at least 1 answered"
            );
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 12: Answer Status Update (Multiple Students)**
     *
     * For any group of students submitting responses, each student's status
     * SHALL independently update to "answered" after their successful submission.
     *
     * **Validates: Requirements 5.4**
     *
     * @covers \mod_classengage\session_state_manager::mark_question_answered
     * @covers \mod_classengage\session_state_manager::get_connected_students
     * @covers \mod_classengage\session_state_manager::get_session_statistics
     */
    public function test_property_answer_status_update_multiple_students(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create fresh test fixtures for each iteration.
            $course = $this->getDataGenerator()->create_course();
            $instructor = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', [
                'course' => $course->id,
            ]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            // Generate random number of students (2-10).
            $numstudents = rand(2, 10);
            $students = [];
            for ($s = 0; $s < $numstudents; $s++) {
                $students[] = $this->getDataGenerator()->create_user();
            }

            // Create session.
            global $DB;
            $timelimit = rand(10, 120);
            $session = $generator->create_session($classengage->id, $instructor->id, [
                'status' => 'active',
                'timelimit' => $timelimit,
                'numquestions' => 5,
                'questionstarttime' => time(),
                'timestarted' => time(),
                'currentquestion' => 0,
            ]);

            // Create a question.
            $question = $generator->create_question($classengage->id, [
                'questiontype' => 'multichoice',
                'correctanswer' => $this->generate_random_answer(),
            ]);

            // Link question to session.
            $sq = new \stdClass();
            $sq->sessionid = $session->id;
            $sq->questionid = $question->id;
            $sq->questionorder = 1;
            $sq->timecreated = time();
            $DB->insert_record('classengage_session_questions', $sq);

            $statemanager = new session_state_manager();
            $engine = new response_capture_engine();

            // Register all students as connected.
            $connectionids = [];
            foreach ($students as $student) {
                $connectionid = 'conn_' . uniqid() . '_' . $student->id;
                $connectionids[$student->id] = $connectionid;
                $statemanager->register_connection($session->id, $student->id, $connectionid, 'polling');
            }

            // Randomly select which students will answer.
            $numtoanswer = rand(1, $numstudents);
            $studentstoanswerkeys = array_rand($students, $numtoanswer);
            if (!is_array($studentstoanswerkeys)) {
                $studentstoanswerkeys = [$studentstoanswerkeys];
            }

            $answeredstudentids = [];
            foreach ($studentstoanswerkeys as $key) {
                $student = $students[$key];
                $answer = $this->generate_random_answer();

                // Submit response.
                $result = $engine->submit_response(
                    $session->id,
                    $question->id,
                    $answer,
                    $student->id
                );

                $this->assertTrue(
                    $result->success,
                    "Iteration $i: Response submission should succeed for student {$student->id}"
                );

                // Mark as answered.
                $statemanager->mark_question_answered($session->id, $student->id);
                $answeredstudentids[] = $student->id;
            }

            // Verify each student's status is correct.
            $connectedstudents = $statemanager->get_connected_students($session->id);

            foreach ($connectedstudents as $connectedstudent) {
                $shouldhaveanswered = in_array($connectedstudent->userid, $answeredstudentids);

                $this->assertEquals(
                    $shouldhaveanswered,
                    $connectedstudent->hasanswered,
                    "Iteration $i: Student {$connectedstudent->userid} hasanswered should be " .
                    ($shouldhaveanswered ? 'true' : 'false')
                );
            }

            // Verify aggregate statistics.
            $stats = $statemanager->get_session_statistics($session->id);

            $this->assertEquals(
                count($answeredstudentids),
                $stats['answered'],
                "Iteration $i: Aggregate answered count should match number of students who answered"
            );

            $this->assertEquals(
                $numstudents - count($answeredstudentids),
                $stats['pending'],
                "Iteration $i: Aggregate pending count should match students who haven't answered"
            );
        }
    }
}
