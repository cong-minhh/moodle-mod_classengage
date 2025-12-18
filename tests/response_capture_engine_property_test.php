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
 * Property-based tests for mod_classengage response capture engine
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
 * Property-based tests for response capture engine
 *
 * Uses PHPUnit data providers to simulate property-based testing with random inputs.
 * Each property test runs a minimum of 100 iterations as specified in the design document.
 */
class response_capture_engine_property_test extends \advanced_testcase {

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
     * Generate random valid answer for a question type
     *
     * @param string $questiontype
     * @param bool $forcorrectanswer If true, limit length for database correctanswer field (max 10 chars)
     * @return string
     */
    protected function generate_random_valid_answer(string $questiontype, bool $forcorrectanswer = false): string {
        switch ($questiontype) {
            case 'multichoice':
                $options = ['A', 'B', 'C', 'D'];
                return $options[array_rand($options)];

            case 'truefalse':
                $options = ['TRUE', 'FALSE', 'T', 'F', '1', '0'];
                return $options[array_rand($options)];

            case 'shortanswer':
                // Database correctanswer field is limited to 10 chars.
                $maxlength = $forcorrectanswer ? 10 : 50;
                $length = rand(1, $maxlength);
                return substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz', 2)), 0, $length);

            default:
                return 'A';
        }
    }

    /**
     * Generate random invalid answer for a question type
     *
     * @param string $questiontype
     * @return string
     */
    protected function generate_random_invalid_answer(string $questiontype): string {
        switch ($questiontype) {
            case 'multichoice':
                // Invalid options: E, F, numbers, special chars, empty.
                $invalidoptions = ['E', 'F', 'G', '1', '2', 'AB', 'CD', '!@#', 'true', 'false'];
                return $invalidoptions[array_rand($invalidoptions)];

            case 'truefalse':
                // Invalid options: anything not TRUE/FALSE/T/F/1/0.
                $invalidoptions = ['YES', 'NO', 'Y', 'N', '2', 'MAYBE', 'A', 'B'];
                return $invalidoptions[array_rand($invalidoptions)];

            case 'shortanswer':
                // Invalid: string longer than 255 characters.
                return str_repeat('x', 256 + rand(1, 100));

            default:
                return '';
        }
    }

    /**
     * Create an active session with a question
     *
     * @param string $questiontype
     * @param string $correctanswer
     * @return array [session, question]
     */
    protected function create_active_session_with_question(string $questiontype = 'multichoice', string $correctanswer = 'A'): array {
        $session = $this->generator->create_session($this->classengage->id, $this->user->id, [
            'status' => 'active',
            'questionstarttime' => time(),
            'timelimit' => 30,
        ]);

        $question = $this->generator->create_question($this->classengage->id, [
            'questiontype' => $questiontype,
            'correctanswer' => $correctanswer,
        ]);

        return [$session, $question];
    }


    /**
     * **Feature: realtime-quiz-engine, Property 5: Duplicate Submission Rejection**
     *
     * For any user who has already submitted an answer for a question,
     * subsequent submissions for the same question in the same session
     * SHALL be rejected with an appropriate error message.
     *
     * **Validates: Requirements 2.3**
     *
     * @covers \mod_classengage\response_capture_engine::submit_response
     * @covers \mod_classengage\response_capture_engine::is_duplicate
     */
    public function test_property_duplicate_submission_rejection(): void {
        $engine = new response_capture_engine();

        // Run property test for multiple iterations.
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Generate random test data.
            $questiontype = ['multichoice', 'truefalse', 'shortanswer'][array_rand(['multichoice', 'truefalse', 'shortanswer'])];
            $correctanswer = $this->generate_random_valid_answer($questiontype, true);

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

            $question = $generator->create_question($classengage->id, [
                'questiontype' => $questiontype,
                'correctanswer' => $correctanswer,
            ]);

            // Generate two random valid answers.
            $firstanswer = $this->generate_random_valid_answer($questiontype);
            $secondanswer = $this->generate_random_valid_answer($questiontype);

            // First submission should succeed.
            $result1 = $engine->submit_response(
                $session->id,
                $question->id,
                $firstanswer,
                $user->id
            );

            $this->assertTrue(
                $result1->success,
                "Iteration $i: First submission should succeed for question type '$questiontype'"
            );

            // Second submission should be rejected as duplicate.
            $result2 = $engine->submit_response(
                $session->id,
                $question->id,
                $secondanswer,
                $user->id
            );

            $this->assertFalse(
                $result2->success,
                "Iteration $i: Second submission should be rejected as duplicate"
            );

            $this->assertStringContainsString(
                'Duplicate',
                $result2->error,
                "Iteration $i: Error message should indicate duplicate submission"
            );

            // Verify is_duplicate returns true after first submission.
            $this->assertTrue(
                $engine->is_duplicate($session->id, $question->id, $user->id),
                "Iteration $i: is_duplicate should return true after first submission"
            );
        }
    }


    /**
     * **Feature: realtime-quiz-engine, Property 4: Answer Validation**
     *
     * For any response submission, the Response_Capture_Engine SHALL validate
     * the answer format matches the expected format for the question type before storing.
     *
     * **Validates: Requirements 2.2**
     *
     * @covers \mod_classengage\response_capture_engine::validate_response
     * @covers \mod_classengage\response_capture_engine::submit_response
     */
    public function test_property_answer_validation(): void {
        $engine = new response_capture_engine();

        // Test valid answers are accepted for each question type.
        $questiontypes = ['multichoice', 'truefalse', 'shortanswer'];

        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            foreach ($questiontypes as $questiontype) {
                // Test valid answer validation.
                $validanswer = $this->generate_random_valid_answer($questiontype);
                $validresult = $engine->validate_response($validanswer, $questiontype);

                $this->assertTrue(
                    $validresult->valid,
                    "Iteration $i: Valid answer '$validanswer' should be accepted for question type '$questiontype'"
                );
                $this->assertNull(
                    $validresult->error,
                    "Iteration $i: Valid answer should have no error message"
                );

                // Test invalid answer validation.
                $invalidanswer = $this->generate_random_invalid_answer($questiontype);
                $invalidresult = $engine->validate_response($invalidanswer, $questiontype);

                $this->assertFalse(
                    $invalidresult->valid,
                    "Iteration $i: Invalid answer '$invalidanswer' should be rejected for question type '$questiontype'"
                );
                $this->assertNotNull(
                    $invalidresult->error,
                    "Iteration $i: Invalid answer should have an error message"
                );
            }
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 4: Answer Validation (Empty Answer)**
     *
     * For any question type, empty answers SHALL be rejected.
     *
     * **Validates: Requirements 2.2**
     *
     * @covers \mod_classengage\response_capture_engine::validate_response
     */
    public function test_property_empty_answer_rejection(): void {
        $engine = new response_capture_engine();
        $questiontypes = ['multichoice', 'truefalse', 'shortanswer'];

        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            foreach ($questiontypes as $questiontype) {
                // Test empty string.
                $result = $engine->validate_response('', $questiontype);
                $this->assertFalse(
                    $result->valid,
                    "Iteration $i: Empty string should be rejected for '$questiontype'"
                );

                // Test whitespace-only string.
                $whitespace = str_repeat(' ', rand(1, 10));
                $result = $engine->validate_response($whitespace, $questiontype);
                $this->assertFalse(
                    $result->valid,
                    "Iteration $i: Whitespace-only string should be rejected for '$questiontype'"
                );
            }
        }
    }


    /**
     * **Feature: realtime-quiz-engine, Property 3: Response Acknowledgment Latency**
     *
     * For any valid response submission, the server SHALL acknowledge receipt
     * within 1 second under normal load conditions.
     *
     * **Validates: Requirements 2.1**
     *
     * @covers \mod_classengage\response_capture_engine::submit_response
     */
    public function test_property_response_acknowledgment_latency(): void {
        $engine = new response_capture_engine();

        // Maximum allowed latency in milliseconds (1 second = 1000ms).
        $maxlatencyms = 1000;

        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Generate random test data.
            $questiontype = ['multichoice', 'truefalse', 'shortanswer'][array_rand(['multichoice', 'truefalse', 'shortanswer'])];
            $correctanswer = $this->generate_random_valid_answer($questiontype, true);

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

            $question = $generator->create_question($classengage->id, [
                'questiontype' => $questiontype,
                'correctanswer' => $correctanswer,
            ]);

            $answer = $this->generate_random_valid_answer($questiontype, false);

            // Measure submission time.
            $starttime = microtime(true);
            $result = $engine->submit_response(
                $session->id,
                $question->id,
                $answer,
                $user->id
            );
            $endtime = microtime(true);

            $actuallatencyms = ($endtime - $starttime) * 1000;

            // Verify submission succeeded.
            $this->assertTrue(
                $result->success,
                "Iteration $i: Response submission should succeed"
            );

            // Verify latency is within acceptable range.
            $this->assertLessThan(
                $maxlatencyms,
                $actuallatencyms,
                "Iteration $i: Response latency ({$actuallatencyms}ms) should be under {$maxlatencyms}ms"
            );

            // Verify the result includes latency measurement.
            $this->assertGreaterThanOrEqual(
                0,
                $result->latencyms,
                "Iteration $i: Result should include non-negative latency measurement"
            );
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 5: Duplicate Rejection in Batch**
     *
     * For any batch submission containing duplicates (same user, session, question),
     * the duplicates SHALL be rejected while valid submissions succeed.
     *
     * **Validates: Requirements 2.3**
     *
     * @covers \mod_classengage\response_capture_engine::submit_batch
     */
    public function test_property_batch_duplicate_rejection(): void {
        $engine = new response_capture_engine();

        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create test fixtures.
            $course = $this->getDataGenerator()->create_course();
            $users = [];
            for ($u = 0; $u < 3; $u++) {
                $users[] = $this->getDataGenerator()->create_user();
            }
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            $session = $generator->create_session($classengage->id, $users[0]->id, [
                'status' => 'active',
                'questionstarttime' => time(),
                'timelimit' => 30,
            ]);

            $question = $generator->create_question($classengage->id, [
                'questiontype' => 'multichoice',
                'correctanswer' => 'A',
            ]);

            // Create batch with one duplicate.
            $responses = [
                ['sessionid' => $session->id, 'questionid' => $question->id, 'answer' => 'A', 'userid' => $users[0]->id],
                ['sessionid' => $session->id, 'questionid' => $question->id, 'answer' => 'B', 'userid' => $users[1]->id],
                ['sessionid' => $session->id, 'questionid' => $question->id, 'answer' => 'C', 'userid' => $users[0]->id], // Duplicate.
            ];

            $result = $engine->submit_batch($responses);

            $this->assertTrue($result->success, "Iteration $i: Batch should process successfully");
            $this->assertEquals(2, $result->processedcount, "Iteration $i: Should process 2 unique submissions");
            $this->assertEquals(1, $result->failedcount, "Iteration $i: Should reject 1 duplicate");

            // Verify the duplicate was rejected with appropriate message.
            $this->assertFalse($result->results[2]->success, "Iteration $i: Duplicate should be rejected");
            $this->assertStringContainsString('Duplicate', $result->results[2]->error);
        }
    }
}
