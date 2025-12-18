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
 * Property-based tests for mod_classengage client cache functionality
 *
 * These tests verify correctness properties for offline caching and late submission
 * handling. The client-side IndexedDB behavior is tested through server-side
 * validation of cached response submissions.
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Property-based tests for client cache functionality
 *
 * Uses PHPUnit data providers to simulate property-based testing with random inputs.
 * Each property test runs a minimum of 100 iterations as specified in the design document.
 */
class client_cache_property_test extends \advanced_testcase {

    /** @var int Number of iterations for property tests */
    const PROPERTY_TEST_ITERATIONS = 100;

    /**
     * Generate random valid answer for a question type
     *
     * @param string $questiontype
     * @param bool $forcorrectanswer If true, limit length for database correctanswer field
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
                $maxlength = $forcorrectanswer ? 10 : 50;
                $length = rand(1, $maxlength);
                return substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz', 2)), 0, $length);

            default:
                return 'A';
        }
    }

    /**
     * Check if an answer is correct using the same logic as response_capture_engine
     *
     * @param string $answer The submitted answer
     * @param string $correctanswer The correct answer
     * @param string $questiontype The question type
     * @return bool True if correct
     */
    protected function check_answer_correct(string $answer, string $correctanswer, string $questiontype): bool {
        $normalizedanswer = strtoupper(trim($answer));
        $normalizedcorrect = strtoupper(trim($correctanswer));

        switch ($questiontype) {
            case 'multichoice':
                return $normalizedanswer === $normalizedcorrect;

            case 'truefalse':
                // Normalize true/false variations.
                $trueanswers = ['TRUE', 'T', '1'];

                $answeristruevariant = in_array($normalizedanswer, $trueanswers);
                $correctistruevariant = in_array($normalizedcorrect, $trueanswers);

                return $answeristruevariant === $correctistruevariant;

            case 'shortanswer':
                return $normalizedanswer === $normalizedcorrect;

            default:
                return false;
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 8: Offline Cache Round-trip**
     *
     * For any response submitted while offline, the Client_Cache SHALL store it locally,
     * and upon reconnection, the cached response SHALL be automatically submitted to the server.
     *
     * This test verifies the server-side handling of cached responses by simulating
     * the round-trip: storing response data, then submitting it later with the original
     * client timestamp. The server should accept the response and preserve all data.
     *
     * **Validates: Requirements 4.1, 4.2**
     *
     * @covers \mod_classengage\response_capture_engine::submit_response
     * @covers \mod_classengage\response_capture_engine::queue_response
     */
    public function test_property_offline_cache_round_trip(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create test fixtures.
            $course = $this->getDataGenerator()->create_course();
            $student = $this->getDataGenerator()->create_user();
            $instructor = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            // Generate random question type.
            $questiontypes = ['multichoice', 'truefalse', 'shortanswer'];
            $questiontype = $questiontypes[array_rand($questiontypes)];
            $correctanswer = $this->generate_random_valid_answer($questiontype, true);

            // Create an active session with sufficient time limit.
            $timelimit = rand(60, 300);
            $questionstarttime = time();

            $session = $generator->create_session($classengage->id, $instructor->id, [
                'status' => 'active',
                'timelimit' => $timelimit,
                'numquestions' => 5,
                'questionstarttime' => $questionstarttime,
                'timestarted' => time(),
            ]);

            $question = $generator->create_question($classengage->id, [
                'questiontype' => $questiontype,
                'correctanswer' => $correctanswer,
            ]);

            // Link question to session.
            global $DB;
            $sq = new \stdClass();
            $sq->sessionid = $session->id;
            $sq->questionid = $question->id;
            $sq->questionorder = 1;
            $sq->timecreated = time();
            $DB->insert_record('classengage_session_questions', $sq);

            // Generate a random valid answer.
            $answer = $this->generate_random_valid_answer($questiontype);

            // Simulate offline caching: record the client timestamp when response was created.
            // This simulates the client storing the response in IndexedDB while offline.
            $clienttimestamp = $questionstarttime + rand(1, $timelimit - 10);

            // Simulate the cached response data structure (as stored in IndexedDB).
            $cachedresponse = [
                'id' => 'resp_' . time() . '_' . rand(1000, 9999),
                'sessionId' => $session->id,
                'questionId' => $question->id,
                'answer' => $answer,
                'timestamp' => $clienttimestamp * 1000, // JavaScript uses milliseconds.
                'clientTimestamp' => $clienttimestamp,
                'retryCount' => 0,
                'status' => 'pending',
            ];

            // Verify the cached response contains all required fields.
            $this->assertArrayHasKey('id', $cachedresponse, "Iteration $i: Cached response should have id");
            $this->assertArrayHasKey('sessionId', $cachedresponse, "Iteration $i: Cached response should have sessionId");
            $this->assertArrayHasKey('questionId', $cachedresponse, "Iteration $i: Cached response should have questionId");
            $this->assertArrayHasKey('answer', $cachedresponse, "Iteration $i: Cached response should have answer");
            $this->assertArrayHasKey('clientTimestamp', $cachedresponse, "Iteration $i: Cached response should have clientTimestamp");

            // Simulate reconnection: submit the cached response to the server.
            $engine = new response_capture_engine();
            $result = $engine->submit_response(
                $cachedresponse['sessionId'],
                $cachedresponse['questionId'],
                $cachedresponse['answer'],
                $student->id,
                $cachedresponse['clientTimestamp']
            );

            // Verify the round-trip: response should be accepted.
            $this->assertTrue(
                $result->success,
                "Iteration $i: Cached response should be accepted after reconnection"
            );

            // Verify the response was stored in the database.
            $this->assertNotNull(
                $result->responseid,
                "Iteration $i: Response should have a valid ID after storage"
            );

            // Verify the stored response matches the cached data.
            $storedresponse = $DB->get_record('classengage_responses', ['id' => $result->responseid]);
            $this->assertNotFalse(
                $storedresponse,
                "Iteration $i: Response should be retrievable from database"
            );
            $this->assertEquals(
                $cachedresponse['sessionId'],
                $storedresponse->sessionid,
                "Iteration $i: Stored session ID should match cached session ID"
            );
            $this->assertEquals(
                $cachedresponse['questionId'],
                $storedresponse->questionid,
                "Iteration $i: Stored question ID should match cached question ID"
            );
            $this->assertEquals(
                $cachedresponse['answer'],
                $storedresponse->answer,
                "Iteration $i: Stored answer should match cached answer"
            );

            // Verify correctness was evaluated.
            $expectedcorrect = $this->check_answer_correct($answer, $correctanswer, $questiontype);
            $this->assertEquals(
                $expectedcorrect,
                $result->iscorrect,
                "Iteration $i: Correctness evaluation should be accurate"
            );
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 9: Late Submission Acceptance**
     *
     * If a cached response is submitted after the question timer expires,
     * the Response_Capture_Engine SHALL accept the response with a "late" flag.
     *
     * This test verifies that responses submitted after the timer expires
     * (due to offline caching) are still accepted and properly flagged as late.
     *
     * **Validates: Requirements 4.3**
     *
     * @covers \mod_classengage\response_capture_engine::submit_response
     */
    public function test_property_late_submission_acceptance(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create test fixtures.
            $course = $this->getDataGenerator()->create_course();
            $student = $this->getDataGenerator()->create_user();
            $instructor = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            // Generate random question type.
            $questiontypes = ['multichoice', 'truefalse', 'shortanswer'];
            $questiontype = $questiontypes[array_rand($questiontypes)];
            $correctanswer = $this->generate_random_valid_answer($questiontype, true);

            // Create an active session with a short time limit that has already expired.
            $timelimit = rand(10, 30);
            // Set question start time in the past so timer has expired.
            $questionstarttime = time() - $timelimit - rand(5, 60);

            $session = $generator->create_session($classengage->id, $instructor->id, [
                'status' => 'active',
                'timelimit' => $timelimit,
                'numquestions' => 5,
                'questionstarttime' => $questionstarttime,
                'timestarted' => $questionstarttime,
            ]);

            $question = $generator->create_question($classengage->id, [
                'questiontype' => $questiontype,
                'correctanswer' => $correctanswer,
            ]);

            // Link question to session.
            global $DB;
            $sq = new \stdClass();
            $sq->sessionid = $session->id;
            $sq->questionid = $question->id;
            $sq->questionorder = 1;
            $sq->timecreated = $questionstarttime;
            $DB->insert_record('classengage_session_questions', $sq);

            // Generate a random valid answer.
            $answer = $this->generate_random_valid_answer($questiontype);

            // Simulate a late submission: client timestamp is after timer expired.
            // This simulates a response that was cached while offline and submitted
            // after the timer had already expired.
            $timerexpiry = $questionstarttime + $timelimit;
            $clienttimestamp = $timerexpiry + rand(1, 30); // 1-30 seconds after expiry.

            // Submit the late response.
            $engine = new response_capture_engine();
            $result = $engine->submit_response(
                $session->id,
                $question->id,
                $answer,
                $student->id,
                $clienttimestamp
            );

            // Verify the late response was accepted.
            $this->assertTrue(
                $result->success,
                "Iteration $i: Late submission should be accepted"
            );

            // Verify the response is flagged as late.
            $this->assertTrue(
                $result->islate,
                "Iteration $i: Response should be flagged as late when submitted after timer expiry"
            );

            // Verify the response was stored in the database.
            $this->assertNotNull(
                $result->responseid,
                "Iteration $i: Late response should have a valid ID"
            );

            // Verify the stored response.
            $storedresponse = $DB->get_record('classengage_responses', ['id' => $result->responseid]);
            $this->assertNotFalse(
                $storedresponse,
                "Iteration $i: Late response should be retrievable from database"
            );
            $this->assertEquals(
                $answer,
                $storedresponse->answer,
                "Iteration $i: Stored answer should match submitted answer"
            );

            // Verify correctness was still evaluated for late submissions.
            $expectedcorrect = $this->check_answer_correct($answer, $correctanswer, $questiontype);
            $this->assertEquals(
                $expectedcorrect,
                $result->iscorrect,
                "Iteration $i: Correctness should be evaluated even for late submissions"
            );
        }
    }

    /**
     * **Feature: realtime-quiz-engine, Property 8: Offline Cache Round-trip (Queue)**
     *
     * For any response queued for batch processing, the queue_response and process_queue
     * methods SHALL preserve all response data through the round-trip.
     *
     * This test verifies the server-side queue mechanism that supports offline caching
     * by ensuring responses can be queued and later processed without data loss.
     *
     * **Validates: Requirements 4.1, 4.2**
     *
     * @covers \mod_classengage\response_capture_engine::queue_response
     * @covers \mod_classengage\response_capture_engine::process_queue
     */
    public function test_property_queue_round_trip(): void {
        for ($i = 0; $i < self::PROPERTY_TEST_ITERATIONS; $i++) {
            $this->resetAfterTest(true);

            // Create test fixtures.
            $course = $this->getDataGenerator()->create_course();
            $student = $this->getDataGenerator()->create_user();
            $instructor = $this->getDataGenerator()->create_user();
            $classengage = $this->getDataGenerator()->create_module('classengage', ['course' => $course->id]);
            $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');

            // Generate random question type.
            $questiontypes = ['multichoice', 'truefalse', 'shortanswer'];
            $questiontype = $questiontypes[array_rand($questiontypes)];
            $correctanswer = $this->generate_random_valid_answer($questiontype, true);

            // Create an active session.
            $timelimit = rand(60, 300);
            $questionstarttime = time();

            $session = $generator->create_session($classengage->id, $instructor->id, [
                'status' => 'active',
                'timelimit' => $timelimit,
                'numquestions' => 5,
                'questionstarttime' => $questionstarttime,
                'timestarted' => time(),
            ]);

            $question = $generator->create_question($classengage->id, [
                'questiontype' => $questiontype,
                'correctanswer' => $correctanswer,
            ]);

            // Link question to session.
            global $DB;
            $sq = new \stdClass();
            $sq->sessionid = $session->id;
            $sq->questionid = $question->id;
            $sq->questionorder = 1;
            $sq->timecreated = time();
            $DB->insert_record('classengage_session_questions', $sq);

            // Generate a random valid answer.
            $answer = $this->generate_random_valid_answer($questiontype);
            $clienttimestamp = $questionstarttime + rand(1, $timelimit - 10);

            // Queue the response (simulating offline storage).
            $engine = new response_capture_engine();
            $queueid = $engine->queue_response(
                $session->id,
                $question->id,
                $answer,
                $student->id,
                $clienttimestamp
            );

            // Verify the response was queued.
            $this->assertGreaterThan(
                0,
                $queueid,
                "Iteration $i: Response should be queued with valid ID"
            );

            // Verify queue entry exists with correct data.
            $queueentry = $DB->get_record('classengage_response_queue', ['id' => $queueid]);
            $this->assertNotFalse(
                $queueentry,
                "Iteration $i: Queue entry should exist"
            );
            $this->assertEquals(
                $session->id,
                $queueentry->sessionid,
                "Iteration $i: Queued session ID should match"
            );
            $this->assertEquals(
                $question->id,
                $queueentry->questionid,
                "Iteration $i: Queued question ID should match"
            );
            $this->assertEquals(
                $answer,
                $queueentry->answer,
                "Iteration $i: Queued answer should match"
            );
            $this->assertEquals(
                $student->id,
                $queueentry->userid,
                "Iteration $i: Queued user ID should match"
            );
            $this->assertEquals(
                0,
                $queueentry->processed,
                "Iteration $i: Queue entry should not be processed yet"
            );

            // Process the queue (simulating reconnection).
            $result = $engine->process_queue(10);

            // Verify the queue was processed successfully.
            $this->assertTrue(
                $result->success,
                "Iteration $i: Queue processing should succeed"
            );
            $this->assertEquals(
                1,
                $result->processedcount,
                "Iteration $i: One response should be processed"
            );

            // Verify the queue entry is now marked as processed.
            $queueentry = $DB->get_record('classengage_response_queue', ['id' => $queueid]);
            $this->assertEquals(
                1,
                $queueentry->processed,
                "Iteration $i: Queue entry should be marked as processed"
            );

            // Verify the response was stored in the responses table.
            $storedresponse = $DB->get_record('classengage_responses', [
                'sessionid' => $session->id,
                'questionid' => $question->id,
                'userid' => $student->id,
            ]);
            $this->assertNotFalse(
                $storedresponse,
                "Iteration $i: Response should be stored after queue processing"
            );
            $this->assertEquals(
                $answer,
                $storedresponse->answer,
                "Iteration $i: Stored answer should match queued answer"
            );
        }
    }
}
