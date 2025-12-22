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
 * Unit tests for mod_classengage response capture engine
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for response capture engine
 *
 * Requirements: 2.1, 2.2, 2.3
 */
class response_capture_engine_test extends \advanced_testcase {

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
     * Test valid response submission for multichoice question
     *
     * @covers \mod_classengage\response_capture_engine::submit_response
     */
    public function test_submit_valid_multichoice_response(): void {
        $engine = new response_capture_engine();

        $session = $this->generator->create_session($this->classengage->id, $this->user->id, [
            'status' => 'active',
            'questionstarttime' => time(),
            'timelimit' => 30,
        ]);

        $question = $this->generator->create_question($this->classengage->id, [
            'questiontype' => 'multichoice',
            'correctanswer' => 'B',
        ]);

        $result = $engine->submit_response($session->id, $question->id, 'B', $this->user->id);

        $this->assertTrue($result->success);
        $this->assertTrue($result->iscorrect);
        $this->assertEquals('B', $result->correctanswer);
        $this->assertNotNull($result->responseid);
        $this->assertFalse($result->islate);
    }

    /**
     * Test valid response submission for truefalse question
     *
     * @covers \mod_classengage\response_capture_engine::submit_response
     */
    public function test_submit_valid_truefalse_response(): void {
        $engine = new response_capture_engine();

        $session = $this->generator->create_session($this->classengage->id, $this->user->id, [
            'status' => 'active',
            'questionstarttime' => time(),
            'timelimit' => 30,
        ]);

        $question = $this->generator->create_question($this->classengage->id, [
            'questiontype' => 'truefalse',
            'correctanswer' => 'TRUE',
        ]);

        // Test with 'T' which should match 'TRUE'.
        $result = $engine->submit_response($session->id, $question->id, 'T', $this->user->id);

        $this->assertTrue($result->success);
        $this->assertTrue($result->iscorrect);
    }

    /**
     * Test valid response submission for shortanswer question
     *
     * @covers \mod_classengage\response_capture_engine::submit_response
     */
    public function test_submit_valid_shortanswer_response(): void {
        $engine = new response_capture_engine();

        $session = $this->generator->create_session($this->classengage->id, $this->user->id, [
            'status' => 'active',
            'questionstarttime' => time(),
            'timelimit' => 30,
        ]);

        $question = $this->generator->create_question($this->classengage->id, [
            'questiontype' => 'shortanswer',
            'correctanswer' => 'Paris',
        ]);

        // Test case-insensitive matching.
        $result = $engine->submit_response($session->id, $question->id, 'paris', $this->user->id);

        $this->assertTrue($result->success);
        $this->assertTrue($result->iscorrect);
    }


    /**
     * Test invalid answer format rejection for multichoice
     *
     * @covers \mod_classengage\response_capture_engine::validate_response
     * @covers \mod_classengage\response_capture_engine::submit_response
     */
    public function test_reject_invalid_multichoice_answer(): void {
        $engine = new response_capture_engine();

        $session = $this->generator->create_session($this->classengage->id, $this->user->id, [
            'status' => 'active',
            'questionstarttime' => time(),
            'timelimit' => 30,
        ]);

        $question = $this->generator->create_question($this->classengage->id, [
            'questiontype' => 'multichoice',
            'correctanswer' => 'A',
        ]);

        // Test invalid answer 'E'.
        $result = $engine->submit_response($session->id, $question->id, 'E', $this->user->id);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Invalid answer format', $result->error);
    }

    /**
     * Test invalid answer format rejection for truefalse
     *
     * @covers \mod_classengage\response_capture_engine::validate_response
     */
    public function test_reject_invalid_truefalse_answer(): void {
        $engine = new response_capture_engine();

        $result = $engine->validate_response('MAYBE', 'truefalse');

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('Invalid answer format', $result->error);
    }

    /**
     * Test shortanswer length validation
     *
     * @covers \mod_classengage\response_capture_engine::validate_response
     */
    public function test_reject_too_long_shortanswer(): void {
        $engine = new response_capture_engine();

        $longanswer = str_repeat('x', 256);
        $result = $engine->validate_response($longanswer, 'shortanswer');

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('exceeds maximum length', $result->error);
    }

    /**
     * Test batch processing with multiple valid responses
     *
     * @covers \mod_classengage\response_capture_engine::submit_batch
     */
    public function test_batch_processing_valid_responses(): void {
        $engine = new response_capture_engine();

        // Create multiple users.
        $users = [];
        for ($i = 0; $i < 5; $i++) {
            $users[] = $this->getDataGenerator()->create_user();
        }

        $session = $this->generator->create_session($this->classengage->id, $this->user->id, [
            'status' => 'active',
            'questionstarttime' => time(),
            'timelimit' => 30,
        ]);

        $question = $this->generator->create_question($this->classengage->id, [
            'questiontype' => 'multichoice',
            'correctanswer' => 'A',
        ]);

        // Create batch of responses.
        $responses = [];
        foreach ($users as $user) {
            $responses[] = [
                'sessionid' => $session->id,
                'questionid' => $question->id,
                'answer' => ['A', 'B', 'C', 'D'][array_rand(['A', 'B', 'C', 'D'])],
                'userid' => $user->id,
            ];
        }

        $result = $engine->submit_batch($responses);

        $this->assertTrue($result->success);
        $this->assertEquals(5, $result->processedcount);
        $this->assertEquals(0, $result->failedcount);
    }

    /**
     * Test batch processing rejects oversized batches
     *
     * @covers \mod_classengage\response_capture_engine::submit_batch
     */
    public function test_batch_processing_rejects_oversized_batch(): void {
        $engine = new response_capture_engine();

        // Create batch larger than MAX_BATCH_SIZE.
        $responses = [];
        for ($i = 0; $i < 101; $i++) {
            $responses[] = [
                'sessionid' => 1,
                'questionid' => 1,
                'answer' => 'A',
                'userid' => $i + 1,
            ];
        }

        $result = $engine->submit_batch($responses);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('exceeds maximum', $result->error);
    }

    /**
     * Test submission to inactive session is rejected
     *
     * @covers \mod_classengage\response_capture_engine::submit_response
     */
    public function test_reject_submission_to_inactive_session(): void {
        $engine = new response_capture_engine();

        $session = $this->generator->create_session($this->classengage->id, $this->user->id, [
            'status' => 'completed',
        ]);

        $question = $this->generator->create_question($this->classengage->id);

        $result = $engine->submit_response($session->id, $question->id, 'A', $this->user->id);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not active', $result->error);
    }

    /**
     * Test submission to non-existent session is rejected
     *
     * @covers \mod_classengage\response_capture_engine::submit_response
     */
    public function test_reject_submission_to_nonexistent_session(): void {
        $engine = new response_capture_engine();

        $result = $engine->submit_response(99999, 1, 'A', $this->user->id);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->error);
    }

    /**
     * Test submission with non-existent question is rejected
     *
     * @covers \mod_classengage\response_capture_engine::submit_response
     */
    public function test_reject_submission_with_nonexistent_question(): void {
        $engine = new response_capture_engine();

        $session = $this->generator->create_session($this->classengage->id, $this->user->id, [
            'status' => 'active',
            'questionstarttime' => time(),
            'timelimit' => 30,
        ]);

        $result = $engine->submit_response($session->id, 99999, 'A', $this->user->id);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->error);
    }

    /**
     * Test duplicate submission is rejected
     *
     * @covers \mod_classengage\response_capture_engine::submit_response
     * @covers \mod_classengage\response_capture_engine::is_duplicate
     */
    public function test_reject_duplicate_submission(): void {
        $engine = new response_capture_engine();

        $session = $this->generator->create_session($this->classengage->id, $this->user->id, [
            'status' => 'active',
            'questionstarttime' => time(),
            'timelimit' => 30,
        ]);

        $question = $this->generator->create_question($this->classengage->id);

        // First submission should succeed.
        $result1 = $engine->submit_response($session->id, $question->id, 'A', $this->user->id);
        $this->assertTrue($result1->success);

        // Second submission should be rejected.
        $result2 = $engine->submit_response($session->id, $question->id, 'B', $this->user->id);
        $this->assertFalse($result2->success);
        $this->assertStringContainsString('Duplicate', $result2->error);
    }

    /**
     * Test response queuing for batch processing
     *
     * @covers \mod_classengage\response_capture_engine::queue_response
     * @covers \mod_classengage\response_capture_engine::process_queue
     */
    public function test_response_queue_processing(): void {
        global $DB;

        $engine = new response_capture_engine();

        $session = $this->generator->create_session($this->classengage->id, $this->user->id, [
            'status' => 'active',
            'questionstarttime' => time(),
            'timelimit' => 30,
        ]);

        $question = $this->generator->create_question($this->classengage->id);

        // Queue a response.
        $queueid = $engine->queue_response($session->id, $question->id, 'A', $this->user->id);
        $this->assertNotEmpty($queueid);

        // Verify it's in the queue.
        $queueentry = $DB->get_record('classengage_response_queue', ['id' => $queueid]);
        $this->assertEquals(0, $queueentry->processed);

        // Process the queue.
        $result = $engine->process_queue(10);

        $this->assertTrue($result->success);
        $this->assertEquals(1, $result->processedcount);

        // Verify it's marked as processed.
        $queueentry = $DB->get_record('classengage_response_queue', ['id' => $queueid]);
        $this->assertEquals(1, $queueentry->processed);
    }

    /**
     * Test incorrect answer is properly marked
     *
     * @covers \mod_classengage\response_capture_engine::submit_response
     */
    public function test_incorrect_answer_marked(): void {
        $engine = new response_capture_engine();

        $session = $this->generator->create_session($this->classengage->id, $this->user->id, [
            'status' => 'active',
            'questionstarttime' => time(),
            'timelimit' => 30,
        ]);

        $question = $this->generator->create_question($this->classengage->id, [
            'questiontype' => 'multichoice',
            'correctanswer' => 'A',
        ]);

        // Submit wrong answer.
        $result = $engine->submit_response($session->id, $question->id, 'B', $this->user->id);

        $this->assertTrue($result->success);
        $this->assertFalse($result->iscorrect);
        $this->assertEquals('A', $result->correctanswer);
    }
}
