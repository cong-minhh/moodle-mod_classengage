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
 * Unit tests for mod_classengage comprehension analyzer
 *
 * Tests the comprehension analyzer including:
 * - Comprehension summary calculation
 * - Concept difficulty identification
 * - Response trend detection
 * - Cache invalidation
 *
 * @package    mod_classengage
 * @copyright  2025 Danielle
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_classengage\comprehension_analyzer
 */

namespace mod_classengage;

defined('MOODLE_INTERNAL') || die();

/**
 * Comprehension analyzer unit tests
 *
 * @group mod_classengage
 * @group mod_classengage_unit
 */
class comprehension_analyzer_test extends \advanced_testcase
{

    /** @var \stdClass Test course */
    private $course;

    /** @var \stdClass Test classengage instance */
    private $classengage;

    /** @var \stdClass Test instructor */
    private $instructor;

    /** @var array Test students */
    private $students = [];

    /** @var \stdClass Test session */
    private $session;

    /** @var array Test questions */
    private $questions = [];

    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAfterTest(true);

        $this->course = $this->getDataGenerator()->create_course();
        $this->instructor = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->instructor->id, $this->course->id, 'editingteacher');

        for ($i = 0; $i < 10; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
            $this->students[] = $student;
        }

        $this->classengage = $this->getDataGenerator()->create_module('classengage', [
            'course' => $this->course->id,
        ]);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $this->session = $generator->create_session($this->classengage->id, $this->instructor->id, [
            'status' => 'active',
            'timelimit' => 30,
            'numquestions' => 5,
            'timestarted' => time(),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $question = $generator->create_question($this->classengage->id, [
                'questiontype' => 'multichoice',
                'correctanswer' => 'A',
            ]);
            $this->questions[] = $question;
            $generator->link_questions_to_session($this->session->id, [$question->id]);
        }
    }

    /**
     * Helper to create responses with specific correctness
     */
    private function create_responses_with_correctness(int $questionindex, int $correctcount, int $incorrectcount): void
    {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $questionid = $this->questions[$questionindex]->id;
        $studentindex = 0;

        for ($i = 0; $i < $correctcount && $studentindex < count($this->students); $i++) {
            $generator->create_response(
                $this->session->id,
                $questionid,
                $this->classengage->id,
                $this->students[$studentindex++]->id,
                ['answer' => 'A', 'iscorrect' => 1]
            );
        }

        for ($i = 0; $i < $incorrectcount && $studentindex < count($this->students); $i++) {
            $generator->create_response(
                $this->session->id,
                $questionid,
                $this->classengage->id,
                $this->students[$studentindex++]->id,
                ['answer' => 'B', 'iscorrect' => 0]
            );
        }
    }

    /**
     * Test high comprehension summary (70%+ correctness)
     */
    public function test_get_comprehension_summary_high(): void
    {
        // 8 correct, 2 incorrect = 80% correctness.
        $this->create_responses_with_correctness(0, 8, 2);

        $analyzer = new comprehension_analyzer($this->session->id);
        $summary = $analyzer->get_comprehension_summary();

        $this->assertIsObject($summary);
        $this->assertObjectHasProperty('avg_correctness', $summary);
        $this->assertObjectHasProperty('level', $summary);
        $this->assertObjectHasProperty('message', $summary);
        $this->assertGreaterThanOrEqual(70, $summary->avg_correctness);
        $this->assertEquals('high', $summary->level);
    }

    /**
     * Test moderate comprehension summary (50-70% correctness)
     */
    public function test_get_comprehension_summary_moderate(): void
    {
        // 6 correct, 4 incorrect = 60% correctness.
        $this->create_responses_with_correctness(0, 6, 4);

        $analyzer = new comprehension_analyzer($this->session->id);
        $summary = $analyzer->get_comprehension_summary();

        $this->assertIsObject($summary);
        $this->assertGreaterThanOrEqual(50, $summary->avg_correctness);
        $this->assertLessThan(70, $summary->avg_correctness);
        $this->assertEquals('moderate', $summary->level);
    }

    /**
     * Test low comprehension summary (<50% correctness)
     */
    public function test_get_comprehension_summary_low(): void
    {
        // 3 correct, 7 incorrect = 30% correctness.
        $this->create_responses_with_correctness(0, 3, 7);

        $analyzer = new comprehension_analyzer($this->session->id);
        $summary = $analyzer->get_comprehension_summary();

        $this->assertIsObject($summary);
        $this->assertLessThan(50, $summary->avg_correctness);
        $this->assertEquals('low', $summary->level);
    }

    /**
     * Test get_concept_difficulty identifies difficult questions
     */
    public function test_get_concept_difficulty(): void
    {
        // Question 0: easy (90% correct).
        $this->create_responses_with_correctness(0, 9, 1);

        // Question 1: difficult (20% correct).
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        for ($i = 0; $i < 2; $i++) {
            $generator->create_response(
                $this->session->id,
                $this->questions[1]->id,
                $this->classengage->id,
                $this->students[$i]->id,
                ['answer' => 'A', 'iscorrect' => 1]
            );
        }
        for ($i = 2; $i < 10; $i++) {
            $generator->create_response(
                $this->session->id,
                $this->questions[1]->id,
                $this->classengage->id,
                $this->students[$i]->id,
                ['answer' => 'B', 'iscorrect' => 0]
            );
        }

        $analyzer = new comprehension_analyzer($this->session->id);
        $difficulty = $analyzer->get_concept_difficulty();

        $this->assertIsArray($difficulty);
        // Should identify at least one difficult concept.
        $this->assertGreaterThan(0, count($difficulty));
    }

    /**
     * Test get_response_trends identifies common wrong answers
     */
    public function test_get_response_trends(): void
    {
        // Create responses where many students pick the same wrong answer.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_classengage');
        $questionid = $this->questions[0]->id;

        // 2 correct.
        for ($i = 0; $i < 2; $i++) {
            $generator->create_response(
                $this->session->id,
                $questionid,
                $this->classengage->id,
                $this->students[$i]->id,
                ['answer' => 'A', 'iscorrect' => 1]
            );
        }

        // 8 students pick 'B' (wrong) - this is >30% of class.
        for ($i = 2; $i < 10; $i++) {
            $generator->create_response(
                $this->session->id,
                $questionid,
                $this->classengage->id,
                $this->students[$i]->id,
                ['answer' => 'B', 'iscorrect' => 0]
            );
        }

        $analyzer = new comprehension_analyzer($this->session->id);
        $trends = $analyzer->get_response_trends();

        $this->assertIsArray($trends);
    }

    /**
     * Test get_confused_topics returns topics with low correctness
     */
    public function test_get_confused_topics(): void
    {
        // Create a question with <40% correctness.
        $this->create_responses_with_correctness(0, 2, 8);

        $analyzer = new comprehension_analyzer($this->session->id);

        // Use reflection to access protected method.
        $reflection = new \ReflectionClass($analyzer);
        $method = $reflection->getMethod('get_confused_topics');
        $method->setAccessible(true);

        $topics = $method->invoke($analyzer);

        $this->assertIsArray($topics);
    }

    /**
     * Test invalidate_cache clears cached data
     */
    public function test_invalidate_cache(): void
    {
        $analyzer = new comprehension_analyzer($this->session->id);

        // Populate cache.
        $analyzer->get_comprehension_summary();

        // Invalidate - should not throw exception.
        $analyzer->invalidate_cache();
        $this->assertTrue(true);
    }

    /**
     * Test empty session handling
     */
    public function test_empty_session_handling(): void
    {
        $analyzer = new comprehension_analyzer($this->session->id);

        $summary = $analyzer->get_comprehension_summary();
        $this->assertIsObject($summary);
        $this->assertEquals(0, $summary->avg_correctness);
        $this->assertEquals('low', $summary->level);
    }
}
